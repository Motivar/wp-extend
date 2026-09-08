<?php
/**
 * In-process WP-CLI shim.
 *
 * The plugin's CLI wrappers (EWP_Content_CLI, EWP_Logger_CLI,
 * EWP_Options_Portability_CLI, WP_CLI_Integration) guard themselves with
 * `class_exists('WP_CLI')`. Outside a real `wp` process — a PHPUnit run, or
 * the self-test dashboard running inside a wp-admin/REST request — that
 * class does not exist, so the wrappers either never get declared or
 * cannot call WP_CLI::success()/error(). This file defines a minimal
 * WP_CLI that records output instead of printing it and throws
 * \WP_CLI\ExitException instead of exiting, so the exact same command
 * handlers can be exercised in-process and their result asserted.
 *
 * Nothing here is defined when real WP-CLI is present.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */

namespace WP_CLI {

    if (!class_exists(__NAMESPACE__ . '\\ExitException', false)) {
        class ExitException extends \RuntimeException
        {
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\StubRecorder', false)) {
        /**
         * Collects everything the stubbed WP_CLI would have printed.
         */
        class StubRecorder
        {
            /** @var array<int, array{command: string, callable: mixed}> */
            public static $commands = [];

            /** @var string[] */
            public static $success = [];

            /** @var string[] */
            public static $warnings = [];

            /** @var string[] */
            public static $log = [];

            /** @var array<int, mixed> */
            public static $printed = [];

            /**
             * Clear recorded output (registered commands are kept).
             *
             * @return void
             */
            public static function reset()
            {
                self::$success  = [];
                self::$warnings = [];
                self::$log      = [];
                self::$printed  = [];
            }
        }
    }
}

namespace {

    if (!class_exists('WP_CLI')) {
        /**
         * Minimal stand-in for the WP_CLI static API used by this plugin.
         */
        class WP_CLI
        {
            public static function add_command($name, $callable, $args = [])
            {
                \WP_CLI\StubRecorder::$commands[] = ['command' => $name, 'callable' => $callable];
            }

            public static function success($message)
            {
                \WP_CLI\StubRecorder::$success[] = (string) $message;
            }

            public static function warning($message)
            {
                \WP_CLI\StubRecorder::$warnings[] = (string) $message;
            }

            public static function log($message)
            {
                \WP_CLI\StubRecorder::$log[] = (string) $message;
            }

            public static function print_value($value, $assoc_args = [])
            {
                \WP_CLI\StubRecorder::$printed[] = $value;
            }

            /**
             * Real WP-CLI exits the process; the shim throws so callers can
             * catch the failure.
             */
            public static function error($message)
            {
                throw new \WP_CLI\ExitException((string) $message);
            }
        }
    }
}

namespace WP_CLI\Utils {

    if (!function_exists(__NAMESPACE__ . '\\format_items')) {
        /**
         * Records the rows instead of printing a table.
         */
        function format_items($format, $items, $fields)
        {
            \WP_CLI\StubRecorder::$printed[] = ['format' => $format, 'items' => $items, 'fields' => $fields];
        }
    }
}

namespace EWP\SelfTest {

    /**
     * Loads the plugin's CLI wrapper classes on top of the shim.
     *
     * Files that `return` early when WP_CLI is missing never declared their
     * class on plugin load, so they are included again here — plain
     * `include`, not `include_once`, because PHP already marked them as
     * included the first time.
     */
    class WP_CLI_Shim
    {
        /**
         * Whether the shim (not real WP-CLI) is what defines WP_CLI.
         *
         * @return bool
         */
        public static function is_stub()
        {
            return class_exists('WP_CLI\\StubRecorder', false) && !defined('WP_CLI');
        }

        /**
         * Make every plugin CLI command class callable in-process.
         *
         * @return void
         */
        public static function load_plugin_commands()
        {
            $base = dirname(__DIR__);

            if (!class_exists('EWP_Content_CLI', false)) {
                include $base . '/awm-content-db-api/custom-content/class-content-cli.php';
            }

            if (!class_exists('WP_CLI_Integration', false)) {
                include $base . '/wp-cli/class-cli-commands.php';
            }

            if (!class_exists('EWP_Options_Portability_CLI', false)) {
                include_once $base . '/ewp-options-portability/class-options-portability-cli.php';
            }

            if (class_exists('EWP_Options_Portability', false) && class_exists('EWP_Options_Portability_CLI', false)) {
                \EWP_Options_Portability_CLI::init(\EWP_Options_Portability::instance());
            }
        }
    }
}
