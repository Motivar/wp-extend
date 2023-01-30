<?php
if (!defined('ABSPATH')) {
  exit;
}


/**
 * setupσ the custom content id db
 */

class AWM_Add_Content_DB_API extends WP_REST_Controller
{
  private $object_type;
  private $object_defaults;

  public function __construct($id, $args)
  {
    // Initialize values
    $this->object_type = $id;
    $this->object_defaults = $args;
  }
  /**
   * get the results
   */
  public function get_results($request)
  {
    if (isset($request)) {
      $params = $request->get_params();

      if (isset($params['id'])) {
        $params['include'] = $params['id'];
        unset($params['id']);
      }
      $posts = awm_get_db_content($this->object_type, $params);


      if (!empty($posts)) {
        foreach ($posts as &$data) {
          $data['meta'] = awm_get_db_content_meta($this->object_type, $data['content_id']);
        }
      }
      return rest_ensure_response(new WP_REST_Response($posts), 200);
    }
    return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
  }

  /**
   * delete the object and all related data
   */
  public function delete($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      if (!isset($params['ids']) || empty($params['ids'])) {
        return rest_ensure_response(new WP_REST_Response(__('No ids detected', 'ewp')), 400);
      }
      $ids = explode(',', $params['ids']);
      return rest_ensure_response(new WP_REST_Response(awm_custom_content_delete($this->object_type, $ids)), 200);
    }
    return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
  }

  /**
   * insert new coupons
   */
  public function insert($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      flx_pretty_print($this->object_defaults);
      die();
      if (!isset($params['ids']) || empty($params['ids'])) {
        return rest_ensure_response(new WP_REST_Response(__('No ids detected', 'ewp')), 400);
      }
      $ids = explode(',', $params['ids']);
      return rest_ensure_response(new WP_REST_Response(awm_custom_content_delete($this->object_type, $ids)), 200);
    }
    return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
  }
}
