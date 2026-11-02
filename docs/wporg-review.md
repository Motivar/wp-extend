# WordPress.org preparation — review report (`wporg` branch)

For: Nikos and his review agent. Kept up to date with every push to `wporg`.
Last update: 2026-11-02, after commit `f67dd2f2` (main caf71b79 merged in).

**Nothing on this branch is pushed to `main` until Nikos approves.**

## What this branch is

Extend WP is to be published on WordPress.org as its **own free plugin** (slug
`extend-wp`: free on WordPress.org on 2026-10-30, equal to the text domain; "wp" in
the name / slug is only a Plugin Check warning). Filox Core will be published free
too and will use it. Filox's `wporg` branch bundles this branch
(`motivar/extend-wp: dev-wporg`); see Filox's `docs/wporg-review.md`.

- `wporg` is branched from `main` (fbf588bc, 1.13.0) and only receives `main` merges.
  **2026-11-01: `main` 75303d0b (1.14.0, 20 commits: field engine, templates, PHP export /
  config sync, REST Health removed) is merged in (8c2e59f8)**, so merging `wporg` into `main`
  is a fast-forward again.
  **2026-11-02: `main` caf71b79 (4 commits: per-object JSON export as zip, config sync
  logging, dead REST Health setting) is merged in (34577c7a), no conflicts.**
  **Nothing here is merged into `main` until you review it.**
- Changes are meant to be behaviour-neutral for every site using Extend WP.
- Every commit has a `CHANGELOG.md` entry under `[Unreleased]` → `### Changed`.
- Plugin Check was run on a clean copy of this branch as plugin `extend-wp`
  (inactive copy on filox-test): **486 errors, 860 warnings** at the start (the
  1,686 seen when checked inside Filox were mostly text-domain noise).

## Commits

| Commit | What | Risk | How it was checked |
|---|---|---|---|
| f6cba74f | `License: GPLv2 or later` + `License URI` | None | — |
| 058b409a | REST API Health: Swagger UI 5.34.0 (what `unpkg …@5` resolved to) in `assets/third-party/swagger-ui/` (Apache-2.0, LICENSE / NOTICE kept). Not `assets/vendor/`: `.gitignore` ignores every `vendor/` | +1.9 MB in the package (~450 KB gzipped) | REST API Health (REST API logs on): both files 200 from the plugin, no unpkg request, 115 Filox operations drawn, no JS errors |
| bfca7992 | `date()` → `awm_date()` (9 calls; `includes/functions/library.php`): `DateTime` in PHP's default timezone = `date()`. Not `gmdate()`: the calls are paired with `strtotime()` | None | 2,090 comparisons in 5 timezones: 0 differences |
| e6893b48 | **`awm_show_content()`, `awm_repeater_content()`, `templates/frontend/inputs/checkbox_multiple.php` escape the values they put into the markup** | Same markup on 54 pages. A label carrying `<script>` / `on*=` now loses it | 54 pages before vs after: 0 differences (below) |
| e4a11b2c | `phpcs:ignore … -- escaped inside awm_show_content().` on the 5 `echo awm_show_content()` sites in Extend WP | None (comments) | — |
| c12bdb1d | This report | — | — |
| 00af2c72 | **Fix:** multi-value fields (`checkbox_multiple`, `map`, `awm_gallery`, `select` with `multiple`) are no longer free-text searchable. Their values are stored serialized (`s:30:"a:2:{i:0;…`), so searching a number LIKE-matched the serialization (root cause of `ViewQueryTest::test_search`, which failed or passed depending on the ids) | A search no longer matches inside serialized multi-value meta; title, text meta and the numeric id still do | PHPUnit 408 / 408 |
| d798ea35 | **Fix (self-test):** `content-crud` judged the CLI refusal by `ok: false`; under real WP-CLI `WP_CLI::error()` goes to stderr without throwing, so `ok` stays true. The command was refused correctly; the check now reads the error | Test only | Self-test 14 passed, 1 skipped |
| 4e31f06e | **Fix:** `input` / `select` / `textarea` / `checkbox_multiple` no longer print a second `name` from `attributes['name']` (they print their own first; browsers and kses use the first, the second was invalid HTML) | None for browsers: submitted names, `element.name` and `getAttribute('name')` unchanged | Rates calendar HTML differs only by the dropped second `name`; 54 pages otherwise identical |
| 41f93239 | Correction of the duplicate-`name` claim (kses keeps the first attribute, like browsers) | — | Tested with `wp_kses()` directly |
| 58fa9768 | **Escaping in Extend WP's own code: late escaping 99 → 0** (`tools/` excluded). Real fix: the custom list screen echoed `$_REQUEST['page']` raw (reflected XSS on an admin page). `awm_show_explanation()` / `awm_show_featured_image()` escape inside; `awm_show_content()` / template output marked; query-class exception messages marked (they are REST / CLI error text) | Same markup on every page checked | Booking flow, admin pages, 54 pages back to back; self-test 14 + 1 skipped; PHPUnit 408 / 408 |
| ddfff20c | **i18n errors → 0**: 96 translator comments, 11 numbered placeholders (+ the import JS that fills two of them), `extend-wp` domain for `awm` / `ewp` / `wp-extend` / `custom_table_example` / literal `filox` strings, 39 dynamic labels marked; 26 approved Greek strings | Greek admin strings now translated; English unchanged | Pages identical; self-test 14 + 1; PHPUnit 408 / 408 |
| 950e8500 | `readme.txt` (Contributors brut3forc3, gnnpls; external services: Google Maps only with an API key); `Requires at least` 6.2 → 6.8 (DataViews lists need 6.8) | Header only checked on activation | Plugin Check readme / header / compatibility: 0 |
| d45ea5a1 | `tools/build-wporg.sh`: WordPress.org package from `HEAD` without tests, tools, docs, examples, `lib/bin`, notes (`HOOKS.md` stays), configs; 1.4 MB | None (not loaded by WordPress) | Package installed as `extend-wp` on filox-test with the Filox WordPress.org package: front / admin pages without errors or notices, Filox self-tests 13 passed |
| 0da91881 | Plugin Check on the package → 0 in Extend WP's own code: `wp_delete_file()`, `wp_is_writable()`, `wp_rand()`, `wp_strip_all_tags()`; streaming / locked file writes marked; dynamic loader waits until an injected dependency has loaded (`data-ewp-loading`) | Textareas with `awm_strip_html` also trimmed; dependent dynamic assets wait for their dependency to load | PHPUnit 408 / 408; Filox date picker with WordPress's moment.js through the loader, also with moment delayed 2 s; package smoke test |
| 643ddf5a | **`tools/build-wporg.sh`: package without `mammothwp/wp-self-test`; Composer's autoloader patched for Plugin Check.** The autoloader is regenerated offline without wp-self-test (`dump-autoload --no-dev --optimize`, entries removed from `installed.json` / `installed.php`, no `platform_check.php`); then direct-access guards, no PHP < 5.6 message, `esc_html()` in `InstalledVersions`' exceptions (same step as Filox). `wp-kit` stays: REST, WP-CLI and abilities are built on it. **Plugin Check on the package 18 → 1** (now counted with `wp-kit`) | None (composer installs untouched); WordPress.org sites have no self-tests | Packages installed in place of Filox on filox-test: activate, no PHP errors, 67 Extend WP routes, product page identical apart from Extend WP's asset URLs |
| 8c2e59f8 | **Merge `main` (1.14.0) into `wporg`.** 15 conflicts. Main wins where it moved markup into templates (settings, term / user / post boxes, list table and form, search form, slug field, field engine, object search, import/export → `Config_Sync`); its own code already has the import guard (administrators only, no ajax, no flush after a failed import). Kept from wporg: `Requires at least: 6.8`, `awm_date()`, `awm_show_explanation()` escaping, `esc_html()` in `ewp_debug()`, translator comments. REST Health deleted as on main, with wporg's Swagger UI copy (`assets/third-party/`) and its readme line | Main's changes are yours; the merge adds none of its own | Trial merge first (`git merge-tree`); every conflict file compared with main after resolving (`class-import-export.php` identical) |
| 40d68d93 | **Plugin Check on main's new code (package: 50 → 1)**: field templates mark prints escaped where they are built (`Field_Attributes::render()`, `Select_Field::data_html()`, the explanation template, wrapper attributes) or escape at the print (`map.php` id, search form id as int); 5 translator comments; dynamic labels marked; `wp_rand()`, `wp_strip_all_tags()` (`awm_strip_html` textareas), `wp_is_writable()`, `awm_date()`, `wp_delete_file()` in `Config_Sync` and two self-test cases; two atomic `rename()` and one test `rmdir()` kept with the reason; Stable tag 1.14.0. **4e31f06e restored in the field engine**: `Field_Renderer::field_row()` drops `attributes['name']` for input / select / textarea / checkbox_multiple | Output unchanged: your render snapshots pass byte for byte. `show-when` stays unescaped (JSON with every `"` turned into `'`, so it cannot leave the attribute) to keep that | PHPUnit 557 / 557, new `test_attributes_name_is_not_printed_twice` fails without the fix; package Plugin Check; merged Extend WP inside Filox on filox-test: self-tests, 6 front-end pages identical (random ids / whitespace masked), 9 admin pages with the same form fields |
| 34577c7a | **Merge `main` caf71b79 into `wporg`** (4 commits). No conflicts | Main's changes are yours; the merge adds none of its own | — |
| f67dd2f2 | **Plugin Check on main's portability code (package: 5 → 1)**: translator comment on `Failed to write to %s.` (`present_files_export()`); `unlink()` → `wp_delete_file()` in `Content_Portability_Case::remove_files_dir()`, its two `rmdir()` marked as in `Config_Sync_Case` | None (comment; self-test cleanup) | PHPCS (prepared SQL) clean; PHPUnit 559 / 559; self-test 15 passed, 0 skipped; package Plugin Check |

## `awm_show_content()` escaping (please review)

*Since the 1.14.0 merge* `awm_show_content()` is your `Field_Renderer` and `templates/fields/*`;
they escape these values themselves, so the section below describes the pre-merge code.
What wporg added on top is in 40d68d93.

Why here: Filox could not escape the result from outside. `wp_kses()` over it dropped
attributes Extend WP's JS reads (`count`, `options`, `awm-skip-selectr`, `exclude_meta`,
`telsettings`, …): field definitions can print any attribute, so no allow-list is safe.
*Correction:* earlier versions of this report, the e6893b48 / 4e31f06e CHANGELOG entries
and commit messages said kses also kept "the wrong" one of the duplicate `name`
attributes on Filox's rates calendar inputs. Tested since: kses keeps the **first**
duplicate, as browsers do; names were never affected. 4e31f06e (dropping the duplicate)
stands as an HTML-validity fix.

What changed, where each value is concatenated:

- `esc_attr()`: ids, names, classes, option / optgroup values, optgroup `options`,
  `data-html`, `data-extra`, `show-when`, `data-config`, `data-message`, wrapper
  `class` / `data-input` / `data-type` / `id`, repeater `data-id` / `maxrows` / row ids,
  map inputs, hidden `awm_custom_meta[]` values, `option['extra']` attribute pairs.
- `wp_kses_post()`: labels, explanations, after-messages, option labels (also the
  placeholder option), radio labels (after `awm_radio_value_label_filter`),
  section / tab / repeater titles, repeater row titles.
- `esc_html__()` / `esc_attr__()`: fixed strings (repeater actions, object filter
  texts, map placeholder); `absint()` for the field counter.
- **Unchanged by design** (comments added): `case 'html'` and `case 'message'`
  print `$a['value']` as developer-supplied markup; `case 'function'` prints the
  callback's own markup. `$extraa` was already built with `esc_attr()`.
- Already escaped before: input values, textareas, media / gallery, buttons, modal.

Verification (filox-test, Extend WP bundled in Filox, the two files overlaid, then
the real bundle after push): 54 pages that render Extend WP fields (all Filox /
Extend WP / FXA settings pages, rates pages, post / booking / term edit screens,
profile, front-end booking forms and search pages) plus REST HTML (rates calendar,
Bookings Dashboard, pace) fetched before and after and compared as parsed HTML
(tags, sorted decoded attributes, decoded text; random ids / nonces masked; two
baselines compare 0 first): **0 differences**. Filox Settings page 110 → 113 ms
(median of 5). Filox self-tests 13 / 13.

## Tests on this branch

- After the caf71b79 merge (f67dd2f2), copy on filox-test: PHPCS clean, PHPUnit **559 / 559**,
  self-test **15 passed, 0 failed, 0 skipped** (144 checks).
- After the 1.14.0 merge (40d68d93): self-test **15 passed, 0 skipped** (inside Filox on filox-test),
  PHPUnit **557 / 557** (main alone: 556 / 556; +1 for the restored duplicate-`name` fix).
  One deprecation notice in the self-test run comes from main: the field-builder `php_code`
  operation still calls `AWM_API::php_code()`, deprecated since 1.15.0.
- Before the merge: self-test (`wp mmwp self-test run --plugin=extend-wp`): **14 passed, 1 skipped**
  (before d798ea35: `content-crud` failed, also on unchanged `main`).
- PHPUnit (`tests/`, run on filox-test with a temporary copy + `wp_extend_tests`
  database): **408 / 408** (before 00af2c72: `Test_Content_View_Query::test_search`
  failed depending on the ids, also on unchanged `main`).
- The pre-push hook (`composer test`) needs a DDEV site around the checkout; on this
  machine the checkout is outside one, so both suites were run by hand as above.

## Where things stand (Plugin Check as plugin `extend-wp`)

| | Start | Now |
|---|---|---|
| Licence header | missing | done |
| Remote scripts (Swagger UI from unpkg) | 2 | 0: REST Health and its Swagger UI left with the 1.14.0 merge (`examples/` still has CDN samples and stays out of the package) |
| `date()` | 9 | 0 |
| Late escaping (core: `includes`, `templates`, main file) | 105 (99 on re-count) | **0** |
| i18n (translators comments, non-literal strings, text domain) | 171 | **0** (dynamic labels marked) |
| File-system calls (`fopen`, `fwrite`, `unlink`, …) | ~40 | **0** (`wp_delete_file()` / `wp_is_writable()`; appends, streams and locked rewrites marked with the reason) |
| `lib/mammothwp/wp-self-test` | 122 errors | left out of the WordPress.org package (643ddf5a) |
| `readme.txt` | missing | done |
| Package build | none | `tools/build-wporg.sh`. Plugin Check on the package (with `wp-kit`): **1 error**, in `lib/mammothwp/wp-kit/src/Adapters/Ability_Adapter.php:73`: `wp_register_ability_category()` is WordPress 6.9, Requires at least is 6.8. Fix upstream in wp-kit: `if (!function_exists('wp_register_ability_category')) { return; }` (the call already runs only on 6.9's hook) |

## Reviewer checklist

- [ ] `wp_kses_post()` on labels: any label in your sites that needs `<script>`,
      `on*=` or form tags inside the label itself?
- [ ] `html` / `message` / `function` cases left as developer markup — agree?
- [ ] Dropping the second `name` (4e31f06e): any code of yours that relied on `attributes['name']` taking effect? (It never did in browsers.)
- [ ] Multi-value fields out of free-text search (00af2c72)
- [ ] 1.14.0 merge (8c2e59f8): conflicts resolved to your code; check nothing of yours was lost
- [ ] `show-when` left unescaped in `Field_Renderer::field_row()` (safe: no `"` can occur) so your snapshots stay byte-identical — or prefer `esc_attr()` and update the snapshots?
- [ ] `Field_Renderer` drops `attributes['name']` for input / select / textarea / checkbox_multiple (40d68d93, as 4e31f06e did in the legacy code)
- [ ] Field-builder `php_code` operation still calls the deprecated `AWM_API::php_code()` (from main; FYI)
- [ ] Exception messages of `View_Query` / `Where` / `SQL` left unescaped (marked): they are API error text
- [ ] `class-wp-content.php` translates content-type labels with Filox's domain (`__($metas['singular'], 'filox')`, marked). These are labels admins type on the Content Types screen, translated through Filox's `.po`; switching the domain would drop those translations on live sites, so it stays (FYI).
- [ ] `content-crud` check reading the error instead of `ok` (d798ea35)
- [ ] Package leaves out `lib/mammothwp/wp-self-test` (643ddf5a); `wp-kit` must stay (runtime). Making wp-self-test a `require-dev` in `composer.json` would also remove self-tests from composer installs: your call.
- [ ] wp-kit `Ability_Adapter.php:73`: add the `function_exists('wp_register_ability_category')` guard upstream (last Plugin Check error)
- [ ] File-system calls kept on PHP file handles (logger appends / locks, streamed export, auto-save to a folder under `wp-content`; WP_Filesystem has no append, lock or stream): FYI, reasons in the code
- [ ] Dynamic loader `data-ewp-loading` wait (scripts and styles the loader injects)
- [ ] Composer's generated autoloader patched by the build (643ddf5a): agree with patching generated files in the package?
