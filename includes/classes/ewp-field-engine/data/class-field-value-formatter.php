<?php

namespace EWP\Fields\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display value of a field (list columns, read-only views): the implementation
 * behind awm_display_meta_value().
 *
 * @package EWP\Fields\Data
 * @since 1.11.0
 */
final class Field_Value_Formatter
{
    /**
     * Display value of a field.
     *
     * @param string $meta           Meta key.
     * @param array  $data           Field definition.
     * @param int    $postId         Object ID.
     * @param mixed  $external_value Value to show instead of the stored one (false: read it).
     * @param string $case           post_type|term|user (ignored with an external value).
     * @return string HTML.
     */
    public static function display($meta, $data, $postId = 0, $external_value = false, $case = 'post_type')
    {
        global $awm_post_id;
        $awm_post_id = $postId;
        $case  = $external_value ? 'external' : $case;
        $value = self::stored_value($meta, $postId, $external_value, $case);
        $original_value = $value;

        $case = isset($data['admin_list_view']) ? $data['admin_list_view'] : $data['case'];
        if (in_array($case, array('message', 'html'), true) && isset($data['strip'])) {
            return isset($data['value']) ? $data['value'] : '';
        }
        $value = self::format($case, $value, $data, $postId);

        /**
         * Filter the display value of a field (list columns, read-only views).
         *
         * @since 1.0.0
         *
         * @param mixed  $value          Display value (usually HTML) built for the field.
         * @param string $meta           Meta key.
         * @param mixed  $original_value Raw stored (or external) value before formatting.
         * @param array  $data           Field definition.
         * @param int    $postId         Object ID.
         */
        return apply_filters('awm_display_meta_value_filter', $value, $meta, $original_value, $data, $postId);
    }

    /**
     * Value as stored for the object (or the external value).
     *
     * @param string $meta           Meta key.
     * @param int    $postId         Object ID.
     * @param mixed  $external_value External value.
     * @param string $case           post_type|term|user|external.
     * @return mixed Value; null for an unknown case.
     */
    private static function stored_value($meta, $postId, $external_value, $case)
    {
        switch ($case) {
            case 'post_type':
                return get_post_meta($postId, $meta, true) ?: false;
            case 'term':
                return get_term_meta($postId, $meta, true) ?: false;
            case 'user':
                return get_user_meta($postId, $meta, true) ?: false;
            case 'external':
                return $external_value;
            default:
                return null;
        }
    }

    /**
     * Format a value for the field's case.
     *
     * @param string $case   Field case (or `admin_list_view`).
     * @param mixed  $value  Stored value.
     * @param array  $data   Field definition.
     * @param int    $postId Object ID.
     * @return mixed Display value (unchanged for an unhandled case).
     */
    private static function format($case, $value, $data, $postId)
    {
        switch ($case) {
            case 'ewp_content':
                return self::content($value, $data);
            case 'repeater':
                return self::repeater($value, $data);
            case 'input':
                return self::input($value, $data);
            case 'term':
                return self::term($value, $data);
            case 'post_types':
                return self::post_types($value);
            case 'user':
                return self::user($value);
            case 'postType':
                return self::post_type($value);
            case 'message':
            case 'html':
                return isset($data['value']) ? $data['value'] : '';
            case 'function':
                return self::callback($value, $data, $postId);
            case 'select':
            case 'checkbox_multiple':
                return self::options($value, $data);
            default:
                return $value;
        }
    }

    /**
     * Title of the custom content row.
     *
     * @param mixed $value Content id.
     * @param array $data  Field definition (`content_type`).
     * @return mixed The title, or the value when the row is missing.
     */
    private static function content($value, $data)
    {
        $object = awm_get_db_content($data['content_type'], array('include' => $value));
        if (!$object || empty($object)) {
            return $value;
        }
        return $object[0]['content_title'];
    }

    /**
     * Repeater rows as `Label: value | …`, rows joined by `<br>`.
     *
     * @param mixed $value Stored rows.
     * @param array $data  Field definition (`include`).
     * @return mixed The HTML, or the value when there is nothing to show.
     */
    private static function repeater($value, $data)
    {
        global $awm_post_id;
        if (!isset($data['include']) || empty($data['include']) || empty($value)) {
            return $value;
        }
        $final = array();
        foreach ($value as $val) {
            $row_val = array();
            foreach ($data['include'] as $key => $rep_data) {
                if (!isset($rep_data['admin_list']) || !$rep_data['admin_list']) {
                    continue;
                }
                $rep_val = isset($val[$key]) ? $val[$key] : '';
                if (!empty($rep_val)) {
                    $rep_val = awm_display_meta_value($key, $rep_data, $awm_post_id, $rep_val);
                }
                $row_val[] = $rep_data['label'] . ': ' . $rep_val;
            }
            $final[] = implode(' | ', $row_val);
        }
        return implode('<br>', $final);
    }

    /**
     * Checkbox as Yes/No, url as a link.
     *
     * @param mixed $value Stored value.
     * @param array $data  Field definition (`type`).
     * @return mixed Display value.
     */
    private static function input($value, $data)
    {
        switch ($data['type']) {
            case 'checkbox':
                return $value ? __('Yes', 'extend-wp') : __('No', 'extend-wp');
            case 'url':
                return $value != '' ? '<a href="' . $value . '" target="_blank">' . $value . '</a>' : '';
            default:
                return $value;
        }
    }

    /**
     * Name of the term.
     *
     * @param mixed $value Term id.
     * @param array $data  Field definition (`taxonomy`).
     * @return mixed The name, or the value when the term is missing.
     */
    private static function term($value, $data)
    {
        $term = get_term($value, $data['taxonomy']);
        return $term ? $term->name : $value;
    }

    /**
     * Labels of post types.
     *
     * @param mixed $value Post type slug, or a list of them.
     * @return string Comma separated labels.
     */
    private static function post_types($value)
    {
        $values = !is_array($value) ? array($value) : $value;
        $msg = array();
        foreach ($values as $slug) {
            $post_type = get_post_types(array('name' => $slug), 'objects');
            $msg[] = $post_type[$slug]->label;
        }
        return implode(', ', $msg);
    }

    /**
     * Link to the user's edit screen.
     *
     * @param mixed $value User id.
     * @return mixed The link, or the value when the user is missing.
     */
    private static function user($value)
    {
        $user = get_user_by('id', $value);
        if (!$user || is_wp_error($user)) {
            return $value;
        }
        /*get edit link also*/
        return '<a href="' . admin_url('user-edit.php?user_id=' . $value) . '" target="_blank">' . $user->display_name . '</a>';
    }

    /**
     * Links to the edit screens of posts.
     *
     * @param mixed $value Post id, or a list of them.
     * @return string Comma separated links.
     */
    private static function post_type($value)
    {
        $values = empty($value) ? array() : (!is_array($value) ? array($value) : $value);
        $msg = array();
        foreach ($values as $pvalue) {
            $msg[] = $pvalue != '' ? '<a href="' . admin_url('post.php?action=edit&post=' . $pvalue) . '" target="_blank">' . get_the_title($pvalue) . '</a>' : '-';
        }
        return implode(",", $msg);
    }

    /**
     * Output of the field's callback.
     *
     * @param mixed $value  Stored value.
     * @param array $data   Field definition (`callback`).
     * @param int   $postId Object ID passed to the callback.
     * @return mixed The callback output, or the value without a callable callback.
     */
    private static function callback($value, $data, $postId)
    {
        if (isset($data['callback']) && function_exists($data['callback'])) {
            return call_user_func_array($data['callback'], array($postId));
        }
        return $value;
    }

    /**
     * Labels of the selected options, joined by `<br>`.
     *
     * @param mixed $value Selected value, or a list of them.
     * @param array $data  Field definition (`options`, optional `callback`).
     * @return string The labels.
     */
    private static function options($value, $data)
    {
        $values = is_array($value) ? $value : array($value);
        if (isset($data['callback'])) {
            $data = self::with_callback_options($data);
        }
        $final = array();
        foreach ($values as $selected) {
            if (!empty($selected) && array_key_exists($selected, $data['options'])) {
                $final[] = $data['options'][$selected]['label'];
            }
        }
        return implode('<br>', $final);
    }

    /**
     * Resolve the field's callback into `options` (and `optgroups`).
     *
     * @param array $data Field definition with a `callback`.
     * @return array The field with `options` filled.
     */
    private static function with_callback_options($data)
    {
        $callback_options = array();
        if (!empty($data['callback_variables'])) {
            $callback_options = call_user_func_array($data['callback'], $data['callback_variables']);
        }
        $callback_results = empty($callback_options) ? call_user_func($data['callback']) : $callback_options;
        $data['options']  = $callback_results;
        if (isset($callback_results['optgroups'])) {
            $data['optgroups'] = $callback_results['optgroups'];
            $data['options']   = $callback_results['options'];
        }
        return $data;
    }
}
