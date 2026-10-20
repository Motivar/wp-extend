<?php
/**
 * EWP\Request_Context: request-kind detection used by Setup.php to skip
 * admin-only modules on front-end requests.
 */

use EWP\Request_Context;

class Test_Request_Context extends WP_UnitTestCase
{
    private $uri;

    /**
     * Remember the request URI and clear the memoised request kind.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $this->uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        Request_Context::reset();
    }

    /**
     * Restore the request URI and clear the memoised request kind.
     *
     * @return void
     */
    public function tear_down()
    {
        $_SERVER['REQUEST_URI'] = $this->uri;
        unset($_GET['rest_route']);
        Request_Context::reset();
        parent::tear_down();
    }

    /**
     * REST URIs are detected in pretty, index and ?rest_route= forms only.
     *
     * @return void
     */
    public function test_rest_uri_detection_covers_pretty_index_and_query_forms()
    {
        foreach (['/wp-json', '/wp-json/', '/wp-json/extend-wp/v1/logs', '/site/wp-json?x=1'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertTrue(Request_Context::is_rest_uri(), "$uri should be REST");
        }
        foreach (['/', '/wp-jsonish/', '/blog/wp-json-archive/'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse(Request_Context::is_rest_uri(), "$uri should not be REST");
        }

        $_SERVER['REQUEST_URI'] = '/';
        $_GET['rest_route'] = '/extend-wp/v1/logs';
        $this->assertTrue(Request_Context::is_rest_uri());
    }

    /**
     * The WP_CLI shim counts as tooling, never as a front-end request.
     *
     * @return void
     */
    public function test_tooling_requests_are_never_front_end()
    {
        // The suite runs under the WP_CLI shim, which counts as tooling.
        $this->assertTrue(class_exists('WP_CLI'));
        $this->assertFalse(Request_Context::is_front_end());
    }

    /**
     * ewp_request_is_front_end overrides detection and the answer is memoised.
     *
     * @return void
     */
    public function test_filter_can_override_and_the_answer_is_memoised()
    {
        add_filter('ewp_request_is_front_end', '__return_true');
        $this->assertTrue(Request_Context::is_front_end());
        remove_filter('ewp_request_is_front_end', '__return_true');
        $this->assertTrue(Request_Context::is_front_end(), 'memoised for the request');

        Request_Context::reset();
        $this->assertFalse(Request_Context::is_front_end());
    }

    /**
     * The self-test package defaults to off on production (0.7.0+).
     *
     * @return void
     */
    public function test_self_test_package_is_off_on_production_by_default()
    {
        $this->assertTrue(defined('MammothWP\\SelfTest\\Loader::GATES_PRODUCTION'));

        $seen = null;
        $spy = function ($enabled, $environment) use (&$seen) {
            $seen = [$enabled, $environment];
            return $enabled;
        };
        add_filter('mmwp_self_test_enabled', $spy, 10, 2);
        \MammothWP\SelfTest\Config::enabled();
        remove_filter('mmwp_self_test_enabled', $spy, 10);

        $this->assertSame([$seen[1] !== 'production', \MammothWP\SelfTest\Loader::environment()], $seen);
        // The suite runs with WP_ENVIRONMENT_TYPE=development, so the package is on.
        $this->assertTrue($seen[0]);
    }
}
