# Hooks

Every custom action and filter fired by `mammothwp/wp-kit`. All names carry the
`mmwp_kit_` prefix. Each hook has a WP-core style docblock directly above the
`do_action` / `apply_filters` call at the location listed; keep this file and
those docblocks in sync when adding or changing a hook, and reuse an existing
hook rather than firing a near-duplicate.

| Hook | Type | Since | Fired in |
|---|---|---|---|
| [`mmwp_kit_booted`](#mmwp_kit_booted) | action | 0.1.0 | `src/Kit.php` — `Kit::boot()` |
| [`mmwp_kit_registry_ready`](#mmwp_kit_registry_ready) | action | 0.1.0 | `src/Registry.php` — `Registry::ready()` |
| [`mmwp_kit_operation_capability`](#mmwp_kit_operation_capability) | filter | 0.1.0 | `src/Capability.php` — `Capability::resolve()` |
| [`mmwp_kit_operation_before_run`](#mmwp_kit_operation_before_run) | action | 0.1.0 | `src/Operation.php` — `Operation::run()` |
| [`mmwp_kit_operation_result`](#mmwp_kit_operation_result) | filter | 0.1.0 | `src/Operation.php` — `Operation::run()` |
| [`mmwp_kit_ability_definitions`](#mmwp_kit_ability_definitions) | filter | 0.1.0 | `src/Adapters/Ability_Adapter.php` — `Ability_Adapter::register_abilities()` |

## Lifecycle

### `mmwp_kit_booted`

**Action.** Fires once the loader has chosen the newest registered copy of the
kit and installed its autoloader, before any `Kit::on_ready()` callback runs.
Fires on `plugins_loaded` (priority -100), or immediately when the kit is
registered after that action already ran.

| Param | Type | Description |
|---|---|---|
| `$path` | `string` | Directory of the booted copy. |
| `$version` | `string` | Version of the booted copy. |

### `mmwp_kit_registry_ready`

**Action.** Fires from `Registry::ready()` once the consumer has declared
every resource, so hosts and adapters can inspect the full set (e.g. build an
`Inventory`) before surfaces are registered.

| Param | Type | Description |
|---|---|---|
| `$registry` | `MammothWP\WpKit\Registry` | The registry. |

## Execution path (`Operation::run()`)

### `mmwp_kit_operation_capability`

**Filter.** Filters the resolved capability an operation requires, after the
declared resolver (bool, capability string or callable) has been evaluated
and before it is enforced. Lets a host override authorisation centrally.

| Param | Type | Description |
|---|---|---|
| `$resolved` | `bool\|string` | Capability string, `true` (public) or `false` (denied). |
| `$op` | `MammothWP\WpKit\Operation` | Operation being authorised. |
| `$input` | `array` | Normalised input. |
| `$ctx` | `MammothWP\WpKit\Context` | Invocation context (surface, user). |

Return the same shape. An unattended WP-CLI run (no `--user`) passes any
string capability regardless of this filter's result.

### `mmwp_kit_operation_before_run`

**Action.** Fires after input has been normalised, validated, authorised and
(where required) confirmed, immediately before the service method is
invoked. Use for auditing or logging; it cannot veto the call.

| Param | Type | Description |
|---|---|---|
| `$op` | `MammothWP\WpKit\Operation` | Operation. |
| `$input` | `array` | Normalised input. |
| `$ctx` | `MammothWP\WpKit\Context` | Invocation context. |

### `mmwp_kit_operation_result`

**Filter.** Filters an operation's result after the service method (and any
`->transform()` callable) has run, before it reaches the surface adapter.
Runs on every surface, so it is the single place to reshape output.

| Param | Type | Description |
|---|---|---|
| `$result` | `mixed` | Result or `WP_Error`. |
| `$op` | `MammothWP\WpKit\Operation` | Operation. |
| `$input` | `array` | Normalised input. |
| `$ctx` | `MammothWP\WpKit\Context` | Invocation context. |

## Surfaces

### `mmwp_kit_ability_definitions`

**Filter.** Filters the ability definitions of one resource before they are
passed to `wp_register_ability()`. Only fires when the Abilities API is
available and the resource declares an `ability_category()`.

| Param | Type | Description |
|---|---|---|
| `$definitions` | `array<string,array>` | Definitions keyed by ability name (`<category>/<slug>`). |
| `$resource` | `MammothWP\WpKit\Resource` | Owning resource. |

## Deprecated names (removed in 2.0.0)

Until the next major version the pre-1.0 hooks still fire, through
`do_action_deprecated()` / `apply_filters_deprecated()`, so WordPress only
logs a notice when something actually listens to the old name. Old filter
callbacks run before the new ones.

| Old hook | New hook |
|---|---|
| `mwp_kit_booted` | `mmwp_kit_booted` |
| `mwp_registry_ready` | `mmwp_kit_registry_ready` |
| `mwp_operation_capability` | `mmwp_kit_operation_capability` |
| `mwp_operation_before_run` | `mmwp_kit_operation_before_run` |
| `mwp_operation_result` | `mmwp_kit_operation_result` |
| `mwp_ability_definitions` | `mmwp_kit_ability_definitions` |
