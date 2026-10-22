# DataViews admin list (custom content types)

Since 1.7.0 a content type's admin list can render with
[`@wordpress/dataviews`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/)
instead of the legacy `WP_List_Table` (`AWM_List_Table`). It has search, filters, sortable columns,
table and list layouts, column visibility, bulk actions and a page size that is remembered per browser.

## Which list renders

`EWP_Content_List_UI::mode($type)` (`includes/classes/awm-list-tables/class-content-list-ui.php`):

| Condition | List |
|---|---|
| Filter `ewp_content_list_ui` returns `dataviews` (default for Extend WP's own `ewp_fields`, `ewp_post_types`, `ewp_taxonomies`, `ewp_search`, `ewp_content_type`) | DataViews |
| Any other type (filox coupons, transactions, …) unless the filter opts it in | legacy |
| WordPress older than 6.8, or `build/modules/ewp-dataviews-list.*` not built | legacy |
| `?ewp_list_ui=legacy` on the list URL ("Switch to the classic list" link) | legacy, for that request |

Opt a type in:

```php
add_filter('ewp_content_list_ui', function ($mode, $type) {
    return $type === 'flx_coupon' ? 'dataviews' : $mode;
}, 10, 2);
```

## How it works

- `flx_table_list_page_handler()` calls `EWP_Content_List_UI::render()`, which prints
  `templates/admin-view/content-list.php`: a `.ewp-dataviews-list` element whose `data-config` holds the
  REST path, fields, statuses, URLs and the default view.
- The module `assets/js/modules/ewp-dataviews-list.js` (built to `build/modules/ewp-dataviews-list.js`
  and `.css`) is registered with the Dynamic Asset Loader on that selector, so it loads on this screen
  only. It bundles DataViews **4.15.4** — the version WordPress 6.8 ships, built for the
  `@wordpress/components` of 6.8+ — and takes React and the other WordPress packages from core
  (`wp-element`, `wp-components`, `wp-api-fetch`, …, enqueued by `render()`).
- Data comes from the type's REST routes, i.e. the same `Content_Service` as the CLI and abilities:
  `GET {prefix}/{type}/query?view=…&render=1` (see `docs/custom-tables.md` → "Querying with a DataViews
  view"), `POST {prefix}/{type}/status`, `DELETE {prefix}/{type}/delete`.

## Fields

`EWP_Content_List_UI::fields()` builds one DataViews field per legacy list column (the type's
`columns` plus library fields flagged `admin_list`), so a type's list shows the same columns as before:

- `title` first, linking to the edit screen; `ewp_date` becomes `created`.
- Columns that are `_main` columns sort (`Content_Service::view_fields()`, filter
  `ewp_content_view_fields`); meta fields do not.
- `status` and `select` / `radio` meta fields are filterable (`is any` / `is none`); `checkbox_multiple`
  fields filter with `is all` / `is not all`. DataViews 4.15 filters only fields with options; the
  REST/CLI/ability `query` accepts every operator.
- Cells show the legacy cell HTML — `ewp_column_{type}_column_content_filter`, kses-filtered — when there
  is one, else the value (option labels for fields with options).

## Actions

Edit and Duplicate (the legacy, nonce-protected `?action=duplicate` link, still processed by
`AWM_List_Table::process_bulk_action()`), one "Set status: …" per status (bulk) and Delete (bulk, with
a confirmation). Actions added through `ewp_row_actions_{type}_filter` beyond edit / duplicate /
delete appear as links under the title.

## Building and testing

```bash
npm run build
```

```bash
npm run test:js
```

`npm run test:js` (Jest) covers the view → request mapping and field helpers
(`assets/js/modules/ewp-dataviews-list/helpers.js`); `composer test` runs it on the host when Node 18+ is
available. PHPUnit: `tests/test-content-list-ui-test.php` (mode, fields, config, rendered cells and
actions over REST, asset registration).
