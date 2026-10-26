# Custom tables (`AWM_DB_Creator`)

`AWM_DB_Creator` creates and versions custom tables from a PHP schema array. Content types
(`awm_register_content_db`) use it for their `{type}_main` / `{type}_data` tables; plugins such as filox
use it directly for their own tables (rates, sessions, …).

```php
new AWM_DB_Creator(array(
    'my_table' => array(
        'data'        => array(
            'id'      => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
            'label'   => 'varchar(191) NOT NULL',
            'counter' => 'int(11) NOT NULL DEFAULT 0',
        ),
        'primaryKey'  => 'id',
        'index'       => array('label', array('label', 'counter'), 'label(20)'),
        'foreignKey'  => array(array('key' => 'parent_id', 'ref' => 'parent_table(id)')),
        'dropColumns' => array('legacy_flag'),
        'version'     => '3',
    ),
));
```

Table names are given **without** the WordPress prefix.

## Schema keys

| Key | Meaning |
|---|---|
| `data` | Column => SQL definition. |
| `primaryKey` | Primary key column(s). |
| `index` | One index (`'col'`, `'a, b'`) or a list of indexes, each a column, a column list or `col(length)` for a prefix index. Anything that is not a plain column name is skipped and logged. |
| `foreignKey` | List of `key` (column) / `ref` (`table(column)`, unprefixed). Added only between InnoDB tables, once. |
| `dropColumns` | Columns to remove on the next version bump. Never removes the primary key or a column still declared in `data`. |
| `version` | Schema version. **Changes apply only when it differs from the stored `ewp_version_{table}` option.** Without a version the table is rebuilt on every load — always set one. |

## What a version bump does

1. `dbDelta()` creates the table if missing and adds new columns.
2. Changed column definitions (type, length, signedness, nullability, default) are applied with
   `MODIFY COLUMN`; unchanged columns are left alone. Server differences (MySQL 8 without integer
   display widths, MariaDB quoted defaults) do not count as changes.
3. Missing foreign keys are added.
4. Columns listed in `dropColumns` are removed (their foreign keys first).
5. Missing indexes are added — an index counts as present when an existing index of any name covers
   the same columns in the same order.
6. `ewp_version_{table}` is stored (autoloaded) and `ewp_database_updated` fires.

Columns that simply disappear from `data` are **kept** with their data; list them in `dropColumns` to
remove them.

## Static API

| Method | Purpose |
|---|---|
| `check_table_exists($table)` | `true` when the table exists. Trusts `ewp_version_{table}`, otherwise `SHOW TABLES LIKE`; cached per request. |
| `drop_table($table)` | Drops the table **and** its version option, so the next load recreates it. Use instead of a raw `DROP TABLE` — a table dropped with its option left behind is never recreated. Fires `ewp_database_dropped`. |
| `drop_columns($table, array $columns)` | Removes columns now (one-off migrations). Returns the removed columns, `false` on an invalid/missing table or a failed ALTER. Fires `ewp_database_columns_dropped`. |
| `truncate_table($table)` | Removes every row and resets `AUTO_INCREMENT`; schema and version stay. Tables referenced by a foreign key (a content type's `_main`) fall back to `DELETE` + counter reset, which fails — deleting nothing — while referencing rows exist: empty `{type}_data` before `{type}_main`. Fires `ewp_database_truncated`; caches built from the table are not flushed. |
| `get_db_data()`, `insert_db_data()`, `update_db_data()`, `delete_db_data()`, `insert_update_db_data()` | Row CRUD on prepared statements (since 1.6.0, see "Querying rows"). |
| `count_db_data($table, $where)` | Rows a WHERE matches (since 1.7.0); same rules and cache as `get_db_data()`. |
| `reset()` | Forgets the per-request caches (tests). |

All table and column names must be plain identifiers (`[A-Za-z0-9_]`); others are refused.

## Querying rows (`EWP\Db`, since 1.6.0)

`get_db_data()`, `update_db_data()` and `delete_db_data()` take the same WHERE array as always and
build it with `EWP\Db\Where` / `EWP\Db\Sql` (`includes/classes/ewp-db/`): every value is a
placeholder, every identifier is checked against the table's real columns and bound with `%i`.

```php
$where = array('clause' => array(
    array('operator' => 'AND', 'clause' => array(
        array('column' => 'post_id', 'compare' => '=', 'value' => 29),
        array('column' => 'status', 'compare' => 'IN', 'value' => array('a', 'b')),
    )),
    array('operator' => 'OR', 'clause' => array(
        array('column' => 'start_date', 'compare' => '>=', 'value' => '2026-12-20'),
        array('column' => 'end_date', 'compare' => '<=', 'value' => '2026-12-22'),
    )),
));
$rows = AWM_DB_Creator::get_db_data('flx_rates', '*', $where, array('column' => 'id', 'type' => 'DESC'), 50);
```

- **Nodes**: a node with `column` is a condition; a node with `clause` is a group (`operator` AND|OR,
  default AND); a plain list is an AND group. Nesting up to 10 levels, 200 conditions.
- **Comparisons**: `=` `!=` `<>` `<` `<=` `>` `>=` `LIKE` `NOT LIKE` `IN` `NOT IN` `BETWEEN`
  `NOT BETWEEN` `IS NULL` `IS NOT NULL` (case-insensitive; default `=`). `LIKE` values carry their own
  `%` — escape user input with `$wpdb->esc_like()` first.
- **IN / NOT IN**: an array of values; an empty array matches nothing (`IN`) or everything (`NOT IN`).
  Hand-built strings such as `"('a','b')"` are still accepted and parsed into an array
  (`EWP\Db\Legacy_In_List`); anything that is not a plain list of quoted strings or numbers is refused.
  For a sub-select pass `'subquery' => array('table' => 'type_data', 'select' => 'content_id', 'where' => …)`.
- **BETWEEN**: an array of two values.
- **Casts** (since 1.7.0): `'cast' => 'number'` or `'date'` compares the column as `DECIMAL(30,10)` /
  `DATETIME` — for numbers and dates stored in text columns such as `meta_value`.
- **Reads fail safe**: an invalid condition (unknown column or comparison, bad IN list) becomes
  `1=0` — it matches nothing and can never widen a result; an unknown ORDER BY or SELECT column is
  dropped. With `WP_DEBUG` the reason is logged (`SQL Notice (table): …`). `prepare_only` returns the
  prepared SQL.
- **Writes fail closed**: an unknown SET column, an invalid condition or an empty WHERE makes
  `update_db_data()` / `delete_db_data()` return `false` without touching any row. (Before 1.6 an
  UPDATE without WHERE changed every row.) Use `truncate_table()` to empty a table.
- **Stored values** are unchanged: `insert_db_data()` stores `addslashes(maybe_serialize($value))`,
  `update_db_data()` stores `maybe_serialize($value)`, reads return `stripslashes_deep()` rows. So an
  exact-match WHERE on a value containing `'`, `"` or `\` finds rows written by an update but not
  rows written by an insert (the old builder could not search such values at all).
- **Values are literal**: a backslash in a WHERE value is matched as a backslash; the old builder
  pasted values into the SQL and let MySQL interpret `\'` and friends.

`awm_get_db_content()` passes `status`, `include`, `include_hashes` as arrays and turns `meta_query`
into `content_id IN (SELECT content_id FROM {type}_data WHERE meta_key = … AND meta_value <compare> …)`
per condition, joined by `relation`; `compare` uses the list above.

### Querying with a DataViews view (since 1.7.0)

`Content_Service::query_items($type, $view, $with_meta)` — REST `GET {prefix}/{type}/query?view=…`,
`wp ewp content query --type=… --view='{json}'`, ability `ewp-content/query-items` — takes a
`@wordpress/dataviews` view and returns `{ items, count, total, total_pages, page, per_page }`.
`EWP\Db\View_Query` turns the view into a WHERE / ORDER BY / LIMIT for `{type}_main`:

```json
{"search": "red", "filters": [{"field": "price", "operator": "greaterThan", "value": 9},
 {"field": "status", "operator": "isAny", "value": ["enabled"]}],
 "sort": {"field": "title", "direction": "asc"}, "page": 1, "perPage": 20}
```

- **Fields** (`Content_Service::view_fields()`, filter `ewp_content_view_fields`): `id`, `title`,
  `status`, `created`, `modified`, `user_id`, the type's extra `_main` columns, and every meta field of its
  library. Column fields filter and sort the row directly; meta fields filter through
  `content_id [NOT] IN (SELECT content_id FROM {type}_data WHERE meta_key = … AND meta_value …)` and do
  not sort. Number / date inputs compare meta as `DECIMAL` / `DATETIME` (Where `cast`), so `9 < 10`.
- **Operators**: `is` `isNot` `isAny` `isNone` `isAll` `isNotAll` `lessThan` `greaterThan`
  `lessThanOrEqual` `greaterThanOrEqual` `before` `after` `beforeInc` `afterInc` `between` `contains`
  `notContains` `startsWith`. Negations on meta keep items that do not have the key. `isAll` /
  `isNotAll` match a plain value or a serialised list (checkbox-multiple) containing it. A filter
  without a value is ignored.
- **Search**: title and other searchable columns, searchable (text) meta values, and the id for a
  numeric term — LIKE-escaped, ANDed with the filters.
- **Refused with 400**: unknown field or operator, a list where one value is expected, `between`
  without two values, sorting by a meta field, a bad direction. `perPage` is 1–200 (default 20).
- `total` comes from `AWM_DB_Creator::count_db_data($table, $where)` (cached like reads). A page with
  meta costs three queries: rows, count, one batched meta query.

Paging with `awm_get_db_content()` (since 1.11.0): `limit` + `offset` (rows to skip) with a stable
`order_by` such as `content_id asc`. Its `title` argument matches with `LIKE` as before; `title_compare =>
'='` (or `'title' => array('value' => …, 'compare' => '=')`) matches exactly.

Bulk status: `Content_Service::set_status($type, $ids, $status)` — REST `POST {prefix}/{type}/status`,
`wp ewp content set-status`, ability `ewp-content/set-status` — saves each row through
`awm_custom_content_save()`, so `{type}_save_action` and `ewp_custom_content_save_action` fire per row.
A type's `surfaces` registration key (since 1.11.0) can route bulk status and delete to its own callables,
refuse them, hide / refuse Duplicate and refuse generic create / update — see
`includes/classes/awm-content-db-api/custom-content/readme.md` ("Registration keys").

### Content type tables outside `admin_init` (since 1.11.0)

A content type's tables are created on `admin_init`. `ewp_content_ensure_tables($type)` (or
`AWM_Add_Content_DB_Setup::ensure_tables($type)`; no argument = every registered type) creates or upgrades
them at once — for WP-CLI, cron and migrations — and returns whether both tables exist. `AWM_DB_Creator`
now forgets a cached "table missing" answer of `check_table_exists()` when it creates the table, so reads
later in the same request (a CLI migration) find it.

### Exporting large tables (since 1.11.0)

`Content_Portability::export($types, $options)` (REST `GET ewp/v1/export`, `wp ewp content export`,
ability `ewp-content/export`) reads 500 rows per query, ordered by id, with the read cache off for the
exported tables and the primed meta dropped after every batch. `page` / `per_page` (1–1000) return one page
of every type plus `pagination` (`type => {page, per_page, total, total_pages}`); the CLI streams to its
`--file` batch by batch (`Content_Portability::export_file()`), so a 90k-row table never sits in memory.
Without options REST and abilities still return every row in one payload.

### Read cache (since 1.6.2)

`get_db_data()` results are kept in the WordPress object cache (group `ewp_db`, class
`EWP\Db\Read_Cache`). Each table has a `last_changed` stamp — WordPress core's pattern for posts and
terms — and a cached result is keyed by its SQL plus the stamps of every table it reads (sub-query tables
included, so a `meta_query` read on `{type}_main` follows writes to `{type}_data`). `insert_db_data()`,
`update_db_data()`, `delete_db_data()`, `truncate_table()`, `drop_table()`, `drop_columns()` and schema
upgrades bump the stamp.

- Without a persistent object cache it is a per-request cache (repeated reads in one request are free).
  With Redis/Memcached results survive between requests.
- **Only the `{type}_main` / `{type}_data` tables of registered content types are cached by default**:
  they are written only through these functions. Tables that a plugin also writes with raw `$wpdb`
  (filox: `flx_rates`, `flx_session`, …) stay uncached, otherwise another request could read stale rows.
- Opt a table in (or out) with the filter `ewp_db_cache_table` (`bool $enabled, string $table`). A
  table opted in whose code also writes it with `$wpdb` must call
  `EWP\Db\Read_Cache::invalidate($table)` after those writes.
- Writes made outside WordPress (SQL client, `wp db import`) need `wp cache flush` with a persistent
  cache, like any other cached WordPress data. `ewp_flush_cache()` flushes it too.

### Batched meta (since 1.6.2)

`awm_get_db_content_metas($type, $ids)` loads the meta of many items with one query (per 500 ids) and
returns `id => [key => value]`; later `awm_get_db_content_meta($type, $id[, $key])` calls in the same
request are answered from it (`EWP\Db\Content_Meta`), until `{type}_data` is written. The admin content
lists, `Content_Service::list_items(…, with_meta)` (REST list, `wp ewp content list`, abilities), the
content export and the field / post type / taxonomy loaders use it, so a 50-row list with meta takes two
queries instead of 51.

`{type}_data` has an index on `(meta_key, meta_value(191))` since 1.6.2 for `meta_query` and exact
meta filters. Existing content types get it on the next admin load (internal schema version
`….0.2`); building it reads the whole `_data` table once, which can take a few seconds on large tables.

### Shadow mode (development / staging)

`define('EWP_DB_SHADOW', true);` on a non-production site makes every `get_db_data()` also run the
pre-1.6 SQL and compare the rows, and every `update_db_data()` / `delete_db_data()` compare the rows
both WHEREs target before writing. Differences are logged with the prefix `[ewp-db-shadow]` and the
caller (`EWP\Db\Shadow`). The old SQL is unescaped, which is why this never runs on production. Turn it
on while testing a consumer plugin against a new extend-wp release. Only WHEREs in the legacy shape
(root `clause`, conditions with `column`, `compare` and a scalar value — what consumer plugins pass) are
compared; array values, sub-queries and bare conditions, which the old builder cannot express, are
skipped (`Shadow::comparable()`, since 1.6.1). The filter `ewp_db_shadow_enabled` switches it per
request.

### Prepared-SQL lint

`composer lint` (part of `composer test`, so of the pre-push hook) runs `phpcs.xml.dist`: the WPCS
`WordPress.DB.PreparedSQL*` sniffs over `ewp-db/`, `awm-db/` and `content-functions.php`. Each
remaining `phpcs:ignore` carries its reason (schema definitions from code, generated `%s` lists).

## Hooks

`ewp_database_updated`, `ewp_database_dropped`, `ewp_database_columns_dropped`, `ewp_database_truncated` —
see `HOOKS.md`.

## Not exposed over REST, WP-CLI or abilities

These are developer APIs. Schema changes ship with code (bump `version`); destructive operations on raw
tables have no capability model of their own, so they are deliberately not reachable from REST, the CLI
or AI abilities. Content types expose their own CRUD through `ewp-surfaces` with the type's capability.

Tests: `tests/test-db-creator-schema-lifecycle-test.php`, `tests/test-db-creator-quick-wins-test.php`,
`tests/test-db-where-test.php`, `tests/test-db-sql-injection-test.php`,
`tests/test-db-compat-fixtures-test.php` (every WHERE recorded from filox selects the same rows as
before), `tests/test-db-shadow-test.php`, `tests/test-db-read-cache-test.php` (cache, batched meta,
`_data` index), `tests/test-content-view-query-test.php` (views, casts, counts, bulk status, legacy list),
`tests/test-content-type-surfaces-test.php` (ensure_tables, keep_user, offset / title compare, custom
columns, `surfaces`, list capabilities, paged / streamed export).
