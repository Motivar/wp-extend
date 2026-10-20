<?php

namespace MammothWP\SelfTest\Surfaces;

use MammothWP\SelfTest\Compat\Legacy;
use MammothWP\SelfTest\Config;
use MammothWP\SelfTest\Runner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST routes behind the self-test dashboard. Thin wrappers over Runner;
 * only registered when the dashboard is enabled (non-production) and always
 * limited to Config::capability().
 *
 * Routes (namespace mmwp/v1):
 *   GET  /self-test/cases?ids=a,b&plugin=x   → runner->cases()
 *   GET  /self-test/preview?ids=a,b&plugin=x → runner->preview()
 *   POST /self-test/run                      → runner->run()   {ids: [], cleanup: bool, plugin: string}
 *   POST /self-test/cleanup                  → runner->cleanup() {ids: []}
 *   GET  /self-test/report                   → runner->report()
 *
 * Deprecated alias (one major version): the same routes without the
 * `/self-test` segment under `mwp-self-test/v1`, answering with a
 * `Deprecation: true` header.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */
final class Rest
{
    /** REST namespace. */
    const NAMESPACE_CURRENT = 'mmwp/v1';

    /** Route prefix inside the current namespace. */
    const BASE = '/self-test';

    /** Deprecated pre-1.0 namespace (routes have no base segment). */
    const NAMESPACE_LEGACY = 'mwp-self-test/v1';

    /** @var Runner */
    private $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /** @return void */
    public function init()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the current routes and, unless another copy owns it, the deprecated alias.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        $this->register_routes_in(self::NAMESPACE_CURRENT, self::BASE);

        if (Legacy::owned_elsewhere('Surfaces\\Rest')) {
            return;
        }

        $this->register_routes_in(self::NAMESPACE_LEGACY, '');
    }

    /**
     * Register the five routes under one namespace.
     *
     * @param string $namespace REST namespace.
     * @param string $base      Route prefix ('' or '/self-test').
     *
     * @return void
     *
     * @since 1.0.0
     */
    private function register_routes_in($namespace, $base)
    {
        $ids_arg = [
            'ids' => [
                'description'       => __('Case ids from the registered manifests. Empty for every case.', Config::TEXT_DOMAIN),
                'type'              => 'array',
                'items'             => ['type' => 'string'],
                'required'          => false,
                'default'           => [],
                'sanitize_callback' => [$this, 'sanitize_ids'],
                'validate_callback' => function ($value) {
                    return is_array($value) || is_string($value);
                },
            ],
            'plugin' => [
                'description'       => __('Restrict to the cases of one registered plugin slug.', Config::TEXT_DOMAIN),
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];

        register_rest_route($namespace, $base . '/cases', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'cases'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route($namespace, $base . '/preview', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'preview'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route($namespace, $base . '/run', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'run'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg + [
                'cleanup' => [
                    'description'       => __('Remove the test data right after each case instead of keeping it for "Remove test data".', Config::TEXT_DOMAIN),
                    'type'              => 'boolean',
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                    'validate_callback' => 'rest_is_boolean',
                ],
            ],
        ]);

        register_rest_route($namespace, $base . '/cleanup', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cleanup'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route($namespace, $base . '/report', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'report'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Accept `a,b` or ['a','b'].
     *
     * @param mixed $value Raw value.
     *
     * @return string[]
     */
    public function sanitize_ids($value)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map('sanitize_key', (array) $value)));
    }

    /** @return true|\WP_Error */
    public function check_permission()
    {
        if (!Config::ui_enabled()) {
            return new \WP_Error('mmwp_self_test_disabled', __('The self-test dashboard is not available on production.', Config::TEXT_DOMAIN), ['status' => 404]);
        }

        if (!current_user_can(Config::capability())) {
            return new \WP_Error('mmwp_self_test_forbidden', __('You are not allowed to run the self-tests.', Config::TEXT_DOMAIN), ['status' => is_user_logged_in() ? 403 : 401]);
        }

        return true;
    }

    /**
     * Wrap a payload as a response, flagging calls to the deprecated namespace.
     *
     * @param \WP_REST_Request $request Current request.
     * @param mixed            $payload Runner result.
     *
     * @return \WP_REST_Response|\WP_Error
     *
     * @since 1.0.0
     */
    private function respond(\WP_REST_Request $request, $payload)
    {
        $response = rest_ensure_response($payload);
        if (!is_wp_error($response) && strpos($request->get_route(), '/' . self::NAMESPACE_LEGACY) === 0) {
            $response->header('Deprecation', 'true');
            $response->header('Link', '</' . rest_get_url_prefix() . '/' . self::NAMESPACE_CURRENT . self::BASE . '>; rel="successor-version"');
        }

        return $response;
    }

    public function cases(\WP_REST_Request $request)
    {
        return $this->respond($request, $this->runner->cases((array) $request->get_param('ids'), (string) $request->get_param('plugin')));
    }

    public function preview(\WP_REST_Request $request)
    {
        return $this->respond($request, $this->runner->preview((array) $request->get_param('ids'), (string) $request->get_param('plugin')));
    }

    public function run(\WP_REST_Request $request)
    {
        return $this->respond($request, $this->runner->run((array) $request->get_param('ids'), (bool) $request->get_param('cleanup'), (string) $request->get_param('plugin')));
    }

    public function cleanup(\WP_REST_Request $request)
    {
        return $this->respond($request, $this->runner->cleanup((array) $request->get_param('ids')));
    }

    public function report(\WP_REST_Request $request)
    {
        $report = $this->runner->report();

        return $this->respond($request, $report ?: ['results' => [], 'summary' => null, 'pending' => $this->runner->pending_ids()]);
    }
}
