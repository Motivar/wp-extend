# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Dynamic Asset Loader System**: Created a performance optimization system that loads scripts and styles only when their corresponding DOM elements are present on the page.
  - **Original Question**: "create a php class which will do the following: 1. register a global script 2. this script will have a localize-script function which will be an empty array with apply_filters 3. developers can register from the apply_filter above their scripts/styles to be dynamically imported based on if DOM element exists"
  - **Solution**: Implemented `Dynamic_Asset_Loader` PHP class with `ewp_register_dynamic_assets` filter hook and JavaScript `EWPDynamicAssetLoader` class that monitors DOM using MutationObserver and dynamically injects assets as ES6 modules when their selectors are detected
  - **Affected Files**: 
    - `/includes/classes/class-dynamic-asset-loader.php` (PHP class with validation, sanitization, and filter hooks)
    - `/assets/js/class-dynamic-asset-loader.js` (JavaScript module for DOM monitoring and dynamic loading)
    - `/includes/classes/Setup.php` (integration)
    - `/docs/dynamic-asset-loader.md` (comprehensive documentation)
    - `/examples/dynamic-asset-loader-example.php` (usage examples)
  - **Features**:
    - Filter-based registration system for developers
    - DOM-based conditional loading (only loads when selector exists)
    - Automatic dependency handling
    - Script localization support
    - MutationObserver for dynamic content
    - Custom events for tracking (`ewp_dynamic_asset_loading`, `ewp_dynamic_asset_loaded`)
    - Support for both scripts and styles
    - ES6 module loading
    - Comprehensive validation and sanitization
  - **Performance Benefits**: Reduces unnecessary HTTP requests, improves page load times, and optimizes Core Web Vitals by loading assets only when needed
  - **Backwards Compatibility**: Fully backwards compatible - new opt-in system that doesn't affect existing asset loading
  - **PageSpeed Optimizations**: Enhanced with Google PageSpeed Insights optimizations
    - **Resource Hints**: Automatic `preconnect` and `dns-prefetch` for external domains to reduce DNS lookup time
    - **Preload Links**: Support for `<link rel="preload">` to prioritize critical resources
    - **Lazy Loading**: Intersection Observer-based lazy loading for below-the-fold assets
    - **Async/Defer**: Script loading control with `async` and `defer` attributes
    - **Critical Assets**: Priority loading for above-the-fold content
    - **Performance API**: Built-in performance monitoring with `performance.mark()` and `performance.measure()`
    - **Core Web Vitals**: Optimized for LCP (Largest Contentful Paint), FID (First Input Delay), and CLS (Cumulative Layout Shift)
    - **Filters**: Configurable via filters (`ewp_dynamic_assets_lazy_load`, `ewp_dynamic_assets_root_margin`, `ewp_dynamic_assets_intersection_threshold`, `ewp_dynamic_assets_resource_hints`, `ewp_dynamic_assets_preload`, `ewp_dynamic_assets_critical_css`)
  - **Documentation**: Added comprehensive PageSpeed optimization guide at `/docs/pagespeed-optimization.md` with real-world examples and Core Web Vitals strategies
  - **Public API Methods**: Added JavaScript methods for manual asset control:
    - `checkAssets()` - Manually check and load assets after AJAX calls
    - `loadAssetByHandle(handle)` - Load specific asset by handle
    - `forceLoadAsset(handle)` - Force load asset bypassing DOM check
    - `isAssetLoaded(handle)` - Check if asset is loaded
    - `getLoadedAssets()` - Get array of loaded asset handles
    - `getRegisteredAssets()` - Get array of registered asset configurations
  - **Critical CSS Support**: Added inline critical CSS for style assets
    - `critical_css` parameter for inlining above-the-fold CSS
    - Outputs in `<head>` before external stylesheets load
    - Improves FCP (First Contentful Paint) and LCP scores
    - Filterable via `ewp_dynamic_assets_critical_css` hook
  - **Context-Specific Loading**: Added `context` parameter to control where assets load
    - `'frontend'` - Load only on public-facing pages
    - `'admin'` - Load only in WordPress admin
    - `'both'` - Load everywhere (default)
    - Reduces unnecessary script loading in admin/frontend
  - **Debug Mode**: Added WP_DEBUG integration for development logging
    - Automatically enabled when `WP_DEBUG` is true
    - Comprehensive console logging for asset lifecycle
    - Zero performance impact in production
    - Logs initialization, DOM checks, asset loading, and errors
    - **Static Log Method**: Added `EWPDynamicAssetLoader.log()` for global developer use
      - Available in all dynamically loaded scripts
      - Respects WP_DEBUG setting automatically
      - Consistent logging format across all scripts
  - **Simplified Architecture**: Removed MutationObserver, IntersectionObserver, and periodic checks
    - Cleaner, more performant implementation
    - Single DOM check on page load
    - Developers manually trigger `checkAssets()` after AJAX/dynamic content
    - Reduced ~120 lines of code
    - No continuous DOM monitoring overhead
- **Default Value Support for Input Fields**: Added ability to set default values for all input field types using the `default` key in field definitions.
  - **Original Question**: "Is it possible for the simple inputs to set default value if value is not set?"
  - **Solution**: Enhanced `awm_show_content()` function to check for `default` key and apply it when field value is empty (preserves zero values)
  - **Affected Files**: `/includes/functions/library.php`
  - **Usage**: Add `'default' => 'value'` to any field definition (input, select, textarea, radio, etc.)
  - **Backwards Compatibility**: Fully backwards compatible - only applies when `default` key is explicitly set

### Fixed
- **Dynamic Asset Loader Module Type**: Fixed issue where all scripts were being loaded as ES6 modules (`type="module"`), causing errors for traditional scripts that rely on global scope. Added `module` parameter (default: `false`) to allow developers to specify whether a script should be loaded as a module or regular script.
  - **Original Issue**: Scripts like `ewp-search-script.js` that use global functions (e.g., `awm_ajax_call`) were failing because they were loaded as modules with isolated scope
  - **Solution**: Made script type configurable via `'module' => true/false` parameter in asset registration
  - **Affected Files**: 
    - `/assets/js/class-dynamic-asset-loader.js` (conditional module type)
    - `/includes/classes/class-dynamic-asset-loader.php` (parameter documentation and sanitization)
  - **Backwards Compatibility**: Defaults to `false` (regular script), so existing registrations work without changes
- **Gutenberg number attribute RangeControl min/max**: Fixed `RangeControl` not respecting `min`/`max` (and added `step`) for `number` attributes when values are `0` or provided under nested `attributes`.
  - **Original Question**: "when we have in the attribute table the column 'attributes' min/max is not getting into account by case 'number'"
  - **Solution**: Replaced falsy fallbacks (`||`) with nullish checks and introduced a safe numeric resolver that reads from both `data.min`/`data.max` and `data.attributes.min`/`data.attributes.max`.
  - **Affected Files**: `/src/index.js`
  - **Backwards Compatibility**: Fully backwards compatible - only affects number RangeControl configuration
- **Database Schema Updates**: Fixed issue where column data type changes (e.g., LONGTEXT to VARCHAR) were not being applied during table version updates. The AWM_DB_Creator class now properly detects and modifies existing columns when their definitions change, not just adds missing columns.
  - **Original Issue**: Column alterations for `cookie_id` (LONGTEXT → VARCHAR(32)) and `address` (LONGTEXT → VARCHAR(45)) in `flx_session_users` table were not being applied
  - **Solution**: Enhanced `dbUpdate()` method to compare existing column definitions with new ones and execute `ALTER TABLE MODIFY COLUMN` statements when changes are detected
  - **Affected Files**: `/includes/classes/awm-db/class-db-creator.php`
  - **Backwards Compatibility**: Fully backwards compatible - existing functionality preserved while adding column modification support

## [1.0.0] - 2024-11-14

### Added
- Initial release of the WordPress Extend plugin
- Database creation and management utilities
- Custom list management functionality
- Form handling and validation
- Admin interface enhancements
