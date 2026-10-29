<?php

namespace EWP\Fields\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prepares a field definition for rendering: the implementation behind
 * awm_prepare_field(), awm_resolve_field_options(), awm_ewp_content_type_options()
 * and awm_ewp_option_page_options().
 *
 * Shorthand cases (`postType`, `term`, `user`, `taxonomies`, …) become a plain
 * `select` (or their `view`) with a `callback` that lists the choices.
 *
 * @package EWP\Fields\Data
 * @since 1.11.0
 */
final class Field_Preparer
{
    /**
     * Prepare a field for rendering, depending on its case.
     *
     * @param array  $a               The field's data.
     * @param string $awm_id          The id of the library.
     * @param bool   $resolve_options Run the field's options callback.
     * @return array The prepared field data.
     */
    public static function prepare($a, $awm_id = '', $resolve_options = true)
    {
        switch ($a['case']) {
            case 'ewp_content_types':
                $a = self::shorthand($a, 'awm_ewp_content_type_options');
                break;
            case 'ewp_option_pages':
                /**
                 * Custom field case that resolves option page choices at render time,
                 * avoiding recursion during field definition (options_boxes() cycle).
                 *
                 * @since 1.1.3
                 */
                $a = self::shorthand($a, 'awm_ewp_option_page_options');
                break;
            case 'ewp_content':
                $a = self::shorthand($a, 'awmEwpContentForInput', array($a['content_type'], self::arg($a, 'args', array())));
                break;
            case 'taxonomies':
                $a = self::shorthand($a, 'awmTaxObjectsForInput', array(self::arg($a, 'args', array())));
                break;
            case 'post_types':
                $a = self::shorthand($a, 'awmPostObjectsForInput', array(self::arg($a, 'args', array())));
                break;
            case 'postType':
                $a = self::shorthand($a, 'awmPostFieldsForInput', array($a['post_type'], self::arg($a, 'number', '-1'), self::arg($a, 'args', array())));
                break;
            case 'term':
                $a = self::shorthand($a, 'awmTaxonomyFieldsForInput', array(
                    $a['taxonomy'],
                    self::arg($a, 'number', '-1'),
                    self::arg($a, 'args', array()),
                    self::arg($a, 'option_key', 'term_id'),
                    $awm_id,
                    self::arg($a, 'show_all', false),
                ));
                break;
            case 'user':
                $a = self::shorthand($a, 'awmUserFieldsForInput', array(self::arg($a, 'roles', array()), self::arg($a, 'number', '-1'), self::arg($a, 'args', array())));
                break;
            case 'user_roles':
                $a = self::shorthand($a, 'awmUserRolesFieldsForInput', array(self::arg($a, 'exclude', array())));
                break;
            case 'date':
                $a = self::date($a);
                break;
            default:
                break;
        }

        return $resolve_options ? self::resolve_options($a) : $a;
    }

    /**
     * Fill a prepared field's `options` from its `callback`.
     *
     * The callback runs with `callback_variables` when they are set; when that
     * returns nothing (or there are none) it runs again without arguments.
     *
     * @param array $a Field data, as returned by prepare().
     * @return array The field with `options` filled (unchanged without a callback).
     */
    public static function resolve_options($a)
    {
        if (!isset($a['callback'])) {
            return $a;
        }
        $callback_options = array();
        if (!empty($a['callback_variables'])) {
            $callback_options = call_user_func_array($a['callback'], $a['callback_variables']);
        }
        $a['options'] = empty($callback_options) ? call_user_func($a['callback']) : $callback_options;
        return $a;
    }

    /**
     * Options of an `ewp_content_types` field: the registered content types.
     *
     * @return array Content type id => definition (with `label`).
     */
    public static function content_type_options()
    {
        return \AWM_Content_DB::get_instance()->get_content_types();
    }

    /**
     * Options of an `ewp_option_pages` field: the exportable options pages,
     * labelled with their field count.
     *
     * @return array Page id => array('label' => "Title (N fields)").
     */
    public static function option_page_options()
    {
        $opts = array();
        foreach (\EWP_Options_Portability::instance()->get_exportable_pages() as $pk => $pd) {
            $cnt = isset($pd['field_count']) ? $pd['field_count'] : count($pd['fields']);
            $opts[$pk] = array(
                'label' => sprintf('%s (%d %s)', $pd['title'], $cnt, _n('field', 'fields', $cnt, 'extend-wp')),
            );
        }
        return $opts;
    }

    /**
     * Turn a shorthand case into its `view` (default `select`) plus a callback.
     *
     * @param array      $a         Field data.
     * @param string     $callback  Options callback.
     * @param array|null $variables Callback arguments (null: leave `callback_variables` untouched).
     * @return array The field.
     */
    private static function shorthand($a, $callback, $variables = null)
    {
        $a['case']     = isset($a['view']) ? $a['view'] : 'select';
        $a['callback'] = $callback;
        if (null !== $variables) {
            $a['callback_variables'] = $variables;
        }
        return $a;
    }

    /**
     * Turn a `date` field into a text input with the date picker class.
     *
     * @param array $a Field data.
     * @return array The field.
     */
    private static function date($a)
    {
        $a['case'] = 'input';
        $a['type'] = 'text';
        $a['class'][] = 'awm_cl_date';
        if (isset($a['date-params'])) {
            $a['attributes']['date-params'] = str_replace('"', '\'', json_encode(apply_filters('awm_cl_date_params', $a['date-params'], $a)));
        }
        return $a;
    }

    /**
     * Read an optional key from the field.
     *
     * @param array  $a       Field data.
     * @param string $key     Key.
     * @param mixed  $default Fallback when unset.
     * @return mixed The value.
     */
    private static function arg($a, $key, $default)
    {
        return isset($a[$key]) ? $a[$key] : $default;
    }
}
