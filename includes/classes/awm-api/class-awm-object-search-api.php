<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Global REST API for the reusable `object_id_filter` field type.
 *
 * Exposes a single search endpoint that returns lightweight {id,label,type}
 * results for a given object type (post type, taxonomy or custom content),
 * so the picker never has to load every ID up front. Preloading of already
 * selected values is handled server-side at render time, so no preload
 * endpoint is needed here.
 *
 * @since 1.4.0
 */
class AWM_Object_Search_API extends WP_REST_Controller
{
  /**
   * @var string The namespace of the API.
   */
  protected $namespace;

  public function __construct()
  {
    $this->namespace = 'extend-wp/v1';
  }

  /**
   * Register the search route.
   */
  public function register_routes()
  {
    register_rest_route($this->namespace, '/objects/search', array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array($this, 'search'),
        'permission_callback' => array($this, 'permission_check'),
        'args'                => array(
          'object_type' => array(
            'required'          => true,
            'sanitize_callback' => 'sanitize_text_field',
          ),
          'search' => array(
            'required'          => false,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
          ),
          'exclude' => array(
            'required'          => false,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
          ),
          'limit' => array(
            'required'          => false,
            'default'           => 20,
            'sanitize_callback' => 'absint',
          ),
          'search_meta' => array(
            'required' => false,
            'default'  => true,
          ),
        ),
      ),
    ));
  }

  /**
   * Capability check. The required capability is filterable so the field can
   * be used in non-admin contexts.
   *
   * The X-WP-Nonce header is validated by the REST infrastructure before this
   * runs, establishing the current user.
   *
   * @return bool
   */
  public function permission_check()
  {
    $capability = apply_filters('awm_object_search_capability', 'manage_options');
    return current_user_can($capability);
  }

  /**
   * Handle the search request.
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response|WP_Error
   */
  public function search($request)
  {
    $object_type = (string) $request->get_param('object_type');

    // Validate the {group}:{slug} format.
    if (strpos($object_type, ':') === false) {
      return new WP_Error('awm_invalid_object_type', __('Invalid object type format.', 'extend-wp'), array('status' => 400));
    }
    list($group, $slug) = explode(':', $object_type, 2);
    $allowed_groups = array('post_type', 'taxonomy', 'custom_content');
    if (!in_array($group, $allowed_groups, true) || $slug === '') {
      return new WP_Error('awm_invalid_object_type', __('Unsupported object type.', 'extend-wp'), array('status' => 400));
    }

    $search      = (string) $request->get_param('search');
    $limit       = absint($request->get_param('limit')) ?: 20;
    $search_meta = filter_var($request->get_param('search_meta'), FILTER_VALIDATE_BOOLEAN);

    $exclude_raw = (string) $request->get_param('exclude');
    $exclude     = array_filter(array_map('absint', array_filter(explode(',', $exclude_raw))));

    $results = awm_object_search_query($group, $slug, $search, array(
      'limit'       => $limit,
      'search_meta' => $search_meta,
      'exclude'     => $exclude,
    ));

    return new WP_REST_Response(array(
      'success' => true,
      'data'    => $results,
    ), 200);
  }
}
