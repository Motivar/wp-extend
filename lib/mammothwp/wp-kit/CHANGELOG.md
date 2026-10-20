# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-20

### Removed
- Removed the 1.x compatibility layer for pre-release class and hook names.

### Changed
- Tests: replaced the compatibility-layer tests with a `Registry` hook test.

## [Unreleased]

Prompt: CI must run on GitLab only, GitHub is just a push mirror.

### Changed
- CI: `check` job now uses the `composer:2` image (the custom registry image does not exist); pipelines run on merge requests and the `develop` and `main` branches, never on tags.
- Removed the GitHub Actions workflow (`.github/`); `.gitlab-ci.yml` now runs the same checks (`php -l`, `composer validate`, PHPUnit, PHPCS, PHPStan) on merge requests and the default branch.

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
