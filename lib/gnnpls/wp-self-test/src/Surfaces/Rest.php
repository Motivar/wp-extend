<?php

namespace Gnnpls\SelfTest\Surfaces;

use Gnnpls\SelfTest\Config;
use Gnnpls\SelfTest\Runner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST routes behind the self-test dashboard. Thin wrappers over Runner;
 * only registered when the dashboard is enabled (non-production) and always
 * limited to Config::capability().
 *
 * Routes (namespace mwp-self-test/v1):
 *   GET  /cases?ids=a,b&plugin=x   → runner->cases()
 *   GET  /preview?ids=a,b&plugin=x → runner->preview()
 *   POST /run                      → runner->run()   {ids: [], cleanup: bool, plugin: string}
 *   POST /cleanup                  → runner->cleanup() {ids: []}
 *   GET  /report                   → runner->report()
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */
final class Rest
{
    /** @var string */
    private static $namespace = 'mwp-self-test/v1';

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

    /** @return void */
    public function register_routes()
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

        register_rest_route(self::$namespace, '/cases', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'cases'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/preview', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'preview'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/run', [
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

        register_rest_route(self::$namespace, '/cleanup', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cleanup'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/report', [
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
            return new \WP_Error('mwp_self_test_disabled', __('The self-test dashboard is not available on production.', Config::TEXT_DOMAIN), ['status' => 404]);
        }

        if (!current_user_can(Config::capability())) {
            return new \WP_Error('mwp_self_test_forbidden', __('You are not allowed to run the self-tests.', Config::TEXT_DOMAIN), ['status' => is_user_logged_in() ? 403 : 401]);
        }

        return true;
    }

    public function cases(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->cases((array) $request->get_param('ids'), (string) $request->get_param('plugin')));
    }

    public function preview(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->preview((array) $request->get_param('ids'), (string) $request->get_param('plugin')));
    }

    public function run(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->run((array) $request->get_param('ids'), (bool) $request->get_param('cleanup'), (string) $request->get_param('plugin')));
    }

    public function cleanup(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->cleanup((array) $request->get_param('ids')));
    }

    public function report()
    {
        $report = $this->runner->report();

        return rest_ensure_response($report ?: ['results' => [], 'summary' => null, 'pending' => $this->runner->pending_ids()]);
    }
}
