<?php
/**
 * PHPUnit bootstrap for motivar/extend-wp.
 *
 * Boots the real WordPress core test suite (via the wp-phpunit/wp-phpunit
 * package installed by tests/composer.json) against ABSPATH/DB settings
 * from tests/wp-tests-config.php, then loads this plugin as if it were
 * active. A minimal WP_CLI stub is defined first so the plugin's CLI
 * classes (guarded by `class_exists('WP_CLI')`) register their commands
 * and can be exercised directly in tests/test-content-cli.php and
 * tests/test-cache-flush.php.
 */

require_once __DIR__ . '/wp-cli-stub.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');

$_tests_dir = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__) . '/tests/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test the same way WordPress would activate it,
 * before the test suite installs the schema so its activation hooks (if
 * any run on `plugins_loaded`) fire in a clean database.
 */
tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/extend-wp.php';
});

/**
 * Run the logger in PHPUnit so its REST routes, commands and abilities can
 * be exercised end to end. Entries go to a throwaway directory, never to
 * the site's uploads.
 */
tests_add_filter('ewp_logger_enabled', '__return_true');

/**
 * Build the surfaces registry during bootstrap, as WP-CLI does. On web
 * requests it is built lazily on the first `rest_api_init` / abilities
 * init, but WP_UnitTestCase restores the hook table after every test, so
 * a registry booted inside one test would lose its adapter hooks for the
 * next; booting here makes them part of the baseline hook table.
 */
tests_add_filter('plugins_loaded', function () {
    \EWP\Surfaces\EWP_Surfaces::instance()->boot();
}, -50);
tests_add_filter('ewp_logger_file_directory', function () {
    return sys_get_temp_dir() . '/ewp-logs-phpunit-' . getmypid();
});

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/trait-content-fixture.php';

/**
 * Note: on a fresh test database, the plugin's own built-in content
 * types (ewp_fields, ewp_search, ewp_post_types, ...) log harmless
 * "table does not exist" warnings the first time something touches them
 * — their tables are created on `admin_init`, which a CLI/REST-only test
 * run never fires, and nothing here depends on those tables existing.
 */
