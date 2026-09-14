# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-14

### Added
- First release as a standalone package, extracted from `includes/kit/` of `motivar/extend-wp` 1.5.0 with its history. Namespace `Gnnpls\WP`, Composer name `gnnpls/wp-kit`.
- `Resource`, `Operation`, `Field`, `Field_Map`: declare a feature once and project it onto REST (`Rest_Adapter`), WP-CLI (`Cli_Adapter`) and the WordPress Abilities API (`Ability_Adapter`).
- `Operation::run()`: the single execution path (normalise, validate, authorise, confirm, hooks, service call).
- `Registry` and `Inventory` for parity tests and generated coverage lists.
- Version-gated `Loader` so several bundled copies coexist.
