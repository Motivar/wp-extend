<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands for the EWP Logger.
 *
 * Provides CLI access for listing, querying, cleaning up, and inspecting logs.
 * Commands are registered under the 'ewp log' namespace.
 *
 * Commands:
 *   wp ewp log list    — List recent log entries.
 *   wp ewp log cleanup — Manually trigger retention cleanup.
 *   wp ewp log stats   — Show log statistics by owner/type/behaviour.
 *   wp ewp log types   — List all registered action types.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_CLI
{
    /**
     * Initialize CLI commands if WP-CLI is available.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function init()
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('ewp log list', [__CLASS__, 'list_logs']);
        \WP_CLI::add_command('ewp log cleanup', [__CLASS__, 'cleanup']);
        \WP_CLI::add_command('ewp log stats', [__CLASS__, 'stats']);
        \WP_CLI::add_command('ewp log types', [__CLASS__, 'types']);
    }

    /**
     * List recent log entries.
     *
     * ## OPTIONS
     *
     * [--owner=<owner>]
     * : Filter by owner plugin slug.
     *
     * [--type=<action_type>]
     * : Filter by action type key.
     *
     * [--object_type=<object_type>]
     * : Filter by object type.
     *
     * [--behaviour=<behaviour>]
     * : Filter by behaviour (1=success, 0=error).
     *
     * [--level=<level>]
     * : Filter by level (editor or developer).
     *
     * [--limit=<limit>]
     * : Number of entries to return. Default 20.
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default table.
     *
     * ## EXAMPLES
     *
     *     wp ewp log list --owner=filox --limit=10
     *     wp ewp log list --behaviour=0 --format=json
     *     wp ewp log list --type=content_save --level=editor
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function list_logs($args, $assoc_args)
    {
        $logger = EWP_Logger::instance();

        $query_args = [
            'owner'       => $assoc_args['owner'] ?? '',
            'action_type' => $assoc_args['type'] ?? '',
            'object_type' => $assoc_args['object_type'] ?? '',
            'level'       => $assoc_args['level'] ?? '',
            'limit'       => $assoc_args['limit'] ?? 20,
            'order'       => 'DESC',
        ];

        if (isset($assoc_args['behaviour'])) {
            $query_args['behaviour'] = (int) $assoc_args['behaviour'];
        }

        $logs   = $logger->get_logs($query_args);
        $format = $assoc_args['format'] ?? 'table';

        if (empty($logs)) {
            \WP_CLI::success('No log entries found matching the criteria.');
            return;
        }

        // Prepare data for display
        $display = array_map(function ($entry) {
            return [
                'ID'          => $entry['log_id'] ?? '-',
                'Date'        => $entry['created_at'] ?? '',
                'Owner'       => $entry['owner'] ?? '',
                'Action'      => $entry['action_type'] ?? '',
                'Object Type' => $entry['object_type'] ?? '',
                'Level'       => $entry['level'] ?? '',
                'Status'      => ((int) ($entry['behaviour'] ?? 1)) === 1 ? 'OK' : 'ERROR',
                'User'        => $entry['user_id'] ?? 0,
                'Message'     => mb_substr($entry['message'] ?? '', 0, 80),
            ];
        }, $logs);

        \WP_CLI\Utils\format_items($format, $display, array_keys($display[0]));
    }

    /**
     * Manually trigger log cleanup.
     *
     * ## OPTIONS
     *
     * [--months=<months>]
     * : Override retention period in months.
     *
     * ## EXAMPLES
     *
     *     wp ewp log cleanup
     *     wp ewp log cleanup --months=3
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function cleanup($args, $assoc_args)
    {
        $logger  = EWP_Logger::instance();
        $storage = $logger->get_storage();

        if (!$storage) {
            \WP_CLI::error('Logger storage is not initialized.');
            return;
        }

        $cleanup = new EWP_Logger_Cleanup($storage);
        $months  = isset($assoc_args['months']) ? absint($assoc_args['months']) : 0;
        $result  = $cleanup->manual_cleanup($months);

        if (isset($result['deleted'])) {
            \WP_CLI::success(sprintf(
                'Cleanup completed: %d entries deleted (cutoff: %s, retention: %d months).',
                $result['deleted'],
                $result['cutoff_date'],
                $result['retention_months']
            ));
            return;
        }

        \WP_CLI::warning('Cleanup returned no results.');
    }

    /**
     * Show log statistics.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default table.
     *
     * ## EXAMPLES
     *
     *     wp ewp log stats
     *     wp ewp log stats --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function stats($args, $assoc_args)
    {
        $logger = EWP_Logger::instance();
        $format = $assoc_args['format'] ?? 'table';

        $total    = $logger->count_logs([]);
        $errors   = $logger->count_logs(['behaviour' => 0]);
        $success  = $logger->count_logs(['behaviour' => 1]);
        $editor   = $logger->count_logs(['level' => 'editor']);
        $dev      = $logger->count_logs(['level' => 'developer']);

        $stats = [
            ['Metric' => 'Total Entries', 'Count' => $total],
            ['Metric' => 'Success',       'Count' => $success],
            ['Metric' => 'Errors',        'Count' => $errors],
            ['Metric' => 'Editor Level',  'Count' => $editor],
            ['Metric' => 'Developer Level', 'Count' => $dev],
        ];

        // Per-owner stats
        $owners = EWP_Logger::get_registered_owners();
        foreach ($owners as $owner) {
            $count = $logger->count_logs(['owner' => $owner]);
            $stats[] = ['Metric' => "Owner: {$owner}", 'Count' => $count];
        }

        \WP_CLI\Utils\format_items($format, $stats, ['Metric', 'Count']);
    }

    /**
     * List all registered action types.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default table.
     *
     * ## EXAMPLES
     *
     *     wp ewp log types
     *     wp ewp log types --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function types($args, $assoc_args)
    {
        $types  = EWP_Logger::get_registered_types();
        $format = $assoc_args['format'] ?? 'table';

        if (empty($types)) {
            \WP_CLI::success('No action types registered.');
            return;
        }

        $display = [];
        foreach ($types as $owner => $owner_types) {
            foreach ($owner_types as $type_key => $type_data) {
                $display[] = [
                    'Owner'       => $owner,
                    'Type Key'    => $type_key,
                    'Label'       => $type_data['label'],
                    'Description' => $type_data['description'],
                ];
            }
        }

        \WP_CLI\Utils\format_items($format, $display, ['Owner', 'Type Key', 'Label', 'Description']);
    }
}
