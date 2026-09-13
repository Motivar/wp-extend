# WordPress Abilities API integration

Extend WP registers its own functionality with the [WordPress Abilities API](https://developer.wordpress.org/) so any consumer of that API — the core AI client, the MCP Adapter, the `ai` plugin — can inspect and change the plugin's configuration without bespoke transport code.

Module: `includes/classes/ewp-abilities/`. The logger's five read-only abilities live separately in `includes/classes/ewp-logger/class-ewp-logger-abilities.php` and are unchanged.

## Requirements

Abilities are registered only when all of the following hold:

| Check | Detail |
| --- | --- |
| WordPress version | 6.9 or newer (`EWP_Abilities::MIN_WP_VERSION`) |
| API surface | `wp_register_ability()`, `wp_register_ability_category()`, `wp_has_ability_category()` and `WP_Abilities_Registry` all present |
| `ewp_abilities_supported` filter | not forced to `false` |
| `ewp_abilities_enabled` filter | not forced to `false` |

On an older WordPress nothing is hooked, the plugin behaves exactly as before, and administrators see a dismissible notice explaining why. `EWP_Abilities::get_unsupported_reason()` returns that text.

## Categories

| Category | Covers |
| --- | --- |
| `ewp-logger` | activity log (read abilities from the logger module, plus the write ability here) |
| `ewp-content` | generic CRUD for any registered custom content type |
| `ewp-fields` | UI-configured custom field groups (`ewp_fields`) |
| `ewp-wp-content` | UI-registered post types and taxonomies |
| `ewp-search` | front-end search filters (read only) |
| `ewp-options` | options page export and import |
| `ewp-system` | site orientation and cache flushing |

## Ability inventory

Annotations are shown as read-only / idempotent / destructive.

### `ewp-logger`

| Ability | Annotations | Capability | Input |
| --- | --- | --- | --- |
| `ewp-logger/write-entry` | no / no / no | logger viewer capability | `owner`\*, `action_type`\*, `message`\*, `data`, `level` (`editor`\|`developer`), `object_type`, `behaviour` (`error`\|`success`\|`warning`) |

This is the ability behind `flx_log()` and every sibling helper: they all delegate to `ewp_log()` with their own owner slug, so one generic write ability covers them all. It returns `503` when logging is switched off.

### `ewp-content`

| Ability | Annotations | Capability | Input |
| --- | --- | --- | --- |
| `ewp-content/list-content-types` | yes / yes / no | `read`, rows filtered per type | none |
| `ewp-content/list-items` | yes / yes / no | the content type's own capability | `content_type`\*, `status`, `search`, `include`, `limit`, `order_by`, `with_meta` |
| `ewp-content/get-item` | yes / yes / no | as above | `content_type`\*, `id`\* |
| `ewp-content/create-item` | no / no / no | as above | `content_type`\*, `title`\*, `status`, `meta` |
| `ewp-content/update-item` | no / yes / no | as above | `content_type`\*, `id`\*, `title`, `status`, `meta` |
| `ewp-content/delete-item` | no / yes / **yes** | as above | `content_type`\*, `ids`\*, `confirm`\* |

`list-content-types` reports each type's statuses, capability and field keys. Call it first: it is the only reliable source of valid `content_type` and meta key values, since other plugins register their own through `awm_register_content_db`.

### `ewp-fields`

| Ability | Annotations | Capability | Input |
| --- | --- | --- | --- |
| `ewp-fields/list-field-vocabulary` | yes / yes / no | `activate_plugins` | none |
| `ewp-fields/list-field-groups` | yes / yes / no | `activate_plugins` | list arguments |
| `ewp-fields/get-field-group` | yes / yes / no | `activate_plugins` | `id`\* |
| `ewp-fields/create-field-group` | no / no / no | `activate_plugins` | `title`\*, `status`, `awm_fields`\*, `awm_positions`\*, `awm_type`, `awm_explanation` |
| `ewp-fields/update-field-group` | no / yes / no | `activate_plugins` | `id`\* plus any of the above |
| `ewp-fields/delete-field-group` | no / yes / **yes** | `activate_plugins` | `ids`\*, `confirm`\* |

`list-field-vocabulary` returns the valid field cases, input sub-types, position cases and usage types with the extra settings each one takes, all read from the live libraries, so a site that adds cases through `awmInputFields_filter` is described correctly.

`awm_fields` and `awm_positions` are stored whole. An update that sends either one replaces it, so send the complete array.

### `ewp-wp-content`

| Ability | Annotations | Capability | Key input |
| --- | --- | --- | --- |
| `ewp-wp-content/list-post-types`, `get-post-type` | yes / yes / no | `activate_plugins` | list arguments, `id`\* |
| `ewp-wp-content/create-post-type` | no / no / no | `activate_plugins` | `title`\*, `post_name`\*, `plural`\*, `singular`\*, `prefix`, `args`, `taxonomies`, `hierarchical`, template sources, `inherit_metas`, role access |
| `ewp-wp-content/update-post-type` | no / yes / no | `activate_plugins` | `id`\* plus any of the above |
| `ewp-wp-content/delete-post-type` | no / yes / **yes** | `activate_plugins` | `ids`\*, `confirm`\* |
| `ewp-wp-content/list-taxonomies`, `get-taxonomy` | yes / yes / no | `activate_plugins` | list arguments, `id`\* |
| `ewp-wp-content/create-taxonomy` | no / no / no | `activate_plugins` | `title`\*, `taxonomy_name`\*, `name`\*, `label`\*, `post_types`\*, `prefix`, `template_source`, `inherit_metas`, `show_admin_column` |
| `ewp-wp-content/update-taxonomy` | no / yes / no | `activate_plugins` | `id`\* plus any of the above |
| `ewp-wp-content/delete-taxonomy` | no / yes / **yes** | `activate_plugins` | `ids`\*, `confirm`\* |

Rows are decorated with `registered_name` (`prefix_slug`) and `is_registered`. The name length is validated against the WordPress limits, 20 characters for a post type and 32 for a taxonomy, before the row is written.

WordPress registers the object on the **next** request, after the cache flush that the save triggers, so `is_registered` is `false` in the response that creates it.

### `ewp-search`, `ewp-options`, `ewp-system`

| Ability | Annotations | Capability | Input |
| --- | --- | --- | --- |
| `ewp-search/list-filters`, `get-filter` | yes / yes / no | `activate_plugins` | list arguments, `id`\* |
| `ewp-options/list-pages` | yes / yes / no | `manage_options` | none |
| `ewp-options/export` | yes / yes / no | `manage_options` | `page_keys`\* |
| `ewp-options/import` | no / no / **yes** | `manage_options` | `data`\*, `dry_run` (default `true`), `skip_url_replace`, `confirm` |
| `ewp-system/get-site-info` | yes / yes / no | `read` | none |
| `ewp-system/flush-cache` | no / yes / no | `manage_options` | none |

Search filter rows are decorated with their `shortcode` and `rest_endpoint`. Writing them is deliberately not exposed here; use the generic `ewp-content` abilities if you need it.

`ewp-options/export` returns raw option values, so treat the payload as sensitive.

## Write safety

- **Explicit confirmation.** Every destructive ability requires a literal `confirm: true` in its input, because neither the Abilities API nor an MCP client offers a confirmation step of its own. `ewp-options/import` requires it only when `dry_run` is `false`; imports are a dry run unless you say otherwise.
- **Capability per content type.** The generic content abilities resolve the capability from the content type's own registration rather than assuming one.
- **Auditing.** Every non-read-only `ewp-*` ability execution is written to the activity log as owner `extend-wp`, action type `ability_write`, level `developer`, with the ability name, a noise-filtered and truncated copy of the input and an outcome summary. Failures are logged with the error behaviour. Content writes therefore produce two entries: the logger's own `content_save` and this one, sharing a `request_id`.
- **Cache flushing is automatic.** Writes go through `awm_custom_content_save()` / `awm_custom_content_delete()`, which fire the actions that flush the transients, so `ewp-system/flush-cache` is rarely needed.

## Authorization across surfaces

Since 1.5.0 the three surfaces agree on who may call a content operation:

| Surface | Read | Write |
|---|---|---|
| Ability (`ewp-content/*`, typed categories) | the content type's `capability` | the content type's `capability` |
| REST (`{prefix}/{type}`, `/create/`, `/update/{id}`, `/delete/`) | the content type's `capability`, or anonymous when the type registers `'public_read' => true` | the content type's `capability` |
| WP-CLI (`wp ewp content …`) | trusted as an administrator without `--user`; with `--user` the same capability applies | same |

`AWM_Dynamic_API` routes with no `permission_callback` require `manage_options` (filter `ewp_dynamic_api_default_permission`) unless the definition says `'public' => true`. The only intentionally anonymous routes are the search-filter results (`ewp-filter/{id}`) and the front-end `recently-seen` recorder (filter `ewp_recently_seen_public`). The field-builder helper routes need `edit_posts`; the map-options route needs a logged-in user (filter `ewp_map_options_public`).

## REST

Core exposes the abilities itself; this module registers no routes. Every ability sets `show_in_rest`.

```bash
curl -u user:app-password "https://example.com/wp-json/wp-abilities/v1/abilities?category=ewp-fields"
curl -u user:app-password "https://example.com/wp-json/wp-abilities/v1/abilities/ewp-system/get-site-info/run"
```

Read-only abilities run over `GET`, the rest over `POST` with the input in the body.

## Filters

| Filter | Signature | Purpose |
| --- | --- | --- |
| `ewp_abilities_supported` | `(bool $supported, string $wp_version)` | Force the whole integration off. |
| `ewp_abilities_enabled` | `(bool $enabled)` | Switch registration off while keeping the API check honest. |
| `ewp_abilities_providers` | `(array $providers, EWP_Abilities_Content_Service $service)` | Add or remove providers before they are initialised. |
| `ewp_abilities_{category}_definitions` | `(array $definitions, EWP_Abilities_Provider $provider)` | Change one provider's abilities, for example `ewp_abilities_ewp-fields_definitions`. |
| `ewp_abilities_capability` | `(string $capability, string $ability_name)` | Change the capability an ability enforces. |
| `ewp_abilities_allow_unknown_meta` | `(bool $allow, string $content_type)` | Accept meta keys a content type does not declare. |
| `ewp_abilities_audit_enabled` | `(bool $record, string $ability_name)` | Skip audit logging for an ability. |

## Extending

Adding abilities for a new content type usually needs no code: the generic `ewp-content` abilities pick up anything registered through `awm_register_content_db`. For typed abilities with their own names and schemas, extend `EWP_Abilities_Typed_Provider`, declare your entities in `entities()`, and add the provider through `ewp_abilities_providers`. Input schemas are derived from the field library, so they stay correct as the library changes.
