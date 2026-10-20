# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Prompt: CI must run on GitLab only, GitHub is just a push mirror.

### Changed
- CI: `check` job now uses the `composer:2` image (the custom registry image does not exist); pipelines run on merge requests and the `develop` and `main` branches, never on tags.
- Removed the GitHub Actions workflow (`.github/`); `.gitlab-ci.yml` now runs the same checks (`php -l`, `composer validate`, PHPUnit, PHPCS, PHPStan) on merge requests and the default branch.

## [1.0.0] - 2026-10-20

Prompt: rebrand the library from gnnpls/wp-kit to Mammoth WP conventions (`mammothwp/wp-kit`).

### Changed
- Package renamed to `mammothwp/wp-kit`; homepage https://mammothwp.com, source https://github.com/mammothwp/wp-kit, contact info@mammothwp.com. Composer `replace` blocks installing it next to `gnnpls/wp-kit`.
- PHP namespace root is now `MammothWP\WpKit\`.
- Hooks are now `mmwp_kit_*`; WP_Error codes are now `mmwp_*`.
- Dev tooling added: PHPUnit, PHPCS (WPCS prefixes `mmwp` / `MammothWP`) and PHPStan, with `composer lint|test|phpcs|phpstan` scripts.

### Added
- `compat.php` (Composer `files` autoload) and `src/Compat/`: lazy `class_alias()` shims for every 0.x class (deprecation notice once per class) and `Legacy_Hooks`, which fires the old hook names through `do_action_deprecated()` / `apply_filters_deprecated()`.
- Tests proving the class aliases and hook bridges.
- `docs/BRAND.md`.

### Backwards compatibility
- Old class names and old hook names keep working and are removed in 2.0.0.
- Not aliased: the `mwp_*` error codes (they are strings, not symbols); consumers comparing `WP_Error::get_error_code()` must switch to the `mmwp_*` codes.
- The library has no global functions and persists no options, transients or meta, so there is nothing to wrap or migrate.
- A plugin that bundles an old `gnnpls/wp-kit` copy and loads before this one still declares its own `Gnnpls\WP\Kit`; both loaders then run side by side until that plugin upgrades.

### Upgrading from gnnpls/wp-kit

| Old | New |
|---|---|
| `gnnpls/wp-kit` (Composer) | `mammothwp/wp-kit` |
| `Gnnpls\WP\Kit` | `MammothWP\WpKit\Kit` |
| `Gnnpls\WP\Registry` | `MammothWP\WpKit\Registry` |
| `Gnnpls\WP\Resource` | `MammothWP\WpKit\Resource` |
| `Gnnpls\WP\Operation` | `MammothWP\WpKit\Operation` |
| `Gnnpls\WP\Field` | `MammothWP\WpKit\Field` |
| `Gnnpls\WP\Field_Map` | `MammothWP\WpKit\Field_Map` |
| `Gnnpls\WP\Context` | `MammothWP\WpKit\Context` |
| `Gnnpls\WP\Capability` | `MammothWP\WpKit\Capability` |
| `Gnnpls\WP\Confirm` | `MammothWP\WpKit\Confirm` |
| `Gnnpls\WP\Inventory` | `MammothWP\WpKit\Inventory` |
| `Gnnpls\WP\Adapters\Rest_Adapter` | `MammothWP\WpKit\Adapters\Rest_Adapter` |
| `Gnnpls\WP\Adapters\Cli_Adapter` | `MammothWP\WpKit\Adapters\Cli_Adapter` |
| `Gnnpls\WP\Adapters\Ability_Adapter` | `MammothWP\WpKit\Adapters\Ability_Adapter` |
| `mwp_kit_booted` | `mmwp_kit_booted` |
| `mwp_registry_ready` | `mmwp_kit_registry_ready` |
| `mwp_operation_capability` | `mmwp_kit_operation_capability` |
| `mwp_operation_before_run` | `mmwp_kit_operation_before_run` |
| `mwp_operation_result` | `mmwp_kit_operation_result` |
| `mwp_ability_definitions` | `mmwp_kit_ability_definitions` |
| error `mwp_invalid_param` | `mmwp_invalid_param` |
| error `mwp_forbidden` | `mmwp_forbidden` |
| error `mwp_confirm_required` | `mmwp_confirm_required` |
| error `mwp_no_handler` | `mmwp_no_handler` |
| error `mwp_operation_failed` | `mmwp_operation_failed` |

## [0.4.0] - 2026-10-13

Prompt: expose wp-kit consumers (EWP, Filox) to MCP clients at once — "go ahead with the mcp.public change", after comparing an own MCP server with Agent Connector.

### Added
- Abilities registered by `Ability_Adapter` now carry `meta.mcp.public` (and `meta.mcp.type = 'tool'`), so MCP servers built on `wordpress/mcp-adapter` (its default server, Agent Connector) list them as tools. Input schema and `readonly`/`destructive`/`idempotent` annotations map to the MCP tool as they are.
- `Resource::mcp()` (default `true`): return `false` to keep a resource's abilities off MCP.

### Backwards compatibility
- No signature change. Abilities that were not MCP-visible become visible to MCP servers on sites that run one; execution still goes through the ability's own permission and confirm checks. Override `Resource::mcp()` or use `mwp_ability_definitions` to opt out.

## [0.3.3] - 2026-10-13

Prompt: moving a plugin's remaining legacy REST routes (session-bound handlers, payment webhooks) onto REST-only resources — "proceed to the fix in wp-kit".

### Fixed
- `Rest_Adapter` no longer overwrites the status of a `WP_REST_Response` the service built itself. A response with any status other than 200 is sent as returned, headers included, so a wrapped handler's `400`/`404`/`422`/`503` (e.g. a payment webhook telling the provider to retry) is no longer turned into the declared success status. A result with status 200 (the `WP_HTTP_Response` default), a plain value, or an array still gets the declared status, so `->rest('POST', '', 201)` behaves as before.

### Backwards compatibility
- No public API change. Only services that returned a non-200 `WP_REST_Response` see a difference: they now get the status they asked for instead of the declared one.

## [0.3.2] - 2026-10-12

Prompt: CI jobs failing on `gnnpls/wp-self-test` (same loader pattern) — "does the latest wp-kit code have this fix?" → "please update both repos now".

### Fixed
- `Kit::register()` no longer boots immediately when it runs before `wp-includes/plugin.php` (Composer `autoload.files` included from `wp-config.php`, i.e. Bedrock root installs and every WP-CLI command). It treated a missing `add_action()` as "past `plugins_loaded`", installed the autoloader and flushed `on_ready()` callbacks before WordPress existed. It now pre-seeds `$wp_filter['plugins_loaded'][-100]` (the same mechanism as `WP_CLI::add_wp_hook()`), so boot happens on `plugins_loaded` as documented.

### Backwards compatibility
- No public API change. `on_ready()` callbacks now run on `plugins_loaded` in the early case instead of during `wp-config.php`.

## [0.3.1] - 2026-10-12

Prompt: publish like gnnpls/wp-self-test — connect the code to GitHub and Packagist, drop the old vendor, always use gnnpls.com and nikos@gnnpls.com, and remove the history.

### Changed
- Published on GitHub (`gnnpls/wp-kit`) and Packagist. Author and support contact are Nikos Giannopoulos <nikos@gnnpls.com>, homepage https://gnnpls.com; old vendor references removed from files and docs.
- CI moves from a PHP 8.2-only pipeline to a GitHub Actions lint workflow: `php -l` on PHP 7.4 and 8.3, plus `composer validate --strict`.
- Repository history restarts from this release.

### Added
- `LICENSE` (GPL-2.0), `.gitattributes` keeping dev-only files out of Composer dist archives, `keywords` and `support` in `composer.json`.

### Backwards compatibility
- No code changes. Namespace, hook and error-code names (`Gnnpls\WP`, `mwp_*`) are unchanged.

## [0.3.0] - 2026-09-14

Prompt: "can you security review all the plugin?" → "please proceed".

### Security
- Nested object properties are now validated, not only normalised: `required()`, `min()`/`max()`, `max_length()`, enum choices and `validate_with()` on a `Field::object()->properties([...])` child are enforced on every surface. Previously they were advertised in the REST args and ability schema but never checked.
- `additional_properties(false)` now rejects undeclared keys (`mwp_invalid_param`, reason `additional_properties`).
- `Field::array()` / multiple enums reject non-scalar items unless the item type is `object`, `array` or `mixed`.
- `Operation::allow_extra()`: undeclared input keys no longer bind to handler parameters by name. Only declared fields (and `confirm`) do; everything else goes to the first array-typed parameter as documented.
- Handler exceptions no longer leak their message to REST and ability callers. `mwp_operation_failed` carries a generic message; the exception text is `error_log`ged and attached as `detail` only for CLI runs or when `WP_DEBUG` is on.
- `Confirm::check()` coerces the flag like a boolean field: `"no"`, `"off"` and arbitrary strings no longer count as confirmation.

### Fixed
- `Field::bool()` returned a PHP warning instead of a validation error for array input.

### Added
- `HOOKS.md`: declaration of every custom action and filter (name, type, params, firing point). README hooks table now links to it.
- `CLAUDE.md`: guidance for Claude Code (commands, loader model, execution path, repo conventions). Refreshed via `/init` to record the 0.3.0 security invariants (advertised ⇒ enforced, declared-only name binding, no exception text in responses) and that CI's PHP 8.2 lint does not catch 7.4 incompatibilities.

### Backwards compatibility
- Consumers relying on nested constraints being ignored, on extra keys reaching named handler parameters, or on `confirm=<any string>` will now receive 400 errors or different arguments. These were all contract violations rather than documented behaviour.
- The REST error body for a failing handler changes from the exception message to `Operation "<name>" failed.`; check logs (or enable `WP_DEBUG`) for the detail.

## [0.2.0] - 2026-09-14

Prompt: "add to wp-kit a flag of which layer to use, so if a layer does not need cli we just register the other two."

### Added
- `Resource::surfaces()` and `Resource::surfaces_reason()`: resource-level default for the layers every operation is exposed on. Operations that do not call `Operation::surfaces()` inherit it; `Operation::has_surfaces()` reports whether one did. The adapters and `Inventory` already honour per-operation surfaces, so a resource can now drop a whole layer (e.g. CLI) with one method instead of repeating `->surfaces()` on each operation.

### Backwards compatibility
- Default is unchanged (all three surfaces); existing per-operation calls take precedence.

## [0.1.0] - 2026-09-14

### Added
- First release as a standalone package, extracted from an internal plugin. Namespace `Gnnpls\WP`, Composer name `gnnpls/wp-kit`.
- `Resource`, `Operation`, `Field`, `Field_Map`: declare a feature once and project it onto REST (`Rest_Adapter`), WP-CLI (`Cli_Adapter`) and the WordPress Abilities API (`Ability_Adapter`).
- `Operation::run()`: the single execution path (normalise, validate, authorise, confirm, hooks, service call).
- `Registry` and `Inventory` for parity tests and generated coverage lists.
- Version-gated `Loader` so several bundled copies coexist.
