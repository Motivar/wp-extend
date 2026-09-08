<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one implementation every surface calls.
 *
 * The dashboard's REST routes, `wp ewp self-test`, the ewp-self-test/*
 * abilities and tests/run-self-test.php (pre-push + CI) are thin wrappers
 * around these five methods: cases(), preview(), run(), cleanup(),
 * report(). State (the last report and the contexts still awaiting
 * cleanup) is kept in one option so "Remove test data" can run in a later
 * request than the run that created the data.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test_Runner
{
    /** Option holding the last report and pending cleanup contexts. */
    const STATE_OPTION = 'ewp_self_test_state';

    /** @var EWP_Self_Test_Manifest */
    private $manifest;

    /**
     * @param EWP_Self_Test_Manifest|null $manifest Defaults to the bundled manifest.
     */
    public function __construct(?EWP_Self_Test_Manifest $manifest = null)
    {
        $this->manifest = $manifest ?: new EWP_Self_Test_Manifest();
    }

    /** @return EWP_Self_Test_Manifest */
    public function manifest()
    {
        return $this->manifest;
    }

    /**
     * Describe the cases: manifest data plus live availability and
     * whether a previous run left data waiting for cleanup.
     *
     * @param string[] $ids Empty for all.
     *
     * @return array[]|\WP_Error
     */
    public function cases(array $ids = [])
    {
        $cases = $this->manifest->cases($ids);

        if (is_wp_error($cases)) {
            return $cases;
        }

        $pending = $this->state()['pending'];
        $list    = [];

        foreach ($cases as $id => $case) {
            $availability = $case->availability();
            $definition   = $this->manifest->definitions()[$id];

            $list[] = [
                'id'              => $id,
                'label'           => $case->label(),
                'category'        => $case->category(),
                'layers'          => $case->layers(),
                'requires'        => isset($definition['requires']) ? (array) $definition['requires'] : [],
                'covers'          => isset($definition['covers']) ? $definition['covers'] : [],
                'available'       => !empty($availability['available']),
                'reason'          => isset($availability['reason']) ? (string) $availability['reason'] : '',
                'pending_cleanup' => isset($pending[$id]),
            ];
        }

        return $list;
    }

    /**
     * Steps each case would take. No side effects.
     *
     * @param string[] $ids Empty for all.
     *
     * @return array[]|\WP_Error
     */
    public function preview(array $ids = [])
    {
        $cases = $this->manifest->cases($ids);

        if (is_wp_error($cases)) {
            return $cases;
        }

        $out = [];

        foreach ($cases as $id => $case) {
            $availability = $case->availability();
            $out[] = [
                'id'        => $id,
                'label'     => $case->label(),
                'available' => !empty($availability['available']),
                'reason'    => isset($availability['reason']) ? (string) $availability['reason'] : '',
                'steps'     => $case->preview(),
            ];
        }

        return $out;
    }

    /**
     * Run cases: run → validate, optionally cleanup, and store the report.
     *
     * @param string[] $ids     Empty for all.
     * @param bool     $cleanup Remove test data straight after each case.
     *
     * @return array|\WP_Error Report.
     */
    public function run(array $ids = [], $cleanup = false)
    {
        $cases = $this->manifest->cases($ids);

        if (is_wp_error($cases)) {
            return $cases;
        }

        $user = $this->ensure_user();
        if (is_wp_error($user)) {
            return $user;
        }

        $state   = $this->state();
        $started = microtime(true);
        $results = [];

        foreach ($cases as $id => $case) {
            $result = $this->run_case($case, $cleanup);

            if ($result['status'] !== 'skipped' && !$cleanup && !empty($result['context'])) {
                $state['pending'][$id] = $result['context'];
            } elseif ($cleanup) {
                unset($state['pending'][$id]);
            }

            unset($result['context']);
            $results[] = $result;
        }

        $report = [
            'started_at'  => gmdate('c', (int) $started),
            'finished_at' => gmdate('c'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'user_id'     => get_current_user_id(),
            'site'        => home_url(),
            'cleanup'     => (bool) $cleanup,
            'summary'     => $this->summarise($results),
            'results'     => $results,
        ];

        $state['report'] = $report;
        $this->save_state($state);

        /**
         * Fires after a self-test run completed.
         *
         * @param array $report The report that was stored.
         *
         * @since 1.5.0
         */
        do_action('ewp_self_test_completed', $report);

        return $report;
    }

    /**
     * Remove test data left by previous runs.
     *
     * @param string[] $ids Empty for every case with pending data.
     *
     * @return array[]|\WP_Error One entry per case: id, label, messages.
     */
    public function cleanup(array $ids = [])
    {
        $state   = $this->state();
        $pending = $state['pending'];
        $targets = empty($ids) ? array_keys($pending) : $ids;

        if (empty($targets)) {
            return [];
        }

        $cases = $this->manifest->cases($targets);

        if (is_wp_error($cases)) {
            return $cases;
        }

        $user = $this->ensure_user();
        if (is_wp_error($user)) {
            return $user;
        }

        $out = [];

        foreach ($cases as $id => $case) {
            $context = isset($pending[$id]) ? $pending[$id] : [];

            try {
                $messages = $case->cleanup($context);
            } catch (\Throwable $e) {
                $messages = [sprintf(__('Cleanup failed: %s', 'extend-wp'), $e->getMessage())];
            }

            unset($state['pending'][$id]);

            $out[] = ['id' => $id, 'label' => $case->label(), 'messages' => $messages];
        }

        if (isset($state['report']['results'])) {
            foreach ($state['report']['results'] as &$result) {
                if (in_array($result['id'], $targets, true)) {
                    $result['cleaned'] = true;
                }
            }
            unset($result);
        }

        $this->save_state($state);

        return $out;
    }

    /**
     * The last stored report.
     *
     * @return array|null
     */
    public function report()
    {
        $state = $this->state();

        return !empty($state['report']) ? $state['report'] : null;
    }

    /**
     * Case ids that still have data to remove.
     *
     * @return string[]
     */
    public function pending_ids()
    {
        return array_keys($this->state()['pending']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Execute one case through its phases.
     *
     * @param EWP_Self_Test_Case $case    Case.
     * @param bool               $cleanup Whether to clean up immediately.
     *
     * @return array Result entry.
     */
    private function run_case(EWP_Self_Test_Case $case, $cleanup)
    {
        $started = microtime(true);
        $entry   = [
            'id'        => $case->id(),
            'label'     => $case->label(),
            'category'  => $case->category(),
            'layers'    => $case->layers(),
            'status'    => 'pass',
            'message'   => '',
            'checks'    => [],
            'cleanup'   => [],
            'cleaned'   => false,
            'context'   => [],
        ];

        $availability = $case->availability();

        if (empty($availability['available'])) {
            $entry['status']  = 'skipped';
            $entry['message'] = (string) $availability['reason'];
            $entry['duration_ms'] = 0;

            return $entry;
        }

        try {
            $context          = $case->run();
            $entry['context'] = is_array($context) ? $context : [];
            $entry['checks']  = $case->validate($entry['context']);
        } catch (\Throwable $e) {
            $entry['status']  = 'error';
            $entry['message'] = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        if ($entry['status'] !== 'error') {
            $entry['status'] = $this->status_from_checks($entry['checks']);
        }

        if ($cleanup) {
            try {
                $entry['cleanup'] = $case->cleanup($entry['context']);
                $entry['cleaned'] = true;
            } catch (\Throwable $e) {
                $entry['cleanup'] = [sprintf(__('Cleanup failed: %s', 'extend-wp'), $e->getMessage())];
            }
        }

        $entry['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $entry;
    }

    /**
     * pass when no check failed; skipped when every check was skipped.
     *
     * @param array[] $checks Checks.
     *
     * @return string
     */
    private function status_from_checks(array $checks)
    {
        $statuses = array_column($checks, 'status');

        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }

        if (!empty($statuses) && count(array_unique($statuses)) === 1 && $statuses[0] === 'skip') {
            return 'skipped';
        }

        return 'pass';
    }

    /**
     * Totals for a set of results.
     *
     * @param array[] $results Results.
     *
     * @return array
     */
    private function summarise(array $results)
    {
        $summary = ['total' => count($results), 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0, 'checks' => ['pass' => 0, 'fail' => 0, 'skip' => 0]];

        foreach ($results as $result) {
            $key = ['pass' => 'passed', 'fail' => 'failed', 'skipped' => 'skipped', 'error' => 'errors'][$result['status']];
            $summary[$key]++;

            foreach ($result['checks'] as $check) {
                $summary['checks'][$check['status']]++;
            }
        }

        return $summary;
    }

    /**
     * Outside a logged-in request (CLI, CI script) act as the first
     * administrator so capability checks behave like the dashboard.
     *
     * @return true|\WP_Error
     */
    private function ensure_user()
    {
        if (current_user_can(EWP_Self_Test::capability())) {
            return true;
        }

        if (!$this->is_cli_context()) {
            return new \WP_Error('ewp_self_test_forbidden', __('You are not allowed to run the self-tests.', 'extend-wp'), ['status' => 403]);
        }

        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

        if (empty($admins)) {
            return new \WP_Error('ewp_self_test_no_admin', __('No administrator user exists to run the self-tests as.', 'extend-wp'));
        }

        wp_set_current_user((int) $admins[0]);

        return true;
    }

    /** @return bool */
    private function is_cli_context()
    {
        return (defined('WP_CLI') && WP_CLI) || php_sapi_name() === 'cli';
    }

    /** @return array{report: array|null, pending: array} */
    private function state()
    {
        $state = get_option(self::STATE_OPTION, []);

        return [
            'report'  => isset($state['report']) && is_array($state['report']) ? $state['report'] : null,
            'pending' => isset($state['pending']) && is_array($state['pending']) ? $state['pending'] : [],
        ];
    }

    private function save_state(array $state)
    {
        update_option(self::STATE_OPTION, $state, false);
    }
}
