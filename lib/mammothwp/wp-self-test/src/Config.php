<?php
/**
 * Package-wide settings, each overridable with a filter.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */

namespace MammothWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Config
{
    /** Option holding the last report and the contexts awaiting cleanup. */
    const STATE_OPTION = 'mmwp_self_test_state';

    /** Text domain of the package strings. */
    const TEXT_DOMAIN = 'wp-self-test';

    /**
     * Capability required to run self-tests on every surface.
     *
     * @return string
     *
     * @since 0.1.0
     */
    public static function capability()
    {
        /**
         * Filter the capability required to run the self-tests.
         *
         * @param string $capability Default 'manage_options'.
         *
         * @since 0.1.0
         */
        return (string) apply_filters('mmwp_self_test_capability', 'manage_options');
    }

    /**
     * The production gate for a site where an older Loader (< 0.7.0,
     * declared by another plugin's copy) booted this copy: same filter
     * and default as Loader::enabled() in 0.7.0+.
     *
     * @return bool
     *
     * @since 0.7.0
     */
    public static function enabled()
    {
        $environment = self::environment();

        /** This filter is documented in src/Loader.php */
        return (bool) apply_filters('mmwp_self_test_enabled', $environment !== 'production', $environment);
    }

    /**
     * The site's environment, also when an older Loader (< 0.6.1) without
     * Loader::environment() is the one declared.
     *
     * @return string
     *
     * @since 0.7.0
     */
    public static function environment()
    {
        if (method_exists(Loader::class, 'environment')) {
            return Loader::environment();
        }

        if (defined('WP_ENV')) {
            return (string) WP_ENV;
        }

        return function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
    }

    /**
     * Whether the wp-admin dashboard and its REST routes are registered.
     *
     * Off on production, because a run creates data on the site: on
     * whenever Loader::environment() is anything but `production`.
     *
     * @return bool
     *
     * @since 0.1.0
     */
    public static function ui_enabled()
    {
        /**
         * Filter whether the self-test dashboard (and its REST routes) are registered.
         *
         * @param bool   $enabled     Default: true unless the environment is `production`.
         * @param string $environment `WP_ENV` or `wp_get_environment_type()`.
         *
         * @since 0.1.0
         * @since 0.6.0 Default follows the environment instead of WP_DEBUG; `$environment` added.
         */
        $environment = self::environment();

        return (bool) apply_filters('mmwp_self_test_ui_enabled', $environment !== 'production', $environment);
    }

    /**
     * Whether the in-process WP_CLI shim is loaded on web requests, so the
     * dashboard can exercise CLI commands. Only where the dashboard is on.
     *
     * @return bool
     *
     * @since 0.1.0
     */
    public static function shim_enabled()
    {
        /**
         * Filter whether the WP_CLI shim is defined on web requests.
         *
         * @param bool $enabled Default: the dashboard is enabled.
         *
         * @since 0.1.0
         */
        return (bool) apply_filters('mmwp_self_test_shim_enabled', self::ui_enabled());
    }

    /**
     * Whether abilities can be executed on this site.
     *
     * @return bool
     *
     * @since 0.1.0
     */
    public static function abilities_available()
    {
        /**
         * Filter whether the Abilities API is usable for self-tests; a host
         * plugin that gates its own abilities can report that here.
         *
         * @param bool $available Default: `function_exists('wp_get_ability')`.
         *
         * @since 0.1.0
         */
        return (bool) apply_filters('mmwp_self_test_abilities_available', function_exists('wp_get_ability'));
    }

    /**
     * Public URL of a file shipped with the booted copy of the package.
     *
     * @param string $relative Path relative to the package root.
     *
     * @return string
     *
     * @since 0.1.0
     */
    public static function asset_url($relative)
    {
        $file = Loader::path() . '/' . ltrim((string) $relative, '/');
        $url  = plugins_url(basename($file), $file);

        if (defined('WP_CONTENT_DIR') && strpos($file, wp_normalize_path(WP_CONTENT_DIR)) === 0) {
            $url = content_url(str_replace(wp_normalize_path(WP_CONTENT_DIR), '', wp_normalize_path($file)));
        }

        /**
         * Filter the URL of a package asset.
         *
         * @param string $url      Resolved URL.
         * @param string $relative Path relative to the package root.
         *
         * @since 0.1.0
         */
        return (string) apply_filters('mmwp_self_test_asset_url', $url, $relative);
    }
}
