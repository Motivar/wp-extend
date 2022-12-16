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
        $permission_callback = isset($endpoint['permission_callback']) ? $endpoint['permission_callback'] : '';
        $args = isset($endpoint['args']) ? $endpoint['args'] : array();
        $rest_args = array(
          "methods" => $method === 'update' ? WP_REST_Server::EDITABLE : ($method === 'delete' ? WP_REST_Server::DELETABLE : ($method === 'get' ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE)),
          'callback' => $callback,
          'args' => $args
        );

        if (!empty($permission_callback)) {
          $rest_args['permission_callback'] = $permission_callback;
        }
        if (!isset($rest_args['permission_callback'])) {
          $rest_args['permission_callback'] = function () {
            return true;
          };
        }
        register_rest_route($namespace, $endpoint['endpoint'], $rest_args);
      }
    }
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
