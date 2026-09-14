<?php
if (!defined('ABSPATH')) {
  exit;
}



/**
 * The wp-admin field-builder and modal-field helpers.
 *
 * A plain service: every method takes PHP values and returns the HTML
 * string, array or WP_Error the admin scripts expect. The
 * `extend-wp/v1/get-case-fields|get-query-fields|get-position-fields|
 * get-php-code|awm-map-options|modal-fields|modal-save` routes are
 * generated from EWP\Surfaces\Resources\Field_Builder_Resource, which
 * declares them REST-only (they render markup for the browser).
 *
 * @since 1.5.0 No longer a WP_REST_Controller; routes come from the resource.
 */
class AWM_API
{
  /**
   * Google Maps options for the admin map field.
   *
   * @return array `{key, lat, lng, map_options}` after `awm_map_options_func_filter`.
   */
  public function map_options()
  {
    $dev_settings = get_option('ewp_dev_settings') ?: array();
    $options = array(
      'key' => isset($dev_settings['google_maps_api_key']) ? $dev_settings['google_maps_api_key'] : '',
      'lat' => '39.0742',
      'lng' => '21.8243',
      'map_options' => array('zoom' => 12),
    );
    return apply_filters('awm_map_options_func_filter', $options);
  }

  /**
   * Highlighted PHP snippet that registers a UI-built field group in code.
   *
   * @param int $post_id ewp_fields row id.
   *
   * @return string HTML; empty when the row does not exist.
   */
  public function php_code($post_id)
  {
    $post_id = absint($post_id);
    if ($post_id < 1) {
      return '';
    }
    $code = array();
    $awm_field = awm_get_db_content('ewp_fields', array('include' => $post_id));
    if (empty($awm_field)) {
      return '';
    }
    $awm_field = $awm_field[0];
    $field_meta = awm_get_db_content_meta('ewp_fields', $awm_field['content_id']);
    $fields = $field_meta['awm_fields'] ?: array();
    $positions = $field_meta['awm_positions'] ?: array();
    $awm_type = $field_meta['awm_type'] ?: array();
    $awm_explanation = $field_meta['awm_explanation'] ?: '';
    $counter = 0;
    foreach ($positions as $position) {
      $final_fields = array();
      $final_fields[$awm_field['content_id'] . '_' . $counter] = $awm_field;
      $final_fields[$awm_field['content_id'] . '_' . $counter]['fields'] = $fields;
      $final_fields[$awm_field['content_id'] . '_' . $counter]['position'] = $position;
      $final_fields[$awm_field['content_id'] . '_' . $counter]['type'] = $awm_type;
      $final_fields[$awm_field['content_id'] . '_' . $counter]['explanation'] = $awm_explanation;
      $fields = awm_create_boxes($position['case'], $final_fields);
      $content = awm_print_php($fields);
   
      $filter = '';
      switch ($position['case']) {
        case 'post_type':
          $filter = 'awm_add_meta_boxes_filter';
          break;
        case 'ewp_block':
          $filter = 'ewp_gutenburg_blocks_filter';
          break;
        case 'taxonomy':
          $filter = 'awm_add_term_meta_boxes_filter';
          break;
        case 'customizer':
          $filter = 'awm_add_customizer_settings_filter';
          break;
        case 'options':
          $filter = 'awm_add_options_boxes_filter';
          break;
        case 'user':
          $filter = 'awm_add_user_boxes_filter';
          break;
      }
      $code[] = str_replace('@@@@', '<br>', highlight_string('<?php add_filter(\'' . $filter . '\',function($boxes){
            $boxes+=array(' . $content . ');
            return $boxes;
          }); 
        ?>', true));
      $counter++;
    }
    return implode('', $code);
  }

  /**
   * Settings markup for one position type of a field group.
   *
   * @param string $position Position type (a key of awm_position_options()).
   * @param string $name     Input name of the position row.
   * @param int    $id       ewp_fields row id.
   *
   * @return string HTML; empty when no position was given.
   */
  public function position_fields($position, $name, $id)
  {
    if (empty($position)) {
      return '';
    }
    return $this->get_awm_metas_configuration(
      sanitize_text_field($position),
      sanitize_text_field($name),
      'awm_positions',
      absint($id),
      awm_position_options(),
      'ewp_fields',
      'case'
    );
  }


  /**
   * Settings markup for one query type of a search filter.
   *
   * @param string $field Query type (a key of ewp_query_fields()).
   * @param string $name  Input name of the query row.
   * @param string $meta  Meta key holding the rows (query_fields).
   * @param int    $id    ewp_search row id.
   *
   * @return string HTML; empty when no field was given.
   */
  public function query_fields($field, $name, $meta, $id)
  {
    if (empty($field)) {
      return '';
    }
    return $this->get_awm_metas_configuration(
      sanitize_text_field($field),
      sanitize_text_field($name),
      sanitize_text_field($meta),
      absint($id),
      ewp_query_fields(),
      'ewp_search',
      'query_type'
    );
  }


  /**
   * Settings markup for one field case (input type) of a field group.
   *
   * @param string $field Field case (a key of awmInputFields()).
   * @param string $name  Input name of the field row.
   * @param string $meta  Meta key holding the rows (awm_fields or query_fields).
   * @param int    $id    Row id in ewp_fields (or ewp_search for query_fields).
   *
   * @return string HTML; empty when no field was given.
   */
  public function case_fields($field, $name, $meta, $id)
  {
    if (empty($field)) {
      return '';
    }
    $meta = sanitize_text_field($meta);
    $db = $meta === 'query_fields' ? 'ewp_search' : 'ewp_fields';
    return $this->get_awm_metas_configuration(sanitize_text_field($field), sanitize_text_field($name), $meta, absint($id), awmInputFields(), $db, 'case');
  }

  private function get_awm_metas_configuration($field, $name, $meta, $postId, $all_fields, $db, $replace)
  {
    $content = '';
    if (array_key_exists($field, $all_fields)) {
      if (isset($all_fields[$field]['field-choices']) && !empty($all_fields[$field]['field-choices'])) {
        $values = awm_get_db_content_meta($db, $postId, $meta) ?: array();
        $metas = array();
        foreach ($all_fields[$field]['field-choices'] as $id => $data) {
          $inputname = str_replace('[' . $replace . ']', '', $name) . '[' . $id . ']';
          $position = absint(str_replace('[' . $replace . ']', '', str_replace($meta . '[', '', str_replace(']', '', $name))));
          $metaId = str_replace(']', '_', str_replace('[', '_', $inputname));
          $data['attributes']['exclude_meta'] = true;
          $data['attributes']['id'] = $metaId;
          $data['attributes']['value'] = isset($values[$position][$id]) ? $values[$position][$id] : '';
          $metas[$inputname] = $data;
        }
      }
      $content = awm_show_content($metas, $postId);
    }
    return $content;
  }

  /**
   * Capability the map-options route requires.
   *
   * The route returns the configured Google Maps browser key, so it is
   * limited to logged-in users; the map field only renders in wp-admin.
   * Use the `ewp_map_options_public` filter if a site renders the map
   * field for anonymous visitors.
   *
   * @return bool `true` when the caller may read the options, `false` otherwise.
   *
   * @since 1.5.0
   */
  public function map_options_capability()
  {
    /**
     * Whether the map options route may answer anonymous requests.
     *
     * @param bool $public Default false.
     *
     * @since 1.5.0
     */
    return apply_filters('ewp_map_options_public', false) ? true : is_user_logged_in();
  }

  /**
   * Get modal fields HTML with current values
   *
   * Renders the modal field definitions with pre-populated values
   * based on the view type (post/term/user/option/content_meta).
   * Field definitions are looked up server-side from registered meta boxes/options.
   * Uses PHP template file for modal HTML structure.
   *
   * @param string $meta_key    Modal meta key (required).
   * @param string $view        View type: post|term|user|option|content_meta.
   * @param int    $object_id   Object id for post/term/user/content_meta views.
   * @param string $modal_title Modal header title.
   * @param string $modal_id    Modal identifier; defaults to the meta key.
   * @param string $option_page Option page key for a direct lookup (option view).
   *
   * @return array|WP_Error `{modal_html, fields_html, modal_title, current_value}`, or a 404 error.
   *
   * @since 1.2.0
   * @since 1.5.0 Takes plain values instead of a WP_REST_Request.
   */
  public function modal_fields($meta_key, $view = 'post', $object_id = 0, $modal_title = '', $modal_id = '', $option_page = '')
  {
    $meta_key = sanitize_key($meta_key);
    $view = sanitize_key($view) ?: 'post';
    $object_id = absint($object_id);
    $modal_title = sanitize_text_field($modal_title);
    $modal_id = sanitize_key($modal_id) ?: $meta_key;
    $option_page = sanitize_key($option_page);

    // Lookup field definitions server-side
    $fields = $this->lookup_modal_field_definition($meta_key, $view, $object_id, $option_page);

    if (!is_array($fields) || empty($fields)) {
      return new WP_Error(
        'ewp_modal_not_found',
        sprintf(__('Field definition not found for meta_key: %s', 'extend-wp'), $meta_key),
        array('status' => 404)
      );
    }

    $current_value = $this->get_modal_value($view, $object_id, $meta_key);
    $current_value = maybe_unserialize($current_value);
    $current_value = is_array($current_value) ? $current_value : array();

    $metas = array();
    foreach ($fields as $key => $data) {
      $inputname = $meta_key . '[' . $key . ']';
      $data['attributes'] = isset($data['attributes']) ? $data['attributes'] : array();
      $data['attributes']['id'] = $meta_key . '_' . $key;
      $data['attributes']['exclude_meta'] = true;

      if (isset($current_value[$key])) {
        $data['attributes']['value'] = $current_value[$key];
      }

      $metas[$inputname] = $data;
    }

    /**
     * Filter modal fields before rendering
     *
     * @param array $metas Field definitions with values
     * @param string $meta_key The modal meta key
     * @param string $view View type (post/term/user/option)
     * @param int $object_id Object ID
     * @param array $current_value Current stored values
     * @since 1.2.0
     */
    $metas = apply_filters('awm_modal_fields_rendered', $metas, $meta_key, $view, $object_id, $current_value);

    $fields_html = awm_show_content($metas, $object_id);

    $args = array(
      'meta_key' => $meta_key,
      'view' => $view,
      'object_id' => $object_id,
      'include' => $fields,
    );

    $modal_html = $this->render_modal_template($modal_id, $modal_title, $fields_html, $args);

    return array(
      'modal_html' => $modal_html,
      'fields_html' => $fields_html,
      'modal_title' => $modal_title,
      'current_value' => $current_value,
    );
  }

  /**
   * Render modal template using PHP template file
   *
   * @param string $modal_id Unique modal identifier
   * @param string $modal_title Modal header title
   * @param string $fields_html Rendered fields HTML
   * @param array $args Original field arguments
   * @return string Rendered modal HTML
   * @since 1.2.0
   */
  private function render_modal_template($modal_id, $modal_title, $fields_html, $args)
  {
    /**
     * Filter modal template path
     *
     * Allows developers to use a custom template file for the modal.
     *
     * @param string $template_path Default template path
     * @param string $modal_id Modal identifier
     * @param array $args Field arguments
     * @since 1.2.0
     */
    $template_path = apply_filters(
      'awm_modal_template_path',
      awm_path . 'templates/admin-view/modal-field.php',
      $modal_id,
      $args
    );

    if (!file_exists($template_path)) {
      return '<div class="notice notice-error">
 <p>' . esc_html__('Modal template not found', 'extend-wp') . '</p>
</div>';
    }

    ob_start();
    include $template_path;
    return ob_get_clean();
  }

  /**
   * Save modal field values
   *
   * Saves the serialized modal values to the appropriate storage
   * based on view type (post_meta/term_meta/user_meta/option/content_meta).
   *
   * @param string $meta_key  Modal meta key (required).
   * @param string $view      View type: post|term|user|option|content_meta.
   * @param int    $object_id Object id for post/term/user/content_meta views.
   * @param array  $values    Values to save, keyed by field.
   *
   * @return array|WP_Error `{success, message, values}`, or a 500 error with debug data.
   *
   * @since 1.2.0
   * @since 1.5.0 Takes plain values instead of a WP_REST_Request.
   */
  public function save_modal_fields($meta_key, $view = 'post', $object_id = 0, $values = array())
  {
    $meta_key = sanitize_key($meta_key);
    $view = sanitize_key($view) ?: 'post';
    $object_id = absint($object_id);
    $values = is_array($values) ? $values : array();

    /**
     * Action before saving modal values
     *
     * @param string $meta_key The modal meta key
     * @param string $view View type (post/term/user/option)
     * @param int $object_id Object ID
     * @param array $values Values to save
     * @since 1.2.0
     */
    do_action('awm_modal_before_save', $meta_key, $view, $object_id, $values);

    $sanitized_values = $this->sanitize_modal_values($values);
    $result = $this->save_modal_value($view, $object_id, $meta_key, $sanitized_values);

    if (!$result) {
      // Log detailed error information
      if (function_exists('ewp_log')) {
        ewp_log(
          'extend-wp',
          'modal_save_error',
          sprintf('Modal save failed for meta_key: %s', $meta_key),
          array(
            'meta_key' => $meta_key,
            'view' => $view,
            'object_id' => $object_id,
            'values_count' => count($sanitized_values),
          ),
          'developer',
          '',
          0
        );
      }

      return new WP_Error(
        'ewp_modal_save_failed',
        __('Failed to save data', 'extend-wp'),
        array(
          'status' => 500,
          'debug' => array(
            'meta_key' => $meta_key,
            'view' => $view,
            'object_id' => $object_id,
          ),
        )
      );
    }

    /**
     * Action after saving modal values
     *
     * @param string $meta_key The modal meta key
     * @param string $view View type (post/term/user/option)
     * @param int $object_id Object ID
     * @param array $sanitized_values Saved values
     * @since 1.2.0
     */
    do_action('awm_modal_after_save', $meta_key, $view, $object_id, $sanitized_values);

    return array(
      'success' => true,
      'message' => __('Data saved successfully', 'extend-wp'),
      'values' => $sanitized_values,
    );
  }

  /**
   * Lookup modal field definition from registered meta boxes/options
   *
   * Searches through registered meta boxes, option pages, term boxes, and user boxes
   * to find the field definition for the given meta_key.
   *
   * @param string $meta_key Meta key to lookup
   * @param string $view View type (post/term/user/option/content_meta)
   * @param int $object_id Object ID (used to determine post type for post view)
   * @param string $option_page Optional option page key for direct lookup (option view only)
   * @return array|false Field 'include' definitions or false if not found
   * @since 1.2.0
   */
  private function lookup_modal_field_definition($meta_key, $view, $object_id, $option_page = '')
  {
    $metas = new AWM_Meta();
    $field_def = false;

    switch ($view) {
      case 'option':
        // Direct lookup if option_page is provided
        if (!empty($option_page)) {
          $option_pages = $metas->options_boxes();

          if (isset($option_pages[$option_page])) {
            $fields = awm_callback_library_options($option_pages[$option_page]);
            if (!empty($fields)) {
              $fields = awm_callback_library($fields, $option_page);
            }
            if (isset($fields[$meta_key]) && isset($fields[$meta_key]['include'])) {
              $field_def = $fields[$meta_key]['include'];
            }
          }
        } else {
          // Fallback: search all option pages
          $option_pages = $metas->options_boxes();
          foreach ($option_pages as $page_id => $page_data) {
            $fields = awm_callback_library_options($page_data);
            if (!empty($fields)) {
              $fields = awm_callback_library($fields, $page_id);
            }
            if (isset($fields[$meta_key]) && isset($fields[$meta_key]['include'])) {
              $field_def = $fields[$meta_key]['include'];
              break;
            }
          }
        }
        break;

      case 'post':
        // Search in meta boxes - need post type
        if ($object_id > 0) {
          $post_type = get_post_type($object_id);
          if ($post_type) {
            $meta_boxes = $metas->meta_boxes();
            foreach ($meta_boxes as $box_id => $box_data) {
              if (isset($box_data['postTypes']) && in_array($post_type, $box_data['postTypes'])) {
                $fields = awm_callback_library_options($box_data);
                if (isset($fields[$meta_key]) && isset($fields[$meta_key]['include'])) {
                  $field_def = $fields[$meta_key]['include'];
                  break 2;
                }
              }
            }
          }
        }
        break;

      case 'term':
        // Search in term meta boxes
        if ($object_id > 0) {
          $term = get_term($object_id);
          if ($term && !is_wp_error($term)) {
            $taxonomy = $term->taxonomy;
            $term_boxes = $metas->term_meta_boxes();
            foreach ($term_boxes as $box_id => $box_data) {
              if (isset($box_data['taxonomies']) && in_array($taxonomy, $box_data['taxonomies'])) {
                $fields = awm_callback_library_options($box_data);
                if (isset($fields[$meta_key]) && isset($fields[$meta_key]['include'])) {
                  $field_def = $fields[$meta_key]['include'];
                  break 2;
                }
              }
            }
          }
        }
        break;

      case 'user':
        // Search in user boxes
        $user_boxes = $metas->user_boxes();
        foreach ($user_boxes as $box_id => $box_data) {
          $fields = awm_callback_library_options($box_data);
          if (isset($fields[$meta_key]) && isset($fields[$meta_key]['include'])) {
            $field_def = $fields[$meta_key]['include'];
            break;
          }
        }
        break;
    }

    /**
     * Filter modal field definition lookup result
     *
     * Allows developers to provide custom field definitions or override
     * the default lookup logic.
     *
     * @param array|false $field_def Field definitions or false if not found
     * @param string $meta_key Meta key being looked up
     * @param string $view View type
     * @param int $object_id Object ID
     * @since 1.2.0
     */
    return apply_filters('awm_modal_field_definition_lookup', $field_def, $meta_key, $view, $object_id);
  }

  /**
   * Get modal value from storage
   *
   * @param string $view View type (post/term/user/option/content_meta)
   * @param int $object_id Object ID
   * @param string $meta_key Meta key
   * @return mixed Stored value or empty array
   * @since 1.2.0
   */
  private function get_modal_value($view, $object_id, $meta_key)
  {
    switch ($view) {
      case 'post':
        return get_post_meta($object_id, $meta_key, true);
      case 'term':
        return get_term_meta($object_id, $meta_key, true);
      case 'user':
        return get_user_meta($object_id, $meta_key, true);
      case 'option':
        return get_option($meta_key, array());
      case 'content_meta':
        if (function_exists('awm_get_db_content_meta_value')) {
          return awm_get_db_content_meta_value($object_id, $meta_key);
        }
        return array();
      default:
        return array();
    }
  }

  /**
   * Save modal value to storage
   *
   * @param string $view View type (post/term/user/option/content_meta)
   * @param int $object_id Object ID
   * @param string $meta_key Meta key
   * @param array $values Values to save
   * @return bool True on success
   * @since 1.2.0
   */
  private function save_modal_value($view, $object_id, $meta_key, $values)
  {
    switch ($view) {
      case 'post':
        return update_post_meta($object_id, $meta_key, $values) !== false;
      case 'term':
        return update_term_meta($object_id, $meta_key, $values) !== false;
      case 'user':
        return update_user_meta($object_id, $meta_key, $values) !== false;
      case 'option':
        // update_option returns false if the value hasn't changed, but that's not an error
        $result = update_option($meta_key, $values);
        // If update_option returns false, check if the current value matches what we're trying to save
        if (!$result) {
          $current = get_option($meta_key);
          // If values match, it's a success (no change needed)
          return $current === $values;
        }
        return true;
      case 'content_meta':
        if (function_exists('awm_update_db_content_meta')) {
          return awm_update_db_content_meta($object_id, $meta_key, $values);
        }
        return false;
      default:
        return false;
    }
  }

  /**
   * Sanitize modal field values recursively
   *
   * @param mixed $values Values to sanitize
   * @return mixed Sanitized values
   * @since 1.2.0
   */
  private function sanitize_modal_values($values)
  {
    if (!is_array($values)) {
      return sanitize_text_field($values);
    }

    $sanitized = array();
    foreach ($values as $key => $value) {
      $sanitized_key = sanitize_key($key);
      $sanitized[$sanitized_key] = is_array($value)
        ? $this->sanitize_modal_values($value)
        : sanitize_text_field($value);
    }

    return $sanitized;
  }
}