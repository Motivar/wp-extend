<?php
/**
 * Prepared SELECT / UPDATE / DELETE statements for custom tables.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the statements behind AWM_DB_Creator's row CRUD. Every identifier
 * is checked against the table's real columns and bound with %i, every value
 * with %d/%f/%s; the result is the string returned by $wpdb->prepare().
 *
 * Reads are lenient (invalid filters match nothing, invalid ORDER BY or
 * SELECT columns are dropped, see `errors`). Writes are strict: any invalid
 * part, or an empty WHERE, throws Invalid_Where so nothing runs.
 */
final class Sql
{
    /** MySQL's "no limit" for an OFFSET without LIMIT. */
    const NO_LIMIT = '18446744073709551615';

    /**
     * SELECT statement.
     *
     * @param string       $table   Table name without the WordPress prefix.
     * @param string|array $select  '*' or a list of columns.
     * @param mixed        $where   WHERE node (see Where), empty for none.
     * @param array        $orderBy { column, type: ASC|DESC }, empty for none.
     * @param int|string   $limit   Row limit; '' or <= 0 for none.
     * @param int|string   $offset  Rows to skip; <= 0 for none.
     * @return array { sql: string|null (null when no selected column exists), errors: string[] }.
     */
    public static function select($table, $select, $where, $orderBy, $limit, $offset)
    {
        global $wpdb;

        $columns = Table_Columns::of($table);
        $errors  = array();
        $values  = array();

        $list = self::select_list($select, $columns, $values, $errors);
        if ($list === null) {
            return array('sql' => null, 'errors' => $errors);
        }
        $values[] = $wpdb->prefix . $table;
        $sql      = 'SELECT ' . $list . ' FROM %i';

        $built  = Where::build($where, $columns);
        $errors = array_merge($errors, $built['errors']);
        if ($built['sql'] !== '') {
            $sql   .= ' WHERE ' . $built['sql'];
            $values = array_merge($values, $built['values']);
        }

        $sql .= self::order_clause($orderBy, $columns, $values, $errors);
        $sql .= self::limit_clause($limit, $offset, $values);

        return array('sql' => $wpdb->prepare($sql, $values), 'errors' => $errors); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
    }

    /**
     * `SELECT COUNT(*)` statement (read rules: an invalid filter matches nothing).
     *
     * @param string $table Table name without the WordPress prefix.
     * @param mixed  $where WHERE node (see Where), empty for none.
     * @return array { sql: string, errors: string[] }.
     */
    public static function count($table, $where)
    {
        global $wpdb;

        $built = Where::build($where, Table_Columns::of($table));
        $sql   = 'SELECT COUNT(*) FROM %i' . ($built['sql'] === '' ? '' : ' WHERE ' . $built['sql']);
        return array('sql' => $wpdb->prepare($sql, array_merge(array($wpdb->prefix . $table), $built['values'])), 'errors' => $built['errors']); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
    }

    /**
     * UPDATE statement.
     *
     * @param string $table Table name without the WordPress prefix.
     * @param array  $data  Column => new value (arrays/objects serialised).
     * @param mixed  $where WHERE node; must not be empty.
     * @return string Prepared statement.
     * @throws Invalid_Where On an unknown column, an invalid WHERE or an empty WHERE.
     */
    public static function update($table, array $data, $where)
    {
        global $wpdb;

        $columns = Table_Columns::of($table);
        if (empty($data)) {
            throw new Invalid_Where('Nothing to update.');
        }
        $values = array($wpdb->prefix . $table);
        $set    = array();
        foreach ($data as $column => $value) {
            $stored = Table_Columns::resolve($column, $columns);
            if ($stored === null) {
                throw new Invalid_Where('Unknown column ' . $column . ' in UPDATE.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message for REST / CLI errors (JSON / text), escaped where it is printed.
            }
            $values[] = $stored;
            $set[]    = '%i = ' . self::bind_stored($value, $values);
        }

        $built = self::required_where($where, $columns);
        $sql   = 'UPDATE %i SET ' . implode(', ', $set) . ' WHERE ' . $built['sql'];
        return $wpdb->prepare($sql, array_merge($values, $built['values'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
    }

    /**
     * DELETE statement.
     *
     * @param string $table Table name without the WordPress prefix.
     * @param mixed  $where WHERE node; must not be empty.
     * @return string Prepared statement.
     * @throws Invalid_Where On an invalid or empty WHERE.
     */
    public static function delete($table, $where)
    {
        global $wpdb;

        $built = self::required_where($where, Table_Columns::of($table));
        return $wpdb->prepare('DELETE FROM %i WHERE ' . $built['sql'], array_merge(array($wpdb->prefix . $table), $built['values'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
    }

    /**
     * Strict WHERE that must not be empty.
     *
     * @param mixed $where   WHERE node.
     * @param array $columns Allowed columns.
     * @return array Output of Where::build().
     * @throws Invalid_Where When empty or invalid.
     */
    private static function required_where($where, array $columns)
    {
        if (empty($where)) {
            throw new Invalid_Where('A WHERE clause is required.');
        }
        $built = Where::build($where, $columns, array('strict' => true));
        if ($built['sql'] === '') {
            throw new Invalid_Where('The WHERE clause is empty.');
        }
        return $built;
    }

    /**
     * Column list for SELECT.
     *
     * @param string|array $select  '*' or columns.
     * @param array        $columns Allowed columns.
     * @param array        $values  Bind values (appended to).
     * @param string[]     $errors  Errors (appended to).
     * @return string|null `*` or `%i, %i`; null when none of the requested columns exists.
     */
    private static function select_list($select, array $columns, array &$values, array &$errors)
    {
        if (!is_array($select)) {
            return '*';
        }
        $parts = array();
        foreach ($select as $column) {
            $stored = Table_Columns::resolve($column, $columns);
            if ($stored === null) {
                $errors[] = 'unknown SELECT column "' . substr((string) $column, 0, 60) . '"';
                continue;
            }
            $values[] = $stored;
            $parts[]  = '%i';
        }
        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * ORDER BY clause, dropped when the column or direction is not allowed.
     *
     * @param mixed    $orderBy { column, type }.
     * @param array    $columns Allowed columns.
     * @param array    $values  Bind values (appended to).
     * @param string[] $errors  Errors (appended to).
     * @return string ` ORDER BY %i ASC|DESC` or ''.
     */
    private static function order_clause($orderBy, array $columns, array &$values, array &$errors)
    {
        if (empty($orderBy) || !is_array($orderBy) || !isset($orderBy['column'])) {
            return '';
        }
        $stored    = Table_Columns::resolve($orderBy['column'], $columns);
        $direction = strtoupper(trim(isset($orderBy['type']) ? (string) $orderBy['type'] : 'ASC'));
        if ($stored === null || !in_array($direction, array('ASC', 'DESC'), true)) {
            $errors[] = 'ORDER BY dropped: unknown column or direction';
            return '';
        }
        $values[] = $stored;
        return ' ORDER BY %i ' . $direction;
    }

    /**
     * LIMIT / OFFSET clause.
     *
     * @param int|string $limit  Row limit; '' or <= 0 for none.
     * @param int|string $offset Rows to skip; <= 0 for none.
     * @param array      $values Bind values (appended to).
     * @return string Clause or ''.
     */
    private static function limit_clause($limit, $offset, array &$values)
    {
        $limit  = is_numeric($limit) ? (int) $limit : 0;
        $offset = is_numeric($offset) ? (int) $offset : 0;
        if ($limit <= 0 && $offset <= 0) {
            return '';
        }
        $sql = $limit > 0 ? ' LIMIT %d' : ' LIMIT ' . self::NO_LIMIT;
        if ($limit > 0) {
            $values[] = $limit;
        }
        if ($offset > 0) {
            $sql     .= ' OFFSET %d';
            $values[] = $offset;
        }
        return $sql;
    }

    /**
     * Bind a value to store, matching what update_db_data always stored:
     * arrays/objects serialised, no added slashes, null as ''.
     *
     * @param mixed $value  Value to store.
     * @param array $values Bind values (appended to).
     * @return string Placeholder.
     */
    private static function bind_stored($value, array &$values)
    {
        $value = maybe_serialize($value);
        if ($value === null) {
            $value = '';
        }
        if (is_bool($value)) {
            $value = (int) $value;
        }
        $values[] = $value;
        if (is_int($value)) {
            return '%d';
        }
        return is_float($value) ? '%f' : '%s';
    }
}
