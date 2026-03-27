<?php
if (!defined('ABSPATH')) {
  exit;
}



class AWM_API extends WP_REST_Controller
{
  /**
   * @var namespace The namespace of the API. Distinct name and version.
   */
  protected $namespace;

  /**
   * Basic constructor function that initializes the namespace and the base of the Filox Rates API
   */
  public function __construct()
  {

    // Initialize values
    $this->namespace = 'extend-wp/v1';
  }
  /**
   * Registers all Filox Rates API endpoints using the proper custom WP REST API configuration
   */
  public function register_routes()
  {

    /**
     * *****************************
     *        RATES SETTINGS
     * *****************************
     */

    /**
     * @param namespace The namespace of the endpoint
     * 
     * @param base The base of the endpoint
     * 
     * @param args Takes an array of arguments. Arguments are 
     * 1. methods: (WP_REST_Server::Readable,Creatable,Editable,Deletable) (GET,POST,PUT,DELETE)
     * 2. callback: The function to call once the endpoint is met. Array of 2 elements. First element is the class and the second the name of the callback function
     * 3. permission_callback: The function that gets called in order to authorize the API call
     */
    register_rest_route($this->namespace, "/get-case-fields/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'get_case_fields'),
        "permission_callback" => function () {
          return true;
        }
      )
    ));

    register_rest_route($this->namespace, "/get-query-fields/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'get_query_fields'),
        "permission_callback" => function () {
          return true;
        }
      )
    ));

    register_rest_route($this->namespace, "/get-php-code/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'ewp_get_php'),
        "permission_callback" => function () {
          return true;
        }
      )
    ));


    register_rest_route($this->namespace, "/get-position-fields/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'get_position_fields'),
        "permission_callback" => function () {
          return true;
        }
      )
    ));

    register_rest_route($this->namespace, "/awm-map-options/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'awm_map_options_func'),
        "permission_callback" => function () {
          return true;
        }
      )
    ));

    /**
     * Modal Fields Endpoints
     * 
     * GET /modal-fields/ — Render modal fields HTML with current values
     * POST /modal-save/ — Save modal field values to meta/option
     * 
     * @since 1.2.0
     */
    register_rest_route($this->namespace, "/modal-fields/", array(
      array(
        "methods" => WP_REST_Server::READABLE,
        "callback" => array($this, 'get_modal_fields'),
        "permission_callback" => array($this, 'modal_permission_check')
      )
    ));

    register_rest_route($this->namespace, "/modal-save/", array(
      array(
        "methods" => WP_REST_Server::CREATABLE,
        "callback" => array($this, 'save_modal_fields'),
        "permission_callback" => array($this, 'modal_permission_check')
      )
    ));
  }

  public function awm_map_options_func($request)
  {
    if (isset($request)) {
      $options = array();
      $dev_settings = get_option('ewp_dev_settings') ?: array();
      $options['key'] = isset($dev_settings['google_maps_api_key']) ? $dev_settings['google_maps_api_key'] : '';
      $options['lat'] = '39.0742';
      $options['lng'] = '21.8243';
      $options['map_options'] = array(
        'zoom' => 12,
      );
      $options = apply_filters('awm_map_options_func_filter', $options);
      return rest_ensure_response(new WP_REST_Response($options), 200);
    }
    return rest_ensure_response(new WP_REST_Response(__('No options detected', 'extend-wp')), 401);
  }




  public function ewp_get_php($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      $post_id = isset($params['awm_post_id']) ? absint($params['awm_post_id']) : 0;
      $code = array();
      $awm_field = awm_get_db_content('ewp_fields', array('include' => $post_id));
      if (empty($awm_field)) {
        return;
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
      return rest_ensure_response(new WP_REST_Response(implode('', $code)), 200);
    }
    return rest_ensure_response(new WP_REST_Response(__('No options detected', 'extend-wp')), 401);
  }


  public function get_position_fields($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      if (!isset($params['position']) || empty($params['position'])) {
        return '';
      }
      $field = sanitize_text_field($params['position']);
      $name = sanitize_text_field($params['name']);
      $postId = absint($params['id']);
      $return = $this->get_awm_metas_configuration(
        $field,
        $name,
        'awm_positions',
        $postId,
        awm_position_options(),
        'ewp_fields',
        'case'
      );
      return rest_ensure_response(new WP_REST_Response($return), 200);
    }
    return false;
  }


  /**
   * get query fields
   */
  public function get_query_fields($request)
  {
    // Check that a request is sent
    if (isset($request)) {
      $params = $request->get_params();
      if (!isset($params['field']) || empty($params['field'])) {
        return '';
      }
      $field = sanitize_text_field($params['field']);
      $name = sanitize_text_field($params['name']);
      $meta = sanitize_text_field($params['meta']);
      $postId = absint($params['id']);
      $return = $this->get_awm_metas_configuration(
        $field,
        $name,
        $meta,
        $postId,
        ewp_query_fields(),
        'ewp_search',
        'query_type'
      );
      return rest_ensure_response(new WP_REST_Response($return), 200);
    }
    return false;
  }


  public function get_case_fields($request)
  {
    // Check that a request is sent
    if (isset($request)) {
      $params = $request->get_params();
      if (!isset($params['field']) || empty($params['field'])) {
        return '';
      }
      $field = sanitize_text_field($params['field']);
      $name = sanitize_text_field($params['name']);
      $meta = sanitize_text_field($params['meta']);
      $db = 'ewp_fields';
      switch ($meta) {
        case 'query_fields':
          $db = 'ewp_search';
          $replace = 'query_type';
          break;
      }
      $postId = absint($params['id']);
      $return = $this->get_awm_metas_configuration($field, $name, $meta, $postId, awmInputFields(), $db, 'case');
      return rest_ensure_response(new WP_REST_Response($return), 200);
    }
    return false;
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
   * Permission check for modal endpoints
   *
   * Requires user to have edit_posts capability.
   *
   * @return bool True if user has permission
   * @since 1.2.0
   */
  public function modal_permission_check()
  {
    return current_user_can('edit_posts');
  }

  /**
   * Get modal fields HTML with current values
   *
   * Renders the modal field definitions with pre-populated values
   * based on the view type (post/term/user/option/content_meta).
   * Field definitions are looked up server-side from registered meta boxes/options.
   * Uses PHP template file for modal HTML structure.
   *
   * @param WP_REST_Request $request REST request object
   * @return WP_REST_Response Rendered HTML or error
   * @since 1.2.0
   */
  public function get_modal_fields($request)
  {
    $params = $request->get_params();

    $meta_key = isset($params['meta_key']) ? sanitize_key($params['meta_key']) : '';
    $view = isset($params['view']) ? sanitize_key($params['view']) : 'post';
    $object_id = isset($params['object_id']) ? absint($params['object_id']) : 0;
    $modal_title = isset($params['modal_title']) ? sanitize_text_field($params['modal_title']) : '';
    $modal_id = isset($params['modal_id']) ? sanitize_key($params['modal_id']) : $meta_key;
    $option_page = isset($params['option_page']) ? sanitize_key($params['option_page']) : '';

    if (empty($meta_key)) {
      return new WP_REST_Response(
        array('message' => __('Missing meta_key parameter', 'extend-wp')),
        400
      );
    }

    // Lookup field definitions server-side
    $fields = $this->lookup_modal_field_definition($meta_key, $view, $object_id, $option_page);

    if (!is_array($fields) || empty($fields)) {
      return new WP_REST_Response(
        array('message' => sprintf(__('Field definition not found for meta_key: %s', 'extend-wp'), $meta_key)),
        404
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

    return new WP_REST_Response(array(
      'modal_html' => $modal_html,
      'fields_html' => $fields_html,
      'modal_title' => $modal_title,
      'current_value' => $current_value,
    ), 200);
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
   * @param WP_REST_Request $request REST request object
   * @return WP_REST_Response Success or error response
   * @since 1.2.0
   */
  public function save_modal_fields($request)
  {
    $params = $request->get_params();

    $meta_key = isset($params['meta_key']) ? sanitize_key($params['meta_key']) : '';
    $view = isset($params['view']) ? sanitize_key($params['view']) : 'post';
    $object_id = isset($params['object_id']) ? absint($params['object_id']) : 0;
    $values = isset($params['values']) ? $params['values'] : array();

    if (empty($meta_key)) {
      return new WP_REST_Response(
        array('message' => __('Missing meta key', 'extend-wp')),
        400
      );
    }

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

      return new WP_REST_Response(
        array(
          'message' => __('Failed to save data', 'extend-wp'),
          'debug' => array(
            'meta_key' => $meta_key,
            'view' => $view,
            'object_id' => $object_id,
          )
        ),
        500
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

    return new WP_REST_Response(array(
      'success' => true,
      'message' => __('Data saved successfully', 'extend-wp'),
      'values' => $sanitized_values,
    ), 200);
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