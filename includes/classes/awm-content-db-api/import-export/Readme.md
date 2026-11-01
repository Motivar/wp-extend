
# EWP IMPORT/EXPORT

The Extend WP plugin provides two filters, `ewp_auto_import_settings_filter` and `ewp_auto_export_settings_filter`, to allow developers to customize the import and export settings programmatically. These filters can be used in both themes and plugins to override or modify the default settings. Please note that you can find a UI page for this at wp-admin/admin.php?page=extend-wp

---

## Filters Overview

### `ewp_auto_import_settings_filter`
- **Purpose**: Modify the default settings for importing content.
- **Context**: Useful for programmatically defining the import behavior, file paths, or enabling/disabling auto-import.

### `ewp_auto_export_settings_filter`
- **Purpose**: Modify the default settings for exporting content.
- **Context**: Useful for setting up export configurations, defining content types, or customizing file paths for exports.

---

### Key Points:
1. **Auto Export**: Enable this in your **Development** environment to automatically export content types (e.g., `Field`, `Post Type`) to the specified path.
   - **Filepath Save Location**: Define a writable path relative to `wp-content` (e.g., `themes/twentytwentyfour/export`).
2. **Auto Import**: Enable this in your **QA/Production** environment to automatically import content from the exported files (the `ewp-config/` folder, or the legacy `ewp_configuration.json`).
   - **Filepath Load Location**: Ensure the path points to the same folder where the exported JSON is saved in the Development environment.

> **Important**: This feature is designed to be used across **different environments**. Enable **Auto Export** in Development and **Auto Import** in QA/Production to ensure a seamless workflow.

## Example Usage

### Plugin Example

Below is an example of how you can use these filters in a custom plugin:

```php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Name: Custom Import/Export Settings
 * Description: Customize the auto-import and auto-export settings for Extend WP plugin.
 * Version: 1.0
 * Author: Your Name
 */

// Modify auto-import settings
add_filter('ewp_auto_import_settings_filter', function ($settings) {
    // Customize the settings
    $settings['import'] = true; // Enable auto-import
    $settings['path'] = 'plugins/your-plugin'; // Define a custom path
    $settings['import_type'] = 'auto'; // Ensure auto-import is enabled
    return $settings;
});

// Modify auto-export settings
add_filter('ewp_auto_export_settings_filter', function ($settings) {
    // Customize the settings
    $settings['store'] = true; // Enable auto-export
    $settings['path'] = 'plugins/your-plugin'; // Define a custom path
    $settings['types'] = ['ewp_fields', 'ewp_post_types']; // Specify the content types to export created by ewp
    return $settings;
});
```

### Theme Example

Below is an example of how you can use these filters in a WordPress theme (e.g., `functions.php`):

```php
/**
 * Modify auto-import and auto-export settings in the active theme.
 */

// Customize auto-import settings
add_filter('ewp_auto_import_settings_filter', function ($settings) {
    // Enable auto-import with a theme-specific path
    $settings['import'] = true;
    $settings['path'] = 'themes/' . get_template() ; // Use the theme's directory for imports
    $settings['import_type'] = 'auto';
    return $settings;
});

// Customize auto-export settings
add_filter('ewp_auto_export_settings_filter', function ($settings) {
    // Enable auto-export with a theme-specific path
    $settings['store'] = true;
    $settings['path'] = 'themes/' . get_template(); // Use the theme's directory for exports
    $settings['types'] = ['ewp_fields', 'ewp_post_types']; // Export specific custom content types
    return $settings;
});
```

---

## Explanation of Parameters

### Import Settings (`ewp_auto_import_settings_filter`)
| Parameter     | Description                                          | Example Value                         |
|---------------|------------------------------------------------------|---------------------------------------|
| `import`      | Enable or disable auto-import functionality.         | `true` or `false`                     |
| `path`        | Define the relative path for import files.           | `plugins/your-plugin`    |
| `import_type` | Specify the import type (e.g., `auto`).              | `auto`                                |

### Export Settings (`ewp_auto_export_settings_filter`)
| Parameter     | Description                                          | Example Value                         |
|---------------|------------------------------------------------------|---------------------------------------|
| `store`       | Enable or disable auto-export functionality.         | `true` or `false`                     |
| `path`        | Define the relative path for export files.           | `themes/twentytwentyfour`|
| `types`       | Specify the content types to export (e.g., posts).   | `['post', 'field']`                   |
| `layout`      | File layout: `per_object` (one file per row) or `single` (legacy `ewp_configuration.json`). Missing means `single`, so existing filters keep working. | `per_object` |

The settings page stores the same keys in the option `ewp_auto_export_settings`. That option was never read before 1.15.0 (its name carried a trailing space), so a site can hold an old `store = on` without having exported. To avoid suddenly writing files, the stored `store` is honoured only when the stored value also contains `layout` (written the next time the settings page is saved); until then the settings page shows a notice asking you to save again. Settings returned by the filter apply as before.

---

## File layouts

| Layout | Files | Notes |
|--------|-------|-------|
| `single` (legacy) | `{path}/ewp_configuration.json` | Every exported type in one file. Rewritten on each save; one file means constant merge conflicts in git. |
| `per_object` (format 2) | `{path}/ewp-config/format.json` and `{path}/ewp-config/{content_type}/{hash}.json` | One row with its meta per file. Output is deterministic (sorted keys, pretty printed, trailing newline, no top-level `modified`, no `content_id` / `user_id`), and a file is only rewritten when its bytes change, so two developers editing different objects never conflict. |

- Saving a row writes only that row's file. Deleting rows removes the files of that type whose row no longer exists.
- Row hashes are identical in both layouts, so switching layouts never duplicates rows.
- Rename the files with the `ewp_config_sync_filename` filter (`$filename`, `$content_type`, `$row`); the name must be a plain `*.json` file name and stay stable per row.

### How the importing site reads the files

On every administrator page load (`admin_init`, never admin-ajax) the importing site looks at the import path:

1. `ewp-config/format.json` exists: the per object layout is read (every `{type}/*.json`). If `ewp_configuration.json` is still there too, the folder wins and a notice says the single file is ignored and can be deleted.
2. Otherwise `ewp_configuration.json` is read exactly as before.

Nothing is imported when the source did not change (the single file by its md5; the folder by a hash of every file's relative path, size and mtime). Rows are upserted by hash. Rows whose hash this site imported last time (option `ewp_auto_imported_hashes`) but that the files no longer list are deleted; rows created on the importing site are never touched. A folder without any object file is treated as an error rather than as "delete everything".

### Migrating from the single file

1. **Upgrade the plugin on every importing environment (QA, production) first.** An environment running an older version only reads `ewp_configuration.json`; once the single file is renamed it would keep the last version it saw and never receive another change.
2. On the exporting environment, open Settings > Extend WP and press **Migrate to per-object files**, or run `wp ewp content sync migrate --yes`. It writes `ewp-config/` from the database, renames `ewp_configuration.json` to `ewp_configuration.legacy.json` and saves `layout = per_object`. Settings supplied by `ewp_auto_export_settings_filter` need `'layout' => 'per_object'` added in code.
3. Commit `ewp-config/` and the renamed legacy file, and deploy.

### Surfaces

| Operation | REST (`ewp/v1`) | WP-CLI | Ability |
|-----------|-----------------|--------|---------|
| Status (layout, folder, file counts, legacy file, last import signature) | `GET config-sync/status` | `wp ewp content sync status` | `ewp-content/sync-status` |
| Re-export everything in the configured (or given) layout | `POST config-sync/export` | `wp ewp content sync export [--layout=<single\|per_object>]` | `ewp-content/sync-export` |
| Migrate to one file per object | `POST config-sync/migrate` | `wp ewp content sync migrate --yes` | `ewp-content/sync-migrate` (`confirm: true`) |
| Import now, ignoring the change signature | `POST config-sync/import` | `wp ewp content sync import [--path=<path>] --yes` | `ewp-content/sync-import` (`confirm: true`) |

All need `manage_options`. Errors while exporting on save (missing or read-only folder) are logged and shown as an admin notice; they never stop the save.

---

## Use Cases

### Plugin Use Case
You want to centralize all exported content into a specific folder within the `plugins/your-plugin` directory. Use the `ewp_auto_export_settings_filter` filter in your custom plugin to override the export path.

```php
add_filter('ewp_auto_export_settings_filter', function ($settings) {
    $settings['path'] = 'plugins/your-plugin/central-export-folder';
    $settings['store'] = true;
    $settings['types'] = ['ewp_post_types', 'ewp_fields'];
    return $settings;
});
```

### Theme Use Case
You want each theme to manage its own import/export files, so each theme has its own settings and file paths. Use the filters in the `functions.php` file of your theme.

```php
add_filter('ewp_auto_import_settings_filter', function ($settings) {
    $settings['path'] = 'themes/' . get_stylesheet() . '';
    $settings['import'] = true;
    $settings['import_type'] = 'auto';
    return $settings;
});

add_filter('ewp_auto_export_settings_filter', function ($settings) {
    $settings['path'] = 'themes/' . get_stylesheet() . '';
    $settings['store'] = true;
    $settings['types'] = ['ewp_post_types','ewp_fields'];
    return $settings;
});
```

---

## Notes

- Ensure the defined `path` is writable by PHP to avoid issues during import/export.
- Always validate your JSON files for correctness before importing them.
- Use WordPress's debugging tools (e.g., `WP_DEBUG_LOG`) to troubleshoot any issues with the filters.

---

## FAQ

### What happens if the path is not writable?
The export logs the error and shows an admin notice; the save itself goes through. Ensure the specified directory has the correct permissions for PHP to read/write files.

### Can I use these filters for both plugins and themes simultaneously?
Yes, but be cautious to avoid conflicts. Filters in plugins typically override those in themes due to priority.

### Can I add custom content types to export?
Yes, you can specify custom post types, taxonomies, or fields in the `types` array for export.
