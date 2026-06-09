<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EWP REST Health — Test Runner & History Manager
 *
 * Executes REST endpoint tests via rest_do_request() and stores
 * per-user history as JSON files under the uploads directory.
 *
 * Every test execution is logged via ewp_log() so runs are auditable
 * and downloadable from the EWP Logger viewer.
 *
 * @package EWP\RestHealth
 * @since   1.0.0
 */
class EWP_REST_Health_Runner
{
    const HISTORY_MAX_ENTRIES = 100;
    const UPLOADS_SUBDIR      = 'ewp-rest-health/history';
    const BATCH_MAX_ROUTES    = 50;

    // -------------------------------------------------------------------------
    // Public: test execution
    // -------------------------------------------------------------------------

    /**
     * Execute a single endpoint test.
     *
     * @param  string $route
     * @param  string $method   HTTP method (GET, POST, etc.)
     * @param  array  $params   Query / body params
     * @param  int    $user_id
     * @return array            Result array
     */
    public function run_single(string $route, string $method, array $params, int $user_id): array
    {
        $method  = strtoupper($method);
        $start   = microtime(true);
        $request = new WP_REST_Request($method, $route);

        if (in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
            $request->set_header('Content-Type', 'application/json');
        }

        try {
            $response = rest_do_request($request);
            $status   = $response->get_status();
            $data     = $response->get_data();
        } catch (\Throwable $e) {
            $status = 500;
            $data   = ['error' => $e->getMessage()];
        }

        $duration = round((microtime(true) - $start) * 1000, 2);
        $result   = [
            'route'       => $route,
            'method'      => $method,
            'status'      => $status,
            'duration_ms' => $duration,
            'params'      => $params,
            'response'    => $data,
            'is_error'    => $status >= 400,
        ];

        $this->append_history($route, $method, $params, $result, $user_id);
        $this->log_result($route, $method, $params, $result);

        return $result;
    }

    /**
     * Execute a batch of endpoint tests.
     *
     * Capped at BATCH_MAX_ROUTES entries. Each item only needs
     * 'route' and 'method' — params are left empty (discovery mode).
     *
     * @param  array $items    [['route' => string, 'method' => string], ...]
     * @param  int   $user_id
     * @return array           {results: array[], summary: array}
     */
    public function run_batch(array $items, int $user_id): array
    {
        $items   = array_slice($items, 0, self::BATCH_MAX_ROUTES);
        $results = [];
        $ok      = 0;
        $errors  = 0;
        $start   = microtime(true);

        foreach ($items as $item) {
            $route  = $item['route'] ?? '';
            $method = $item['method'] ?? 'GET';
            if (!$route) {
                continue;
            }
            $result    = $this->run_single($route, $method, [], $user_id);
            $results[] = $result;
            $result['is_error'] ? $errors++ : $ok++;
        }

        $summary = [
            'total'       => count($results),
            'ok'          => $ok,
            'errors'      => $errors,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ];

        $this->log_batch_summary($summary);

        return ['results' => $results, 'summary' => $summary];
    }

    // -------------------------------------------------------------------------
    // Public: history
    // -------------------------------------------------------------------------

    /**
     * Get history entries for a specific route + method (newest first).
     *
     * @param  string $route
     * @param  string $method
     * @param  int    $user_id
     * @return array[]
     */
    public function get_history(string $route, string $method, int $user_id): array
    {
        $method  = strtoupper($method);
        $data    = $this->read_history_file($user_id);
        $entries = array_filter(
            $data['entries'] ?? [],
            fn($e) => ($e['route'] ?? '') === $route && ($e['method'] ?? '') === $method
        );
        return array_values($entries);
    }

    /**
     * Clear all history entries for a specific route + method.
     *
     * @param  string $route
     * @param  string $method
     * @param  int    $user_id
     */
    public function clear_history(string $route, string $method, int $user_id): void
    {
        $method  = strtoupper($method);
        $data    = $this->read_history_file($user_id);
        $data['entries'] = array_values(array_filter(
            $data['entries'] ?? [],
            fn($e) => !($e['route'] === $route && $e['method'] === $method)
        ));
        $data['updated'] = current_time('mysql');
        $this->write_history_file($user_id, $data);
    }

    // -------------------------------------------------------------------------
    // Private: history file management
    // -------------------------------------------------------------------------

    private function get_history_path(int $user_id): string
    {
        $upload = wp_upload_dir();
        return $upload['basedir'] . '/' . self::UPLOADS_SUBDIR . '/user-' . $user_id . '.json';
    }

    /**
     * @return array{version: int, updated: string, entries: array[]}
     */
    private function read_history_file(int $user_id): array
    {
        $path = $this->get_history_path($user_id);
        if (!file_exists($path)) {
            return ['version' => 1, 'updated' => '', 'entries' => []];
        }
        $json = file_get_contents($path);
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : ['version' => 1, 'updated' => '', 'entries' => []];
    }

    private function write_history_file(int $user_id, array $data): void
    {
        $path = $this->get_history_path($user_id);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            $this->create_security_files($dir);
        }

        file_put_contents($path, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function create_security_files(string $dir): void
    {
        file_put_contents($dir . '/.htaccess', 'Deny from all');
        file_put_contents($dir . '/index.php', '<?php exit;');
    }

    private function append_history(
        string $route,
        string $method,
        array  $params,
        array  $result,
        int    $user_id
    ): void {
        $data = $this->read_history_file($user_id);

        array_unshift($data['entries'], [
            'route'       => $route,
            'method'      => $method,
            'timestamp'   => current_time('mysql'),
            'status'      => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'params'      => $params,
            'response'    => $result['response'],
        ]);

        if (count($data['entries']) > self::HISTORY_MAX_ENTRIES) {
            $data['entries'] = array_slice($data['entries'], 0, self::HISTORY_MAX_ENTRIES);
        }

        $data['updated'] = current_time('mysql');
        $this->write_history_file($user_id, $data);
    }

    // -------------------------------------------------------------------------
    // Private: EWP logging
    // -------------------------------------------------------------------------

    /**
     * Log every test result.
     * Always emits rest_test_run; additionally emits rest_test_error on 5xx.
     */
    private function log_result(string $route, string $method, array $params, array $result): void
    {
        if (!function_exists('ewp_log')) {
            return;
        }

        $behaviour = ($result['status'] >= 400) ? 0 : 1;

        ewp_log(
            'ewp-rest-health',
            'rest_test_run',
            sprintf('%s %s → %d (%sms)', $method, $route, $result['status'], $result['duration_ms']),
            [
                'route'       => $route,
                'method'      => $method,
                'params'      => $params,
                'status'      => $result['status'],
                'duration_ms' => $result['duration_ms'],
                'response'    => $result['response'],
            ],
            'developer',
            '',
            $behaviour
        );

        if ($result['status'] >= 500) {
            ewp_log(
                'ewp-rest-health',
                'rest_test_error',
                sprintf('5xx: %s %s → %d', $method, $route, $result['status']),
                ['route' => $route, 'method' => $method, 'status' => $result['status'], 'response' => $result['response']],
                'developer',
                '',
                0
            );
        }
    }

    private function log_batch_summary(array $summary): void
    {
        if (!function_exists('ewp_log')) {
            return;
        }

        $behaviour = $summary['errors'] > 0 ? 2 : 1;

        ewp_log(
            'ewp-rest-health',
            'rest_batch_summary',
            sprintf('Batch: %d/%d ok, %d errors (%sms)', $summary['ok'], $summary['total'], $summary['errors'], $summary['duration_ms']),
            $summary,
            'developer',
            '',
            $behaviour
        );
    }
}
