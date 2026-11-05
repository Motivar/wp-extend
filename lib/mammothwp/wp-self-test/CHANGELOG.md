# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.5] - 2026-10-30

### Changed
- Documentation cleanup: removed internal request notes from the changelog and dropped `CLAUDE.md` from the repository (now git-ignored).

## [2.2.4] - 2026-10-29

### Fixed
- `mmwp-e2e pages`: a page whose document returns its expected `status` (e.g. 410, 404) no longer fails on Chrome's own "Failed to load resource: ... status of <status>" console message for that same document URL (final URL after redirects). Console errors for other URLs, or with a different status, still fail the page.

## [2.2.3] - 2026-10-29

### Changed
- `mmwp-e2e pages` and `crawl` use a desktop Chrome user agent derived from the bundled Chromium version (`Mozilla/5.0 (X11; Linux x86_64) ... Chrome/<version> Safari/537.36`) instead of Playwright's "HeadlessChrome" one, which bot and prefetch guards ignore. No `Sec-Purpose` or other header is added. The UA is printed in the run header.
- `--allow-console` regexes are matched against the full reported string (`<message> @ <url>`) as well as the message alone, with the same code in `crawl` and `pages`.
- A 4xx/5xx response for a non-document resource whose URL matches an `--allow-console` pattern no longer fails the page.

### Added
- `--user-agent=<ua>` (env `MMWP_E2E_USER_AGENT`) on `pages` and `crawl` overrides the user agent.

## [2.2.2] - 2026-10-29

### Added
- `mmwp-e2e pages --allow-requests=<re>` (repeatable, also `MMWP_E2E_ALLOW_REQUESTS`): ignores failed requests whose "STATUS URL" matches, exactly as in `crawl` (the offline CI site's blocked external hosts). The document's status, PHP errors and console errors are unaffected.

### Changed
- `mmwp-e2e pages` still exits 1 on an unknown option, but the message now names it and lists the valid options.

## [2.2.1] - 2026-10-28

### Added
- `mmwp-e2e pages`: optional page key `expect_text`, a plain substring (after `${VAR}` substitution) that must appear in `document.body.innerText`.

### Fixed
- A page with an expected error `status` (e.g. 410, 500) no longer also fails the generic failed-request check for its own document; other responses, PHP errors and console errors still fail it.

## [2.2.0] - 2026-10-28

### Added
- `mmwp-e2e pages --url --file --vars --user --password [--workers --timeout-ms --allow-console --json --junit]`: opens the pages of a `browser-pages.json` (version 1) in a fresh browser context each (`as: admin` reuses the login, `guest` has no cookies), applies the crawler's failure checks plus `expect`, `expect_url` and `status`, substitutes `${VAR}` from `--vars`, and reports name, area, as, final URL, duration and result as JSON and JUnit. `crawl` is unchanged.
- File validation: unknown keys warn, a missing `path` or `as` fails before the browser starts.
- Tests for parsing, substitution, validation and argument handling, plus a fixture `e2e/test/fixtures/browser-pages.json`; README section "Listed pages mode".

### Deployment note
- The ci-templates browser image packs this package from `main`; `image-browser` in `mammothwp/ci-templates` must be re-run after merge.

## [2.1.1] - 2026-10-23

### Fixed
- The Node side calls every REST route through `?rest_route=/mmwp/v1/self-test/...`, which works with any permalink setting. `--rest-url` still overrides the base.
- REST responses are checked (status, content-type) before parsing. A missing or non-JSON `admin-menu` route falls back to scraping `#adminmenu` instead of failing the run.
- Unusable responses are reported with URL, HTTP status and content-type; a token login against a site without the routes says so and points at password login.
- Password login only uses `/wp-login.php` and never touches a self-test REST route.

### Added
- Tests: `rest.js` unit tests, and fake-site integration tests for plain permalinks without self-test routes (HTML and JSON 404), plain permalinks with routes, and pretty permalinks with routes.

## [2.1.0] - 2026-10-23

### Added
- `browser` layer. A manifest case may declare `"browser": {"crawl": true, "specs": [...], "allow": {"console": [], "requests": [], "pages": []}, "timeout": 300}`; the case then lists, counts and filters the `browser` layer like `rest`/`cli`/`ability`/`core` (dashboard, `wp mmwp self-test list|run|report`, abilities). Existing manifests stay valid. `MammothWP\SelfTest\Browser_Case` is a ready-made class for browser-only cases.
- `--layer=<layer>` on `wp mmwp self-test run`, `bin/run.php`, the `layer` argument of `POST mmwp/v1/self-test/run` and of the `mmwp/self-test-run` ability. `--layer=browser` runs only the Playwright checks; any other layer skips them.
- `Browser\Browser_Runner` shells out to the Node runner only when Node and the installed e2e package are found next to the manifest; otherwise (and on production) the browser checks are reported as skipped with the reason, never as failures. A crashed runner without results is a failure.
- `GET mmwp/v1/self-test/admin-menu`: read-only inventory of the current user's admin menu and submenu URLs (same permission and `mmwp_self_test_ui_enabled` gating as the dashboard routes). Excludes action URLs (`action=`, nonces, `customize.php`, update/install screens, `post-new.php`, logout); extend or trim the list with the `mmwp_self_test_admin_menu_exclusions` filter.
- Passwordless browser login: `Browser\Login_Token` issues a hashed, single-use token (120 s) that `POST mmwp/v1/self-test/browser-login` exchanges once for auth cookies; the runner revokes every session it created after the run. Never issued or redeemed when the environment is `production`, whatever the filters return. Security model documented in the README.
- `mmwp-e2e crawl` CLI (`e2e/bin/cli.js`) so CI can run the crawler without the PHP runner against a throwaway WordPress: flags or `MMWP_E2E_*` environment variables for the URL, login token or admin user/password, `--only-plugin`, allow-lists, `--json` and `--junit` output. Password login is refused unless `MMWP_E2E_ALLOW_PASSWORD_LOGIN=1`. Without the package's `admin-menu` route the page list is scraped from the admin menu HTML.
- The crawler also opens the site front page once and fails on any response >= 500 and on uncaught page errors.
- `mmwp_self_test_browser_command` filter to change how the Node runner is started.
- Node package `@mammothwp/wp-self-test-e2e` at the repo root (`package.json` with a `files` whitelist, code in `e2e/`): Playwright config, the generic read-only admin crawler (HTTP 200, no PHP/WordPress error markup, no console errors, no failed wp-json/admin-ajax calls, per-plugin allow-lists), flow-test helpers (`openAdminPage`, `fillForm`, `submitForm`, `expectNotice`, `collector`, `expectNoBrowserErrors`) and JSON results folded into the normal report. Plugins install it from GitHub (`git+https://github.com/mammothwp/wp-self-test.git`); every Node file is `export-ignore`d so the Composer dist archive never contains it.
- `bin/pre-push` runs `wp mmwp self-test run --layer=browser` through DDEV (or `MMWP_SELF_TEST_BROWSER_CMD`) for plugins that depend on the e2e package; a stopped DDEV or missing Node warns and skips.
- CI `e2e` job: Node unit tests on saved HTML/console fixtures and the crawler against a fake site in Chromium.
- Example flow test in `docs/examples/coupon-flow.spec.js`.
- PHPUnit tests for browser manifest parsing, the menu inventory and the login token lifecycle.

## [2.0.0] - 2026-10-20

### Changed
- CI runs from `.gitlab-ci.yml` (one `check` job: `php -l`, `composer validate --strict`, PHPUnit, PHPCS, PHPStan) on the public `composer:2` image; pipelines run for merge requests and the `develop` and `main` branches, never tags.

### Removed
- Removed the 1.x compatibility layer for pre-release names.

## [1.0.0] - 2026-10-20

Initial Mammoth WP release.

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
