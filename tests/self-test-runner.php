<?php
/**
 * Run the self-test suite (manifest.json) outside PHPUnit.
 *
 * Used by .githooks/pre-push and .gitlab-ci.yml. Boots WordPress through
 * tests/bootstrap.php — the same isolated test database PHPUnit uses, so a
 * run never touches real site data — but without PHPUnit's per-test
 * transaction wrapper, because the content case creates real tables and
 * WP_UnitTestCase would silently turn those into temporary ones.
 *
 * Every case runs with cleanup, and the process exits 1 when any case
 * fails or errors, so the hook/CI job blocks.
 *
 *   WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db php tests/run-self-test.php [case,ids]
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$ids    = isset($argv[1]) ? array_filter(explode(',', $argv[1])) : [];
$runner = \EWP\SelfTest\EWP_Self_Test::instance()->runner();
$report = $runner->run($ids, true);

if (is_wp_error($report)) {
    fwrite(STDERR, 'Self-test could not run: ' . $report->get_error_message() . PHP_EOL);
    exit(1);
}

$icons = ['pass' => '✓', 'fail' => '✗', 'skip' => '-'];

foreach ($report['results'] as $result) {
    echo sprintf('[%s] %s — %s%s', strtoupper($result['status']), $result['id'], $result['label'], $result['message'] !== '' ? ' (' . $result['message'] . ')' : '') . PHP_EOL;

    foreach ($result['checks'] as $check) {
        echo sprintf('    %s %-8s %s%s', $icons[$check['status']], $check['layer'], $check['label'], $check['detail'] !== '' ? ' — ' . $check['detail'] : '') . PHP_EOL;
    }

    foreach ($result['cleanup'] as $message) {
        echo '    cleanup: ' . $message . PHP_EOL;
    }
}

$summary = $report['summary'];

echo PHP_EOL . sprintf(
    'Self-test: %d passed, %d failed, %d skipped, %d error(s) — %d checks in %d ms',
    $summary['passed'],
    $summary['failed'],
    $summary['skipped'],
    $summary['errors'],
    array_sum($summary['checks']),
    $report['duration_ms']
) . PHP_EOL;

exit(($summary['failed'] > 0 || $summary['errors'] > 0) ? 1 : 0);
