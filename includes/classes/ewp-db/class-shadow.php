<?php
/**
 * Shadow comparison of the pre-1.6 WHERE builder against EWP\Db\Where.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Development / staging check that the prepared-statement layer selects
 * exactly the rows the old string-built SQL selected.
 *
 * Enabled by `define('EWP_DB_SHADOW', true)` on a non-production site. Every
 * get_db_data() also runs the old SELECT and compares the rows; every
 * update_db_data() / delete_db_data() first compares the rows both WHEREs
 * target. Differences go to the PHP error log, prefixed `[ewp-db-shadow]`,
 * with the caller. The old SQL is unescaped, which is why this never runs on
 * production. Nothing is changed by the comparison itself.
 */
final class Shadow
{
    /** Log prefix. */
    const TAG = '[ewp-db-shadow]';

    /**
     * Whether shadow comparison runs on this request.
     *
     * @return bool True when EWP_DB_SHADOW is set and the site is not production.
     */
    public static function enabled()
    {
        /**
         * Filters whether the shadow comparison runs on this request. Never runs on production,
         * whatever the filter returns.
         *
         * @since 1.6.1
         *
         * @param bool $enabled True when the EWP_DB_SHADOW constant is set and true.
         */
        $enabled = (bool) apply_filters('ewp_db_shadow_enabled', defined('EWP_DB_SHADOW') && EWP_DB_SHADOW);
        return $enabled && wp_get_environment_type() !== 'production';
    }

    /**
     * Whether the pre-1.6 builder can express a WHERE node at all, so the
     * comparison means something. It only knew the legacy shape — a root
     * with `clause`, conditions with `column`, `compare` and a scalar or
     * string `value` — not array values, sub-queries, casts or bare conditions,
     * which extend-wp's own callers use since 1.6.0.
     *
     * @param mixed $where WHERE node.
     * @return bool True when old and new SQL can be compared.
     */
    public static function comparable($where)
    {
        if (empty($where)) {
            return true;
        }
        return is_array($where) && isset($where['clause']) && is_array($where['clause']) && self::legacy_node($where);
    }

    /**
     * Whether a node and its children use only legacy features.
     *
     * @param array $node Group or condition.
     * @return bool True when every condition is legacy-shaped.
     */
    private static function legacy_node(array $node)
    {
        if (array_key_exists('column', $node)) {
            return isset($node['compare']) && !isset($node['subquery']) && !isset($node['cast']) && array_key_exists('value', $node) && is_scalar($node['value']);
        }
        if (!isset($node['clause']) || !is_array($node['clause'])) {
            return false;
        }
        foreach ($node['clause'] as $child) {
            if (!is_array($child) || !self::legacy_node($child)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compare a read with the rows the old SELECT returns.
     *
     * @param string $table      Table name without the WordPress prefix.
     * @param string $legacy_sql SELECT the old builder produced.
     * @param array  $rows       Rows the new statement returned (before stripslashes).
     * @return bool True when both returned the same rows.
     */
    public static function compare_select($table, $legacy_sql, array $rows)
    {
        if ($legacy_sql === null) {
            return true;
        }
        $legacy = self::run($legacy_sql);
        if ($legacy !== null && self::same_rows($legacy, $rows)) {
            return true;
        }
        self::report('select', $table, $legacy_sql, $legacy === null ? 'old SQL failed' : count($legacy) . ' old rows vs ' . count($rows) . ' new rows');
        return false;
    }

    /**
     * Before a write, compare the rows both WHEREs target.
     *
     * @param string $table        Table name without the WordPress prefix.
     * @param mixed  $where        WHERE node.
     * @param string $legacy_where WHERE the old builder produced.
     * @param string $operation    `update` or `delete`, for the log.
     * @return bool True when both target the same rows (or the new WHERE is invalid, which blocks the write anyway).
     */
    public static function compare_targets($table, $where, $legacy_where, $operation)
    {
        global $wpdb;

        if ($legacy_where === null || !self::comparable($where)) {
            return true;
        }
        try {
            $new = Sql::select($table, '*', $where, array(), '', 0);
            Where::build($where, Table_Columns::of($table), array('strict' => true));
        } catch (Invalid_Where $e) {
            return true;
        }
        $legacy_sql = 'SELECT * FROM ' . $wpdb->prefix . $table . ($legacy_where === '' ? '' : ' WHERE ' . $legacy_where);
        $legacy     = self::run($legacy_sql);
        $rows       = self::run($new['sql']);
        if ($legacy !== null && $rows !== null && self::same_rows($legacy, $rows)) {
            return true;
        }
        self::report($operation, $table, $legacy_sql, ($legacy === null ? 'old SQL failed' : count($legacy) . ' old rows') . ' vs ' . ($rows === null ? 'new SQL failed' : count($rows) . ' new rows'));
        return false;
    }

    /**
     * Whether two result sets hold the same rows, ignoring order.
     *
     * @param array $a Rows.
     * @param array $b Rows.
     * @return bool True when equal as multisets.
     */
    public static function same_rows(array $a, array $b)
    {
        $hash = function (array $rows) {
            $keys = array_map('serialize', $rows);
            sort($keys);
            return md5(implode("\n", $keys));
        };
        return count($a) === count($b) && $hash($a) === $hash($b);
    }

    /**
     * Run a SELECT without printing or keeping errors.
     *
     * @param string|null $sql Query.
     * @return array|null Rows, null on failure.
     */
    private static function run($sql)
    {
        global $wpdb;
        if (empty($sql)) {
            return null;
        }
        $suppress   = $wpdb->suppress_errors(true);
        $last_error = $wpdb->last_error;
        $rows       = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- shadow comparison of legacy SQL, dev/staging only.
        $failed     = $wpdb->last_error !== '' && $wpdb->last_error !== $last_error;
        $wpdb->suppress_errors($suppress);
        return $failed ? null : (array) $rows;
    }

    /**
     * Log a difference with the caller outside the DB layer.
     *
     * @param string $operation  select, update or delete.
     * @param string $table      Table name.
     * @param string $legacy_sql Old SQL.
     * @param string $detail     What differed.
     * @return void
     */
    private static function report($operation, $table, $legacy_sql, $detail)
    {
        error_log(sprintf('%s %s on %s differs (%s) from %s; old SQL: %s', self::TAG, $operation, $table, $detail, self::caller(), substr($legacy_sql, 0, 500)));
    }

    /**
     * First frame outside the DB layer, as file:line.
     *
     * @return string Caller or 'unknown'.
     */
    private static function caller()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = isset($frame['file']) ? $frame['file'] : '';
            if ($file !== '' && strpos($file, 'class-db-creator.php') === false && strpos($file, '/ewp-db/') === false) {
                return basename(dirname($file)) . '/' . basename($file) . ':' . (isset($frame['line']) ? $frame['line'] : 0);
            }
        }
        return 'unknown';
    }
}
