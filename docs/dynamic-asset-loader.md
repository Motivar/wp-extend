# Dynamic Asset Loader

## Overview

The Dynamic Asset Loader is a performance optimization system that loads scripts and styles **only when their corresponding DOM elements are present** on the page. This prevents unnecessary HTTP requests and reduces page load times.

## Problem It Solves

Traditional WordPress asset loading enqueues all scripts/styles on every page, even if they're not needed. This leads to:
- Unnecessary HTTP requests
- Increased page load times
- Wasted bandwidth
- Poor Core Web Vitals scores

The Dynamic Asset Loader solves this by:
1. Monitoring the DOM for specific selectors
2. Loading assets only when their target elements exist
3. Supporting dynamic content (AJAX, SPA-style updates)
4. Handling dependencies automatically

## Architecture

### PHP Side (`class-dynamic-asset-loader.php`)
- Registers a global JavaScript loader
- Provides `ewp_register_dynamic_assets` filter for developers
- Validates and sanitizes asset configurations
- Passes configuration to JavaScript via `wp_localize_script`

### JavaScript Side (`class-dynamic-asset-loader.js`)
- Monitors DOM using MutationObserver
- Checks for registered selectors
- Dynamically injects `<script>` and `<link>` tags as ES6 modules
- Handles dependencies and localization
- Dispatches custom events for tracking

## Usage for Developers

### Basic Script Registration

```php
/**
 * Register a script to load when .my-widget element exists
 */
add_filter('ewp_register_dynamic_assets', function($assets) {
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
});
```

### Basic Style Registration

```php
/**
 * Register a stylesheet to load when .custom-gallery element exists
 */
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'custom-gallery-style',
        'selector' => '.custom-gallery',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/gallery.css',
        'version' => '1.0.0',
        'media' => 'all',
        'dependencies' => array()
    );
    
    return $assets;
});
```

### Script with Localization

```php
/**
 * Register a script with localized data
 */
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-ajax-handler',
        'selector' => '[data-ajax-form]',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/ajax-handler.js',
        'version' => '1.0.0',
        'dependencies' => array(),
        'in_footer' => true,
        'localize' => array(
            'objectName' => 'myAjaxData',
            'data' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('my-ajax-nonce'),
                'strings' => array(
                    'loading' => __('Loading...', 'my-plugin'),
                    'error' => __('Error occurred', 'my-plugin')
                )
            )
        )
    );
    
    return $assets;
});
```

### Multiple Selectors Strategy

If you need to load an asset when **any** of multiple elements exist, register separate entries:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Load when either selector exists
    $assets[] = array(
        'handle' => 'shared-script-1',
        'selector' => '.widget-type-a',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/shared.js',
        'version' => '1.0.0'
    );
    
    $assets[] = array(
        'handle' => 'shared-script-2',
        'selector' => '.widget-type-b',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/shared.js',
        'version' => '1.0.0'
    );
    
    return $assets;
});
```

### Complex Selectors

You can use any valid CSS selector:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'advanced-selector',
        'selector' => 'div[data-component="slider"]:not(.loaded)',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/slider.js',
        'version' => '1.0.0'
    );
    
    return $assets;
});
```

## Asset Configuration Reference

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `handle` | string | Unique identifier for the asset |
| `selector` | string | CSS selector to check for DOM element |
| `type` | string | Asset type: `'script'` or `'style'` |
| `src` | string | Full URL to the asset file |

### Optional Fields (Scripts)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `version` | string | `'1.0.0'` | Version for cache busting |
| `dependencies` | array | `[]` | Array of dependency handles |
| `in_footer` | bool | `true` | Load in footer vs header |
| `localize` | array | `null` | Localization configuration |

### Optional Fields (Styles)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `version` | string | `'1.0.0'` | Version for cache busting |
| `media` | string | `'all'` | Media type (all, screen, print) |
| `dependencies` | array | `[]` | Array of dependency handles |

### Localize Configuration

| Field | Type | Description |
|-------|------|-------------|
| `objectName` | string | JavaScript global object name |
| `data` | array | Data to pass to JavaScript |

## JavaScript Events

The loader dispatches custom events you can listen to:

### Asset Loading Event

Fired when an asset starts loading:

```javascript
document.addEventListener('ewp_dynamic_asset_loading', function(e) {
    console.log('Loading:', e.detail.handle);
    console.log('Type:', e.detail.type);
    console.log('Selector:', e.detail.selector);
});
```

### Asset Loaded Event

Fired when an asset finishes loading (success or failure):

```javascript
document.addEventListener('ewp_dynamic_asset_loaded', function(e) {
    console.log('Loaded:', e.detail.handle);
    console.log('Success:', e.detail.success);
    
    if (e.detail.success) {
        // Asset loaded successfully
        console.log('Asset ready:', e.detail.handle);
    } else {
        // Asset failed to load
        console.error('Asset failed:', e.detail.handle);
    }
});
```

## JavaScript API

Access the loader instance globally:

```javascript
// Check if asset is loaded
if (window.EWPDynamicAssetLoader.isAssetLoaded('my-widget-script')) {
    console.log('Widget script is loaded');
}

// Get all loaded assets
const loadedAssets = window.EWPDynamicAssetLoader.getLoadedAssets();
console.log('Loaded assets:', Array.from(loadedAssets));

// Manually trigger check (useful after AJAX content load)
window.EWPDynamicAssetLoader.refresh();

// Destroy the loader (cleanup)
window.EWPDynamicAssetLoader.destroy();
```

## Best Practices

### 1. Use Specific Selectors

```php
// Good - specific and unique
'selector' => '.my-plugin-widget'

// Bad - too generic, might match unrelated elements
'selector' => '.widget'
```

### 2. Add Data Attributes for Clarity

```php
// In your HTML output
echo '<div class="my-widget" data-requires-script="my-widget-script">';

// In your asset registration
'selector' => '[data-requires-script="my-widget-script"]'
```

### 3. Handle Dependencies Properly

```php
// If your script needs jQuery
'dependencies' => array('jquery')

// If your style needs another style
'dependencies' => array('my-base-style')
```

### 4. Version Your Assets

```php
// Use plugin version or file modification time
'version' => filemtime(plugin_dir_path(__FILE__) . 'js/script.js')
```

### 5. Namespace Your Handles

```php
// Good - namespaced
'handle' => 'myplugin-widget-script'

// Bad - might conflict
'handle' => 'widget-script'
```

## Performance Considerations

### MutationObserver

The loader uses MutationObserver to detect DOM changes efficiently. It automatically:
- Monitors all DOM mutations
- Checks for new elements matching registered selectors
- Stops monitoring once all assets are loaded

### Periodic Fallback

A 1-second interval check runs as a fallback for browsers without MutationObserver support.

### Cleanup

The loader automatically cleans up when all assets are loaded, removing observers and intervals.

## Debugging

### Enable Console Logging

The loader logs errors and warnings to the console:

```javascript
// Check browser console for messages like:
// "EWP Dynamic Asset Loader: Failed to load script my-script"
// "EWP Dynamic Asset Loader: Invalid selector .my[invalid"
```

### Check Registered Assets

```javascript
// View all registered assets
console.log(ewpDynamicAssets.assets);

// Check what's loaded
console.log(window.EWPDynamicAssetLoader.getLoadedAssets());
```

### PHP Debugging

```php
// View registered assets
add_action('ewp_dynamic_asset_loader_enqueued', function($assets) {
    error_log('Dynamic Assets: ' . print_r($assets, true));
});
```

## Real-World Examples

### Example 1: Product Review Widget

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'product-reviews',
        'selector' => '.product-reviews-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/reviews.js',
        'version' => '2.1.0',
        'dependencies' => array('jquery'),
        'localize' => array(
            'objectName' => 'reviewsData',
            'data' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('load-reviews'),
                'perPage' => 10
            )
        )
    );
    
    $assets[] = array(
        'handle' => 'product-reviews-style',
        'selector' => '.product-reviews-widget',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/reviews.css',
        'version' => '2.1.0'
    );
    
    return $assets;
});
```

### Example 2: Interactive Map

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Load Leaflet library
    $assets[] = array(
        'handle' => 'leaflet-js',
        'selector' => '[data-map]',
        'type' => 'script',
        'src' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        'version' => '1.9.4'
    );
    
    $assets[] = array(
        'handle' => 'leaflet-css',
        'selector' => '[data-map]',
        'type' => 'style',
        'src' => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        'version' => '1.9.4'
    );
    
    // Load custom map handler (depends on Leaflet)
    $assets[] = array(
        'handle' => 'custom-map-handler',
        'selector' => '[data-map]',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/map-handler.js',
        'version' => '1.0.0',
        'dependencies' => array('leaflet-js'),
        'localize' => array(
            'objectName' => 'mapConfig',
            'data' => array(
                'apiKey' => get_option('map_api_key'),
                'defaultZoom' => 12
            )
        )
    );
    
    return $assets;
});
```

### Example 3: Admin-Only Script

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Only register in admin area
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
                'nonce' => wp_create_nonce('wp_rest')
            )
        )
    );
    
    return $assets;
});
```

## Hooks Reference

### Filters

#### `ewp_register_dynamic_assets`

Register assets for dynamic loading.

**Parameters:**
- `$assets` (array) Empty array to populate with asset configurations

**Returns:**
- (array) Array of asset configurations

#### `ewp_dynamic_asset_loader_enqueued`

Fires after the loader is enqueued.

**Parameters:**
- `$assets` (array) Registered assets configuration

## Troubleshooting

### Asset Not Loading

1. **Check selector exists in DOM**
   ```javascript
   console.log(document.querySelector('.my-selector'));
   ```

2. **Verify asset is registered**
   ```javascript
   console.log(ewpDynamicAssets.assets);
   ```

3. **Check browser console for errors**

### Asset Loads Multiple Times

- Ensure handle is unique
- Check if you're registering the same asset multiple times

### Dependencies Not Loading

- Verify dependency handles are correct
- Check if dependencies are registered in WordPress
- Ensure dependency scripts are loaded before your script

### Localization Not Working

- Verify `objectName` is unique
- Check `data` is a valid array
- Ensure script loads before accessing localized data

## Migration from Traditional Enqueue

### Before (Traditional)

```php
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'my-widget',
        plugin_dir_url(__FILE__) . 'js/widget.js',
        array('jquery'),
        '1.0.0',
        true
    );
    
    wp_localize_script('my-widget', 'widgetData', array(
        'ajaxUrl' => admin_url('admin-ajax.php')
    ));
});
```

### After (Dynamic Loading)

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-widget',
        'selector' => '.my-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/widget.js',
        'version' => '1.0.0',
        'dependencies' => array('jquery'),
        'in_footer' => true,
        'localize' => array(
            'objectName' => 'widgetData',
            'data' => array(
                'ajaxUrl' => admin_url('admin-ajax.php')
            )
        )
    );
    
    return $assets;
});
```

## Support

For issues or questions, please refer to the plugin documentation or contact support.
