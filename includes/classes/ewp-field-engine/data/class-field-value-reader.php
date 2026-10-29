<?php

namespace EWP\Fields\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads a field's stored value for a view: the implementation behind
 * awm_get_field_value() and awm_get_raw_field_value().
 *
 * @package EWP\Fields\Data
 * @since 1.11.0
 */
final class Field_Value_Reader
{
    /**
     * Stored value of a field, run through `awm_show_content_value_filter`.
     *
     * @param string           $view          widget|user|term|post|option|restrict_manage_posts.
     * @param array            $fields        The field definition (`widget-key` for view `widget`).
     * @param int|string|array $id            Post/term/user id, or the widget instance.
     * @param string           $original_meta The meta key.
     * @return mixed The (filtered) value; 0 for an unknown view.
     */
    public static function value($view, $fields, $id, $original_meta)
    {
        $val = self::read($view, $fields, $id, $original_meta);
        /**
         * Filter a field value read for display or editing.
         *
         * @since 1.0.0
         *
         * @param mixed  $val           Stored value.
         * @param int    $id            Object ID.
         * @param string $original_meta Meta key.
         * @param string $view          post|term|user|option|restrict_manage_posts.
         */
        return apply_filters('awm_show_content_value_filter', $val, $id, $original_meta, $view);
    }

    /**
     * Raw value from the database, without decryption hooks.
     *
     * @param string           $view          post|term|user|option (anything else yields '').
     * @param int|string|array $id            The object id.
     * @param string           $original_meta The meta key.
     * @return mixed Raw stored value, '' when empty.
     */
    public static function raw($view, $id, $original_meta)
    {
        switch ($view) {
            case 'user':
                return get_user_meta($id, $original_meta, true) ?: '';
            case 'term':
                return get_term_meta($id, $original_meta, true) ?: '';
            case 'post':
                return get_post_meta($id, $original_meta, true) ?: '';
            case 'option':
                return get_option($original_meta) ?: '';
            default:
                return '';
        }
    }

    /**
     * Unfiltered value for a view.
     *
     * @param string           $view          The view.
     * @param array            $fields        The field definition.
     * @param int|string|array $id            The object id / widget instance.
     * @param string           $original_meta The meta key.
     * @return mixed Value; 0 for an unknown view.
     */
    private static function read($view, $fields, $id, $original_meta)
    {
        switch ($view) {
            case 'widget':
                return isset($id[$fields['widget-key']]) ? $id[$fields['widget-key']] : '';
            case 'restrict_manage_posts':
                return isset($_GET[$original_meta]) ? $_GET[$original_meta] : ''; // phpcs:ignore WordPress.Security.NonceVerification
            case 'user':
            case 'term':
            case 'post':
            case 'option':
                return self::raw($view, $id, $original_meta);
            default:
                return 0;
        }
    }
}
