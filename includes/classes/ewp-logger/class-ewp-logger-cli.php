<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp ewp log *` entry points.
 *
 * The commands are declared once in EWP\Surfaces\Resources\Logger_Resource
 * and registered by the kit's Cli_Adapter, together with the matching
 * REST routes and `ewp-logger/*` abilities. This class only keeps the
 * static callables the self-test suite invokes in-process and forwards
 * each to the same operation.
 *
 * Commands:
 *   wp ewp log list    — List log entries.
 *   wp ewp log get     — One entry with its full payload.
 *   wp ewp log trace   — Every entry of one request.
 *   wp ewp log stats   — Statistics for a window.
 *   wp ewp log types   — Registered action types.
 *   wp ewp log write   — Write an entry.
 *   wp ewp log delete  — Delete entries matching filters (--yes).
 *   wp ewp log cleanup — Retention cleanup now.
 *
 * @package    EWP\Logger
 * @author     Motivar
 *
 * @since 1.0.0
 */
class EWP_Logger_CLI
{
    /**
     * Kept for callers that used to register the commands here.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function init()
    {
    }

    public static function list_logs($args, $assoc_args)
    {
        return self::run('search', $args, self::alias_type($assoc_args));
    }

    public static function get_entry($args, $assoc_args)
    {
        return self::run('entry', $args, $assoc_args);
    }

    public static function trace($args, $assoc_args)
    {
        return self::run('trace', $args, $assoc_args);
    }

    public static function stats($args, $assoc_args)
    {
        return self::run('stats', $args, $assoc_args);
    }

    public static function types($args, $assoc_args)
    {
        return self::run('vocabulary', $args, $assoc_args);
    }

    public static function write($args, $assoc_args)
    {
        return self::run('write', $args, $assoc_args);
    }

    public static function delete($args, $assoc_args)
    {
        return self::run('delete', $args, self::alias_type($assoc_args));
    }

    public static function cleanup($args, $assoc_args)
    {
        return self::run('cleanup', $args, $assoc_args);
    }

    /**
     * `--type` stays an alias of `--action_type`.
     *
     * @param array $assoc_args Named arguments.
     *
     * @return array
     */
    private static function alias_type($assoc_args)
    {
        $assoc_args = (array) $assoc_args;
        if (isset($assoc_args['type']) && !isset($assoc_args['action_type'])) {
            $assoc_args['action_type'] = $assoc_args['type'];
            unset($assoc_args['type']);
        }

        return $assoc_args;
    }

    /**
     * @param string $operation  Operation key on the `logger` resource.
     * @param array  $args       Positional arguments.
     * @param array  $assoc_args Named arguments.
     *
     * @return mixed
     *
     * @since 1.5.0
     */
    private static function run($operation, $args, $assoc_args)
    {
        $registry = class_exists('EWP\\Surfaces\\EWP_Surfaces') ? \EWP\Surfaces\EWP_Surfaces::instance()->registry() : null;
        $found    = $registry ? $registry->find('logger', $operation) : null;

        if ($found === null) {
            \WP_CLI::error('The log commands are unavailable: the Extend WP surfaces did not boot.');
            return null;
        }

        return \Gnnpls\WP\Adapters\Cli_Adapter::invoke($found, (array) $args, (array) $assoc_args);
    }
}
