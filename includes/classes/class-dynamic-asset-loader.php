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
    private const VERSION = '1.0.0';

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
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    private function init_hooks()
    {
        add_action('wp_enqueue_scripts', array($this, 'register_loader_script'), 5);
        add_action('admin_enqueue_scripts', array($this, 'register_loader_script'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_loader'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_loader'), 999);
        add_action('wp_head', array($this, 'add_resource_hints'), 1);
        add_action('wp_head', array($this, 'add_preload_links'), 2);
    }

    /**
     * Register the main loader script
     * 
     * @return void
     */
    public function register_loader_script()
    {
        wp_register_script(
            self::LOADER_HANDLE,
            awm_url . 'assets/js/class-dynamic-asset-loader.js',
            array(),
            self::VERSION,
            true
        );
    }

    /**
     * Enqueue the loader script with localized asset configuration
     * 
     * @return void
     */
    public function enqueue_loader()
    {
        $assets = $this->get_registered_assets();
        
        if (empty($assets)) {
            return;
        }

        wp_enqueue_script(self::LOADER_HANDLE);
        
        wp_localize_script(
            self::LOADER_HANDLE,
            'ewpDynamicAssets',
            array(
                'assets' => $assets,
                'nonce' => wp_create_nonce('ewp_dynamic_assets'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'performance' => array(
                    'lazyLoad' => apply_filters('ewp_dynamic_assets_lazy_load', true),
                    'intersectionThreshold' => apply_filters('ewp_dynamic_assets_intersection_threshold', 0.1),
                    'rootMargin' => apply_filters('ewp_dynamic_assets_root_margin', '50px')
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
         * - handle (string, required): Unique identifier for the asset
         * - selector (string, required): CSS selector to check for DOM element presence
         * - type (string, required): 'script' or 'style'
         * - src (string, required): URL to the asset file
         * - version (string, optional): Asset version for cache busting
         * - dependencies (array, optional): Array of dependency handles (scripts only)
         * - in_footer (bool, optional): Load script in footer (scripts only, default: true)
         * - media (string, optional): Media type for styles (styles only, default: 'all')
         * - localize (array, optional): Localization data (scripts only)
         *   - objectName (string): JavaScript object name
         *   - data (array): Data to pass to JavaScript
         * - async (bool, optional): Load script asynchronously (scripts only)
         * - defer (bool, optional): Defer script execution (scripts only)
         * - preload (bool, optional): Add preload link for faster loading
         * - lazy (bool, optional): Use Intersection Observer for lazy loading
         * - critical (bool, optional): Mark as critical (loads immediately)
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
        $sanitized = array(
            'handle' => sanitize_key($asset['handle']),
            'selector' => sanitize_text_field($asset['selector']),
            'type' => sanitize_key($asset['type']),
            'src' => esc_url($asset['src']),
            'version' => isset($asset['version']) ? sanitize_text_field($asset['version']) : self::VERSION,
        );

        if ($asset['type'] === 'script') {
            $sanitized['dependencies'] = isset($asset['dependencies']) && is_array($asset['dependencies']) 
                ? array_map('sanitize_key', $asset['dependencies']) 
                : array();
            
            $sanitized['in_footer'] = isset($asset['in_footer']) ? (bool) $asset['in_footer'] : true;
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

        return array(
            'objectName' => sanitize_key($localize['objectName']),
            'data' => $localize['data']
        );
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
