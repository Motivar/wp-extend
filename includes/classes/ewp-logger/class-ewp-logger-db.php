<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database storage backend for the EWP Logger.
 *
 * Stores log entries in a custom DB table (ewp_logs) created via AWM_DB_Creator.
 * Supports batch insert for performance and all CRUD operations needed by the logger.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_DB extends EWP_Logger_Storage
{
    /**
     * Custom table name (without WP prefix).
     *
     * @var string
     */
    private static $table_name = 'ewp_logs';

    /**
     * Table version for schema migrations.
     *
     * @var string
     */
    private static $table_version = '1.1.0';

    /**
     * Whether the table has been initialized in this request.
     *
     * @var bool
     */
    private static $table_initialized = false;

    /**
     * Initialize the DB table on first use.
     *
     * Creates the ewp_logs table if it does not exist, using AWM_DB_Creator.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init_table()
    {
        if (self::$table_initialized) {
            return;
        }

        /**
         * Filter the logger DB table schema definition.
         *
         * @param array $table_data Table schema definition array.
         *
         * @since 1.0.0
         */
        $table_data = apply_filters('ewp_logger_db_table_schema', [
            self::$table_name => [
                'data' => [
                    'log_id'      => 'bigint(20) NOT NULL AUTO_INCREMENT',
                    'owner'       => 'VARCHAR(80) NOT NULL',
                    'action_type' => 'VARCHAR(80) NOT NULL',
                    'object_type' => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
                    'behaviour'   => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'level'       => 'VARCHAR(20) NOT NULL DEFAULT \'editor\'',
                    'user_id'     => 'bigint(20) UNSIGNED NULL',
                    'object_id'   => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
                    'message'     => 'TEXT NULL',
                    'data'            => 'LONGTEXT NULL',
                    'request_id'      => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
                    'request_context' => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
                    'created_at'      => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primaryKey' => 'log_id',
                'index'      => [
                    ['owner', 'action_type'],
                    ['object_type'],
                    ['behaviour'],
                    ['level'],
                    ['request_id'],
                    ['created_at'],
                ],
                'version' => self::$table_version,
            ],
        ]);

        new \AWM_DB_Creator($table_data);
        self::$table_initialized = true;
    }

    /**
     * Insert one or more log entries in a single batch query.
     *
     * Uses a multi-row INSERT for performance when flushing the queue.
     *
     * @param array $entries Array of log entry arrays.
     *
     * @return bool True on success, false on failure.
     *
     * @since 1.0.0
     */
    public function insert(array $entries)
    {
        if (empty($entries)) {
            return true;
        }

        $this->init_table();

        global $wpdb;

        $table = $wpdb->prefix . self::$table_name;

        $columns = [
            'owner',
            'action_type',
            'object_type',
            'behaviour',
            'level',
            'user_id',
            'object_id',
            'message',
            'data',
            'request_id',
            'request_context',
            'created_at',
        ];

        $placeholders = [];
        $values       = [];

        foreach ($entries as $entry) {
            $placeholders[] = '(%s, %s, %s, %d, %s, %d, %d, %s, %s, %s, %s, %s)';
            $values[]       = sanitize_text_field($entry['owner'] ?? '');
            $values[]       = sanitize_text_field($entry['action_type'] ?? '');
            $values[]       = sanitize_text_field($entry['object_type'] ?? '');
            $values[]       = isset($entry['behaviour']) ? (int) $entry['behaviour'] : 1;
            $values[]       = sanitize_text_field($entry['level'] ?? 'editor');
            $values[]       = absint($entry['user_id'] ?? 0);
            $values[]       = absint($entry['object_id'] ?? 0);
            $values[]       = $entry['message'] ?? '';
            $values[]       = $entry['data'] ?? '';
            $values[]       = sanitize_text_field($entry['request_id'] ?? '');
            $values[]       = sanitize_text_field($entry['request_context'] ?? '');
            $values[]       = $entry['created_at'] ?? current_time('mysql');
        }

        $column_list = implode(', ', $columns);
        $placeholder_list = implode(', ', $placeholders);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} ({$column_list}) VALUES {$placeholder_list}",
            $values
        );

        $result = $wpdb->query($sql);

        if ($result === false) {
            error_log('EWP Logger DB insert failed: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Query log entries with filtering and pagination.
     *
     * @param array $args Query arguments (see EWP_Logger_Storage::query).
     *
     * @return array Array of log entry arrays.
     *
     * @since 1.0.0
     */
    public function query(array $args = [])
    {
        $this->init_table();

        global $wpdb;

        $args  = $this->normalize_query_args($args);
        $table = $wpdb->prefix . self::$table_name;

        $where  = $this->build_where_clause($args);
        $sql    = "SELECT * FROM {$table}";

        if (!empty($where['clause'])) {
            $sql .= ' WHERE ' . $where['clause'];
        }

        $sql .= ' ORDER BY created_at ' . $args['order'];
        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);

        if (!empty($where['values'])) {
            $sql = $wpdb->prepare($sql, $where['values']);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        if ($results === null) {
            return [];
        }

        return stripslashes_deep($results);
    }

    /**
     * Count log entries matching the given filters.
     *
     * @param array $args Filter arguments (same as query without limit/offset/order).
     *
     * @return int Total count.
     *
     * @since 1.0.0
     */
    public function count(array $args = [])
    {
        $this->init_table();

        global $wpdb;

        $args  = $this->normalize_query_args($args);
        $table = $wpdb->prefix . self::$table_name;

        $where = $this->build_where_clause($args);
        $sql   = "SELECT COUNT(*) FROM {$table}";

        if (!empty($where['clause'])) {
            $sql .= ' WHERE ' . $where['clause'];
        }

        if (!empty($where['values'])) {
            $sql = $wpdb->prepare($sql, $where['values']);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Delete log entries older than a given number of months.
     *
     * @param int $months Number of months.
     *
     * @return int Number of entries deleted, or -1 on failure.
     *
     * @since 1.0.0
     */
    public function delete_older_than($months)
    {
        $this->init_table();

        global $wpdb;

        $months     = max(1, absint($months));
        $cutoff     = date('Y-m-d H:i:s', strtotime("-{$months} months"));
        $table      = $wpdb->prefix . self::$table_name;

        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at < %s",
            $cutoff
        );
        $count = (int) $wpdb->get_var($count_sql);

        if ($count === 0) {
            return 0;
        }

        $delete_sql = $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        );
        $result = $wpdb->query($delete_sql);

        if ($result === false) {
            error_log('EWP Logger DB cleanup failed: ' . $wpdb->last_error);
            return -1;
        }

        return (int) $result;
    }

    /**
     * Build a WHERE clause and values array from normalized query args.
     *
     * @param array $args Normalized query arguments.
     *
     * @return array {
     *   @type string $clause The WHERE clause (without 'WHERE' keyword).
     *   @type array  $values Values for $wpdb->prepare().
     * }
     *
     * @since 1.0.0
     */
    private function build_where_clause(array $args)
    {
        $conditions = [];
        $values     = [];

        if (!empty($args['owner'])) {
            $this->add_in_condition($conditions, $values, 'owner', $args['owner'], '%s');
        }

        if (!empty($args['action_type'])) {
            $this->add_in_condition($conditions, $values, 'action_type', $args['action_type'], '%s');
        }

        if (!empty($args['object_type'])) {
            $this->add_in_condition($conditions, $values, 'object_type', $args['object_type'], '%s');
        }

        if ($args['behaviour'] !== null && $args['behaviour'] !== '') {
            $this->add_in_condition($conditions, $values, 'behaviour', $args['behaviour'], '%d');
        }

        if (!empty($args['level'])) {
            $conditions[] = 'level = %s';
            $values[]     = $args['level'];
        }

        if (!empty($args['user_id'])) {
            $conditions[] = 'user_id = %d';
            $values[]     = $args['user_id'];
        }

        if (!empty($args['date_from'])) {
            $conditions[] = 'created_at >= %s';
            $values[]     = sanitize_text_field($args['date_from']);
        }

        if (!empty($args['date_to'])) {
            $conditions[] = 'created_at <= %s';
            $values[]     = sanitize_text_field($args['date_to']);
        }

        if (!empty($args['request_id'])) {
            $conditions[] = 'request_id = %s';
            $values[]     = sanitize_text_field($args['request_id']);
        }

        return [
            'clause' => implode(' AND ', $conditions),
            'values' => $values,
        ];
    }

    /**
     * Append an equality or IN condition to the WHERE clause.
     *
     * Supports both scalar and array values. Arrays produce
     * column IN (%s, %s, ...) clauses.
     *
     * @param array  &$conditions Reference to conditions array.
     * @param array  &$values     Reference to values array.
     * @param string $column      Column name.
     * @param mixed  $value       Scalar or array of values.
     * @param string $placeholder wpdb placeholder (%s or %d).
     *
     * @return void
     *
     * @since 1.2.0
     */
    private function add_in_condition(array &$conditions, array &$values, $column, $value, $placeholder)
    {
        if (is_array($value)) {
            $placeholders   = implode(', ', array_fill(0, count($value), $placeholder));
            $conditions[]   = "{$column} IN ({$placeholders})";
            $values         = array_merge($values, array_values($value));
            return;
        }

        $conditions[] = "{$column} = {$placeholder}";
        $values[]     = $value;
    }

    /**
     * Get the table name (without WP prefix).
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_table_name()
    {
        return self::$table_name;
    }
}
