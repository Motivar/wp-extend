<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EWP REST Health — Bootstrapper
 *
 * Registers the admin options page (super-admin only), dynamic assets
 * (Swagger UI CDN + custom shell), and EWP Logger types.
 *
 * Page fields are PHP-registered via get_page_fields() and rendered by AWM's
 * awm_show_content. The plugin multiselect is built server-side so developers
 * can modify it via the ewp_rh_page_fields filter.
 *
 * @package EWP\RestHealth
 * @since   1.0.0
 */
class EWP_REST_Health
{
    const ASSET_VERSION = '1.0.0';

    public function __construct()
    {
        add_filter('awm_add_options_boxes_filter', [$this, 'register_options_page'], 200);
        add_filter('ewp_register_dynamic_assets',  [$this, 'register_assets']);
        add_action('ewp_logger_initialized',        [$this, 'register_log_types']);

        // Register the live traffic capture hook only when monitoring is active.
        // Checked on rest_api_init (runs once per REST request) to avoid a DB
        // query on every non-REST page load.
        add_action('rest_api_init', [$this, 'maybe_register_capture_hook']);

        new EWP_REST_Health_Controller();
    }

    /**
     * Register the rest_post_dispatch hook only if monitoring is currently active.
     * Keeps the hook absent on all other requests (zero cost when off).
     */
    public function maybe_register_capture_hook(): void
    {
        if (get_option('ewp_rh_monitor_active', false)) {
            add_filter('rest_post_dispatch', [$this, 'capture_dispatch'], 10, 3);
        }
    }

    /**
     * Live traffic capture: fires after every REST request while monitoring is on.
     *
     * - Skips our own health-check endpoints (avoid infinite recursion)
     * - Auto-stops after 10 minutes
     * - Stores last payload per route+method in a single option
     * - Logs every capture to ewp_log for audit/download
     *
     * @param  WP_HTTP_Response $result
     * @param  WP_REST_Server   $server
     * @param  WP_REST_Request  $request
     * @return WP_HTTP_Response Unchanged
     */
    public function capture_dispatch($result, $server, $request)
    {
        $route = $request->get_route();

        // Never capture our own endpoints
        if (str_contains($route, '/rest-health/')) {
            return $result;
        }

        // Auto-stop after 10 minutes
        $started = (int) get_option('ewp_rh_monitor_started', 0);
        if ((time() - $started) > 600) {
            update_option('ewp_rh_monitor_active', false, false);
            return $result;
        }

        // Only capture routes in the monitored namespaces
        $monitored_ns = (array) get_option('ewp_rh_monitor_namespaces', []);
        $ns           = $this->ns_from_route($route);
        if ($ns && !in_array($ns, $monitored_ns, true)) {
            return $result;
        }

        $method  = $request->get_method();
        $payload = [
            'route'        => $route,
            'method'       => $method,
            'query_params' => $request->get_query_params(),
            'body_params'  => $request->get_body_params(),
            'url_params'   => $request->get_url_params(),
            'status'       => $result->get_status(),
            'response'     => $this->truncate_response($result->get_data()),
            'timestamp'    => current_time('mysql'),
        ];

        // Log to EWP Logger so data is downloadable from the logger viewer
        if (function_exists('ewp_log')) {
            ewp_log(
                'ewp-rest-health',
                'rest_monitored',
                sprintf('[MONITOR] %s %s → %d', $method, $route, $payload['status']),
                $payload,
                'developer',
                '',
                $payload['status'] >= 400 ? 0 : 1
            );
        }

        // Store last captured payload per route+method (overwrites previous)
        $all                             = (array) get_option('ewp_rh_captured_payloads', []);
        $all[$route . ':' . $method]     = $payload;
        update_option('ewp_rh_captured_payloads', $all, false);

        // Increment counter for UI feedback
        update_option('ewp_rh_monitor_count', ((int) get_option('ewp_rh_monitor_count', 0)) + 1, false);

        return $result;
    }

    /**
     * Truncate a response body so stored payloads don't bloat wp_options.
     * Arrays are capped at 50 top-level items; strings at 4KB.
     */
    private function truncate_response($data)
    {
        if (is_array($data)) {
            if (count($data) > 50) {
                return array_slice($data, 0, 50, true) + ['_truncated' => true, '_original_count' => count($data)];
            }
            return $data;
        }
        if (is_string($data) && strlen($data) > 4096) {
            return substr($data, 0, 4096) . '…[truncated]';
        }
        return $data;
    }

    /** Extract a REST namespace from a route path (e.g. /filox/v1/rates → filox/v1). */
    private function ns_from_route(string $route): string
    {
        $parts = explode('/', ltrim($route, '/'), 3);
        if (count($parts) >= 2 && preg_match('/^v\d+$/', $parts[1])) {
            return $parts[0] . '/' . $parts[1];
        }
        return $parts[0] ?? '';
    }

    // -------------------------------------------------------------------------
    // Options page
    // -------------------------------------------------------------------------

    /**
     * Register REST API Health as a standalone top-level admin menu,
     * the same pattern used by the EWP Logger page.
     */
    public function register_options_page(array $pages): array
    {
        $pages['ewp-rest-health'] = [
            'title'       => __('REST API Health', 'extend-wp'),
            'parent'      => false,                // top-level standalone menu
            'icon'        => 'dashicons-rest-api',
            'cap'         => 'manage_options',
            'order'       => 900,
            'hide_submit' => true,
            'callback'    => [$this, 'get_page_fields'],
        ];
        return $pages;
    }

    /**
     * Return AWM fields for the page.
     *
     * Two fields rendered by awm_show_content:
     *  1. ewp_rh_plugins — PHP-built <select multiple> of active plugins
     *  2. ewp_rh_panel   — Swagger UI mount + toolbar buttons + history panel
     *
     * Extensible via the ewp_rh_page_fields filter.
     *
     * @return array
     */
    public function get_page_fields(): array
    {
        if (!is_super_admin()) {
            return [
                'ewp_rh_restricted' => [
                    'case'  => 'html',
                    'value' => '<p class="ewp-rh-restricted">'
                        . esc_html__('Access restricted to super administrators.', 'extend-wp')
                        . '</p>',
                ],
            ];
        }

        return apply_filters('ewp_rh_page_fields', [
            'ewp_rh_plugins' => [
                'case'       => 'html',
                'label'      => __('Plugins', 'extend-wp'),
                'show_label' => true,
                'value'      => $this->render_plugin_selector(),
                'order'      => 10,
            ],
            'ewp_rh_panel' => [
                'case'  => 'html',
                'value' => $this->render_panel(),
                'order' => 20,
            ],
        ]);
    }

    /**
     * Build the plugin multiselect HTML.
     *
     * Options are generated server-side from active WP plugins, with their
     * inferred REST namespaces shown as labels. JavaScript only reads the
     * selected values — it never recreates this element.
     */
    private function render_plugin_selector(): string
    {
        $discovery = new EWP_REST_Health_Discovery();
        $plugins   = $discovery->get_active_plugins();
        $ns_map    = $discovery->build_namespace_plugin_map();

        $options = '';
        foreach ($plugins as $path => $meta) {
            $namespaces = array_keys(array_filter($ns_map, fn($p) => $p === $path));
            $label      = $meta['name'];
            if (!empty($namespaces)) {
                $label .= ' [' . implode(', ', $namespaces) . ']';
            }
            $options .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr($path),
                esc_html($label)
            );
        }

        return sprintf(
            '<p class="ewp-rh-selector-hint">%s</p>'
            . '<select id="ewp-rh-plugin-select" class="ewp-rh-plugin-select" multiple size="6">%s</select>',
            esc_html__('Hold Ctrl / ⌘ to select multiple plugins.', 'extend-wp'),
            $options
        );
    }

    /**
     * Build the controls bar (method filters + toolbar), health-status monitor,
     * Swagger UI mount, and history panel.
     * The .ewp-rest-health-wrap element carries data-nonce / data-rest-url for JS.
     */
    private function render_panel(): string
    {
        $nonce    = esc_attr(wp_create_nonce('wp_rest'));
        $rest_url = esc_attr(rest_url('extend-wp/v1/rest-health/'));

        // Method filter checkboxes — all checked by default
        $method_cbs = '';
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            $method_cbs .= '<label class="ewp-rh-method-label ewp-rh-method-' . strtolower($m) . '">'
                . '<input type="checkbox" class="ewp-rh-method-cb" value="' . $m . '" checked> ' . $m
                . '</label>';
        }

        return '<div class="ewp-rest-health-wrap" data-nonce="' . $nonce . '" data-rest-url="' . $rest_url . '">'

            // ── Row 1: method filters + search + refresh ──────────────────
            . '<div class="ewp-rh-controls">'
            . '<div class="ewp-rh-method-filters">'
            . '<span class="ewp-rh-filter-label">' . esc_html__('Methods:', 'extend-wp') . '</span>'
            . $method_cbs
            . '</div>'
            . '<div class="ewp-rh-search-wrap">'
            . '<input id="ewp-rh-search" type="search" class="regular-text" placeholder="'
            . esc_attr__('Filter routes…', 'extend-wp') . '">'
            . '</div>'
            . '<div class="ewp-rh-toolbar-actions">'
            . '<button class="button button-secondary ewp-rh-refresh" type="button">&#8635; ' . esc_html__('Refresh', 'extend-wp') . '</button>'
            . '</div>'
            . '</div>'

            // ── Row 2: live monitor bar ───────────────────────────────────
            . '<div class="ewp-rh-monitor-bar">'
            . '<strong class="ewp-rh-monitor-label">' . esc_html__('Monitor', 'extend-wp') . '</strong>'
            . '<button class="button button-primary ewp-rh-monitor-start" type="button">'
            . esc_html__('Start', 'extend-wp') . '</button>'
            . '<button class="button ewp-rh-monitor-stop" type="button" hidden>'
            . esc_html__('Stop', 'extend-wp') . '</button>'
            . '<span class="ewp-rh-monitor-status"></span>'
            . '<span class="ewp-rh-monitor-countdown" hidden></span>'
            . '</div>'

            // ── Captured payloads panel (populated while/after monitoring) ─
            . '<div class="ewp-rh-payloads-panel" hidden>'
            . '<div class="ewp-rh-payloads-header">'
            . '<strong>' . esc_html__('Captured Payloads', 'extend-wp') . '</strong>'
            . '<button class="button-link ewp-rh-payloads-clear" type="button">'
            . esc_html__('Clear all', 'extend-wp') . '</button>'
            . '</div>'
            . '<div class="ewp-rh-payloads-list"></div>'
            . '</div>'

            // ── Swagger UI mount ───────────────────────────────────────────
            . '<div class="ewp-rh-swagger-wrap">'
            . '<div id="ewp-rh-swagger-panel"><p class="ewp-rh-hint">'
            . esc_html__('Select a plugin above to inspect its REST endpoints.', 'extend-wp')
            . '</p></div>'
            . '</div>'

            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Dynamic assets
    // -------------------------------------------------------------------------

    /**
     * Register Swagger UI (CDN) + custom shell JS/CSS.
     * Assets load only when .ewp-rest-health-wrap is in the DOM.
     */
    public function register_assets(array $assets): array
    {
        $selector = '.ewp-rest-health-wrap';

        // Swagger UI from CDN — same pattern as Chart.js CDN example
        $assets[] = [
            'handle'   => 'swagger-ui-bundle',
            'selector' => $selector,
            'type'     => 'script',
            'src'      => 'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js',
            'version'  => '5',
            'context'  => 'admin',
        ];
        $assets[] = [
            'handle'   => 'swagger-ui-css',
            'selector' => $selector,
            'type'     => 'style',
            'src'      => 'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css',
            'version'  => '5',
            'context'  => 'admin',
        ];

        // Custom shell
        $assets[] = [
            'handle'    => 'ewp-rest-health',
            'selector'  => $selector,
            'type'      => 'script',
            'src'       => awm_url . 'assets/js/admin/ewp-rest-health.js',
            'version'   => self::ASSET_VERSION,
            'context'   => 'admin',
            'in_footer' => true,
            'defer'     => true,
            'localize'  => [
                'objectName' => 'ewpRestHealth',
                'data'       => [
                    'strings' => [
                        'selectPlugin' => __('Select a plugin above to inspect its REST endpoints.', 'extend-wp'),
                        'noEndpoints'  => __('No endpoints found for selected plugins.', 'extend-wp'),
                        'confirmBatch' => __("Proceed with these mutating endpoints?\n\n", 'extend-wp'),
                        'clearConfirm' => __('Clear history for this endpoint?', 'extend-wp'),
                        'swaggerError' => __('Swagger UI not loaded. Check your network connection.', 'extend-wp'),
                    ],
                ],
            ],
        ];

        $assets[] = [
            'handle'   => 'ewp-rest-health-css',
            'selector' => $selector,
            'type'     => 'style',
            'src'      => awm_url . 'assets/css/admin/ewp-rest-health.css',
            'version'  => self::ASSET_VERSION,
            'context'  => 'admin',
        ];

        return $assets;
    }

    // -------------------------------------------------------------------------
    // EWP Logger registration
    // -------------------------------------------------------------------------

    public function register_log_types(): void
    {
        if (!function_exists('ewp_register_log_owner')) {
            return;
        }

        ewp_register_log_owner('ewp-rest-health', __('REST API Health', 'extend-wp'));

        ewp_register_log_type('ewp-rest-health', 'rest_test_run',      'Endpoint Test',      'Single endpoint test executed via the health check page.');
        ewp_register_log_type('ewp-rest-health', 'rest_test_error',    'REST Test Error',    '5xx response received during an endpoint test.');
        ewp_register_log_type('ewp-rest-health', 'rest_missing_cb',    'Missing Callback',   'Endpoint permission callback is not callable.');
        ewp_register_log_type('ewp-rest-health', 'rest_batch_summary', 'Batch Test Summary', 'All-endpoints batch test completed.');
        ewp_register_log_type('ewp-rest-health', 'rest_monitored',     'Live Capture',       'Live REST traffic captured during active monitoring session.');
    }
}

/* Initialize */
new EWP_REST_Health();
