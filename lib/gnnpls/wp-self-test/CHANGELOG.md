# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- **Renamed**: the Composer package is `gnnpls/wp-self-test` and the PHP namespace `Gnnpls\SelfTest` (was `motivar/wp-self-test`, `Motivar\SelfTest`). Hook names (`mwp_self_test_*`), the `wp mwp self-test` commands, the `mwp-self-test/v1` routes, the `mwp-self-test/*` abilities and the `mwp_self_test_state` option are unchanged. Consumers update their `require`, their `use` statements and the `lib/gnnpls/wp-self-test` paths in `post-install-cmd`, `.gitlab-ci.yml` and test bootstraps; `bin/install-hooks` still recognises the shim it wrote under the old name.

## [0.2.0] - 2026-09-14

### Added
- Dashboard filters: a **Plugin** select (one option per registered manifest) and a **Surface** select (REST / WP-CLI / Abilities). They hide cases outside the selection and keep hidden cases out of the run; the surface filter also narrows the check rows shown in results, with a note counting the hidden ones. Rows carry `data-plugin` and `data-layers`, check rows `data-check-layer`.

## [0.1.0] - 2026-09-14

### Added
- First release, extracted from the `ewp-self-test` module of `motivar/extend-wp` 1.5.0 so any plugin can ship a self-test suite without depending on Extend WP.
- `Registry`: several plugins register a manifest each (`mwp_self_test_register`), with optional `requirements` vocabulary and `cli_loaders`; case ids are validated for collisions across plugins.
- `Runner` with `cases()`, `preview()`, `run()`, `cleanup()`, `report()`, each accepting a plugin filter; one state option shared by every surface.
- `Case_Base` with the `rest()`, `cli()`, `ability()` helpers and the assertion helpers.
- Surfaces: Tools › Self-test dashboard, `mwp-self-test/v1` REST routes, `wp mwp self-test` commands, `mwp-self-test/*` abilities.
- `bin/run.php`, `bin/pre-push`, `bin/install-hooks`, `ci/gitlab-ci.yml`, `phpunit/bootstrap.php`.
- Version-gated `Loader` so several bundled copies coexist.
