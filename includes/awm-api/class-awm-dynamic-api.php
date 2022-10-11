<?php
if (!defined('ABSPATH')) {
  exit;
}



class AWM_Dynamic_API extends WP_REST_Controller
{
  /**
   * @var namespace The namespace of the API. Distinct name and version.
   */
  protected $awm_options;

  /**
   * Basic constructor function that initializes the namespace and the base of the Filox Rates API
   */
  public function __construct($args)
  {

    // Initialize values
    $this->awm_options = $args;
  }
  /**
   * Registers all Filox Rates API endpoints using the proper custom WP REST API configuration
   */
  public function register_routes()
  {
    foreach ($this->awm_options as $option) {
      if (isset($option['rest'])) {
        $endpoint = $option['rest'];
        if (isset($endpoint['endpoint'])) {
          $method = $endpoint['method'] ?: 'get';
          $namespace = $endpoint['namespace'] ?: 'awm-dynamic-api/v1';
          $callback = $endpoint['php_callback'] ?: array($this, 'awm_default_callback');
          $permission_callback = $endpoint['permission_callback'] ?: '';
          $rest_args = array(
            "methods" => strtolower($method) === 'get' ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
            "callback" => $callback,
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
