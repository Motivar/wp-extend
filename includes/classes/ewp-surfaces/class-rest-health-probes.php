<?php

namespace EWP\Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The interactive REST-health features behind the admin page: OpenAPI
 * export, single and batch probes, per-route history, live monitoring
 * and the user's page preferences.
 *
 * Every method takes plain PHP values and returns arrays, so the
 * Rest_Health_Probes_Resource can project them; they are REST-only by
 * declaration because each one drives a browser interaction.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Rest_Health_Probes
{
    /** Seconds a monitoring session stays active before it stops itself. */
    const MONITOR_WINDOW = 600;

    /** @var \EWP_REST_Health_Discovery */
    private $discovery;

    /** @var \EWP_REST_Health_Runner */
    private $runner;

    /** @var \EWP_REST_Health_OpenAPI */
    private $openapi;

    /**
     * @param \EWP_REST_Health_Discovery|null $discovery Discovery service.
     * @param \EWP_REST_Health_Runner|null    $runner    Probe runner.
     * @param \EWP_REST_Health_OpenAPI|null   $openapi   OpenAPI generator.
     */
    public function __construct($discovery = null, $runner = null, $openapi = null)
    {
        $this->discovery = $discovery ?: new \EWP_REST_Health_Discovery();
        $this->runner    = $runner ?: new \EWP_REST_Health_Runner();
        $this->openapi   = $openapi ?: new \EWP_REST_Health_OpenAPI($this->runner);
    }

    /**
     * OpenAPI 3.0 document for the routes of the given plugins.
     *
     * @param string[] $plugins Plugin paths; empty = every active plugin.
     * @param string[] $methods HTTP methods to include; empty = all.
     *
     * @return array OpenAPI document.
     *
     * @since 1.5.0
     */
    public function openapi(array $plugins = [], array $methods = [])
    {
        if ($plugins === []) {
            $plugins = array_keys($this->discovery->get_active_plugins());
        }

        $routes = $this->discovery->get_routes_for_plugins($plugins);

        return $this->openapi->generate($routes, get_current_user_id(), array_map('strtoupper', $methods));
    }

    /**
     * Probe one route as the current user.
     *
     * @param string $route  Route, e.g. `/wp/v2/posts`.
     * @param string $method HTTP method.
     * @param array  $params Request parameters.
     *
     * @return array Probe result (status, duration, body excerpt).
     *
     * @since 1.5.0
     */
    public function test($route, $method = 'GET', array $params = [])
    {
        return $this->runner->run_single((string) $route, strtoupper((string) $method), $params, get_current_user_id());
    }

    /**
     * Probe several routes in one go.
     *
     * @param array $items List of `{route, method}` objects.
     *
     * @return array Batch summary with one result per item.
     *
     * @since 1.5.0
     */
    public function batch(array $items)
    {
        return $this->runner->run_batch($items, get_current_user_id());
    }

    /**
     * Probe history of one route for the current user.
     *
     * @param string $route  Route.
     * @param string $method HTTP method.
     *
     * @return array History entries, newest first.
     *
     * @since 1.5.0
     */
    public function history($route, $method = 'GET')
    {
        return $this->runner->get_history((string) $route, strtoupper((string) $method), get_current_user_id());
    }

    /**
     * Forget the probe history of one route for the current user.
     *
     * @param string $route  Route.
     * @param string $method HTTP method.
     *
     * @return array `{cleared: true}`.
     *
     * @since 1.5.0
     */
    public function clear_history($route, $method = 'GET')
    {
        $this->runner->clear_history((string) $route, strtoupper((string) $method), get_current_user_id());

        return ['cleared' => true];
    }

    /**
     * Live-monitor state; stops an expired session as a side effect.
     *
     * @return array `{active, started, remaining_sec, captured_count, namespaces}`.
     *
     * @since 1.5.0
     */
    public function monitor()
    {
        $active    = (bool) get_option('ewp_rh_monitor_active', false);
        $started   = (int) get_option('ewp_rh_monitor_started', 0);
        $remaining = $active ? max(0, self::MONITOR_WINDOW - (time() - $started)) : 0;

        if ($active && $remaining === 0) {
            update_option('ewp_rh_monitor_active', false, false);
            $active = false;
        }

        return [
            'active'         => $active,
            'started'        => $started ? wp_date('Y-m-d H:i:s', $started) : '',
            'remaining_sec'  => $remaining,
            'captured_count' => (int) get_option('ewp_rh_monitor_count', 0),
            'namespaces'     => (array) get_option('ewp_rh_monitor_namespaces', []),
        ];
    }

    /**
     * Start or stop live monitoring.
     *
     * @param string   $action  `start` or `stop`.
     * @param string[] $plugins Plugin paths whose namespaces to capture (start only).
     *
     * @return array The monitor state after the change.
     *
     * @since 1.5.0
     */
    public function update_monitor($action, array $plugins = [])
    {
        if ($action !== 'start') {
            update_option('ewp_rh_monitor_active', false, false);
            return $this->monitor();
        }

        $map        = $this->discovery->build_namespace_plugin_map();
        $namespaces = array_keys(array_filter($map, function ($plugin) use ($plugins) {
            return in_array($plugin, $plugins, true);
        }));

        update_option('ewp_rh_monitor_active', true, false);
        update_option('ewp_rh_monitor_started', time(), false);
        update_option('ewp_rh_monitor_count', 0, false);
        update_option('ewp_rh_monitor_namespaces', $namespaces, false);

        return $this->monitor();
    }

    /**
     * Payloads captured by the live monitor.
     *
     * @return array Captured payloads keyed by route and method.
     *
     * @since 1.5.0
     */
    public function payloads()
    {
        return \EWP_REST_Health::read_payloads();
    }

    /**
     * Discard captured payloads and reset the counter.
     *
     * @return array `{cleared: true}`.
     *
     * @since 1.5.0
     */
    public function clear_payloads()
    {
        $path = \EWP_REST_Health::get_payloads_path();
        if (file_exists($path)) {
            @unlink($path);
        }
        update_option('ewp_rh_monitor_count', 0, false);

        return ['cleared' => true];
    }

    /**
     * The current user's REST-health page preferences.
     *
     * @return array `{plugins: string[], methods: string[]}` or empty.
     *
     * @since 1.5.0
     */
    public function preferences()
    {
        $prefs = get_user_meta(get_current_user_id(), 'ewp_rh_preferences', true);

        return is_array($prefs) ? $prefs : [];
    }

    /**
     * Store the current user's REST-health page preferences.
     *
     * @param string[] $plugins Selected plugin paths.
     * @param string[] $methods Selected HTTP methods.
     *
     * @return array `{saved: true}`.
     *
     * @since 1.5.0
     */
    public function save_preferences(array $plugins = [], array $methods = [])
    {
        update_user_meta(get_current_user_id(), 'ewp_rh_preferences', [
            'plugins' => array_map('sanitize_text_field', $plugins),
            'methods' => array_map('sanitize_text_field', $methods),
        ]);

        return ['saved' => true];
    }
}
