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

  /**
   * Shared content service also used by the WP Abilities API providers and
   * the `wp ewp content` CLI commands, so create/update validation and
   * persistence behave identically no matter which surface is used.
   *
   * @var \EWP\Abilities\EWP_Abilities_Content_Service
   */
  private $content_service;

  public function __construct($id, $args)
  {
    // Initialize values
    $this->object_type = $id;
    $this->object_defaults = $args;
    $this->content_service = new \EWP\Abilities\EWP_Abilities_Content_Service();
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
   * Delete one or more items and all their meta.
   *
   * Delegates to EWP_Abilities_Content_Service::delete_items(), the same
   * implementation the abilities and `wp ewp content delete` use, so the
   * existence check and the `{count, deleted, not_found}` result are
   * identical on every surface.
   *
   * @param WP_REST_Request $request The incoming REST request. `ids` is a comma separated list.
   *
   * @return WP_REST_Response|WP_Error
   */
  public function delete($request)
  {
    if (!isset($request)) {
      return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
    }

    $params = $request->get_params();
    if (!isset($params['ids']) || $params['ids'] === '') {
      return rest_ensure_response(new WP_REST_Response(__('No ids detected', 'ewp')), 400);
    }

    $ids    = array_filter(array_map('absint', explode(',', (string) $params['ids'])));
    $result = $this->content_service->delete_items($this->object_type, array_values($ids));

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response($result);
  }

  /**
   * Create a new content item.
   *
   * Delegates to EWP_Abilities_Content_Service::create_item(), the same
   * implementation the `ewp-content`/`ewp-fields`/`ewp-wp-content` abilities
   * and the `wp ewp content create` CLI command use, so validation
   * (required fields, unknown meta keys, status) and persistence are
   * identical across all three surfaces.
   *
   * @param WP_REST_Request $request The incoming REST request.
   *
   * @return WP_REST_Response|WP_Error The created item, or a WP_Error on validation/save failure.
   */
  public function insert($request)
  {
    if (!isset($request)) {
      return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
    }

    $params = $request->get_params();
    $title  = isset($params['title']) ? (string) $params['title'] : '';
    $status = isset($params['status']) ? (string) $params['status'] : '';
    $meta   = isset($params['meta']) && is_array($params['meta']) ? $params['meta'] : array();

    $result = $this->content_service->create_item($this->object_type, $title, $status, $meta);

    if (is_wp_error($result)) {
      return $result;
    }

    $response = rest_ensure_response($result);
    $response->set_status(201);
    return $response;
  }

  /**
   * Update an existing content item.
   *
   * Delegates to EWP_Abilities_Content_Service::update_item() (patch
   * semantics: only the keys present in the request body are changed),
   * the same implementation the abilities layer and the
   * `wp ewp content update` CLI command use.
   *
   * @param WP_REST_Request $request The incoming REST request. Requires the `id` route param.
   *
   * @return WP_REST_Response|WP_Error The updated item, or a WP_Error on validation/save failure.
   */
  public function update($request)
  {
    if (!isset($request)) {
      return rest_ensure_response(new WP_REST_Response(__('No params detected', 'ewp')), 400);
    }

    $params = $request->get_params();
    $id     = isset($params['id']) ? absint($params['id']) : 0;

    $patch = array();
    if (array_key_exists('title', $params)) {
      $patch['title'] = (string) $params['title'];
    }
    if (array_key_exists('status', $params)) {
      $patch['status'] = (string) $params['status'];
    }
    if (isset($params['meta']) && is_array($params['meta'])) {
      $patch['meta'] = $params['meta'];
    }

    $result = $this->content_service->update_item($this->object_type, $id, $patch);

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response($result);
  }
}
