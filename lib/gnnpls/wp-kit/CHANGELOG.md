# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
