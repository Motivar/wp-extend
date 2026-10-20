# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-20

### Changed
- CI runs on GitLab only (`.gitlab-ci.yml`, one `check` job: `php -l`, `composer validate --strict`, PHPUnit, PHPCS, PHPStan) on the public `composer:2` image; pipelines run for merge requests and the `develop` and `main` branches, never tags.

### Removed
- Removed the 1.x compatibility layer for pre-release names.

## [1.0.0] - 2026-10-20

Initial Mammoth WP release. Source of truth is GitLab `mammothwp/wp-self-test`; `github.com/mammothwp/wp-self-test` is a push mirror.

### Added
- Manifest-driven self-test suite for WordPress plugins: `Registry`, `Runner` and `Case_Base` with `rest()`, `cli()`, `ability()` helpers and assertion helpers.
- Surfaces: Tools › Self-test dashboard, REST routes under `mmwp/v1/self-test/*`, `wp mmwp self-test` commands (`wp mmwp self-test <slug> --format=table` runs one plugin's cases), abilities `mmwp/self-test-*`.
- Production gate: nothing boots on `production` unless `mmwp_self_test_enabled` returns true.
- Version-gated `Loader` so several bundled copies coexist.
- `bin/run.php`, `bin/pre-push`, `bin/install-hooks`, `phpunit/bootstrap.php`.
- Dev tooling: PHPUnit suite with a WordPress stub bootstrap (`tests/`), PHPCS (WPCS, `PrefixAllGlobals` = `mmwp`/`MammothWP`) and PHPStan.
- README, `HOOKS.md` and brand guidelines in `docs/BRAND.md`.

## [0.x]

0.x — pre-release development.
