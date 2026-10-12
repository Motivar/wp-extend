# Gnnpls WP kit

Declare a WordPress feature once and expose it as a REST route, a WP-CLI
command and a WordPress Ability from the same definition. Parameters,
authorization, validation and output shape live in one place, so the three
surfaces cannot drift apart.

The kit has no dependency on any plugin. Install it with
`composer require gnnpls/wp-kit` (source:
`https://github.com/gnnpls/wp-kit`); it is a runtime dependency,
because the adapters register routes, commands and abilities inside
WordPress.

## Loading

Require `bootstrap.php` once per plugin. It is a no-op outside WordPress and
safe to include from several active plugins: each include registers its copy,
and the newest boots on `plugins_loaded` (priority -100). Production autoload
is owned by the loader on purpose; a PSR-4 mapping in a consumer's
`composer.json` would let whichever Composer autoloader registers first win,
regardless of version.

```php
// Composer's autoload.files requires bootstrap.php; nothing else to include.

\Gnnpls\WP\Kit::on_ready(function () {
    // declare resources, register adapters
});
```

## Declaring a resource

```php
use Gnnpls\WP\{Field, Operation, Resource};

final class Things_Resource extends Resource
{
    public function name()             { return 'things'; }
    public function service()          { return Things_Service::instance(); }
    public function rest_namespace()   { return 'my-plugin/v1'; }
    public function cli_base()         { return 'my-plugin things'; }
    public function ability_category() { return 'my-plugin-things'; }

    public function operations()
    {
        return [
            'list' => Operation::read('list_items')
                ->label('List things')
                ->input([
                    Field::enum('status', ['enabled', 'disabled'])->multiple(),
                    Field::int('limit')->default_value(50)->max(200)->describe('Rows to return.'),
                ])
                ->rest('GET')
                ->cli('list', ['columns' => ['id', 'title', 'status']])
                ->ability('list-things'),

            'delete' => Operation::destructive('delete_items')
                ->input([Field::int_list('ids')->positional()->required()])
                ->rest('DELETE', '/delete/')
                ->cli('delete', ['success' => 'Deleted %count% thing(s).'])
                ->ability('delete-things'),
        ];
    }
}
```

Then, inside `Kit::on_ready()`:

```php
$registry = (new \Gnnpls\WP\Registry())->add(new Things_Resource())->ready();

foreach ($registry->all() as $resource) {
    (new \Gnnpls\WP\Adapters\Rest_Adapter($resource))->register();
    (new \Gnnpls\WP\Adapters\Cli_Adapter($resource))->register();
    (new \Gnnpls\WP\Adapters\Ability_Adapter($resource))->register();
}
```

## Choosing surfaces

Every operation is exposed on REST, WP-CLI and the Abilities API unless told
otherwise. Leave a layer out per operation with `Operation::surfaces()`, or
for the whole resource by overriding `Resource::surfaces()`; operations that
call `surfaces()` themselves keep their own choice:

```php
class Admin_Helpers_Resource extends Resource
{
    /** No meaningful CLI for editor helpers: register REST and abilities only. */
    public function surfaces()
    {
        return [Context::REST, Context::ABILITY];
    }

    public function surfaces_reason()
    {
        return 'editor helpers have no command-line use';
    }
}
```

The reason travels into `Inventory::export()` so parity tests can tell a
deliberate omission from a missing registration. Both accept a callable for
decisions that depend on runtime state.

## What one field becomes

| `Field::int('limit')->default_value(50)->max(200)` | Output |
|---|---|
| REST | `'limit' => ['type' => 'integer', 'default' => 50, 'maximum' => 200, 'description' => …, 'sanitize_callback' => …, 'validate_callback' => …]` |
| CLI | `[--limit=<limit>]` in the synopsis and in `wp help` |
| Ability | `'limit' => ['type' => 'integer', 'default' => 50, 'maximum' => 200, 'description' => …]` |

Field types: `string`, `int`, `bool`, `enum`, `object`, `int_list`, `array`.
Modifiers: `required()`, `default_value()`, `min()`, `max()`, `max_length()`,
`describe()`, `validate_with()`, `sanitize_with()`, `multiple()`,
`positional()` (CLI), `items()`, `properties()`.

## Execution path

`Operation::run()` is the only path every surface uses:

1. normalise input against the fields and apply defaults
2. validate (`mwp_invalid_param`, 400). Nested `properties()` of an object
   field are validated the same way (required, bounds, enum, `validate_with()`),
   and `additional_properties(false)` rejects undeclared keys. Only the kit
   enforces these: the surfaces advertise the schema but do not validate
   against it.
3. authorise (`mwp_forbidden`, 403). A resolver is `true`, `false`, a
   capability string, or `fn(Operation, array $input, Context)` returning one
   of those. An unattended WP-CLI run (no `--user`) is trusted as an
   administrator; with `--user` the capability is enforced like any surface.
4. confirm destructive calls where required (`mwp_confirm_required`, 400).
   Abilities pass `confirm: true`; the CLI passes `--yes`.
5. `do_action('mwp_operation_before_run', $op, $input, $ctx)`
6. call the service method: declared fields are matched to parameters by
   name; with `->allow_extra()` the undeclared keys go to the first
   array-typed parameter and never bind by name. Use `->args(callable)` to
   take over the mapping. A handler exception becomes `mwp_operation_failed`
   (500) with a generic message; the exception text is logged and only
   attached as `detail` on the CLI or when `WP_DEBUG` is on.
7. `apply_filters('mwp_operation_result', $result, $op, $input, $ctx)`

## Hooks

| Hook | Kind | Signature |
|---|---|---|
| `mwp_kit_booted` | action | `($path, $version)` |
| `mwp_registry_ready` | action | `(Registry $registry)` |
| `mwp_operation_capability` | filter | `($resolved, Operation $op, array $input, Context $ctx)` |
| `mwp_operation_before_run` | action | `(Operation $op, array $input, Context $ctx)` |
| `mwp_operation_result` | filter | `($result, Operation $op, array $input, Context $ctx)` |
| `mwp_ability_definitions` | filter | `(array $definitions, Resource $resource)` |

Full descriptions, parameters and firing points are in [HOOKS.md](HOOKS.md).

## Inventory

`Inventory::export($registry)` returns one row per operation with the REST
routes, CLI commands and ability names it is exposed as, plus the surfaces it
deliberately excludes and why. Parity tests and generated test manifests read
it instead of hand-maintained lists.
