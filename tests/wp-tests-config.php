<?php
/**
 * WP core test suite config for motivar/extend-wp.
 *
 * Every value is read from the environment so the same file works locally
 * (inside the DDEV web container, reusing the site's WordPress core and a
 * dedicated `wp_extend_tests` database) and in CI (a fresh WordPress core
 * downloaded by tests/composer.json and a throwaway `mariadb` service
 * database) — see tests/bootstrap.php for the WP_TESTS_CONFIG_FILE_PATH
 * wiring and .githooks/pre-push / .gitlab-ci.yml for the env values used
 * in each context.
 */

define('ABSPATH', rtrim(getenv('WP_CORE_DIR') ?: '/var/www/html', '/\\') . '/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wp_extend_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'db');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'db');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = getenv('WP_TESTS_TABLE_PREFIX') ?: 'wptests_';

define('WP_TESTS_DOMAIN', getenv('WP_TESTS_DOMAIN') ?: 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Extend WP Test Suite');

define('WP_PHP_BINARY', 'php');
define('WPLANG', '');

define('WP_DEBUG', true);
// gnnpls/wp-self-test boots only outside production (filter mwp_self_test_enabled).
define('WP_ENVIRONMENT_TYPE', 'development');
