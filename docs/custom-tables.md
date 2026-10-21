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
| `get_db_data()`, `insert_db_data()`, `update_db_data()`, `delete_db_data()`, `insert_update_db_data()` | Row CRUD (being rewritten onto prepared statements, see the DB layer plan). |
| `reset()` | Forgets the per-request caches (tests). |

All table and column names must be plain identifiers (`[A-Za-z0-9_]`); others are refused.

## Hooks

`ewp_database_updated`, `ewp_database_dropped`, `ewp_database_columns_dropped`, `ewp_database_truncated` —
see `HOOKS.md`.

## Not exposed over REST, WP-CLI or abilities

These are developer APIs. Schema changes ship with code (bump `version`); destructive operations on raw
tables have no capability model of their own, so they are deliberately not reachable from REST, the CLI
or AI abilities. Content types expose their own CRUD through `ewp-surfaces` with the type's capability.

Tests: `tests/test-db-creator-schema-lifecycle-test.php`, `tests/test-db-creator-quick-wins-test.php`.
