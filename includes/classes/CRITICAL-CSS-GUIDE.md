# Critical CSS Guide - EWP Dynamic Asset Loader

## Overview

The EWP Dynamic Asset Loader provides comprehensive support for inline critical CSS to improve PageSpeed scores and Core Web Vitals. Critical CSS is the minimal CSS required to render above-the-fold content, loaded inline in the `<head>` for immediate rendering.

## Version

- **PHP Class Version**: 1.0.6
- **JS Class Version**: 1.0.5
- **Feature**: Critical CSS Support (Enhanced)

---

## Features

### 1. **Inline Critical CSS**
Inject critical CSS directly into the HTML `<head>` for immediate rendering.

### 2. **Conditional Loading**
Load critical CSS only on specific pages, post types, or templates.

### 3. **Automatic Minification**
Automatically minify critical CSS to reduce file size (enabled by default).

### 4. **Dynamic Inline Styles**
Inject inline styles dynamically via JavaScript when selectors are detected.

### 5. **Performance Optimization**
- Preload links for critical assets
- Resource hints (preconnect, dns-prefetch)
- Performance marks and measures

---

## Basic Usage

### Example 1: Simple Critical CSS (Inline String)

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-hero-styles',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'version' => '1.0.0',
        'critical_css' => '
            .hero-section {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .hero-title {
                font-size: 3rem;
                color: white;
                text-align: center;
            }
        ',
        'minify_critical' => true  // Default: true
    );
    return $assets;
});
```

**Result**: The critical CSS is inlined in the `<head>`, minified automatically. The full stylesheet loads when `.hero-section` is detected in the DOM.

---

### Example 2: Critical CSS from External File

Load critical CSS from a separate file and inline it automatically:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-hero-styles',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'version' => '1.0.0',
        'critical_src' => plugin_dir_url(__FILE__) . 'css/hero-critical.css',
        'minify_critical' => true  // Default: true
    );
    return $assets;
});
```

**Result**: The system loads `hero-critical.css`, minifies it, and inlines it in the `<head>`. The full `hero.css` loads when `.hero-section` is detected.

**Benefits**:
- Keep critical CSS in a separate file for easier maintenance
- Works with local files (faster) or remote URLs
- Automatically handles file reading and HTTP requests
- If `critical_css` is also provided, it takes precedence over `critical_src`

---

## Advanced Usage

### Example 3: Conditional Critical CSS (Post Types)

Load critical CSS only on specific post types:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'product-critical-styles',
        'selector' => '.product-page',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/product.css',
        'critical_css' => '
            .product-page { padding: 2rem; }
            .product-title { font-size: 2.5rem; font-weight: bold; }
            .product-price { color: #e74c3c; font-size: 2rem; }
        ',
        'critical_conditions' => array(
            'post_types' => array('product', 'shop')
        )
    );
    return $assets;
});
```

---

### Example 4: Conditional Critical CSS (Page Templates)

Load critical CSS only on specific page templates:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'landing-critical-styles',
        'selector' => '.landing-page',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/landing.css',
        'critical_css' => '
            .landing-page { background: #fff; }
            .cta-button { 
                background: #3498db; 
                color: white; 
                padding: 1rem 2rem;
                border-radius: 4px;
            }
        ',
        'critical_conditions' => array(
            'page_templates' => array('template-landing.php', 'template-sales.php')
        )
    );
    return $assets;
});
```

---

### Example 5: Conditional Critical CSS (WordPress Conditionals)

Use WordPress conditional tags:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'homepage-critical-styles',
        'selector' => '.homepage',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/homepage.css',
        'critical_css' => '
            .homepage { max-width: 1200px; margin: 0 auto; }
            .featured-posts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem; }
        ',
        'critical_conditions' => array(
            'is_front_page' => true,
            'is_home' => false
        )
    );
    return $assets;
});
```

**Available WordPress Conditionals**:
- `is_front_page`
- `is_home`
- `is_archive`
- `is_singular`

---

### Example 6: Conditional Critical CSS (Specific Post IDs)

Load critical CSS only on specific posts/pages:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'special-page-styles',
        'selector' => '.special-content',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/special.css',
        'critical_css' => '
            .special-content { 
                background: linear-gradient(to right, #ff6b6b, #feca57);
                padding: 3rem;
            }
        ',
        'critical_conditions' => array(
            'post_ids' => array(42, 123, 456)
        )
    );
    return $assets;
});
```

---

### Example 7: Conditional Critical CSS (Custom Callback)

Use a custom callback function for complex conditions:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'custom-condition-styles',
        'selector' => '.custom-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/custom.css',
        'critical_css' => '
            .custom-section { display: flex; flex-direction: column; }
        ',
        'critical_conditions' => array(
            'callback' => function() {
                // Load only for logged-in users on weekdays
                if (!is_user_logged_in()) {
                    return false;
                }
                $day = date('N'); // 1 (Monday) to 7 (Sunday)
                return $day >= 1 && $day <= 5;
            }
        )
    );
    return $assets;
});
```

---

### Example 8: Combined Conditions

Combine multiple conditions (all must be true):

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'blog-post-styles',
        'selector' => '.blog-post',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/blog.css',
        'critical_css' => '
            .blog-post { max-width: 800px; margin: 0 auto; }
            .blog-post h1 { font-size: 2.5rem; line-height: 1.2; }
        ',
        'critical_conditions' => array(
            'post_types' => array('post'),
            'is_singular' => true,
            'callback' => function() {
                // Only for posts with featured images
                return has_post_thumbnail();
            }
        )
    );
    return $assets;
});
```

---

## Dynamic Inline CSS (JavaScript)

### Example 9: Inline CSS via JavaScript

Instead of loading an external stylesheet, inject CSS directly:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'tooltip-inline-styles',
        'selector' => '.tooltip',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/tooltip.css', // Fallback
        'inline_css' => '
            .tooltip {
                position: absolute;
                background: rgba(0, 0, 0, 0.9);
                color: white;
                padding: 0.5rem 1rem;
                border-radius: 4px;
                font-size: 0.875rem;
                pointer-events: none;
                z-index: 9999;
            }
            .tooltip::after {
                content: "";
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: rgba(0, 0, 0, 0.9);
            }
        '
    );
    return $assets;
});
```

**Result**: When `.tooltip` is detected, the CSS is injected as an inline `<style>` tag instead of loading an external file.

---

## Performance Optimization

### Example 10: Critical Asset with Preload

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'critical-hero-styles',
        'selector' => '.hero',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'critical' => true,        // Load immediately
        'preload' => true,         // Add preload link
        'critical_css' => '
            .hero { min-height: 100vh; display: flex; }
        ',
        'resource_hints' => array(
            'preconnect' => array('https://fonts.googleapis.com'),
            'dns-prefetch' => array('https://cdn.example.com')
        )
    );
    return $assets;
});
```

---

## Parameter Precedence

### `critical_css` vs `critical_src`

When both parameters are provided, `critical_css` takes precedence:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-styles',
        'selector' => '.my-element',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/styles.css',
        'critical_src' => plugin_dir_url(__FILE__) . 'css/critical.css',  // Loaded from file
        'critical_css' => '.my-element { color: red; }'  // This takes precedence!
    );
    return $assets;
});
```

**Result**: The inline `critical_css` string will be used, and `critical_src` will be ignored.

**Best Practice**: Use one or the other, not both:
- Use `critical_css` for small, inline CSS strings
- Use `critical_src` for larger CSS files or when you want to maintain critical CSS separately

---

## Filters & Hooks

### Filter: `ewp_dynamic_assets_critical_css`

Modify critical CSS before output:

```php
add_filter('ewp_dynamic_assets_critical_css', function($critical_css, $assets) {
    // Add custom CSS to all critical styles
    foreach ($critical_css as $handle => $css) {
        $critical_css[$handle] = '/* Custom prefix */ ' . $css;
    }
    return $critical_css;
}, 10, 2);
```

### Action: `ewp_dynamic_assets_critical_css_output`

Execute code after critical CSS is output:

```php
add_action('ewp_dynamic_assets_critical_css_output', function($critical_css) {
    // Log critical CSS output for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Critical CSS output: ' . print_r(array_keys($critical_css), true));
    }
});
```

---

## Disable Minification

To disable automatic minification:

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'my-styles',
        'selector' => '.my-element',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/styles.css',
        'critical_css' => '
            .my-element {
                /* This will NOT be minified */
                display: flex;
                flex-direction: column;
            }
        ',
        'minify_critical' => false  // Disable minification
    );
    return $assets;
});
```

---

## Best Practices

### 1. **Keep Critical CSS Minimal**
Only include styles for above-the-fold content (typically < 14KB).

### 2. **Use Conditions Wisely**
Avoid loading critical CSS on every page if it's only needed on specific pages.

### 3. **Test Performance**
Use tools like Google PageSpeed Insights, Lighthouse, or WebPageTest to measure impact.

### 4. **Combine with Preload**
Use `preload => true` for critical assets to hint the browser to load them early.

### 5. **Monitor File Size**
Minification is enabled by default, but always check the final output size.

### 6. **Use Inline CSS for Small Styles**
For very small CSS (< 1KB), use `inline_css` instead of external files.

---

## Debugging

Enable debug mode to see loader activity in the browser console:

```php
add_filter('ewp_dynamic_assets_debug_filter', '__return_true');
```

Or set `WP_DEBUG` to `true` in `wp-config.php`:

```php
define('WP_DEBUG', true);
```

---

## JavaScript API

### Check if Asset is Loaded

```javascript
if (window.EWPDynamicAssetLoader.instance.isAssetLoaded('my-hero-styles')) {
    console.log('Hero styles are loaded');
}
```

### Force Load Asset

```javascript
window.EWPDynamicAssetLoader.instance.forceLoadAsset('my-hero-styles', 'style');
```

### Get Performance Metrics

```javascript
const metrics = window.EWPDynamicAssetLoader.instance.getPerformanceMetrics();
console.log('Asset load times:', metrics);
```

---

## Complete Example: E-commerce Product Page

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Critical product page styles
    $assets[] = array(
        'handle' => 'product-critical',
        'selector' => '.product-page',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/product.css',
        'version' => '1.0.0',
        'critical' => true,
        'preload' => true,
        'critical_css' => '
            .product-page { max-width: 1200px; margin: 0 auto; padding: 2rem; }
            .product-header { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
            .product-image { width: 100%; height: auto; }
            .product-title { font-size: 2.5rem; font-weight: bold; margin-bottom: 1rem; }
            .product-price { color: #e74c3c; font-size: 2rem; font-weight: bold; }
            .add-to-cart { 
                background: #27ae60; 
                color: white; 
                padding: 1rem 2rem;
                border: none;
                border-radius: 4px;
                font-size: 1.125rem;
                cursor: pointer;
            }
        ',
        'critical_conditions' => array(
            'post_types' => array('product'),
            'is_singular' => true
        ),
        'minify_critical' => true,
        'resource_hints' => array(
            'preconnect' => array('https://cdn.shopify.com'),
            'dns-prefetch' => array('https://images.example.com')
        )
    );

    // Product gallery (inline CSS)
    $assets[] = array(
        'handle' => 'product-gallery',
        'selector' => '.product-gallery',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/gallery.css',
        'inline_css' => '
            .product-gallery { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
            .gallery-item { cursor: pointer; transition: transform 0.2s; }
            .gallery-item:hover { transform: scale(1.05); }
        '
    );

    return $assets;
});
```

---

## Support

For issues or questions, please refer to the main plugin documentation or contact support.

**Version**: 1.0.6  
**Last Updated**: 2026-05-20
