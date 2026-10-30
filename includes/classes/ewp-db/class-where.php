<?php
/**
 * WHERE builder for custom tables: placeholders only, allow-listed identifiers.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a WHERE fragment and its bind values from the nested array format
 * AWM_DB_Creator has always accepted:
 *
 *     array('operator' => 'AND', 'clause' => array(          // group
 *         array('operator' => 'OR', 'clause' => array(       // nested group
 *             array('column' => 'status', 'compare' => 'IN', 'value' => array('a', 'b')),
 *             array('column' => 'title', 'compare' => 'LIKE', 'value' => '%x%'),
 *         )),
 *         array('column' => 'id', 'compare' => '=', 'value' => 5), // condition
 *     ))
 *
 * A node with `column` is a condition, a node with `clause` a group (a plain
 * list counts as an AND group). Columns must exist in the table and are bound
 * with %i; values are bound with %d/%f/%s; operators and comparisons come
 * from fixed lists. IN accepts an array, a legacy "('a','b')" string (see
 * Legacy_In_List) or a `subquery` node. A condition may add
 * `'cast' => 'number'|'date'` to compare its column as DECIMAL / DATETIME
 * (meta values are LONGTEXT, so `9 < 10` needs it).
 *
 * Errors: in read mode an invalid condition becomes `1=0` (it matches
 * nothing, so it can never widen a result) and is reported in `errors`; in
 * strict mode (writes) the first error throws Invalid_Where.
 */
final class Where
{
    /** Deepest nesting accepted. */
    const MAX_DEPTH = 10;

    /** Most conditions accepted in one WHERE. */
    const MAX_CONDITIONS = 200;

    /** Group operators. */
    const OPERATORS = array('AND', 'OR');

    /** Casts a condition may apply to its column (`cast` key), e.g. numeric or date order on LONGTEXT meta values. */
    const CASTS = array('number' => 'DECIMAL(30,10)', 'date' => 'DATETIME');

    /** Comparisons. */
    const COMPARES = array('=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL');

    /** @var array Allowed columns of the current table (see Table_Columns::of()). */
    private $columns;

    /** @var bool Throw on the first error instead of matching nothing. */
    private $strict;

    /** @var string WordPress table prefix, for sub-query tables. */
    private $prefix;

    /** @var callable Returns the columns of a table (without prefix), for sub-queries. */
    private $columns_of;

    /** @var array Bind values, in placeholder order. */
    private $values = array();

    /** @var string[] Problems found (read mode). */
    private $errors = array();

    /** @var int Conditions seen so far. */
    private $conditions = 0;

    /** @var string|null SQL type the current condition casts its column to. */
    private $cast = null;

    /**
     * @param array $columns Allowed columns (lower case => stored name).
     * @param array $options { strict: bool, prefix: string, columns_of: callable }.
     */
    private function __construct(array $columns, array $options)
    {
        global $wpdb;
        $this->columns    = $columns;
        $this->strict     = !empty($options['strict']);
        $this->prefix     = isset($options['prefix']) ? (string) $options['prefix'] : $wpdb->prefix;
        $this->columns_of = isset($options['columns_of']) ? $options['columns_of'] : array(Table_Columns::class, 'of');
    }

    /**
     * Build a WHERE fragment.
     *
     * @param mixed $node    Root node (group, condition or list); empty = no WHERE.
     * @param array $columns Allowed columns of the table (lower case => stored name).
     * @param array $options { strict: bool (default false), prefix: string, columns_of: callable }.
     * @return array { sql: string (without the WHERE keyword, '' for none), values: array, errors: string[] }.
     * @throws Invalid_Where In strict mode, on the first invalid node.
     */
    public static function build($node, array $columns, array $options = array())
    {
        $builder = new self($columns, $options);
        $sql     = empty($node) ? '' : $builder->node($node, 0);
        return array('sql' => $sql, 'values' => $builder->values, 'errors' => $builder->errors);
    }

    /**
     * Build any node.
     *
     * @param mixed $node  Node.
     * @param int   $depth Current depth.
     * @return string SQL fragment, '' for an empty group.
     */
    private function node($node, $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return $this->fail('nesting deeper than ' . self::MAX_DEPTH);
        }
        if (!is_array($node)) {
            return $this->fail('a node must be an array');
        }
        if (array_key_exists('column', $node)) {
            return $this->condition($node);
        }
        if (array_key_exists('clause', $node)) {
            return $this->group($node['clause'], isset($node['operator']) ? $node['operator'] : 'AND', $depth);
        }
        if ($node === array_values($node)) {
            return $this->group($node, 'AND', $depth);
        }
        return $this->fail('a node needs `column` or `clause`');
    }

    /**
     * Build a group of nodes joined by AND/OR.
     *
     * @param mixed  $children Child nodes.
     * @param string $operator AND or OR.
     * @param int    $depth    Current depth.
     * @return string Parenthesised fragment, the single child, or '' when empty.
     */
    private function group($children, $operator, $depth)
    {
        $operator = strtoupper(trim((string) $operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            return $this->fail('unknown operator ' . $operator);
        }
        if (!is_array($children)) {
            return $this->fail('`clause` must be an array');
        }
        $parts = array();
        foreach ($children as $child) {
            $part = $this->node($child, $depth + 1);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (count($parts) < 2) {
            return $parts ? $parts[0] : '';
        }
        return '(' . implode(' ' . $operator . ' ', $parts) . ')';
    }

    /**
     * Build one condition.
     *
     * @param array $node { column, compare, value | subquery }.
     * @return string Fragment with placeholders.
     */
    private function condition(array $node)
    {
        if (++$this->conditions > self::MAX_CONDITIONS) {
            return $this->fail('more than ' . self::MAX_CONDITIONS . ' conditions');
        }
        $column = Table_Columns::resolve($node['column'], $this->columns);
        if ($column === null) {
            return $this->fail('unknown column ' . self::describe($node['column']));
        }
        $compare = strtoupper(preg_replace('/\s+/', ' ', trim(isset($node['compare']) ? (string) $node['compare'] : '=')));
        if (!in_array($compare, self::COMPARES, true)) {
            return $this->fail('unknown comparison ' . self::describe($compare));
        }
        if (isset($node['cast']) && !isset(self::CASTS[$node['cast']])) {
            return $this->fail('unknown cast ' . self::describe($node['cast']));
        }
        $this->cast = isset($node['cast']) ? self::CASTS[$node['cast']] : null;
        $sql        = $this->comparison($column, $compare, $node);
        $this->cast = null;
        return $sql;
    }

    /**
     * Bind the condition's column, wrapped in CAST(… AS type) when the condition asked for one.
     *
     * @param string $column Stored column name.
     * @return string `%i` or `CAST(%i AS type)`.
     */
    private function column_sql($column)
    {
        $placeholder = $this->bind_identifier($column);
        return $this->cast === null ? $placeholder : 'CAST(' . $placeholder . ' AS ' . $this->cast . ')';
    }

    /**
     * Fragment for a validated column and comparison.
     *
     * @param string $column  Stored column name.
     * @param string $compare Allow-listed comparison.
     * @param array  $node    Condition node.
     * @return string Fragment with placeholders.
     */
    private function comparison($column, $compare, array $node)
    {
        if ($compare === 'IS NULL' || $compare === 'IS NOT NULL') {
            return $this->column_sql($column) . ' ' . $compare;
        }
        if (($compare === 'IN' || $compare === 'NOT IN') && isset($node['subquery'])) {
            return $this->subquery($column, $compare, $node['subquery']);
        }
        $value = array_key_exists('value', $node) ? $node['value'] : null;
        if ($compare === 'IN' || $compare === 'NOT IN') {
            return $this->in_list($column, $compare, $value);
        }
        if ($compare === 'BETWEEN' || $compare === 'NOT BETWEEN') {
            return $this->between($column, $compare, $value);
        }
        if (!is_scalar($value)) {
            return $this->fail('the value of ' . $column . ' must be a scalar');
        }
        return $this->column_sql($column) . ' ' . $compare . ' ' . $this->bind($value);
    }

    /**
     * `col IN (…)` from an array or a legacy list string.
     *
     * @param string $column  Stored column name.
     * @param string $compare IN or NOT IN.
     * @param mixed  $value   Array of scalars or "('a','b')".
     * @return string Fragment; an empty list gives 1=0 (IN) or 1=1 (NOT IN).
     */
    private function in_list($column, $compare, $value)
    {
        if (is_string($value)) {
            $parsed = Legacy_In_List::parse($value);
            if ($parsed === null) {
                return $this->fail('the IN list of ' . $column . ' is not a plain list of values');
            }
            $value = $parsed;
        }
        if (!is_array($value)) {
            return $this->fail('the IN value of ' . $column . ' must be an array');
        }
        if (empty($value)) {
            return $compare === 'IN' ? '1=0' : '1=1';
        }
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return $this->fail('the IN list of ' . $column . ' must hold scalars');
            }
        }
        // Bind order must follow placeholder order: the column first, then the list.
        $sql          = $this->column_sql($column) . ' ' . $compare . ' (';
        $placeholders = array();
        foreach ($value as $item) {
            $placeholders[] = $this->bind($item);
        }
        return $sql . implode(', ', $placeholders) . ')';
    }

    /**
     * `col BETWEEN a AND b`.
     *
     * @param string $column  Stored column name.
     * @param string $compare BETWEEN or NOT BETWEEN.
     * @param mixed  $value   Array of exactly two scalars.
     * @return string Fragment.
     */
    private function between($column, $compare, $value)
    {
        if (!is_array($value) || count($value) !== 2 || !is_scalar(reset($value)) || !is_scalar(end($value))) {
            return $this->fail('BETWEEN on ' . $column . ' needs an array of two values');
        }
        $value = array_values($value);
        return $this->column_sql($column) . ' ' . $compare . ' ' . $this->bind($value[0]) . ' AND ' . $this->bind($value[1]);
    }

    /**
     * `col IN (SELECT col2 FROM other WHERE …)`.
     *
     * @param string $column   Stored column name.
     * @param string $compare  IN or NOT IN.
     * @param mixed  $subquery { table (without prefix), select (column), where (node) }.
     * @return string Fragment.
     */
    private function subquery($column, $compare, $subquery)
    {
        if (!is_array($subquery) || empty($subquery['table']) || empty($subquery['select']) || !Table_Columns::is_identifier($subquery['table'])) {
            return $this->fail('a subquery needs `table` and `select`');
        }
        $columns = call_user_func($this->columns_of, $subquery['table']);
        $select  = Table_Columns::resolve($subquery['select'], (array) $columns);
        if ($select === null) {
            return $this->fail('unknown subquery column ' . self::describe($subquery['select']));
        }

        $sql    = $this->column_sql($column) . ' ' . $compare . ' (SELECT ' . $this->bind_identifier($select) . ' FROM ' . $this->bind_identifier($this->prefix . $subquery['table']);
        $outer  = $this->columns;
        $cast   = $this->cast;
        $this->columns = (array) $columns;
        $this->cast    = null;
        $where  = empty($subquery['where']) ? '' : $this->node($subquery['where'], 1);
        $this->columns = $outer;
        $this->cast    = $cast;
        return $sql . ($where === '' ? '' : ' WHERE ' . $where) . ')';
    }

    /**
     * Bind an identifier and return its placeholder.
     *
     * @param string $identifier Validated identifier.
     * @return string `%i`.
     */
    private function bind_identifier($identifier)
    {
        $this->values[] = $identifier;
        return '%i';
    }

    /**
     * Bind a value and return the placeholder matching its type.
     *
     * @param bool|int|float|string $value Scalar value.
     * @return string `%d`, `%f` or `%s`.
     */
    private function bind($value)
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }
        $this->values[] = $value;
        if (is_int($value)) {
            return '%d';
        }
        return is_float($value) ? '%f' : '%s';
    }

    /**
     * Record an error: throw in strict mode, otherwise match nothing.
     *
     * @param string $message What is wrong.
     * @return string `1=0`.
     * @throws Invalid_Where In strict mode.
     */
    private function fail($message)
    {
        if ($this->strict) {
            throw new Invalid_Where('Invalid WHERE: ' . $message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message for REST / CLI errors (JSON / text), escaped where it is printed.
        }
        $this->errors[] = $message;
        return '1=0';
    }

    /**
     * Short printable form of a value for error messages.
     *
     * @param mixed $value Any value.
     * @return string Up to 60 characters.
     */
    private static function describe($value)
    {
        $text = is_scalar($value) ? (string) $value : gettype($value);
        return '"' . substr($text, 0, 60) . '"';
    }
}
