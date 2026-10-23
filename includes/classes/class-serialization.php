<?php

namespace EWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unserialize stored values without ever creating objects.
 *
 * Meta and log values are stored with maybe_serialize(), and custom content
 * meta is read with two passes because older code serialized before the
 * DB layer serialized again. A user-supplied string that looks serialized
 * survives the first pass as a string, and a plain maybe_unserialize() on
 * the second pass would instantiate any class it names (PHP object
 * injection). These helpers unserialize with `allowed_classes => false`.
 *
 * @package EWP
 *
 * @since 1.10.0
 */
class Serialization
{
    /**
     * Unserialize a value if it is a serialized string; objects become __PHP_Incomplete_Class.
     *
     * @param mixed $value Required. Stored value.
     * @return mixed Unserialized value, or `$value` unchanged when it is not serialized.
     */
    public static function unserialize($value)
    {
        if (!is_string($value) || !is_serialized($value)) {
            return $value;
        }
        $result = @unserialize(trim($value), array('allowed_classes' => false));
        return ($result === false && $value !== serialize(false)) ? $value : $result;
    }

    /**
     * Two unserialize passes, for custom content meta written by older code.
     *
     * @param mixed $value Required. Stored value.
     * @return mixed Unserialized value.
     */
    public static function unserialize_twice($value)
    {
        return self::unserialize(self::unserialize($value));
    }
}
