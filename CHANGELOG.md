# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Logger Search Field** (`2026-06-24`):
  - **Question/Prompt**: "I need to add a plain text field to search in the logger view. I need to filter the data based on all filters and the search text, which can be anything in the log."
  - **Summary**: Added a plain text search field to the EWP Logger viewer that filters log entries across all text fields using case-insensitive matching with AND logic alongside existing filters. The search field is positioned after date filters and searches across: message, owner, action_type, object_type, request_context, data payload (JSON), user display name, user login, and user email. Search works in combination with all existing filters (date range, owner, action type, behaviour, level, etc.) to progressively narrow down results.
  - **Affected Modules**:
    - `class-ewp-logger-viewer.php`: Added `search_text` field to viewer UI
    - `class-ewp-logger-api.php`: Registered `search_text` parameter in REST API
    - `class-ewp-logger-storage.php`: Added `search_text` to query defaults and sanitization
    - `class-ewp-logger-file.php`: Implemented search logic in `entry_matches_filters()` method
  - **Backwards Compatibility**: Fully backwards compatible. Search parameter is optional; empty search text is ignored (no filtering applied). No JavaScript changes required as existing form serialization handles the new field automatically.

### Added
- **Custom Post Status Support for Classic, Gutenberg & Quick Edit** (`2026-06-15`):
  - **Question/Prompt**: "How to create an extra field for the array that being passed to register_post_types function to enable custom statuses, visible via the classic editor and the Gutenberg editor, and the quick editor?"
  - **Summary**: Implemented comprehensive custom post status support with strict post-type isolation. Each post type can now define its own `custom_status` array in the post type configuration, and those statuses will be visible and functional in Classic Editor, Gutenberg Editor, and Quick Edit. The system ensures that custom statuses are isolated per post type - Product statuses only appear when editing Products, Review statuses only appear when editing Reviews, etc. No cross-contamination between post types.
  - **PHP Implementation** (`class-wp-content-installer.php`):
    - `enqueue_custom_status_scripts()`: Detects current post type and localizes only that post type's custom statuses to JavaScript
    - `display_custom_status_label()`: Shows custom status labels in admin post list with post-type validation
    - `fix_custom_status_save()`: Prevents WordPress from resetting custom statuses to 'draft' and validates status belongs to post type
  - **JavaScript Implementation** (`awm-admin-script.js`):
    - `EWPCustomPostStatus` class: OOP implementation with vanilla JavaScript
    - `addToClassicEditor()`: Adds custom statuses to Classic Editor dropdown and updates publish meta box display
    - `addToGutenberg()`: Integrates with Gutenberg via wp.data API with post-type validation
    - `addToQuickEdit()`: Hooks into WordPress inline edit to add custom statuses to Quick Edit dropdown
  - **Post-Type Isolation**: Each post type receives only its own custom statuses; validation prevents cross-post-type status assignments
  - **Array Structure**: Developers add `custom_status` array to post type definitions via `epw_get_post_types` filter with status keys and configuration (label, public, show_in_admin_all_list, etc.)
  - **Affected Files**: `includes/classes/ewp-wp-content/class-wp-content-installer.php`, `assets/js/admin/awm-admin-script.js`
  - **Backwards Compatibility**: Fully backwards compatible. Post types without `custom_status` array work normally with default WordPress statuses.

### Fixed
- **Logger REST API endpoints not available when logging enabled** (`2026-06-10`):
  - **Question/Prompt**: "Why am I getting 404 on /extend-wp/v1/logs endpoint on filox site while it works in ddev? The endpoint should be available when 'enabled' is true and only to logged-in users. We need rest_api_enabled to control REST Health feature loading."
  - **Summary**: Fixed logger REST API initialization to be available whenever logging is enabled (`enabled` setting). Clarified that the `rest_api_enabled` setting **only** controls the REST Health monitoring page (`includes/classes/ewp-rest-health/`), not the logger REST API endpoints. Updated field label to "Enable REST Health Monitoring" with clearer explanation. Logger REST API endpoints (`/extend-wp/v1/logs`) are now available when `enabled` is checked, with authentication enforced via `check_permission()` (logged-in administrators only).
  - **Affected Files**: `includes/classes/ewp-logger/class-ewp-logger.php`, `includes/classes/ewp-logger/class-ewp-logger-settings.php`
  - **Backwards Compatibility**: Sites with only `enabled` checked now have working logger REST API endpoints. REST Health feature requires both `enabled` and `rest_api_enabled` to be checked.

- **EWP_Encryption class not found error** (`2026-06-10`):
  - **Question/Prompt**: "Uncaught Error: Class 'EWP_Encryption' not found in library.php on line 1122 when using encrypted fields in repeater content."
  - **Summary**: The `EWP_Encryption` class was not being autoloaded because it's a non-namespaced class in `includes/classes/class-encryption.php` but wasn't included in Composer's autoload files array. Added `includes/classes/class-encryption.php` to the `files` array in `composer.json` to ensure the class is loaded automatically. Ran `composer dump-autoload` to regenerate autoload files.
  - **Affected Files**: `composer.json`
  - **Backwards Compatibility**: No breaking changes; fixes fatal error when using encrypted fields.

### Added
- **EWP REST API Health Check Page** (`2026-06-08`):
  - **Summary**: Super-admin-only page under Extend WP → REST API Health. Combines route discovery (`rest_get_server()->get_routes()`), OpenAPI 3.0 spec generation, and Swagger UI (CDN, loaded via Dynamic Asset Loader) for interactive endpoint testing across all active plugins. Swagger "Try it out" forms are pre-filled from the user's own test history (last successful params per endpoint). All test runs logged via `ewp_log()` (owner: `ewp-rest-health`) — visible and downloadable in the EWP Logger viewer. Per-user history stored as JSON files under `uploads/ewp-rest-health/history/`. Batch testing with confirmation dialog before mutating methods.
  - **New Files**: `includes/classes/ewp-rest-health/` (5 PHP classes), `assets/js/admin/ewp-rest-health.js`, `assets/css/admin/ewp-rest-health.css`
  - **Modified Files**: `includes/classes/Setup.php`
  - **REST Endpoints** (`extend-wp/v1/rest-health/*`, super-admin only): `plugins`, `endpoints`, `openapi`, `test`, `batch`, `history`
  - **Extensibility**: `ewp_rest_health_namespace_plugin_map` filter for manual namespace→plugin overrides
- **AJAX Loading Spinner Support with Auto-Hide, Body Classes & Events** (`2026-06-05`):
  - **Question/Prompt**: "Enhance awm_ajax_call with loading support including spinner HTML, show/hide helper functions, and loadingAutoHide option for automatic spinner management. Can AWM_SPINNER_HTML be passed via localize? Add relative body classes like started, processing, succeeded, failed, event dispatched."
  - **Summary**: Implemented comprehensive loading spinner system for AJAX requests with state management. Spinner HTML is now localized via `awmGlobals.spinnerHtml` (filterable with `awm_spinner_html` filter) allowing themes/plugins to customize the spinner markup. Created `awm_show_loading(selector)` and `awm_hide_loading(selector)` helper functions with fade in/out effects (0.3s transitions). Enhanced `awm_ajax_call()` with two new options: `loading` (CSS selector for spinner container) and `loadingAutoHide` (boolean, default false). **Body state classes** automatically added/removed during AJAX lifecycle: `awm-ajax-started` (on init), `awm-ajax-processing` (on loadstart), `awm-ajax-succeeded` (on 2xx, removed after 300ms), `awm-ajax-failed` (on error, removed after 300ms). **Custom events** dispatched at each state: `awm_ajax_started`, `awm_ajax_processing`, `awm_ajax_call_callback` (success), `awm_ajax_call_error` (failure). Enables CSS-based UI changes (overlays, button states) and advanced integrations (analytics, notifications).
  - **Affected Files**: `includes/classes/class-extend-wp.php` (added spinnerHtml to awmGlobals with awm_spinner_html filter), `assets/js/global/awm-global-script.js` (helper functions, awm_ajax_call enhancement with body classes and events), `assets/css/global/sass/_loading.scss` (added .bounce3 class), `assets/js/global/LOADING_USAGE.md` (comprehensive documentation with PHP filter, body classes, events examples)
  - **Backwards Compatibility**: Fully backwards compatible. New options default to `false`, existing code continues to work without changes. Body classes and events are additive only.

### Changed
- **Loading Spinner SCSS Conversion** (`2026-06-05`):
  - **Question/Prompt**: "Convert AWM AJAX loading spinner CSS to proper SCSS with nested structure and variables."
  - **Summary**: Converted flat CSS in `_loading.scss` to proper SCSS with DRY principles. Extracted 7 variables for easy customization: `$spinner-margin-top`, `$spinner-width`, `$spinner-dot-size`, `$spinner-color`, `$spinner-duration`, `$bounce1-delay`, `$bounce2-delay`. Nested `.bounce1`, `.bounce2`, `.bounce3` selectors inside `.awm-ajax-spinner` parent. Maintained webkit prefixes for browser compatibility. Single point of truth for all spinner properties.
  - **Affected Files**: `assets/css/global/sass/_loading.scss`
  - **Backwards Compatibility**: Compiles to identical CSS output, no breaking changes.
- **Reusable `object_id_filter` field type** (`2026-06-02`):
  - **Question/Prompt**: "Create a fully reusable `object_id_filter` field type for awm_show_content that allows dynamic object selection without loading all IDs at once… can you revise this, check for bottlenecks…"
  - **Summary**: New `awm_show_content` field type that pairs a content-type selector (post types / taxonomies / custom content, grouped via optgroups) with a SlimSelect multi-select that searches matching object IDs on demand through a global REST endpoint — never loading all IDs up front. Stores **two flat meta keys** (`{field}` = the chosen `group:slug`, and `{field}_ids` / configurable `id_field_name` = the selected IDs) so both persist through the standard `awm_custom_meta[]` save pipeline. Already-selected values are **preloaded server-side** at render time (no preload round-trip). Fully configurable per field: `allowed_types`, `post_types`, `taxonomies`, `custom_content`, `id_field_name`, `min_search_chars`, `max_results`, `search_meta`.
  - **Scope decisions**: field + REST search API + JS/CSS only (no logger integration this round); top-level fields only (not repeater rows); search covers title/name **plus meta** by default (`search_meta` opt-out per field).
  - **REST**: `GET extend-wp/v1/objects/search` (`object_type`, `search`, `exclude`, `limit`, `search_meta`); capability `manage_options` (filterable via `awm_object_search_capability`), `X-WP-Nonce` verified.
  - **Reuses**: `get_post_types`/`get_taxonomies`, `AWM_Content_DB::get_content_types()`, `get_posts`/`get_terms`/`awm_get_db_content()`, the existing optgroup `select` markup + global SlimSelect, and the `awm-global-script.js` dynamic-import module pattern (SlimSelect webpack-bundled into the chunk).
  - **Filters**: `awm_object_type_options`, `awm_object_type_options_{$field}`, `awm_object_search_capability`, `awm_object_search_query_args`, `awm_object_search_results`.
  - **New Files**: `includes/classes/awm-api/class-awm-object-search-api.php`, `assets/js/modules/awm-object-id-filter-module.js`, `assets/css/admin/awm-object-id-filter.css`.
  - **Affected Files**: `includes/functions/library.php` (`awm_object_id_filter_config()`, `awm_get_object_type_options()`, `awm_object_search_query()`, `object_id_filter` render case), `includes/classes/Setup.php`, `includes/classes/class-extend-wp.php` (route registration + dynamic CSS asset), `assets/js/global/awm-global-script.js`, `examples/field-examples.php`, rebuilt `build/`.
  - **Backwards Compatibility**: Additive only — new field type and endpoint; no changes to existing field behavior.

- **Auto-sync asset version from package.json** (`2026-05-29`):
  - **Question/Prompt**: "Can we when we build the script also change the version of register_script_styles?"
  - **Summary**: Added `EmitVersionPhpPlugin` to `webpack.config.js` that writes `build/version.php` on every build containing the version from `package.json`. Defined `AWM_ASSET_VERSION` constant in `extend-wp.php` reading from that file. Replaced hardcoded `$version = 0.29` in `register_script_styles` with `AWM_ASSET_VERSION`.
  - **Affected Files**: `webpack.config.js`, `extend-wp.php`, `includes/classes/class-extend-wp.php`
  - **Backwards Compatibility**: No breaking changes; `AWM_ASSET_VERSION` falls back to `'1.0.0'` if `build/version.php` doesn't exist yet.

### Fixed
- **Scripts never run under WP Rocket Delay JavaScript execution** (`2026-05-29`):
  - **Question/Prompt**: "if we have all wp-rocket settings enabled both dynamic-asset-loader and global script did not get triggered. how to fix this?"
  - **Summary**: With WP Rocket's *Delay JavaScript execution* enabled, neither `awm-global-script.js` nor `class-dynamic-asset-loader.js` loaded, so no modules initialized. Two root causes:
    1. **Exclusion filters never registered.** `EWP_WP_Rocket` is instantiated at plugin-load time and bailed out if `WP_ROCKET_VERSION` was undefined. EWP loads before `wp-rocket` alphabetically (and when bundled inside filox), so the constant was never defined yet and the `rocket_*` exclusion filters were never added. Removed the `WP_ROCKET_VERSION` guard entirely and register the filters directly in the constructor — these hooks only fire when WP Rocket is running, so they are harmless no-ops otherwise.
    2. **Entry script not excluded from delay.** Only the dynamic loader was in the exclusion list. Added the `awm-global-script` pattern, which (matched against the full script tag for delay, and the URL path for minify) covers both the external entry script and its inline `awmGlobals` localize tag (`awm-global-script-js-extra`) — so the localized data is not delayed out from under the script.
  - **Why delay differs from minify**: minify only relocates a script (the publicPath fix handles that), but delay prevents execution until user interaction — the only way for these critical scripts to run on page load is to exclude them. This stays transparent: the plugin does it automatically via filters, no user configuration.
  - **Affected Files**: `includes/classes/ewp-third-party/class-wp-rocket.php`, `package.json`, rebuilt `build/`
  - **Backwards Compatibility**: No breaking changes. Filters still only apply when WP Rocket is active.
- **Webpack chunks 404 under WP Rocket minify/combine** (`2026-05-29`):
  - **Question/Prompt**: "this happens when we have wp-rocket minification on... what we want to achieve is even if all wp-rocket options are enabled to full load correct the modules. Can we make it work without excluding it from wp-rocket? we need this to be transparent for users."
  - **Summary**: Lazy-loaded webpack chunks (inputs/forms/repeater modules, slim-select vendor chunk, inputs CSS chunk) 404'd because their base URL (`publicPath`) was derived from the entry script's served URL. With WP Rocket minify/combine on, the entry script is served from `/wp-content/cache/min/...`, so chunks were requested from the cache dir where they don't exist (and under Combine JS the derivation collapsed entirely). Fixed by anchoring `publicPath` to a server-known absolute URL.
  - **Changes**:
    - Localized `buildUrl` (`awm_url . 'build/'`) into the `awmGlobals` object so PHP provides the real build directory URL.
    - `awm-global-script.js` now sets `__webpack_public_path__` from `awmGlobals.buildUrl`, keeping the previous script-URL derivation only as a fallback.
    - Removed the now-redundant `.chunk.js` pattern from the WP Rocket exclusion class (runtime-injected chunks are never in the HTML); kept `class-dynamic-asset-loader.js` excluded.
  - **Affected Files**: `includes/classes/class-extend-wp.php`, `assets/js/global/awm-global-script.js`, `includes/classes/ewp-third-party/class-wp-rocket.php`, `package.json`, rebuilt `build/`
  - **Backwards Compatibility**: No breaking changes. `awm_url` is derived from the plugin's actual location, so it resolves correctly standalone or when bundled inside another plugin (e.g. filox). No per-site WP Rocket exclusions required.
- **JavaScript Syntax Error in awm_tab onclick Handler** (`2026-05-28`):
  - **Question/Prompt**: "why on a page with awm_tab I get this issue? [Error] SyntaxError: Unexpected token '{'. Expected ')' to end a compound expression."
  - **Summary**: Fixed invalid JavaScript syntax in generated `onclick` attribute for tab navigation. Extra space after escaped quote was breaking the function call.
  - **Changes**: Removed space in `onclick="awm_open_tab(event,\' ' . $tab_id . '\')` to `onclick="awm_open_tab(event,\'' . $tab_id . '\')"` at line 1160
  - **Root Cause**: Generated HTML had `onclick="awm_open_tab(event,' tab_id')"` with invalid space before parameter
  - **Affected Files**: `includes/functions/library.php`
  - **Backwards Compatibility**: No breaking changes; fixes JavaScript syntax errors on pages with `awm_tab` fields
- **JavaScript Missing Variable Declaration in Inputs Module** (`2026-05-28`):
  - **Summary**: Fixed missing variable declaration in `awmMultipleCheckBox()` function
  - **Changes**: Added `var` declaration for `inputs` variable at line 391 in `awm-inputs-module.js`
  - **Affected Files**: `assets/js/modules/awm-inputs-module.js`
  - **Backwards Compatibility**: No breaking changes; prevents ReferenceError in checkbox handling
- **Repeater Callback Functions Not Exposed** (`2026-05-25`):
  - **Question/Prompt**: "I get this with repeater? I think is regarding @[conversation:"Fixing awm_init_inputs Error"]"
  - **Summary**: Fixed missing function exposures in admin script after webpack bundling migration. Functions called via `data-callback` attributes and `onclick` handlers were not accessible on window object, causing "function does not exist" errors in repeater fields.
  - **Changes**: Exposed all callback functions to window object in `awm-admin-script.js`:
    - `awm_get_case_fields`, `awm_show_field_details`
    - `awm_get_position_settings`, `awm_show_position_settings`
    - `awm_get_query_fields`, `awm_show_query_details`
    - `ewp_get_php_code`, `awm_show_php_code`
    - `awmSelectrBoxes`, `awm_options_rest_call`, `awm_rest_options_callback`
  - **Affected Files**: `assets/js/admin/awm-admin-script.js`
  - **Backwards Compatibility**: No breaking changes; ensures repeater fields work correctly with webpack bundled scripts

### Added
- **Dynamic Asset Loader: Enhanced Critical CSS Support** (`2026-05-20`):
  - **Question/Prompt**: "Enhance EWP Dynamic Asset Loader to support inline critical CSS"
  - **Summary**: Comprehensive critical CSS system with conditional loading, automatic minification, and inline style injection
  - **PHP Enhancements** (`class-dynamic-asset-loader.php` v1.0.6):
    - Added `critical_src` parameter to load critical CSS from external file and inline it automatically
    - Added `load_critical_css_file()` method to read local files or fetch remote URLs
    - Supports both local files (faster, uses `file_get_contents()`) and remote URLs (uses `wp_remote_get()`)
    - If both `critical_css` and `critical_src` are provided, `critical_css` takes precedence
    - Added `inline_css` parameter for dynamic inline style injection via JavaScript
    - Added `critical_conditions` parameter with support for:
      - Post types (`post_types` array)
      - Page templates (`page_templates` array)
      - WordPress conditionals (`is_front_page`, `is_home`, `is_archive`, `is_singular`)
      - Specific post IDs (`post_ids` array)
      - Custom callbacks (`callback` callable)
    - Added `minify_critical` parameter (default: true) for automatic CSS minification
    - Added `should_load_critical_css()` method to evaluate conditional loading rules
    - Added `minify_css()` method for automatic CSS minification (removes comments, whitespace, line breaks)
    - Added `sanitize_critical_conditions()` method for secure condition validation
    - Enhanced `add_critical_css()` method with conditional loading and minification support
    - Added `ewp_dynamic_assets_critical_css_output` action hook after CSS output
    - Enhanced `ewp_dynamic_assets_critical_css` filter with $assets parameter
  - **JavaScript Enhancements** (`class-dynamic-asset-loader.js`):
    - Added `injectInlineStyle()` method for dynamic inline CSS injection
    - Enhanced `loadStyle()` to check for `inline_css` parameter and inject styles instead of loading external files
    - Inline styles marked with `data-inline="true"` attribute for identification
    - Supports media queries and critical flags for inline styles
  - **Documentation**: Created comprehensive `CRITICAL-CSS-GUIDE.md` with 10 detailed examples covering:
    - Basic critical CSS usage
    - Conditional loading by post type, template, WordPress conditionals, post IDs, and custom callbacks
    - Combined conditions
    - Dynamic inline CSS injection
    - Performance optimization with preload and resource hints
    - Filters, hooks, and debugging
    - Complete e-commerce example
  - **Use Cases**:
    - Load critical CSS from external file (`critical_src`) for easier maintenance
    - Load critical hero section CSS only on homepage
    - Inject product page styles only on WooCommerce products
    - Conditional landing page styles based on templates
    - Inline tooltip CSS when tooltips are detected
    - Custom conditions (e.g., logged-in users, specific days)
  - **Performance Impact**: Improves PageSpeed scores by inlining above-the-fold CSS, reducing render-blocking resources, and conditionally loading styles only where needed
  - **Affected files**: `includes/classes/class-dynamic-asset-loader.php`, `assets/js/class-dynamic-asset-loader.js`, `includes/classes/CRITICAL-CSS-GUIDE.md`
  - **Backwards Compatibility**: Fully compatible; all new parameters are optional; existing critical_css usage unchanged
- **Dynamic Asset Loader: Export Localized Settings as Globals, Multiple Selectors & Handle+Type Tracking** (`2026-05-20`):
  - Added `exportLocalizeSettingsAsGlobals(asset)` method to `EWPDynamicAssetLoader` class that exports localized data as window globals only for scripts being loaded
  - Localized data is exported in `loadAsset()` before the asset loads, ensuring globals are available when the script executes
  - Only exports localized data for assets that will actually load (critical assets or assets with matching selectors), avoiding global scope pollution
  - Added `selectorExists(selector)` method supporting both single selector (string) and multiple selectors (array); uses OR logic to load asset if ANY selector exists
  - **Fixed**: Assets with same handle but different types (e.g., `my-asset` as both script and style) now load independently using composite key (handle:type)
  - Added `getAssetKey(asset, type)`, `isAssetLoaded(asset, type)`, and `markAssetAsLoaded(asset)` methods for proper asset tracking
  - Updated `loadAssetByHandle(handle, type)` and `forceLoadAsset(handle, type)` to support optional type parameter for disambiguation
  - Improves developer experience by providing centralized access to localized configuration, flexible selector matching, and proper multi-type asset handling
  - **Affected files**: `assets/js/class-dynamic-asset-loader.js`
  - **Backwards Compatibility**: Fully backward compatible; `elementExists()` now delegates to `selectorExists()` for legacy support; type parameter is optional in public APIs
- **Webpack Bundling for Module Scripts & npm Dependencies** (`2026-05-20`):
  - Configured webpack to dynamically discover and bundle all module scripts (`assets/js/modules/*.js`), the global orchestrator script (`assets/js/global/awm-global-script.js`), and the admin script (`assets/js/admin/awm-admin-script.js`)
  - Added `slim-select` as an npm dependency; now imported directly in `awm-inputs-module.js` instead of vendored `slimselect.min.js`
  - Webpack configuration uses dynamic entry discovery via `buildEntries()` function — new modules in `assets/js/modules/` are automatically bundled without config changes
  - Build artifacts output to `build/` directory with minification, tree-shaking, and sourcemaps enabled
  - **Affected files**: `webpack.config.js`, `package.json`, `assets/js/modules/awm-inputs-module.js`, `assets/js/modules/awm-maps-module.js`, `assets/js/admin/awm-admin-script.js`, `includes/classes/class-extend-wp.php`, `.gitignore`
- **Maps Module Extraction** (`2026-05-20`):
  - Created `awm-maps-module.js` to isolate Google Maps functionality (initialization, marker management, search box)
  - Maps module is lazy-loaded only when `.awm_map` elements are present on the page
  - Reduces initial JavaScript payload by deferring maps code until needed
  - Exported functions: `awm_add_map()`, `awm_call_maps_api()`, `awmInitMap()`, `removeMarkers()`, `placeMarker()`, `noenter()`
- **External Plugin API for AWM Functions** (`2026-05-20`):
  - Added `window.awmWaitForFunction(functionName, timeout)` — Promise-based wait for any AWM function to be available
  - Added `window.awmOnReady(callback)` — Queue callback to execute after all AWM modules load
  - Added `window.awmExecuteReadyCallbacks()` — Internal function to execute queued callbacks
  - Enables external plugins to safely use AWM functions without timing issues
  - Example usage: `window.awmOnReady(() => { window.awm_selectr_box(element); })`

### Changed
- **Bundled JavaScript & Removed WP Rocket Exclusions** (`2026-05-20`):
  - Module scripts now load from `build/modules/` instead of `assets/js/modules/` (webpack artifacts)
  - Global script now loads from `build/global/awm-global-script.js` (webpack artifact)
  - Admin script now loads from `build/admin/awm-admin-script.js` (webpack artifact) — fixes promise rejection error with `awm_init_inputs()`
  - Removed `exclude_from_rocket_minification()` method and `rocket_exclude_js` filter — no longer needed since webpack handles minification
  - Removed vendored `slimselect.min.js` and `slimselect.min.css` registrations; slim-select CSS now bundled with inputs module
  - Build artifacts are now committed to git (removed `build` from `.gitignore`) for ready-to-use plugin distribution
  - **Backwards Compatibility**: Script handles (`awm-global-script`, `awm-inputs-module`, `awm-admin-script`, etc.) remain unchanged; only URLs differ. Map functions remain globally accessible via `window.*`
  - **Performance**: Minified bundles + tree-shaking provide ~30–60% size reduction for module scripts; small PageSpeed improvement on "Minify JavaScript" and "Reduce unused JavaScript" audits

### Removed
- Vendored `assets/js/global/slimselect.min.js` and `assets/css/global/slimselect.min.css` (now npm dependency)
- `exclude_from_rocket_minification()` method from `AWM_Meta` class (WP Rocket can now safely minify bundled artifacts)

### Fixed
- **Admin Script Promise Rejection Error** (`2026-05-20`):
  - **Issue**: "ReferenceError: Can't find variable: awm_init_inputs" when admin script tried to call `awm_init_inputs()`
  - **Root Cause**: `awm_init_inputs()` was defined in global script but not exposed to `window` object, making it inaccessible to bundled admin script
  - **Solution**: Added `window.awm_init_inputs = awm_init_inputs;` to global script exports
  - **Impact**: Admin script now properly awaits and calls the global initialization function without errors
  - **Affected files**: `assets/js/global/awm-global-script.js`
- **Dynamic Asset Loader — Dependency Loading Performance** (`2026-05-19`):
  - **Issue**: Scripts with dependencies (e.g., `'dependencies' => array('awm-global-script')`) were waiting up to 5 seconds before loading, causing significant delays for small libraries
  - **Root Cause**: `isDependencyLoaded()` method couldn't detect WordPress-enqueued scripts properly, causing full timeout on every dependency check
  - **Solution**: Enhanced dependency detection with multiple fallback checks:
    - Check for WordPress script tags with `-js` suffix (WordPress naming convention)
    - Check WordPress script queue (`wp.scripts.queue`) if available
    - Search all script tags by src attribute containing the handle name
    - Reduced timeout from 5000ms to 1000ms for faster failure recovery
    - Reduced check interval from 100ms to 50ms for faster detection
  - **Impact**: Scripts with dependencies now load instantly when dependencies are already present, eliminating the 5-second delay
  - **Affected files**: `assets/js/class-dynamic-asset-loader.js` (v1.0.4), `includes/classes/class-dynamic-asset-loader.php`
  - **Backwards Compatibility**: Fully compatible, no breaking changes

### Added
- **Dynamic Asset Loader — Individual Asset Registration** (`2026-05-19`):
  - `register_scripts()` method now registers individual asset handles (both scripts and styles) from `get_registered_assets()` in addition to the main loader script
  - Developers can now enqueue specific asset scripts via `wp_enqueue_script()` and styles via `wp_enqueue_style()` independently for extra flexibility
  - Each registered asset respects its configured dependencies, version, and media type (for styles) or footer placement (for scripts)
  - Main loader script loads in `<head>` (not footer) for faster initialization and earlier DOM detection
  - Allows developers to manually control when/where individual assets load without relying on dynamic loader
  - **Affected files**: `includes/classes/class-dynamic-asset-loader.php`

### Changed
- **JavaScript Performance Optimization — Modular Architecture with Lazy Loading** (`2026-05-19`):
  - **Fixed**: Module import paths corrected from `./modules/` to `../modules/` for proper relative resolution
  - **Fixed**: Module functions now properly exposed globally for backwards compatibility
  - **Added**: WP Rocket compatibility filter to exclude ES6 modules from minification
  - **Optimized**: Smart loading strategy - inputs module loads once when `.awm-show-content` exists
  - **Optimized**: Initialization moved from `load` event to `DOMContentLoaded` for faster execution (no wait for images/resources)
  - When modules are imported, their functions are assigned to `window` object to ensure availability in admin scripts and custom code
  - Ensures `ewp_jsVanillaSerialize()`, `awm_selectr_box()`, `repeater()`, and other critical functions are accessible globally
  - Maintains full backwards compatibility with existing code that calls these functions directly
  - Added `exclude_from_rocket_minification()` method in AWM_Meta class to prevent WP Rocket from breaking relative import paths
  - Automatically excludes `/assets/js/global/awm-global-script.js` and `/assets/js/modules/` from WP Rocket minification
  - Inputs module loads once and initializes all features (calendars, selects, checkboxes, callbacks, etc.) when form content exists
  - Other modules (repeaters, TinyMCE, forms) load independently based on their specific DOM presence
  - Restructured JavaScript codebase from monolithic to modular architecture for better performance
  - Eliminated redundant `awm-public-script.js` (only contained 2 function calls already in global script)
  - Refactored `awm-global-script.js` into smart loader with dynamic module imports based on DOM presence
  - Created modular system with 4 lazy-loaded feature modules:
    - `awm-tinymce-module.js`: TinyMCE editor initialization, repeater editor queue, editor utilities
    - `awm-repeater-module.js`: Repeater cloning, ordering, field management
    - `awm-forms-module.js`: Form validation and submission handling
    - `awm-inputs-module.js`: Calendar pickers, checkboxes, select boxes, callbacks, conditional fields
  - Implemented smart `awm_init_inputs()` function that detects required features via DOM queries and imports only needed modules
  - Modules are dynamically imported via ES6 `import()` only when corresponding DOM elements are detected
  - Frontend pages now load ~75% less JavaScript (typical form: 450 lines vs 1900 lines previously)
  - Admin pages benefit from better parse time with async module loading
  - Eliminated one HTTP request (removed public-script)
  - Modules cached separately by browser for better long-term caching
  - Updated `awm-admin-script.js` to use new modular system while maintaining admin-specific features
  - Updated PHP registration in `class-extend-wp.php`:
    - Removed `awm-public-script` registration and enqueue
    - Added module script registrations for lazy loading
    - Maintained backwards compatibility with all existing functions
  - **Affected files**:
    - `assets/js/global/awm-global-script.js` (refactored to core + smart loader)
    - `assets/js/admin/awm-admin-script.js` (updated to use new system)
    - `assets/js/public/awm-public-script.js` (removed - functionality moved to core)
    - `assets/js/modules/awm-tinymce-module.js` (new)
    - `assets/js/modules/awm-repeater-module.js` (new)
    - `assets/js/modules/awm-forms-module.js` (new)
    - `assets/js/modules/awm-inputs-module.js` (new)
    - `includes/classes/class-extend-wp.php` (script registration updates)
  - **Backwards compatibility**: Fully compatible; all existing functions remain available globally, just loaded on-demand
  - **Performance gains**:
    - Frontend: ~75% reduction in typical form pages (from 1900 to ~450 lines)
    - Admin: Better parse time with async loading
    - Eliminates one HTTP request
    - Better browser caching with separate module files
  - **Testing**: Verified on frontend forms (calendars, checkboxes, validation) and admin (all field types, repeaters, TinyMCE)

### Fixed
- **Repeater WP Editor Blank Visual Mode Issue** (`2026-05-19`):
  - Fixed issue where `wp_editor` fields in repeater rows showed blank visual mode on page load and when cloning
  - Fixed issue where newly added editors became blank when reordered (content only preserved for pre-existing rows)
  - Content was only visible when switching to Code/Text mode, not in Visual mode
  - Implemented lazy initialization queue system to prevent premature TinyMCE initialization
  - Created `awmRepeaterEditorQueue` global object with `add()`, `process()`, and `initEditor()` methods
  - Added `awm_queue_repeater_editors_on_load()` function to detect and queue existing repeater editors on page load
  - Enhanced `awm_initialize_repeater_wp_editor()` to preserve textarea content before/after cleanup and force content sync to TinyMCE
  - Updated `repeater()` function to queue cloned editors for delayed initialization instead of immediate init
  - Updated `updateInputAttributes()` to:
    - Force save TinyMCE content to textarea BEFORE ID changes (critical for newly added editors)
    - Create content map with OLD IDs before any changes
    - Update textarea IDs (previously skipped for wp-editor, causing content loss)
    - Update wp-editor wrapper IDs (`wp-{id}-wrap`, `wp-{id}-editor-container`, `wp-{id}-editor-tools`) to match new textarea ID
    - Restore content to textareas with NEW IDs using the content map
    - Queue editors with NEW IDs for reinitialization with preserved content
    - Fixes issue where content was lost because it was saved to old ID but editor initialized with new ID
    - Fixes issue where TinyMCE couldn't find wrapper elements after ID change
  - Added page load event handler to process queued editors with staggered initialization (200ms gaps)
  - Added content verification after initialization with retry logic if content not visible
  - Delays: 500ms after page load, 200ms between editors, 300ms for cloned/reordered editors
  - **Affected files**: 
    - `assets/js/global/awm-global-script.js` (queue system, initialization enhancements, reorder fix)
  - **Backwards compatibility**: Fully compatible; existing editors continue to work, new system only affects repeater editors
  - **Testing**: Works with initial page load, cloning rows, reordering rows (including newly added ones), and multiple repeater fields on same page

### Added
- **EWP Log Viewer — Enhanced Per-Page Options and Raw Data Export** (`2026-05-19`):
  - Added per-page options `1000` and `2000` to the log viewer dropdown (previously capped at 500)
  - Implemented "Download Raw Data" button to export all filtered log entries as JSON with unlimited pagination
  - Created `downloadRawData()` method that recursively fetches all matching entries across all pages
  - Created `fetchAllRawData()` method for recursive pagination with 10000 entries per request
  - Created `saveRawDataFile()` method to generate JSON file with export metadata (timestamp, total count, filters)
  - Created `generateRawDataFilename()` method to generate descriptive filenames based on active filters
  - JSON export includes: `exported_at`, `total_entries`, `filters` (active filter parameters), and complete `entries` array with all fields
  - Dynamic filename format: `ewp-logs-[date_from]-[date_to]-[metabox]-[filter-tags].json` (e.g., `ewp-logs-11-05-2026-15-05-2026-ewp-log-viewer-owner-sys-action-con.json`)
  - Filename includes: date range (if filtered), metabox name, and abbreviated filter tags for readability
  - Filter tags abbreviated: owner, action, obj (object_type), behaviour, level
  - Respects all active filters (date range, owner, action type, object type, behaviour, level)
  - Button shows "⟳ Downloading..." state with smooth spinning animation during multi-page fetch operations
  - Added `ewp-spin` CSS keyframe animation (360° rotation, 1s linear infinite) for minimal visual feedback
  - **Affected files**: 
    - `includes/classes/ewp-logger/class-ewp-logger-viewer.php` (HTML template)
    - `assets/js/admin/class-ewp-log-viewer.js` (JavaScript implementation)
    - `assets/css/admin/ewp-log-viewer.css` (Loading animation styles)
  - **Backwards compatibility**: Fully compatible; new features are additive
  - **Performance**: Efficient recursive pagination with 10000 entries per request for large datasets

### Fixed
- **EWP Log Viewer Cache Busting Issue** (`2026-05-19`):
  - Fixed hardcoded version string (`1.0.0`) in log viewer asset registration causing browser cache issues
  - Changed to dynamically read plugin version from `extend-wp.php` header
  - Ensures script/CSS files are properly cache-busted when plugin version updates
  - Prevents stale JavaScript from being loaded when changes are made to `class-ewp-log-viewer.js`
  - **Affected files**: `includes/classes/ewp-logger/class-ewp-logger-viewer.php`
- **PHP 8.1+ Type Error in General Settings Utility** (`2026-05-15`):
  - Fixed TypeError when `get_option('ewp_general_settings')` returns non-array value
  - Changed assignment to use ternary operator ensuring only arrays are assigned to typed property
  - Prevents fatal error: "Cannot assign string to property Extend_WP_Default_Content::$general_settings_cache of type ?array"
  - **Affected files**: `includes/classes/awm-content-db-api/custom-content/class-defaults.php`

### Added
- **General Settings Utility Function** (`2026-05-15`):
  - Created `Extend_WP_Default_Content::get_general_settings()` static method for centralized access to `ewp_general_settings` option
  - Supports optional key parameter to retrieve specific settings: `get_general_settings('ewp_enable_ai_integration')`
  - Implements request-level caching via `$general_settings_cache` to reduce database queries
  - Added `bust_general_settings_cache()` static method for cache invalidation on option updates
  - Hooked `updated_option` to automatically bust cache when `ewp_general_settings` is modified
  - Replaces 4 scattered `get_option('ewp_general_settings')` calls with single reusable function
  - **Affected files**: `includes/classes/awm-content-db-api/custom-content/class-defaults.php`, `includes/classes/ewp-ai-content/class-ewp-ai-content.php`
  - **DRY principle**: Single source of truth for general settings access
- **AI Integration Conditional Loading** (`2026-05-15`):
  - Made `EWP_AI_Content` class instantiation conditional on `ewp_enable_ai_integration` setting
  - AI module now only loads when enabled in General Settings → Enable AI Integration checkbox
  - Uses new `Extend_WP_Default_Content::get_general_settings()` utility function
  - **Affected files**: `includes/classes/ewp-ai-content/class-ewp-ai-content.php`
  - **Backwards compatibility**: Fully compatible; setting defaults to disabled for clean installations
- **EWP Slug Manager** (`2026-04-28`):
  - Implemented transient caching system for post type/taxonomy slug options
  - Reduces 11 database queries to 1 on first load, 0 on cached loads (30-day cache)
  - Auto-invalidates cache when slug options are updated via WordPress admin
  - Integrated with `ewp_flush_cache()` for manual cache clearing
  - Created `EWP\WP_Content\Slug_Manager` singleton class with namespace
  - Filter hook: `ewp_slug_manager_slugs` for developer customization
  - **Affected files**: 
    - `includes/classes/ewp-wp-content/class-slug-manager.php` (new)
    - `includes/classes/ewp-wp-content/class-wp-content-installer.php`
    - `includes/classes/Setup.php`
  - **Performance**: ~99% reduction in slug-related database queries
  - **Backwards compatibility**: Fully compatible, transparent to existing code
- **Time Input Field Type** (`2026-04-16`):
  - Added `time` to `awmInputFieldsTypes()` function for field type selection in UI
  - Implemented time value formatting in `awm_show_content()` to convert stored values to HH:mm format
  - Time fields now properly display and save time values in HTML5 time input format
  - **Affected files**: `includes/classes/ewp-fields/ewp_field_functions.php`, `includes/functions/library.php`
  - **Backwards compatibility**: No breaking changes; new field type available for new fields

### Fixed
- **PHP Fatal Error: Undefined function add_filter() in library.php** (`2026-03-30`):
  - Fixed fatal error caused by calling WordPress hooks during Composer autoload before WordPress initialization
  - Moved encryption hook registrations from `library.php` (lines 13-18) to `Setup.php` constructor
  - Hooks now registered after WordPress core functions are available: `update_post_meta`, `update_user_meta`, `update_term_meta`, `updated_option`
  - **Affected files**: `includes/functions/library.php`, `includes/classes/Setup.php`
  - **Backwards compatibility**: No breaking changes; hooks function identically, just registered at proper time
- **EWP AI Content Modal — Footer Buttons and Field Rendering** (`2026-03-28`):
  - Fixed footer buttons not displaying correctly — refactored to use filterable template approach
  - Fixed tasks checkbox field not rendering — changed field type from `checkbox` to `checkbox_multiple`
  - Updated JavaScript to properly select checkbox inputs with `input[name*="[tasks]"]:checked` selector
  - **Fixed modal not displaying as overlay** — Updated CSS dynamic asset selector from `.ewp-ai-content-metabox` to `.awm-modal-trigger[data-modal-id*="ai_generator"]` so CSS file loads properly and modal displays as fixed overlay
  - **Refactored modal footer buttons** — Created new template `modal-footer-buttons.php` with `awm_modal_footer_buttons_html` filter to allow clean button replacement without CSS hacks
    - Default modals render Save/Cancel buttons via template
    - AI modal renders Generate/Accept All/Retry buttons via filter override
    - Removed all button-hiding CSS rules and footer sizing workarounds
    - Fixed filter not running on REST API requests by moving registration outside admin-only guard
    - Fixed Generate/Accept All/Retry button click handlers using event delegation on footer for robustness

### Changed
- **EWP AI Content Modal — Refactored to use awm_modal Infrastructure** (`2026-03-28`):
  - **User Request**: "refactor this to use the awm_modal type and remove code not needed or is redundant. hook into awm_modal_body_content and prepare the fields using the EWP field types"
  - **Implementation**: Refactored AI Content Generator modal to leverage existing `awm_modal` system:
    - **PHP Changes** (`class-ewp-ai-content.php`):
      - Replaced direct `add_meta_box()` with `awm_add_meta_boxes_filter` registration
      - Created `register_ai_meta_box()` method to register meta box via filter with `awm_modal` field type
      - Created `get_ai_generator_fields()` method to define modal fields using EWP field types (checkbox, select, radio, textarea, html)
      - Provider/model options now populated from PHP settings (single source of truth)
      - Added template filter callbacks:
        - `filter_modal_wrapper_classes()` — Add AI-specific CSS classes
        - `filter_modal_body_content()` — Wrap fields in AI container
        - `filter_modal_footer_start()` — Inject custom Generate/Accept All/Retry buttons
        - `filter_modal_after_body()` — Inject results section HTML
      - Created `get_prompt_preview_html()` helper for collapsible prompt preview
      - Added `filter_modal_field_definition_lookup()` to provide field definitions to REST API modal-fields endpoint
      - Updated `register_dynamic_assets()` to use new modal trigger selector
    - **JavaScript Changes** (`class-ewp-ai-content.js`):
      - Removed `buildGeneratorModal()`, `openGeneratorModal()`, `closeGeneratorModal()` methods (~200 lines)
      - Added `initGeneratorModal(overlay)` method to hook into `awm_modal_fields_loaded` event
      - Updated `syncModels()`, `togglePromptPreview()`, `runGenerate()`, `showResults()` to accept modal parameter
      - Updated field selectors to work with new awm_modal field structure (`[name*="[field]"]`)
      - Kept all editor integration methods unchanged
      - Kept business context generation logic unchanged
    - **Benefits**:
      - Single source of truth for provider/model options (PHP settings)
      - Reuses existing `awm_modal` template and REST infrastructure
      - Removed ~200 lines of redundant modal HTML generation
      - Consistent UX with other EWP features
      - JavaScript only loads when modal trigger exists (Dynamic Asset Loader)
      - Clear separation: field definitions in PHP, behavior in JS
  - **Backwards Compatibility**: Full — all existing functionality preserved, just refactored infrastructure

### Added
- **EWP AI Content — Comprehensive Developer Filters and Hooks** (`2026-03-29`):
  - **User Request**: "at all php files please add necessary filters and hooks for developers, so they can edit fields/auto populate fields in prompt etc."
  - **Implementation**: Added 60+ filters and actions across all AI Content PHP files to enable complete developer customization:
    - **Settings & Business Data** (`class-ewp-ai-content.php`):
      - `ewp_ai_content_settings` — Modify AI settings before caching
      - `ewp_ai_content_business_data` — Modify business data fields dynamically
      - `ewp_ai_content_business_data_fields` — Add/remove business data field definitions
      - `ewp_ai_content_generation_options` — Modify generation options (provider, model, instructions, etc.)
      - `ewp_ai_content_business_context_data` — Modify business data before context generation
      - `ewp_ai_content_business_context_parts` — Add/remove context parts for business prompt
      - `ewp_ai_content_business_context_prompt_parts` — Modify business context prompt structure
      - `ewp_ai_content_business_context_system_prompt` — Customize system prompt for business context
      - `ewp_ai_content_business_context_user_prompt` — Customize user prompt for business context
      - `ewp_ai_content_business_context_generated` — Post-process generated business context
      - `ewp_ai_content_before_save_business_context` — Action before saving business context
      - `ewp_ai_content_after_save_business_context` — Action after saving business context
    - **Content Generation** (`class-content-generator.php`):
      - `ewp_ai_content_before_generate` — Action before generation starts
      - `ewp_ai_content_generation_context` — Modify post context before prompt building
      - `ewp_ai_content_translation_mode` — Modify translation mode (translate/recreate)
      - `ewp_ai_content_provider_options` — Modify provider-specific options (max_tokens, temperature)
      - `ewp_ai_content_result` — Modify AI result before returning
      - `ewp_ai_content_generated_text` — Post-process generated content text
      - `ewp_ai_content_after_generate` — Action after successful generation
      - `ewp_ai_content_system_prompt_role` — Customize AI role description
      - `ewp_ai_content_system_prompt_parts` — Add/remove system prompt sections
      - `ewp_ai_content_system_prompt` — Modify complete system prompt
      - `ewp_ai_content_context_block` — Modify formatted context block
      - `ewp_ai_content_user_prompt_parts` — Add/remove user prompt sections
      - `ewp_ai_content_user_prompt` — Modify complete user prompt
      - `ewp_ai_content_task_language` — Customize language for task instructions
      - `ewp_ai_content_task_instructions` — Modify task-specific instructions
      - `ewp_ai_content_task_instruction` — Modify final task instruction
      - `ewp_ai_content_format_context` — Modify context before formatting
      - `ewp_ai_content_context_lines` — Add/remove formatted context lines
    - **Context Building** (`class-context-builder.php`):
      - `ewp_ai_content_before_build_context` — Action before building context
      - `ewp_ai_content_context_post` — Modify post object before extraction
      - `ewp_ai_content_context_field` — Modify individual context fields (generic)
      - `ewp_ai_content_context_field_{$field}` — Modify specific context field (dynamic)
      - `ewp_ai_content_context` — Modify complete context array (existing)
      - `ewp_ai_content_context_excerpt` — Customize excerpt extraction
      - `ewp_ai_content_context_content` — Customize content extraction
      - `ewp_ai_content_context_author` — Customize author name
      - `ewp_ai_content_context_featured_image` — Customize featured image URL
      - `ewp_ai_content_context_taxonomies_list` — Filter taxonomies to include
      - `ewp_ai_content_context_taxonomies` — Modify taxonomies array
      - `ewp_ai_exclude_meta_keys` — Add meta keys to exclude (existing, enhanced with $post_id)
      - `ewp_ai_content_context_meta_value` — Modify individual meta values
      - `ewp_ai_content_context_meta` — Modify complete meta array
      - `ewp_ai_content_context_language` — Customize language code
    - **Provider APIs** (OpenAI, Claude, Gemini):
      - `ewp_ai_{provider}_before_generate` — Action before API call
      - `ewp_ai_{provider}_prompt` — Modify prompt before building messages
      - `ewp_ai_{provider}_request_body` — Modify API request body
      - `ewp_ai_{provider}_messages` / `ewp_ai_{provider}_contents` — Modify messages/contents array
      - `ewp_ai_{provider}_response` — Modify API response
      - `ewp_ai_{provider}_after_generate` — Action after successful API call
    - **Screenshot Processing** (`class-screenshot-generator.php`):
      - `ewp_ai_screenshot_before_get_url` — Action before getting frontend URL
      - `ewp_ai_screenshot_frontend_url` — Customize URL for screenshot capture
      - `ewp_ai_screenshot_raw_base64` — Modify raw base64 before sanitization
      - `ewp_ai_screenshot_max_size` — Customize maximum screenshot size
      - `ewp_ai_screenshot_sanitized_base64` — Modify sanitized base64 data
      - `ewp_ai_screenshot_provider_options` — Modify screenshot options for provider
  - **Affected Files**:
    - `includes/classes/ewp-ai-content/class-ewp-ai-content.php` — 12 filters, 2 actions
    - `includes/classes/ewp-ai-content/class-content-generator.php` — 18 filters, 2 actions
    - `includes/classes/ewp-ai-content/class-context-builder.php` — 14 filters, 1 action
    - `includes/classes/ewp-ai-content/class-openai-provider.php` — 4 filters, 2 actions
    - `includes/classes/ewp-ai-content/class-claude-provider.php` — 4 filters, 2 actions
    - `includes/classes/ewp-ai-content/class-gemini-provider.php` — 4 filters, 2 actions
    - `includes/classes/ewp-ai-content/class-screenshot-generator.php` — 5 filters, 1 action
  - **Use Cases Enabled**:
    - Auto-populate instructions based on post type or custom fields
    - Inject external data sources into context (CRM, analytics, etc.)
    - Customize prompts per post type or taxonomy
    - Override provider settings dynamically
    - Add custom business data fields
    - Post-process AI-generated content
    - Implement custom caching strategies
    - Add custom validation or sanitization
    - Integrate with third-party services
  - **Documentation**: All filters/actions include comprehensive PHPDoc with parameters and @since tags
  - **Backwards Compatibility**: All changes are additive; existing functionality unchanged
- **EWP AI Content — Comprehensive Developer-Level Logging** (`2026-03-28`):
  - **User Request**: "When a call to AI happens either for business context or AI content generation, please log everything at developer level with ewp_log"
  - **Implementation**: Added comprehensive logging at developer level for all AI-related operations:
    - **Business Context Generation** (`rest_generate_business_context`):
      - Logs generation start with default provider
      - Logs full system and user prompts before API call
      - Logs business data fields being sent
      - Logs API response with usage statistics
      - Logs success/failure with detailed error information
    - **AI Content Generation** (`generate_content` in `class-content-generator.php`):
      - Logs generation request start with post ID, task, and options
      - Logs built prompts (system and user) before filtering
      - Logs screenshot attachment details (MIME type, size)
      - Logs final prompts and generation options before API call
      - Logs API call failures with error codes and messages
      - Logs successful generation with usage stats and content preview
    - **All logs include**:
      - Provider ID and model used
      - Full system and user prompts
      - Token usage statistics
      - Temperature and max_tokens settings
      - Error details when applicable
  - **New Log Types Registered**:
    - `ai_content_business_context` — Business context generation for AI content system
    - `ai_content_api_call` — API call to AI provider for content generation
  - **Affected Files**:
    - `includes/classes/ewp-ai-content/class-ewp-ai-content.php` — Added logging to `rest_generate_business_context()` method
    - `includes/classes/ewp-ai-content/class-content-generator.php` — Added logging throughout `generate_content()` method
  - **Log Level**: All logs use `developer` level for detailed debugging
  - **Impact**: Complete visibility into all AI operations for debugging and monitoring

### Fixed
- **EWP AI Content — Variable typo causing null prompt in business context generation** (`2026-03-28`):
  - **Issue**: Fatal type error "Argument #1 ($prompt) must be of type string, null given" when generating business context.
  - **Root Cause**: Line 1332 used `$$prompt` (double dollar sign) instead of `$prompt`, creating a variable variable that evaluated to null.
  - **Solution**: Fixed typo to use single `$prompt` variable.
  - **Affected File**: `includes/classes/ewp-ai-content/class-ewp-ai-content.php` — line 1332.
  - **Impact**: Business context generation now works correctly without type errors.

- **EWP AI Content — Target audience not included in business context prompt** (`2026-03-28`):
  - **Issue**: The `target_audience` field was never sent to the AI when generating business context.
  - **Root Cause**: Condition on line 1325 checked `$biz['brand_voice']` (non-existent field) instead of `$biz['target_audience']`.
  - **Solution**: Changed condition to check `$biz['target_audience']`.
  - **Affected File**: `includes/classes/ewp-ai-content/class-ewp-ai-content.php` — line 1325.

- **AWM Modal Field — Prevent Settings API interference with modal data** (`2026-03-28`):
  - **Issue**: Modal field data was corrupted/lost when saving options page due to double-encoding and Settings API processing.
  - **Root Cause**: `awm_modal` fields were registered with `register_setting()`, causing WordPress to expect POST data. A hidden input workaround used `wp_json_encode()` on serialized data, creating encoding conflicts where serialized PHP arrays were JSON-encoded, resulting in corrupted data.
  - **Solution**: Skip `register_setting()` for `awm_modal` fields and remove hidden input. Modal fields now save exclusively via REST API, completely independent from WordPress Settings API.
  - **Affected Files**: 
    - `includes/classes/class-extend-wp.php` — Added check to skip modal field registration (lines 569-572)
    - `includes/functions/library.php` — Removed hidden input workaround (previously lines 1280-1286)
  - **Backwards Compatibility**: Modal fields continue to save via REST API as before. Options page saves no longer interfere with modal data.
  - **Impact**: Clean separation of concerns - Settings API handles regular fields, REST API handles modal fields. No data loss, no encoding issues.

### Changed
- **EWP AI Content — Prompt Optimization & Smart Caching** (`2026-03-27` to `2026-03-28`):
  - **Field Simplification**: Removed low-value fields from `get_business_data_fields()`:
    - Removed `brand_voice` select field (no longer used in system prompt)
    - Removed `competitors` repeater field (removed from prompting logic and frontend UI)
    - Removed `social_links` repeater field (removed from prompting logic and frontend UI)
    - These fields were previously sent to the AI model as raw structured data alongside the AI-generated `business_context` summary, creating redundancy
    - **Frontend Impact**: Removed field definitions from `get_business_data_fields()` so these fields no longer appear in the admin modal interface
  - **Smart Caching with Hash Detection**:
    - Added `get_business_data_hash()` private static method — calculates SHA256 hash of `business_data` option (excluding `business_data_hash` field itself)
    - `rest_generate_business_context()` endpoint now:
      - Checks if current hash matches stored hash at start of request
      - Returns cached `business_context` immediately if hash unchanged (early exit)
      - Skips entire context generation process if data hasn't changed
      - Stores new hash and context in `business_data` option after generation
    - Result: ~60% token reduction for content generation requests (only sends AI-generated summary, not raw fields)
  - **Simplified REST Endpoint** (`rest_generate_business_context()`):
    - Removed slow `wp_remote_get()` call for website URL accessibility check
    - Removed social_links processing section
    - Removed competitors processing section
    - Removed "(max 200 words)" instruction from prompt (replaced with max_tokens: 300 API parameter)
    - Kept review link sentiment extraction (still needed for `customer_sentiment` in business_context)
  - **Refactored System Prompt** (`build_system_prompt()` in `class-content-generator.php`):
    - **New Structure**: Now uses SINGLE SOURCE OF TRUTH — the AI-generated `business_context` field
    - Removed ALL raw business data fields:
      - ❌ `business_name`, `business_location`, `business_description`, `key_services`, `unique_selling_points`
      - ❌ `customer_sentiment`, `review_links`, `social_links`, `competitors` (now all contained in `business_context`)
      - ❌ `brand_voice` (removed entirely)
    - Kept strategic/behavioral fields:
      - ✅ `target_audience` — helps tailor tone/depth to audience
      - ✅ `custom_instructions` — user-specific writing guidelines
    - Result: ~40% reduction in system prompt size per content generation request
  - **Performance Impact**:
    - Eliminated prompt redundancy (business data sent twice)
    - Reduced token usage per content generation: ~60% savings
    - Eliminated slow URL accessibility checks in REST endpoint
    - Smart hash-based caching prevents unnecessary AI calls when data unchanged

### Added
- **Global Encryption Helper for Meta Fields** (`2026-03-27`):
  - **New Class**: `EWP_Encryption` — Moved from AI Content module to global `includes/classes/class-encryption.php` for reusability across all meta field types.
  - **Automatic Encryption/Decryption**: Fields with `'encrypt' => true` automatically encrypt values before saving and decrypt/mask them on display.
  - **Field Configuration**: Add `'encrypt' => true`, `'show_masked' => true` (default), and optional `'encrypt_algorithm'` to any input field definition.
  - **Supported Field Types**: Input fields only (`text`, `password`, `email`, `url`, `number`). Complex fields like repeater, select, textarea are excluded.
  - **Masked Display**: Encrypted values show as `••••••••abcd` (8 bullets + last 4 chars) in password fields by default, allowing users to verify stored values without exposing secrets.
  - **Backward Compatibility**: Existing plain-text values automatically migrate to encrypted format on first save.
  - **Masked Value Preservation**: When user doesn't change a masked field, the original encrypted value is preserved (not re-encrypted).
  - **Helper Functions**:
    - `awm_should_encrypt_field($field_config)` — Check if field should be encrypted
    - `awm_encrypt_field_value($value, $field_config)` — Encrypt before save
    - `awm_decrypt_field_value($value, $field_config, $for_display)` — Decrypt/mask on display
  - **Field Config Registry**: Global `$awm_field_configs` tracks field definitions during rendering for use in save operations.
  - **Integration Points**:
    - `awm_show_content()` — Registers field configs and decrypts values for display
    - `awm_custom_meta_update_vars()` — Encrypts values before DB save
  - **Filters**:
    - `ewp_encryption_algorithm` — Override default cipher (aes-256-cbc)
    - `ewp_encrypt_field_value` — Modify value before encryption
    - `ewp_decrypt_field_value` — Modify value after decryption
  - **Example Usage**: `'openai_api_key' => ['case' => 'input', 'type' => 'password', 'encrypt' => true, 'show_masked' => true]`
  - **Affected Files**: `includes/classes/class-encryption.php` (new), `includes/functions/library.php`, `includes/classes/ewp-ai-content/class-ewp-ai-content.php`
  - **Security**: Encryption keys derived from WordPress salts (AUTH_KEY, SECURE_AUTH_SALT). If salts are rotated, encrypted values must be re-entered.

### Changed
- **EWP AI Content — Inherit AWM Modal + DRY field definitions** (`2026-03-27`):
  - **Business Data modal** now fully managed by `awm_modal` field type. All custom PHP/JS modal lifecycle code removed.
  - **Field consolidation**: `brand_voice`, `target_audience`, `custom_instructions` moved from `general_instructions` settings section into the Business Data modal (`get_business_data_fields()`). Single source of truth — field labels and defaults defined once.
  - **Auto-generated `business_context`**: On modal save (`awm_modal_fields_saved` JS event), JS automatically calls `POST /ai-content/generate-business-context`. This endpoint reads `business_data` from DB, fetches review pages for sentiment extraction, includes competitor context, and returns a concise `business_context` paragraph that is auto-populated in the settings textarea.
  - **Removed PHP**: `render_business_modal_footer_actions()`, `render_business_modal_summary_area()`, `rest_save_business_data()`, `rest_extract_review_sentiment()`, `rest_business_summary()`, `rest_save_business_context()`, global `ewp_ai_render_extract_sentiment_button()`.
  - **Removed REST routes**: `/save-business-data`, `/business-summary`, `/save-business-context`, `/extract-review-sentiment`.
  - **Added PHP**: `rest_generate_business_context()` + route, `EWP_AI_Content::get_business_data()` static accessor, `EWP_AI_Content::extract_scalar_defaults()` helper. `get_business_data_fields()` is now `public static`.
  - **Removed JS**: `openOnboardingModal()`, `closeOnboardingModal()`, `serializeBusinessForm()`, `_setNestedValue()`, `generateBusinessSummary()`, `saveBusinessContext()`, `saveBusinessData()`.
  - **Added JS**: `generateBusinessContext()` — async method called after modal save to populate `business_context` textarea.
  - **Affected files**: `class-ewp-ai-content.php`, `class-ewp-ai-content.js`.

### Changed
- **AWM Modal Field — Server-side field lookup with direct option page access**: Modal field REST API now retrieves field definitions server-side using `meta_key` and optional `option_page`, eliminating massive URL parameters and enabling direct field lookup.
  - **Original Issue**: Modal was sending all field definitions via URL parameters (`data-include` attribute), creating 4KB+ URLs that hit browser limits and caused performance issues. Additionally, the server had to search through all registered option pages to find the field definition.
  - **Changes**:
    - `GET extend-wp/v1/modal-fields/` now accepts `meta_key`, `view`, `object_id`, `modal_title`, and optional `option_page` parameters
    - Server performs direct lookup when `option_page` is provided (e.g., `option_page=ewp_ai_content_settings&meta_key=business_data`)
    - Falls back to searching all pages if `option_page` is not provided (backwards compatible)
    - Removed `data-include` attribute from modal trigger button HTML
    - Added `data-option-page` attribute for option view modal fields
    - JavaScript client sends `option_page` parameter when available
    - Settings template passes option page ID as `context_id` to `awm_show_content()`
  - **New Method**: `AWM_API::lookup_modal_field_definition()` with direct lookup path for option pages
  - **New Filter**: `awm_modal_field_definition_lookup` — Override field definition lookup logic
  - **New Parameter**: `awm_show_content()` now accepts `$context_id` (8th parameter) for passing option page key
  - **Affected Files**:
    - `includes/classes/awm-api/class-awm-api.php` — Added server-side field lookup with direct option page access
    - `assets/js/admin/class-awm-modal-field.js` — Added option_page parameter to REST call
    - `includes/functions/library.php` — Added context_id parameter and data-option-page attribute
    - `templates/settings.php` — Pass option page ID as context to awm_show_content
    - `includes/classes/ewp-ai-content/class-ewp-ai-content.php` — Moved `awm_add_options_boxes_filter` registration outside `is_admin()` guard for REST API access
  - **Benefits**: Smaller DOM, faster page load, no URL length limits, single source of truth, direct field access without searching
  - **Backwards Compatibility**: Fully backwards-compatible. Existing modal fields work without changes; option_page is optional.

### Fixed
- **AWM Modal Field — Options page not available in REST context**: Moved `awm_add_options_boxes_filter` registration in `EWP_AI_Content` class outside the `is_admin()` guard. Previously, the AI Content settings page was only registered in admin context, causing REST API modal field lookups to fail with "Field definition not found" error. The filter now registers on all request types, making option pages available for server-side field lookup in REST endpoints.

### Added
- **AWM Modal Field Type (`awm_modal`)**: New input field type that displays nested fields inside a modal overlay, with REST API endpoints for loading/saving data.
  - **Original Request**: "We need to create a new view for input fields like repeater, section, tabs. We need to add the modal view. 'case' => 'awm_modal'. We need to use the 'ewp-ai-modal-overlay'."
  - **Features**:
    - Trigger button (`.awm-modal-trigger`) opens modal overlay
    - Modal HTML rendered server-side via PHP template (`templates/admin-view/modal-field.php`)
    - Fields loaded via REST API (`GET /modal-fields/`) with pre-populated values
    - Field definitions looked up server-side from registered meta boxes/options
    - Values saved via REST API (`POST /modal-save/`) as single serialized array
    - Supports post_meta, term_meta, user_meta, options, and content_meta storage
    - Uses existing `ewp-ai-modal-overlay` CSS pattern for consistent UI
    - Nested fields defined via `include` key (same as repeater/section)
    - JS/CSS loaded dynamically via Dynamic Asset Loader (selector: `.awm-modal-trigger`)
  - **REST Endpoints**:
    - `GET extend-wp/v1/modal-fields/` — Render modal HTML + fields with current values (server-side field lookup)
    - `POST extend-wp/v1/modal-save/` — Save modal field values to meta/option
  - **Filters/Hooks**:
    - `awm_modal_template_path` — Use custom template file for modal
    - `awm_modal_wrapper_classes` — Modify modal wrapper classes
    - `awm_modal_dialog_classes` — Modify modal dialog classes
    - `awm_modal_header_classes` — Modify modal header classes
    - `awm_modal_body_classes` — Modify modal body classes
    - `awm_modal_footer_classes` — Modify modal footer classes
    - `awm_modal_save_button_text` — Modify save button text
    - `awm_modal_cancel_button_text` — Modify cancel button text
    - `awm_modal_save_button_classes` — Modify save button classes
    - `awm_modal_cancel_button_classes` — Modify cancel button classes
    - `awm_modal_body_content` — Modify modal body content
    - `awm_modal_field_args` — Modify modal field configuration before rendering
    - `awm_modal_fields_rendered` — Modify rendered fields HTML
    - `awm_modal_before_save` — Action before saving modal values
    - `awm_modal_after_save` — Action after saving modal values
  - **Actions**:
    - `awm_modal_before_wrapper` — Before modal wrapper
    - `awm_modal_after_wrapper` — After modal wrapper
    - `awm_modal_before_header` — Before modal header
    - `awm_modal_after_header` — After modal header
    - `awm_modal_before_body` — Before modal body
    - `awm_modal_after_body` — After modal body
    - `awm_modal_before_footer` — Before modal footer
    - `awm_modal_after_footer` — After modal footer
    - `awm_modal_footer_start` — At start of modal footer
    - `awm_modal_footer_end` — At end of modal footer
  - **Affected Files**:
    - `templates/admin-view/modal-field.php` — PHP template for modal HTML
    - `includes/classes/awm-api/class-awm-api.php` — REST endpoints
    - `includes/functions/library.php` — `awm_modal` case in `awm_show_content()`
    - `assets/js/admin/class-awm-modal-field.js` — `AWMModalField` JavaScript class
    - `assets/css/admin/awm-modal-field.css` — Modal field styles
    - `includes/classes/class-extend-wp.php` — Dynamic Asset Loader registration
    - `includes/classes/ewp-fields/ewp_field_functions.php` — `awm_modal` option in `awm_fields_usages()`
  - **Backwards Compatibility**: Fully backwards-compatible. New field type, no changes to existing functionality.

- **AI Content Generator — Business Data section in settings**: New `business_data` options section on the AI Content settings page with fields: business name, website URL (defaults to `home_url()`), location/service area, description, key products/services, unique selling points, customer review summary, and three EWP repeater fields — review platform links, social media profiles, and competitors.
- **AI Content Generator — PHP-rendered Business Data modal**: `render_business_data_modal()` (hooked to `admin_footer`) outputs a server-side modal using `awm_show_content()`, pre-populated from the `business_data` option. Separate repeaters for Review Links, Social Media, and Competitors. Footer: "Generate Summary", "Save to Settings", "Save Data", "Cancel".
- **AI Content Generator — `POST /ai-content/save-business-data` REST endpoint**: Persists the full business data object to the `business_data` wp_options row with full sanitization (scalar fields + repeater rows). Busts the settings cache on success.
- **AI Content Generator — URL accessibility validation before AI prompts**: `is_url_accessible()` performs a HEAD request (5 s timeout, 3 redirects, SSL-permissive) to check that each URL returns 2xx–3xx before it is included in the AI prompt. Applied to review links, social links, and competitor URLs in `rest_business_summary()`. Competitor entries with inaccessible URLs include the name only.
- **AI Content Generator — Expanded `rest_business_summary()`**: Accepts all new structured fields (`business_location`, `unique_selling_points`, `customer_sentiment`, `review_links[]`, `social_links[]`, `competitors[]`). All link arrays are validated per-URL before inclusion in the summary prompt.
- **AI Content Generator — Business data merged into `get_settings()`**: `get_settings()` now reads and merges the `business_data` option alongside the three existing section options. Defaults added for all new fields.
- **AI Content Generator — Structured business data in system prompt**: `build_system_prompt()` now includes all business data fields (name, location, description, services, USPs, customer sentiment, review platforms, social media, competitors) when populated.
- **AI Content Generator — JS modal rewrite for PHP-rendered onboarding**: `openOnboardingModal()` now reveals the server-side `#ewp-ai-business-data-modal` DOM node instead of building a custom modal. `serializeBusinessForm()` parses EWP bracket-notation field names (including nested repeater rows) into a plain JS object. `saveBusinessData()` and `generateBusinessSummary()` updated to use the structured repeater data.

### Added
- **AI Content Generator — Business Onboarding Modal**: On-demand "🏢 Setup Business Context" button injected on the AI Content settings page. Clicking opens a guided modal that collects business name, website URL, industry, description, services, and review/social links. Data is sent to the configured AI provider via `POST extend-wp/v1/ai-content/business-summary`, which generates a compact 150-word summary. The user can review the summary in an editable textarea before approving. On approval, the summary is saved to `general_instructions.business_context` via `POST extend-wp/v1/ai-content/save-business-context` and immediately included in all subsequent AI content prompts. Fully on-demand — not triggered automatically on key save.
- **AI Content Generator — Generator Modal with multi-select tasks**: The post editor meta box is now a single "✦ Generate with AI" trigger button. Clicking opens a full overlay modal with: task checkboxes (Title / Excerpt / Full Content — any combination selectable), provider and model dropdowns (auto-populated from configured providers), WPML translation mode radios (Translate / Recreate, shown when WPML active), per-generation custom instructions textarea, and a collapsible Prompt Preview panel. Generation runs tasks sequentially, showing per-task results in a results section. "Accept All" applies all results to editor fields at once; "Retry" regenerates all selected tasks with the same parameters; "Close" discards results. New REST endpoints: `GET /ai-content/prompt-preview` and `POST /ai-content/business-summary` and `POST /ai-content/save-business-context`.
- **AI Content Generator — Gutenberg native block insertion**: "Accept All" now inserts content as native Gutenberg blocks via `wp.blocks.rawHandler({ HTML })` + `wp.data.dispatch('core/block-editor').resetBlocks(blocks)` instead of a raw `core/html` block. HTML is parsed into proper paragraph, heading, list, and other core blocks, giving the user full block-level control after insertion. Classic editor and excerpt fallback paths unchanged (TinyMCE `setContent` / textarea value).
- **AI Content Generator — Prompt Preview Panel**: Collapsible "Preview prompt" section inside the generator modal. Before or after generation, clicking "Preview prompt" calls `GET extend-wp/v1/ai-content/prompt-preview?post_id=&task=&instructions=&translation_mode=` which builds the full system + user prompt without making an AI API call. Both prompts are displayed in monospace `<pre>` blocks so the user can inspect exactly what will be sent to the provider.

### Fixed
- **AI Content Generator — REST 404 on `health-status`**: `rest_api_init` was registered inside `is_admin()` guard; WordPress REST API requests are not admin requests so routes were never registered. Moved `add_action('rest_api_init', ...)` before the guard so REST routes register on every request type.
- **AI Content Generator — grey admin bar dot / providers always empty**: `get_settings()` read from `ewp_ai_content_settings` option but EWP stores settings by **section key** (`provider_config`, `content_settings`, `general_instructions`). Fixed by merging the three section options in `get_settings()`. Also fixed `pre_update_option_` to hook `provider_config` where API keys actually live, and added `$section_keys` map for documentation.
- **AI Content Generator — no EWP Logger entries for AI actions**: `ewp_logger_initialized` hook was behind `is_admin()` guard; REST handlers calling `ewp_log()` hadn't registered the log types. Moved `add_action('ewp_logger_initialized', ...)` before the guard.

- **EWP Logger stuck on "Loading..."**: Fixed issue where the log viewer page remained stuck on "Loading..." when clicking Filter or Reset buttons. The `class-ewp-log-viewer.js` script depends on `awm_ajax_call()` and `ewp_jsVanillaSerialize()` functions from `awm-global-script.js`, but had an empty dependencies array. Added `'awm-global-script'` to the dependencies array in `class-ewp-logger-viewer.php` to ensure proper script load order.
  - **Original Issue**: Log viewer stuck on "Loading..." with no console errors; AJAX calls failing silently due to undefined functions.
  - **Root Cause**: Script registration in `register_assets()` method had empty dependencies array, causing logger script to load before required global functions.
  - **Affected Files**: `includes/classes/ewp-logger/class-ewp-logger-viewer.php` (line 376)
  - **Backwards Compatibility**: Fully backwards-compatible. Only affects script load order, no API or functionality changes.

### Added
- **AI Content Generator module** (`ewp-ai-content`): New admin-only module for generating post titles, excerpts, and full content via AI providers directly from the post editor.
  - **Providers**: OpenAI (GPT-4o, GPT-4.1 family), Claude/Anthropic (Sonnet 4, Haiku 4), Google Gemini (2.5 Flash, 2.5 Pro).
  - **Settings page**: Standalone options page under Extend WP menu with Provider Configuration, Content Settings, and General Instructions (brand voice, target audience, business context, custom instructions) sections.
  - **API key security**: Keys encrypted at rest using AES-256-CBC with WordPress salt constants (`AUTH_KEY` + `SECURE_AUTH_SALT`). Admin UI shows masked value (last 4 chars). Constant overrides supported (`EWP_OPENAI_API_KEY`, `EWP_CLAUDE_API_KEY`, `EWP_GEMINI_API_KEY`).
  - **Health check**: API key validated on settings save via a lightweight REST call to each provider.
  - **Post meta box**: Appears on all public post types. Supports task selection (title / excerpt / full content), provider/model selection, per-post custom instructions, and WPML translation mode (Translate vs Recreate).
  - **Accept flow**: Generated content populates editor fields (Classic TinyMCE or Gutenberg) without auto-saving — user must click Update/Publish.
  - **Screenshot context**: Optional html2canvas screenshot of the post frontend captured client-side and sent to vision-capable models for visual context.
  - **WPML integration**: Language auto-detected via `wpml_current_language` filter; translation mode radio shown when WPML is active.
  - **EWP Logger integration**: `ai_content_generate`, `ai_content_error`, `ai_content_health_check` log types registered.
  - **Admin bar health indicator**: Colored dot in the WP admin bar showing the default provider connection status (green = ok, red = error, yellow = not verified, grey = not configured). Status is polled asynchronously every 5 minutes via `awm_ajax_call` → `GET extend-wp/v1/ai-content/health-status` (reads transient, no live API call). Dot updates in place without page reload. Resets to "not verified" when API keys are saved.
  - **Dynamic Asset Loader**: JS and CSS loaded conditionally via `.ewp-ai-content-metabox` selector; html2canvas loaded only when screenshot feature is enabled.
  - **Developer filters**: `ewp_ai_content_context`, `ewp_ai_content_prompt`, `ewp_ai_content_result`, `ewp_ai_exclude_meta_keys`.
  - **Admin-only**: `is_admin()` guard in constructor — no code executes on the frontend under any circumstances.
  - **Files added**: `includes/classes/ewp-ai-content/` (8 PHP classes), `assets/js/admin/class-ewp-ai-content.js`, `assets/css/admin/ewp-ai-content.css`.

### Fixed
- **Translation loading too early**: Fixed "Function _load_textdomain_just_in_time was called incorrectly" notice by preventing filter callbacks from executing translation functions before `init` action fires. Translation functions (`__()`, `_e()`, etc.) were being called before `load_plugin_textdomain()` executed, triggering WordPress 6.7.0+ warnings.
  - **Original Issue**: Notice appeared in DDEV logs: "Translation loading for the extend-wp domain was triggered too early."
  - **Root Cause**: Filters were being applied during early initialization (before `init`), causing callbacks with translation functions to execute too early.
  - **Affected Files**: 
    - `class-ewp-logger-settings.php`: Added `did_action('init')` check in `register_admin_fields()` + added `get_field_defaults()` method to return untranslated defaults for early initialization + fixed undefined `$fields` variable
    - `class-ewp-logger.php`: Delayed `register_builtin_types()` call to `init` action with priority 20 (runs after `load_plugin_textdomain` at priority 10)
    - `class-register.php`: Added `did_action('init')` check in `awm_position_options_filter()`
    - `class-field.php`: Added `did_action('init')` check in `register_defaults()`
    - `class-wp-content.php`: Added `did_action('init')` check in `register_defaults()`
  - **Backwards Compatibility**: Fully backwards-compatible. Filter callbacks return early (unchanged $options/$fields/$data) when called before `init`, then execute normally after `init`. Settings retrieval now uses untranslated defaults.
    - `class-content-proxy.php`: Moved `AWM_Content_DB` content registration from `plugins_loaded` (priority 100) and `after_setup_theme` to `init` (priority 20), ensuring all `awm_register_content_db` filter callbacks run after translations are loaded
    - `class-extend-wp.php`: Removed duplicate `load_textdomain()` call on `plugins_loaded` hook — textdomain is already loaded in `Setup.php` on `init`

- **PHP 8.1+ deprecation warnings**: Fixed multiple "Passing null to parameter of type string is deprecated" warnings for `strpos()` calls by adding null checks before string operations.
  - **Original Issue**: PHP 8.1+ strict typing caused deprecation warnings when null values were passed to `strpos()`.
  - **Affected Files**: `class-list-form.php` (conditionally call `add_submenu_page()` only when parent is not null), `class-list-table.php` (added null check before `strpos()`), `functions.php` (added null checks in two locations), `settings.php` (added null check before `strpos()`)
  - **Backwards Compatibility**: Fully backwards-compatible. Logic remains identical, just with proper null handling.

### Added
- **WP Rocket: Auto-exclude Dynamic Asset Loader from all JS optimizations**: New `EWP_WP_Rocket` class automatically excludes `class-dynamic-asset-loader.js` from WP Rocket's Delay JS, Minify/Combine, and Defer when WP Rocket is active. Uses filename-based matching so it works regardless of install path (standalone or embedded in other plugins). Extensible via `ewp_wp_rocket_js_exclusions` filter.
  - **Original Request**: "Make sure class-dynamic-asset-loader.js is always excluded by default in WP Rocket if WP Rocket exists."
  - **Affected Files**: `ewp-third-party/class-wp-rocket.php` (new), `Setup.php`
  - **Backwards Compatibility**: Fully backwards-compatible. No-op when WP Rocket is not installed.

- **Options Portability: Export/Import for EWP option pages**: New module that extends the existing Import/Export admin page with option-page export/import functionality. Features include: transaction-safe imports with automatic rollback on failure, recursive URL replacement (home_url, site_url, content_url, upload_url with scheme-safe serialized data handling), versioning metadata in export payload (plugin_version, wp_version, php_version), dry-run mode, enriched logging via ewp_log() with actor/counts/diff context. Accessible via admin UI, REST API (3 endpoints under `extend-wp/v1/options-portability/`), and WP-CLI (`wp ewp options export|import|list`). Includes 7 developer filters/hooks for extensibility.
  - **Original Request**: "I need an export/import logic for all option pages created with EWP with page-view, select box, multiple selection, import validation, and ewp_log logging."
  - **Affected Files**: `class-options-portability.php`, `class-options-portability-cli.php`, `class-ewp-options-portability.js`, `ewp-options-portability.css`, `Readme.md`, `class-import-export.php` (1-line filter), `Setup.php`
  - **Backwards Compatibility**: Fully backwards-compatible. Adds `ewp_import_export_fields_filter` to existing `import_export_fields()` method (non-breaking).

- **Logger: `ewp_logger_enabled` filter**: New filter allows developers to programmatically enable or disable logging regardless of the admin setting. Receives the boolean value from settings as input.
  - **Original Request**: "Can you set a filter for default status of class-ewp-logger?"
  - **Affected Files**: `class-ewp-logger.php`

- **Logger: `ewp_logger_viewer_capability` filter**: New filter controls which capability is required to access the log viewer page and REST API. Defaults to `manage_options`. Single source of truth via `EWP_Logger::get_viewer_capability()`, used by both the viewer and API.
  - **Original Request**: "We need a filter to check which users will have access to the log view."
  - **Affected Files**: `class-ewp-logger.php`, `class-ewp-logger-viewer.php`, `class-ewp-logger-api.php`

- **Logger: Toolbar — per-page, export CSV, delete filtered**: Added a toolbar to the log viewer with three features: (1) per-page select (25/50/100/200/500) to control entries per page, (2) "Export CSV" button that fetches all filtered entries and downloads a CSV file client-side, (3) "Delete Filtered" button that deletes all entries matching the current filters via a new DELETE `/logs` REST endpoint with confirmation dialog. Added `delete_by_filters()` abstract method to `EWP_Logger_Storage` and implemented in `EWP_Logger_File`. Bumped per_page max to 10000 for export. New filter: `ewp_logger_rest_delete_args`.
  - **Original Request**: "Add a button at logger UI where we can cleanup the current selection, set the number of entries we see simultaneously, export as CSV."
  - **Affected Files**: `class-ewp-logger-storage.php`, `class-ewp-logger-file.php`, `class-ewp-logger-api.php`, `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`, `ewp-log-viewer.css`

- **Logger: Simplified filter architecture**: Form field names now match query arg names directly (`owner`, `date_from`, etc.) — no mapping layer needed. Removed JS `nameMap`, removed `data-filter` attributes, removed `get_field_param_map()`. JS is fully abstract: serializes form and sends as-is. PHP `get_filter_params()` returns a flat list of recognized param names (filterable via `ewp_logger_filter_params`). Endpoint args simplified to pagination-only. Date format conversion (d-m-Y → Y-m-d) handled in `extract_filter_args()`.
  - **Original Request**: "Simplify inputs, use date_from instead of ewp_log_filter_date_from."
  - **Affected Files**: `class-ewp-logger-api.php`, `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`

### Fixed
- **Logger: Date filtering same-day bug**: Filtering with From=To on the same day returned 0 results because `created_at` (`2026-02-11 14:30:00`) was string-compared against bare date (`2026-02-11`). Fixed by appending `00:00:00`/`23:59:59` to date-only values in `normalize_query_args()`.
  - **Affected Files**: `class-ewp-logger-storage.php`

- **Logger: d-m-Y date format support**: Added `convert_date_format()` in the REST API to convert `d-m-Y` dates from the AWM date picker to `Y-m-d` before storage queries.
  - **Affected Files**: `class-ewp-logger-api.php`

- **Logger: Multi-select reset**: Fixed Reset button not clearing multi-select filters. Now explicitly deselects all options and dispatches change events.
  - **Affected Files**: `class-ewp-log-viewer.js`

### Removed
- **Logger: Database storage backend removed**: Removed `class-ewp-logger-db.php` entirely. Logger now uses file-only storage (`EWP_Logger_File`), reducing code to maintain. The "Storage Backend" setting has been removed from the settings page. A one-time migration (`maybe_drop_legacy_db_table()`) automatically drops the `ewp_logs` DB table and cleans up stale options for existing installs. Custom backends are still supported via the `ewp_logger_storage_backend` filter.
  - **Original Request**: "Can we totally remove db logging? Just file."
  - **Affected Files**: `class-ewp-logger.php`, `class-ewp-logger-settings.php`, `class-ewp-logger-db.php` (deleted)

### Fixed
- **Cache flushed multiple times per request**: `clear_transients()` in `AWM_Add_Content_DB_Setup` was called once per registered content type on every save/delete because all instances hooked into the same global action. Added a `static $flushed` guard so `ewp_flush_cache()` runs only once per request.
  - **Original Request**: "Why cache was flushed 6 times?"
  - **Affected Files**: `includes/classes/awm-content-db-api/custom-content/class-content-setup.php`

- **Logger enabled by default when no options saved**: Static `$enabled` property defaulted to `true`, so `is_enabled()` returned `true` before `init()` ran. Changed default to `false`; logger now stays disabled until explicitly enabled in settings.
  - **Original Request**: "By default the log should be disabled if no options appear."
  - **Affected Files**: `class-ewp-logger.php`

- **Logger: Syntax error in auto-hook registration**: Fixed a broken method call `$this->\n('options_save')` split across two lines, causing a PHP parse error. Restored to `$this->is_auto_log_enabled('options_save')`.
  - **Affected Files**: `class-ewp-logger.php`

- **Logger helper functions safety**: Added `class_exists('EWP\Logger\EWP_Logger')` guards to `ewp_log()` and `ewp_register_log_type()` so they safely return without errors if the logger isn't loaded. Added new `ewp_register_log_owner()` helper.
  - **Affected Files**: `logger-functions.php`

- **DB Creator: Log errors via EWP Logger**: Added `ewp_log()` call in `dbUpdate()` catch block to log DB update failures at developer level.
  - **Affected Files**: `class-db-creator.php`

### Changed
- **Log viewer level filter supports multiple selection**: Routed the `level` REST param through `parse_multi_param()` and updated both DB and file storage backends to handle array values via `IN()` / `in_array()`.
  - **Original Request**: "ewp_log_filter_level support also multiple choices"
  - **Affected Files**: `class-ewp-logger-api.php`, `class-ewp-logger-db.php`, `class-ewp-logger-file.php`, `class-ewp-logger-viewer.php`

- **Logger: Human-Readable Labels in Table & Filters**: Log viewer table rows and filter dropdowns now show human-readable labels for owner, action type, object type, and user instead of raw keys/IDs. New `EWP_Logger::register_owner($slug, $label)` lets plugins register a display name; `resolve_owner_label()` formats content-type owners as "Fields - Extend WP" by looking up the content type's `parent` field in `$ewp_data_configuration` to find the registered owner label. `resolve_action_type_label()` searches all registered owners (fixes broken owner-scoped lookup). `resolve_object_type_label()` uses built-in map + humanize. REST API enriches each entry with `owner_label`, `action_type_label`, `object_type_label`, `user_display_name`. New developer hooks: `ewp_logger_owner_label`, `ewp_logger_action_type_label`, `ewp_logger_object_type_label`, `ewp_logger_prepare_entry_for_output`, `ewp_logger_rest_response_data`.
  - **Original Request**: "The owners should be like Fields - Extend WP. The logger class will automatically add to each owner the plugin name. Developers can hook into the filters' library and into the results of the log library."
  - **Affected Files**: `class-ewp-logger.php`, `class-ewp-logger-api.php`, `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`

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

- **Logger: awm_show_content Filters**: Log viewer filter bar now uses `awm_show_content` field definitions instead of hardcoded HTML selects. Owner, Action Type, and Object Type options are auto-populated from registered data server-side (no more REST calls to `/logs/owners` and `/logs/types` on page load). Action type options carry `data-owner` attributes for client-side owner→type filtering. New filter hooks: `ewp_logger_viewer_fields`, `ewp_logger_viewer_owner_options`, `ewp_logger_viewer_action_type_options`, `ewp_logger_viewer_object_type_options`. `render_viewer_html()` replaced by `render_results_html()` (buttons + table + pagination only).
  - **Original Request**: "The filters should also be registered with awm_show_content and auto populated based on the action types, owners, object types etc, registered by the users."
  - **Affected Files**: `class-ewp-logger-viewer.php`, `class-ewp-log-viewer.js`

- **Logger: Multi-Select Filters + awm_ajax_call**: Owner, Action Type, Object Type, and Behaviour filters now support `multiple` selection. REST API accepts comma-separated values for these params and builds `IN (...)` SQL clauses. JS uses `awm_ajax_call` instead of raw `fetch` for all REST requests (nonce handled automatically via `awmGlobals`). `getFilters()` serializes the entire form via `ewp_jsVanillaSerialize` and translates field names to REST params via a `nameMap` built from `data-filter` attributes — developer-added fields are automatically included. `resetFilters()` uses native `form.reset()` to restore all fields to their server-rendered defaults generically.
  - **Original Request**: "We added multiple attribute in some options of get_viewer_fields. Please make the changes in REST and JS. Use awm_ajax_call for fetch. Get all data from the form, not one by one."
  - **Affected Files**: `class-ewp-logger-api.php`, `class-ewp-logger-storage.php`, `class-ewp-logger-db.php`, `class-ewp-logger-file.php`, `class-ewp-log-viewer.js`

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
