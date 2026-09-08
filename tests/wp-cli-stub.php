<?php
/**
 * WP-CLI stub for the test suite.
 *
 * The stub itself lives in the plugin (it is also what the self-test
 * dashboard uses to call the CLI wrappers inside a web request), so this
 * file just loads it before the plugin boots. See
 * includes/classes/ewp-self-test/class-ewp-self-test-wp-cli-shim.php.
 */

require_once dirname(__DIR__) . '/includes/classes/ewp-self-test/class-ewp-self-test-wp-cli-shim.php';
