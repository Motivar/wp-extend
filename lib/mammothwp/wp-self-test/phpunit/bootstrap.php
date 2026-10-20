<?php
/**
 * Optional shared PHPUnit bootstrap for plugins that have none of their own.
 *
 * Boots the WordPress core test suite (wp-phpunit, installed from the
 * consumer's tests/composer.json) with the plugin(s) named in the
 * MMWP_PLUGIN_FILES environment variable loaded as if active, and defines
 * the WP_CLI shim first so CLI command classes are declared.
 *
 *   MMWP_PLUGIN_FILES=/path/to/plugin.php[,/path/to/other.php]
 *   WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php   (default: <cwd>/tests/wp-tests-config.php)
 *   WP_PHPUNIT__DIR=/path/to/wp-phpunit                     (default: <cwd>/tests/vendor/wp-phpunit/wp-phpunit)
 *
 * A plugin with its own bootstrap keeps it and only requires
 * src/Wp_Cli_Shim.php before loading WordPress.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */

require_once dirname(__DIR__) . '/src/Wp_Cli_Shim.php';

$mmmwp_tests_dir = getenv('WP_PHPUNIT__DIR') ?: getcwd() . '/tests/vendor/wp-phpunit/wp-phpunit';

if (!getenv('WP_PHPUNIT__TESTS_CONFIG')) {
    putenv('WP_PHPUNIT__TESTS_CONFIG=' . getcwd() . '/tests/wp-tests-config.php');
}

require_once $mmmwp_tests_dir . '/includes/functions.php';

$mmwp_plugin_files = array_filter(array_map('trim', explode(',', (string) (getenv('MMWP_PLUGIN_FILES') ?: getenv('MWP_PLUGIN_FILES')))));

tests_add_filter('muplugins_loaded', function () use ($mmwp_plugin_files) {
    foreach ($mmwp_plugin_files as $file) {
        require $file;
    }
});

require $mmmwp_tests_dir . '/includes/bootstrap.php';
