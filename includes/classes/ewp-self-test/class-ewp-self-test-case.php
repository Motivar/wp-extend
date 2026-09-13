<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for one self-test case.
 *
 * A case describes one feature of the plugin and exercises it through every
 * surface it is exposed on (REST, WP-CLI, Abilities API). It has four
 * phases, each callable on its own so the dashboard, the CLI and the
 * abilities can drive them independently:
 *
 *  - preview():          human-readable list of the steps run() will take. No side effects.
 *  - run():              performs the steps against the live site and returns an
 *                        array of observations ("context"). May create data.
 *  - validate($context): turns the observations into pass/fail/skip checks. No side effects.
 *  - cleanup($context):  removes whatever run() created, using the same context.
 *
 * The context must be JSON-serialisable: the runner stores it so cleanup can
 * happen in a later request (the "Remove test data" button).
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
abstract class EWP_Self_Test_Case
{
    /** Owner slug used for every log entry and label written by a case. */
    const OWNER = 'ewp-self-test';

    /** @var array Definition from the manifest (id, label, category, layers, args, requires). */
    protected $definition = [];

    /**
     * Attach the manifest definition.
     *
     * @param array $definition Manifest entry.
     *
     * @return void
     */
    public function configure(array $definition)
    {
        $this->definition = $definition;
    }

    /** @return string Manifest id. */
    public function id()
    {
        return isset($this->definition['id']) ? (string) $this->definition['id'] : static::class;
    }

    /** @return string Human label. */
    public function label()
    {
        return isset($this->definition['label']) ? (string) $this->definition['label'] : $this->id();
    }

    /** @return string Category slug (content, logger, options, search, system, ai). */
    public function category()
    {
        return isset($this->definition['category']) ? (string) $this->definition['category'] : 'general';
    }

    /** @return string[] Surfaces this case covers: rest, cli, ability. */
    public function layers()
    {
        return isset($this->definition['layers']) ? (array) $this->definition['layers'] : [];
    }

    /** @return array Free-form args from the manifest. */
    protected function args()
    {
        return isset($this->definition['args']) && is_array($this->definition['args']) ? $this->definition['args'] : [];
    }

    /**
     * Whether the case can run on this site, and why not when it cannot.
     *
     * @return array{available: bool, reason: string}
     */
    public function availability()
    {
        return ['available' => true, 'reason' => ''];
    }

    /**
     * Describe what run() will do.
     *
     * @return string[] Ordered, human-readable steps.
     */
    abstract public function preview();

    /**
     * Execute the case.
     *
     * @return array JSON-serialisable observations for validate()/cleanup().
     */
    abstract public function run();

    /**
     * Assess the observations.
     *
     * @param array $context Value returned by run().
     *
     * @return array[] Checks, each from check()/skip().
     */
    abstract public function validate(array $context);

    /**
     * Remove test data. Default: nothing to remove.
     *
     * @param array $context Value returned by run().
     *
     * @return string[] Messages describing what was removed.
     */
    public function cleanup(array $context)
    {
        unset($context);

        return [];
    }

    /* ------------------------------------------------------------------
     * Helpers for subclasses
     * ---------------------------------------------------------------- */

    /**
     * Build one check result.
     *
     * @param string $layer  rest|cli|ability|core.
     * @param string $label  What was checked.
     * @param bool   $pass   Outcome.
     * @param string $detail Extra information shown in the report.
     *
     * @return array
     */
    protected function check($layer, $label, $pass, $detail = '')
    {
        return [
            'layer'  => (string) $layer,
            'label'  => (string) $label,
            'status' => $pass ? 'pass' : 'fail',
            'detail' => (string) $detail,
        ];
    }

    /**
     * Build a skipped check (feature not available on this site).
     *
     * @param string $layer  rest|cli|ability|core.
     * @param string $label  What would have been checked.
     * @param string $reason Why it was skipped.
     *
     * @return array
     */
    protected function skip($layer, $label, $reason)
    {
        return [
            'layer'  => (string) $layer,
            'label'  => (string) $label,
            'status' => 'skip',
            'detail' => (string) $reason,
        ];
    }

    /**
     * Dispatch an internal REST request as the current user.
     *
     * @param string $method     HTTP method.
     * @param string $route      Route, e.g. `/ewp/fields/create`.
     * @param array  $params     Body/query params.
     * @param array  $url_params Route regex captures, e.g. ['id' => 5].
     *
     * @return array{status: int, data: mixed} Serialisable summary of the response.
     */
    protected function rest($method, $route, array $params = [], array $url_params = [])
    {
        $request = new \WP_REST_Request(strtoupper($method), $route);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        if (!empty($url_params)) {
            $request->set_url_params($url_params);
        }

        $response = rest_get_server()->dispatch($request);

        if (is_wp_error($response)) {
            $data   = $response->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;

            return ['status' => $status, 'data' => ['code' => $response->get_error_code(), 'message' => $response->get_error_message()]];
        }

        return ['status' => (int) $response->get_status(), 'data' => $this->serialisable($response->get_data())];
    }

    /**
     * Whether the Abilities API is usable here.
     *
     * @return bool
     */
    protected function abilities_available()
    {
        return class_exists('EWP\\Abilities\\EWP_Abilities')
            && \EWP\Abilities\EWP_Abilities::is_enabled()
            && function_exists('wp_get_ability');
    }

    /**
     * Execute a registered ability.
     *
     * @param string $name  Ability name, e.g. `ewp-content/get-item`.
     * @param array  $input Ability input.
     *
     * @return array{found: bool, ok: bool, data: mixed, error: string}
     */
    protected function ability($name, array $input = [])
    {
        if (!$this->abilities_available()) {
            return ['found' => false, 'ok' => false, 'data' => null, 'error' => 'abilities-unavailable'];
        }

        $ability = wp_get_ability($name);

        if (!$ability) {
            return ['found' => false, 'ok' => false, 'data' => null, 'error' => 'not-registered'];
        }

        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            return ['found' => true, 'ok' => false, 'data' => null, 'error' => $result->get_error_code() . ': ' . $result->get_error_message()];
        }

        return ['found' => true, 'ok' => true, 'data' => $this->serialisable($result), 'error' => ''];
    }

    /**
     * Whether the CLI wrappers can be called in this process.
     *
     * @return bool
     */
    protected function cli_available()
    {
        return class_exists('WP_CLI');
    }

    /**
     * Run a plugin CLI command handler in-process and capture its output.
     *
     * @param callable $callable   e.g. ['EWP_Content_CLI', 'create'].
     * @param array    $args       Positional args.
     * @param array    $assoc_args Named args.
     *
     * @return array{ok: bool, success: string[], printed: array, error: string}
     */
    protected function cli($callable, array $args = [], array $assoc_args = [])
    {
        if (!$this->cli_available() || !is_callable($callable)) {
            return ['ok' => false, 'success' => [], 'printed' => [], 'error' => 'cli-unavailable'];
        }

        if (class_exists('WP_CLI\\StubRecorder', false) && !defined('WP_CLI')) {
            return $this->cli_via_stub($callable, $args, $assoc_args);
        }

        return $this->cli_via_wp_cli($callable, $args, $assoc_args);
    }

    /**
     * In-process shim (dashboard, PHPUnit, tests/self-test-runner.php).
     */
    private function cli_via_stub($callable, array $args, array $assoc_args)
    {
        \WP_CLI\StubRecorder::reset();

        try {
            call_user_func($callable, $args, $assoc_args);
        } catch (\Throwable $e) {
            return ['ok' => false, 'success' => [], 'printed' => [], 'error' => $e->getMessage()];
        }

        return [
            'ok'      => true,
            'success' => \WP_CLI\StubRecorder::$success,
            'printed' => $this->serialisable(\WP_CLI\StubRecorder::$printed),
            'error'   => '',
        ];
    }

    /**
     * Real `wp` process: swap in WP-CLI's buffering Execution logger, make
     * WP_CLI::error() throw instead of exit, and buffer echoed output.
     */
    private function cli_via_wp_cli($callable, array $args, array $assoc_args)
    {
        $previous_logger = method_exists('WP_CLI', 'get_logger') ? \WP_CLI::get_logger() : null;
        $logger          = class_exists('WP_CLI\\Loggers\\Execution') ? new \WP_CLI\Loggers\Execution() : null;
        $capture_exit    = null;

        if ($logger) {
            \WP_CLI::set_logger($logger);
        }

        try {
            $capture_exit = new \ReflectionProperty('WP_CLI', 'capture_exit');
            $capture_exit->setAccessible(true);
            $capture_exit->setValue(null, true);
        } catch (\ReflectionException $e) {
            $capture_exit = null;
        }

        ob_start();
        $error = '';

        try {
            call_user_func($callable, $args, $assoc_args);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $echoed = (string) ob_get_clean();

        if ($capture_exit) {
            $capture_exit->setValue(null, false);
        }
        if ($logger && $previous_logger) {
            \WP_CLI::set_logger($previous_logger);
        }

        $stdout  = ($logger ? (string) $logger->stdout : '') . $echoed;
        $success = [];

        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            if (strpos($line, 'Success: ') === 0) {
                $success[] = substr($line, 9);
            }
        }

        return [
            'ok'      => $error === '',
            'success' => $success,
            'printed' => $this->printed_from_text($echoed !== '' ? $echoed : $stdout),
            'error'   => $error !== '' ? $error : ($logger ? trim((string) $logger->stderr) : ''),
        ];
    }

    /**
     * Shape captured stdout like the stub recorder would.
     *
     * @param string $text Captured output.
     *
     * @return array
     */
    private function printed_from_text($text)
    {
        $decoded = json_decode(trim($text), true);

        if (is_array($decoded)) {
            return array_is_list($decoded) ? [['format' => 'json', 'items' => $decoded]] : [$decoded];
        }

        return $text === '' ? [] : [['raw' => $text]];
    }

    /**
     * Make a value safe to json_encode and store in an option.
     *
     * @param mixed $value Any value.
     *
     * @return mixed
     */
    protected function serialisable($value)
    {
        return json_decode(wp_json_encode($value), true);
    }

    /* ---------------------------------------------------------------------
     * Assertion helpers shared by cases
     * ------------------------------------------------------------------ */

    /**
     * HTTP status of an observed REST call, 0 when it did not run.
     *
     * @param array  $o   Observed results.
     * @param string $key Result key.
     *
     * @return int
     */
    protected function status(array $o, $key)
    {
        return isset($o[$key]['status']) ? (int) $o[$key]['status'] : 0;
    }

    protected function detail(array $o, $key)
    {
        if (!isset($o[$key])) {
            return __('not executed', 'extend-wp');
        }

        return 'HTTP ' . $this->status($o, $key) . ' ' . wp_json_encode($o[$key]['data']);
    }

    protected function cli_check(array $o, $key, $label, $expected_fragment = null)
    {
        if (!$this->cli_available()) {
            return $this->skip('cli', $label, __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        if (!isset($o[$key])) {
            return $this->check('cli', $label, false, __('not executed', 'extend-wp'));
        }

        $r    = $o[$key];
        $pass = !empty($r['ok']) && ($expected_fragment === null || (!empty($r['success'][0]) && strpos($r['success'][0], $expected_fragment) !== false));

        return $this->check('cli', $label, $pass, $pass ? (!empty($r['success'][0]) ? $r['success'][0] : __('ok', 'extend-wp')) : ($r['error'] ?: wp_json_encode($r['success'])));
    }

    protected function cli_check_printed(array $o, $key, $label, callable $predicate)
    {
        if (!$this->cli_available()) {
            return $this->skip('cli', $label, __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        $r    = isset($o[$key]) ? $o[$key] : ['ok' => false, 'printed' => [], 'error' => 'not executed'];
        $pass = !empty($r['ok']) && $predicate(isset($r['printed']) ? $r['printed'] : []);

        return $this->check('cli', $label, $pass, $pass ? __('ok', 'extend-wp') : ($r['error'] ?: __('expected row not found in output', 'extend-wp')));
    }

    protected function ability_check(array $o, $key, $label, callable $predicate)
    {
        if (!$this->abilities_available()) {
            return $this->skip('ability', $label, __('Abilities API not available on this site.', 'extend-wp'));
        }

        if (!isset($o[$key])) {
            return $this->check('ability', $label, false, __('not executed — an earlier step it depends on failed', 'extend-wp'));
        }

        $r = $o[$key];

        if (empty($r['found'])) {
            return $this->check('ability', $label, false, __('ability is not registered', 'extend-wp'));
        }

        $pass = !empty($r['ok']) && $predicate(is_array($r['data']) ? $r['data'] : []);

        return $this->check('ability', $label, $pass, $pass ? __('ok', 'extend-wp') : ($r['error'] ?: wp_json_encode($r['data'])));
    }
}
