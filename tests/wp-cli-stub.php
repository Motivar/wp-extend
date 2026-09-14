<?php
/**
 * WP-CLI stub for the test suite.
 *
 * The shim ships with the gnnpls/wp-self-test package (it is also what
 * the self-test dashboard uses to call the CLI wrappers inside a web
 * request); this file just loads it before the plugin boots so the
 * plugin's CLI classes register under it.
 */

require_once dirname(__DIR__) . '/lib/gnnpls/wp-self-test/src/Wp_Cli_Shim.php';
