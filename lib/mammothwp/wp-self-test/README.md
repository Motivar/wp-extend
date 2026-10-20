# Mammoth WP Self-Test

A manifest-driven self-test suite for WordPress plugins. A plugin ships a
`manifest.json` and a few case classes in its own folder; the package runs
them on a real site with removable test data and exposes the same runner
on five surfaces:

| Surface | Where |
|---|---|
| wp-admin | Tools › Self-test (only when the environment is not `production`) |
| REST | `mmwp/v1/self-test/{cases,preview,run,cleanup,report}` (same gate) |
| WP-CLI | `wp mmwp self-test [<slug>]` and `wp mmwp self-test list\|preview\|run\|cleanup\|report\|migrate` |
| Abilities API | `mmwp/self-test-{list-cases,preview,run,cleanup,get-report}` (WP 6.9+) |
| Pre-push hook | `bin/pre-push`, `bin/run.php` |

Several plugins can register manifests; the dashboard, commands and report
show every case with the plugin it belongs to, and the dashboard can be
filtered by plugin and by surface (REST / WP-CLI / Abilities).

## Install in a plugin

```bash
composer require mammothwp/wp-self-test
```

Source of truth: GitLab `mammothwp/wp-self-test`; `github.com/mammothwp/wp-self-test`
is a push mirror (issues and Packagist point there).

The package is a runtime dependency because the dashboard, the command and
the abilities execute inside WordPress. Nothing boots when the environment
(`WP_ENV` if defined, else `wp_get_environment_type()`, i.e. the
`WP_ENVIRONMENT_TYPE` environment variable; `production` when unset) is
`production`, because a run creates data on the site: no commands,
abilities, dashboard or manifests. `mmwp_self_test_enabled` overrides that
per request — return `true` to smoke-test a production site, or `false` to
skip e.g. front-end requests. Elsewhere the commands and abilities always
register, the dashboard, its REST routes and the WP_CLI shim follow
`mmwp_self_test_ui_enabled` (also off on `production`), and every surface
requires `manage_options` (filter `mmwp_self_test_capability`).

Composer's `autoload.files` loads `bootstrap.php`, which registers this
copy of the package. When several active plugins bundle copies, the newest
boots and the rest stay dormant, so committing your vendor directory is
safe.

## Running it

| From | Command |
|---|---|
| wp-admin | Tools › Self-test (`?page=mmwp-self-test`), non-production only |
| WP-CLI | `wp mmwp self-test <slug> --format=table` runs one plugin's cases; without `<slug>` it runs every case. Actions: `list`, `preview`, `run [--cleanup]`, `cleanup`, `report`, `migrate [--dry-run]`. Exits 1 when a case fails. |
| REST | `GET mmwp/v1/self-test/cases`, `GET .../preview`, `POST .../run`, `POST .../cleanup`, `GET .../report` (cookie + `X-WP-Nonce`, `manage_options`) |
| Abilities | `mmwp/self-test-list-cases`, `-preview`, `-run`, `-cleanup`, `-get-report`; run and cleanup need `confirm: true` |
| Pre-push | `bin/pre-push` via `bin/install-hooks`, or `php bin/run.php` (see below) |

`<slug>` is the plugin slug passed to `Registry::register()`. A plugin whose
slug equals an action name (`list`, `run`, ...) has to use `--plugin=<slug>`.

## Register a manifest

```php
add_action('mmwp_self_test_register', function (\MammothWP\SelfTest\Registry $registry) {
    $registry->register('my-plugin', __DIR__ . '/self-test/manifest.json', [
        'label'        => 'My Plugin',
        'requirements' => ['abilities', 'logger'],            // optional: valid `requires` keys
        'cli_loaders'  => [['My_Plugin_Cli_Shim', 'load']],   // optional, see "CLI wrappers"
    ]);
});
```

`self-test/manifest.json`:

```json
{
    "version": 1,
    "cases": [
        {
            "id": "content-crud",
            "label": "Custom content CRUD",
            "category": "content",
            "layers": ["rest", "cli", "ability"],
            "class": "My\\Plugin\\SelfTest\\Content_Crud_Case",
            "requires": [],
            "args": {},
            "covers": {
                "rest": ["my-plugin/v1/items", "my-plugin/v1/items/{id}"],
                "cli": ["my-plugin items list"],
                "ability": ["my-plugin/list-items"]
            }
        }
    ]
}
```

Case ids must be unique across every registered plugin. `covers` is
documentation the package carries along (your own tests can assert every
route, command and ability you register is claimed by a case).

## Write a case

```php
use MammothWP\SelfTest\Case_Base;

class Content_Crud_Case extends Case_Base
{
    public function preview()  { return ['Create an item over REST', 'Read it back over the CLI', '…']; }

    public function run()
    {
        $o = [];
        $o['create'] = $this->rest('POST', '/my-plugin/v1/items', ['title' => 'Self-test']);
        $o['cli']    = $this->cli(['My_Cli', 'get'], [$o['create']['data']['id']], []);
        $o['get']    = $this->ability('my-plugin/get-item', ['id' => $o['create']['data']['id']]);
        return ['observed' => $o, 'id' => $o['create']['data']['id']];
    }

    public function validate(array $context)
    {
        $o = $context['observed'];
        return [
            $this->check('rest', 'POST /items returns 201', $this->status($o, 'create') === 201, $this->detail($o, 'create')),
            $this->cli_check($o, 'cli', 'wp my-plugin items get prints the item'),
            $this->ability_check($o, 'get', 'my-plugin/get-item returns the item', fn($data) => !empty($data['id'])),
        ];
    }

    public function cleanup(array $context)
    {
        delete_item((int) $context['id']);
        return ['Deleted the test item.'];
    }
}
```

Four phases, each callable on its own: `preview()` (no side effects),
`run()` (returns a JSON-serialisable context, may create data),
`validate($context)` (turns observations into `check()` / `skip()` rows),
`cleanup($context)` (removes what `run()` created; the runner stores the
context so cleanup can happen in a later request). Helpers: `rest()`,
`cli()`, `ability()`, `status()`, `detail()`, `cli_check()`,
`cli_check_printed()`, `ability_check()`, `availability()`.

## CLI wrappers

Cases call CLI command handlers in-process. In a real `wp` process that is
WP-CLI itself; in PHPUnit, the dashboard and `bin/run.php` it is the shim
in `src/Wp_Cli_Shim.php`, a minimal `WP_CLI` that records output and
throws instead of exiting. Command classes that `return` early when
`WP_CLI` is missing are declared again by the `cli_loaders` callables a
plugin passes to `register()`; the runner calls them once before a run.

## The pre-push hook in a consumer

```json
{
    "scripts": {
        "test": "bash tests/run-tests.sh",
        "post-install-cmd": ["@php vendor/mammothwp/wp-self-test/bin/install-hooks"]
    }
}
```

`install-hooks` writes `.githooks/pre-push` (a two-line shim that execs the
package's `bin/pre-push`), makes it executable and sets
`core.hooksPath`. On `git push` the hook skips tag-only and delete pushes,
`php -l`s every tracked PHP file, then runs `composer run-script test`
(or `tests/pre-push.sh`). A push that passes the hook is the gate; the
package ships no CI template (`git push --no-verify` bypasses the hook, so
treat that as a deliberate decision).

`bin/run.php` boots WordPress through your PHPUnit bootstrap
(`--bootstrap=tests/bootstrap.php`, `--autoload=tests/vendor/autoload.php`)
and runs every manifest with cleanup, exiting 1 on failure. Plugins without
a bootstrap can point `phpunit.xml` and `bin/run.php` at
`phpunit/bootstrap.php` with `MMWP_PLUGIN_FILES=/path/to/plugin.php`.

## Filters and actions

| Hook | Kind | Signature |
|---|---|---|
| `mmwp_self_test_register` | action | `(Registry $registry)` — fired at `init` 0 |
| `mmwp_self_test_booted` | action | `(string $path, string $version)` |
| `mmwp_self_test_completed` | action | `(array $report)` |
| `mmwp_self_test_manifest` | filter | `(array $manifest, string $path, string $plugin)` |
| `mmwp_self_test_capability` | filter | `(string $capability)` default `manage_options` |
| `mmwp_self_test_enabled` | filter | `(bool $enabled, string $environment)` — whether the package boots at all on this request; default `true` unless the environment is `production` (since 0.7.0). Return `false` on e.g. front-end requests, `true` to run on production. |
| `mmwp_self_test_ui_enabled` | filter | `(bool $enabled, string $environment)` — dashboard, its REST routes and the shim; default `true` unless the environment (`WP_ENV` if defined, else `wp_get_environment_type()`) is `production`. |
| `mmwp_self_test_shim_enabled` | filter | `(bool $enabled)` default: UI enabled |
| `mmwp_self_test_abilities_available` | filter | `(bool $available)` |
| `mmwp_self_test_ability_definitions` | filter | `(array $definitions)` |
| `mmwp_self_test_asset_url` | filter | `(string $url, string $relative)` |

State (last report, contexts awaiting cleanup) lives in the
`mmwp_self_test_state` option.

The same names with the `mwp_` prefix still fire for one major version; see
[HOOKS.md](HOOKS.md#deprecated-hook-names-pre-10).

## Upgrading from gnnpls/wp-self-test

1.0.0 is the Mammoth WP rebrand. Nothing you wrote has to change on day one:

- `composer.json` declares `replace: gnnpls/wp-self-test`, so Composer will not
  install both. Swap the requirement for `mammothwp/wp-self-test`.
- `Gnnpls\SelfTest\*` class names are `class_alias()`ed to
  `MammothWP\SelfTest\*` (see `compat.php`), so existing `extends Case_Base`
  keeps working. Each old class name is reported once per site, as an
  `E_USER_DEPRECATED` notice when `WP_DEBUG` is on.
- `mwp_self_test_*` hooks still fire after their `mmwp_` replacements, with
  WordPress's usual deprecation notice when something is attached.
- `wp mwp self-test <action>` still works, prints a deprecation warning and
  forwards. The `mwp-self-test/v1` REST routes and `mwp-self-test/*` abilities
  stay registered as deprecated aliases.
- The `mwp_self_test_state` option is moved to `mmwp_self_test_state` once, on
  the first request after the upgrade. Check first with
  `wp mmwp self-test migrate --dry-run`. Until then the old option is read as a
  fallback.
- `MWP_PLUGIN_FILES` and `MWP_SELF_TEST_BOOTSTRAP` / `_AUTOLOAD` are still read.
- Re-run `bin/install-hooks`; it replaces hooks written by the old package.

Not aliased: the dashboard page slug (`mwp-self-test` is now `mmwp-self-test`),
its CSS classes and JS globals. If another active plugin still bundles an old
`gnnpls/wp-self-test` copy, both copies boot side by side until that plugin is
updated; the deprecated CLI alias and REST routes are skipped in that case so
the old copy keeps ownership of them.

Every renamed identifier is listed in the [CHANGELOG](CHANGELOG.md#100---2026-10-20).

## Brand

Naming, identifiers and the (deliberately small) visual rules live in
[docs/BRAND.md](docs/BRAND.md).
