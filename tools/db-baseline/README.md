# DB layer baseline recorder (temporary)

Records every query that goes through the extend-wp DB layer — `AWM_DB_Creator::*` and the
`awm_*_db_content*` / `awm_custom_content_*` API — so the `AWM_DB_Creator` rewrite can be checked
against what consumers (filox and its add-ons) actually send today. Test plan:
`.ai/context/db-layer-test-list.md`.

It hooks `$wpdb`'s `query` filter and inspects the backtrace, so it records **whichever
`AWM_DB_Creator` copy is loaded** (the wp-extend plugin or the copy bundled in
`filox/includes/3rd-party/extend-wp`) without modifying it. The plugin never loads this folder.

## Use (dev sites only)

1. Install the stub (it defines `EWP_DB_BASELINE` and starts the recorder):

   ```bash
   cp wp-content/plugins/wp-extend/tools/db-baseline/mu-plugin-stub.php wp-content/mu-plugins/ewp-db-baseline.php
   ```

2. Exercise the site: `ddev exec wp mmwp self-test run --user=1 --cleanup`, the admin list
   screens, the booking funnel.
3. Summarise:

   ```bash
   ddev exec wp eval-file wp-content/plugins/wp-extend/tools/db-baseline/summarize.php
   ```

   Writes `summary.md` (overview table) and `baseline.json` (per call shape: entry, consumer
   call site, argument shape, up to 5 distinct SQL/arg samples, row counts, result hashes, errors)
   next to the raw `records-YYYYMMDD.jsonl`.
4. Remove the stub when done: `rm wp-content/mu-plugins/ewp-db-baseline.php`.

## Notes

- Off unless `EWP_DB_BASELINE` is true, and always off when `wp_get_environment_type()` is
  `production`.
- Records go to `wp-content/ewp-db-baseline/` (override with `EWP_DB_BASELINE_DIR`). The
  `.htaccess` deny file does nothing on nginx (DDEV), so the folder is web-readable there — keep
  it to local sites and delete it afterwards. Records hold argument samples (ids, statuses, meta
  values, truncated to 200 chars).
- A record is one query: a call such as `awm_get_db_content()` that runs several queries yields
  several records sharing the same entry and call site.
- Fingerprint = entry + inner method + call site + argument shape (data values replaced by their
  type, structural keys such as `compare`/`operator`/`column` kept), so the same call with
  different data groups together.

## Classes (`EWP\Tools\Db_Baseline`)

| Class | Role |
|---|---|
| `Bootstrap` | `enabled()` gate, `dir()`, `start()` |
| `Query_Recorder` | `query` filter + `shutdown` flush; builds and completes records |
| `Call_Site` | finds the outer API entry / inner runner in a backtrace; `owner()`, `relative()` |
| `Shape` | argument shape and safe samples |
| `Record_Writer` | JSON-lines writer with deny files |
| `Summary` | groups records by fingerprint; markdown table |

Tests: `tests/test-db-baseline-recorder-test.php`.
