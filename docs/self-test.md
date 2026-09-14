# Self-test suite

One manifest, one runner, four ways to drive it. The self-test suite exercises every REST route, WP-CLI command and Abilities API ability the plugin exposes, **on a real WordPress install**, with test data that is removed afterwards. It complements the PHPUnit suite (`tests/`), which covers the shared implementations in isolation.

## Single source of truth

`includes/classes/ewp-self-test/manifest.json` lists every case:

| key | meaning |
| --- | --- |
| `id` | case id, used by `--cases=`, `ids[]` and the dashboard |
| `label`, `category` | display |
| `layers` | surfaces the case drives: `rest`, `cli`, `ability` |
| `class` | a class extending `EWP\SelfTest\EWP_Self_Test_Case` |
| `requires` | site requirements: `abilities`, `logger`, `ai` (reported as *unavailable*, never as failures) |
| `args` | free-form config passed to the case |
| `covers` | the routes / commands / abilities the case exercises — the coverage matrix |

`tests/test-self-test-manifest-test.php` fails when a case class is missing, ids collide, or a registered `wp ewp …` command is not listed under `covers.cli`. **Add an entry whenever you add a REST route, CLI command or ability.**

Other plugins can append cases through the `ewp_self_test_manifest` filter.

## The four phases

Every case implements the same contract, and each phase can be driven on its own:

1. **preview()** – the steps `run()` will take. No side effects.
2. **run()** – performs the steps and returns a JSON-serialisable *context* (ids created, responses observed).
3. **validate(context)** – turns the context into `pass` / `fail` / `skip` checks, each tagged with the layer (`rest`, `cli`, `ability`, `core`, `ai`).
4. **cleanup(context)** – removes what `run()` created. The runner stores the context, so cleanup can happen in a later request ("Remove test data").

A case is `skipped` when its site requirement is missing (logger off, no Abilities API, no AI provider configured), `fail` when any check fails, `error` when it throws.

## Where it runs

| layer | how | database |
| --- | --- | --- |
| **wp-admin dashboard** — *Extend WP → Self test* | **Preview** the steps, or **Run + remove data**: runs the selected cases with cleanup and shows per-check validation, the summary and a *Download raw data (JSON)* link. Only registered when `WP_DEBUG` is on (`ewp_self_test_ui_enabled` filter); requires `manage_options` (`ewp_self_test_capability`). | the live site |
| **WP-CLI** — `wp ewp self-test list\|preview\|run\|cleanup\|report` | `run --cleanup` exits 1 on any failure. | the live site |
| **Abilities API** — `ewp-self-test/list-cases`, `preview`, `run`, `cleanup`, `get-report` | `run` and `cleanup` require `confirm: true`. | the live site |
| **pre-push hook** — `.githooks/pre-push` | runs `tests/self-test-runner.php` after PHPUnit | `wp_extend_tests` (isolated) |
| **GitLab CI** — `.gitlab-ci.yml` `phpunit` job | same script | throwaway `mariadb` service |

`tests/self-test-runner.php` boots WordPress through `tests/bootstrap.php` (same isolated database as PHPUnit) but *without* PHPUnit's per-test transaction wrapper, because the content case creates real tables and `WP_UnitTestCase` would silently turn them into temporary ones.

The REST routes live under `extend-wp/v1/self-test/{cases,preview,run,cleanup,report}`.

## Running the CLI layer outside `wp`

The plugin's CLI classes guard themselves with `class_exists('WP_CLI')`. In a web request or under PHPUnit that class does not exist, so `class-ewp-self-test-wp-cli-shim.php` defines a minimal recording `WP_CLI` (and `WP_CLI\Utils\format_items`) and reloads the command classes, letting the exact same handlers run in-process. Under a real `wp` process the runner swaps in WP-CLI's `Execution` logger to capture output instead. `tests/wp-cli-stub.php` just loads the shim.

## Cases

| id | what it proves |
| --- | --- |
| `content-crud` | a throwaway content type (real tables) goes through create → read → update → delete on REST, `wp ewp content` and `ewp-content/*`; cleanup drops the tables |
| `search-filters` | read surfaces on all layers, `ewp-filter/{id}` execution, and that no write routes exist (`writable => false`) |
| `cache-flush` | `wp ewp delete-cache` and `ewp-system/flush-cache` both go through `ewp_flush_cache()` (hooks fire) |
| `logger` | writes one entry, reads it back on REST / CLI / abilities, runs a no-op retention cleanup; cleanup deletes the `ewp-self-test` entries |
| `options-portability` | list / export / dry-run import on all layers — never writes an option |
| `ai-abilities` | every `ewp-*` category is registered with core; reports whether an AI provider is configured (skipped, not failed, when it is not; no paid completion is ever requested) |

## Adding a case

1. Create `includes/classes/ewp-self-test/cases/class-<name>-case.php` extending `EWP_Self_Test_Case`; implement `preview()`, `run()`, `validate()`, and `cleanup()` when `run()` creates anything. Use the helpers `rest()`, `cli()`, `ability()`, `check()`, `skip()`.
2. Require it in `class-ewp-self-test.php`.
3. Add the manifest entry, including `covers`.
4. Run `wp ewp self-test run --cases=<id> --cleanup` and the PHPUnit suite.

## Hooks

- `ewp_self_test_ui_enabled` (bool) — default `defined('WP_DEBUG') && WP_DEBUG`.
- `ewp_self_test_capability` (string) — default `manage_options`.
- `ewp_self_test_manifest` (array $manifest, string $path) — append or alter cases.
- `ewp_self_test_completed` (array $report) — fires after a run.

## Cases added in 1.5.0

- `content-portability`: exports a fixture row through REST, CLI and ability, deletes it and imports it back on each surface (upsert by hash).
- `object-search`: finds a private post by title through `GET /objects/search`, `wp ewp objects search` and `ewp-system/search-objects`.
- `rest-health`: the plugin appears in its own inventory on all three surfaces. On a test database with no active plugins it lists itself in `active_plugins` for the duration of the case and removes itself in cleanup; when the plugin runs bundled inside another plugin it skips with a reason.

Cases that need somewhere safe to write use the `Fixture_Content_Type` trait (`cases/trait-fixture-content-type.php`), and the assertion helpers `status()`, `detail()`, `cli_check()`, `cli_check_printed()` and `ability_check()` live on `EWP_Self_Test_Case`. `EWP\Surfaces\EWP_Surfaces::cli($resource, $operation)` returns an in-process callable for any kit command, so a case can run `wp ewp objects search` without a shim class per module.

## Coverage gates

`tests/test-self-test-manifest-test.php` fails when:

- a route, command or ability generated from the surfaces registry (`Motivar\WP\Inventory`) is not listed under some case's `covers`;
- a registered `wp ewp` command is uncovered;
- a REST route of this plugin is neither generated from a resource, covered by a case, nor explained in `includes/classes/ewp-self-test/rest-only.json`.

`tests/test-surface-parity-test.php` fails when a declared surface is not actually registered or an operation or field lacks a description. Together they replace hand-maintained lists as the definition of "in sync".

