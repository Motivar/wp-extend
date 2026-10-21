<?php
/**
 * Plugin Name: EWP DB baseline recorder (temporary)
 * Description: Records every extend-wp DB-layer query for the AWM_DB_Creator rewrite baseline. Copy to wp-content/mu-plugins/ewp-db-baseline.php on a dev site; delete when done.
 *
 * @package extend-wp
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('EWP_DB_BASELINE')) {
    define('EWP_DB_BASELINE', true);
}

$ewp_db_baseline = WP_CONTENT_DIR . '/plugins/wp-extend/tools/db-baseline/class-bootstrap.php';
if (is_readable($ewp_db_baseline)) {
    require_once $ewp_db_baseline;
    \EWP\Tools\Db_Baseline\Bootstrap::start();
}
unset($ewp_db_baseline);
