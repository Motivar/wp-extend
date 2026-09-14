#!/usr/bin/env php
<?php
/**
 * Run the registered self-test suites outside PHPUnit, with cleanup.
 *
 * Boots WordPress through a bootstrap file that must load the plugins under
 * test (a PHPUnit-style bootstrap using wp-phpunit does exactly that), then
 * runs every registered manifest — or one plugin's, or given case ids —
 * and exits 1 when any case fails or errors, so a hook or CI job blocks.
 *
 *   php bin/run.php [--bootstrap=tests/bootstrap.php] [--autoload=tests/vendor/autoload.php] [--plugin=<slug>] [case,ids]
 *
 * Environment: MWP_SELF_TEST_BOOTSTRAP and MWP_SELF_TEST_AUTOLOAD provide
 * the same two paths. Relative paths resolve against the working directory.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options   = getopt('', ['bootstrap::', 'autoload::', 'plugin::', 'help']);
$arguments = array_values(array_filter(array_slice($argv, 1), function ($arg) {
    return strpos($arg, '--') !== 0;
}));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/run.php [--bootstrap=FILE] [--autoload=FILE] [--plugin=SLUG] [case,ids]\n");
    exit(0);
}

$resolve = function ($path) {
    if ($path === '' || $path === null) {
        return '';
    }

    return $path[0] === '/' ? $path : getcwd() . '/' . $path;
};

$autoload  = $resolve(isset($options['autoload']) ? $options['autoload'] : (getenv('MWP_SELF_TEST_AUTOLOAD') ?: 'tests/vendor/autoload.php'));
$bootstrap = $resolve(isset($options['bootstrap']) ? $options['bootstrap'] : (getenv('MWP_SELF_TEST_BOOTSTRAP') ?: 'tests/bootstrap.php'));

if ($autoload !== '' && is_file($autoload)) {
    require $autoload;
}

if (!is_file($bootstrap)) {
    fwrite(STDERR, "Self-test could not run: bootstrap file not found at {$bootstrap}. Pass --bootstrap=FILE or set MWP_SELF_TEST_BOOTSTRAP.\n");
    exit(1);
}

require $bootstrap;

if (!class_exists('Motivar\\SelfTest\\Self_Test')) {
    fwrite(STDERR, "Self-test could not run: the bootstrap loaded WordPress but not the motivar/wp-self-test package (is it required by the plugin under test?).\n");
    exit(1);
}

$ids    = isset($arguments[0]) ? array_values(array_filter(explode(',', $arguments[0]))) : [];
$plugin = isset($options['plugin']) ? (string) $options['plugin'] : '';
$runner = \Motivar\SelfTest\Self_Test::instance()->runner();
$report = $runner->run($ids, true, $plugin);

if (is_wp_error($report)) {
    fwrite(STDERR, 'Self-test could not run: ' . $report->get_error_message() . PHP_EOL);
    exit(1);
}

$icons = ['pass' => '✓', 'fail' => '✗', 'skip' => '-'];

foreach ($report['results'] as $result) {
    echo sprintf('[%s] %s/%s — %s%s', strtoupper($result['status']), $result['plugin'], $result['id'], $result['label'], $result['message'] !== '' ? ' (' . $result['message'] . ')' : '') . PHP_EOL;

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
