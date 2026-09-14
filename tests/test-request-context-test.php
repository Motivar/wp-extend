<?php
/**
 * EWP\Request_Context: request-kind detection used by Setup.php to skip
 * admin-only modules on front-end requests.
 */

use EWP\Request_Context;

class Test_Request_Context extends WP_UnitTestCase
{
    private $uri;

    public function set_up()
    {
        parent::set_up();
        $this->uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        Request_Context::reset();
    }

    public function tear_down()
    {
        $_SERVER['REQUEST_URI'] = $this->uri;
        unset($_GET['rest_route']);
        Request_Context::reset();
        parent::tear_down();
    }

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

    public function test_tooling_requests_are_never_front_end()
    {
        // The suite runs under the WP_CLI shim, which counts as tooling.
        $this->assertTrue(class_exists('WP_CLI'));
        $this->assertFalse(Request_Context::is_front_end());
    }

    public function test_filter_can_override_and_the_answer_is_memoised()
    {
        add_filter('ewp_request_is_front_end', '__return_true');
        $this->assertTrue(Request_Context::is_front_end());
        remove_filter('ewp_request_is_front_end', '__return_true');
        $this->assertTrue(Request_Context::is_front_end(), 'memoised for the request');

        Request_Context::reset();
        $this->assertFalse(Request_Context::is_front_end());
    }
}
