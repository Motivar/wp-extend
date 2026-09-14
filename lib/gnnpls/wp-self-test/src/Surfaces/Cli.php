<?php

namespace Gnnpls\SelfTest\Surfaces;

use Gnnpls\SelfTest\Runner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp mwp self-test` — the CLI face of Runner. Used by hand and, through
 * bin/run.php, by the pre-push hook and CI.
 *
 * Commands:
 *   wp mwp self-test list [--plugin=<slug>]     — cases from every registered manifest.
 *   wp mwp self-test preview [--cases=<ids>]    — the steps each case would run.
 *   wp mwp self-test run [--cases=<ids>] [--cleanup] [--format=<f>]
 *                                               — run + validate; exits 1 when any case fails.
 *   wp mwp self-test cleanup [--cases=<ids>]    — remove test data left by earlier runs.
 *   wp mwp self-test report [--format=<f>]      — the last stored report.
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */
final class Cli
{
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

        \WP_CLI::add_command('mwp self-test list', [__CLASS__, 'list_cases']);
        \WP_CLI::add_command('mwp self-test preview', [__CLASS__, 'preview']);
        \WP_CLI::add_command('mwp self-test run', [__CLASS__, 'run']);
        \WP_CLI::add_command('mwp self-test cleanup', [__CLASS__, 'cleanup']);
        \WP_CLI::add_command('mwp self-test report', [__CLASS__, 'report']);
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
     *     wp mwp self-test list
     *     wp mwp self-test list --plugin=extend-wp
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
     *     wp mwp self-test preview --cases=content-crud,logger
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
     * [--cleanup]
     * : Remove the test data right after each case (what pre-push and CI use).
     *
     * [--format=<format>]
     * : Output format (table, json). Default table.
     *
     * ## EXAMPLES
     *
     *     wp mwp self-test run --cleanup
     *     wp mwp self-test run --cases=logger --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function run($args, $assoc_args)
    {
        $report = self::unwrap(self::$runner->run(self::ids($assoc_args), isset($assoc_args['cleanup']), self::plugin($assoc_args)));
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
     *     wp mwp self-test cleanup
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
     *     wp mwp self-test report --format=json
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
            \WP_CLI::warning('No self-test report stored yet. Run "wp mwp self-test run".');
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
