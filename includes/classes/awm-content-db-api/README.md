
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
The plugin will throw an error. Ensure the specified directory has the correct permissions for PHP to read/write files.

### Can I use these filters for both plugins and themes simultaneously?
Yes, but be cautious to avoid conflicts. Filters in plugins typically override those in themes due to priority.

### Can I add custom content types to export?
Yes, you can specify custom post types, taxonomies, or fields in the `types` array for export.
