<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EWP REST Health — REST Controller
 *
 * Provides 7 REST endpoints for the health check admin page.
 * All routes are restricted to super administrators.
 *
 * Routes (namespace: extend-wp/v1):
 *   GET    /rest-health/plugins      List active WP plugins
 *   POST   /rest-health/endpoints    Discover routes for selected plugins
 *   GET    /rest-health/openapi      OpenAPI 3.0 spec JSON
 *   POST   /rest-health/test         Run a single endpoint test
 *   POST   /rest-health/batch        Run a batch of endpoint tests
 *   GET    /rest-health/history      Get test history for a route
 *   DELETE /rest-health/history      Clear test history for a route
 *
 * @package EWP\RestHealth
 * @since   1.0.0
 */
class EWP_REST_Health_Controller extends WP_REST_Controller
{
    protected $namespace = 'extend-wp/v1';
    protected $rest_base = 'rest-health';

    /** @var EWP_REST_Health_Discovery */
    private $discovery;

    /** @var EWP_REST_Health_Runner */
    private $runner;

    /** @var EWP_REST_Health_OpenAPI */
    private $openapi;

    public function __construct()
    {
        $this->discovery = new EWP_REST_Health_Discovery();
        $this->runner    = new EWP_REST_Health_Runner();
        $this->openapi   = new EWP_REST_Health_OpenAPI($this->runner);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function register_routes(): void
    {
        $perm = [$this, 'check_permission'];
        $base = '/' . $this->rest_base;

        register_rest_route($this->namespace, $base . '/plugins', [
            ['methods' => WP_REST_Server::READABLE,  'callback' => [$this, 'get_plugins'],  'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/endpoints', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'get_endpoints'], 'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/openapi', [
            ['methods' => WP_REST_Server::READABLE,  'callback' => [$this, 'get_openapi'],  'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/test', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'run_test'],     'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/batch', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'run_batch'],    'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/history', [
            ['methods' => WP_REST_Server::READABLE,  'callback' => [$this, 'get_history'],   'permission_callback' => $perm],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'clear_history'], 'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/monitor', [
            ['methods' => WP_REST_Server::READABLE,  'callback' => [$this, 'get_monitor'],    'permission_callback' => $perm],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'update_monitor'], 'permission_callback' => $perm],
        ]);

        register_rest_route($this->namespace, $base . '/monitor/payloads', [
            ['methods' => WP_REST_Server::READABLE,  'callback' => [$this, 'get_payloads'],   'permission_callback' => $perm],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'clear_payloads'], 'permission_callback' => $perm],
        ]);
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    public function check_permission(): bool
    {
        return is_super_admin();
    }

    // -------------------------------------------------------------------------
    // Route callbacks
    // -------------------------------------------------------------------------

    public function get_plugins(WP_REST_Request $request): WP_REST_Response
    {
        $plugins = $this->discovery->get_active_plugins();
        $ns_map  = $this->discovery->build_namespace_plugin_map();
        $result  = [];

        foreach ($plugins as $path => $meta) {
            $namespaces = array_keys(array_filter($ns_map, fn($p) => $p === $path));
            $result[]   = [
                'path'       => $path,
                'name'       => $meta['name'],
                'dir'        => $meta['dir'],
                'namespaces' => array_values($namespaces),
            ];
        }

        return rest_ensure_response($result);
    }

    public function get_endpoints(WP_REST_Request $request): WP_REST_Response
    {
        $plugins = (array) ($request->get_param('plugins') ?? []);
        if (empty($plugins)) {
            return new WP_REST_Response(['error' => 'No plugins specified.'], 400);
        }
        $routes = $this->discovery->get_routes_for_plugins($plugins);
        return rest_ensure_response(array_values($routes));
    }

    public function get_openapi(WP_REST_Request $request): WP_REST_Response
    {
        $plugins = (array) ($request->get_param('plugins') ?? []);
        if (empty($plugins)) {
            // Default: all active plugins
            $plugins = array_keys($this->discovery->get_active_plugins());
        }

        $routes         = $this->discovery->get_routes_for_plugins($plugins);
        $method_filter  = array_map('strtoupper', (array) ($request->get_param('methods') ?? []));
        $spec           = $this->openapi->generate($routes, get_current_user_id(), $method_filter);

        $response = new WP_REST_Response($spec, 200);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }

    public function run_test(WP_REST_Request $request): WP_REST_Response
    {
        $route  = sanitize_text_field((string) ($request->get_param('route') ?? ''));
        $method = strtoupper(sanitize_text_field((string) ($request->get_param('method') ?? 'GET')));
        $params = (array) ($request->get_param('params') ?? []);

        if (!$route) {
            return new WP_REST_Response(['error' => 'Route is required.'], 400);
        }

        $result = $this->runner->run_single($route, $method, $params, get_current_user_id());
        return rest_ensure_response($result);
    }

    public function run_batch(WP_REST_Request $request): WP_REST_Response
    {
        $items = (array) ($request->get_param('routes_methods') ?? []);
        if (empty($items)) {
            return new WP_REST_Response(['error' => 'No routes specified.'], 400);
        }
        $result = $this->runner->run_batch($items, get_current_user_id());
        return rest_ensure_response($result);
    }

    public function get_history(WP_REST_Request $request): WP_REST_Response
    {
        $route  = sanitize_text_field((string) ($request->get_param('route') ?? ''));
        $method = strtoupper(sanitize_text_field((string) ($request->get_param('method') ?? 'GET')));

        if (!$route) {
            return new WP_REST_Response(['error' => 'Route is required.'], 400);
        }

        $entries = $this->runner->get_history($route, $method, get_current_user_id());
        return rest_ensure_response($entries);
    }

    public function clear_history(WP_REST_Request $request): WP_REST_Response
    {
        $route  = sanitize_text_field((string) ($request->get_param('route') ?? ''));
        $method = strtoupper(sanitize_text_field((string) ($request->get_param('method') ?? 'GET')));

        if (!$route) {
            return new WP_REST_Response(['error' => 'Route is required.'], 400);
        }

        $this->runner->clear_history($route, $method, get_current_user_id());
        return rest_ensure_response(['cleared' => true]);
    }

    // -------------------------------------------------------------------------
    // Monitor
    // -------------------------------------------------------------------------

    public function get_monitor(WP_REST_Request $request): WP_REST_Response
    {
        $active    = (bool) get_option('ewp_rh_monitor_active', false);
        $started   = (int)  get_option('ewp_rh_monitor_started', 0);
        $count     = (int)  get_option('ewp_rh_monitor_count', 0);
        $remaining = $active ? max(0, 600 - (time() - $started)) : 0;

        // Auto-stop if time has expired
        if ($active && $remaining === 0) {
            update_option('ewp_rh_monitor_active', false, false);
            $active = false;
        }

        return rest_ensure_response([
            'active'          => $active,
            'started'         => $started ? wp_date('Y-m-d H:i:s', $started) : '',
            'remaining_sec'   => $remaining,
            'captured_count'  => $count,
            'namespaces'      => (array) get_option('ewp_rh_monitor_namespaces', []),
        ]);
    }

    /**
     * Start or stop live monitoring.
     * Body: { action: 'start'|'stop', plugins: string[] }
     */
    public function update_monitor(WP_REST_Request $request): WP_REST_Response
    {
        $action  = sanitize_text_field($request->get_param('action') ?? 'stop');
        $plugins = (array) ($request->get_param('plugins') ?? []);

        if ($action === 'start') {
            // Build the list of namespaces for the chosen plugins once and store it
            $ns_map       = $this->discovery->build_namespace_plugin_map();
            $monitored_ns = array_keys(array_filter($ns_map, fn($p) => in_array($p, $plugins, true)));

            update_option('ewp_rh_monitor_active',     true,                false);
            update_option('ewp_rh_monitor_started',    time(),              false);
            update_option('ewp_rh_monitor_count',      0,                   false);
            update_option('ewp_rh_monitor_namespaces', $monitored_ns,       false);
        } else {
            update_option('ewp_rh_monitor_active', false, false);
        }

        return $this->get_monitor($request);
    }

    public function get_payloads(WP_REST_Request $request): WP_REST_Response
    {
        $all = (array) get_option('ewp_rh_captured_payloads', []);
        return rest_ensure_response(array_values($all));
    }

    public function clear_payloads(WP_REST_Request $request): WP_REST_Response
    {
        update_option('ewp_rh_captured_payloads', [], false);
        update_option('ewp_rh_monitor_count',     0,  false);
        return rest_ensure_response(['cleared' => true]);
    }
}
