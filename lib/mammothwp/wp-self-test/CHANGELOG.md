# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- CI runs on GitLab only (`.gitlab-ci.yml`, one `check` job: `php -l`, `composer validate --strict`, PHPUnit, PHPCS, PHPStan); GitHub is a push mirror. The GitHub Actions lint workflow and `.github/` are removed.
- `.gitlab-ci.yml`: `check` job uses the public `composer:2` image; pipelines run for merge requests and the `develop` and `main` branches, never tags.

## [1.0.0] - 2026-10-20

Rebrand to Mammoth WP. Source of truth is GitLab `mammothwp/wp-self-test`; `github.com/mammothwp/wp-self-test` is a push mirror.

### Changed
- **Breaking (renames, all with a deprecated alias for this major version, see below):** Composer package, PHP namespace, hook/option/REST/CLI/ability names and CSS/JS identifiers now follow the Mammoth WP brand rules (`mmwp_`, `mmwp-`, `MammothWP\`).
- `wp mmwp self-test <slug> --format=table` runs one plugin's cases (the shared Mammoth WP CI command). `wp mmwp self-test` is a single command: the first positional argument is a plugin slug or one of `list`, `preview`, `run`, `cleanup`, `report`, `migrate`.
- Authors, homepage and support links are Mammoth WP (info@mammothwp.com, mammothwp.com).
- README rewritten; brand guidelines added in `docs/BRAND.md`.

### Added
- `compat.php` (Composer `autoload.files`): `Gnnpls\SelfTest\*` classes resolve to `MammothWP\SelfTest\*` through `class_alias()`; each old class name is reported once per site.
- `Compat\Hooks`: every hook fires under the new name, then under the old `mwp_` name via `do_action_deprecated()` / `apply_filters_deprecated()`.
- `Migration` and `wp mmwp self-test migrate [--dry-run]`: idempotent move of `mwp_self_test_state` to `mmwp_self_test_state` (runs once at `init`; the old option is read as a fallback until then).
- Deprecated aliases: `wp mwp self-test <action>` (prints a warning, forwards), REST namespace `mwp-self-test/v1`, abilities `mwp-self-test/*`. They are skipped when another plugin's pre-1.0 copy is active and already registers them.
- Dev tooling: PHPUnit suite with a WordPress stub bootstrap (`tests/`), PHPCS (WPCS, `PrefixAllGlobals` = `mmwp`/`MammothWP`) and PHPStan; the lint workflow now runs all three.

### Fixed
- `bin/install-hooks` recognises hooks written by `gnnpls/wp-self-test` and rewrites them to the new vendor path.

### Upgrading from gnnpls/wp-self-test

`composer.json` declares `"replace": {"gnnpls/wp-self-test": "self.version"}`. Swap the requirement for `mammothwp/wp-self-test`; old names keep working but are deprecated and removed in 2.0.0.

| Old | New |
|---|---|
| `gnnpls/wp-self-test` (Composer) | `mammothwp/wp-self-test` |
| `Gnnpls\SelfTest\*` (every class) | `MammothWP\SelfTest\*` |
| `mwp_self_test_enabled`, `_booted`, `_register`, `_manifest`, `_ui_enabled`, `_shim_enabled`, `_capability`, `_abilities_available`, `_asset_url`, `_ability_definitions`, `_completed` | same suffix with `mmwp_self_test_` |
| `mwp_self_test_state` (option) | `mmwp_self_test_state` |
| WP_Error codes `mwp_self_test_*` | `mmwp_self_test_*` |
| `wp mwp self-test <action>` | `wp mmwp self-test <action>` or `wp mmwp self-test <slug>` |
| REST `mwp-self-test/v1/<route>` | `mmwp/v1/self-test/<route>` |
| Abilities `mwp-self-test/<verb>` | `mmwp/self-test-<verb>` |
| Ability category `mwp-self-test` | `mmwp-self-test` |
| Admin page `?page=mwp-self-test`, asset handles `mwp-self-test` | `mmwp-self-test` (no alias) |
| CSS `.mwp-self-test*`, `--mwp-self-test-*` | `.mmwp-self-test*`, `--mmwp-self-test-*` (no alias) |
| JS `mwpSelfTest`, `MwpSelfTestDashboard`, `window.mwpSelfTestDebug` | `mmwpSelfTest`, `MmwpSelfTestDashboard`, `window.mmwpSelfTestDebug` (no alias) |
| Env `MWP_PLUGIN_FILES`, `MWP_SELF_TEST_BOOTSTRAP`, `MWP_SELF_TEST_AUTOLOAD` | `MMWP_*` (old names still read) |
| `Case_Base::OWNER` `mwp-self-test` | `mmwp-self-test` |

## [0.7.0] - 2026-10-12

Prompt: "in wp-extend can we use the plugin only in development environments and not in productions?" → "please commit to gnnpls-self test and then update the wp-extend also".

### Changed
- **Nothing boots on production.** `Loader::enabled()` (`mwp_self_test_enabled`) now defaults to `environment !== 'production'` (environment = `WP_ENV`, else `wp_get_environment_type()`, `production` when unset), so on production there are no `wp mwp self-test` commands, no `mwp-self-test/*` abilities, no dashboard and no manifests. Reverses the 0.6.0 default. Return `true` from `mwp_self_test_enabled` to smoke-test a production site.

### Added
- `Loader::GATES_PRODUCTION` and `Config::enabled()` / `Config::environment()`: when an older Loader (< 0.7.0, declared by another plugin's copy) boots this copy, `Self_Test::boot()` applies the same production gate itself, so the newest copy's rule wins regardless of load order.

### Backwards compatibility
- Breaking for anyone running self-tests on production (fleet or CI smoke tests): target staging, or add `add_filter('mwp_self_test_enabled', '__return_true');`. Sites with no `WP_ENV` / `WP_ENVIRONMENT_TYPE` count as production — set the variable on development and staging machines.

## [0.6.3] - 2026-10-12

Prompt: CI jobs failing (motivar.io 446911, onpelion.gr 446919) — "why this happened?" → "please update both repos now".

### Fixed
- Fatal `Call to undefined function Gnnpls\SelfTest\apply_filters()` on every WP-CLI command in Bedrock sites that require the package from the project root. Composer's `autoload.files` runs `bootstrap.php` from `wp-config.php`, where WP-CLI has already defined `ABSPATH` but `wp-includes/plugin.php` is not loaded yet; `Loader::register()` treated a missing `add_action()` as "past `plugins_loaded`" and booted immediately. It now pre-seeds `$wp_filter['plugins_loaded'][-100]` (the same mechanism as `WP_CLI::add_wp_hook()`), so boot happens on `plugins_loaded` as documented.

### Backwards compatibility
- No public API change. Boot timing changes only for the early case above, which previously fataled.

## [0.6.2] - 2026-10-12

### Added
- `CLAUDE.md` with repository guidance for Claude Code.
- `HOOKS.md`: every action and filter with its parameters and firing point.
- `LICENSE` (GPL-2.0, as declared in `composer.json`).
- `.gitattributes` keeping development-only files out of Composer dist archives.
- GitHub Actions workflow: PHP syntax check on 7.4 and 8.3 and `composer validate --strict`.

### Changed
- Package metadata: author Nikos Giannopoulos <nikos@gnnpls.com>, homepage https://gnnpls.com. Removed the remaining references to the previous vendor from code comments and docs. Hook, route, command, ability and option names (`mwp_*` / `mwp-self-test`) are unchanged.
- The package moved to GitHub (`gnnpls/wp-self-test`) and is installed from Packagist: consumers drop any `repositories` entry pointing at the old GitLab URL and run `composer update gnnpls/wp-self-test` (the history was rewritten, so commit hashes pinned in old lock files no longer exist).
- `composer.json`: `keywords`, `support` (issues, source, email); the description no longer mentions CI.

### Removed
- `.gitlab-ci.yml` (replaced by the GitHub Actions workflow).
- Tags `v0.4.0` and `v0.6.0`, which fatal on `plugins_loaded`; use `v0.4.1` / `v0.6.1` or later.
- `bin/install-hooks` no longer treats a pre-push shim carrying the pre-0.3.0 marker as its own; re-run `composer install` (or delete `.githooks/pre-push`) if such a shim is still present.

## [0.6.1] - 2026-09-14

### Fixed
- `Loader::environment()` was missing from the 0.6.0 tag (fatal on `plugins_loaded`). Require `>=0.6.1`.

## [0.6.0] - 2026-09-14

### Changed
- The environment now gates only the **UI**: `Config::ui_enabled()` (dashboard, its REST routes, the WP_CLI shim) defaults to `true` unless `Loader::environment()` is `production` — previously `WP_DEBUG`. `mwp_self_test_ui_enabled` gains a second `$environment` argument.
- `Loader::enabled()` / `mwp_self_test_enabled` default to `true` regardless of environment again: manifests, `wp mwp self-test` and the `mwp-self-test/*` abilities are registered on production too (they were in 0.3 and earlier; 0.4–0.5 hid them there). `Loader::environment()` added.

## [0.5.0] - 2026-09-14

### Removed
- **`ci/gitlab-ci.yml`** and the CI section of the README. The pre-push hook (`bin/pre-push` → `composer run-script test`) is the gate; a consumer that included the template must drop the `include:` line (GitLab fails the pipeline on a missing local include).

## [0.4.1] - 2026-09-14

### Fixed
- `Loader::enabled()` was missing from the 0.4.0 tag (its `boot()` called it and fataled on `plugins_loaded`). 0.4.0 is unusable; require `>=0.4.1`.

## [0.4.0] - 2026-09-14

### Added
- `Loader::enabled()` and the `mwp_self_test_enabled` filter `(bool $enabled, string $environment)`: the package now boots only when it returns true. Default: `true` unless the environment is `production`, where the environment is `WP_ENV` when defined and `wp_get_environment_type()` otherwise. Evaluated once on `plugins_loaded` (-100), so a plugin adds the filter at load time (e.g. return `false` on front-end requests, or `true` to allow runs on a production site). When disabled nothing is autoloaded and no surface, command, route or ability is registered; `mwp_self_test_booted` does not fire.

### Changed
- **Breaking for production sites**: a site whose environment type is `production` (WordPress' default when `WP_ENVIRONMENT_TYPE`/`WP_ENV` is unset) no longer gets the `wp mwp self-test` commands, the `mwp-self-test/*` abilities or the dashboard unless `mwp_self_test_enabled` returns true. Set `WP_ENVIRONMENT_TYPE` to `development`/`staging`/`local` on non-production sites (the PHPUnit config of a consumer must do the same, or add the filter in its bootstrap).

## [0.3.0] - 2026-09-14

### Changed
- **Renamed**: the Composer package is `gnnpls/wp-self-test` and the PHP namespace `Gnnpls\SelfTest`. Hook names (`mwp_self_test_*`), the `wp mwp self-test` commands, the `mwp-self-test/v1` routes, the `mwp-self-test/*` abilities and the `mwp_self_test_state` option are unchanged. Consumers update their `require`, their `use` statements and the `lib/gnnpls/wp-self-test` paths in `post-install-cmd`, `.gitlab-ci.yml` and test bootstraps.

## [0.2.0] - 2026-09-14

### Added
- Dashboard filters: a **Plugin** select (one option per registered manifest) and a **Surface** select (REST / WP-CLI / Abilities). They hide cases outside the selection and keep hidden cases out of the run; the surface filter also narrows the check rows shown in results, with a note counting the hidden ones. Rows carry `data-plugin` and `data-layers`, check rows `data-check-layer`.

## [0.1.0] - 2026-09-14

### Added
- First release: a standalone package so any plugin can ship a self-test suite.
- `Registry`: several plugins register a manifest each (`mwp_self_test_register`), with optional `requirements` vocabulary and `cli_loaders`; case ids are validated for collisions across plugins.
- `Runner` with `cases()`, `preview()`, `run()`, `cleanup()`, `report()`, each accepting a plugin filter; one state option shared by every surface.
- `Case_Base` with the `rest()`, `cli()`, `ability()` helpers and the assertion helpers.
- Surfaces: Tools › Self-test dashboard, `mwp-self-test/v1` REST routes, `wp mwp self-test` commands, `mwp-self-test/*` abilities.
- `bin/run.php`, `bin/pre-push`, `bin/install-hooks`, `ci/gitlab-ci.yml`, `phpunit/bootstrap.php`.
- Version-gated `Loader` so several bundled copies coexist.
