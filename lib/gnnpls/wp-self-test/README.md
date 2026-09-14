# wp-self-test

A manifest-driven self-test suite for WordPress plugins. A plugin ships a
`manifest.json` and a few case classes in its own folder; the package runs
them on a real site with removable test data and exposes the same runner
on five surfaces:

| Surface | Where |
|---|---|
| wp-admin | Tools › Self-test (only when `WP_DEBUG` is on) |
| REST | `mwp-self-test/v1/{cases,preview,run,cleanup,report}` (same gate) |
| WP-CLI | `wp mwp self-test list\|preview\|run\|cleanup\|report` |
| Abilities API | `mwp-self-test/{list-cases,preview,run,cleanup,get-report}` (WordPress 6.9+) |
| Pre-push hook | `bin/pre-push`, `bin/run.php` |

Several plugins can register manifests; the dashboard, commands and report
show every case with the plugin it belongs to, and the dashboard can be
filtered by plugin and by surface (REST / WP-CLI / Abilities).

## Install in a plugin

```bash
composer require gnnpls/wp-self-test
```

The package is a runtime dependency because the dashboard, the command and
the abilities execute inside WordPress. Nothing is exposed on a production
site: the package does not boot at all when the environment (`WP_ENV` if
defined, else `wp_get_environment_type()`) is `production` (filter
`mwp_self_test_enabled`), the UI, its REST routes and the WP_CLI shim are
off unless `WP_DEBUG` is on (filter `mwp_self_test_ui_enabled`), and every
surface requires `manage_options` (filter `mwp_self_test_capability`).

Composer's `autoload.files` loads `bootstrap.php`, which registers this
copy of the package. When several active plugins bundle copies, the newest
boots and the rest stay dormant, so committing your vendor directory is
safe.

## Register a manifest

```php
add_action('mwp_self_test_register', function (\Gnnpls\SelfTest\Registry $registry) {
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
use Gnnpls\SelfTest\Case_Base;

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
        "post-install-cmd": ["@php lib/gnnpls/wp-self-test/bin/install-hooks"]
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
`phpunit/bootstrap.php` with `MWP_PLUGIN_FILES=/path/to/plugin.php`.

## Filters and actions

| Hook | Kind | Signature |
|---|---|---|
| `mwp_self_test_register` | action | `(Registry $registry)` — fired at `init` 0 |
| `mwp_self_test_booted` | action | `(string $path, string $version)` |
| `mwp_self_test_completed` | action | `(array $report)` |
| `mwp_self_test_manifest` | filter | `(array $manifest, string $path, string $plugin)` |
| `mwp_self_test_capability` | filter | `(string $capability)` default `manage_options` |
| `mwp_self_test_enabled` | filter | `(bool $enabled, string $environment)` — whether the package boots at all on this request; default `true` unless the environment (`WP_ENV` if defined, else `wp_get_environment_type()`) is `production`. Return `false` on e.g. front-end requests, `true` to allow runs on a production site. |
| `mwp_self_test_ui_enabled` | filter | `(bool $enabled)` default `WP_DEBUG` |
| `mwp_self_test_shim_enabled` | filter | `(bool $enabled)` default: UI enabled |
| `mwp_self_test_abilities_available` | filter | `(bool $available)` |
| `mwp_self_test_ability_definitions` | filter | `(array $definitions)` |
| `mwp_self_test_asset_url` | filter | `(string $url, string $relative)` |

State (last report, contexts awaiting cleanup) lives in the
`mwp_self_test_state` option.
