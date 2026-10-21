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

### Shadow mode (development / staging)

`define('EWP_DB_SHADOW', true);` on a non-production site makes every `get_db_data()` also run the
pre-1.6 SQL and compare the rows, and every `update_db_data()` / `delete_db_data()` compare the rows
both WHEREs target before writing. Differences are logged with the prefix `[ewp-db-shadow]` and the
caller (`EWP\Db\Shadow`). The old SQL is unescaped, which is why this never runs on production. Turn it
on while testing a consumer plugin against a new extend-wp release.

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
before), `tests/test-db-shadow-test.php`.
