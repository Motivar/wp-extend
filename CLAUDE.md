# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`wp-extend` (plugin name "Extend WP", text domain `extend-wp`) is a WordPress developer toolkit plugin. It lives inside a DDEV WordPress site (`wp-content/plugins/wp-extend`), so runtime commands go through `ddev`. It is consumed both directly and as a Composer package (`motivar/extend-wp`) by sibling plugins/themes (e.g. `filox`), which hook into its filters — treat every public filter as an API surface with external callers.

## Commands

Build JS/CSS (webpack via `@wordpress/scripts`; also regenerates `build/version.php` from `package.json` version):

```bash
npm run build
```

Watch mode:

```bash
npm run start
```

Composer install (note: `vendor-dir` is `lib/`, not `vendor/`):

```bash
composer install
```

Syntax-check a changed PHP file:

```bash
php -l includes/classes/ewp-logger/class-ewp-logger.php
```

WP-CLI inside the DDEV site (run from anywhere in the project):

```bash
ddev wp ewp log stats
```

Plugin WP-CLI commands: `ewp delete-cache`, `ewp log list|cleanup|stats|types`, `ewp options export|import|list`, `ewp content types|list|get|create|update|delete`, `ewp self-test list|preview|run|cleanup|report`.

There is no linter config or PHPCS ruleset. There **are** two test layers, and both must pass before a change is done (the pre-push hook and GitLab CI run both):

```bash
ddev exec bash -c "cd wp-content/plugins/wp-extend && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db tests/vendor/bin/phpunit -c phpunit.xml.dist"
```

```bash
ddev exec bash -c "cd wp-content/plugins/wp-extend && WP_CORE_DIR=/var/www/html WP_TESTS_DB_HOST=db php tests/self-test-runner.php"
```

Against the live DDEV site instead of the test database: `ddev exec wp ewp self-test run --cleanup --user=1`. One-time setup: `cd tests && composer install` (own vendor dir, never `lib/`), and the `wp_extend_tests` database (the pre-push hook creates it).

## Testing (required for every code change)

- **PHPUnit** (`tests/`, wp-phpunit + a real WordPress core) tests the shared implementations in isolation. Test files end in `-test.php`. Create custom tables only in `setUpBeforeClass()` — `WP_UnitTestCase` rewrites `CREATE TABLE` into `CREATE TEMPORARY TABLE` inside a test (see `tests/includes/trait-content-fixture.php`).
- **Self-test suite** (`includes/classes/ewp-self-test/`, docs in `docs/self-test.md`) exercises every REST route, CLI command and ability on a real install with removable test data. `manifest.json` there is the single source of truth for what is tested; the dashboard (*Extend WP → Self test*, `WP_DEBUG` only), `wp ewp self-test`, the `ewp-self-test/*` abilities, the pre-push hook and CI all read it.
- **When you add or change a REST route, WP-CLI command or ability**: add/extend a case and list it under that case's `covers` in `manifest.json` (a PHPUnit test fails for uncovered CLI commands), add PHPUnit coverage for the shared implementation, then run both commands above. A feature is not done until both pass.
- The CLI wrappers cannot run without `WP_CLI`; in PHPUnit and the dashboard they run on the in-process shim in `class-ewp-self-test-wp-cli-shim.php`.

## Architecture

### Bootstrap

`extend-wp.php` defines `awm_path`, `awm_url`, `awm_relative_path`, `AWM_ASSET_VERSION` (read from `build/version.php`), loads `lib/autoload.php` (Composer), and instantiates `\EWP\Setup`.

`includes/classes/Setup.php` is the single load manifest — every module is `require_once`d here, in a deliberate order (comments mark ordering constraints, e.g. meta-inheritance must load after `ewp-fields` because both hook at `PHP_INT_MAX`). Adding a module means adding a line here. Composer `autoload.files` additionally loads `includes/functions/init.php` (→ `main.php`, `library.php`, `transient_functions.php`) and `class-encryption.php`.

Only `EWP\` (PSR-4 → `includes/classes/`) is namespaced. Most classes are global-namespace legacy classes (`AWM_Meta`, `Extend_WP_Fields`, `AWM_DB_Creator`, `EWP_Template_Resolver`, …) with `awm_` / `ewp_` prefixed procedural helpers. Follow the convention of the file you are editing rather than imposing namespaces on legacy files.

### The field system (the core abstraction)

Everything — post meta boxes, term/user fields, options pages, customizer sections, Gutenberg blocks, custom-content-table forms — is described by the same nested array structure ("a library"): `array($meta_key => array('label' => …, 'case' => 'input|select|repeater|section|…', …))`.

Two paths produce these libraries:

1. **Code**: hook `awm_add_meta_boxes_filter`, `awm_add_term_meta_boxes_filter`, `awm_add_user_boxes_filter`, `awm_add_options_boxes_filter`, `awm_add_customizer_settings_filter`, `ewp_gutenburg_blocks_filter`, `awm_content_db_metaboxes_filter`. See `examples/field-examples.php`.
2. **UI**: `Extend_WP_Fields` (`includes/classes/ewp-fields/`) hooks all of the above at `PHP_INT_MAX` and injects fields configured in wp-admin. Those definitions are stored as `ewp_fields` rows in the custom content DB (not postmeta), read by `awm_get_fields($case, $type, $id)` (transient-cached), and converted to libraries by `awm_create_boxes($case, $fields)` / `awm_create_library($field)`.

Rendering and saving of every field `case` lives in `includes/functions/library.php` (~2400 lines) — `awm_show_content()` renders, `awm_save_custom_meta()` persists. A new field type means a new `case` in both places plus, usually, a template under `templates/`.

`AWM_Meta` (`class-extend-wp.php`) wires the libraries into WordPress: `add_meta_boxes`, `save_post`, `edit_term`/`create_term`, `profile_update`, `admin_menu` options pages, admin list-table columns, `restrict_manage_posts` filters, and `pre_get_posts`.

### Custom content DB (`awm-content-db-api`)

An alternative to custom post types: each registered content type gets two tables, `{prefix}_{id}_main` and `{prefix}_{id}_data` (mirroring posts/postmeta), created and versioned by `AWM_DB_Creator` from a schema array (bumping the schema's `version` triggers `dbDelta`; the applied version is stored in option `ewp_version_{table}`). Register via the `awm_register_content_db` filter. CRUD helpers: `awm_get_db_content()`, `awm_get_db_content_meta()`, and friends in `custom-content/content-functions.php`. The plugin dogfoods this — `ewp_fields`, post-type/taxonomy definitions and search filters are all stored this way.

### REST API

Canonical namespace is `extend-wp/v1` (logger, options portability, object search, REST health, generic AWM API). Search filters expose `ewp-filter/1`. UI-configured dynamic endpoints get their own namespace, defaulting to `awm-dynamic-api/v1`. Prefer REST over `admin-ajax.php`. `includes/classes/ewp-rest-health/` scans source for `register_rest_route()` calls to build a route inventory — keep route registration greppable (literal namespace strings, `$namespace`/`$rest_namespace` properties).

### Dynamic Asset Loader

`class-dynamic-asset-loader.php` + `assets/js/class-dynamic-asset-loader.js`: assets register against a CSS selector via the `ewp_register_dynamic_assets` filter and are injected only when a matching element appears in the DOM (MutationObserver). Registration must happen before `init` priority 1, when the loader collects entries — this is why several modules register in a constructor or at `init` priority 0. Docs: `docs/dynamic-asset-loader.md`, `docs/external-plugin-api.md` (`awmOnReady()` / `awmWaitForFunction()` for other plugins consuming the lazy-loaded `awm_*` JS globals).

### Build output

`webpack.config.js` auto-discovers entries: every `.js` in `assets/js/modules/` → `build/modules/*`, plus `assets/js/global/awm-global-script.js`, `assets/js/admin/awm-admin-script.js` and `src/index.js`. Modules are loaded as lazy ES chunks. `assets/` holds source; `build/` is committed output — after touching JS in `assets/js/modules|global|admin`, run `npm run build`. SCSS under `assets/css/**/sass/` compiles to the `.css`/`.min.css` next to it; some admin CSS is hand-maintained in both plain and `.min` form — update both.

### Other modules

- `ewp-logger/` — activity log with file storage, viewer UI, REST (`extend-wp/v1/logs`), WP-CLI, and read-only WordPress Abilities API integration plus an AI "Diagnose" box (gated by `EWP\Abilities\EWP_Abilities::is_supported()` / `wp_supports_ai()`). The log *write* ability lives in `ewp-abilities/`, not here.
- `ewp-abilities/` — registers the plugin's functionality with the core WordPress Abilities API: log writes, full CRUD over `ewp_fields`, generic custom content rows, post types and taxonomies, plus search filter reads, options export/import and cache flushing. One provider class per category extending `EWP_Abilities_Provider` (or `EWP_Abilities_Typed_Provider` for a fixed content type); persistence goes through `EWP_Abilities_Content_Service`, never the content-type REST routes (whose create/update handlers are broken). Everything is gated on WordPress 6.9+ via `EWP_Abilities::has_api_support()`. Input schemas are derived from the field libraries by `EWP_Abilities_Schema`, so do not hand-write them. Docs: `docs/abilities.md`.
- `ewp-search-filter/` — UI-configured front-end filters, `[ewp_search id="…"]` shortcode, REST-backed; templates in `templates/frontend/search/`.
- `ewp-wp-content/` — UI-registered post types/taxonomies, slug manager, meta inheritance, and `EWP_Template_Resolver` (a post type can reuse another object's theme template; the `ewp_template_source_path` filter lets the owning plugin supply its own path).
- `ewp-options-portability/` — export/import options pages (REST + CLI).
- `class-encryption.php` — `EWP_Encryption` with `is_encrypted()`/`is_masked()` guards; encrypts flagged meta/option values on save via `update_*_meta` / `updated_option` hooks. Decryption is explicit at read time by the consumer, not via an `option_` filter.
- `ewp-self-test/` — manifest-driven self-test suite (`EWP\SelfTest`): `EWP_Self_Test_Runner` is the one implementation behind the wp-admin dashboard + REST (`extend-wp/v1/self-test/*`, `WP_DEBUG` only), `wp ewp self-test`, the `ewp-self-test/*` abilities and `tests/self-test-runner.php`. Cases extend `EWP_Self_Test_Case` (preview / run / validate / cleanup). Must load right before `ewp-abilities` (it hooks `ewp_abilities_providers`, applied at require time). Docs: `docs/self-test.md`.
- `ewp-third-party/` — WPML and WP Rocket compatibility. `ewp-ai-content/` is present but **not loaded** (its `require_once` in `Setup.php` is commented out).

## Conventions

- Prefixes: `awm_` (older layer) and `ewp_` (newer). Match the surrounding module; use `ewp_` for genuinely new subsystems.
- Every PHP file starts with an `ABSPATH` guard. Indentation is inconsistent across the codebase (tabs, 1 space, 4 spaces) — match the file.
- **Always separate JS, CSS and HTML** — never inline `<style>`/`<script>` blocks, `style="…"` attributes, or `onclick="…"` handlers emitted from PHP, and no HTML strings built inside JS where a template can carry them.
  - **HTML** → a template file under `templates/`, rendered by the PHP layer.
  - **JS** → an ES module under `assets/js/modules/` (auto-discovered by webpack into `build/modules/*`), registered against a CSS selector through the `ewp_register_dynamic_assets` filter so it is imported lazily only on pages where its elements exist. Prefer this selector-driven module import over enqueuing a script globally. Pass data in via the registration's `localize` key (or `wp_localize_script`), and hook elements by class or `data-` attribute.
  - **CSS** → SASS. Write `.scss` under `assets/css/**/sass/` (or the module's `.scss` next to its output) and compile; do not hand-edit the generated `.css`/`.min.css`. Colors, spacing and other tokens go through `:root` custom properties.
- Every new extension point gets `apply_filters`/`do_action` with a documented signature; new filters belong in the module's readme/docs and the changelog.
- User-facing strings use the `extend-wp` text domain.
- Vanilla JS (jQuery only where already present in the file you are editing).
- **Keep REST, WP-CLI, and WP Abilities API in sync.** Any functionality reachable one way should be reachable all three ways: a new REST route gets a matching WP-CLI subcommand (and vice versa) and, where it fits the Abilities API's read/act model, a registered ability (guarded by `function_exists('wp_register_ability')`, following the `ewp-logger/` pattern). When adding or changing a feature, check whether existing REST/CLI/Abilities surfaces for it now drifted out of sync and update the others in the same change.
  - **One shared callback.** All three surfaces must call the same underlying function/method for a given piece of functionality — the REST callback, the CLI command handler, and the ability's `execute_callback` should each be a thin wrapper (auth/arg-shape only) around one shared implementation, never three parallel copies of the logic.
  - **Document parameters thoroughly.** Every parameter on that shared function and on each surface's wrapper gets a doc comment: type, whether required/optional, default, accepted values/format, and what it does. REST route `args` need `type`/`required`/`description`/`sanitize_callback`/`validate_callback` filled in (not left to defaults), WP-CLI commands need full `@synopsis`/docblock parameter descriptions (so `wp help` is self-sufficient), and ability `input_schema`/`output_schema` need real JSON Schema `description`s per property, not just types.

## Changelog (required)

`CHANGELOG.md` is Keep a Changelog format and is treated as the project's design record. After any change, add an entry under `[Unreleased]` including the originating prompt/question (1–2 lines), a summary, affected files, new hooks/routes/settings, and backwards-compatibility notes. Existing entries are detailed — match that depth. Skip only if explicitly told not to update the changelog.

`.ai/context/_current.md` holds working notes for the in-flight task and `.ai/sessions/` past session logs; both are gitignored but useful context when picking up unfinished work.
