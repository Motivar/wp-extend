<?php
/**
 * Column allow-list of custom tables.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Real column names of a table, read once per request with SHOW COLUMNS.
 *
 * Identifiers (columns, ORDER BY, SELECT lists) cannot be bound as values;
 * they are checked against this list instead and quoted with %i.
 */
final class Table_Columns
{
    /** @var array<string, string[]> Prefixed table name => columns (lower case => stored case). */
    private static $cache = array();

    /**
     * Columns of a table.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return array Lower-case column name => column name as stored; empty when the table does not exist.
     */
    public static function of($table)
    {
        global $wpdb;

        $prefixed = $wpdb->prefix . $table;
        if (isset(self::$cache[$prefixed])) {
            return self::$cache[$prefixed];
        }
        if (!self::is_identifier($table)) {
            return array();
        }

        $suppress = $wpdb->suppress_errors(true);
        $rows     = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $prefixed));
        $wpdb->suppress_errors($suppress);

        $columns = array();
        foreach ((array) $rows as $column) {
            $columns[strtolower($column)] = $column;
        }
        self::$cache[$prefixed] = $columns;
        return $columns;
    }

    /**
     * Stored name of a column, or null when the table has no such column.
     *
     * @param string $column  Column name as requested (any case).
     * @param array  $columns Output of of().
     * @return string|null Column name as stored.
     */
    public static function resolve($column, array $columns)
    {
        $key = strtolower(trim((string) $column));
        if (!self::is_identifier($key)) {
            return null;
        }
        return isset($columns[$key]) ? $columns[$key] : null;
    }

    /**
     * Forget a table's columns (after its schema changed) or every table.
     *
     * @param string|null $table Table name without prefix; null for all.
     * @return void
     */
    public static function forget($table = null)
    {
        global $wpdb;
        if ($table === null) {
            self::$cache = array();
            return;
        }
        unset(self::$cache[$wpdb->prefix . $table]);
    }

    /**
     * Whether a name is a plain identifier (letters, digits, `_`).
     *
     * @param string $name Name to check.
     * @return bool True when safe to use as an identifier.
     */
    public static function is_identifier($name)
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', (string) $name);
    }
}
