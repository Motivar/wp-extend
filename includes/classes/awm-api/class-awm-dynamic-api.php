<?php
if (!defined('ABSPATH')) {
  exit;
}



class AWM_Dynamic_API extends WP_REST_Controller
{
  /**
   * @var endpoints An array with all the endpoints to register
   */
  private $endpoints;

  /**
   * Basic constructor function gather the args
   */
  public function __construct($args)
  {

    // Initialize values
    $this->endpoints = $args;
  }
  /**
   * Registers all Filox Rates API endpoints using the proper custom WP REST API configuration
   */
  public function register_routes()
  {
    if (empty($this->endpoints)) {
      return true;
    }
    foreach ($this->endpoints as $endpoint) {
      if (isset($endpoint['endpoint'])) {
        $method = strtolower($endpoint['method']) ?: 'get';
        $namespace = $endpoint['namespace'] ?: 'awm-dynamic-api/v1';
        $callback = $endpoint['php_callback'] ?: [$this, 'awm_default_callback'];
        $args = isset($endpoint['args']) ? $endpoint['args'] : array();
        $rest_args = array(
          "methods" => $this->resolve_methods($method),
          'callback' => $callback,
          'args' => $args,
          'permission_callback' => $this->resolve_permission($endpoint),
        );
        register_rest_route($namespace, $endpoint['endpoint'], $rest_args);
      }
    }
  }

  /**
   * Map the endpoint's `method` key onto a WP_REST_Server method constant.
   *
   * @param string $method Lower-cased method: get|post|put|patch|update|delete.
   *
   * @return string
   *
   * @since 1.5.0
   */
  private function resolve_methods($method)
  {
    switch ($method) {
      case 'get':
        return WP_REST_Server::READABLE;
      case 'put':
      case 'patch':
      case 'update':
        return WP_REST_Server::EDITABLE;
      case 'delete':
        return WP_REST_Server::DELETABLE;
    }

    return WP_REST_Server::CREATABLE;
  }

  /**
   * Resolve the permission callback for an endpoint definition.
   *
   * A route is only public when its definition says so explicitly with
   * `'public' => true`. Before 1.5.0 a missing `permission_callback`
   * silently made the route public, which exposed every custom content
   * type's read routes to anonymous requests.
   *
   * @param array $endpoint Endpoint definition.
   *
   * @return callable|string
   *
   * @since 1.5.0
   */
  private function resolve_permission(array $endpoint)
  {
    if (!empty($endpoint['permission_callback'])) {
      return $endpoint['permission_callback'];
    }

    if (!empty($endpoint['public'])) {
      return '__return_true';
    }

    /**
     * Filter the permission callback used when an endpoint definition
     * declares neither `permission_callback` nor `'public' => true`.
     *
     * @param callable|string $callback Default: `ewp_rest_check_user_is_admin` (manage_options).
     * @param array           $endpoint The endpoint definition.
     *
     * @since 1.5.0
     */
    return apply_filters('ewp_dynamic_api_default_permission', 'ewp_rest_check_user_is_admin', $endpoint);
  }

  public function awm_default_callback($request)
  {
    if (isset($request)) {
      $params = $request->get_params();
      return rest_ensure_response(new WP_REST_Response($params), 200);
    }
    return rest_ensure_response(new WP_REST_Response(false), 400);
  }
}
