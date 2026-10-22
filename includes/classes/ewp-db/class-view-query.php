<?php
/**
 * DataViews view → content type query.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translates a `@wordpress/dataviews` view (search, filters, sort, page,
 * perPage) into a WHERE node, ORDER BY and LIMIT/OFFSET for a content type's
 * `{type}_main` table, bound later by Where / Sql like every other read.
 *
 * Fields come from a field map (see Content_Service::view_fields()):
 *
 *     'title'  => array('source' => 'column', 'key' => 'content_title', 'type' => 'text', 'sortable' => true, 'searchable' => true),
 *     'colour' => array('source' => 'meta',   'key' => 'colour',        'type' => 'text', 'searchable' => true),
 *
 * Column fields filter the row directly; meta fields filter through
 * `content_id [NOT] IN (SELECT content_id FROM {type}_data WHERE meta_key = … AND meta_value …)`,
 * with `number` / `date` fields compared as DECIMAL / DATETIME. Only column
 * fields sort. Anything the map or the operator list does not know throws
 * Invalid_Where, so a bad view is a 400, never a silently wider result.
 */
final class View_Query
{
    /** Default rows per page. */
    const DEFAULT_PER_PAGE = 20;

    /** Most rows per page. */
    const MAX_PER_PAGE = 200;

    /** Positive operators → SQL comparison. */
    const COMPARE = array(
        'is'                 => '=',
        'isAny'              => 'IN',
        'lessThan'           => '<',
        'greaterThan'        => '>',
        'lessThanOrEqual'    => '<=',
        'greaterThanOrEqual' => '>=',
        'before'             => '<',
        'after'              => '>',
        'beforeInc'          => '<=',
        'afterInc'           => '>=',
        'between'            => 'BETWEEN',
        'contains'           => 'LIKE',
        'startsWith'         => 'LIKE',
        'isAll'              => 'ALL',
    );

    /** Negative operators → their positive counterpart (meta: NOT IN the positive sub-query). */
    const NEGATE = array(
        'isNot'       => 'is',
        'isNone'      => 'isAny',
        'notContains' => 'contains',
        'isNotAll'    => 'isAll',
    );

    /** Field types compared with a cast on meta values. */
    const CASTS = array('integer' => 'number', 'number' => 'number', 'date' => 'date', 'datetime' => 'date');

    /**
     * Build the query parts.
     *
     * @param string $type   Content type id (tables `{type}_main` / `{type}_data`).
     * @param array  $view   { search?: string, filters?: list<{field, operator, value}>, sort?: {field, direction}, page?: int, perPage?: int }.
     * @param array  $fields Field id => { source: column|meta, key: string, type?: string, sortable?: bool, searchable?: bool }.
     * @return array { where: array, order_by: array, limit: int, offset: int, page: int, per_page: int }.
     * @throws Invalid_Where On an unknown field, operator, sort or malformed value.
     */
    public static function build($type, array $view, array $fields)
    {
        if (!Table_Columns::is_identifier((string) $type)) {
            throw new Invalid_Where('Invalid content type.');
        }
        $per_page = isset($view['perPage']) ? (int) $view['perPage'] : self::DEFAULT_PER_PAGE;
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $page     = max(1, isset($view['page']) ? (int) $view['page'] : 1);

        $clauses = self::filters($type, isset($view['filters']) ? $view['filters'] : array(), $fields);
        $search  = self::search($type, isset($view['search']) ? $view['search'] : '', $fields);
        if ($search !== null) {
            $clauses[] = $search;
        }

        return array(
            'where'    => $clauses ? array('operator' => 'AND', 'clause' => $clauses) : array(),
            'order_by' => self::sort(isset($view['sort']) ? $view['sort'] : array(), $fields),
            'limit'    => $per_page,
            'offset'   => ($page - 1) * $per_page,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }

    /**
     * Conditions for every filter with a value (DataViews sends filters without one while the user picks).
     *
     * @param string $type    Content type id.
     * @param mixed  $filters List of { field, operator, value }.
     * @param array  $fields  Field map.
     * @return array WHERE nodes.
     * @throws Invalid_Where On an unknown field or operator, or a malformed value.
     */
    private static function filters($type, $filters, array $fields)
    {
        if (!is_array($filters)) {
            throw new Invalid_Where('`filters` must be a list.');
        }
        $clauses = array();
        foreach ($filters as $filter) {
            if (!is_array($filter) || !isset($filter['field'], $filter['operator'])) {
                throw new Invalid_Where('A filter needs `field` and `operator`.');
            }
            $value = array_key_exists('value', $filter) ? $filter['value'] : null;
            if ($value === null || $value === '' || $value === array()) {
                continue;
            }
            $field = self::field($fields, $filter['field']);
            $clauses[] = self::condition($type, $field, (string) $filter['operator'], $value);
        }
        return $clauses;
    }

    /**
     * One filter as a WHERE node.
     *
     * @param string $type     Content type id.
     * @param array  $field    Field definition.
     * @param string $operator DataViews operator.
     * @param mixed  $value    Filter value.
     * @return array WHERE node.
     * @throws Invalid_Where On an unknown operator or a malformed value.
     */
    private static function condition($type, array $field, $operator, $value)
    {
        $negated  = isset(self::NEGATE[$operator]);
        $positive = $negated ? self::NEGATE[$operator] : $operator;
        if (!isset(self::COMPARE[$positive])) {
            throw new Invalid_Where('Unknown filter operator "' . substr($operator, 0, 40) . '".');
        }
        $value = self::value($field, $positive, $value);

        if ($field['source'] === 'meta') {
            return self::meta_condition($type, $field, $positive, $value, $negated);
        }
        return $negated ? self::negate_column($field['key'], $positive, $value) : self::match_nodes($field['key'], $positive, $value, null);
    }

    /**
     * Normalise a filter value for an operator and field type.
     *
     * @param array  $field    Field definition.
     * @param string $operator Positive operator.
     * @param mixed  $value    Raw value.
     * @return mixed Scalar, or list of scalars for isAny / isAll / between.
     * @throws Invalid_Where On a malformed value.
     */
    private static function value(array $field, $operator, $value)
    {
        $list = in_array($operator, array('isAny', 'isAll', 'between'), true);
        $vals = is_array($value) ? array_values($value) : array($value);
        foreach ($vals as $item) {
            if (!is_scalar($item)) {
                throw new Invalid_Where('Filter values must be scalars.');
            }
        }
        if ($operator === 'between' && count($vals) !== 2) {
            throw new Invalid_Where('`between` needs two values.');
        }
        if (in_array(isset($field['type']) ? $field['type'] : '', array('integer'), true)) {
            $vals = array_map('intval', $vals);
        }
        if ($list) {
            return $vals;
        }
        if (count($vals) !== 1) {
            throw new Invalid_Where('Operator "' . $operator . '" takes one value.');
        }
        return $vals[0];
    }

    /**
     * Positive match on one column (`_main` column, or `meta_value` inside a sub-query).
     *
     * @param string      $column   Column.
     * @param string      $operator Positive operator.
     * @param mixed       $value    Normalised value.
     * @param string|null $cast     Where cast (`number` / `date`) or null.
     * @return array WHERE node.
     */
    private static function match_nodes($column, $operator, $value, $cast)
    {
        global $wpdb;

        $node = array('column' => $column, 'compare' => self::COMPARE[$operator], 'value' => $value);
        if ($operator === 'contains') {
            $node['value'] = '%' . $wpdb->esc_like((string) $value) . '%';
        } elseif ($operator === 'startsWith') {
            $node['value'] = $wpdb->esc_like((string) $value) . '%';
        } elseif ($operator === 'isAll') {
            return self::all_values($column, $value);
        }
        if ($cast !== null && !in_array($operator, array('contains', 'startsWith', 'isAny'), true)) {
            $node['cast'] = $cast;
        }
        return $node;
    }

    /**
     * `isAll`: every value is present — a plain value equal to it, or a serialised list containing it.
     *
     * @param string $column Column.
     * @param array  $values Values.
     * @return array AND group of one has_value() group per value.
     */
    private static function all_values($column, array $values)
    {
        $clauses = array();
        foreach ($values as $value) {
            $clauses[] = self::has_value($column, $value);
        }
        return array('operator' => 'AND', 'clause' => $clauses);
    }

    /**
     * The column holds a value: equal to it, or a serialised list containing it. Lists
     * written by AWM_DB_Creator::insert_db_data() are stored slashed (`\"a\"`), lists
     * written by an update are not (`"a"`); both match.
     *
     * @param string $column Column.
     * @param mixed  $value  Scalar value.
     * @param bool   $negated Return "does not hold it" instead.
     * @return array OR group (AND group when negated).
     */
    private static function has_value($column, $value, $negated = false)
    {
        global $wpdb;

        $value    = (string) $value;
        $patterns = array('"' . $value . '"', '\\"' . $value . '\\"');
        $clauses  = array(array('column' => $column, 'compare' => $negated ? '!=' : '=', 'value' => $value));
        foreach ($patterns as $pattern) {
            $clauses[] = array('column' => $column, 'compare' => $negated ? 'NOT LIKE' : 'LIKE', 'value' => '%' . $wpdb->esc_like($pattern) . '%');
        }
        return array('operator' => $negated ? 'AND' : 'OR', 'clause' => $clauses);
    }

    /**
     * Negated filter on a `_main` column.
     *
     * @param string $column   Column.
     * @param string $operator Positive operator being negated.
     * @param mixed  $value    Normalised value.
     * @return array WHERE node.
     */
    private static function negate_column($column, $operator, $value)
    {
        global $wpdb;

        switch ($operator) {
            case 'is':
                return array('column' => $column, 'compare' => '!=', 'value' => $value);
            case 'isAny':
                return array('column' => $column, 'compare' => 'NOT IN', 'value' => $value);
            case 'contains':
                return array('column' => $column, 'compare' => 'NOT LIKE', 'value' => '%' . $wpdb->esc_like((string) $value) . '%');
        }
        // isNotAll: at least one value missing.
        $clauses = array();
        foreach ($value as $item) {
            $clauses[] = self::has_value($column, $item, true);
        }
        return array('operator' => 'OR', 'clause' => $clauses);
    }

    /**
     * Filter on a meta field through `{type}_data`. Negated operators keep the
     * items that have no matching meta row (including items without the key).
     *
     * @param string $type     Content type id.
     * @param array  $field    Field definition.
     * @param string $operator Positive operator.
     * @param mixed  $value    Normalised value.
     * @param bool   $negated  NOT IN instead of IN.
     * @return array WHERE node.
     */
    private static function meta_condition($type, array $field, $operator, $value, $negated)
    {
        $cast = isset($field['type'], self::CASTS[$field['type']]) ? self::CASTS[$field['type']] : null;
        if ($operator === 'isAll' && $negated) {
            // isNotAll: one value is missing → OR of "no row containing value".
            $clauses = array();
            foreach ($value as $item) {
                $clauses[] = self::meta_subquery($type, $field['key'], self::all_values('meta_value', array($item)), true);
            }
            return array('operator' => 'OR', 'clause' => $clauses);
        }
        if ($operator === 'isAll') {
            $clauses = array();
            foreach ($value as $item) {
                $clauses[] = self::meta_subquery($type, $field['key'], self::all_values('meta_value', array($item)), false);
            }
            return array('operator' => 'AND', 'clause' => $clauses);
        }
        return self::meta_subquery($type, $field['key'], self::match_nodes('meta_value', $operator, $value, $cast), $negated);
    }

    /**
     * `content_id [NOT] IN (SELECT content_id FROM {type}_data WHERE meta_key = key AND <match>)`.
     *
     * @param string $type    Content type id.
     * @param string $key     Meta key.
     * @param array  $match   Condition on meta_value.
     * @param bool   $negated NOT IN.
     * @return array WHERE node.
     */
    private static function meta_subquery($type, $key, array $match, $negated)
    {
        return array(
            'column'   => 'content_id',
            'compare'  => $negated ? 'NOT IN' : 'IN',
            'subquery' => array(
                'table'  => $type . '_data',
                'select' => 'content_id',
                'where'  => array('operator' => 'AND', 'clause' => array(
                    array('column' => 'meta_key', 'compare' => '=', 'value' => (string) $key),
                    $match,
                )),
            ),
        );
    }

    /**
     * Free-text search over searchable columns (LIKE) and searchable meta keys (one sub-query); a numeric term also matches the id.
     *
     * @param string $type   Content type id.
     * @param mixed  $search Search text.
     * @param array  $fields Field map.
     * @return array|null OR group, null when there is nothing to search.
     */
    private static function search($type, $search, array $fields)
    {
        global $wpdb;

        $search = is_scalar($search) ? trim((string) $search) : '';
        if ($search === '') {
            return null;
        }
        $like      = '%' . $wpdb->esc_like($search) . '%';
        $clauses   = array();
        $meta_keys = array();
        foreach ($fields as $field) {
            if (empty($field['searchable'])) {
                continue;
            }
            if ($field['source'] === 'meta') {
                $meta_keys[] = (string) $field['key'];
                continue;
            }
            $clauses[] = array('column' => $field['key'], 'compare' => 'LIKE', 'value' => $like);
        }
        if ($meta_keys) {
            $clauses[] = array('column' => 'content_id', 'compare' => 'IN', 'subquery' => array(
                'table'  => $type . '_data',
                'select' => 'content_id',
                'where'  => array('operator' => 'AND', 'clause' => array(
                    array('column' => 'meta_key', 'compare' => 'IN', 'value' => $meta_keys),
                    array('column' => 'meta_value', 'compare' => 'LIKE', 'value' => $like),
                )),
            ));
        }
        if (ctype_digit($search)) {
            $clauses[] = array('column' => 'content_id', 'compare' => '=', 'value' => (int) $search);
        }
        return $clauses ? array('operator' => 'OR', 'clause' => $clauses) : array('column' => 'content_id', 'compare' => 'IN', 'value' => array());
    }

    /**
     * ORDER BY from the view's sort; newest first when none.
     *
     * @param mixed $sort   { field, direction: asc|desc }.
     * @param array $fields Field map.
     * @return array { column, type }.
     * @throws Invalid_Where On a field that cannot be sorted or a bad direction.
     */
    private static function sort($sort, array $fields)
    {
        if (empty($sort) || !is_array($sort) || empty($sort['field'])) {
            return array('column' => 'created', 'type' => 'DESC');
        }
        $field     = self::field($fields, $sort['field']);
        $direction = strtoupper(isset($sort['direction']) ? (string) $sort['direction'] : 'desc');
        if ($field['source'] !== 'column' || empty($field['sortable'])) {
            throw new Invalid_Where('Field "' . substr((string) $sort['field'], 0, 40) . '" cannot be sorted.');
        }
        if (!in_array($direction, array('ASC', 'DESC'), true)) {
            throw new Invalid_Where('Sort direction must be asc or desc.');
        }
        return array('column' => $field['key'], 'type' => $direction);
    }

    /**
     * Look a field up in the map.
     *
     * @param array $fields Field map.
     * @param mixed $id     Field id.
     * @return array Field definition.
     * @throws Invalid_Where When unknown.
     */
    private static function field(array $fields, $id)
    {
        $id = is_scalar($id) ? (string) $id : '';
        if (!isset($fields[$id]['source'], $fields[$id]['key']) || !in_array($fields[$id]['source'], array('column', 'meta'), true)) {
            throw new Invalid_Where('Unknown field "' . substr($id, 0, 40) . '".');
        }
        return $fields[$id];
    }
}
