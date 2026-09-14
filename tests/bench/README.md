# Performance benchmark

Compares two git refs of this plugin on the DDEV site and prints the median of
every metric with the relative delta. Use it before and after a refactor to
show that bootstrap cost, REST registration and request latency did not regress.

```bash
tests/bench/bench.sh main feat/my-branch 10
tests/bench/bench.sh main . 10          # "." = the current working tree, uncommitted changes included
```

Refs are checked out in place (uncommitted changes are stashed around the other
ref and restored on exit), each ref gets a cache flush and a warm-up, and every
iteration records:

| Metric | Source | What it tells you |
|---|---|---|
| `boot_ms`, `boot_q`, `boot_files`, `boot_mem_mb` | `probe.php` via `wp eval-file` | WordPress + all plugins bootstrapped, before anything REST-related. CLI compiles every file per process (no shared opcache), so this over-weights file count compared with PHP-FPM. Under WP-CLI the surfaces registry boots eagerly, so this does not show the lazy-boot saving of web requests. |
| `rest_init_ms`, `rest_init_q`, `routes_total`, `routes_plugin` | probe | Cost of `rest_api_init` (route registration) and how many routes this plugin adds. |
| `abilities`, `abilities_ms` | probe | Registered abilities (`wp_get_abilities()`). |
| `r_<case>_ms`, `_q`, `_st` | probe, `rest_do_request()` as user 1 | In-process dispatch of routes that exist on every ref: logs list, log types, REST-health plugins/endpoints, options-portability pages, object search. `_st` must be 200 on both refs or the timing is meaningless. |
| `end_files`, `end_mem_mb` | probe | Files/peak memory after those requests. |
| `http_index_ms`, `http_ns_ms`, `http_home_ms` | `curl` inside the web container | TTFB of `/wp-json/`, `/wp-json/extend-wp/v1` and the front page (cache-busted) on PHP-FPM with a warm opcache — the production-like number. |
| `cli_system_info_ms`, `cli_help_ms` | wall clock | `wp ewp system info` and `wp ewp --help`; dominated by `ddev exec` overhead, only large deltas matter. |

Raw rows land in `results/<ref>.jsonl` (gitignored); `report.php a.jsonl b.jsonl`
re-prints a comparison. Docker-for-Mac noise is roughly ±10 %: run 20–30
iterations before trusting a single-digit delta, and compare minimums as well
as medians for latency metrics.

To add a route to the probe, append it to `$cases` in `probe.php` with the
parameters it needs on **both** refs.
