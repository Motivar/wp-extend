# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- CI: include `publish.gitlab-ci.yml` from ci-templates (GitHub publishing) and run pipelines for `vX.Y.Z` tags.

## [2.0.2] - 2026-10-30

### Changed
- Docs cleanup: removed private references from the changelog and CI/export config.
- Removed `CLAUDE.md` from the repository (now git-ignored).

## [2.0.1] - 2026-10-30

### Changed
- Docs: removed private-host references and hosting wording from public files.
- CI: `check` job now uses the `composer:2` image (the custom registry image does not exist); pipelines run on merge requests and the `develop` and `main` branches, never on tag pipelines.
- Removed the GitHub Actions workflow (`.github/`); CI now runs the same checks (`php -l`, `composer validate`, PHPUnit, PHPCS, PHPStan) on merge requests and the default branch.

## [2.0.0] - 2026-10-20

### Removed
- Removed the 1.x compatibility layer for pre-release class and hook names.

### Changed
- Tests: replaced the compatibility-layer tests with a `Registry` hook test.

## [1.0.0] - 2026-10-20

Initial Mammoth WP release.

### Added
- Package `mammothwp/wp-kit`, PHP namespace root `MammothWP\WpKit\`, hooks `mmwp_kit_*`, WP_Error codes `mmwp_*`.
- `Resource`, `Operation`, `Field`, `Field_Map`: declare a feature once and project it onto REST, WP-CLI and the WordPress Abilities API.
- `Registry` and `Inventory` for parity tests and generated coverage lists.
- Version-gated loader so several bundled copies coexist.
- Dev tooling: PHPUnit, PHPCS (WPCS prefixes `mmwp` / `MammothWP`) and PHPStan, with `composer lint|test|phpcs|phpstan` scripts.
- `docs/BRAND.md`.

## [0.x]

0.x — pre-release development.
