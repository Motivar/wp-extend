<?php

namespace EWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answers "which kind of request is this?" at plugin-load time, so
 * Setup.php can skip modules a front-end request never uses.
 *
 * Everything that is not a plain front-end page counts as "tooling":
 * wp-admin (including admin-ajax), WP-CLI or its test shim, cron, XML-RPC
 * and REST requests. REST is detected from the request URI because
 * REST_REQUEST is only defined later, on `parse_request`.
 *
 * @package EWP
 *
 * @since 1.5.1
 */
final class Request_Context
{
    /** @var bool|null Memoised answer for this request. */
    private static $front_end = null;

    /**
     * Whether this is a plain front-end request (no admin, CLI, cron,
     * XML-RPC or REST involvement).
     *
     * @return bool
     *
     * @since 1.5.1
     */
    public static function is_front_end()
    {
        if (self::$front_end !== null) {
            return self::$front_end;
        }

        $front_end = !is_admin()
            && !class_exists('WP_CLI')
            && !(defined('DOING_CRON') && DOING_CRON)
            && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            && !self::is_rest_uri();

        /**
         * Filter whether the current request is treated as a plain front-end
         * request, which skips the admin-only modules (list tables, field
         * builder API, options portability, self-test). Return false to load
         * everything, e.g. for a front-end integration that uses one of them.
         *
         * @param bool $front_end Detected value.
         *
         * @since 1.5.1
         */
        self::$front_end = (bool) apply_filters('ewp_request_is_front_end', $front_end);

        return self::$front_end;
    }

    /**
     * Whether the request URI targets the REST API (pretty or `?rest_route=`).
     *
     * @return bool
     *
     * @since 1.5.1
     */
    public static function is_rest_uri()
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (isset($_GET['rest_route'])) {
            return true;
        }

        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';

        return $uri !== '' && preg_match('#/' . preg_quote(trim($prefix, '/'), '#') . '(/|\?|$)#', $uri) === 1;
    }

    /**
     * Reset the memoised answer (tests only).
     *
     * @return void
     *
     * @since 1.5.1
     */
    public static function reset()
    {
        self::$front_end = null;
    }
}
