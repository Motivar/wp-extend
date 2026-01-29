<?php

/**
 * Dynamic Asset Loader - Usage Examples
 * 
 * This file demonstrates how to use the Dynamic Asset Loader
 * to register scripts and styles that load only when needed.
 * 
 * @package ExtendWP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example 1: Basic Script Registration
 * 
 * Register a script that loads when .my-widget element exists
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'my-widget-script',
        'selector' => '.my-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/widget.js',
        'version' => '1.0.0',
        'dependencies' => array('jquery'),
        'in_footer' => true
    );

    return $assets;
}, 10);

/**
 * Example 2: Style Registration
 * 
 * Register a stylesheet that loads when .custom-gallery exists
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'custom-gallery-style',
        'selector' => '.custom-gallery',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/gallery.css',
        'version' => '1.0.0',
        'media' => 'all'
    );

    return $assets;
}, 10);

/**
 * Example 3: Script with Localization
 * 
 * Register a script with localized data for AJAX
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'ajax-form-handler',
        'selector' => '[data-ajax-form]',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/ajax-handler.js',
        'version' => '1.0.0',
        'dependencies' => array(),
        'in_footer' => true,
        'localize' => array(
            'objectName' => 'ajaxFormData',
            'data' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ajax-form-nonce'),
                'strings' => array(
                    'loading' => __('Loading...', 'extend-wp'),
                    'success' => __('Form submitted successfully', 'extend-wp'),
                    'error' => __('An error occurred', 'extend-wp')
                )
            )
        )
    );

    return $assets;
}, 10);

/**
 * Example 4: Multiple Assets for Same Component
 * 
 * Register both script and style for a component
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    // Script
    $assets[] = array(
        'handle' => 'slider-script',
        'selector' => '[data-slider]',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/slider.js',
        'version' => '2.0.0',
        'dependencies' => array('jquery')
    );

    // Style
    $assets[] = array(
        'handle' => 'slider-style',
        'selector' => '[data-slider]',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/slider.css',
        'version' => '2.0.0'
    );

    return $assets;
}, 10);

/**
 * Example 5: Admin-Only Assets
 * 
 * Register assets that only load in admin area
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    if (!is_admin()) {
        return $assets;
    }

    $assets[] = array(
        'handle' => 'admin-dashboard-widget',
        'selector' => '#my-dashboard-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/admin-dashboard.js',
        'version' => '1.0.0',
        'localize' => array(
            'objectName' => 'dashboardData',
            'data' => array(
                'restUrl' => rest_url('myplugin/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'userId' => get_current_user_id()
            )
        )
    );

    return $assets;
}, 10);

/**
 * Example 6: External Library with Dependencies
 * 
 * Load external library and custom handler
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    // External library
    $assets[] = array(
        'handle' => 'chart-js',
        'selector' => '.chart-container',
        'type' => 'script',
        'src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        'version' => '4.4.0'
    );

    // Custom handler (depends on Chart.js)
    $assets[] = array(
        'handle' => 'custom-charts',
        'selector' => '.chart-container',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/charts.js',
        'version' => '1.0.0',
        'dependencies' => array('chart-js'),
        'localize' => array(
            'objectName' => 'chartConfig',
            'data' => array(
                'colors' => array(
                    'primary' => '#007bff',
                    'secondary' => '#6c757d'
                )
            )
        )
    );

    return $assets;
}, 10);

/**
 * Example 7: Conditional Loading Based on User Role
 * 
 * Load assets only for specific user roles
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    if (!current_user_can('edit_posts')) {
        return $assets;
    }

    $assets[] = array(
        'handle' => 'editor-tools',
        'selector' => '.editor-toolbar',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/editor-tools.js',
        'version' => '1.0.0'
    );

    return $assets;
}, 10);

/**
 * Example 8: Listen to Load Events
 * 
 * JavaScript example to listen for asset load events
 */
function mtv_add_asset_load_listener()
{
?>
    <script>
        // Listen for asset loading
        document.addEventListener('ewp_dynamic_asset_loading', function(e) {
            console.log('Loading asset:', e.detail.handle);
        });

        // Listen for asset loaded
        document.addEventListener('ewp_dynamic_asset_loaded', function(e) {
            if (e.detail.success) {
                console.log('Asset loaded successfully:', e.detail.handle);

                // Initialize component after asset loads
                if (e.detail.handle === 'my-widget-script') {
                    // Initialize widget
                    if (typeof MyWidget !== 'undefined') {
                        MyWidget.init();
                    }
                }
            } else {
                console.error('Asset failed to load:', e.detail.handle);
            }
        });

        // Manually refresh after AJAX content load
        jQuery(document).on('ajaxComplete', function() {
            if (window.EWPDynamicAssetLoader) {
                window.EWPDynamicAssetLoader.refresh();
            }
        });
    </script>
<?php
}
add_action('wp_footer', 'mtv_add_asset_load_listener');
add_action('admin_footer', 'mtv_add_asset_load_listener');

/**
 * ========================================
 * PAGESPEED OPTIMIZATION EXAMPLES
 * ========================================
 */

/**
 * Example 9: Critical Above-the-Fold Assets
 * 
 * Load critical assets immediately for better LCP
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'hero-section-styles',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'version' => '1.0.0',
        'critical' => true,
        'preload' => true
    );

    $assets[] = array(
        'handle' => 'hero-section-script',
        'selector' => '.hero-section',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/hero.js',
        'version' => '1.0.0',
        'critical' => true,
        'defer' => true
    );

    return $assets;
}, 10);

/**
 * Example 10: Lazy Loading Below-the-Fold Content
 * 
 * Use Intersection Observer for lazy loading
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'testimonials-slider',
        'selector' => '.testimonials-section',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/testimonials.js',
        'version' => '1.0.0',
        'lazy' => true,
        'defer' => true
    );

    $assets[] = array(
        'handle' => 'testimonials-styles',
        'selector' => '.testimonials-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/testimonials.css',
        'version' => '1.0.0',
        'lazy' => true
    );

    return $assets;
}, 10);

/**
 * Example 11: External Resources with Resource Hints
 * 
 * Optimize third-party scripts with preconnect
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'google-fonts',
        'selector' => 'body',
        'type' => 'style',
        'src' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
        'version' => '1.0.0',
        'preload' => true,
        'resource_hints' => array(
            'preconnect' => array(
                'https://fonts.googleapis.com',
                'https://fonts.gstatic.com'
            )
        )
    );

    $assets[] = array(
        'handle' => 'google-maps',
        'selector' => '[data-map]',
        'type' => 'script',
        'src' => 'https://maps.googleapis.com/maps/api/js?key=YOUR_KEY',
        'version' => '1.0.0',
        'async' => true,
        'lazy' => true,
        'resource_hints' => array(
            'preconnect' => array(
                'https://maps.googleapis.com',
                'https://maps.gstatic.com'
            )
        )
    );

    return $assets;
}, 10);

/**
 * Example 12: Async Analytics Scripts
 * 
 * Load analytics without blocking rendering
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    $assets[] = array(
        'handle' => 'google-analytics',
        'selector' => 'body',
        'type' => 'script',
        'src' => 'https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID',
        'version' => '1.0.0',
        'async' => true,
        'resource_hints' => array(
            'preconnect' => array('https://www.googletagmanager.com')
        )
    );

    return $assets;
}, 10);

/**
 * Example 13: Progressive Enhancement
 * 
 * Load core functionality first, enhance later
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    // Core functionality (critical)
    $assets[] = array(
        'handle' => 'app-core',
        'selector' => '.app-container',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/core.js',
        'version' => '1.0.0',
        'critical' => true,
        'defer' => true
    );

    // Enhanced features (lazy)
    $assets[] = array(
        'handle' => 'app-enhanced',
        'selector' => '.app-container',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/enhanced.js',
        'version' => '1.0.0',
        'lazy' => true,
        'defer' => true,
        'dependencies' => array('app-core')
    );

    return $assets;
}, 10);

/**
 * Example 14: Configure Intersection Observer Settings
 * 
 * Customize lazy loading behavior
 */
add_filter('ewp_dynamic_assets_root_margin', function ($margin) {
    return '100px'; // Start loading 100px before element enters viewport
});

add_filter('ewp_dynamic_assets_intersection_threshold', function ($threshold) {
    return 0.25; // Load when 25% of element is visible
});

/**
 * Example 15: Performance Monitoring
 * 
 * Track and log asset loading performance
 */
add_action('wp_footer', function () {
?>
    <script>
        // Wait for page load
        window.addEventListener('load', function() {
            // Log performance summary after 2 seconds
            setTimeout(function() {
                if (window.EWPDynamicAssetLoader) {
                    window.EWPDynamicAssetLoader.logPerformanceSummary();

                    // Get detailed metrics
                    const metrics = window.EWPDynamicAssetLoader.getPerformanceMetrics();
                    console.log('Total assets loaded:', window.EWPDynamicAssetLoader.getLoadedAssets().size);
                    console.log('Average load time:',
                        metrics.reduce((sum, m) => sum + m.duration, 0) / metrics.length + 'ms'
                    );
                }
            }, 2000);
        });
    </script>
<?php
}, 999);

/**
 * Example 16: E-commerce Product Page Optimization
 * 
 * Complete example for optimizing a product page
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    // Only on product pages
    if (!is_singular('product')) {
        return $assets;
    }

    // Critical: Product gallery (above-the-fold)
    $assets[] = array(
        'handle' => 'product-gallery',
        'selector' => '.product-gallery',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/product-gallery.js',
        'version' => '2.0.0',
        'critical' => true,
        'preload' => true,
        'defer' => true
    );

    $assets[] = array(
        'handle' => 'product-gallery-styles',
        'selector' => '.product-gallery',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/product-gallery.css',
        'version' => '2.0.0',
        'critical' => true,
        'preload' => true
    );

    // Lazy: Reviews (below-the-fold)
    $assets[] = array(
        'handle' => 'product-reviews',
        'selector' => '.product-reviews',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/reviews.js',
        'version' => '2.0.0',
        'lazy' => true,
        'defer' => true
    );

    // Lazy: Related products (below-the-fold)
    $assets[] = array(
        'handle' => 'related-products',
        'selector' => '.related-products',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/related-products.js',
        'version' => '2.0.0',
        'lazy' => true,
        'async' => true
    );

    return $assets;
}, 10);

/**
 * ========================================
 * EXAMPLE 17: Public API - Manual Asset Loading After AJAX
 * ========================================
 */
add_action('wp_footer', function () {
?>
    <script>
        // After AJAX call that loads new content
        document.addEventListener('myAjaxComplete', function() {
            // Method 1: Check all assets for new DOM elements
            window.EWPDynamicAssetLoader.checkAssets();

            // Method 2: Load specific asset by handle
            window.EWPDynamicAssetLoader.loadAssetByHandle('my-widget-script');

            // Method 3: Force load asset (bypasses DOM check)
            window.EWPDynamicAssetLoader.forceLoadAsset('emergency-script');

            // Check if asset is loaded
            if (window.EWPDynamicAssetLoader.isAssetLoaded('my-widget-script')) {
                console.log('Widget script is ready!');
            }

            // Get all loaded assets
            const loaded = window.EWPDynamicAssetLoader.getLoadedAssets();
            console.log('Loaded assets:', loaded);

            // Get all registered assets
            const registered = window.EWPDynamicAssetLoader.getRegisteredAssets();
            console.log('Registered assets:', registered);
        });
    </script>
<?php
});

/**
 * ========================================
 * EXAMPLE 18: Critical CSS for Above-the-Fold Content
 * ========================================
 */
add_filter('ewp_register_dynamic_assets', function ($assets) {
    // Hero section with inline critical CSS
    $assets[] = array(
        'handle' => 'hero-critical',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => get_template_directory_uri() . '/css/hero.css',
        'critical' => true,
        'preload' => true,
        'critical_css' => '
            .hero-section {
                min-height: 100vh;
                display: flex;
                align-items: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .hero-title {
                font-size: 3rem;
                color: white;
                margin: 0;
            }
        '
    );

    // Navigation with critical CSS
    $assets[] = array(
        'handle' => 'nav-critical',
        'selector' => '.main-navigation',
        'type' => 'style',
        'src' => get_template_directory_uri() . '/css/navigation.css',
        'critical' => true,
        'critical_css' => '
            .main-navigation {
                position: fixed;
                top: 0;
                width: 100%;
                background: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                z-index: 1000;
            }
        '
    );

    return $assets;
}, 10);

/**
 * ========================================
 * EXAMPLE 19: Filter Critical CSS Output
 * ========================================
 */
add_filter('ewp_dynamic_assets_critical_css', function ($critical_css) {
    // Add global critical CSS
    $critical_css['global'] = '
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    ';

    // Modify existing critical CSS
    if (isset($critical_css['hero-critical'])) {
        $critical_css['hero-critical'] .= ' .hero-subtitle { font-size: 1.5rem; }';
    }

    return $critical_css;
});
