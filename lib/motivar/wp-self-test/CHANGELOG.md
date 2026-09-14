# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-14

### Added
- First release, extracted from the `ewp-self-test` module of `motivar/extend-wp` 1.5.0 so any plugin can ship a self-test suite without depending on Extend WP.
- `Registry`: several plugins register a manifest each (`mwp_self_test_register`), with optional `requirements` vocabulary and `cli_loaders`; case ids are validated for collisions across plugins.
- `Runner` with `cases()`, `preview()`, `run()`, `cleanup()`, `report()`, each accepting a plugin filter; one state option shared by every surface.
- `Case_Base` with the `rest()`, `cli()`, `ability()` helpers and the assertion helpers.
- Surfaces: Tools › Self-test dashboard, `mwp-self-test/v1` REST routes, `wp mwp self-test` commands, `mwp-self-test/*` abilities.
- `bin/run.php`, `bin/pre-push`, `bin/install-hooks`, `ci/gitlab-ci.yml`, `phpunit/bootstrap.php`.
- Version-gated `Loader` so several bundled copies coexist.
