<?php
/**
 * Version-gated loader for the Motivar WP kit.
 *
 * Several active plugins may each bundle a copy of the kit. Every copy
 * registers itself here; on `plugins_loaded` (priority -100) the newest
 * copy installs its autoloader and every `on_ready()` callback runs.
 *
 * This class must stay backwards compatible: whichever plugin loads first
 * declares it, even when a newer copy later wins the autoloader.
 *
 * @package Motivar\WP
 * @since   0.1.0
 */

namespace Motivar\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Kit
{
    /**
     * Namespace prefix served by the autoloader.
     *
     * @var string
     */
    const PREFIX = 'Motivar\\WP\\';

    /**
     * Registered copies: absolute path => version string.
     *
     * @var array<string,string>
     */
    private static $copies = [];

    /**
     * Path of the copy that booted, or null before boot.
     *
     * @var string|null
     */
    private static $booted = null;

    /**
     * Callbacks waiting for boot.
     *
     * @var callable[]
     */
    private static $ready = [];

    /**
     * Whether the boot hook has been attached.
     *
     * @var bool
     */
    private static $hooked = false;

    /**
     * Register a copy of the kit.
     *
     * @param string $version Semantic version of the copy.
     * @param string $path    Absolute directory containing bootstrap.php.
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
     * Boot the newest registered copy and flush the ready queue.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function boot()
    {
        if (self::$booted !== null) {
            return;
        }

        $path = self::pick_newest(self::$copies);
        if ($path === null) {
            return;
        }

        self::$booted = $path;
        spl_autoload_register([self::class, 'autoload'], true, true);

        if (function_exists('do_action')) {
            /**
             * Fires once the kit has chosen a copy and installed its autoloader.
             *
             * @param string $path    Directory of the booted copy.
             * @param string $version Version of the booted copy.
             *
             * @since 0.1.0
             */
            do_action('mwp_kit_booted', $path, self::version());
        }

        $queue       = self::$ready;
        self::$ready = [];
        foreach ($queue as $callback) {
            call_user_func($callback);
        }
    }

    /**
     * Pick the path with the highest version.
     *
     * @param array<string,string> $copies Path => version.
     *
     * @return string|null
     *
     * @since 0.1.0
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

    /**
     * Run a callback once the kit is booted (immediately when it already is).
     *
     * @param callable $callback Callback receiving no arguments.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function on_ready(callable $callback)
    {
        if (self::$booted !== null) {
            call_user_func($callback);
            return;
        }

        self::$ready[] = $callback;
    }

    /**
     * Whether a copy has booted.
     *
     * @return bool
     *
     * @since 0.1.0
     */
    public static function is_booted()
    {
        return self::$booted !== null;
    }

    /**
     * Version of the booted copy.
     *
     * @return string Empty before boot.
     *
     * @since 0.1.0
     */
    public static function version()
    {
        if (self::$booted === null) {
            return '';
        }

        return self::$copies[self::$booted];
    }

    /**
     * Directory of the booted copy.
     *
     * @return string Empty before boot.
     *
     * @since 0.1.0
     */
    public static function path()
    {
        return self::$booted === null ? '' : self::$booted;
    }

    /**
     * Every registered copy.
     *
     * @return array<string,string> Path => version.
     *
     * @since 0.1.0
     */
    public static function copies()
    {
        return self::$copies;
    }

    /**
     * PSR-4 style autoloader rooted at the booted copy's src/ directory.
     *
     * @param string $class Fully qualified class name.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function autoload($class)
    {
        if (self::$booted === null || strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $file     = self::$booted . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
