<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract storage backend for the EWP Logger.
 *
 * All storage implementations (DB, File) must extend this class
 * and provide concrete implementations for each method.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
abstract class EWP_Logger_Storage
{
    /**
     * Insert one or more log entries in batch.
     *
     * @param array $entries Array of log entry arrays. Each entry contains:
     *   - owner        (string)  Plugin slug.
     *   - action_type  (string)  Registered action type key.
     *   - object_type  (string)  WP object context (post_type, taxonomy, etc.).
     *   - behaviour    (int)     1 = success, 0 = error.
     *   - level        (string)  'editor' or 'developer'.
     *   - user_id      (int)     WordPress user ID.
     *   - object_id    (int)     Related object ID.
     *   - message      (string)  Human-readable summary.
     *   - data            (string)  Serialized payload.
     *   - request_id      (string)  Unique per-request identifier.
     *   - request_context  (string)  HTTP method + URI or CLI command.
     *   - created_at       (string)  MySQL datetime.
     *
     * @return bool True on success, false on failure.
     *
     * @since 1.0.0
     */
    abstract public function insert(array $entries);

    /**
     * Query log entries with filtering and pagination.
     *
     * @param array $args {
     *   Optional query arguments.
     *
     *   @type string $owner        Filter by owner slug.
     *   @type string $action_type  Filter by action type key.
     *   @type string $object_type  Filter by object type.
     *   @type int    $behaviour    Filter by behaviour (0 = error, 1 = success, 2 = warning).
     *   @type string $level        Filter by level ('editor' or 'developer').
     *   @type int    $user_id      Filter by user ID.
     *   @type string $date_from    Filter from date (Y-m-d H:i:s).
     *   @type string $date_to      Filter to date (Y-m-d H:i:s).
     *   @type string $request_id   Filter by request ID.
     *   @type int    $limit        Max rows to return. Default 50.
     *   @type int    $offset       Offset for pagination. Default 0.
     *   @type string $order        'ASC' or 'DESC'. Default 'DESC'.
     * }
     *
     * @return array Array of log entry arrays.
     *
     * @since 1.0.0
     */
    abstract public function query(array $args = []);

    /**
     * Count log entries matching the given filters.
     *
     * @param array $args Same filter arguments as query() (without limit/offset/order).
     *
     * @return int Total count of matching entries.
     *
     * @since 1.0.0
     */
    abstract public function count(array $args = []);

    /**
     * Delete log entries older than a given number of months.
     *
     * @param int $months Number of months. Entries older than this are deleted.
     *
     * @return int Number of entries deleted, or -1 on failure.
     *
     * @since 1.0.0
     */
    abstract public function delete_older_than($months);

    /**
     * Sanitize and normalize query arguments with defaults.
     *
     * @param array $args Raw query arguments.
     *
     * @return array Normalized arguments with defaults applied.
     *
     * @since 1.0.0
     */
    protected function normalize_query_args(array $args)
    {
        /**
         * Filter the default query arguments for the logger storage.
         *
         * @param array $defaults Default query arguments.
         *
         * @since 1.0.0
         */
        $defaults = apply_filters('ewp_logger_query_defaults', [
            'owner'       => '',
            'action_type' => '',
            'object_type' => '',
            'behaviour'   => null,
            'level'       => '',
            'user_id'     => 0,
            'date_from'   => '',
            'date_to'     => '',
            'request_id'  => '',
            'limit'       => 50,
            'offset'      => 0,
            'order'       => 'DESC',
        ]);

        $args = wp_parse_args($args, $defaults);

        // Sanitize values
        $args['owner']       = sanitize_text_field($args['owner']);
        $args['action_type'] = sanitize_text_field($args['action_type']);
        $args['object_type'] = sanitize_text_field($args['object_type']);
        $args['level']       = in_array($args['level'], ['editor', 'developer'], true) ? $args['level'] : '';
        $args['user_id']     = absint($args['user_id']);
        $args['request_id']  = sanitize_text_field($args['request_id']);
        $args['limit']       = max(1, min(500, absint($args['limit'])));
        $args['offset']      = max(0, absint($args['offset']));
        $args['order']       = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Behaviour: null means no filter, otherwise cast to valid int (0, 1, 2)
        if ($args['behaviour'] !== null && $args['behaviour'] !== '') {
            $args['behaviour'] = EWP_Logger::normalize_behaviour($args['behaviour']);
        }

        return $args;
    }
}
