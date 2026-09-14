<?php
/**
 * Version-gated loader.
 *
 * Several active plugins may each bundle a copy of this package in their
 * committed vendor directory. Every copy registers here; on `plugins_loaded`
 * (priority -100) the newest copy installs its autoloader and boots the
 * suite once. This class must stay backwards compatible: whichever plugin
 * loads first declares it, even when a newer copy wins.
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */

namespace Gnnpls\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Loader
{
    const PREFIX = 'Gnnpls\\SelfTest\\';

    /** @var array<string,string> Path => version. */
    private static $copies = [];

    /** @var string|null */
    private static $booted = null;

    /** @var bool */
    private static $hooked = false;

    /** @var bool|null Memoised answer of enabled(). */
    private static $enabled = null;

    /**
     * Register a copy of the package.
     *
     * @param string $version Semantic version.
     * @param string $path    Directory containing bootstrap.php.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function register($version, $path)
    {
        $path = rtrim((string) $path, '/\\');
        if ($path === '') {
            return;
        }

        self::$copies[$path] = (string) $version;

        if (self::$booted !== null || self::$hooked) {
            return;
        }
        self::$hooked = true;

        if (!function_exists('add_action') || did_action('plugins_loaded')) {
            self::boot();
            return;
        }

        add_action('plugins_loaded', [self::class, 'boot'], -100);
    }

    /**
     * Boot the newest registered copy.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function boot()
    {
        if (self::$booted !== null || !self::enabled()) {
            return;
        }

        $path = self::pick_newest(self::$copies);
        if ($path === null) {
            return;
        }

        self::$booted = $path;
        spl_autoload_register([self::class, 'autoload'], true, true);

        Self_Test::instance()->boot();

        /**
         * Fires once the self-test package has booted.
         *
         * @param string $path    Directory of the booted copy.
         * @param string $version Version of the booted copy.
         *
         * @since 0.1.0
         */
        do_action('mwp_self_test_booted', $path, self::version());
    }

    /**
     * Whether the package boots at all on this request.
     *
     * Self-tests create data on the site, so the package is off on
     * production by default: the environment is `WP_ENV` when defined
     * (Bedrock-style) and `wp_get_environment_type()` otherwise, and
     * anything other than `production` enables it. Evaluated once, on
     * `plugins_loaded` (-100), so plugins add the filter at load time.
     *
     * @return bool
     *
     * @since 0.4.0
     */
    public static function enabled()
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $environment = defined('WP_ENV')
            ? (string) WP_ENV
            : (function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production');

        $default = $environment !== 'production';

        /**
         * Filter whether the self-test package boots on this request
         * (surfaces, manifests, dashboard, commands and abilities).
         *
         * @param bool   $enabled     Default: true unless the environment is `production`.
         * @param string $environment `WP_ENV` or `wp_get_environment_type()`.
         *
         * @since 0.4.0
         */
        self::$enabled = function_exists('apply_filters')
            ? (bool) apply_filters('mwp_self_test_enabled', $default, $environment)
            : $default;

        return self::$enabled;
    }

    /**
     * @param array<string,string> $copies Path => version.
     *
     * @return string|null Path with the highest version.
     */
    public static function pick_newest(array $copies)
    {
        $best_path    = null;
        $best_version = null;

        foreach ($copies as $path => $version) {
            if ($best_version === null || version_compare($version, $best_version, '>')) {
                $best_path    = $path;
                $best_version = $version;
            }
        }

        return $best_path;
    }

    /** @return bool */
    public static function is_booted()
    {
        return self::$booted !== null;
    }

    /** @return string Version of the booted copy, empty before boot. */
    public static function version()
    {
        return self::$booted === null ? '' : self::$copies[self::$booted];
    }

    /** @return string Directory of the booted copy, empty before boot. */
    public static function path()
    {
        return self::$booted === null ? '' : self::$booted;
    }

    /** @return array<string,string> */
    public static function copies()
    {
        return self::$copies;
    }

    /**
     * PSR-4 autoloader rooted at the booted copy's src/ directory.
     *
     * @param string $class Fully qualified class name.
     *
     * @return void
     */
    public static function autoload($class)
    {
        if (self::$booted === null || strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $file = self::$booted . '/src/' . str_replace('\\', '/', substr($class, strlen(self::PREFIX))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}
