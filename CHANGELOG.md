# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Abstract Global Logger System**: Created a comprehensive, plugin-agnostic logging system for extend-wp.
  - **Original Request**: "Create an abstract global logger for the plugin — admins configure retention/storage/level, libraries register action types, queue-based for performance, editor/developer levels."
  - **Summary**:
    - Core singleton `EWP_Logger` with static API: `::log()`, `::register_action_type()`, `::get_logs()`
    - Global helpers: `ewp_log()` and `ewp_register_log_type()` for easy external plugin use
    - Two storage backends: Database (custom table via `AWM_DB_Creator`) and File (JSON-lines in `wp-content/ewp-logs/`)
    - In-memory queue (`EWP_Logger_Queue`) with batch flush on shutdown — zero runtime performance impact
    - Log entry structure: `owner`, `action_type`, `object_type`, `behaviour` (bool), `level` (editor/developer), `user_id`, `object_id`, `message`, `data`, `created_at`
    - Admin settings page (storage backend, retention period 1–24 months, default view level, enable/disable)
    - Cron-based data retention cleanup (`ewp_logger_cleanup_hook`)
    - Auto-logging on 8 existing EWP hooks: content save/delete, meta update, DB update, CPT/taxonomy registration, cache flush, gallery save
    - Each auto-log individually disableable via `ewp_logger_auto_log_{$action_type}_enabled` filter
    - REST API endpoint `GET /extend-wp/v1/logs` (administrator-only) with full filtering and pagination
    - Additional endpoints: `/logs/types`, `/logs/owners`
    - WP-CLI commands: `wp ewp log list`, `wp ewp log cleanup`, `wp ewp log stats`, `wp ewp log types`
    - Lightweight AJAX-powered log viewer admin page with filters, expandable detail rows, pagination
    - JS/CSS loaded via Dynamic Asset Loader (selector: `.ewp-log-viewer-wrap`, admin context only)
    - Core data stored raw (language-neutral); labels translated via `__()` on output only
    - Comprehensive filter hooks for all operations (`ewp_logger_before_log`, `ewp_logger_storage_backend`, `ewp_logger_registered_types`, etc.)
  - **Affected Files**:
    - `includes/classes/ewp-logger/` (11 new PHP files)
    - `assets/js/admin/class-ewp-log-viewer.js` (new)
    - `assets/css/admin/ewp-log-viewer.css` (new)
    - `includes/classes/Setup.php` (updated — added logger require and init)
  - **Backwards-compatibility**: Fully backwards compatible — new opt-in system. No changes to existing functionality.

- **Logger: Request ID Grouping**: Log entries from the same HTTP request are now grouped together.
  - **Original Request**: "Can we somehow wrap the logs based on the action that triggered them — like if we click a refresh and a lot of different post types have been registered then give the runtime an ID?"
  - **Summary**:
    - Added `request_id` (unique 16-char hex per PHP request) and `request_context` (HTTP method + URI, or CLI command) to every log entry
    - DB schema bumped to v1.1.0 with new indexed columns
    - Log viewer groups consecutive entries with the same `request_id` under a collapsible header showing context, count, and timestamp
    - REST API supports `request_id` filter parameter
    - All storage backends (DB + File) updated
  - **Affected Files**: `class-ewp-logger.php`, `class-ewp-logger-db.php`, `class-ewp-logger-file.php`, `class-ewp-logger-storage.php`, `class-ewp-logger-api.php`, `class-ewp-log-viewer.js`, `ewp-log-viewer.css`

### Added
- **Logger: SCSS Source**: Created `_ewp-log-viewer.scss` with proper SCSS nesting. CSS is now compiled to `ewp-log-viewer.min.css`.
  - **Affected Files**: `assets/css/admin/sass/_ewp-log-viewer.scss`, `assets/css/admin/ewp-log-viewer.min.css`, `class-ewp-logger-viewer.php`

- **Logger: Options Save Auto-Hook**: EWP options page saves are now logged at `developer` level with action type `options_save`. Uses `updated_option` hook with static flag to log once per page save. Detects EWP pages via `awm_metabox_case` POST field.
  - **Affected Files**: `class-ewp-logger.php`

- **Logger: Default Owner Filter**: Log viewer now defaults to `extend-wp` owner filter via `data-default-owner` attribute. Restored on reset.
  - **Affected Files**: `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`

### Changed
- **Logger: Settings on Main EWP Page**: Logger settings now appear as a "Logger Settings" section on the main Extend WP admin page (via `ewp_admin_fields_filter`) instead of a separate options page. Follows the same section + include pattern as `ewp_dev_settings` / `ewp_auto_export_settings`. All fields stored as a single serialised array in `wp_options` under key `ewp_logger_settings` with short keys (`enabled`, `storage`, `retention_months`). Removed separate `ewp-logger-settings` options page.
  - **Affected Files**: `class-ewp-logger-settings.php`, `class-ewp-logger.php`, `class-ewp-logger-cleanup.php`, `class-ewp-logger-viewer.php`

- **Logger: 3-State Behaviour**: The `behaviour` field now supports 3 values: `0` = error, `1` = success, `2` = warning (was previously boolean 0/1). Constants `EWP_Logger::BEHAVIOUR_ERROR`, `BEHAVIOUR_SUCCESS`, `BEHAVIOUR_WARNING` added. `normalize_behaviour()` handles backwards-compatible bool→int conversion. Viewer filter dropdown, JS rendering, CSS styles, and REST API all updated for the warning state.
  - **Original Request**: "Add to behaviour the type warning so the values should be 0 → error, 1 → success, 2 → warning."
  - **Affected Files**: `class-ewp-logger.php`, `class-ewp-logger-storage.php`, `class-ewp-logger-api.php`, `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`, `ewp-log-viewer.css`, `logger-functions.php`
  - **Backwards-compatibility**: Existing `true`/`false` values are auto-cast via `normalize_behaviour()`. DB TINYINT column already supports 0–2.

- **Logger: Log Directory to Uploads**: File storage now defaults to `{uploads}/ewp-logs` instead of `wp-content/ewp-logs`. Works correctly in standard WP and Bedrock environments. Improved `.htaccess` with Apache 2.2/2.4 compat rules, added `index.html` fallback. Still customizable via `ewp_logger_file_directory` filter.
  - **Affected Files**: `class-ewp-logger-file.php`

- **Logger: Dynamic Settings**: `get_settings()` now derives all keys and defaults dynamically from `get_settings_fields()` — no hardcoded keys. Single source of truth is the field definitions. Removed `default_level` setting. All consumers updated to use full option keys (`ewp_logger_enabled`, `ewp_logger_storage`, `ewp_logger_retention_months`). Added `ewp_logger_resolved_settings` filter.
  - **Original Request**: "Never hardcode the keys — get all variables from get_settings_fields, check which have defaults, and save settings based on that."
  - **Affected Files**: `class-ewp-logger-settings.php`, `class-ewp-logger.php`, `class-ewp-logger-cleanup.php`, `class-ewp-logger-viewer.php`

- **Logger: Content Save Data**: The `content_save` auto-hook now stores actual filtered field values instead of only field names (`array_keys`). WordPress boilerplate fields (nonces, referers, meta-box internals) are automatically stripped via `EWP_Logger::filter_wp_noise()`. Customizable via `ewp_logger_wp_noise_keys` filter.

### Removed
- **Logger: CPT/Taxonomy Registration Auto-Logs**: Removed `cpt_registered` and `taxonomy_registered` auto-hooks and built-in type registrations. These fired on every PHP request (including REST, AJAX, `.map` files), flooding the log with developer noise. Plugins can still manually call `ewp_log()` for these events if needed.

- **Logger: Gallery Save Auto-Log**: Removed `gallery_save` auto-hook and built-in type registration. Developer-level noise that added little value.
  - **Affected Files**: `class-ewp-logger.php`

### Fixed
- **Logger: CPT Registration Warning**: Fixed "Array to string conversion" warning in the `cpt_registered` auto-hook — `ewp_register_post_type_action` passes `$type` as an array, now correctly extracts `$type['post']` as the slug.

- **Multiple Select Support in Gutenberg Blocks**: Select fields with `'attributes' => array('multiple' => true)` now render as multi-select in the block editor.
  - **Original Request**: "For the select property if we have attribute multiple = 1 can we enable multiple select box in UI?"
  - **Summary**:
    - PHP: Detect `multiple` attribute in `prepare_attributes()` and propagate flag + set type to `array`
    - JS: Pass `multiple` prop to `SelectControl`, handle array values for multi-select
    - Fixed default value for array-type attributes (empty array instead of empty string)
    - Fixed nullish value checks so empty arrays are not discarded during initialization
  - **Affected Files**:
    - `includes/classes/ewp-gutenburg/class-register.php`
    - `src/index.js`
  - **Backwards-compatibility**: No breaking changes. Single select fields remain unchanged.

### Changed
- **npm dependencies & Block API v3 upgrade**: Resolved 49 npm audit vulnerabilities and WordPress 6.9 Block API deprecation warnings.
  - **Original Request**: "npm audit fix --force causes errors — update packages to be up to date and fix block API deprecation warnings"
  - **Summary**:
    - Upgraded `@wordpress/scripts` from `^19.2.4` to `^30.7.0` (resolves all high/critical vulnerabilities)
    - Removed redundant devDependencies (`@babel/core`, `@babel/preset-env`, `@babel/preset-react`, `babel-loader`, `webpack`, `webpack-cli`)
    - Removed unnecessary bundled `react`/`react-dom` dependencies (WordPress provides React via `wp-element`)
    - Deleted standalone `.babelrc` (handled internally by `@wordpress/scripts`)
    - Simplified `webpack.config.js` to use `@wordpress/scripts` defaults
    - Added `api_version: 3` to PHP `register_block_type()` and `apiVersion: 3` to JS `registerBlockType()`
    - Build scripts updated to use `wp-scripts build` / `wp-scripts start`
    - Replaced `console.log`/`console.warn` in `src/index.js` with `EWPDynamicAssetLoader.log` dev debugging (behind feature flag)
    - Fixed first preview loading without input values: added mount `useEffect` that flushes all initial/default attribute values through `setAttributes`, ensuring the first preview request includes all field data
  - **Affected Files**:
    - `package.json` (updated dependencies and scripts)
    - `.babelrc` (deleted)
    - `webpack.config.js` (simplified)
    - `includes/classes/ewp-gutenburg/class-register.php` (added `api_version: 3`)
    - `src/index.js` (added `apiVersion: 3`, replaced console.log with ewpLog helper)
  - **Backwards-compatibility**:
    - Requires WordPress 6.5+ (for `react-jsx-runtime` script handle and `apiVersion: 3`)
    - Requires Node 18+ for development builds
    - No PHP API changes — only added `api_version` key to block registration
    - 2 remaining moderate `webpack-dev-server` vulnerabilities are dev-only (not in production)

### Changed
- **Gallery & Image Field Unification Refactor**: Unified `image` and `awm_gallery` field cases into a single reusable `awm_media_field_html()` function.
  - **Original Request**: "Refactor gallery-meta-box: remove old class, recreate awm_gallery at awm_show_content with select/remove/reorder/pre-select, make image case use same function with limit 1, Gutenberg compatible"
  - **Summary**:
    - Removed legacy `Truongwp_Gallery_Meta_Box` class (`includes/classes/gallery-meta-box/`)
    - Created `EWP_Gallery_Meta_Box` class in `includes/classes/ewp-gallery/` — preserves all filter hooks (`gallery_meta_box_post_types`, `gallery_meta_box_meta_key`, `gallery_meta_box_save` action), auto-registers meta boxes, handles save + featured image, thumbnail admin column
    - Created `awm_media_field_html()` — unified PHP function for both gallery (multi) and image (single) modes
    - Created `AWMMediaField` vanilla JS class (`assets/js/admin/class-awm-media-field.js`) — per-field wp.media frame, pre-selects existing images, drag-to-reorder sortable, remove
    - Assets loaded via Dynamic Asset Loader (`ewp_register_dynamic_assets` filter, selector: `.awm-media-field`)
    - Added `image` render_type to Gutenberg blocks (`class-register.php` + `src/index.js`) — single image picker using `MediaUpload` with `multiple={false}`
    - Deprecated `awm_custom_image_image_uploader_field()` and `awm_gallery_meta_box_html()` (kept as wrappers)
    - Removed old jQuery gallery/image code from `awm-admin-script.js`
  - **Affected Files**:
    - `includes/classes/gallery-meta-box/` (deleted)
    - `includes/classes/ewp-gallery/class-ewp-gallery.php` (new)
    - `includes/classes/Setup.php` (updated require)
    - `includes/functions/library.php` (new function + updated cases + deprecated old functions)
    - `includes/classes/ewp-gutenburg/class-register.php` (added `image` case)
    - `src/index.js` (added `image` render_type)
    - `assets/js/admin/class-awm-media-field.js` (new)
    - `assets/css/admin/awm-media-field.css` (new)
    - `assets/js/admin/awm-admin-script.js` (removed old gallery/image code)
  - **Backwards-compatibility**:
    - Filter hooks `gallery_meta_box_post_types`, `gallery_meta_box_post_types_filter`, `gallery_meta_box_meta_key` preserved
    - `EWP_WP_Content_Installer::gallery()` works unchanged
    - Old functions kept as deprecated wrappers
    - CSS classes `.awm-gallery-images-list`, `.awm-gallery-image`, `.awm-remove-image` preserved
    - Data format unchanged (single ID for image, array for gallery)

### Fixed
- **`awm_ajax_call` GET callbacks crash**: Fixed `TypeError: null is not an object (evaluating 'options.data.name')` in AJAX success callbacks for GET requests. The GET serialization logic was nullifying `Options.data` after appending params to the URL, breaking callbacks (`awm_show_query_details`, `awm_show_field_details`, `awm_show_position_settings`) that relied on `options.data`. Replaced `Options.data = null` with a `_dataSerialized` flag so the original data remains accessible to callbacks.
  - **Affected Files**: `/assets/js/global/awm-global-script.js`
  - **Backwards-compatibility**: Fully backwards-compatible; no changes to public API

### Changed
- **`awm_ajax_call` Refactored**: Improved AJAX utility function with bug fixes, error callback support, and logging.
  - **Original Request**: "Review awm_ajax_call, add error callback for 4xx, fix GET bug, add comments, use EWPDynamicAssetLoader.log"
  - **Changes**:
    - Fixed GET request data serialization bug — now handles both object and array data types correctly
    - Added `errorCallback` option for 4xx client error handling with structured error data
    - Added `awm_ajax_call_error` custom event dispatched on all errors for global listeners
    - Replaced custom logging with `EWPDynamicAssetLoader.log()` for consistency across codebase
    - Added comprehensive try/catch blocks at every critical point
    - Added full JSDoc documentation and inline comments
  - **Affected Files**: `/assets/js/global/awm-global-script.js`
  - **Backwards-compatibility**: Fully backwards-compatible; `errorCallback` is optional, existing callers unaffected

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
