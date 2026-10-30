# WordPress.org preparation — review report (`wporg` branch)

For: Nikos and his review agent. Kept up to date with every push to `wporg`.
Last update: 2026-10-30, after commit `4e31f06e`.

**Nothing on this branch is pushed to `main` until Nikos approves.**

## What this branch is

Extend WP is to be published on WordPress.org as its **own free plugin** (slug
`extend-wp`: free on WordPress.org on 2026-10-30, equal to the text domain; "wp" in
the name / slug is only a Plugin Check warning). Filox Core will be published free
too and will use it. Filox's `wporg` branch bundles this branch
(`motivar/extend-wp: dev-wporg`); see Filox's `docs/wporg-review.md`.

- `wporg` is branched from `main` (fbf588bc, 1.13.0) and only receives `main` merges.
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

## `awm_show_content()` escaping (please review)

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

- Self-test (`wp mmwp self-test run --plugin=extend-wp`): **14 passed, 1 skipped**
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
| Remote scripts (Swagger UI from unpkg) | 2 | 0 (`examples/` still has CDN samples: leave `examples/` out of the WordPress.org package) |
| `date()` | 9 | 0 |
| Late escaping (core: `includes`, `templates`, main file) | 105 | lower (re-count pending); `awm_show_content()` callers marked |
| i18n (translators comments, non-literal strings, text domain) | ~220 | open |
| File-system calls (`fopen`, `fwrite`, `unlink`, …) | ~40 | open |
| `lib/mammothwp/wp-self-test` | 122 errors | candidate to leave out of the WordPress.org package (off on production) |
| `readme.txt` | missing | open |

## Reviewer checklist

- [ ] `wp_kses_post()` on labels: any label in your sites that needs `<script>`,
      `on*=` or form tags inside the label itself?
- [ ] `html` / `message` / `function` cases left as developer markup — agree?
- [ ] Dropping the second `name` (4e31f06e): any code of yours that relied on `attributes['name']` taking effect? (It never did in browsers.)
- [ ] Multi-value fields out of free-text search (00af2c72)
- [ ] Swagger UI version pin (5.34.0) and package size
- [ ] `content-crud` check reading the error instead of `ok` (d798ea35)
