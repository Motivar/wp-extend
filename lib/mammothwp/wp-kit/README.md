<h1 align="center">
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/banner-dark.svg">
  <img alt="Mammoth WP Kit — Define a feature once. Get a REST route, a WP-CLI command and an Ability." src="docs/assets/banner-light.svg" width="100%">
</picture>
</h1>

Declare a WordPress feature once and expose it as a REST route, a WP-CLI
command and a WordPress Ability from the same definition. Parameters,
authorization, validation and output shape live in one place, so the three
surfaces cannot drift apart.

The kit has no dependency on any plugin. Install it with
`composer require mammothwp/wp-kit` (source:
`https://github.com/mammothwp/wp-kit`); it is a runtime dependency,
because the adapters register routes, commands and abilities inside
WordPress.

## How it works

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/diagram-dark.svg">
  <img alt="How one operation definition becomes a REST route, a WP-CLI command and an Ability." src="docs/assets/diagram-light.svg" width="100%">
</picture>

## Loading

Require `bootstrap.php` once per plugin. It is a no-op outside WordPress and
safe to include from several active plugins: each include registers its copy,
and the newest boots on `plugins_loaded` (priority -100). Production autoload
is owned by the loader on purpose; a PSR-4 mapping in a consumer's
`composer.json` would let whichever Composer autoloader registers first win,
regardless of version.

```php
// Composer's autoload.files requires bootstrap.php; nothing else to include.

\MammothWP\WpKit\Kit::on_ready(function () {
    // declare resources, register adapters
});
```

## Declaring a resource

```php
use MammothWP\WpKit\{Field, Operation, Resource};

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
$registry = (new \MammothWP\WpKit\Registry())->add(new Things_Resource())->ready();

foreach ($registry->all() as $resource) {
    (new \MammothWP\WpKit\Adapters\Rest_Adapter($resource))->register();
    (new \MammothWP\WpKit\Adapters\Cli_Adapter($resource))->register();
    (new \MammothWP\WpKit\Adapters\Ability_Adapter($resource))->register();
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

### MCP

Every ability the kit registers carries `meta.mcp.public = true`, so any MCP
server built on [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter)
(its default server, Agent Connector, …) lists it as a tool with the field
schema and the read/write/destructive annotations. The kit ships no MCP server
and no dependency; the tool call runs the ability, so capability and
`confirm` checks apply unchanged. Keep a resource off MCP with:

```php
public function mcp() { return false; }
```

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
2. validate (`mmwp_invalid_param`, 400). Nested `properties()` of an object
   field are validated the same way (required, bounds, enum, `validate_with()`),
   and `additional_properties(false)` rejects undeclared keys. Only the kit
   enforces these: the surfaces advertise the schema but do not validate
   against it.
3. authorise (`mmwp_forbidden`, 403). A resolver is `true`, `false`, a
   capability string, or `fn(Operation, array $input, Context)` returning one
   of those. An unattended WP-CLI run (no `--user`) is trusted as an
   administrator; with `--user` the capability is enforced like any surface.
4. confirm destructive calls where required (`mmwp_confirm_required`, 400).
   Abilities pass `confirm: true`; the CLI passes `--yes`.
5. `do_action('mmwp_kit_operation_before_run', $op, $input, $ctx)`
6. call the service method: declared fields are matched to parameters by
   name; with `->allow_extra()` the undeclared keys go to the first
   array-typed parameter and never bind by name. Use `->args(callable)` to
   take over the mapping. A handler exception becomes `mmwp_operation_failed`
   (500) with a generic message; the exception text is logged and only
   attached as `detail` on the CLI or when `WP_DEBUG` is on.
7. `apply_filters('mmwp_kit_operation_result', $result, $op, $input, $ctx)`

On REST, a `WP_Error` result keeps its own status. Any other result is sent
with the status declared in `->rest($method, $path, $status)` (default 200),
unless the service returns a `WP_REST_Response` with a status other than 200:
that status and its headers are kept (0.3.3). This lets an operation wrap a
legacy handler that answers `new WP_REST_Response($body, 503)` without
changing what clients receive.

## Hooks

| Hook | Kind | Signature |
|---|---|---|
| `mmwp_kit_booted` | action | `($path, $version)` |
| `mmwp_kit_registry_ready` | action | `(Registry $registry)` |
| `mmwp_kit_operation_capability` | filter | `($resolved, Operation $op, array $input, Context $ctx)` |
| `mmwp_kit_operation_before_run` | action | `(Operation $op, array $input, Context $ctx)` |
| `mmwp_kit_operation_result` | filter | `($result, Operation $op, array $input, Context $ctx)` |
| `mmwp_kit_ability_definitions` | filter | `(array $definitions, Resource $resource)` |

Full descriptions, parameters and firing points are in [HOOKS.md](HOOKS.md).

## Inventory

`Inventory::export($registry)` returns one row per operation with the REST
routes, CLI commands and ability names it is exposed as, plus the surfaces it
deliberately excludes and why. Parity tests and generated test manifests read
it instead of hand-maintained lists.

## Brand

Naming, prefixes and tone: [docs/BRAND.md](docs/BRAND.md).

## Development

```bash
composer install
composer lint && composer test && composer phpcs && composer phpstan
```

## License

GPL-2.0-or-later. Changes are listed in [CHANGELOG.md](CHANGELOG.md).
