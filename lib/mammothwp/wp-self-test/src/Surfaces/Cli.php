<?php

namespace MammothWP\SelfTest\Surfaces;

use MammothWP\SelfTest\Runner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp mmwp self-test` — the CLI face of Runner. Used by hand, by the shared
 * Mammoth WP CI pipeline (`wp mmwp self-test <slug> --format=table`) and,
 * through bin/run.php, by the pre-push hook.
 *
 * `mmwp self-test` is one command (WP-CLI cannot give a command both a
 * positional argument and subcommands), so the first positional argument
 * is either an action or a plugin slug:
 *
 *   wp mmwp self-test <slug> [--format=<f>]     — run + validate one plugin's cases; exits 1 on failure.
 *   wp mmwp self-test                           — run every registered case.
 *   wp mmwp self-test list [<slug>]             — cases from every registered manifest.
 *   wp mmwp self-test preview [<slug>]          — the steps each case would run.
 *   wp mmwp self-test run [<slug>] [--cleanup]  — same as the bare form.
 *   wp mmwp self-test cleanup [--cases=<ids>]   — remove test data left by earlier runs.
 *   wp mmwp self-test report [--format=<f>]     — the last stored report.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */
final class Cli
{
    /** Actions accepted as the first positional argument. */
    const ACTIONS = ['list', 'preview', 'run', 'cleanup', 'report'];

    /** @var Runner */
    private static $runner;

    /**
     * Register commands.
     *
     * @param Runner $runner Shared runner.
     *
     * @return void
     */
    public static function init(Runner $runner)
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        self::$runner = $runner;

        \WP_CLI::add_command('mmwp self-test', [__CLASS__, 'dispatch']);
    }

    /**
     * Run a plugin's self-test cases, or another self-test action.
     *
     * ## OPTIONS
     *
     * [<slug>]
     * : Plugin slug whose cases to run, or one of: list, preview, run, cleanup, report. Default: run every case.
     *
     * [<plugin>]
     * : Plugin slug for the list, preview and run actions.
     *
     * [--cases=<ids>]
     * : Comma-separated case ids. Default: all.
     *
     * [--plugin=<slug>]
     * : Only the cases registered by this plugin.
     *
     * [--layer=<layer>]
     * : Only the cases declaring this layer (rest, cli, ability, core, browser). `browser` runs only the Playwright checks.
     *
     * [--cleanup]
     * : Remove the test data right after each case (what pre-push and CI use).
     *
     * [--format=<format>]
     * : Output format (table, json, csv for list). Default table.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test my-plugin --format=table
     *     wp mmwp self-test run --cleanup
     *     wp mmwp self-test list my-plugin
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function dispatch($args, $assoc_args)
    {
        $args   = array_values((array) $args);
        $first  = isset($args[0]) ? (string) $args[0] : '';
        $action = in_array($first, self::ACTIONS, true) ? $first : 'run';
        $slug   = $action === $first ? (isset($args[1]) ? (string) $args[1] : '') : $first;

        if ($slug !== '' && !isset($assoc_args['plugin'])) {
            $assoc_args['plugin'] = $slug;
        }

        self::call($action, $assoc_args);
    }

    /**
     * Invoke an action handler.
     *
     * @param string $action     One of ACTIONS.
     * @param array  $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    private static function call($action, array $assoc_args)
    {
        $handler = ['list' => 'list_cases'][$action] ?? $action;
        self::$handler([], $assoc_args);
    }

    /**
     * List the self-test cases.
     *
     * ## OPTIONS
     *
     * [--plugin=<slug>]
     * : Only the cases registered by this plugin.
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default table.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test list
     *     wp mmwp self-test list --plugin=my-plugin
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function list_cases($args, $assoc_args)
    {
        $cases = self::unwrap(self::$runner->cases([], self::plugin($assoc_args)));
        $rows  = array_map(function ($case) {
            return [
                'ID'        => $case['id'],
                'Plugin'    => $case['plugin'],
                'Label'     => $case['label'],
                'Category'  => $case['category'],
                'Layers'    => implode(',', $case['layers']),
                'Available' => $case['available'] ? 'yes' : 'no: ' . $case['reason'],
                'Pending'   => $case['pending_cleanup'] ? 'cleanup pending' : '',
            ];
        }, $cases);

        \WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, ['ID', 'Plugin', 'Label', 'Category', 'Layers', 'Available', 'Pending']);
    }

    /**
     * Show the steps each case would run, without running anything.
     *
     * ## OPTIONS
     *
     * [--cases=<ids>]
     * : Comma-separated case ids. Default: all.
     *
     * [--plugin=<slug>]
     * : Only the cases registered by this plugin.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test preview --cases=content-crud,logger
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function preview($args, $assoc_args)
    {
        foreach (self::unwrap(self::$runner->preview(self::ids($assoc_args), self::plugin($assoc_args))) as $case) {
            \WP_CLI::log(sprintf('%s — %s%s', $case['id'], $case['label'], $case['available'] ? '' : ' [unavailable: ' . $case['reason'] . ']'));
            foreach ($case['steps'] as $i => $step) {
                \WP_CLI::log(sprintf('  %d. %s', $i + 1, $step));
            }
        }
    }

    /**
     * Run the self-tests.
     *
     * ## OPTIONS
     *
     * [--cases=<ids>]
     * : Comma-separated case ids. Default: all.
     *
     * [--plugin=<slug>]
     * : Only the cases registered by this plugin.
     *
     * [--layer=<layer>]
     * : Only the cases declaring this layer (rest, cli, ability, core, browser). `browser` runs only the Playwright checks.
     *
     * [--cleanup]
     * : Remove the test data right after each case (what pre-push and CI use).
     *
     * [--format=<format>]
     * : Output format (table, json). Default table.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test run --cleanup
     *     wp mmwp self-test run --cases=logger --format=json
     *     wp mmwp self-test run --layer=browser
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function run($args, $assoc_args)
    {
        $report = self::unwrap(self::$runner->run(self::ids($assoc_args), isset($assoc_args['cleanup']), self::plugin($assoc_args), isset($assoc_args['layer']) ? (string) $assoc_args['layer'] : ''));
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($format === 'json') {
            \WP_CLI::print_value($report, ['format' => 'json']);
        } else {
            self::print_report($report);
        }

        $summary = $report['summary'];

        if ($summary['failed'] > 0 || $summary['errors'] > 0) {
            \WP_CLI::error(sprintf('%d failed, %d error(s), %d passed, %d skipped.', $summary['failed'], $summary['errors'], $summary['passed'], $summary['skipped']));
        }

        \WP_CLI::success(sprintf('%d passed, %d skipped, %d checks.', $summary['passed'], $summary['skipped'], array_sum($summary['checks'])));
    }

    /**
     * Remove test data left by earlier runs.
     *
     * ## OPTIONS
     *
     * [--cases=<ids>]
     * : Comma-separated case ids. Default: every case with pending data.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test cleanup
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function cleanup($args, $assoc_args)
    {
        $out = self::unwrap(self::$runner->cleanup(self::ids($assoc_args)));

        if (empty($out)) {
            \WP_CLI::success('Nothing to clean up.');
            return;
        }

        foreach ($out as $case) {
            \WP_CLI::log($case['id'] . ':');
            foreach ($case['messages'] as $message) {
                \WP_CLI::log('  - ' . $message);
            }
        }

        \WP_CLI::success(sprintf('Cleaned up %d case(s).', count($out)));
    }

    /**
     * Print the last stored report.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json). Default table.
     *
     * ## EXAMPLES
     *
     *     wp mmwp self-test report --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function report($args, $assoc_args)
    {
        $report = self::$runner->report();

        if (!$report) {
            \WP_CLI::warning('No self-test report stored yet. Run "wp mmwp self-test run".');
            return;
        }

        if ((isset($assoc_args['format']) ? $assoc_args['format'] : 'table') === 'json') {
            \WP_CLI::print_value($report, ['format' => 'json']);
            return;
        }

        self::print_report($report);
    }

    /* ------------------------------------------------------------------ */

    private static function plugin(array $assoc_args)
    {
        return isset($assoc_args['plugin']) ? sanitize_key((string) $assoc_args['plugin']) : '';
    }

    private static function ids(array $assoc_args)
    {
        if (empty($assoc_args['cases'])) {
            return [];
        }

        return array_values(array_filter(array_map('sanitize_key', explode(',', $assoc_args['cases']))));
    }

    private static function unwrap($value)
    {
        if (is_wp_error($value)) {
            \WP_CLI::error($value->get_error_message());
        }

        return $value;
    }

    private static function print_report(array $report)
    {
        \WP_CLI::log(sprintf('Self-test report — %s (%d ms)', $report['finished_at'], $report['duration_ms']));

        foreach ($report['results'] as $result) {
            \WP_CLI::log(sprintf('[%s] %s/%s — %s%s', strtoupper($result['status']), $result['plugin'], $result['id'], $result['label'], $result['message'] ? ' (' . $result['message'] . ')' : ''));

            foreach ($result['checks'] as $check) {
                \WP_CLI::log(sprintf('    %s %-8s %s%s', ['pass' => '✓', 'fail' => '✗', 'skip' => '-'][$check['status']], $check['layer'], $check['label'], $check['detail'] !== '' ? ' — ' . $check['detail'] : ''));
            }

            foreach ($result['cleanup'] as $message) {
                \WP_CLI::log('    cleanup: ' . $message);
            }
        }
    }
}
