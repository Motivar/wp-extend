<?php
/**
 * Optional shared PHPUnit bootstrap for plugins that have none of their own.
 *
 * Boots the WordPress core test suite (wp-phpunit, installed from the
 * consumer's tests/composer.json) with the plugin(s) named in the
 * MWP_PLUGIN_FILES environment variable loaded as if active, and defines
 * the WP_CLI shim first so CLI command classes are declared.
 *
 *   MWP_PLUGIN_FILES=/path/to/plugin.php[,/path/to/other.php]
 *   WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php   (default: <cwd>/tests/wp-tests-config.php)
 *   WP_PHPUNIT__DIR=/path/to/wp-phpunit                     (default: <cwd>/tests/vendor/wp-phpunit/wp-phpunit)
 *
 * A plugin with its own bootstrap keeps it and only requires
 * src/Wp_Cli_Shim.php before loading WordPress.
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */

require_once dirname(__DIR__) . '/src/Wp_Cli_Shim.php';

$mwp_tests_dir = getenv('WP_PHPUNIT__DIR') ?: getcwd() . '/tests/vendor/wp-phpunit/wp-phpunit';

if (!getenv('WP_PHPUNIT__TESTS_CONFIG')) {
    putenv('WP_PHPUNIT__TESTS_CONFIG=' . getcwd() . '/tests/wp-tests-config.php');
}

require_once $mwp_tests_dir . '/includes/functions.php';

$mwp_plugin_files = array_filter(array_map('trim', explode(',', (string) getenv('MWP_PLUGIN_FILES'))));

tests_add_filter('muplugins_loaded', function () use ($mwp_plugin_files) {
    foreach ($mwp_plugin_files as $file) {
        require $file;
    }
});

require $mwp_tests_dir . '/includes/bootstrap.php';
