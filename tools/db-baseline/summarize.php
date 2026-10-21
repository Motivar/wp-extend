<?php
/**
 * Writes summary.md and baseline.json from the recorded queries.
 *
 * Run with: wp eval-file wp-content/plugins/wp-extend/tools/db-baseline/summarize.php
 *
 * @package extend-wp
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bootstrap.php';
require_once __DIR__ . '/class-summary.php';

$ewp_db_baseline_dir    = \EWP\Tools\Db_Baseline\Bootstrap::dir();
$ewp_db_baseline_groups = \EWP\Tools\Db_Baseline\Summary::from_dir($ewp_db_baseline_dir);

file_put_contents($ewp_db_baseline_dir . '/summary.md', \EWP\Tools\Db_Baseline\Summary::markdown($ewp_db_baseline_groups));
file_put_contents(
    $ewp_db_baseline_dir . '/baseline.json',
    wp_json_encode(array_values($ewp_db_baseline_groups), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

file_put_contents(
    $ewp_db_baseline_dir . '/fixtures.json',
    wp_json_encode(\EWP\Tools\Db_Baseline\Summary::fixtures($ewp_db_baseline_groups), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

printf("%d call shapes written to %s/{summary.md,baseline.json,fixtures.json}\n", count($ewp_db_baseline_groups), $ewp_db_baseline_dir);
