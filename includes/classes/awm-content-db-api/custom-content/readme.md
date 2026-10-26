# Custom Content Type Objects for WordPress

This package provides an efficient way to create and manage custom content type objects in WordPress using two relational tables, similar to the relationship between `wp_posts` and `wp_postmeta`. The goal is to give developers a robust and flexible way to handle structured content beyond the limitations of custom post types and their metadata.

---

## Key Features

1. **Efficient Data Storage:**
   - Main data is stored in a table with the `_main` extension.
   - Additional attributes or fields are stored in a related table with the `_data` extension, similar to the `wp_postmeta` concept.

2. **Relational Table Design:**
   - By separating main object data and additional metadata, queries are optimized and scale better for complex data.

3. **Custom Admin Pages:**
   - Easily create custom admin pages to manage and display custom content objects.

4. **Dynamic Metabox Registration:**
   - Define custom fields for content objects using metaboxes, enabling flexibility for developers and content editors.

5. **Built-In CRUD Operations:**
   - Simplified functions to create, read, update, and delete custom content objects.

6. **Similar API to Posts:**
   - The API mimics the familiar structure of working with WordPress posts and postmeta, reducing the learning curve.

---

## Why Use This Package?

1. **Overcome Limitations of Custom Post Types:**
   - Custom post types are stored in `wp_posts`, which can lead to performance issues as the site scales.
   - This package allows you to structure your data in dedicated tables, making queries faster and more efficient.

2. **Relational Data Made Easy:**
   - WordPress core does not natively support complex relationships or table joins.
   - With the `_main` and `_data` tables, you can build relationships and store structured data efficiently.

3. **Optimized Query Performance:**
   - Queries targeting specific content types are faster due to separate tables.
   - Meta information is stored in a dedicated table, avoiding the clutter and performance bottlenecks of `wp_postmeta`.

4. **Customizable Admin Interface:**
   - Developers can create custom admin interfaces tailored to the needs of the content type, providing a better UX.

---

## How to Use

### 1. Installation
   - It comes installed with the plugin

### 2. Register a Custom Content Type Object
Use the class to register a new content type by passing arguments that define the main table and data table structure.

```php
$args = array(
    'custom_id' => 'my_content_type',
    'custom_list' => array(
        'list_name' => 'My Custom Content',
        'capability' => 'manage_options',
        'results_per_page' => 50,
        'metaboxes' => $this->ewp_fields_metas()
    )
);

new AWM_Add_Custom_List($args);
```

### Example: Registering Defaults
You can extend functionality by setting up default configurations using the `register_defaults` method.

```php
public function register_defaults($data)
{
    $data['fields'] = array(
        'parent' => 'extend-wp',
        'statuses' => array(
            'enabled' => array('label' => __('Enabled', 'extend-wp')),
            'disabled' => array('label' => __('Disabled', 'extend-wp')),
        ),
        'show_new' => false,
        'list_name' => __('Fields', 'extend-wp'),
        'list_name_singular' => __('Field', 'extend-wp'),
        'order' => 1,
        'capability' => 'activate_plugins',
        'version' => 0.01,
        'metaboxes' => $this->ewp_fields_metas()
    );
    return $data;
}

public function ewp_fields_metas()
{
    $boxes = array();
    $boxes['awm_metas'] = array(
        'title' => __('Fields Configuration', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'high',
        'callback' => 'awm_fields_configuration',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_position_settings'] = array(
        'title' => __('Fields Position', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'low',
        'callback' => 'awm_fields_positions',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_php_usage'] = array(
        'title' => __('Php usage', 'extend-wp'),
        'context' => 'normal',
        'priority' => 'low',
        'callback' => 'awm_php_views',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_fields_usage'] = array(
        'title' => __('Fields Usage', 'extend-wp'),
        'context' => 'side',
        'priority' => 'low',
        'callback' => 'awm_fields_usages',
        'auto_translate' => true,
        'order' => 1,
    );
    $boxes['awm_fields_dev_notes'] = array(
        'title' => __('Developer notes', 'extend-wp'),
        'context' => 'side',
        'priority' => 'low',
        'callback' => 'awm_dev_notes',
        'auto_translate' => true,
        'order' => 1,
    );
    return $boxes;
}
```

### 3. Defining Tables
- The main table (`{prefix}_my_content_type_main`) will store essential details such as ID, title, and primary attributes.
- The data table (`{prefix}_my_content_type_data`) will store additional fields as key-value pairs.

### 4. Using Metaboxes
Metaboxes allow developers to add custom fields for additional content customization. These fields are dynamically added to the data table and made available in the admin interface. Users can attach metaboxes using the interface available at `admin.php?page=ewp_fields`.

### 5. Adding and Managing Custom Content
Users can add and manage custom content through the admin interface at `admin.php?page=ewp_content_type`.

### 6. CRUD Operations
CRUD operations are built-in and abstracted for convenience:

- **Create:**
  ```php
  $id = awm_custom_content_save('my_content_type', $_POST);
  ```

- **Read:**
  ```php
  $content = awm_get_db_content('my_content_type', array('include' => array($id)));
  ```

- **Update:**
  The same function as create can be used to update an existing record.

- **Delete:**
  ```php
  awm_custom_content_delete('my_content_type', array($id));
  ```

### 6a. Querying (`awm_get_db_content()` arguments)

| Argument | Meaning |
|---|---|
| `fields` | `*` (default) or a list of `_main` columns. |
| `limit` / `offset` | Rows to return (`-1` = all) and, since 1.11.0, rows to skip (with a limit) — page through big tables with `order_by => array('column' => 'content_id', 'type' => 'asc')`. |
| `order_by` | `{ column, type }`, default `created desc`. |
| `include` / `include_hashes` | Content ids / row hashes. |
| `status` | One status or a list. |
| `title` | Title match. A string uses `LIKE` (as always — add `%` yourself); `title_compare => '='` matches exactly (1.11.0). `array('value' => …, 'compare' => '='\|'LIKE')` sets both, compare defaulting to `=`. |
| `meta_query` | `{ key, value, compare }` conditions plus `relation`, see `docs/custom-tables.md`. |

### 6b. Registration keys (`awm_register_content_db`)

Besides `list_name`, `list_name_singular`, `statuses`, `metaboxes`, `capability`, `version`, `parent`,
`custom_prefix`, `main_table_data` (extra `_main` columns), `meta_table_data`, `show_new`, `show_delete`,
`disable_new`, `writable` and `public_read`, a type may set (all since 1.11.0, each defaulting to the
previous behaviour):

| Key | Default | Effect |
|---|---|---|
| `icon_url` | `''` | Menu icon of a top-level list page (`add_menu_page()`; ignored for sub-menus). |
| `results_per_page` | `50` | Rows per page of the legacy list. |
| `keep_user` | `false` | `true`: `user_id` is written when a row is created and never overwritten by later saves (the author stays the author). |
| `surfaces` | see below | Turn generic actions off or route them to the type's own code. |

`surfaces` keys:

| Key | Default | Effect |
|---|---|---|
| `duplicate` | `true` | `false` hides Duplicate in both admin lists and makes `Content_Service::duplicate_item()` / the `?action=duplicate` link refuse it. |
| `set_status` | `null` | `null` = the generic save per row. A callable `fn(int[] $ids, string $status, string $content_type)` replaces it on REST `{prefix}/{type}/status`, `wp ewp content set-status`, `ewp-content/set-status` and the DataViews bulk action; return `{updated: int[], not_found?: int[]}`, `true` or a `WP_Error`. `false` refuses it. |
| `delete` | `null` | Same for deletes (REST `/delete`, `wp ewp content delete`, `ewp-content/delete-item`, both admin lists, the edit form's Delete link): `fn(int[] $ids, string $content_type)`, called with the ids that exist. `false` refuses it and hides Delete. |
| `generic_write` | `true` | `false`: `Content_Service::create_item()` / `update_item()` refuse the type, so REST `/create` and `/update/{id}`, `wp ewp content create\|update` and `ewp-content/create-item\|update-item` answer `ewp_content_surface_disabled` (403). The type's own admin form and `awm_custom_content_save()` keep working; bulk set-status follows `set_status`. |

A refused action returns the `WP_Error` `ewp_content_surface_disabled` with status 403.

```php
add_filter('awm_register_content_db', function ($types) {
    $types['booking'] = array(
        'custom_prefix'      => 'flx',
        'list_name'          => __('Bookings', 'filox'),
        'list_name_singular' => __('Booking', 'filox'),
        'capability'         => 'edit_posts',
        'icon_url'           => 'dashicons-calendar-alt',
        'results_per_page'   => 30,
        'keep_user'          => true,
        'surfaces'           => array(
            'duplicate'     => false,
            'set_status'    => array('Filox_Booking_Status', 'bulk_set'), /* runs the booking's own transitions */
            'delete'        => array('Filox_Booking', 'cancel_many'),     /* soft delete */
            'generic_write' => false,                                     /* bookings are written by the checkout only */
        ),
        'main_table_data'    => array('data' => array(
            'start_date' => array('sql' => 'date', 'label' => __('Check-in', 'filox')),
        )),
        'metaboxes'          => array(/* … */),
    );
    return $types;
});
```

Rows returned by `Content_Service` (REST, CLI, abilities) carry the type's own `_main` columns under their
registration key (`start_date` above), next to `id`, `title`, `status`, … and `meta` (since 1.11.0).

### 6c. Creating the tables outside `admin_init`

Tables are created and upgraded on `admin_init`. WP-CLI commands, cron jobs and migrations that run before
any admin request can create them now (since 1.11.0):

```php
ewp_content_ensure_tables('flx_booking'); /* true when both tables exist afterwards */
ewp_content_ensure_tables();              /* every registered type: type id => bool */
AWM_Add_Content_DB_Setup::ensure_tables('flx_booking'); /* the same, as a static call */
```

It registers the content types first when `init` has not done it yet, and is a no-op for tables whose
schema version is already applied.

### 6d. Per-type hooks

The dynamic part `{type}` is the content type id (`flx_booking`):

| Hook | Type | Parameters | Fired |
|---|---|---|---|
| `{type}_save_action` | action | `int $object_id`, `array $data` (posted) | `awm_custom_content_save()`, after the row and meta were written, before `ewp_custom_content_save_action` |
| `{type}_pre_delete_action` | action | `array $ids`, `array $where_clause` (the IN value is an array since 1.6.0) | `awm_custom_content_delete()`, before the `_data` and `_main` rows are deleted |
| `{type}_after_delete_action` | action | `array $ids`, `array $where_clause` | `awm_custom_content_delete()`, after the delete, before `ewp_custom_content_delete_action` |
| `{type}_after_duplicate_action` | action | `int $new_id`, `int $id` (original) | `awm_custom_content_duplicate()`, after the copy and its meta were written |

A `surfaces.delete` callable replaces `awm_custom_content_delete()`, so the delete hooks fire only if the
callable calls it.

### 7. Admin Interface
When the custom content type is registered, a new menu item will appear in the WordPress admin. The interface allows users to:
   - View a paginated list of custom content items.
   - Add new items or edit existing ones using custom forms defined in the metaboxes.

---

## Template Sources & Meta Inheritance

When several post types behave alike, they no longer need duplicated theme files or repeated field-library edits.

### Reusing another object's template

The *Post type configuration* screen offers **Archive template from** and **Single template from**; the *Taxonomy configuration* screen offers **Archive template from**. Each lists the public post types and taxonomies, and stores a token (`post_type:{slug}` / `taxonomy:{slug}`).

Resolution order, highest first:

1. The object's own theme file — `archive-{post_type}.php`, `{post_type}.php`, `taxonomy-{taxonomy}.php`
2. The configured source, resolved through `EWP_Template_Resolver::locate()`
3. The legacy free-text `template` path (taxonomies only)
4. The WordPress default

Leaving the select empty keeps the previous behaviour exactly.

```php
/*add your own sources, or point a token at different files*/
add_filter('ewp_template_source_options_filter', function ($options) { return $options; });
add_filter('ewp_template_source_candidates', function ($candidates, $source, $context) {
    return $candidates; /*$context is 'archive' or 'single'*/
}, 10, 3);
```

### Inheriting meta boxes

**Inherit meta boxes from post types** / **Inherit meta boxes from taxonomies** make every meta box attached to the selected objects render on the current one too. Nothing is copied: `EWP_Meta_Inheritance` extends each box's `postTypes` / `taxonomies` array at runtime, so the admin screen, list-table columns, the REST API and saving all follow automatically, and edits on the source apply instantly.

Chains are resolved transitively (A inherits B, B inherits C ⇒ A also gets C's boxes). Self-references are ignored and cycles are guarded, so a misconfiguration cannot hang the admin.

```php
/*inspect or override the resolved target => sources map*/
add_filter('ewp_meta_inheritance_map_filter', function ($map, $case) {
    return $map; /*$case is 'post_type' or 'taxonomy'*/
}, 10, 2);
```

> Both features read from the transient-cached post type/taxonomy settings, so run `wp ewp delete-cache` (or, as an administrator, load any page with `?ewp_delete_trans`) if a change does not show up.

---

## Example Use Cases
1. **Product Catalog:**
   - Store product details and specifications in the main table while keeping additional attributes like color, size, or ratings in the data table.

2. **Event Management:**
   - Store event metadata (date, location, attendees) efficiently in the relational tables.

3. **Custom Forms:**
   - Save form submissions with custom fields dynamically mapped to the `_data` table.

---

## Performance Tips
- Avoid storing large amounts of text or HTML in the main table—keep it to core details.
- Use indexes on key columns in both the `_main` and `_data` tables to optimize search queries.
- Regularly clean up unused or obsolete entries to avoid bloating the database.

---

## Known Limitations
- This system is designed to work within WordPress but relies on custom tables, which may require adjustments when migrating or backing up.
- Not fully compatible with all WordPress core functionalities like WP_Query unless extended.

---

## Conclusion
This package provides developers with an efficient, scalable, and maintainable way to handle structured content within WordPress. By leveraging custom tables and relational data storage, you can overcome the limitations of custom post types and optimize your content for performance.

