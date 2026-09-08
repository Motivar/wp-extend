<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST routes behind the self-test dashboard. Thin wrappers over
 * EWP_Self_Test_Runner; only registered when the dashboard is enabled
 * (WP_DEBUG) and always limited to EWP_Self_Test::capability().
 *
 * Routes (namespace extend-wp/v1):
 *   GET  /self-test/cases            → runner->cases()
 *   GET  /self-test/preview?ids=a,b  → runner->preview()
 *   POST /self-test/run              → runner->run()   {ids: [], cleanup: bool}
 *   POST /self-test/cleanup          → runner->cleanup() {ids: []}
 *   GET  /self-test/report           → runner->report()
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test_REST
{
    /** @var string */
    private static $namespace = 'extend-wp/v1';

    /** @var EWP_Self_Test_Runner */
    private $runner;

    public function __construct(EWP_Self_Test_Runner $runner)
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
                'description'       => __('Case ids from manifest.json. Empty for every case.', 'extend-wp'),
                'type'              => 'array',
                'items'             => ['type' => 'string'],
                'required'          => false,
                'default'           => [],
                'sanitize_callback' => [$this, 'sanitize_ids'],
                'validate_callback' => function ($value) {
                    return is_array($value) || is_string($value);
                },
            ],
        ];

        register_rest_route(self::$namespace, '/self-test/cases', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'cases'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/self-test/preview', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'preview'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/self-test/run', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'run'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg + [
                'cleanup' => [
                    'description'       => __('Remove the test data right after each case instead of keeping it for "Remove test data".', 'extend-wp'),
                    'type'              => 'boolean',
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                    'validate_callback' => 'rest_is_boolean',
                ],
            ],
        ]);

        register_rest_route(self::$namespace, '/self-test/cleanup', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cleanup'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $ids_arg,
        ]);

        register_rest_route(self::$namespace, '/self-test/report', [
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
        if (!EWP_Self_Test::ui_enabled()) {
            return new \WP_Error('ewp_self_test_disabled', __('The self-test dashboard is only available when WP_DEBUG is on.', 'extend-wp'), ['status' => 404]);
        }

        if (!current_user_can(EWP_Self_Test::capability())) {
            return new \WP_Error('ewp_self_test_forbidden', __('You are not allowed to run the self-tests.', 'extend-wp'), ['status' => 403]);
        }

        return true;
    }

    public function cases(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->cases((array) $request->get_param('ids')));
    }

    public function preview(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->preview((array) $request->get_param('ids')));
    }

    public function run(\WP_REST_Request $request)
    {
        return rest_ensure_response($this->runner->run((array) $request->get_param('ids'), (bool) $request->get_param('cleanup')));
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
