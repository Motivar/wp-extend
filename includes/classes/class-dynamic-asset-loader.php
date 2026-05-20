<?php

/**
 * Dynamic Asset Loader Class
 * 
 * This class provides a system for dynamically loading scripts and styles
 * based on the presence of specific DOM elements on the page.
 * 
 * Developers can register their assets through filters, and the system will
 * only load them when their corresponding DOM selectors are detected.
 * 
 * @package ExtendWP
 * @since 1.0.0
 */

namespace EWP\DynamicAssets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Dynamic_Asset_Loader
 * 
 * Manages dynamic loading of scripts and styles based on DOM element presence.
 */
class Dynamic_Asset_Loader
{
    /**
     * Singleton instance
     * 
     * @var Dynamic_Asset_Loader|null
     */
    private static $instance = null;

    /**
     * Registered assets configuration
     * 
     * @var array
     */
    private static $registered_assets = array();

    /**
     * Script handle for the loader
     * 
     * @var string
     */
    private const LOADER_HANDLE = 'ewp-dynamic-asset-loader';

    /**
     * Script version
     * 
     * @var string
     */
    private const VERSION = '1.0.6';

    /**
     * Get singleton instance
     * 
     * @return Dynamic_Asset_Loader
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor for singleton
     * 
     * @return void
     */
    private function __construct()
    {
        // Delay initialization until WordPress is fully loaded
        add_action('init', array($this, 'init_hooks'), 1);
    }

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    public function init_hooks()
    {
        $assets = $this->get_registered_assets();

        if (empty($assets)) {
            return;
        }

        add_action('init', array($this, 'register_scripts'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_loader'), 999);
        add_action('wp_head', array($this, 'add_resource_hints'), 1);
        add_action('wp_head', array($this, 'add_preload_links'), 2);
        add_action('wp_head', array($this, 'add_critical_css'), 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_loader'), 1);
    }


    /**
     * Register the main loader script and individual asset scripts
     * 
     * Registers the dynamic asset loader script and all individual asset handles
     * managed by get_registered_assets(). This allows developers to enqueue
     * specific asset scripts independently if needed for extra flexibility.
     * 
     * @return void
     */
    public function register_scripts()
    {
        wp_register_script(
            self::LOADER_HANDLE,
            awm_url . 'assets/js/class-dynamic-asset-loader.js',
            array(),
            self::VERSION,
            true
        );

        $assets = $this->get_registered_assets();

        if (empty($assets)) {
            return;
        }

        foreach ($assets as $asset) {
            if ($asset['type'] === 'script') {
                wp_register_script(
                    $asset['handle'],
                    $asset['src'],
                    $asset['dependencies'],
                    $asset['version'],
                    $asset['in_footer']
                );
            } elseif ($asset['type'] === 'style') {
                wp_register_style(
                    $asset['handle'],
                    $asset['src'],
                    $asset['dependencies'],
                    $asset['version'],
                    $asset['media']
                );
            }
        }
    }

    /**
     * Enqueue the loader script with localized asset configuration
     * 
     * @return void
     */
    public function enqueue_loader()
    {
        wp_enqueue_script(self::LOADER_HANDLE);
        $assets = $this->get_registered_assets();

        if (empty($assets)) {
            return;
        }

        // Filter assets based on current context
        $current_context = is_admin() ? 'admin' : 'frontend';
        $filtered_assets = array_filter($assets, function ($asset) use ($current_context) {
            return $asset['context'] === 'both' || $asset['context'] === $current_context;
        });

        if (empty($filtered_assets)) {
            return;
        }



        wp_localize_script(
            self::LOADER_HANDLE,
            'ewpDynamicAssets',
            array(
                'assets' => array_values($filtered_assets),
                'nonce' => wp_create_nonce('ewp_dynamic_assets'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'debug' => apply_filters('ewp_dynamic_assets_debug_filter', defined('WP_DEBUG') && WP_DEBUG),
                'context' => $current_context,
                'performance' => array(
                    'lazyLoad' => apply_filters('ewp_dynamic_assets_lazy_load', true),
                    'intersectionThreshold' => apply_filters('ewp_dynamic_assets_intersection_threshold', 0.1),
                    'rootMargin' => apply_filters('ewp_dynamic_assets_root_margin', '0px')
                )
            )
        );

        /**
         * Fires after dynamic asset loader is enqueued
         * 
         * @param array $assets Registered assets configuration
         */
        do_action('ewp_dynamic_asset_loader_enqueued', $assets);
    }

    /**
     * Get all registered assets through filter
     * 
     * Developers can hook into this filter to register their assets.
     * 
     * @return array Array of registered assets
     * 
     * @example
     * add_filter('ewp_register_dynamic_assets', function($assets) {
     *     $assets[] = array(
     *         'handle' => 'my-custom-script',
     *         'selector' => '.my-custom-element',
     *         'type' => 'script',
     *         'src' => plugin_dir_url(__FILE__) . 'js/my-script.js',
     *         'version' => '1.0.0',
     *         'dependencies' => array('jquery'),
     *         'in_footer' => true,
     *         'localize' => array(
     *             'objectName' => 'myScriptData',
     *             'data' => array(
     *                 'ajaxUrl' => admin_url('admin-ajax.php'),
     *                 'nonce' => wp_create_nonce('my-nonce')
     *             )
     *         )
     *     );
     *     return $assets;
     * });
     */
    private function get_registered_assets()
    {
        if (!empty(self::$registered_assets)) {
            return self::$registered_assets;
        }

        /**
         * Filter to register dynamic assets
         * 
         * @param array $assets Empty array to be populated with asset configurations
         * 
         * Each asset should have the following structure:
         * - handle (string, required): Unique asset identifier
         * - selector (string, required): CSS selector to check for DOM element presence
         * - type (string, required): 'script' or 'style'
         * - src (string, required): URL to the asset file
         * - version (string, optional): Asset version for cache busting
         * - context (string, optional): Where to load - 'frontend', 'admin', or 'both' (default: 'both')
         * - dependencies (array, optional): Array of dependency handles (scripts only)
         * - in_footer (bool, optional): Load script in footer (scripts only, default: true)
         * - media (string, optional): Media type for styles (styles only, default: 'all')
         * - localize (array, optional): Localization data (scripts only)
         *   - objectName (string): JavaScript object name
         *   - data (array): Data to pass to JavaScript
         * - module (bool, optional): Load as ES6 module (scripts only, default: false)
         * - async (bool, optional): Load script asynchronously (scripts only)
         * - defer (bool, optional): Defer script execution (scripts only)
         * - preload (bool, optional): Add preload link for faster loading
         * - lazy (bool, optional): Use Intersection Observer for lazy loading
         * - critical (bool, optional): Mark as critical (loads immediately)
         * - critical_css (string, optional): Inline critical CSS content (styles only)
         * - critical_src (string, optional): URL to external critical CSS file to inline (styles only)
         * - critical_conditions (array, optional): Conditions for when to inline critical CSS (styles only)
         * - minify_critical (bool, optional): Minify critical CSS (styles only, default: true)
         * - inline_css (string, optional): Inline CSS to inject dynamically (styles only)
         * - resource_hints (array, optional): Array of resource hints (preconnect, dns-prefetch)
         */
        $assets = apply_filters('ewp_register_dynamic_assets', array());

        self::$registered_assets = $this->validate_assets($assets);

        return self::$registered_assets;
    }

    /**
     * Validate and sanitize registered assets
     * 
     * @param array $assets Raw assets array
     * @return array Validated assets array
     */
    private function validate_assets($assets)
    {
        if (!is_array($assets)) {
            return array();
        }

        $validated = array();

        foreach ($assets as $asset) {
            if (!$this->is_valid_asset($asset)) {
                continue;
            }

            $validated[] = $this->sanitize_asset($asset);
        }
        return $validated;
    }

    /**
     * Check if asset configuration is valid
     * 
     * @param mixed $asset Asset configuration
     * @return bool True if valid, false otherwise
     */
    private function is_valid_asset($asset)
    {
        if (!is_array($asset)) {
            return false;
        }

        $required_keys = array('handle', 'selector', 'type', 'src');

        foreach ($required_keys as $key) {
            if (!isset($asset[$key]) || empty($asset[$key])) {
                return false;
            }
        }

        if (!in_array($asset['type'], array('script', 'style'), true)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize asset configuration
     * 
     * @param array $asset Raw asset configuration
     * @return array Sanitized asset configuration
     */
    private function sanitize_asset($asset)
    {
        // Handle selector - can be string or array of strings
        $selector = $asset['selector'];
        if (is_array($selector)) {
            $selector = array_map('sanitize_text_field', $selector);
        } else {
            $selector = sanitize_text_field($selector);
        }

        $sanitized = array(
            'handle' => sanitize_key($asset['handle']),
            'selector' => $selector,
            'type' => sanitize_key($asset['type']),
            'src' => esc_url($asset['src']),
            'version' => isset($asset['version']) ? sanitize_text_field($asset['version']) : self::VERSION,
            'context' => isset($asset['context']) ? sanitize_key($asset['context']) : 'both',
        );

        if (!in_array($sanitized['context'], array('frontend', 'admin', 'both'), true)) {
            $sanitized['context'] = 'both';
        }

        if ($asset['type'] === 'script') {
            $sanitized['dependencies'] = isset($asset['dependencies']) && is_array($asset['dependencies'])
                ? array_map('sanitize_key', $asset['dependencies'])
                : array();

            $sanitized['in_footer'] = isset($asset['in_footer']) ? (bool) $asset['in_footer'] : true;
            $sanitized['module'] = isset($asset['module']) ? (bool) $asset['module'] : false;
            $sanitized['async'] = isset($asset['async']) ? (bool) $asset['async'] : false;
            $sanitized['defer'] = isset($asset['defer']) ? (bool) $asset['defer'] : false;

            if (isset($asset['localize']) && is_array($asset['localize'])) {
                $sanitized['localize'] = $this->sanitize_localize_data($asset['localize']);
            }
        }

        if ($asset['type'] === 'style') {
            $sanitized['media'] = isset($asset['media']) ? sanitize_text_field($asset['media']) : 'all';
            $sanitized['dependencies'] = isset($asset['dependencies']) && is_array($asset['dependencies'])
                ? array_map('sanitize_key', $asset['dependencies'])
                : array();

            if (isset($asset['critical_css']) && !empty($asset['critical_css'])) {
                $sanitized['critical_css'] = wp_strip_all_tags($asset['critical_css']);
            }

            // Support critical CSS from external file
            if (isset($asset['critical_src']) && !empty($asset['critical_src'])) {
                $sanitized['critical_src'] = esc_url($asset['critical_src']);
            }

            // Support inline CSS injection
            if (isset($asset['inline_css']) && !empty($asset['inline_css'])) {
                $sanitized['inline_css'] = wp_strip_all_tags($asset['inline_css']);
            }

            // Critical CSS conditions (when to inline)
            if (isset($asset['critical_conditions']) && is_array($asset['critical_conditions'])) {
                $sanitized['critical_conditions'] = $this->sanitize_critical_conditions($asset['critical_conditions']);
            }

            // Minify critical CSS option
            $sanitized['minify_critical'] = isset($asset['minify_critical']) ? (bool) $asset['minify_critical'] : true;
        }

        $sanitized['preload'] = isset($asset['preload']) ? (bool) $asset['preload'] : false;
        $sanitized['lazy'] = isset($asset['lazy']) ? (bool) $asset['lazy'] : false;
        $sanitized['critical'] = isset($asset['critical']) ? (bool) $asset['critical'] : false;

        if (isset($asset['resource_hints']) && is_array($asset['resource_hints'])) {
            $sanitized['resource_hints'] = array();
            foreach ($asset['resource_hints'] as $hint_type => $domains) {
                if (in_array($hint_type, array('preconnect', 'dns-prefetch'), true) && is_array($domains)) {
                    $sanitized['resource_hints'][$hint_type] = array_map('esc_url', $domains);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize localization data
     * 
     * @param array $localize Raw localization data
     * @return array|null Sanitized localization data or null if invalid
     */
    private function sanitize_localize_data($localize)
    {
        if (!isset($localize['objectName']) || !isset($localize['data'])) {
            return null;
        }

        if (!is_array($localize['data'])) {
            return null;
        }

        // Use sanitize_text_field instead of sanitize_key to preserve camelCase
        return array(
            'objectName' => sanitize_text_field($localize['objectName']),
            'data' => $this->sanitize_localize_data_recursive($localize['data'])
        );
    }

    /**
     * Sanitize critical CSS conditions
     * 
     * @param array $conditions Raw conditions array
     * @return array Sanitized conditions
     */
    private function sanitize_critical_conditions($conditions)
    {
        $sanitized = array();

        if (isset($conditions['post_types']) && is_array($conditions['post_types'])) {
            $sanitized['post_types'] = array_map('sanitize_key', $conditions['post_types']);
        }

        if (isset($conditions['page_templates']) && is_array($conditions['page_templates'])) {
            $sanitized['page_templates'] = array_map('sanitize_text_field', $conditions['page_templates']);
        }

        if (isset($conditions['is_front_page'])) {
            $sanitized['is_front_page'] = (bool) $conditions['is_front_page'];
        }

        if (isset($conditions['is_home'])) {
            $sanitized['is_home'] = (bool) $conditions['is_home'];
        }

        if (isset($conditions['is_archive'])) {
            $sanitized['is_archive'] = (bool) $conditions['is_archive'];
        }

        if (isset($conditions['is_singular'])) {
            $sanitized['is_singular'] = (bool) $conditions['is_singular'];
        }

        if (isset($conditions['post_ids']) && is_array($conditions['post_ids'])) {
            $sanitized['post_ids'] = array_map('absint', $conditions['post_ids']);
        }

        if (isset($conditions['callback']) && is_callable($conditions['callback'])) {
            $sanitized['callback'] = $conditions['callback'];
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize localization data
     * 
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitize_localize_data_recursive($data)
    {
        if (is_array($data)) {
            $sanitized = array();
            foreach ($data as $key => $value) {
                $sanitized[sanitize_text_field($key)] = $this->sanitize_localize_data_recursive($value);
            }
            return $sanitized;
        }

        if (is_string($data)) {
            // Preserve URLs
            if (filter_var($data, FILTER_VALIDATE_URL)) {
                return esc_url_raw($data);
            }
            // Use esc_js to properly escape quotes and special chars for JavaScript
            // This handles: ' " \ and other special characters
            return esc_js(sanitize_text_field($data));
        }

        if (is_numeric($data)) {
            return $data;
        }

        if (is_bool($data)) {
            return $data;
        }

        // For null or other types, return as-is
        return $data;
    }

    /**
     * Get registered assets for external use
     * 
     * @return array Registered assets
     */
    public static function get_assets()
    {
        $instance = self::get_instance();
        return $instance->get_registered_assets();
    }

    /**
     * Clear cached assets (useful for testing)
     * 
     * @return void
     */
    public static function clear_cache()
    {
        self::$registered_assets = array();
    }

    /**
     * Add resource hints for external domains
     * Improves PageSpeed by establishing early connections
     * 
     * @return void
     */
    public function add_resource_hints()
    {
        if (is_admin()) {
            return;
        }

        $assets = $this->get_registered_assets();
        $hints = array(
            'preconnect' => array(),
            'dns-prefetch' => array()
        );

        foreach ($assets as $asset) {
            if (isset($asset['resource_hints']) && is_array($asset['resource_hints'])) {
                foreach ($asset['resource_hints'] as $hint_type => $domains) {
                    if (isset($hints[$hint_type]) && is_array($domains)) {
                        $hints[$hint_type] = array_merge($hints[$hint_type], $domains);
                    }
                }
            }

            $parsed_url = wp_parse_url($asset['src']);
            if (isset($parsed_url['host']) && $parsed_url['host'] !== wp_parse_url(home_url())['host']) {
                $origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                if (!in_array($origin, $hints['preconnect'], true)) {
                    $hints['dns-prefetch'][] = $origin;
                }
            }
        }

        $hints = apply_filters('ewp_dynamic_assets_resource_hints', $hints);

        foreach ($hints['preconnect'] as $origin) {
            echo '<link rel="preconnect" href="' . esc_url($origin) . '" crossorigin>' . "\n";
        }

        foreach (array_unique($hints['dns-prefetch']) as $origin) {
            if (!in_array($origin, $hints['preconnect'], true)) {
                echo '<link rel="dns-prefetch" href="' . esc_url($origin) . '">' . "\n";
            }
        }
    }

    /**
     * Add preload links for critical assets
     * Improves PageSpeed by loading critical resources early
     * 
     * @return void
     */
    public function add_preload_links()
    {
        if (is_admin()) {
            return;
        }

        $assets = $this->get_registered_assets();
        $preload_assets = array();

        foreach ($assets as $asset) {
            if (isset($asset['preload']) && $asset['preload'] === true) {
                $preload_assets[] = $asset;
            }
        }

        $preload_assets = apply_filters('ewp_dynamic_assets_preload', $preload_assets);

        foreach ($preload_assets as $asset) {
            $as = $asset['type'] === 'script' ? 'script' : 'style';
            $crossorigin = $this->is_external_url($asset['src']) ? ' crossorigin' : '';

            echo '<link rel="preload" href="' . esc_url($asset['src']) . '" as="' . esc_attr($as) . '"' . $crossorigin . '>' . "\n";
        }
    }

    /**
     * Check if URL is external
     * 
     * @param string $url URL to check
     * @return bool True if external
     */
    private function is_external_url($url)
    {
        $parsed_url = wp_parse_url($url);
        $home_url = wp_parse_url(home_url());

        if (!isset($parsed_url['host'])) {
            return false;
        }

        return $parsed_url['host'] !== $home_url['host'];
    }

    /**
     * Add inline critical CSS for style assets
     * Improves PageSpeed by inlining critical above-the-fold CSS
     * 
     * @return void
     */
    public function add_critical_css()
    {
        if (is_admin()) {
            return;
        }

        $assets = $this->get_registered_assets();
        $critical_css = array();

        foreach ($assets as $asset) {
            if ($asset['type'] !== 'style') {
                continue;
            }

            // Check critical conditions if specified
            if (isset($asset['critical_conditions']) && !$this->should_load_critical_css($asset['critical_conditions'])) {
                continue;
            }

            $css = '';

            // Load critical CSS from external file if specified
            if (isset($asset['critical_src']) && !empty($asset['critical_src'])) {
                $css = $this->load_critical_css_file($asset['critical_src']);
                if ($css === false) {
                    continue;
                }
            }

            // Use inline critical CSS if provided (takes precedence)
            if (isset($asset['critical_css']) && !empty($asset['critical_css'])) {
                $css = $asset['critical_css'];
            }

            // Skip if no critical CSS available
            if (empty($css)) {
                continue;
            }

            // Minify if enabled
            if (isset($asset['minify_critical']) && $asset['minify_critical']) {
                $css = $this->minify_css($css);
            }

            $critical_css[$asset['handle']] = $css;
        }

        /**
         * Filter critical CSS before output
         * 
         * @param array $critical_css Array of critical CSS keyed by handle
         * @param array $assets All registered assets
         */
        $critical_css = apply_filters('ewp_dynamic_assets_critical_css', $critical_css, $assets);

        if (empty($critical_css)) {
            return;
        }

        echo '<style id="ewp-critical-css" data-assets="' . esc_attr(implode(',', array_keys($critical_css))) . '">' . "\n";
        foreach ($critical_css as $handle => $css) {
            echo '/* ' . esc_attr($handle) . ' */' . "\n";
            echo $css . "\n";
        }
        echo '</style>' . "\n";

        /**
         * Action after critical CSS is output
         * 
         * @param array $critical_css Array of critical CSS keyed by handle
         */
        do_action('ewp_dynamic_assets_critical_css_output', $critical_css);
    }

    /**
     * Check if critical CSS should be loaded based on conditions
     * 
     * @param array $conditions Conditions array
     * @return bool True if should load
     */
    private function should_load_critical_css($conditions)
    {
        if (empty($conditions)) {
            return true;
        }

        // Check post types
        if (isset($conditions['post_types']) && !empty($conditions['post_types'])) {
            $current_post_type = get_post_type();
            if ($current_post_type && !in_array($current_post_type, $conditions['post_types'], true)) {
                return false;
            }
        }

        // Check page templates
        if (isset($conditions['page_templates']) && !empty($conditions['page_templates'])) {
            $current_template = get_page_template_slug();
            if ($current_template && !in_array($current_template, $conditions['page_templates'], true)) {
                return false;
            }
        }

        // Check WordPress conditional tags
        if (isset($conditions['is_front_page']) && $conditions['is_front_page'] !== is_front_page()) {
            return false;
        }

        if (isset($conditions['is_home']) && $conditions['is_home'] !== is_home()) {
            return false;
        }

        if (isset($conditions['is_archive']) && $conditions['is_archive'] !== is_archive()) {
            return false;
        }

        if (isset($conditions['is_singular']) && $conditions['is_singular'] !== is_singular()) {
            return false;
        }

        // Check specific post IDs
        if (isset($conditions['post_ids']) && !empty($conditions['post_ids'])) {
            $current_post_id = get_the_ID();
            if ($current_post_id && !in_array($current_post_id, $conditions['post_ids'], true)) {
                return false;
            }
        }

        // Check custom callback
        if (isset($conditions['callback']) && is_callable($conditions['callback'])) {
            if (!call_user_func($conditions['callback'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load critical CSS from external file
     * 
     * @param string $url URL to the critical CSS file
     * @return string|false CSS content or false on failure
     */
    private function load_critical_css_file($url)
    {
        // Convert URL to local path if it's a local file
        $parsed_url = wp_parse_url($url);
        $home_url = wp_parse_url(home_url());

        if (isset($parsed_url['host']) && isset($home_url['host']) && $parsed_url['host'] === $home_url['host']) {
            // Local file - convert to file path
            $file_path = str_replace(
                array(content_url(), plugins_url(), get_template_directory_uri(), get_stylesheet_directory_uri()),
                array(WP_CONTENT_DIR, WP_PLUGIN_DIR, get_template_directory(), get_stylesheet_directory()),
                $url
            );

            // Try to read local file
            if (file_exists($file_path) && is_readable($file_path)) {
                $css = file_get_contents($file_path);
                if ($css !== false) {
                    return $css;
                }
            }
        }

        // Fallback to remote request for external files or if local read failed
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $css = wp_remote_retrieve_body($response);
        return !empty($css) ? $css : false;
    }

    /**
     * Minify CSS by removing comments, whitespace, and line breaks
     * 
     * @param string $css CSS to minify
     * @return string Minified CSS
     */
    private function minify_css($css)
    {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        // Remove whitespace
        $css = str_replace(array("\r\n", "\r", "\n", "\t"), '', $css);

        // Remove multiple spaces
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around certain characters
        $css = str_replace(array(' {', '{ ', ' }', '} ', ' :', ': ', ' ;', '; ', ' ,', ', '), array('{', '{', '}', '}', ':', ':', ';', ';', ',', ','), $css);

        return trim($css);
    }
}

/**
 * Initialize the Dynamic Asset Loader
 * 
 * @return Dynamic_Asset_Loader
 */
function ewp_dynamic_asset_loader()
{
    return Dynamic_Asset_Loader::get_instance();
}

// Initialize
ewp_dynamic_asset_loader();