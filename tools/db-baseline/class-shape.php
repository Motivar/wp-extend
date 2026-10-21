<?php
/**
 * Reduces DB-layer call arguments to their structure.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns argument values into a "shape": keys and structural values kept,
 * data values replaced by their type, so calls with different data but the
 * same query structure share one fingerprint.
 */
class Shape
{
    /** Keys whose values describe query structure, kept verbatim. */
    const STRUCTURAL_KEYS = array('compare', 'operator', 'relation', 'column', 'key', 'type', 'order_by', 'select');

    /** Longest string kept in a sample before truncation. */
    const MAX_STRING = 200;

    /**
     * Build the shape of a value.
     *
     * @param mixed       $value Any argument value.
     * @param string|null $key   Array key the value sits under, null at the root.
     * @return mixed Nested array of keys and type names, or a kept structural scalar.
     */
    public static function of($value, $key = null)
    {
        if (is_array($value)) {
            return self::of_array($value);
        }
        if (is_object($value)) {
            return 'object:' . get_class($value);
        }
        if ($key !== null && in_array((string) $key, self::STRUCTURAL_KEYS, true) && is_scalar($value)) {
            return $value;
        }
        return self::type_name($value);
    }

    /**
     * Shape of an array, keeping keys and recursing into values.
     *
     * @param array $value Array argument.
     * @return array Shaped array.
     */
    private static function of_array(array $value)
    {
        $shape = array();
        foreach ($value as $k => $v) {
            $shape[$k] = self::of($v, is_string($k) ? $k : null);
        }
        return $shape;
    }

    /**
     * Type label for a scalar, distinguishing pre-glued IN strings.
     *
     * @param mixed $value Scalar or null.
     * @return string Type label (e.g. "int", "string", "string:in-list").
     */
    private static function type_name($value)
    {
        if (is_string($value) && preg_match('/^\s*\(.*\)\s*$/s', $value)) {
            return 'string:in-list';
        }
        if (is_string($value) && is_numeric($value)) {
            return 'string:numeric';
        }
        return gettype($value);
    }

    /**
     * Copy of a value safe to store: objects named, long strings cut.
     *
     * @param mixed $value Any argument value.
     * @return mixed Sanitised copy.
     */
    public static function sample($value)
    {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'sample'), $value);
        }
        if (is_object($value)) {
            return 'object:' . get_class($value);
        }
        if (is_string($value) && strlen($value) > self::MAX_STRING) {
            return substr($value, 0, self::MAX_STRING) . '…';
        }
        return $value;
    }
}
