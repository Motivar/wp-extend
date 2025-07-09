<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('awm_show_explanation')) {
    /**
     * with this we show the explanation for the user
     * @param array $data the data for the box
     */
    function awm_show_explanation($data)
    {
        if (isset($data['explanation']) && !empty($data['explanation'])) {
            return '<div class="awm-box-explanation">' . __($data['explanation'], 'extend-wp') . '</div>';
        }
        return '';
    }
}

if (!function_exists('awm_get_field_value')) {
    /**
     * get awm value for fields
     * @param string $view the view to user
     * @param array $fields all the fields
     * @paramint|string|array $id the post_id,term_id
     * @param string $original_meta the meta which used
     */
    function awm_get_field_value($view, $fields, $id, $original_meta)
    {
        switch ($view) {
            case 'widget':
                $val = isset($id[$fields['widget-key']]) ? $id[$fields['widget-key']] : '';
                break;
            case 'user':
                $val = get_user_meta($id, $original_meta, true) ?: '';
                break;
            case 'term':
                $val = get_term_meta($id, $original_meta, true) ?: '';
                break;
            case 'post':
                $val = get_post_meta($id, $original_meta, true) ?: '';
                break;
            case 'restrict_manage_posts':
                $val = isset($_GET[$original_meta]) ? $_GET[$original_meta] : '';
                break;
            default:
                $val = 0;
                break;
        }
        $val = apply_filters('awm_show_content_value_filter', $val, $id, $original_meta, $view);
        return $val;
    }
}


if (!function_exists('awm_prepare_field')) {
    /**
     * with this function we prepare the ewp field dependin on the case
     * @param array $a the fields' data
     * @param string $awm_id the id of the library
     * @return arrray $a the manipulated fields data
     */
    function awm_prepare_field($a, $awm_id = '')
    {

        switch ($a['case']) {
            case 'ewp_content_types':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $db_content = AWM_Content_DB::get_instance();
                $a['options'] = $db_content->get_content_types();
                break;
            case 'ewp_content':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmEwpContentForInput';
                $a['callback_variables'] = array($a['content_type'], $args);
                break;
            case 'taxonomies':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmTaxObjectsForInput';
                $a['callback_variables'] = array($args);
                break;
            case 'post_types':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmPostObjectsForInput';
                $a['callback_variables'] = array($args);
                break;
            case 'postType':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmPostFieldsForInput';
                $a['callback_variables'] = array($a['post_type'], $number, $args);
                break;
            case 'term':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';

                $args = isset($a['args']) ? $a['args'] : array();
                $option_key = isset($a['option_key']) ? $a['option_key'] : 'term_id';
                $a['callback'] = 'awmTaxonomyFieldsForInput';
                $show_all = isset($a['show_all']) ? $a['show_all'] : false;
                $a['callback_variables'] = array($a['taxonomy'], $number,  $args, $option_key, $awm_id, $show_all);
                break;
            case 'user':
                $roles = isset($a['roles']) ? $a['roles'] : array();
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmUserFieldsForInput';
                $a['callback_variables'] = array($roles, $number, $args);
                break;
            case 'user_roles':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $exclude = isset($a['exclude']) ? $a['exclude'] : array();
                $a['callback'] = 'awmUserRolesFieldsForInput';
                $a['callback_variables'] = array($exclude);
                break;
            case 'date':
                $a['case'] = 'input';
                $a['type'] = 'text';
                $a['class'][] = 'awm_cl_date';
                if (isset($a['date-params'])) {
                    $a['attributes']['date-params'] = str_replace('"', '\'', json_encode(apply_filters('awm_cl_date_params', $a['date-params'], $a)));
                }
                break;
            default:
                break;
        }

        if (isset($a['callback'])) {
            $callback_options = array();
            if (!empty($a['callback_variables'])) {
                $callback_options = call_user_func_array($a['callback'], $a['callback_variables']);
            }
            $a['options'] = empty($callback_options) ? call_user_func($a['callback']) : $callback_options;
        }

        return $a;
    }
}


if (!function_exists('awm_show_content')) {
    /**
     * this is the function which is responsible to display the custom inputs for metaboxes/options
     * @param array $arrs the array with the inputs
     * @param array $id the post_id,term_id
     * @param array $view which source to use to load the data
     */
    function awm_show_content($arrs, $id = 0, $view = 'post', $target = 'edit', $label = true, $specific = '', $sep = ', ')
    {
        static $user_roles = array();
        static $user_checked = false;
        $msg = array();
        global $awm_post_id;
        $awm_post_id = $id;
        $awm_id = '';
        if (isset($arrs['awm-id'])) {
            $awm_id = $arrs['awm-id'];
            unset($arrs['awm-id']);
        }
        if (empty($arrs)) {
            return array();
        }
        uasort($arrs, function ($a, $b) {
            $first = isset($a['order']) ? absint($a['order']) : 100;
            $second = isset($b['order']) ? absint($b['order']) : 100;
            return $first - $second;
        });
        $meta_counter = 0;
        if (!$user_checked) {
            if (is_user_logged_in() && empty($user_roles)) {
                $user = wp_get_current_user();
                if (!is_wp_error($user)) {
                    $user_roles = $user->roles;
                }
            }
            $user_checked = true;
        }

        foreach ($arrs as $n => $a) {
            /**
             * check for translation plugins
             */
            /** wmpl check */
            if (function_exists('icl_object_id')) {
                global $sitepress;
                $default = $sitepress->get_default_language();

                if ($default != ICL_LANGUAGE_CODE && isset($a['auto_translate'])) {
                    continue;
                }
            }
            if (!in_array('administrator', $user_roles)) {
                if (isset($a['not_visible_by']) && !empty($a['not_visible_by'])) {
                    $a['not_visible_by'] = is_array($a['not_visible_by']) ? $a['not_visible_by'] : array($a['not_visible_by']);
                    $matching_roles = array_intersect($user_roles, $a['not_visible_by']);

                    if (!empty($matching_roles)) {
                        unset($arrs[$n]);
                        continue;
                    }
                }
                if (isset($a['not_editable_by']) && !empty($a['not_editable_by'])) {
                    $a['not_editable_by'] = is_array($a['not_editable_by']) ? $a['not_editable_by'] : array($a['not_editable_by']);
                    $matching_roles = array_intersect($user_roles, $a['not_editable_by']);
                    if (!empty($matching_roles)) {
                        $a['attributes']['disabled'] = 'disabled';
                        $a['attributes']['readonly'] = 'readonly';
                        $a['disabled'] = true;
                    }
                }
            }

            /*check if hidden val or not*/
            $required = (isset($a['required']) && $a['required']) ? 'required="true"' : false;
            $explanation = isset($a['explanation']) ? '<span class="awm-explanation">' . __($a['explanation'], 'extend-wp') . '</span>' : '';
            $original_meta = $n;
            $display_wrapper = true;
            $ins = '';
            $label = isset($a['label']) ? $a['label'] : $n;
            if (substr($n, 0, 1) === '_') {
                $n = ltrim($n, '_');
            }

            /**exlude meta for widgets */
            if ($view == 'widget') {
                $a['exclude_meta'] = true;
            }

            if (($n == $specific && $specific != '') || $specific == '') {
                $show = isset($a['show']) ? $a['show'] : 1;
                $stop = 0;
                if ($show == 1) {
                    switch ($a['case']) {
                        case 'html':
                            $html_label = '';
                            if (isset($a['value']) && !empty($a['value'])) {
                                if (isset($a['show_label']) && isset($a['label'])) {
                                    $html_label = '<label for="' . $original_meta . '" class="awm-input-label">' . $a['label'] . '</label>' . $explanation;
                                }
                                $msg[] = '<div class="awm-meta-html awm-meta-field" id="' . $n . '">' . $html_label . $a['value'] . '</div>';
                                $stop = true;
                                if (isset($a['strip'])) {
                                    $msg = array($a['value']);
                                }
                            }
                            break;
                        default:
                            $a = awm_prepare_field($a, $awm_id);
                            break;
                    }

                    /*make changes for combined inputs*/
                    $val = awm_get_field_value($view, $a, $id, $original_meta);
                    $label_class = $extra_fields2 = $label_attrs = array();
                    $extraa = '';
                    $class = isset($a['class']) ? implode(' ', $a['class']) : '';

                    if (isset($a['label_class']) && !empty($a['label_class'])) {
                        $label_class = $a['label_class'];
                    }
                    /*check if isset attribute value*/
                    if (isset($a['attributes']['value'])) {
                        $val = $a['attributes']['value'];
                        unset($a['attributes']['value']);
                    }

                    /*change the id*/
                    $original_meta_id = $original_meta;
                    if (isset($a['attributes']['id'])) {
                        $original_meta_id = $a['attributes']['id'];
                        unset($a['attributes']['id']);
                    }
                    $label_class[] = $a['case'];

                    $label_class = apply_filters('awm_label_class_filter', $label_class, $a, $required);
                    if (in_array('awm-needed', $label_class)) {
                        $required = '';
                    }


                    if (isset($a['type'])) {
                        $label_class[] = $a['type'];
                    }

                    /*display input fields*/
                    $hide_label = isset($a['hide-label']) ? $a['hide-label'] : false;
                    if (!$hide_label && $label && !in_array($a['case'], array('checkbox_multiple', 'repeater', 'awm_tab', 'button'))) {
                        if (($a['case'] == 'input' && isset($a['type']) && $view != 'none' && !in_array($a['type'], array('submit', 'hidden', 'button'))) || ($a['case'] == 'select' || $a['case'] == 'textarea' || $a['case'] == 'radio')) {
                            $ins .= '<label for="' . $original_meta_id . '" class="awm-input-label"><span>' . $label . '</span></label>' . $explanation;
                        }
                    }
                    if (isset($a['show-when']) && !empty($a['show-when']) && is_array($a['show-when'])) {
                        $label_attrs[] = 'show-when="' . str_replace('"', '\'', json_encode($a['show-when'])) . '"';
                    }
                    if (isset($a['disable-elements']) && !empty($a['disable-elements']) && is_array($a['disable-elements'])) {
                        $a['attributes']['disable-elements'] = str_replace('"', '\'', json_encode($a['disable-elements']));
                    }
                    if (!empty($a['attributes']) && is_array($a['attributes'])) {

                        foreach ($a['attributes'] as $k => $v) {
                            if (is_array($v)) {
                                $v = implode(',', $v);
                            }
                            if ($k == 'placeholder') {
                                $v = __($v, 'extend-wp');
                            }
                            $extra_fields2[] = $k . '="' . $v . '"';
                            if ($k == 'min' && $val == 0) {
                                $val = $v;
                            }
                        }
                    }


                    $extraa .= isset($extra_fields2) ? implode(' ', $extra_fields2) : '';

                    switch ($a['case']) {
                        case 'function':
                            if (isset($a['callback'])  && function_exists($a['callback'])) {
                                $ins = '<div class="awm-meta-message" id="' . $original_meta_id . '"><div class="awm-meta-message-label">' . $a['label'] . $explanation . '</div><div class="awm-meta-message-inner">'  . call_user_func_array($a['callback'], array($id)) . '</div></div>';
                            }
                            break;
                        case 'awm_gallery':
                            if (!did_action('wp_enqueue_media')) {
                                wp_enqueue_media();
                                wp_enqueue_script('jquery-ui-sortable');
                            }
                            $ins = '<div class="awm-meta-message" id="' . $original_meta_id . '"><div class="awm-meta-message-label">' . $a['label'] . $explanation . '</div>' . awm_gallery_meta_box_html($original_meta_id, $val) . '</div>';
                            break;
                        case 'message':
                            if (isset($a['value']) && !empty($a['value'])) {
                                $ins = '<div class="awm-meta-message" id="' . $original_meta_id . '"><div class="awm-meta-message-label">' . $a['label'] . $explanation . '</div><div class="awm-meta-message-inner">' . $a['value'] . '</div></div>';
                            }
                            break;
                        case 'button':
                            $link = isset($a['link']) ? $a['link'] : '#';
                            $ins = '<a href="' . $link . '" id="' . $n . '" title="' . $a['label'] . '" class="' . $class . '" ' . $extraa . '>' . $a['label'] . '</a>';
                            break;
                        case 'input':
                            $input_type = $a['type'];
                            $after_message = (isset($a['after_message']) && !empty($a['after_message'])) ? '<span class="awm-after-message"><label for="' . $original_meta_id . '">' . $a['after_message'] . '</span></label>' : '';

                            switch ($input_type) {
                                case 'number':
                                    $val = (int) $val;
                                    break;
                                case 'checkbox':
                                    if ($val == 1) {
                                        $extraa .= ' checked';
                                    }
                                    $val = 1;
                                    break;
                                case 'hidden':
                                    $ins .= '<input type="' . $input_type . '" name="' . $original_meta . '" id="' . $original_meta_id . '" value="' . $val . '" ' . $extraa . ' class="' . $class . '" ' . $required . '/>';
                                    $display_wrapper = false;
                                    break;
                                default:
                                    break;
                            }
                            if ($display_wrapper) {
                                $input_html = '<input type="' . $input_type . '" name="' . $original_meta . '" id="' . $original_meta_id . '" value="' . $val . '" ' . $extraa . ' class="' . $class . '" ' . $required . '/>';

                                $ins .= '<div class="input-wrapper">';
                                $ins .=  $input_html . $after_message;
                                if ($a['type'] == 'password') {
                                    $ins .= '<div class="eye" data-toggle="password" data-id="' . $original_meta_id . '"></div>';
                                }
                                $ins .= '</div>';
                            }

                            break;
                        case 'checkbox_multiple':
                            $ins .= '<label class="awm-checkboxes-title"><span>' . $a['label'] . '</span></label>' . $explanation;
                            $checkboxOptions = array();
                            $ins .= '<div class="awm-options-wrapper">';
                            if (isset($a['options']) && !empty($a['options'])) {
                                if (!isset($a['disable_apply_all'])) {
                                    $checkboxOptions['awm_apply_all'] = array('label' => __('Select All', 'extend-wp'), 'extra_label' => __('Deselect All', 'extend-wp'));
                                }
                                $checkboxOptions = $checkboxOptions + $a['options'];
                                $val = !is_array($val) ? array($val) : $val;
                                foreach ($checkboxOptions as $dlm => $dlmm) {
                                    $chk_ex = $chk_label_class = '';
                                    if (is_array($val) && in_array($dlm, $val)) {
                                        $chk_ex = ' checked';
                                        $chk_label_class = ' selected';
                                    }
                                    $value_name = $dlm != 'amw_apply_all' ? $original_meta . '[]' : '';
                                    $extraLabel = ($dlm == 'awm_apply_all' && isset($dlmm['extra_label'])) ? 'data-extra="' . $dlmm['extra_label'] . '"' : '';
                                    $valueInside = $dlm != 'awm_apply_all' ? $dlm : '';
                                    $input_id = $original_meta_id . '_' . $dlm . '_' . rand(10, 100);
                                    global $ewp_input_vars;
                                    $ewp_input_vars = array(
                                        'field' => $a,
                                        'input_id' => $input_id,
                                        'value_name' => $value_name,
                                        'valueInside' => $valueInside,
                                        'extraa' => $extraa,
                                        'chk_ex' => $chk_ex,
                                        'chk_label_class' => $chk_label_class,
                                        'class' => $class,
                                        'extraLabel' => $extraLabel,
                                        'dlm' => $dlm,
                                        'dlmm' => $dlmm
                                    );
                                    $ins .= awm_parse_template(awm_path . 'templates/frontend/inputs/checkbox_multiple.php', $ewp_input_vars);
                                }
                                $n = $n . '[]';
                            }
                            $ins .= '</div>';
                            break;
                        case 'select':
                            if ($val != '' && !is_array($val)) {
                                $val = array($val);
                            }

                            if (isset($a['options']['optgroups'])) {
                                $a['optgroups'] = $a['options']['optgroups'];
                                unset($a['options']['optgroups']);
                            }

                            $select_name = $original_meta;
                            if (isset($a['attributes']) && array_key_exists('multiple', $a['attributes']) && $a['attributes']['multiple']) {
                                $select_name .= '[]';
                            }
                            $ins .= '<select name="' . $select_name . '" id="' . $original_meta_id . '" class="' . $class . '" ' . $extraa . ' ' . $required . '>';

                            if (empty($a['options'])) {

                                switch ($view) {
                                    case 'restrict_manage_posts':

                                        $stop = 1;
                                        break;
                                }
                            }

                            $optgroups = isset($a['optgroups']) ? $a['optgroups'] : array();
                            $optgroups_assigned = 0;
                            $select_options = array();
                            if (!empty($a['options'])) {
                                if (!(isset($a['removeEmpty']) && $a['removeEmpty'])) {
                                    $select_options[] = '<option value="" data-html="' . str_replace('"', "'", json_encode(htmlspecialchars($a['label']))) . '" data-placeholder="true">' . $a['label'] . '</option>';
                                }

                                foreach ($a['options'] as $vv => $vvv) {

                                    $selected = '';
                                    if (!empty($val) && in_array($vv, $val)) {
                                        $selected = 'selected';
                                    }
                                    $attrs = array();
                                    if (isset($vvv['extra'])) {
                                        foreach ($vvv['extra'] as $lp => $ld) {
                                            $attrs[] = $lp . '="' . $ld . '"';
                                        }
                                    }
                                    $option_label = isset($vvv['label']) ? $vvv['label'] : $vv;
                                    $data_html = !isset($a['no_style']) ? 'data-html="' . str_replace('"', "'", json_encode(htmlspecialchars($option_label))) . '"' : '';
                                    $select_options[$vv] = '<option value="' . $vv . '" ' . $selected . ' ' . implode(' ', $attrs) . ' ' . $data_html . ' >' . $option_label . '</option>';
                                    if (!empty($optgroups) && isset($vvv['optgroup'])) {
                                        $optgroups[$vvv['optgroup']]['options'][] = $vv;
                                        $optgroups_assigned++;
                                    }
                                }
                            }
                            if ($optgroups_assigned > 0) {
                                $optgroup_options = array();
                                foreach ($optgroups as $opt_id => $opt_option) {
                                    if (isset($opt_option['options']) && !empty($opt_option['options']))
                                        $optgroup_options['opt_' . $opt_id . '_start'] = '<optgroup id="' . $opt_id . '" label="' . $opt_option['label'] . '" data-html="' . str_replace('"', "'", json_encode(htmlspecialchars($opt_option['label']))) . '" options="' . implode(',', $opt_option['options']) . '">';
                                    foreach ($opt_option['options'] as $opt_option_id) {
                                        $optgroup_options[$opt_option_id] = $select_options[$opt_option_id];
                                        unset($select_options[$opt_option_id]);
                                    }
                                    $optgroup_options['opt_' . $opt_id . '_end'] = '</optgroup>';
                                }
                                $select_options = $optgroup_options + $select_options;
                            }
                            $ins .= implode('', $select_options) . '</select>';

                            break;
                        case 'image':
                            if (!did_action('wp_enqueue_media')) {
                                wp_enqueue_media();
                                wp_enqueue_script('jquery-ui-sortable');
                            }
                            $multiple = isset($a['multiple']) ? $a['multiple'] : false;
                            $ins .= '<label for="' . $original_meta_id . '" class="awm-input-label">' . $a['label'] . '</label>' . $explanation;
                            $ins .= awm_custom_image_image_uploader_field($original_meta, $original_meta_id, $val, $multiple, $required);
                            $label_class[] = 'awm-custom-image-meta';
                            break;
                        case 'textarea':
                            $label_class[] = 'awm-cls-100';
                            $wp_editor = isset($a['wp_editor']) ? $a['wp_editor'] : (isset($a['attributes']['wp_editor']) ? $a['attributes']['wp_editor'] : false);

                            if ($wp_editor) {
                                $wp_args = array('textarea_name' => $original_meta, 'editor_class' => $class, 'textarea_rows' => 10);
                                $wp_disalbed = isset($a['disabled']) ? $a['disabled'] : false;
                                if ($wp_disalbed) {
                                    // $wp_args['tinymce'] = array('readonly' => 1);
                                }
                                $wp_editor_textarea = '';
                                ob_start();
                                wp_editor($val, $original_meta_id, $wp_args);
                                $wp_editor_textarea = ob_get_clean();
                                $ins .= $wp_editor_textarea;
                                $label_class[] = 'awm-wp-editor';
                            } else {
                                if (isset($a['awm_strip_html'])) {
                                    $val = strip_tags($val);
                                }
                                $ins .= '<textarea rows="5" name="' . $original_meta . '" id="' . $original_meta_id . '" class="' . $class . '" ' . $required . ' ' . $extraa . '>' . $val . '</textarea>';
                            }

                            break;
                        case 'radio':
                            $optionsCounter = 0;
                            $radio_div = '<div class="awm-radio-options">';
                            foreach ($a['options'] as $vkey => $valll) {
                                $chk = '';
                                $labelRequired = '';
                                if ($vkey == $val) {
                                    $chk = 'checked="checked"';
                                }
                                if ($optionsCounter < 1 && $required != '') {
                                    $labelRequired = $required;
                                }
                                if (!isset($a['disable_wrapper'])) {
                                    $radio_div .= '<div class="awm-radio-option">';
                                }
                                $radio_div .= '<input type="radio" name="' . $original_meta . '" id="' . $original_meta_id . '_' . $vkey . '" value="' . $vkey . '" ' . $chk . ' ' . $labelRequired . '/><label class="awm-radio-option-label" for="' . $original_meta_id . '_' . $vkey . '"><span class="awm-radio-label">' . apply_filters('awm_radio_value_label_filter', $valll['label'], $vkey, $original_meta_id) . '</span></label>';

                                if (!isset($a['disable_wrapper'])) {
                                    $radio_div .= '</div>';
                                }
                                $optionsCounter++;
                            }
                            $radio_div .= '</div>';
                            $radio_div .= apply_filters('awm_radio_after_text', '', $a);

                            $ins .= $radio_div;
                            break;
                        case 'section':
                            $label_class[] = 'awm-section-field';
                            $ins .= '<div class="awm-inner-section">';
                            if (isset($a['label'])) {
                                $ins .= '<div class="section-header">' . $a['label'] . $explanation . '</div>';
                            }
                            $ins .= '<div class="awm-inner-section-content">';
                            $val = !empty($val) ? maybe_unserialize($val) : array();
                            $section_fields = array();
                            foreach ($a['include'] as $key => $data) {

                                $inputname = !isset($a['keep_inputs']) ? $original_meta_id . '[' . $key . ']' : $key;

                                $data['attributes']['id'] = isset($a['keep_inputs']) ? $original_meta_id . '_' . $key : $key;
                                $data['attributes']['exclude_meta'] = true;
                                if (!isset($a['keep_inputs']) && !empty($val) && isset($val[$key])) {
                                    $data['attributes']['value'] = $val[$key];
                                }
                                $section_fields[$inputname] = $data;
                            }
                            $ins .= awm_show_content($section_fields);
                            $ins .= '</div></div>';

                            break;
                        case 'awm_tab':
                            if (isset($a['awm_tabs']) && !empty($a['awm_tabs'])) {
                                $main_tab_id = $original_meta;
                                $tabs = '';
                                $tab_contents = '';
                                $ins .= '<div class="awm-tab-wrapper">';
                                $ins .= '<div class="awm-tab-wrapper-title">' . $a['label'] . '</div>';
                                $first_visit = 0;
                                $val = !empty($val) ? $val : array();
                                foreach ($a['awm_tabs'] as $tab_id => $tab_intro) {
                                    ++$first_visit;
                                    $show = $first_visit == 1 ? 'awm-tab-show active' : '';
                                    $style = $first_visit == 1 ? 'style="display: block;"' : '';
                                    $tabs .= '<div id="' . $tab_id . '_tab" class="awm_tablinks ' . $show . '" onclick="awm_open_tab(event,\' ' . $tab_id . '\')">' . $tab_intro['label'] . '</div>';
                                    $tab_contents .= '<div id="' . $tab_id . '_content_tab" class="awm_tabcontent" ' . $style . '>';
                                    $tab_meta = array();
                                    if (isset($tab_intro['callback'])) {
                                        $callback_options = array();
                                        if (!empty($tab_intro['callback_variables'])) {
                                            $callback_options = call_user_func_array($data['callback'], $data['callback_variables']);
                                        }
                                        $tab_intro['include'] = empty($callback_options) ? call_user_func($tab_intro['callback']) : $callback_options;
                                    }
                                    foreach ($tab_intro['include'] as $key => $data) {
                                        $inputname = $main_tab_id . '[' . $tab_id . '][' . $key . ']';
                                        $data['attributes']['id'] = $main_tab_id . '_' . $tab_id . '_' . $key;
                                        if (isset($val[$tab_id][$key])) {
                                            $data['attributes']['value'] = $val[$tab_id][$key];
                                        }
                                        $data['attributes']['exclude_meta'] = true;
                                        $tab_meta[$inputname] = $data;
                                    }
                                    if (!empty($explanation)) {
                                        $tab_contents .= '<div class="tab-explanation">' . $explanation . '</div>';
                                    }
                                    $tab_contents .= awm_show_content($tab_meta);
                                    $tab_contents .= '</div>';
                                }
                                $ins .= '<div class="awm-tab">' . $tabs . '</div>' . $tab_contents;
                                $ins .= '</div>';
                            }

                            break;
                        case 'map':
                            $label_class[] = 'awm-cls-100';
                            $lat = (isset($val['lat']) && !empty($val['lat'])) ? $val['lat'] : '';
                            $lng = (isset($val['lng']) && !empty($val['lng'])) ? $val['lng'] : '';
                            $address = (isset($val['address']) && !empty($val['address'])) ? $val['address'] : '';
                            $ins .= '<input id="awm_map' . $original_meta_id . '_search_box" class="controls" type="text" placeholder="' . __('Type to search', 'awm') . '" value="' . $address . '" ' . $required . ' onkeypress="return noenter()"><div class="awm_map" id="awm_map' . $original_meta_id . '"></div>';
                            $ins .= '<input type="hidden" name="' . $original_meta . '[lat]" id="awm_map' . $original_meta_id . '_lat" value="' . $lat . '" />';
                            $ins .= '<input type="hidden" name="' . $original_meta . '[lng]" id="awm_map' . $original_meta_id . '_lng" value="' . $lng . '" />';
                            $ins .= '<input type="hidden" name="' . $original_meta . '[address]" id="awm_map' . $original_meta_id . '_address" value="' . $address . '" />';
                            break;
                        case 'repeater':
                            if (!empty($a['include'])) {
                                $minrows = isset($a['minrows']) ? absint($a['minrows']) : 0;
                                $maxrows = isset($a['maxrows']) ? absint($a['maxrows']) : '';
                                $ins .= '<div class="awm-repeater" data-count="' . count($a['include']) . '" data-id="' . $original_meta_id . '" maxrows="' . $maxrows . '">';
                                $r_label = isset($a['label']) ? $a['label'] : '';
                                $ins .= '<div class="awm-repeater-title">' . $r_label . $explanation . '</div>';
                                $ins .= '<div class="awm-repeater-contents">';
                                $val = !empty($val) ? array_values(maybe_unserialize($val)) : array();
                                if ((empty($val)) && isset($a['prePopulated'])) {
                                    $val = $a['prePopulated'];
                                }
                                $a['include']['awm_key'] = array(
                                    'case' => 'input',
                                    'type' => 'hidden',
                                    'attributes' => array('data-unique' => true)
                                );
                                $counter = !empty($val) ? count($val) : $minrows;
                                if ($counter != 0) {
                                    for ($i = 0; $i < $counter; ++$i) {
                                        $ins .= awm_repeater_content($i, $original_meta, $a, $original_meta_id, $val);
                                    }
                                }
                                if (!isset($a['no_template'])) {
                                    $ins .= awm_repeater_content('template', $original_meta, $a, $original_meta_id, array());
                                }
                                $ins .= '</div>';

                                $ins .= '</div>';
                            }
                            break;
                        default:
                            break;
                    }
                    if ($label && !(isset($a['attributes']['exclude_meta'])) && $view != 'none' && !isset($a['attributes']['disabled']) && !isset($a['exclude_meta'])) {
                        $ins .= '<input type="hidden" name="awm_custom_meta[]" value="' . $original_meta . '"/>';
                    }



                    if ($stop != 1 && isset($n)) {
                        $label_class[] = 'awm-meta-field';
                        $labelClass = implode(' ', $label_class);
                        $labelAttrs = implode(' ', $label_attrs);
                        if (!$display_wrapper) {
                            $msg[] = $ins;
                            continue;
                        }
                        switch ($view) {
                            case 'none':
                                /*fronted view*/
                                $msg[] = $ins;
                                break;
                            case 'term':
                                switch ($id) {
                                    case 0:
                                        $msg[] = '<div class="form-field term-group awm-term-meta-row awm-meta-term-field ' . $labelClass . '" ' . $labelAttrs . '>' . $ins . '</div>';
                                        break;
                                    default:
                                        $msg[] = '<tr class="form-field term-group-wrap awm-meta-term-field" data-input="' . $original_meta_id . '"><th scope="row" class="' . implode(' ', $label_class) . '" data-input="' . $original_meta_id . '" data-type="' . $a['case'] . '"><label for="' . $original_meta_id . '" class="awm-input-label">' . $a['label'] . '</label>' . $explanation . '</th><td class="awm-term-input">' . $ins . '</td></tr>';
                                        break;
                                }

                                break;
                            case 'user':
                                /*user view*/
                                $msg[] = '<tr data-input="' . $original_meta_id . '" ' . $labelAttrs . ' class="' . implode(' ', $label_class) . '" data-input="' . $original_meta_id . '" data-type="' . $a['case'] . '"><th><label for="' . $original_meta_id . '" class="awm-input-label"><span>' . $a['label'] . '</span></label>' . $explanation . '</th>';
                                $msg[] = '<td class="awm-user-input">' . $ins . '</td></tr>';
                                break;
                            default:
                                $msg[] = '<div class="' . implode(' ', $label_class) . '" data-input="' . $original_meta_id . '" data-type="' . $a['case'] . '" ' . $labelAttrs . ' id="awm-element-' . $original_meta_id . '">';
                                $msg[] = $ins;
                                if (is_admin() && isset($a['information']) && !empty($a['information'])) {
                                    $msg[] = '<div class="flx-tippy-admin-message"><span class="flx_icon flx-icon-gps" data-message="' . $a['information'] . '"></span></div>';
                                }
                                $msg[] = '</div>';
                                break;
                        }
                        if (!in_array('awm_no_show', $label_class)) {
                            $meta_counter++;
                        }
                    }
                }
            }
        }
        if (empty($msg) || empty($arrs)) {
            return '';
        }

        $msg = apply_filters('awm_show_content_filter', $msg, $id, $arrs, $view, $target, $label, $specific, $sep);



        if (!empty($msg)) {
            $msg = '<div id="' . $awm_id . '" class="awm-show-content" count="' .  $meta_counter . '">' . implode('', $msg) . '</div>';
        }


        return $msg;
    }
}

function awm_repeater_content($i, $original_meta, $a, $original_meta_id, $val)
{

    $class = '';
    if (!is_int($i) && $i == 'template') {
        $class = 'temp-source';
    }
    $html = '<div id="awm-' . str_replace('[', '_', str_replace(']', '', str_replace('][', '_', $original_meta))) . '-' . $i . '" class="awm-repeater-content ' . $class . '" data-counter="' . $i . '" data-id="' . $original_meta_id . '"><div class="awm-repeater-inputs">';
    $new_metas = array();
    $action = '';
    foreach ($a['include'] as $key => $data) {
        $data['attributes'] = (isset($data['attributes']) ? $data['attributes'] : array()) + (isset($a['attributes']) ? $a['attributes'] : array());
        $inputname = $original_meta . '[' . $i . '][' . $key . ']';
        if (isset($val[$i][$key])) {
            $data['attributes']['value'] = awm_repeater_check_quotes($val[$i][$key]);
        }
        $data['attributes']['exclude_meta'] = true;
        $data['attributes']['id'] = str_replace(']', '_', str_replace('[', '_', $original_meta)) . '_' . $i . '_' . $key;
        $data['attributes']['input-name'] = $original_meta;
        $data['attributes']['input-key'] = $key;
        if (!is_int($i) && $i == 'template') {
            $data['attributes']['disabled'] = true;
            $data['attributes']['awm-template'] = true;
            $data['attributes']['readonly'] = true;
            $data['disabled'] = true;
        }

        $new_metas[$inputname] = $data;
    }
    if (isset($a['row_title']) && is_int($i)) {
        $normal = $i + 1;
        $html .= '<div class="row-title">' . sprintf($a['row_title'], $normal) . '</div>';
    }
    $html .= awm_show_content($new_metas);
    $item = isset($a['item_name']) ? $a['item_name'] : __('Row', 'extend-wp');
    $html .= '</div>';
    if (!isset($a['hide_buttons'])) {
        $action = '<div class="awm-repeater-clone" onclick="ewp_repeater_clone_row(this)"><span class="awm_action ">' . __('Clone', 'extend-wp') . '</span></div><div class="awm-repeater-move-up" onclick="awm_repeater_order(this,true)"><span class="awm_action ">' . __('Move up', 'extend-wp') . '</span></div><div class="awm-repeater-move-down" onclick="awm_repeater_order(this,false)"><span class="awm_action ">' . __('Move down', 'extend-wp') . '</span></div><div class="awm-repeater-remove"><span class="awm_action awm-remove" onclick="repeater(this)">' . __('Remove', 'extend-wp') . ' ' . $item . '</span></div>';
        if (!is_int($i) && $i == 'template') {
            $action .= '<div class="awm-repeater-add" data-id="' . $original_meta_id . '"><span class="awm_action awm-add" onclick="repeater(this)">' . __('Add', 'extend-wp') . ' ' . $item . '</span></div>';
        }
        $html .= '<div class="awm-actions">' . $action . '</div>';
    }

    $html .= '</div>';
    return $html;
    /*repeater content end*/
}


function awm_save_custom_meta($data, $dataa, $id, $view = 'post', $postType = '')
{
    if (isset($data) && !empty($data)) {
        awm_custom_meta_update_vars($data, $dataa, $id, $view);
        /*check for translation */
        do_action('awm_custom_meta_update_action', $data, $dataa, $id, $view, $postType);
        awm_auto_translate($data, $dataa, $id, $view);
    }
}

if (!function_exists('awm_find_tranlsatable_fields')) {
    /**
     * with this function we look for auto translatable fields
     * @param string $metabox the metabox id
     * @param string $case the case
     * 
     * @return an array with all the keys
     */
    function awm_find_tranlsatable_fields($metabox, $case)
    {
        $autoTranslate = array();


        $metaboxData = awm_get_metabox_info($metabox, $case);

        if (isset($metaboxData['auto_translate']) && $metaboxData['auto_translate']) {
            $autoTranslate = array_keys($metaboxData['library']);
        }
        if (empty($autoTranslate)) {
            foreach ($metaboxData['library'] as $field => $field_data) {
                if (isset($field_data['auto_translate'])) {
                    $autoTranslate[] = $field;
                }
            }
        }
        return apply_filters('awm_auto_translate_fields_filter', $autoTranslate, $case, $metabox);
    }
}

function awm_auto_translate($data, $dataa, $id, $view)
{
    /*wpml check*/
    $autoTranslate = array();

    if (isset($dataa['awm_metabox']) && !empty($dataa['awm_metabox'])) {
        foreach ($dataa['awm_metabox'] as $metabox) {
            $autoTranslate = array_unique(array_merge($autoTranslate, awm_find_tranlsatable_fields($metabox, $dataa['awm_metabox_case'])));
        }
        if (!empty($autoTranslate)) {
            if (function_exists('icl_object_id')) {
                global $sitepress;
                $ids = awm_translated_ids($id, $dataa['awm_metabox_case']);
                if (!empty($ids) && isset($ids['original']) && isset($ids['translations'])) {
                    foreach ($autoTranslate as $key) {
                        $value = '';
                        switch ($dataa['awm_metabox_case']) {
                            case 'post':
                                $value = ($id == $ids['original'] && isset($dataa[$key])) ? $dataa[$key] : get_post_meta($ids['original'], $key, true);
                                break;
                            case 'term':
                                $value = ($id == $ids['original'] && isset($dataa[$key])) ? $dataa[$key] : get_term_meta($ids['original'], $key, true);
                                break;
                        }
                        foreach ($ids['translations'] as $trans) {
                            switch ($dataa['awm_metabox_case']) {
                                case 'post':
                                    if (empty($value)) {
                                        delete_post_meta($trans, $key);
                                        break;
                                    }
                                    update_post_meta($trans, $key, $value);
                                    break;
                                case 'term':
                                    if (empty($value)) {
                                        delete_term_meta($trans, $key);
                                        break;
                                    }

                                    update_term_meta($trans, $key, $value);
                                    break;
                            }
                        }
                    }
                }
            }
        }
    }
    return;
}


function awm_custom_meta_update_vars($meta, $metaa, $id, $view)
{
    foreach ($meta as $k) {
        $chk = '';

        if (strpos($k, '[') !== false) {
            $keys = explode('[', $k);
            $ref = &$metaa;
            $ref2 = &$arr;
            $count = 0;
            while ($key = array_shift($keys)) {
                if ($count == 0) {
                    $k = $key;
                }
                $key = str_replace(']', '', $key);
                $ref = &$ref[$key];
                $ref2 = &$ref2[$key];
                ++$count;
            }
            $ref2 = $ref;
            $val = $arr[$k];
        } else {
            if (isset($metaa[$k])) {
                $chk = $metaa[$k];
            }
            $val = isset($chk) ? $chk : '';
            $arr[$k] = $val;
        }

        switch ($view) {
            case 'user':
                /*update user meta*/
                if (!empty($val)) {
                    update_user_meta($id, $k, $val);
                } else {
                    delete_user_meta($id, $k);
                }
                break;
            case 'term':
                /*update user meta*/
                if (!empty($val)) {
                    update_term_meta($id, $k, $val);
                } else {
                    delete_term_meta($id, $k);
                }
                break;
            default:
                /* update post type*/
                if (!empty($val)) {
                    update_post_meta($id, $k, $val);
                } else {
                    delete_post_meta($id, $k);
                }
                break;
        }
    }
    return $arr;
}


function awm_custom_image_image_uploader_field($name, $id, $value = '', $multiple = false, $required = '')
{
    $image = ' button">' .  __('Insert media', 'extend-wp');

    $display = 'none'; // display state ot the "Remove image" button
    if ($value && !empty($value) && get_attached_file($value)) {
        /* check file type*/
        $file_type = wp_check_filetype($value);
        $show_image = false;
        $after = '';
        $default_image = site_url() . '/wp-includes/images/media/document.png';
        switch ($file_type['type']) {
            case 'zip':
                $show_image = site_url() . '/wp-includes/images/media/archive.svg';
                break;
            default:
                $show_image = wp_get_attachment_thumb_url($value);
                break;
        }
        if (!$show_image) {
            $show_image = $default_image;
            $after = '<br><small>' . get_attached_file($value) . '</small>';
        }
        $image = '" data-image="' . $value . '"><img src="' . $show_image . '"/>' . $after;
        $display = 'block';
    }
    $content = '<div class="awm-image-upload" id="awm_image' . $id . '"data-multiple="' . $multiple . '" data-add_label="' . __('Insert media', 'extend-wp') . '" data-remove_label="' . __('Remove media', 'extend-wp') . '">';

    $inner_content = '<a href="#" class="awm_custom_image_upload_image_button' . $image . '</a>
		<input type="hidden" name="' . $name . '" id="' . $id . '" value="' . $value . '" ' . $required . '/>
		<a href="#" class="awm_custom_image_remove_image_button" style="display:inline-block;display:' . $display . '">' . __('Remove media', 'extend-wp') . '</a>';

    $content .= apply_filters('awm_custom_image_image_uploader_field_filter', $inner_content, $name, $id, $value, $multiple, $required);
    $content .= '</div>';
    return $content;
}




if (!function_exists('awm_translated_ids')) {
    /**
     * get translations ids based on wpml code
     * @param  int $content_id the post_id,$term_id
     * @param string $case the cse to use
     */
    function awm_translated_ids($post_id, $case)
    {
        global $sitepress;
        $default = $sitepress->get_default_language();
        $languages = array_keys(apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc'));
        $type = '';
        switch ($case) {
            case 'post':
                $type = get_post_type($post_id);
                break;
            case 'term':
                $term = get_term($post_id);
                if ($term && !is_wp_error($term)) {
                    $type = $term->taxonomy;
                }
                break;
        }
        foreach ($languages as $language) {
            $tran_id = (int) apply_filters('wpml_object_id', $post_id, $type, true, $language);
            if ($language == $default) {
                $ids['original'] = $tran_id;
                continue;
            }
            $ids['translations'][] = $tran_id;
        }

        return apply_filters('awm_translated_ids_filter', $ids);
    }
}

function awm_display_meta_value($meta, $data, $postId = 0, $external_value = false, $case = 'post_type')
{
    global $awm_post_id;
    $awm_post_id = $postId;
    $case = $external_value ? 'external' : $case;
    switch ($case) {
        case 'post_type':
            $value = get_post_meta($postId, $meta, true) ?: false;
            break;
        case 'term':
            $value = get_term_meta($postId, $meta, true) ?: false;
            break;
        case 'user':
            $value = get_user_meta($postId, $meta, true) ?: false;
            break;
        case 'external':
            $value = $external_value;
            break;
    }
    $original_value = $value;
    $case = isset($data['admin_list_view']) ? $data['admin_list_view'] : $data['case'];
    switch ($case) {
        case 'ewp_content':
            $object = awm_get_db_content($data['content_type'], array('include' => $value));
            if (!$object || empty($object)) {
                break;
            }
            $object = $object[0];
            $value = $object['content_title'];
            break;
        case 'repeater':
            if (isset($data['include']) && !empty($data['include'])) {
                $finalShow = array();
                if (empty($value)) {
                    break;
                }
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
                    $finalShow[] = implode(' | ', $row_val);
                }
                $value = implode('<br>', $finalShow);
            }
            break;
        case 'input':
            switch ($data['type']) {
                case 'checkbox':
                    $value = $value ? __('Yes', 'extend-wp') : __('No', 'extend-wp');
                    break;
                case 'url':
                    $value = $value != '' ? '<a href="' . $value . '" target="_blank">' . $value . '</a>' : '';
                    break;
                default:
                    break;
            }

            break;
        case 'term':
            $term = get_term($value, $data['taxonomy']);
            if ($term) {
                $value = $term->name;
            }
            break;
        case 'post_types':
            $values = !is_array($value) ? array($value) : $value;
            $msg = array();
            foreach ($values as $value) {
                $post_type = get_post_types(array('name' => $value), 'objects');
                $msg[] = $post_type[$value]->label;
            }
            $value = implode(', ', $msg);
            break;
        case 'user':
            $user = get_user_by('id', $value);
            if ($user && !is_wp_error($user)) {
                /*get edit link also*/
                $value = '<a href="' . admin_url('user-edit.php?user_id=' . $value) . '" target="_blank">' . $user->display_name . '</a>';
            }
            break;
        case 'postType':
            $values = empty($value) ? array() : (!is_array($value) ? array($value) : $value);
            $msg = array();
            if (!empty($values)) {
                foreach ($values as $pvalue) {
                    $msg[] = $pvalue != '' ? '<a href="' . admin_url('post.php?action=edit&post=' . $pvalue) . '" target="_blank">' . get_the_title($pvalue) . '</a>' : '-';
                }
            }
            $value = implode(",", $msg);
            break;
        case 'message':
        case 'html':
            $value = isset($data['value']) ? $data['value'] : '';
            if (isset($data['strip'])) {
                return $value;
            }
            break;
        case 'function':
            if (isset($data['callback']) && function_exists($data['callback'])) {
                $value = call_user_func_array($data['callback'], array($postId));
            }
            break;
        case 'select':
        case 'checkbox_multiple':
            $values = is_array($value) ? $value : array($value);
            $finalShow = array();
            if (isset($data['callback'])) {
                $callback_options = array();
                if (!empty($data['callback_variables'])) {
                    $callback_options = call_user_func_array($data['callback'], $data['callback_variables']);
                }
                $callback_results = empty($callback_options) ? call_user_func($data['callback']) : $callback_options;
                $data['options'] = $callback_results;
                if (isset($callback_results['optgroups'])) {
                    $data['optgroups'] = $callback_results['optgroups'];
                    $data['options'] = $callback_results['options'];
                }
            }
            foreach ($values as $value) {
                if (!empty($value) && array_key_exists($value, $data['options'])) {
                    $finalShow[] = $data['options'][$value]['label'];
                }
            }
            $value = implode('<br>', $finalShow);
            break;
    }

    return apply_filters('awm_display_meta_value_filter', $value, $meta, $original_value, $data, $postId);
}



/**
 * this funciton is used to creat a form for the fields we add
 * @param array $data all the data needed
 */
function awm_create_form($options)
{

    $defaults = array(
        'library' => '',
        'id' => '',
        'method' => 'post',
        'action' => '',
        'submit' => true,
        'submit_label' => __('Register', 'awm'),
        'nonce' => true
    );

    $settings = array_merge($defaults, $options);
    $library = $settings['library'];

    ob_start();
?>
<form id="<?php echo $settings['id']; ?>" action="<?php echo $settings['action']; ?>" method="<?php echo $post; ?>">
 <?php
        if ($settings['nonce']) {
            wp_nonce_field($settings['id'], 'awm_form_nonce_field');
        }
        ?>
 <?php echo awm_show_content($library); ?>
 <?php if ($settings['submit']) {
        ?>
 <input type="submit" id="awm-submit-<?php echo $settings['id'] ?>" value="<?php echo $settings['submit_label']; ?>" />
 <?php
        }
        ?>
</form>
<?php
    $content = ob_get_contents();
    ob_end_clean();
    return $content;
}



if (!function_exists('awm_callback_library')) {
    /**
     * get all library data
     * @param array $library the data to display
     * @param string $id the id of the library
     */
    function awm_callback_library($library, $id)
    {
        return apply_filters('awm_show_content_fields_filter', $library, $id);
    }
}


if (!function_exists('awm_callback_library_options')) {
    /**
     * return options library
     * @param array $library the library either for the metas or the options
     */
    function awm_callback_library_options($library)
    {
        if (isset($library['library'])) {
            return $library['library'];
        }
        if (isset($library['options']) && !empty($library['options'])) {
            return $library['options'];
        }

        if (!isset($library['library'])) {

            if (isset($library['callback'])) {
                if (!((is_string($library['callback']) && function_exists($library['callback'])) || (is_array($library['callback']) && method_exists($library['callback'][0], $library['callback'][1])))) {
                    return '';
                }
                $callback_options = array();
                if (!empty($library['callback_variables'])) {
                    $callback_options = call_user_func_array($library['callback'], $library['callback_variables']);
                }
                $library = empty($callback_options) ? call_user_func($library['callback']) : $callback_options;
                return $library;
            }
        }
        return '';
    }
}


function awm_gallery_meta_box_html($meta, $val)
{
    // HTML for the gallery meta box
    $content = '<div class="awm-gallery-container" id="' . $meta . '-gallery" data-id="' . $meta . '">';
    $content .= '<ul class="awm-gallery-images-list">';
    if (!empty($val)) {
        foreach ($val as $image_id) {
            if ($image_id && !empty($image_id) && get_attached_file($image_id)) {
                $image = wp_get_attachment_thumb_url($image_id);

                if (!$image) {
                    $image = home_url() . '/wp-includes/images/media/document.png';
                }


                $content .= '<li class="awm-gallery-image" data-image-id="' . $image_id . '"><div class="awm-img-wrapper">';
                $content .= '<img src="' . esc_url($image) . '"></div>';
                $content .= '<a href="#" class="awm-remove-image">Remove</a>';
                $content .= '<input type="hidden" name="' . $meta . '[]" value="' . $image_id . '">';
                $content .= '</li>';
            }
        }
    }
    // If there are images, list them here
    $content .= '</ul>';
    $content .= '<button id="' . $meta . '-button"class="button awm-upload-button" data-id="' . $meta . '">' . __('Add', 'extend-wp') . '</button>';
    // Hidden field to store the IDs of the gallery images
    $content .= '</div>';
    return $content;
}


function ewp_rest_check_user_is_admin()
{
    return current_user_can('manage_options');
}

function awm_repeater_check_quotes($value)
{
    if (is_array($value)) {
        foreach ($value as $key => &$val) {
            $val = awm_repeater_check_quotes($val);
        }
        return $value;
    }
    return str_replace('"', '&quot;', $value);
}