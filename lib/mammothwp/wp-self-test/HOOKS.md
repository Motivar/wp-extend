# Hooks

Every custom action and filter fired by `mammothwp/wp-self-test`. All names carry
the `mmwp_self_test_` prefix. Each hook has a WP-core style docblock directly
above the `do_action` / `apply_filters` call at the location listed; keep this
file, those docblocks and the README "Filters and actions" table in sync when
adding or changing a hook, and reuse an existing hook rather than firing a
near-duplicate.

| Hook | Type | Since | Fired in |
|---|---|---|---|
| [`mmwp_self_test_enabled`](#mmwp_self_test_enabled) | filter | 0.4.0 | `src/Loader.php` — `Loader::enabled()` |
| [`mmwp_self_test_booted`](#mmwp_self_test_booted) | action | 0.1.0 | `src/Loader.php` — `Loader::boot()` |
| [`mmwp_self_test_register`](#mmwp_self_test_register) | action | 0.1.0 | `src/Self_Test.php` — `Self_Test::fire_register()` |
| [`mmwp_self_test_manifest`](#mmwp_self_test_manifest) | filter | 0.1.0 | `src/Manifest.php` — `Manifest::load()` |
| [`mmwp_self_test_ui_enabled`](#mmwp_self_test_ui_enabled) | filter | 0.1.0 | `src/Config.php` — `Config::ui_enabled()` |
| [`mmwp_self_test_shim_enabled`](#mmwp_self_test_shim_enabled) | filter | 0.1.0 | `src/Config.php` — `Config::shim_enabled()` |
| [`mmwp_self_test_capability`](#mmwp_self_test_capability) | filter | 0.1.0 | `src/Config.php` — `Config::capability()` |
| [`mmwp_self_test_abilities_available`](#mmwp_self_test_abilities_available) | filter | 0.1.0 | `src/Config.php` — `Config::abilities_available()` |
| [`mmwp_self_test_asset_url`](#mmwp_self_test_asset_url) | filter | 0.1.0 | `src/Config.php` — `Config::asset_url()` |
| [`mmwp_self_test_ability_definitions`](#mmwp_self_test_ability_definitions) | filter | 0.1.0 | `src/Surfaces/Abilities.php` — `Abilities::register_abilities()` |
| [`mmwp_self_test_completed`](#mmwp_self_test_completed) | action | 0.1.0 | `src/Runner.php` — `Runner::run()` |

## Lifecycle

### `mmwp_self_test_enabled`

**Filter.** Whether the package boots at all on this request (surfaces,
manifests, dashboard, commands and abilities). Evaluated once, on
`plugins_loaded` (priority -100), and memoised, so add the filter at plugin
load time. Return `false` on e.g. front-end requests, `true` to run on a
production site. When it returns
`false`, nothing is autoloaded and `mmwp_self_test_booted` does not fire.

| Param | Type | Description |
|---|---|---|
| `$enabled` | `bool` | Default `true` unless the environment is `production` (since 0.7.0; 0.6.x defaulted to `true` everywhere). |
| `$environment` | `string` | `WP_ENV` when defined, else `wp_get_environment_type()`. |

### `mmwp_self_test_booted`

**Action.** Fires once the loader has chosen the newest registered copy of
the package, installed its autoloader and booted `Self_Test`.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Directory of the booted copy. |
| `$version` | `string` | Version of the booted copy. |

### `mmwp_self_test_register`

**Action.** Lets plugins register their manifests. Fires at `init` priority 0
(or immediately at boot when `init` has already run), once per request.

| Param | Type | Description |
|---|---|---|
| `$registry` | `MammothWP\SelfTest\Registry` | Call `$registry->register($slug, $manifest_path, $opts)`. |

### `mmwp_self_test_manifest`

**Filter.** Filters one parsed and validated manifest before its cases are
built.

| Param | Type | Description |
|---|---|---|
| `$manifest` | `array` | Parsed manifest (`version`, `cases`). |
| `$path` | `string` | Manifest file path. |
| `$plugin` | `string` | Owning plugin slug. |

## Gates and configuration

### `mmwp_self_test_ui_enabled`

**Filter.** Whether the wp-admin dashboard and its `mmwp/v1` REST
routes are registered. CLI commands and abilities are not affected.

| Param | Type | Description |
|---|---|---|
| `$enabled` | `bool` | Default `true` unless the environment is `production`. |
| `$environment` | `string` | `WP_ENV` when defined, else `wp_get_environment_type()` (added in 0.6.0). |

### `mmwp_self_test_shim_enabled`

**Filter.** Whether the in-process `WP_CLI` shim (`src/Wp_Cli_Shim.php`) is
defined on web requests, so the dashboard can exercise CLI commands.

| Param | Type | Description |
|---|---|---|
| `$enabled` | `bool` | Default: the result of `Config::ui_enabled()`. |

### `mmwp_self_test_capability`

**Filter.** Capability every surface (dashboard, REST, abilities) requires.

| Param | Type | Description |
|---|---|---|
| `$capability` | `string` | Default `manage_options`. |

### `mmwp_self_test_abilities_available`

**Filter.** Whether the Abilities API is usable for self-tests; a host plugin
that gates its own abilities can report that here, and ability checks in
cases are skipped when it returns `false`.

| Param | Type | Description |
|---|---|---|
| `$available` | `bool` | Default `function_exists('wp_get_ability')`. |

### `mmwp_self_test_asset_url`

**Filter.** Public URL of a file shipped with the booted copy of the package
(dashboard CSS/JS).

| Param | Type | Description |
|---|---|---|
| `$url` | `string` | Resolved URL. |
| `$relative` | `string` | Path relative to the package root. |

## Surfaces and runs

### `mmwp_self_test_ability_definitions`

**Filter.** Filters the `mmwp-self-test/*` ability definitions before they are
passed to `wp_register_ability()`. Only fires when the Abilities API is
available (WordPress 6.9+).

| Param | Type | Description |
|---|---|---|
| `$definitions` | `array<string,array>` | `wp_register_ability()` arguments keyed by ability name. |

### `mmwp_self_test_completed`

**Action.** Fires after a run finished and its report was stored in the
`mmwp_self_test_state` option. Fires for every surface (dashboard, REST,
WP-CLI, abilities, `bin/run.php`).

| Param | Type | Description |
|---|---|---|
| `$report` | `array` | The stored report (`started_at`, `finished_at`, `duration_ms`, `user_id`, `site`, `package`, `plugin`, `cleanup`, `summary`, `results`). |

## Deprecated hook names (pre-1.0)

Before 1.0 every hook above carried the `mwp_self_test_` prefix. Each one is
still fired after its `mmwp_self_test_` replacement through
`do_action_deprecated()` / `apply_filters_deprecated()` (see
`src/Compat/Hooks.php`; `mwp_self_test_enabled` and `mwp_self_test_booted` are
bridged inline in `src/Loader.php`, which runs before the autoloader exists).
WordPress logs a deprecation notice only when a callback is attached to the old
name. The old names are removed in the next major version.

| Old name | New name |
|---|---|
| `mwp_self_test_enabled` | `mmwp_self_test_enabled` |
| `mwp_self_test_booted` | `mmwp_self_test_booted` |
| `mwp_self_test_register` | `mmwp_self_test_register` |
| `mwp_self_test_manifest` | `mmwp_self_test_manifest` |
| `mwp_self_test_ui_enabled` | `mmwp_self_test_ui_enabled` |
| `mwp_self_test_shim_enabled` | `mmwp_self_test_shim_enabled` |
| `mwp_self_test_capability` | `mmwp_self_test_capability` |
| `mwp_self_test_abilities_available` | `mmwp_self_test_abilities_available` |
| `mwp_self_test_asset_url` | `mmwp_self_test_asset_url` |
| `mwp_self_test_ability_definitions` | `mmwp_self_test_ability_definitions` |
| `mwp_self_test_completed` | `mmwp_self_test_completed` |
