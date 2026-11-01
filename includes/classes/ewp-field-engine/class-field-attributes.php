<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalises and renders the `attributes` array of a field definition.
 *
 * @package EWP\Fields
 * @since 1.14.0
 */
final class Field_Attributes
{
    /**
     * Normalise raw attributes: array values become comma lists, the
     * placeholder is translated.
     *
     * @param array $attributes Raw `attributes` of a field definition.
     * @return array<string, string> Key => unescaped scalar value.
     */
    public static function prepare(array $attributes)
    {
        $prepared = array();
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if ('placeholder' === $key) {
                $value = __($value, 'extend-wp'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- labels defined by the plugin registering the field / content type, translated with that plugin's .po (intended).
            }
            $prepared[$key] = $value;
        }
        return $prepared;
    }

    /**
     * Render attributes as escaped `key="value"` pairs separated by spaces.
     *
     * @param array<string, string> $attributes Prepared attributes.
     * @return string Escaped markup, '' when empty.
     */
    public static function render(array $attributes)
    {
        $pairs = array();
        foreach ($attributes as $key => $value) {
            $pairs[] = esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        return implode(' ', $pairs);
    }
}
