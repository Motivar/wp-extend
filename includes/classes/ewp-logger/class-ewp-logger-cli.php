<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands for the EWP Logger.
 *
 * Thin wrappers around EWP_Logger_Query, the same query layer the REST
 * route and the abilities use, so every filter, default and label is
 * identical on the command line.
 *
 * Commands:
 *   wp ewp log list    — List log entries.
 *   wp ewp log cleanup — Manually trigger retention cleanup.
 *   wp ewp log stats   — Show log statistics for a window.
 *   wp ewp log types   — List all registered action types.
 *
 * @package    EWP\Logger
 * @author     Motivar
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
     * List log entries.
     *
     * Every filter the REST route and the abilities accept works here too,
     * including filters other plugins register via `ewp_logger_filter_params`.
     *
     * ## OPTIONS
     *
     * [--owner=<owner>]
     * : Filter by owner plugin slug. Comma separated for several.
     *
     * [--type=<action_type>]
     * : Filter by action type key. Comma separated for several. Alias of --action_type.
     *
     * [--object_type=<object_type>]
     * : Filter by object type. Comma separated for several.
     *
     * [--object_id=<object_id>]
     * : Filter by object id. Comma separated for several.
     *
     * [--behaviour=<behaviour>]
     * : Filter by behaviour: error, success or warning (storage integers 0, 1, 2 also accepted).
     *
     * [--level=<level>]
     * : Filter by level: editor or developer.
     *
     * [--user_id=<user_id>]
     * : Filter by the acting user's id.
     *
     * [--request_id=<request_id>]
     * : Only entries recorded during this request.
     *
     * [--search_text=<text>]
     * : Free-text match against the message and payload.
     *
     * [--date_from=<date>]
     * : Start of the window, Y-m-d or d-m-Y. Unbounded when omitted.
     *
     * [--date_to=<date>]
     * : End of the window, Y-m-d or d-m-Y. Unbounded when omitted.
     *
     * [--limit=<limit>]
     * : Number of entries to return. Default 50, maximum 500.
     *
     * [--offset=<offset>]
     * : Entries to skip. Default 0.
     *
     * [--order=<order>]
     * : ASC or DESC by date. Default DESC.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default table.
     *
     * ## EXAMPLES
     *
     *     wp ewp log list --owner=filox --limit=10
     *     wp ewp log list --behaviour=error --date_from=2026-09-01 --format=json
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
        $input = self::input_from($assoc_args);
        $result = self::query()->search($input, ['shape' => 'full', 'window' => null]);
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($result['entries'] === []) {
            \WP_CLI::success('No log entries found matching the criteria.');
            return;
        }

        $display = array_map(function ($entry) {
            return [
                'ID'          => isset($entry['log_id']) ? $entry['log_id'] : '-',
                'Date'        => isset($entry['created_at']) ? $entry['created_at'] : '',
                'Owner'       => isset($entry['owner']) ? $entry['owner'] : '',
                'Action'      => isset($entry['action_type']) ? $entry['action_type'] : '',
                'Object Type' => isset($entry['object_type']) ? $entry['object_type'] : '',
                'Level'       => isset($entry['level']) ? $entry['level'] : '',
                'Status'      => isset($entry['behaviour_label']) ? $entry['behaviour_label'] : '',
                'User'        => !empty($entry['user_display_name']) ? $entry['user_display_name'] : (isset($entry['user_id']) ? $entry['user_id'] : 0),
                'Message'     => mb_substr(isset($entry['message']) ? $entry['message'] : '', 0, 80),
            ];
        }, $result['entries']);

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
        $months = isset($assoc_args['months']) ? absint($assoc_args['months']) : 0;
        $result = self::query()->cleanup($months);

        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
            return;
        }

        \WP_CLI::success(sprintf(
            'Cleanup completed: %d entries deleted (cutoff: %s, retention: %d months).',
            isset($result['deleted']) ? $result['deleted'] : 0,
            isset($result['cutoff_date']) ? $result['cutoff_date'] : '',
            isset($result['retention_months']) ? $result['retention_months'] : 0
        ));
    }

    /**
     * Show log statistics for a window, in a single scan.
     *
     * ## OPTIONS
     *
     * [--date_from=<date>]
     * : Start of the window, Y-m-d or d-m-Y. Default 7 days ago.
     *
     * [--date_to=<date>]
     * : End of the window, Y-m-d or d-m-Y. Default today.
     *
     * [--owner=<owner>]
     * : Restrict to one owner (comma separated for several).
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default table.
     *
     * ## EXAMPLES
     *
     *     wp ewp log stats
     *     wp ewp log stats --date_from=2026-01-01 --format=json
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
        $stats  = self::query()->stats(self::input_from($assoc_args));
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $rows = [
            ['Metric' => 'Window', 'Count' => $stats['date_from'] . ' → ' . $stats['date_to']],
            ['Metric' => 'Total Entries', 'Count' => $stats['total']],
            ['Metric' => 'Success', 'Count' => $stats['by_behaviour']['success']],
            ['Metric' => 'Errors', 'Count' => $stats['by_behaviour']['error']],
            ['Metric' => 'Warnings', 'Count' => $stats['by_behaviour']['warning']],
        ];

        foreach ($stats['by_level'] as $level => $count) {
            $rows[] = ['Metric' => ucfirst($level) . ' Level', 'Count' => $count];
        }

        foreach ($stats['by_owner'] as $owner => $count) {
            $rows[] = ['Metric' => "Owner: {$owner}", 'Count' => $count];
        }

        if (!empty($stats['capped'])) {
            \WP_CLI::warning($stats['hint']);
        }

        \WP_CLI\Utils\format_items($format, $rows, ['Metric', 'Count']);
    }

    /**
     * List all registered action types.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default table.
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
        $types  = self::query()->vocabulary()['action_types'];
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($types === []) {
            \WP_CLI::success('No action types registered.');
            return;
        }

        $display = array_map(function ($type) {
            return [
                'Owner'       => $type['owner'],
                'Type Key'    => $type['key'],
                'Label'       => $type['label'],
                'Description' => $type['description'],
            ];
        }, $types);

        \WP_CLI\Utils\format_items($format, $display, ['Owner', 'Type Key', 'Label', 'Description']);
    }

    /**
     * The shared query layer.
     *
     * @return EWP_Logger_Query
     *
     * @since 1.5.0
     */
    private static function query()
    {
        return new EWP_Logger_Query();
    }

    /**
     * Translate command-line flags into the input every surface shares.
     *
     * `--type` is kept as an alias of `--action_type`; everything else is
     * passed through by name, so a filter registered via
     * `ewp_logger_filter_params` works as `--<param>=<value>`.
     *
     * @param array $assoc_args Named arguments.
     *
     * @return array
     *
     * @since 1.5.0
     */
    private static function input_from(array $assoc_args)
    {
        $input = $assoc_args;
        unset($input['format']);

        if (isset($input['type']) && !isset($input['action_type'])) {
            $input['action_type'] = $input['type'];
        }
        unset($input['type']);

        return $input;
    }
}
