<?php

namespace MammothWP\SelfTest\Surfaces;

use MammothWP\SelfTest\Browser\Login_Token;
use MammothWP\SelfTest\Browser\Menu_Inventory;
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
 *   GET  /self-test/admin-menu               → Menu_Inventory::current() (browser layer crawler)
 *   POST /self-test/browser-login            → exchanges a single-use login token for auth cookies {token: string}
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
     * Register the five routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        $namespace = self::NAMESPACE_CURRENT;
        $base      = self::BASE;

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
                'layer'   => [
                    'description'       => __('Run only the cases declaring this layer (rest, cli, ability, core, browser).', Config::TEXT_DOMAIN),
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => 'rest_validate_request_arg',
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

        register_rest_route($namespace, $base . '/admin-menu', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'admin_menu'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($namespace, $base . '/browser-login', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'browser_login'],
            'permission_callback' => [$this, 'check_login_token'],
            'args'                => [
                'token' => [
                    'description' => __('Single-use login token issued by the runner for the browser layer.', Config::TEXT_DOMAIN),
                    'type'        => 'string',
                    'required'    => true,
                ],
            ],
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
     * Wrap a payload as a response.
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
        return rest_ensure_response($payload);
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
        return $this->respond($request, $this->runner->run((array) $request->get_param('ids'), (bool) $request->get_param('cleanup'), (string) $request->get_param('plugin'), (string) $request->get_param('layer')));
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

    /**
     * The current user's admin pages for the browser crawler.
     *
     * @param \WP_REST_Request $request Current request.
     *
     * @return \WP_REST_Response|\WP_Error
     *
     * @since 2.1.0
     */
    public function admin_menu(\WP_REST_Request $request)
    {
        return $this->respond($request, Menu_Inventory::current());
    }

    /**
     * Permission of the login exchange: the token is the credential.
     *
     * @param \WP_REST_Request $request Current request.
     *
     * @return true|\WP_Error
     *
     * @since 2.1.0
     */
    public function check_login_token(\WP_REST_Request $request)
    {
        if (!Config::ui_enabled() || !Login_Token::is_valid((string) $request->get_param('token'))) {
            return new \WP_Error('mmwp_self_test_login_refused', __('Login token invalid, expired, used or not available here.', Config::TEXT_DOMAIN), ['status' => 403]);
        }

        return true;
    }

    /**
     * Redeem a login token once and set the auth cookies of its user.
     * The session it creates is recorded so the runner can revoke it.
     *
     * @param \WP_REST_Request $request Current request.
     *
     * @return \WP_REST_Response|\WP_Error
     *
     * @since 2.1.0
     */
    public function browser_login(\WP_REST_Request $request)
    {
        $token   = (string) $request->get_param('token');
        $user_id = Login_Token::consume($token);

        if ($user_id === 0 || !user_can($user_id, Config::capability())) {
            return new \WP_Error('mmwp_self_test_login_refused', __('Login token invalid, expired, used or not available here.', Config::TEXT_DOMAIN), ['status' => 403]);
        }

        add_action('set_logged_in_cookie', function ($cookie, $expire, $expiration, $uid, $scheme, $session = '') use ($token) {
            Login_Token::attach_session($token, $session);
        }, 10, 6);

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false);

        return $this->respond($request, ['ok' => true, 'user_id' => $user_id]);
    }
}
