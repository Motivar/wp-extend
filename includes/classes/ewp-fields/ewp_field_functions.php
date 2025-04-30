<?php
if (!defined('ABSPATH')) {
  exit;
}


if (!function_exists('awm_get_library_values')) {
  /**
   * get values for a library of metas/options
   * @param int|string $post_id, the post id of the awm-library either the callback for all the metas
   * @param strings $case post/term/user/option
   * 
   * @return array an array with keys as meta_keys and values as meta_values
   */

  function awm_get_library_values($post_id, $case = 'post', $content_id = '')
  {

    $dynamic_library = is_int($post_id);
    $fields = $return = array();

    switch ($dynamic_library) {
      case true:
        $registered_fields = awm_get_db_content_meta('ewp_fields', $post_id, 'awm_fields') ?: array();
        $fields = awm_basic_meta_structure($registered_fields);
        break;
      default:
        if (function_exists($post_id)) {
          $fields = call_user_func($post_id);
        }
        break;
    }
    if (!empty($fields)) {
      $fields = array_keys($fields);
      foreach ($fields as $field) {
        switch ($case) {
          case 'post':
            $return[$field] = get_post_meta($content_id, $field, true);
            break;
          case 'user':
            $return[$field] = get_user_meta($content_id, $field, true);
            break;
          case 'term':
            $return[$field] = get_term_meta($content_id, $field, true);
            break;
          default:
            $return[$field] = get_option($field);
            break;
        }
      }
    }

    return $return;
  }
}



if (!function_exists('awm_basic_meta_structure')) {
  /**
   * construct the basis structure for the metas
   * @param array $fields the array with the fields
   * 
   * @return array $the fields constructed
   */
  function awm_basic_meta_structure($fields)
  {
    $metas = array();

    foreach ($fields as $field) {
      $meta_key = awm_clean_string($field['key']);
      $metas[$meta_key] = $field;
      $metas[$meta_key]['label'] = __($field['label'], 'extend-wp');

      $metas[$meta_key]['class'] = !empty($field['class']) ? explode(',', $field['class']) : array();
      $attributes = array();
      if (isset($field['attributes'])) {
        foreach ($field['attributes'] as $attribute) {
          if (!empty($attribute['label']) && !empty($attribute['value'])) {
            $attributes[$attribute['label']] = $attribute['value'];
          }
        }
      }
      $metas[$meta_key]['attributes'] = $attributes;
      switch ($field['case']) {
        case 'select':
        case 'radio':
          $metas[$meta_key]['options'] = array();
          if (!empty($field['options'])) {
            foreach ($field['options'] as $option) {
              $metas[$meta_key]['options'][$option['option']] = array('label' => __($option['label'], 'extend-wp'));
            }
          }
          break;
      }
    }

    return $metas;
  }
}


if (!function_exists('awm_create_library')) {

  /**
   * with this function we manipulate the date we want
   * @param object $al related data to post/fields/position & type
   */
  function awm_create_library($awm_field)
  {

    $fields = $awm_field['fields'];

    $metas = awm_basic_meta_structure($fields);

    switch ($awm_field['type']) {
      case 'repeater':
      case 'section':
        $include = $metas;
        $metas = array();
        $repeater_fields = array_keys(awm_fields_usages());
        $repeater_data = array();
        $repeater_meta_data = awm_get_db_content_meta('ewp_fields', $awm_field['content_id']);
        foreach ($repeater_fields as $meta_key) {
          $field_key = str_replace($awm_field['type'] . '_', '', $meta_key);
          $repeater_data[$field_key] = isset($repeater_meta_data[$meta_key]) ? $repeater_meta_data[$meta_key] : false;
        }
        if ($repeater_data['key']) {

          $meta_key = awm_clean_string($repeater_data['key']);
          $metas[$meta_key]['label'] = __($repeater_data['label'], 'extend-wp');
          $metas[$meta_key] = $repeater_data;
          $metas[$meta_key]['include'] = $include;
          $metas[$meta_key]['case'] = $awm_field['type'];
          $metas[$meta_key]['class'] = !empty($repeater_data['class']) ? explode(',', $repeater_data['class']) : array();
          $attributes = array();
          if (isset($repeater_data['item']) && !empty($repeater_data['item'])) {
            $metas[$meta_key]['item_name'] = $repeater_data['item'];
          }
          if (isset($repeater_data['attributes']) && !empty($repeater_data['attributes'])) {
            foreach ($repeater_data['attributes'] as $attribute) {
              if (!empty($attribute['label']) && !empty($attribute['value'])) {
                $attributes[$attribute['label']] = $attribute['value'];
              }
            }
          }
          $metas[$meta_key]['attributes'] = $attributes;
        }
        break;
    }
    return apply_filters('awm_create_library_filter', $metas, $awm_field['content_id']);
  }
}


if (!function_exists('awm_get_fields')) {
  /**
   * with this function we get the fields depending on the case we want
   * @param string $case the case
   * @param string $type which type to use
   * @param int $id the post id to search for
   */
  function awm_get_fields($case = '', $type = '', $id = '')
  {
    $final_fields = array();
    $transient_key = 'awm_get_fields_' . $case . '_' . $type . '_' . $id;
    
    $cached = awm_get_transient($transient_key);
    if ($cached !== false) {
      return $cached;
    }
    $args = array(
      'status' => array('enabled'),
      'limit' => -1,
      'meta_query' => array(
        'relation' => 'AND',
        array(
          'key' => 'awm_positions',
          'value' => '%' . $case . '%',
          'compare' => 'LIKE'
        )
      )
    );
    if (!empty($type)) {
      $args['meta_query'][] = array(
        'key' => 'awm_positions',
        'value' => '%' . $type . '%',
        'compare' => 'LIKE'
      );
    }
    if (!empty($id)) {
      $args['limit'] = 1;
      $args['include'] = array($id);
    }
    $awm_fields = awm_get_db_content('ewp_fields', $args);
  
    if (!empty($awm_fields)) {
      $counter = 0;
      foreach ($awm_fields as  $awm_field) {
        $field_meta = awm_get_db_content_meta('ewp_fields', $awm_field['content_id']);
        $fields = $field_meta['awm_fields'] ?: array();
        $positions = $field_meta['awm_positions'] ?: array();
        $awm_type = $field_meta['awm_type'] ?: array();
        $awm_explanation = $field_meta['awm_explanation'] ?: '';

        if (empty($positions)) {
          continue;
        }
        foreach ($positions as $position) {
          if ($position['case'] == $case) {
            $final_fields[$awm_field['content_id'] . '_' . $counter] = $awm_field;
            $final_fields[$awm_field['content_id'] . '_' . $counter]['fields'] = $fields;
            $final_fields[$awm_field['content_id'] . '_' . $counter]['position'] = $position;
            $final_fields[$awm_field['content_id'] . '_' . $counter]['type'] = $awm_type;
            $final_fields[$awm_field['content_id'] . '_' . $counter]['explanation'] = $awm_explanation;
            $counter++;
          }
        }
      }
    }
    awm_set_transient($transient_key, $final_fields, 0, 36, 'awm_post_fields_transients');
    return $final_fields;
  }
}



if (!function_exists('awm_fields_configuration')) {
  /**
   * with this function we configure the inputs for thew awm field
   */
  function awm_fields_configuration()
  {
    $metas = array(
      'awm_fields' => array(
        'item_name' => __('Field', 'extend-wp'),
        'label' => __('Fields', 'extend-wp'),
        'case' => 'repeater',
        'include' => array(
          'key' => array(
            'label' => __('Meta key', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'label_class' => array('awm-needed'),
          ),
          'label' => array(
            'label' => __('Meta label', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'label_class' => array('awm-needed'),
          ),
          'case' => array(
            'label' => __('Field type', 'extend-wp'),
            'case' => 'select',
            'options' => awmInputFields(),
            'label_class' => array('awm-needed'),
            'attributes' => array('data-callback' => "awm_get_case_fields"),
          ),
          'case_extras' => array(
            'label' => __('Field type configuration', 'extend-wp'),
            'case' => 'html',
            'value' => '<div class="awm-field-type-configuration"></div>',
            'exclude_meta' => true,
            'show_label' => true
          ),
          'attributes' => array(
            'label' => __('Attributes', 'extend-wp'),
            'explanation' => __('like min=0 / onchange=action etc'),
            'minrows' => 0,
            'case' => 'repeater',
            'item_name' => __('Attribute', 'extend-wp'),
            'include' => array(
              'label' => array(
                'label' => __('Attribute label', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
              ),
              'value' => array(
                'label' => __('Attribute value', 'extend-wp'),
                'case' => 'input',
                'type' => 'text',
              ),
            )
          ),
          'not_visible_by' => array(
            'label' => __('Restrict visibility to roles', 'extend-wp'),
            'case' => 'user_roles',
            'exclude' => array('administrator'),
            'attributes' => array('multiple' => true),
            'explanation' => __('If you select roles here, the field will not be visible to them', 'extend-wp')
          ),
          'not_editable_by' => array(
            'label' => __('Restrict editing to roles', 'extend-wp'),
            'case' => 'user_roles',
            'exclude' => array('administrator'),
            'attributes' => array('multiple' => true),
            'explanation' => __('If you select roles here, the field will be not editable to them', 'extend-wp')
          ),

          'class' => array(
            'label' => __('Class', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'attributes' => array('placeholder' => __('Seperated with (,) comma', 'extend-wp')),
          ),
          'required' => array(
            'label' => __('Required', 'extend-wp'),
            'case' => 'input',
            'type' => 'checkbox',
          ),
          'admin_list' => array(
            'label' => __('Show in admin list', 'extend-wp'),
            'case' => 'input',
            'type' => 'checkbox',
          ),
          'auto_translate' => array(
            'label' => __('Auto translate', 'extend-wp'),
            'case' => 'input',
            'type' => 'checkbox',
          ),
          'order' => array(
            'label' => __('Order', 'extend-wp'),
            'case' => 'input',
            'type' => 'number',
          ),
          'explanation' => array(
            'label' => __('User message', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
            'attributes' => array('placeholder' => __('Guidelines for the user', 'extend-wp')),
          ),
        )
      )
    );
    return apply_filters('awm_fields_configuration_filter', $metas);
  }
}

if (!function_exists('awm_fields_usages')) {
  /**
   * with this we decide if they are going to be plain fields or in repeater etc
   */
  function awm_fields_usages()
  {
    return array(
      'awm_type' => array(
        'case' => 'select',
        'label' => __('Type of usage', 'extend-wp'),
        'removeEmpty' => true,
        'admin_list' => true,
        'options' => array(
          'simple_use' => array('label' => __('Simple inputs', 'extend-wp')),
          'repeater' => array(
            'label' => __('Repeater', 'extend-wp')
          ),
          'section' => array(
            'label' => __('Section', 'extend-wp')
          ),
        ),
      ),
      'section_key' => array(
        'show-when' => array('awm_type' => array('values' => array('section' => true))),
        'label' => __('Section Meta key', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
      ),
      'section_label' => array(
        'show-when' => array('awm_type' => array('values' => array('section' => true))),
        'label' => __('Section Meta label', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
      ),

      'repeater_key' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater Meta key', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
      ),
      'repeater_item' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater item', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
      ),
      'repeater_label' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater Meta label', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'label_class' => array('awm-needed'),
      ),
      'repeater_attributes' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater Attributes', 'extend-wp'),
        'case' => 'repeater',
        'item_name' => __('Attribute', 'extend-wp'),
        'include' => array(
          'label' => array(
            'label' => __('Attribute label', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
          ),
          'value' => array(
            'label' => __('Attribute value', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
          ),
        )
      ),
      'repeater_class' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater Class', 'extend-wp'),
        'case' => 'input',
        'type' => 'text',
        'attributes' => array('placeholder' => __('Seperated with (,) comma', 'extend-wp')),
      ),
      'repeater_order' => array(
        'show-when' => array('awm_type' => array('values' => array('repeater' => true))),
        'label' => __('Repeater Order', 'extend-wp'),
        'case' => 'input',
        'type' => 'number',
      ),
      'awm_explanation' => array(
        'case' => 'textarea',
        'label' => __('Input box explanation', 'extend-wp'),
      ),
    );
  }
}


if (!function_exists('awm_dev_notes')) {
  /**
   * with this we decide if they are going to be plain fields or in repeater etc
   */
  function awm_dev_notes()
  {
    return array(
      'html' => array(
        'case' => 'html',
        'label' => __('How to get variables', 'extend-wp'),
        'show_label' => true,
        'value' => '<div class="awm-dev-info"><div>' . __('Depending on your fields position choice, you can use the ordinary WordPress functions like get_post_meta, get_term_meta, get_user_meta, get_option with the <b>Meta Key</b> specified.') . '</div><div class="">' . __('If you want to get all the variables at once you can use the function <b>awm_get_library_values($awm_field_id,$case,$post_id)', 'extend-wp') . '</b>, where:<br></brr><strong>$awm_field_id</strong>=the post id of this screen<br><strong>$case</strong> = either post/term/user/option (depending on your fields position)<br><b>$post_id</b>= the id of the post/user/term you want to get the values for. </div></div>'
      ),
      'html2' => array(
        'case' => 'html',
        'label' => __('How to apply_filters in admin view', 'extend-wp'),
        'show_label' => true,
        'value' => '<div class="awm-dev-info"><div>' . __('You can use the filter \'awm_create_library_filter\', with variables:$metas, $awm_field_id where:<br><strong>$metas</strong>=then array with the field structure<br><strong>$awm_field_id</strong> =the post id of this screen</div></div>')
      )
    );
  }
}


if (!function_exists('awm_php_views')) {
  /**
   * with this function we configure the position of the inputs for the awm field
   */
  function awm_php_views()
  {
    global $ewp_content_id;
    if ($ewp_content_id) {
      $html = '<div id="awm-php-wrapper"><div><input type="button" value="' . __('Show php code', 'extend-wp') . '" onclick="ewp_get_php_code(' . $ewp_content_id . ')"/></div><div id="awm-php-code"></div></div>';

      return array(
        'position_content' => array(
          'label' => __('Php code', 'extend-wp'),
          'case' => 'html',
          'value' => $html,
          'exclude_meta' => true,
          'show_label' => true
        )
      );
    }

    return array();
  }
}

if (!function_exists('awm_print_php')) {
  function awm_print_php($fields)
  {
    $vals = array();
    foreach ($fields as $key => $item) {
      $display = is_int($key) ? '' : '\'' . $key . '\'=>';
      $value = '';

      if (!empty($item) && !is_array($item) && in_array($key, array('title', 'label', 'explanation'), true)) {
        $value = '__(\'' . $item . '\',\'extend-wp\')';
      }
      if (is_array($item)) {
        $value = ' array(' . awm_print_php($item) . ') ';
      }
      if (empty($value)) {
        $value = is_int($item) ? $item : '\'' . $item . '\'';
      }
      $vals[] = $display . $value;
    }
    return implode(',@@@@', $vals);
  }
}

if (!function_exists('awm_create_boxes')) {
  /**
   * with this function we create the boxes to add to the filters
   * @param string $case the case to use for
   * @param object $awm_fields the fields add to the boxes
   */
  function awm_create_boxes($case, $awm_fields)
  {
    if (empty($awm_fields) || empty($case)) {
      return array();
    }
    $boxes = array();
    foreach ($awm_fields as $id => $awm_field) {

      switch ($case) {
        case 'ewp_block':
          $version = strtotime($awm_field['modified']);
          $title = (isset($awm_field['content_title']) && !empty($awm_field['content_title'])) ? $awm_field['content_title'] : sprintf(__('Ewp Dynamic block %s', 'wp-extend'), $awm_field['content_id']);
          $namespace = (isset($awm_field['position']['namespace']) && !empty($awm_field['position']['namespace'])) ? awm_clean_string($awm_field['position']['namespace']) : 'ewp-block';
          $name = (isset($awm_field['position']['name']) && !empty($awm_field['position']['name'])) ? awm_clean_string($awm_field['position']['name']) : awm_clean_string($title);
          $dependencies = (isset($awm_field['position']['dependencies']) && !empty($awm_field['position']['dependencies'])) ? explode(',', $awm_field['position']['dependencies']) : array();
          $render_callback = (isset($awm_field['position']['render_callback']) && !empty($awm_field['position']['render_callback'])) ? $awm_field['position']['render_callback'] : '';
          $icon = (isset($awm_field['position']['icon']) && !empty($awm_field['position']['icon'])) ? $awm_field['position']['icon'] : '';
          $category = (isset($awm_field['position']['category']) && !empty($awm_field['position']['category'])) ? $awm_field['position']['category'] : 'common';
          $boxes[$id] = array(
            'namespace' => $namespace,
            'name' => $name,
            'title' => $title,
            'version' => $version,
            'dependencies' => $dependencies,
            'render_callback' => $render_callback,
            'attributes' => awm_create_library($awm_field),
            'category' => $category,
            'icon' => $icon
          );
          break;
        case 'customizer':
          $section = array(
            $id => array(
              'title' => __($awm_field['position']['title'], 'extend-wp'),
              'priority'   => $awm_field['position']['priority'] ?: 100,
              'capability' => $awm_field['position']['cap'] ?: 'edit_theme_options',
              'library' => awm_create_library($awm_field),
              'description' => $awm_field['explanation']
            )
          );
          if ($awm_field['position']['panel_id'] != '') {
            $panel_id = $awm_field['position']['panel_id'];
            $boxes[$panel_id]['sections'][$id] = $section[$id];
            break;
          }
          $boxes[$id] = array(
            'title' => __($awm_field['position']['panel_name'], 'extend-wp'),
            'priority' => 100,
            'sections' => $section
          );
          break;

        case 'content_types':
          $boxes[$id] = array(
            'title' => __($awm_field['content_title'], 'extend-wp'),
            'library' => awm_create_library($awm_field),
            'context' =>  isset($awm_field['position']['context']) ? $awm_field['position']['context'] : 'normal',
            'priority' =>  isset($awm_field['position']['priority'])  ? $awm_field['position']['priority'] : 'high',
            'explanation' => isset($awm_field['explanation']) ? $awm_field['explanation'] : '',
            'view' => isset($awm_field['position']['view']) ? $awm_field['position']['view'] : ''
          );
          break;  
        case 'post_type':
          $boxes[$id] = array(
            'title' => __($awm_field['content_title'], 'extend-wp'),
            'postTypes' => isset($awm_field['position']['post_types'])  ? $awm_field['position']['post_types'] : array(),
            'context' =>  isset($awm_field['position']['context']) ? $awm_field['position']['context'] : 'normal',
            'priority' =>  isset($awm_field['position']['priority'])  ? $awm_field['position']['priority'] : 100,
            'library' => awm_create_library($awm_field),
            'explanation' => isset($awm_field['explanation']) ? $awm_field['explanation'] : '',
          );
          break;
        case 'taxonomy':
          $boxes[$id] = array(
            'title' => __($awm_field['content_title'], 'extend-wp'),
            'taxonomies' => $awm_field['position']['taxonomies'],
            'library' => awm_create_library($awm_field),
            'explanation' => isset($awm_field['explanation']) ? $awm_field['explanation'] : '',
          );
          break;
        case 'options':
          $boxes['awm_option_' . $id] = array(
            'title' => isset($awm_field['position']['title']) ? __($awm_field['position']['title'], 'extend-wp') : $id,
            'library' => awm_create_library($awm_field),
            'parent' => isset($awm_field['position']['parent']) ? $awm_field['position']['parent'] : '',
            'hide_submit' => isset($awm_field['position']['hide_submit']) ? $awm_field['position']['hide_submit'] : false,
            'submit_label' => !empty($awm_field['position']['submit_label']) ? $awm_field['position']['submit_label'] : '',
            'cap' => isset($awm_field['position']['cap']) ? $awm_field['position']['cap'] : '',
            'disable_register' => isset($awm_field['position']['disable_register']) ? $awm_field['position']['disable_register'] : false,
            'explanation' => isset($awm_field['explanation']) ? $awm_field['explanation'] : '',
          );
          break;
        case 'user':
          $boxes[$id] = array(
            'title' => __($awm_field['content_title'], 'extend-wp'),
            'library' => awm_create_library($awm_field),
            'explanation' => isset($awm_field['explanation']) ? $awm_field['explanation'] : '',
          );
          break;
      }
    }
    return $boxes;
  }
}


if (!function_exists('awm_fields_positions')) {
  /**
   * with this function we configure the position of the inputs for the awm field
   */
  function awm_fields_positions()
  {
    $metas = array(
      'awm_positions' => array(
        'item_name' => __('Position', 'extend-wp'),
        'label' => __('Positions', 'extend-wp'),
        'case' => 'repeater',
        'admin_list' => true,
        'include' => array(
          'case' => array(
            'label' => __('Position', 'extend-wp'),
            'case' => 'select',
            'options' => awm_position_options(),
            'label_class' => array('awm-needed'),
            'attributes' => array('data-callback' => "awm_get_position_settings"),
            'admin_list' => true
          ),
          'position_content' => array(
            'label' => __('Position configuration', 'extend-wp'),
            'case' => 'html',
            'value' => '<div class="awm-position-configuration"></div>',
            'exclude_meta' => true,
            'show_label' => true
          )
        )
      )
      /**/
    );
    return apply_filters('awm_fields_position_filter', $metas);
  }
}

if (!function_exists('awm_position_options')) {
  function awm_position_options()
  {
    return apply_filters('awm_position_options_filter', array(
      'post_type' => array('label' => __('Post Type', 'extend-wp'), 'field-choices' =>
      array(
        'post_types' => array(
          'label' => __('Post types', 'extend-wp'),
          'case' => 'post_types',
          'attributes' => array('multiple' => true),
          'label_class' => array('awm-needed'),
        ),
        'context' => array(
          'label' => __('Context', 'extend-wp'),
          'case' => 'select',
          'removeEmpty' => true,
          'options' => array(
            'normal' => array('label' => __('Normal', 'extend-wp')),
            'side' => array('label' => __('Side', 'extend-wp')),
          )
        ),
        'priority' => array(
          'label' => __('Priority', 'extend-wp'),
          'removeEmpty' => true,
          'case' => 'select',
          'options' => array(
            'high' => array('label' => __('High', 'extend-wp')),
            'low' => array('label' => __('Low', 'extend-wp')),
          )
        ),
      )),
      'taxonomy' => array('label' => __('Taxonomy', 'extend-wp'), 'field-choices' =>
      array(
        'taxonomies' => array(
          'label' => __('Taxonomies', 'extend-wp'),
          'case' => 'taxonomies',
          'attributes' => array('multiple' => true),
          'label_class' => array('awm-needed'),
        ),
      )),
      'user' => array('label' => __('User profile', 'extend-wp')),
      'options' => array('label' => __('New options page', 'extend-wp'), 'field-choices' =>
      array(
        'title' => array(
          'label' => __('Title', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
          'label_class' => array('awm-needed'),
        ),
        'parent' => array(
          'label' => __('Position <small>(leave empty for dedicated page)</small>', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
        ),
        'cap' => array(
          'label' => __('User cap', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
        ),
        'hide_submit' => array(
          'label' => __('Hide submit', 'extend-wp'),
          'case' => 'input',
          'type' => 'checkbox',
        ),
        'submit_label' => array(
          'label' => __('Submit label', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
        ),
        'disable_register' => array(
          'label' => __('Disable register settings', 'extend-wp'),
          'case' => 'input',
          'type' => 'checkbox',
        ),
      )),
      'customizer' => array('label' => __('Customizer pages', 'extend-wp'), 'field-choices' =>
      array(
        'title' => array(
          'label' => __('Title', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
          'label_class' => array('awm-needed'),
        ),
        'priority' => array(
          'label' => __('Priority', 'extend-wp'),
          'case' => 'input',
          'type' => 'number',
        ),
        'cap' => array(
          'label' => __('User cap', 'extend-wp'),
          'case' => 'input',
          'type' => 'text',
          'label_class' => array('awm-needed'),
        ),
        'panel_id' => array(
          'case' => 'input',
          'type' => 'text',
          'label' => __('The panel id to add section (leave empty for new panel)', 'extend-wp'),
        ),
        'panel_name' => array(
          'case' => 'input',
          'type' => 'text',
          'label' => __('The panel name to show if no panel_id is filled in', 'extend-wp'),
        ),

      )),
      'existing_awm_fields' => array('label' => __('Existing meta fields', 'filox'), 'field-choices' => array(
        'page_ids' => array(
          'label' => __('Select meta libraries', 'filox'),
          'case' => 'select',
          'attibutes' => array('multiple' => 1),
          'callback' => 'all_awm_meta_libraries',
          'label_class' => array('awm-needed'),
          )
        )),
        'content_types' => array(
          'label' => __('Content types', 'extend-wp'),
          'field-choices' => array(
            'ewp_content_types' => array(
              'label' => __('Content types', 'extend-wp'),
              'case' => 'ewp_content_types',
              'attributes' => array('multiple' => true),
              'label_class' => array('awm-needed'),
            ),
            'context' => array(
              'label' => __('Context', 'extend-wp'),
              'case' => 'select',
              'removeEmpty' => true,
              'options' => array(
                'normal' => array('label' => __('Normal', 'extend-wp')),
                'side' => array('label' => __('Side', 'extend-wp')),
              )
            ),
            'priority' => array(
              'label' => __('Priority', 'extend-wp'),
              'removeEmpty' => true,
              'case' => 'select',
              'options' => array(
                'high' => array('label' => __('High', 'extend-wp')),
                'low' => array('label' => __('Low', 'extend-wp')),
              )
            ),
          )
        )
      )
    );
  }
}


if (!function_exists('all_awm_meta_libraries')) {
  /**
   * function to get all existsing php registered meta libraries
   */
  function all_awm_meta_libraries()
  {
    $metaBoxes = $options = array();
    $metas = new AWM_Meta();
    $metaBoxes['metas'] = $metas->meta_boxes();
    $metaBoxes['term'] = $metas->term_meta_boxes();
    $metaBoxes['options'] = $metas->options_boxes();
    $metaBoxes['user'] = $metas->user_boxes();
    if (!empty($metaBoxes)) {
      foreach ($metaBoxes as $key => $data) {
        $afterfix = __('Post type metabox', 'extend-wp');
        switch ($key) {
          case 'user':
            $afterfix = __('User metabox', 'extend-wp');
            break;
          case 'term':
            $afterfix = __('Taxonomy metabox', 'extend-wp');
            break;
          case 'options':
            $afterfix = __('Option metabox', 'extend-wp');
            break;
        }
        foreach ($data as $meta_key => $meta_data) {
          $name = $meta_data['title'] . ' - ' . $afterfix;;
          $options[$meta_key] = array('label' => $name);
        }
      }
    }
    return $options;
  }
}


if (!function_exists('awmInputFields')) {
  function awmInputFields()
  {
    /**
     * function to show the fields available for user to choose and create a form
     */

    return apply_filters('awmInputFields_filter', array(
      'input' => array('label' => __('Input', 'extend-wp'), 'field-choices' => array(
        'type' => array(
          'label' => __('Input type', 'extend-wp'),
          'case' => 'select',
          'options' => awmInputFieldsTypes(),
          'label_class' => array('awm-needed'),
        ),
      )),
      'select' => array('label' => __('Select', 'extend-wp'), 'field-choices' => awm_select_options()),
      'textarea' => array('label' => __('Textarea', 'extend-wp')),
      'image' => array('label' => __('Image', 'extend-wp')),
      'awm_gallery' => array('label' => __('Gallery', 'extend-wp')),
      'radio' => array('label' => __('Radio', 'extend-wp'), 'field-choices' => awm_select_options()),
      'checkbox_multiple' => array('label' => __('Multiple checkbox', 'extend-wp'), 'field-choices' => awm_select_options()),
      'date' => array('label' => __('Date', 'extend-wp')),
      'map' => array('label' => __('Map', 'extend-wp')),
      'postType' => array('label' => __('Post type(s) Content', 'extend-wp'), 'field-choices' => awm_post_type_options()),
      'post_types' => array('label' => __('Post types object', 'extend-wp')),
      'term' => array('label' => __('Taxonomies', 'extend-wp'), 'field-choices' => awm_term_options()),
      'taxonomies' => array('label' => __('Taxonomies object', 'extend-wp')),
      'user' => array('label' => __('Users', 'extend-wp'), 'field-choices' => array(
        'roles' => array(
          'label' => __('Role', 'extend-wp'),
          'case' => 'select',
          'callback' => 'awm_user_roles',
          'attributes' => array('multiple' => true),
          'label_class' => array('awm-needed'),
        ),
        'view' => array(
          'case' => 'select',
          'label' => __('View', 'extend-wp'),
          'removeEmpty' => true,
          'options' => array(
            'select' => array('label' => __('Select box', 'extend-wp')),
            'radio' => array('label' => __('Radio', 'extend-wp')),
            'checkbox_multiple' => array('label' => __('Multiple checkbox', 'extend-wp')),
          )
        ),
      )),
      'ewp_content' =>  array('label' => __('Ewp Content', 'extend-wp'), 'field-choices' => ewp_options()),
      'ewp_content_types' =>  array('label' => __('Ewp Content Types', 'extend-wp')),
      'function' => array('label' => __('PHP Function', 'extend-wp'), 'field-choices' => array(
        'callback' => array(
          'case' => 'input',
          'type' => 'text',
          'label' => __('PHP function', 'extend-wp'),
          'label_class' => array('awm-needed'),
        )
      )),

      'html' => array('label' => __('Html', 'extend-wp'), 'field-choices' => array(
        'value' => array(
          'case' => 'textarea',
          'label' => __('HTML Code', 'extend-wp'),
          'label_class' => array('awm-needed'),
        )
      )),
    ));
  }
}

if (!function_exists('awm_user_roles')) {
  function awm_user_roles()
  {
    global $wp_roles;
    $roles = $wp_roles->roles;
    $role_names = array();
    foreach ($roles as $role => $data) {
      $role_names[$role] = array('label' => $data['name']);
    }
    return $role_names;
  }
}


if (!function_exists('ewp_options')) {
  function ewp_options()
  {
    // Get the singleton instance.
    $db_content = AWM_Content_DB::get_instance();
    $options = $db_content->get_content_types();
    return array(
      'content_type' => array(
        'label' => __('Content Object', 'extend-wp'),
        'case' => 'select',
        'options' => $options,
        'label_class' => array('awm-needed'),
      ),
      'view' => array(
        'case' => 'select',
        'label' => __('View', 'extend-wp'),
        'removeEmpty' => true,
        'options' => array(
          'select' => array('label' => __('Select box', 'extend-wp')),
          'radio' => array('label' => __('Radio', 'extend-wp')),
          'checkbox_multiple' => array('label' => __('Multiple checkbox', 'extend-wp')),
        )
      ),
    );
  }
}





if (!function_exists('awm_term_options')) {
  function awm_term_options()
  {
    return array(
      'view' => array(
        'case' => 'select',
        'label' => __('View', 'extend-wp'),
        'removeEmpty' => true,
        'options' => array(
          'select' => array('label' => __('Select box', 'extend-wp')),
          'radio' => array('label' => __('Radio', 'extend-wp')),
          'checkbox_multiple' => array('label' => __('Multiple checkbox', 'extend-wp')),
        )
      ),
      'taxonomy' => array(
        'label' => __('Taxonomies', 'extend-wp'),
        'case' => 'taxonomies',
        'attributes' => array('multiple' => true),
        'label_class' => array('awm-needed'),
      ),
      'show_empty' => array(
        'label' => __('Show empty categories', 'extend-wp'),
        'case' => 'input',
        'type' => 'checkbox',
        'explanation' => __('Show empty categories', 'extend-wp'),
      ),
      'show_all' => array(
        'label' => __('Show all', 'extend-wp'),
        'case' => 'input',
        'type' => 'checkbox',
        'explanation' => __('Show the \'All\' option', 'extend-wp'),
      ),
    );
  }
}


if (!function_exists('awm_post_type_options')) {
  function awm_post_type_options()
  {
    return array(
      'view' => array(
        'case' => 'select',
        'label' => __('View', 'extend-wp'),
        'removeEmpty' => true,
        'options' => array(
          'select' => array('label' => __('Select box', 'extend-wp')),
          'radio' => array('label' => __('Radio', 'extend-wp')),
          'checkbox_multiple' => array('label' => __('Multiple checkbox', 'extend-wp')),
        )
      ),
      'post_type' => array(
        'label' => __('Post types', 'extend-wp'),
        'case' => 'post_types',
        'attributes' => array('multiple' => true),
        'label_class' => array('awm-needed'),
      )
    );
  }
}


if (!function_exists('awmInputFieldsTypes')) {
  /**
   * all the types for the input html awm field
   */

  function awmInputFieldsTypes()
  {
    return apply_filters('awmInputFieldsTypes_filter', array(
      'text' => array('label' => __('Text', 'extend-wp')),
      'email' => array('label' => __('Email', 'extend-wp')),
      'checkbox' => array('label' => __('Checkbox', 'extend-wp')),
      'url' => array('label' => __('Url', 'extend-wp')),
      'number' => array('label' => __('Number', 'extend-wp')),
      'file' => array('label' => __('File', 'extend-wp')),
      'color' => array('label' => __('Color', 'extend-wp')),
      'submit' => array('label' => __('Submit', 'extend-wp')),
      'hidden' => array('label' => __('Hidden', 'extend-wp')),
    ));
  }
}


if (!function_exists('awm_select_options')) {
  /**
   * the select options choices builder
   */
  function awm_select_options()
  {
    return array(
      'options' => array(
        'label' => __('Options', 'extend-wp'),
        'case' => 'repeater',
        'include' => array(
          'option' => array(
            'label' => __('Value', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
          ),
          'label' => array(
            'label' => __('Label', 'extend-wp'),
            'case' => 'input',
            'type' => 'text',
          ),
        ),
      ),
    );
  }
}