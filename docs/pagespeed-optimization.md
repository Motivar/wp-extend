# PageSpeed Optimization Guide

## Overview

The Dynamic Asset Loader includes advanced PageSpeed optimization features designed to improve Google PageSpeed Insights scores and Core Web Vitals metrics.

## PageSpeed Features

### 1. Resource Hints (Preconnect & DNS-Prefetch)

Establish early connections to external domains to reduce latency.

**Benefits:**
- Reduces DNS lookup time
- Establishes TCP connections early
- Improves Time to First Byte (TTFB)
- Better Largest Contentful Paint (LCP)

**Usage:**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'google-fonts',
        'selector' => 'body',
        'type' => 'style',
        'src' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
        'version' => '1.0.0',
        'resource_hints' => array(
            'preconnect' => array(
                'https://fonts.googleapis.com',
                'https://fonts.gstatic.com'
            )
        )
    );
    
    return $assets;
});
```

**Output in HTML:**
```html
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

### 2. Preload Critical Assets

Preload critical resources for faster initial rendering.

**Benefits:**
- Faster First Contentful Paint (FCP)
- Improved Largest Contentful Paint (LCP)
- Reduces render-blocking time

**Usage:**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Critical above-the-fold CSS
    $assets[] = array(
        'handle' => 'hero-styles',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'version' => '1.0.0',
        'preload' => true,
        'critical' => true
    );
    
    return $assets;
});
```

**Output in HTML:**
```html
<link rel="preload" href="/wp-content/plugins/my-plugin/css/hero.css" as="style">
```

### 3. Lazy Loading with Intersection Observer

Load assets only when they enter the viewport.

**Benefits:**
- Reduces initial page weight
- Improves Time to Interactive (TTI)
- Better First Input Delay (FID)
- Saves bandwidth

**Usage:**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Lazy load below-the-fold content
    $assets[] = array(
        'handle' => 'testimonials-slider',
        'selector' => '.testimonials-section',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/slider.js',
        'version' => '1.0.0',
        'lazy' => true
    );
    
    return $assets;
});
```

**Configure Intersection Observer:**

```php
// Adjust when assets start loading
add_filter('ewp_dynamic_assets_root_margin', function($margin) {
    return '100px'; // Start loading 100px before element enters viewport
});

add_filter('ewp_dynamic_assets_intersection_threshold', function($threshold) {
    return 0.25; // Load when 25% of element is visible
});
```

### 4. Async & Defer Script Loading

Control script execution timing to prevent render blocking.

**Benefits:**
- Eliminates render-blocking JavaScript
- Improves FCP and LCP
- Better Time to Interactive (TTI)

**Async vs Defer:**
- **Async**: Downloads in parallel, executes immediately when ready
- **Defer**: Downloads in parallel, executes after HTML parsing

**Usage:**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Async for independent scripts
    $assets[] = array(
        'handle' => 'analytics',
        'selector' => 'body',
        'type' => 'script',
        'src' => 'https://analytics.example.com/tracker.js',
        'version' => '1.0.0',
        'async' => true
    );
    
    // Defer for scripts that need DOM
    $assets[] = array(
        'handle' => 'interactive-map',
        'selector' => '[data-map]',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/map.js',
        'version' => '1.0.0',
        'defer' => true
    );
    
    return $assets;
});
```

### 5. Critical Asset Priority

Mark assets as critical for immediate loading.

**Benefits:**
- Prioritizes above-the-fold content
- Improves perceived performance
- Better LCP scores

**Usage:**

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'critical-styles',
        'selector' => 'body',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/critical.css',
        'version' => '1.0.0',
        'critical' => true,
        'preload' => true
    );
    
    return $assets;
});
```

### 6. Performance Monitoring

Track asset loading times using the Performance API.

**JavaScript API:**

```javascript
// Get performance metrics
const metrics = window.EWPDynamicAssetLoader.getPerformanceMetrics();
console.log(metrics);

// Log summary to console
window.EWPDynamicAssetLoader.logPerformanceSummary();
```

**Output:**
```
EWP Dynamic Asset Loader - Performance Summary
  my-widget-script: 45.23ms
  custom-gallery-style: 23.45ms
  slider-script: 67.89ms
```

## PageSpeed Optimization Strategies

### Strategy 1: Above-the-Fold Optimization

Load critical above-the-fold content immediately, defer everything else.

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Critical: Hero section (loads immediately)
    $assets[] = array(
        'handle' => 'hero-styles',
        'selector' => '.hero-section',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/hero.css',
        'critical' => true,
        'preload' => true
    );
    
    // Non-critical: Footer (lazy loads)
    $assets[] = array(
        'handle' => 'footer-styles',
        'selector' => '.site-footer',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/footer.css',
        'lazy' => true
    );
    
    return $assets;
});
```

### Strategy 2: Third-Party Script Optimization

Optimize external scripts with resource hints and async loading.

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    $assets[] = array(
        'handle' => 'google-maps',
        'selector' => '[data-map]',
        'type' => 'script',
        'src' => 'https://maps.googleapis.com/maps/api/js?key=YOUR_KEY',
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
});
```

### Strategy 3: Progressive Enhancement

Load basic functionality first, enhance with additional features.

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Basic functionality (critical)
    $assets[] = array(
        'handle' => 'core-functionality',
        'selector' => '.app-container',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/core.js',
        'critical' => true,
        'defer' => true
    );
    
    // Enhanced features (lazy)
    $assets[] = array(
        'handle' => 'enhanced-features',
        'selector' => '.app-container',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/enhanced.js',
        'lazy' => true,
        'dependencies' => array('core-functionality')
    );
    
    return $assets;
});
```

### Strategy 4: Conditional Loading by Device

Load different assets based on viewport or device capabilities.

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Mobile-specific assets
    if (wp_is_mobile()) {
        $assets[] = array(
            'handle' => 'mobile-menu',
            'selector' => '.mobile-menu-toggle',
            'type' => 'script',
            'src' => plugin_dir_url(__FILE__) . 'js/mobile-menu.js',
            'defer' => true
        );
    } else {
        // Desktop-specific assets
        $assets[] = array(
            'handle' => 'desktop-menu',
            'selector' => '.desktop-menu',
            'type' => 'script',
            'src' => plugin_dir_url(__FILE__) . 'js/desktop-menu.js',
            'defer' => true
        );
    }
    
    return $assets;
});
```

## Core Web Vitals Impact

### Largest Contentful Paint (LCP)

**Target:** < 2.5 seconds

**Optimizations:**
- Use `preload` for critical images/fonts
- Use `resource_hints` for external domains
- Mark above-the-fold assets as `critical`
- Use `lazy` for below-the-fold content

**Example:**
```php
$assets[] = array(
    'handle' => 'hero-image-script',
    'selector' => '.hero-image',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/hero-image.js',
    'critical' => true,
    'preload' => true,
    'resource_hints' => array(
        'preconnect' => array('https://cdn.example.com')
    )
);
```

### First Input Delay (FID)

**Target:** < 100 milliseconds

**Optimizations:**
- Use `async` or `defer` for all scripts
- Use `lazy` for non-interactive elements
- Split large scripts into smaller chunks

**Example:**
```php
$assets[] = array(
    'handle' => 'interactive-form',
    'selector' => '.contact-form',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/form.js',
    'defer' => true,
    'lazy' => true
);
```

### Cumulative Layout Shift (CLS)

**Target:** < 0.1

**Optimizations:**
- Load styles before scripts
- Use `preload` for critical fonts
- Ensure proper sizing for dynamic content

**Example:**
```php
// Load styles first
$assets[] = array(
    'handle' => 'widget-styles',
    'selector' => '.widget',
    'type' => 'style',
    'src' => plugin_dir_url(__FILE__) . 'css/widget.css',
    'critical' => true
);

// Then load scripts
$assets[] = array(
    'handle' => 'widget-script',
    'selector' => '.widget',
    'type' => 'script',
    'src' => plugin_dir_url(__FILE__) . 'js/widget.js',
    'defer' => true,
    'dependencies' => array('widget-styles')
);
```

## Performance Filters

### Disable Lazy Loading Globally

```php
add_filter('ewp_dynamic_assets_lazy_load', '__return_false');
```

### Adjust Intersection Observer Settings

```php
// Load assets earlier (200px before viewport)
add_filter('ewp_dynamic_assets_root_margin', function() {
    return '200px';
});

// Require more visibility before loading (50%)
add_filter('ewp_dynamic_assets_intersection_threshold', function() {
    return 0.5;
});
```

### Customize Resource Hints

```php
add_filter('ewp_dynamic_assets_resource_hints', function($hints) {
    // Add global preconnect
    $hints['preconnect'][] = 'https://cdn.example.com';
    
    // Add global dns-prefetch
    $hints['dns-prefetch'][] = 'https://analytics.example.com';
    
    return $hints;
});
```

### Customize Preload Assets

```php
add_filter('ewp_dynamic_assets_preload', function($assets) {
    // Only preload critical styles
    return array_filter($assets, function($asset) {
        return $asset['type'] === 'style' && $asset['critical'];
    });
});
```

## Testing & Monitoring

### Google PageSpeed Insights

Test your site at: https://pagespeed.web.dev/

**Key Metrics to Monitor:**
- Performance Score
- First Contentful Paint (FCP)
- Largest Contentful Paint (LCP)
- Total Blocking Time (TBT)
- Cumulative Layout Shift (CLS)
- Speed Index

### Chrome DevTools

**Performance Tab:**
1. Open DevTools (F12)
2. Go to Performance tab
3. Record page load
4. Check for:
   - Long tasks (> 50ms)
   - Render-blocking resources
   - Layout shifts

**Network Tab:**
1. Check waterfall chart
2. Look for:
   - Parallel downloads
   - Early connections (preconnect)
   - Deferred scripts

**Console:**
```javascript
// View performance metrics
window.EWPDynamicAssetLoader.logPerformanceSummary();

// Check loaded assets
console.log(window.EWPDynamicAssetLoader.getLoadedAssets());
```

### WebPageTest

Test at: https://www.webpagetest.org/

**Check for:**
- Start Render time
- First Contentful Paint
- Speed Index
- Time to Interactive

## Best Practices Summary

### ✅ DO

- **Use `critical: true`** for above-the-fold assets
- **Use `lazy: true`** for below-the-fold assets
- **Use `preload: true`** for critical resources
- **Use `resource_hints`** for external domains
- **Use `async`** for independent scripts
- **Use `defer`** for DOM-dependent scripts
- **Monitor performance** with built-in metrics
- **Test regularly** with PageSpeed Insights

### ❌ DON'T

- Don't mark everything as critical
- Don't preload non-critical assets
- Don't use both async and defer (async takes precedence)
- Don't lazy load above-the-fold content
- Don't skip resource hints for external domains
- Don't ignore Core Web Vitals metrics

## Real-World Example: E-commerce Product Page

```php
add_filter('ewp_register_dynamic_assets', function($assets) {
    // Critical: Product images (above-the-fold)
    $assets[] = array(
        'handle' => 'product-gallery',
        'selector' => '.product-gallery',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/gallery.js',
        'critical' => true,
        'preload' => true,
        'defer' => true
    );
    
    $assets[] = array(
        'handle' => 'product-gallery-styles',
        'selector' => '.product-gallery',
        'type' => 'style',
        'src' => plugin_dir_url(__FILE__) . 'css/gallery.css',
        'critical' => true,
        'preload' => true
    );
    
    // Non-critical: Reviews (below-the-fold)
    $assets[] = array(
        'handle' => 'product-reviews',
        'selector' => '.product-reviews',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/reviews.js',
        'lazy' => true,
        'defer' => true
    );
    
    // Non-critical: Related products (below-the-fold)
    $assets[] = array(
        'handle' => 'related-products',
        'selector' => '.related-products',
        'type' => 'script',
        'src' => plugin_dir_url(__FILE__) . 'js/related.js',
        'lazy' => true,
        'async' => true
    );
    
    // External: Payment gateway (with resource hints)
    $assets[] = array(
        'handle' => 'payment-gateway',
        'selector' => '.checkout-form',
        'type' => 'script',
        'src' => 'https://payment.example.com/sdk.js',
        'async' => true,
        'resource_hints' => array(
            'preconnect' => array('https://payment.example.com')
        )
    );
    
    return $assets;
});
```

**Expected Results:**
- **LCP:** < 2.0s (critical assets load first)
- **FID:** < 50ms (deferred/async scripts)
- **CLS:** < 0.05 (styles load before scripts)
- **Performance Score:** 90+ on mobile

## Troubleshooting

### Issue: Assets Not Preloading

**Check:**
```php
// Verify preload is enabled
add_action('wp_head', function() {
    $assets = \EWP\DynamicAssets\Dynamic_Asset_Loader::get_assets();
    error_log('Preload assets: ' . print_r(array_filter($assets, function($a) {
        return isset($a['preload']) && $a['preload'];
    }), true));
}, 3);
```

### Issue: Resource Hints Not Appearing

**Check:**
- Ensure you're on frontend (not admin)
- Check `wp_head` output
- Verify filter is registered before `wp_head` action

### Issue: Lazy Loading Not Working

**Check:**
```javascript
// Verify Intersection Observer is supported
console.log('IntersectionObserver supported:', 'IntersectionObserver' in window);

// Check if lazy loading is enabled
console.log('Lazy load enabled:', window.EWPDynamicAssetLoader.lazyLoadEnabled);
```

## Support & Resources

- **Documentation:** `/docs/dynamic-asset-loader.md`
- **Examples:** `/examples/dynamic-asset-loader-example.php`
- **Google PageSpeed:** https://pagespeed.web.dev/
- **Web.dev Core Web Vitals:** https://web.dev/vitals/
