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
  }

  public function awm_map_options_func($request)
  {
    if (isset($request)) {
      $options = array();
      $options['key'] = '';
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
      $return = $this->get_awm_metas_configuration($field, $name, 'awm_positions', $postId, awm_position_options());
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

      $postId = absint($params['id']);
      $return = $this->get_awm_metas_configuration($field, $name, 'awm_fields', $postId, awmInputFields());
      return rest_ensure_response(new WP_REST_Response($return), 200);
    }
    return false;
  }

  private function get_awm_metas_configuration($field, $name, $meta, $postId, $all_fields)
  {
    $content = '';
    if (array_key_exists($field, $all_fields)) {
      if (isset($all_fields[$field]['field-choices']) && !empty($all_fields[$field]['field-choices'])) {
        $values = awm_get_db_content_meta('ewp_fields', $postId, $meta) ?: array();
        $metas = array();
        foreach ($all_fields[$field]['field-choices'] as $id => $data) {
          $inputname = str_replace('[case]', '', $name) . '[' . $id . ']';
          $position = absint(str_replace('[case]', '', str_replace($meta . '[', '', str_replace(']', '', $name))));
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
}
