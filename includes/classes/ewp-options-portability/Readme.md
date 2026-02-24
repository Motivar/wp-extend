# EWP Options Portability

Export and import EWP option page values with transaction-safe imports, URL replacement, versioning metadata, and full CLI + REST accessibility.

## Overview

This module extends the existing **Import/Export** admin page (`ewp-import-export`) with two new action cases:

- **Export Options** — Select one or more registered EWP option pages and download their values as a `.json` file.
- **Import Options** — Upload a previously exported `.json` file to restore option page values. The system validates that each option page exists, creates a backup before writing, and automatically rolls back on failure.

All operations are accessible via:
- **Admin UI** — Integrated into the existing Import/Export page
- **REST API** — Three endpoints under `extend-wp/v1/options-portability/`
- **WP-CLI** — Commands under `wp ewp options`

## Export Data Structure

```json
{
  "ewp_options_export": true,
  "format_version": "1.0",
  "plugin": "extend-wp",
  "plugin_version": "1.1.2",
  "wp_version": "6.7",
  "php_version": "8.2.x",
  "home_url": "https://source-site.com",
  "site_url": "https://source-site.com",
  "content_url": "https://source-site.com/wp-content",
  "upload_url": "https://source-site.com/wp-content/uploads",
  "exported_at": "2026-02-19 12:00:00",
  "pages": {
    "my-plugin-settings": {
      "title": "My Plugin Settings",
      "field_count": 5,
      "fields": {
        "my_option_key": "value",
        "another_option": ["array", "value"]
      }
    }
  }
}
```

## URL Replacement

On import, the system compares the export's URL metadata with the current site. If differences are detected, all field values are recursively updated:

| URL Type | Export Key | Current Value |
|---|---|---|
| Home URL | `home_url` | `home_url()` |
| Site URL | `site_url` | `site_url()` |
| Content URL | `content_url` | `content_url()` |
| Upload URL | `upload_url` | `wp_upload_dir()['baseurl']` |

### Safety features:
- **Scheme handling** — Replaces both `http://` and `https://` variants
- **Serialized data safety** — Detects serialized strings via `is_serialized()`, unserializes before replacing, then re-serializes
- **Longest-first ordering** — Prevents partial URL replacements
- **Skip option** — `--skip-url-replace` (CLI) or `skip_url_replace` param (REST)

## Transaction Safety

Every import creates a backup of existing values before writing:

1. **Backup** — Stores current value for each key that will be touched
2. **Write** — Updates options one by one
3. **Rollback** — On any failure/exception, restores all touched keys to their backup values
4. **Backup file** — CLI supports `--backup-file=<path>` to persist the backup as JSON

## REST API Endpoints

All endpoints require `manage_options` capability.

### List Pages
```
GET /wp-json/extend-wp/v1/options-portability/pages/
```
Returns available option pages with field counts.

### Export
```
GET /wp-json/extend-wp/v1/options-portability/export/?pages[]=page-key-1&pages[]=page-key-2
```
Returns the full export JSON payload.

### Import
```
POST /wp-json/extend-wp/v1/options-portability/import/
Content-Type: application/json

{
  "data": "<raw JSON string from export file>",
  "dry_run": false,
  "skip_url_replace": false
}
```
Returns an import summary with counts of imported/skipped pages and fields.

## WP-CLI Commands

### List exportable pages
```bash
wp ewp options list
wp ewp options list --format=json
```

### Export
```bash
wp ewp options export
wp ewp options export --pages=my-settings,another-page
wp ewp options export --file=/tmp/backup.json
wp ewp options export --format=table
```

### Import
```bash
wp ewp options import /tmp/backup.json
wp ewp options import backup.json --dry-run
wp ewp options import backup.json --skip-url-replace
wp ewp options import backup.json --backup-file=/tmp/pre-import-backup.json
```

## Logging

All export/import operations are logged via `ewp_log()` with detailed data:

| Field | Description |
|---|---|
| `actor` | `'cli'`, `'rest'`, or `'admin'` |
| `dry_run` | Whether this was a dry-run |
| `pages_requested` | Page keys requested |
| `pages_imported` / `pages_skipped` | Results |
| `fields_imported` / `fields_skipped` | Field counts |
| `old_home_url` / `new_home_url` | URL context |
| `url_replace_applied` | Whether URLs were replaced |
| `plugin_version_match` | Whether plugin versions matched |
| `warnings` | Array of warning strings |
| `backup_created` | Whether backup was created |

Action types registered: `options_export`, `options_import`.

## Filters & Hooks

| Filter/Action | Description |
|---|---|
| `ewp_import_export_fields_filter` | Extend the Import/Export page fields (used by this module) |
| `ewp_options_portability_exportable_pages` | Modify which option pages are available for export |
| `ewp_options_portability_export_data` | Modify the export payload before returning |
| `ewp_options_portability_before_import` | Modify or cancel import data before processing (return `false` to cancel) |
| `ewp_options_portability_after_import` | Action fired after import completes (receives summary + options) |
| `ewp_options_portability_url_replacements` | Modify URL replacement pairs before they are applied |
| `ewp_options_portability_before_rollback` | Action fired before rollback executes |

## Versioning & Compatibility

The export payload includes `plugin_version`, `wp_version`, and `php_version`. On import:

- **Missing pages** — Skipped with a warning (non-blocking)
- **Plugin major version mismatch** — Warning in response + confirmation dialog in admin UI
- **format_version** — For future-proofing; allows the import logic to handle older export formats

## Files

```
includes/classes/ewp-options-portability/
  class-options-portability.php      — Main PHP class (admin, REST, core logic)
  class-options-portability-cli.php  — WP-CLI commands
  Readme.md                          — This file
assets/js/admin/class-ewp-options-portability.js  — Admin JS (OOP class)
assets/css/admin/ewp-options-portability.css       — Admin CSS
```
