# DataViews admin list (custom content types)

Since 1.7.0 a content type's admin list can render with
[`@wordpress/dataviews`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/)
instead of the legacy `WP_List_Table` (`AWM_List_Table`); since 1.8.0 it is the default for every content type. It has search, filters, sortable columns,
table and list layouts, column visibility, bulk actions and a page size that is remembered per browser.

## Which list renders

`EWP_Content_List_UI::mode($type)` (`includes/classes/awm-list-tables/class-content-list-ui.php`):

| Condition | List |
|---|---|
| Filter `ewp_content_list_ui` returns `dataviews` (the default for every registered content type) | DataViews |
| The filter returns `legacy` for the type | legacy |
| WordPress older than 6.8, or `build/modules/ewp-dataviews-list.*` not built | legacy |
| `?ewp_list_ui=legacy` on the list URL ("Switch to the classic list" link) | legacy, for that request |

Keep a type on the legacy list (e.g. one whose screen relies on markup the legacy table prints):

```php
add_filter('ewp_content_list_ui', function ($mode, $type) {
    return $type === 'flx_coupon' ? 'legacy' : $mode;
}, 10, 2);
```

Before 1.8.0 only Extend WP's own types (`ewp_fields`, `ewp_post_types`, `ewp_taxonomies`, `ewp_search`,
`ewp_content_type`) defaulted to DataViews and the `DEFAULT_TYPES` constant listed them; it is gone.

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
  fields filter with `is all` / `is not all`. Date, number and text filters are declared (see
  [Filters](#filters-since-1120)); the REST/CLI/ability `query` accepts every operator.
- Legacy restrict filters (library fields with `restrict_content`) that are not columns become hidden,
  filter-only fields (`hidden: true`, not in the default view) when they have options, so a type keeps its
  filters after switching.
- Cells show the legacy cell HTML — `ewp_column_{type}_column_content_filter`, kses-filtered — when there
  is one, else the value (option labels for fields with options).

## Filters (since 1.12.0)

Every filter of the list lives in DataViews' filter panel, behind the funnel icon next to the search box
("Add filter", one chip per filter, "Reset"). Besides the automatic ones above, a content type declares
filters in its registration (`list_filters`) or with the `ewp_content_list_filters` filter, keyed by a
field id. A list column gets the filter; any other id becomes a filter-only field (in the panel, never a
column).

```php
add_filter('awm_register_content_db', function ($types) {
    $types['event']['list_filters'] = array(
        'status'  => array('type' => 'multiselect', 'isPrimary' => true, 'elements' => array('draft' => 'Draft', 'live' => 'Live')),
        'when'    => array('type' => 'date', 'label' => 'Dates', 'fields' => array('starts' => 'Starts', 'created' => 'Created')),
        'price'   => array('type' => 'number'),
        'title'   => array('type' => 'text'),
        'venue'   => array('type' => 'select', 'elements' => array('a' => 'Hall A', 'b' => 'Hall B'), 'operators' => array('is')),
    );
    return $types;
});
```

| Key | Meaning |
|---|---|
| `type` | `select` (one value; operators `is`, `isNot`), `multiselect` (`isAny`, `isNone`, `isAll`, `isNotAll`; default `isAny` + `isNone`), `date` (a from / to range), `number` (a min / max range), `text` (contains). |
| `label` | Label of a filter-only field (a column keeps its own). |
| `elements` | `select` / `multiselect`: value => label, or a list of `{value, label}`. Required for those types. |
| `operators` | `select` / `multiselect`: a subset of the type's operators. |
| `field` | The view field the filter applies to (default: the id). |
| `fields` | `date` / `number`: view fields the range may apply to, value => label; the panel shows an "Applies to" select and the first is the default (e.g. one "Dates" filter over check-in / check-out / created). |
| `isPrimary` | Show the chip without "Add filter" first. |

`EWP_Content_List_UI::normalize_filter()` validates each declaration against `Content_Service::view_fields()`:
an unknown type, a target that is not a view field, an element filter without elements or an operator outside
the type's list is dropped, so the list only sends conditions the `query` route accepts (the route itself
allowlists fields and operators and binds every value). The field config then carries
`filter: { type, field, fields, elements?, operators?, isPrimary }`; a list with its own REST adapter
(its own `restPath`, such as Filox bookings) can put the same `filter` key on its fields.

**Query format.** The panel stores a range or text as one value; the request translates it into standard
view conditions on the target field, so `GET {restPath}/query?view=…` receives:

| Filter | Condition(s) |
|---|---|
| date from + to | `{field, operator: "between", value: ["2026-01-01", "2026-01-31"]}` |
| date from / to only | `afterInc` / `beforeInc` with one date (`Y-m-d`) |
| number min + max | `between` `[200, 450]`; min / max only: `greaterThanOrEqual` / `lessThanOrEqual` |
| text | `contains` |
| select / multiselect | the operator and value(s) as picked (`is`, `isAny`, …) |

A date-only upper bound on a `date` / `datetime` field covers that whole day (`View_Query`, since 1.12.0).

**Why not a newer DataViews.** DataViews 4.15 (WordPress 6.8) has no date / number operators and shows
only filters with elements. Version 5.0+ adds them but is built for the `@wordpress/components` of
WordPress 6.9+ (10.1 ships with 6.9, 12.0 with 7.0, current releases need components 43 and the new
`@wordpress/ui`); the module takes components from core, so a newer bundle would break the list on older
sites. Instead `webpack.config.js` swaps DataViews' internal filter popover
(`build-module/components/dataviews-filters/search-widget.js`) for
`assets/js/modules/ewp-dataviews-list/filter-widget.js`: element filters render the original widget, input
filters render from / to (or min / max, or text) inputs with an Apply button, and each input filter gets one
element (its current value) so DataViews lists it and labels its chip. The build fails if that file moves, and
a Jest test pins DataViews to 4.15.4: an upgrade has to revisit the widget (and can drop it once the fleet's
oldest WordPress ships DataViews 5+).

**Links that follow the view.** An element with `data-ewp-dataviews-view-link="{type}"` (an "Export"
button, say) gets its `href` rewritten on every view change: its original URL plus `view` = the request view
as JSON (`{search, filters, sort}`, no paging). The mount element also fires
`ewp-dataviews-list:view` (`detail: {type, view}`, bubbles).

**Page filters.** When the configuration's `defaultView` has filters (or a search), they win over the view
saved in the browser, so a link with filters opens with them in the panel.

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

`npm run test:js` (Jest) covers the view → request mapping, the filter specs, value encoding and
condition translation, view links and field helpers (`assets/js/modules/ewp-dataviews-list/helpers.js`),
and pins the bundled DataViews version; `composer test` runs it on the host when Node 18+ is
available. PHPUnit: `tests/test-content-list-ui-test.php` (mode, fields, restrict filters, declared filters, config,
rendered cells and actions over REST, asset registration); `tests/test-content-view-query-test.php` (the
conditions the panel sends, end-of-day upper bounds).
