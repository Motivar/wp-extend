<?php
/**
 * Object-cache layer for AWM_DB_Creator::get_db_data().
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caches the rows of a prepared SELECT in the WordPress object cache.
 *
 * Every table has a `last_changed` stamp (WordPress core's pattern for
 * posts and terms); a cached result is keyed by the SQL plus the stamps of
 * every table it reads (the table and any sub-query tables), so a write to
 * any of them makes the old entries unreachable. AWM_DB_Creator bumps the
 * stamp on every insert, update, delete, truncate, drop and schema change.
 *
 * Without a persistent object cache this is a per-request cache. With one
 * (Redis, Memcached) entries survive between requests, so a table is cached
 * only when every write to it goes through AWM_DB_Creator: by default the
 * `{type}_main` / `{type}_data` tables of registered content types. Plugins
 * that write a table with raw `$wpdb` must leave it uncached, or call
 * Read_Cache::invalidate() after their writes; `ewp_db_cache_table` opts a
 * table in or out.
 */
final class Read_Cache
{
    /** Object cache group. */
    const GROUP = 'ewp_db';

    /**
     * Whether reads of a table are cached.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return bool True when cached.
     */
    public static function enabled_for($table)
    {
        /**
         * Filters whether AWM_DB_Creator::get_db_data() results for a table are cached in the
         * object cache. Return true only for tables written exclusively through AWM_DB_Creator
         * (or whose raw writers call EWP\Db\Read_Cache::invalidate()).
         *
         * @since 1.6.2
         *
         * @param bool   $enabled True for the `_main` / `_data` tables of registered content types.
         * @param string $table   Table name without the WordPress prefix.
         */
        return (bool) apply_filters('ewp_db_cache_table', self::is_content_table($table), (string) $table);
    }

    /**
     * Whether a table belongs to a registered content type.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return bool True for `{type}_main` / `{type}_data` of a registered type.
     */
    public static function is_content_table($table)
    {
        if (!class_exists('AWM_Add_Content_DB_Setup') || !preg_match('/^(.+)_(main|data)$/', (string) $table, $m)) {
            return false;
        }
        return isset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$m[1]]);
    }

    /**
     * Cached rows of a query.
     *
     * @param string[] $tables Tables the query reads, without prefix.
     * @param string   $sql    Prepared SQL.
     * @return array|null Rows, null on a miss.
     */
    public static function get(array $tables, $sql)
    {
        $found = false;
        $rows  = wp_cache_get(self::key($tables, $sql), self::GROUP, false, $found);
        return ($found && is_array($rows)) ? $rows : null;
    }

    /**
     * Store the rows of a query.
     *
     * @param string[] $tables Tables the query reads, without prefix.
     * @param string   $sql    Prepared SQL.
     * @param array    $rows   Rows to cache.
     * @return void
     */
    public static function set(array $tables, $sql, array $rows)
    {
        wp_cache_set(self::key($tables, $sql), $rows, self::GROUP);
    }

    /**
     * Make every cached read of a table stale. Call after writing the table
     * without AWM_DB_Creator.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return void
     */
    public static function invalidate($table)
    {
        wp_cache_set(self::stamp_key($table), microtime(), self::GROUP);
    }

    /**
     * Tables a SELECT reads: the table itself plus every `subquery.table` in the WHERE node.
     *
     * @param string $table Main table without prefix.
     * @param mixed  $where WHERE node.
     * @return string[] Unique table names.
     */
    public static function tables_of($table, $where)
    {
        $tables = array((string) $table);
        self::collect_subquery_tables($where, $tables);
        return array_values(array_unique($tables));
    }

    /**
     * Walk a WHERE node and collect sub-query tables.
     *
     * @param mixed    $node   Node or value.
     * @param string[] $tables Collected names (appended to).
     * @return void
     */
    private static function collect_subquery_tables($node, array &$tables)
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['subquery']['table']) && is_string($node['subquery']['table'])) {
            $tables[] = $node['subquery']['table'];
        }
        foreach ($node as $child) {
            self::collect_subquery_tables($child, $tables);
        }
    }

    /**
     * Cache key: SQL hash plus each table's stamp.
     *
     * @param string[] $tables Tables the query reads.
     * @param string   $sql    Prepared SQL.
     * @return string Key.
     */
    private static function key(array $tables, $sql)
    {
        $stamps = array_map(array(__CLASS__, 'stamp'), $tables);
        return 'q:' . md5($sql . '|' . implode('|', $stamps));
    }

    /**
     * Current `last_changed` stamp of a table, created on first use. Changes on every write.
     *
     * @param string $table Table name without the WordPress prefix.
     * @return string Stamp.
     */
    public static function stamp($table)
    {
        $stamp = wp_cache_get(self::stamp_key($table), self::GROUP);
        if (!$stamp) {
            $stamp = microtime();
            wp_cache_set(self::stamp_key($table), $stamp, self::GROUP);
        }
        return (string) $stamp;
    }

    /**
     * Object cache key of a table's stamp (includes the site's table prefix, for multisite).
     *
     * @param string $table Table name without the WordPress prefix.
     * @return string Key.
     */
    private static function stamp_key($table)
    {
        global $wpdb;
        return 'lc:' . $wpdb->prefix . $table;
    }
}
