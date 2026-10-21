<?php
/**
 * Starts the DB baseline recorder when explicitly enabled.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gatekeeper: records only when EWP_DB_BASELINE is true and the environment
 * is not production. Never loaded by the plugin itself — only through the
 * temporary mu-plugin stub (see README.md).
 */
class Bootstrap
{
    /**
     * Whether recording may run in this environment.
     *
     * Off under the WordPress test suite: PHPUnit boots the site's own core
     * and mu-plugins, and its throwaway-database traffic must not end up in
     * the baseline.
     *
     * @return bool True when enabled by constant, not on production and not in PHPUnit.
     */
    public static function enabled()
    {
        if (!defined('EWP_DB_BASELINE') || !EWP_DB_BASELINE || defined('WP_TESTS_DOMAIN')) {
            return false;
        }
        return wp_get_environment_type() !== 'production';
    }

    /**
     * Directory records are written to.
     *
     * @return string Absolute path; EWP_DB_BASELINE_DIR overrides the default.
     */
    public static function dir()
    {
        if (defined('EWP_DB_BASELINE_DIR') && EWP_DB_BASELINE_DIR) {
            return (string) EWP_DB_BASELINE_DIR;
        }
        return WP_CONTENT_DIR . '/ewp-db-baseline';
    }

    /**
     * Load the classes and attach the recorder when enabled.
     *
     * @return Query_Recorder|null The running recorder, or null when disabled.
     */
    public static function start()
    {
        if (!self::enabled()) {
            return null;
        }
        require_once __DIR__ . '/class-shape.php';
        require_once __DIR__ . '/class-call-site.php';
        require_once __DIR__ . '/class-record-writer.php';
        require_once __DIR__ . '/class-query-recorder.php';
        $recorder = new Query_Recorder(new Record_Writer(self::dir()), wp_generate_uuid4());
        $recorder->register();
        return $recorder;
    }
}
