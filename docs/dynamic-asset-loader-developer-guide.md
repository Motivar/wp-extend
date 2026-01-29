# Dynamic Asset Loader - Developer Guide

This document explains how to implement and use the **Dynamic Asset Loader** system in the Extend WP plugin. The Dynamic Asset Loader improves page load performance by loading scripts and styles only when their corresponding DOM elements are present on the page.

> 💡 **What is Dynamic Asset Loading?**  
> Dynamic Asset Loading is a performance optimization technique that loads JavaScript and CSS files on-demand based on DOM element presence. This eliminates unnecessary HTTP requests, reduces initial page weight, and improves Core Web Vitals metrics (FCP, LCP, TTI).

---

## **1. How It Works**

The system operates in two phases:

### **PHP Phase (Server-Side)**
1. Developers register assets using the `ewp_register_dynamic_assets` filter
2. Assets are validated and sanitized
3. Configuration is passed to JavaScript via `wp_localize_script`
4. Resource hints and preload links are added to `<head>`
5. Critical CSS is inlined in `<head>` (priority 3)

### **JavaScript Phase (Client-Side)**
1. The loader initializes on `DOMContentLoaded`
2. Checks DOM for registered selectors
3. Loads assets when selectors are found
4. Uses MutationObserver to detect dynamically added content
5. Supports Intersection Observer for lazy loading

---

## **2. Basic Registration**

### **Minimal Script Example**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-widget',
        'selector' => '.my-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/widget.js'
    );
    return $assets;
});
```

### **Minimal Style Example**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-widget-styles',
        'selector' => '.my-widget',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/widget.css'
    );
    return $assets;
});
```

---

## **3. Runtime Execution Order**

### **Server-Side Execution**

```
1. wp_enqueue_scripts (priority 5)
   └─ Register loader script

2. wp_enqueue_scripts (priority 999)
   └─ Enqueue loader with localized data

3. wp_head (priority 1)
   └─ Output resource hints (preconnect, dns-prefetch)

4. wp_head (priority 2)
   └─ Output preload links

5. wp_head (priority 3)
   └─ Output inline critical CSS
```

### **Client-Side Execution**

```
1. DOMContentLoaded event
   └─ EWPDynamicAssetLoader initializes

2. Load critical assets (critical: true)
   └─ Bypasses DOM check, loads immediately

3. Check DOM for selectors
   └─ Load assets when selectors found

4. Setup MutationObserver
   └─ Watch for dynamically added content

5. Setup IntersectionObserver
   └─ Watch for lazy-loaded elements (lazy: true)

6. Periodic check (every 5 seconds)
   └─ Fallback for missed mutations
```

---

## **4. Critical CSS Implementation**

Critical CSS is inlined in the `<head>` to eliminate render-blocking requests for above-the-fold content.

### **Basic Critical CSS**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'hero-section',
        'selector' => '.hero',
        'type' => 'style',
        'src' => get_template_directory_uri() . '/css/hero.css',
        'critical' => true,
        'critical_css' => '
            .hero {
                min-height: 100vh;
                display: flex;
                align-items: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .hero-title {
                font-size: 3rem;
                color: white;
            }
        '
    );
    return $assets;
});
```

### **Output in HTML**

```html
<style id="ewp-critical-css" data-assets="hero-section,navigation">
/* hero-section */
.hero {
    min-height: 100vh;
    display: flex;
    align-items: center;
}
/* navigation */
.main-nav {
    position: fixed;
    top: 0;
}
</style>
```

### **Filter Critical CSS**

```php
add_filter('ewp_dynamic_assets_critical_css', function($critical_css) {
    // Add global critical CSS
    $critical_css['global'] = '
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; }
    ';
    return $critical_css;
});
```

---

## **5. Public API Methods**

The loader exposes public methods via `window.EWPDynamicAssetLoader` for manual control.

### **Check All Assets After AJAX**

```javascript
// After AJAX call loads new content
fetch('/api/load-content')
    .then(response => response.text())
    .then(html => {
        document.getElementById('content').innerHTML = html;
        
        // Trigger asset check
        window.EWPDynamicAssetLoader.checkAssets();
    });
```

### **Load Specific Asset**

```javascript
// Load asset by handle if selector exists
window.EWPDynamicAssetLoader.loadAssetByHandle('my-widget');
```

### **Force Load Asset**

```javascript
// Force load without DOM check (use with caution)
window.EWPDynamicAssetLoader.forceLoadAsset('emergency-script');
```

### **Check Asset Status**

```javascript
// Check if asset is loaded
if (window.EWPDynamicAssetLoader.isAssetLoaded('my-widget')) {
    console.log('Widget is ready!');
}

// Get all loaded assets
const loaded = window.EWPDynamicAssetLoader.getLoadedAssets();
// ['ewp-search', 'my-widget']

// Get all registered assets
const registered = window.EWPDynamicAssetLoader.getRegisteredAssets();
```

---

## **6. Advanced Configuration**

### **Script with Dependencies**

```php
$assets[] = array(
    'handle' => 'my-app',
    'selector' => '.my-app',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/app.js',
    'dependencies' => array('jquery', 'wp-api'),
    'defer' => true
);
```

### **ES6 Module**

```php
$assets[] = array(
    'handle' => 'my-module',
    'selector' => '.my-component',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/module.js',
    'module' => true  // Loads as type="module"
);
```

### **Localized Script**

```php
$assets[] = array(
    'handle' => 'my-widget',
    'selector' => '.my-widget',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/widget.js',
    'localize' => array(
        'objectName' => 'myWidgetData',
        'data' => array(
            'apiUrl' => rest_url('my-plugin/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => array(
                'loading' => __('Loading...', 'my-plugin')
            )
        )
    )
);
```

### **Lazy Loading**

```php
$assets[] = array(
    'handle' => 'gallery',
    'selector' => '.gallery',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/gallery.js',
    'lazy' => true  // Uses Intersection Observer
);
```

### **Resource Hints**

```php
$assets[] = array(
    'handle' => 'google-maps',
    'selector' => '[data-map]',
    'type' => 'script',
    'src' => 'https://maps.googleapis.com/maps/api/js?key=KEY',
    'async' => true,
    'resource_hints' => array(
        'preconnect' => array(
            'https://maps.googleapis.com',
            'https://maps.gstatic.com'
        )
    )
);
```

---

## **7. Performance Configuration**

### **Global Lazy Load Settings**

```php
// Disable lazy loading globally
add_filter('ewp_dynamic_assets_lazy_load', '__return_false');

// Adjust Intersection Observer threshold
add_filter('ewp_dynamic_assets_intersection_threshold', function() {
    return 0.25; // Load when 25% visible
});

// Adjust root margin
add_filter('ewp_dynamic_assets_root_margin', function() {
    return '100px'; // Load 100px before viewport
});
```

### **Global Resource Hints**

```php
add_filter('ewp_dynamic_assets_resource_hints', function($hints) {
    $hints['preconnect'][] = 'https://cdn.example.com';
    $hints['dns-prefetch'][] = 'https://analytics.example.com';
    return $hints;
});
```

---

## **8. Event Listeners**

### **Asset Loading Events**

```javascript
// Listen for asset loading start
document.addEventListener('ewp_dynamic_asset_loading', function(e) {
    console.log('Loading:', e.detail.asset.handle);
});

// Listen for asset loaded
document.addEventListener('ewp_dynamic_asset_loaded', function(e) {
    console.log('Loaded:', e.detail.asset.handle);
    console.log('Success:', e.detail.success);
});
```

---

## **9. Best Practices**

### **Critical CSS Guidelines**

- **Keep it minimal** - Only above-the-fold content (< 14KB)
- **Mobile-first** - Prioritize mobile viewport styles
- **No duplication** - Don't repeat styles from main CSS
- **Compress** - Always minify critical CSS
- **Test** - Use Lighthouse to verify FCP/LCP improvements

### **Selector Best Practices**

- **Be specific** - Use unique class names (`.my-widget` not `.widget`)
- **Avoid generic** - Don't use `.container`, `.wrapper`, etc.
- **Use data attributes** - `[data-component="gallery"]` for clarity
- **Test selectors** - Verify they match intended elements only

### **Performance Tips**

- **Combine assets** - Group related scripts/styles under one selector
- **Use lazy loading** - For below-the-fold content
- **Set critical flag** - For above-the-fold assets
- **Monitor metrics** - Use Performance API methods

---

## **10. Extracting Critical CSS**

### **Manual Method (Chrome DevTools)**

1. Open page in Chrome
2. Press `Cmd+Shift+P` (Mac) or `Ctrl+Shift+P` (Windows)
3. Type "Show Coverage" and press Enter
4. Click reload button in Coverage tab
5. Identify used CSS for above-the-fold content
6. Copy used styles to `critical_css` parameter

### **Automated Tools**

```bash
# Using Critical package
npm install critical --save-dev
```

```javascript
const critical = require('critical');

critical.generate({
  inline: false,
  base: 'dist/',
  src: 'index.html',
  target: 'css/critical.css',
  width: 1300,
  height: 900,
  dimensions: [
    { width: 375, height: 667 },
    { width: 1920, height: 1080 }
  ]
});
```

---

## **11. Debugging**

### **Check Loaded Assets**

```javascript
// In browser console
console.log(window.EWPDynamicAssetLoader.getLoadedAssets());
console.log(window.EWPDynamicAssetLoader.getRegisteredAssets());
```

### **View Performance Metrics**

```javascript
// In browser console
window.EWPDynamicAssetLoader.logPerformanceSummary();
// Output:
// EWP Dynamic Asset Loader - Performance Summary
//   my-widget: 45.23ms
//   gallery: 23.45ms
```

### **Verify Critical CSS**

View page source and look for:

```html
<style id="ewp-critical-css" data-assets="hero-section,navigation">
/* Critical CSS content */
</style>
```

---

## **12. Common Issues**

### **Issue: Asset not loading**

**Solution:** Check selector specificity

```javascript
// Test selector in console
document.querySelector('.my-widget'); // Should return element
```

### **Issue: Script errors with global functions**

**Solution:** Don't use `module: true` for traditional scripts

```php
// Wrong - isolates scope
'module' => true

// Correct - global scope
'module' => false  // or omit
```

### **Issue: Critical CSS too large**

**Solution:** Only include truly critical styles

```php
// Wrong - includes footer
'critical_css' => '
    .hero { ... }
    .footer { ... }  // Remove this
'

// Correct - only above-fold
'critical_css' => '
    .hero { ... }
'
```

---

## **13. Quick Reference**

### **Asset Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `handle` | string | Yes | Unique asset identifier |
| `selector` | string | Yes | CSS selector to check |
| `type` | string | Yes | `'script'` or `'style'` |
| `src` | string | Yes | Asset URL |
| `version` | string | No | Cache busting version |
| `dependencies` | array | No | Asset handles to load first |
| `module` | bool | No | Load as ES6 module (scripts) |
| `async` | bool | No | Load asynchronously (scripts) |
| `defer` | bool | No | Defer execution (scripts) |
| `in_footer` | bool | No | Load in footer (scripts) |
| `media` | string | No | Media query (styles) |
| `localize` | array | No | Localization data (scripts) |
| `critical` | bool | No | Load immediately |
| `critical_css` | string | No | Inline CSS (styles) |
| `lazy` | bool | No | Use Intersection Observer |
| `preload` | bool | No | Add preload link |
| `resource_hints` | array | No | Preconnect/DNS-prefetch |

### **Public API Methods**

| Method | Description |
|--------|-------------|
| `checkAssets()` | Check all assets for new DOM elements |
| `loadAssetByHandle(handle)` | Load specific asset if selector exists |
| `forceLoadAsset(handle)` | Force load asset (bypasses DOM check) |
| `isAssetLoaded(handle)` | Check if asset is loaded |
| `getLoadedAssets()` | Get array of loaded handles |
| `getRegisteredAssets()` | Get array of registered assets |
| `getPerformanceMetrics()` | Get performance timing data |
| `logPerformanceSummary()` | Log performance to console |

### **Filter Hooks**

| Hook | Description |
|------|-------------|
| `ewp_register_dynamic_assets` | Register assets |
| `ewp_dynamic_assets_lazy_load` | Enable/disable lazy loading |
| `ewp_dynamic_assets_root_margin` | Intersection Observer margin |
| `ewp_dynamic_assets_intersection_threshold` | Intersection threshold |
| `ewp_dynamic_assets_resource_hints` | Add global resource hints |
| `ewp_dynamic_assets_preload` | Filter preload assets |
| `ewp_dynamic_assets_critical_css` | Filter critical CSS output |

---

## **14. Performance Validation**

### **Target Metrics**

- **FCP (First Contentful Paint):** < 1.8s
- **LCP (Largest Contentful Paint):** < 2.5s
- **TTI (Time to Interactive):** < 3.8s
- **Critical CSS Size:** < 14KB
- **Unused CSS:** < 20%

### **Testing Tools**

- **Google Lighthouse** - Overall performance score
- **WebPageTest** - Detailed waterfall analysis
- **Chrome DevTools** - Coverage and Performance tabs
- **PageSpeed Insights** - Real-world data

---

## **15. Migration from Traditional Loading**

### **Before (Traditional)**

```php
function my_plugin_enqueue_scripts() {
    wp_enqueue_script('my-widget', plugin_dir_url(__FILE__) . 'js/widget.js');
    wp_enqueue_style('my-widget', plugin_dir_url(__FILE__) . 'css/widget.css');
}
add_action('wp_enqueue_scripts', 'my_plugin_enqueue_scripts');
```

### **After (Dynamic)**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-widget',
        'selector' => '.my-widget',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/widget.js'
    );
    $assets[] = array(
        'handle' => 'my-widget-styles',
        'selector' => '.my-widget',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/widget.css'
    );
    return $assets;
});
```

---

*Document version 1.0 - Last updated: January 2026*
