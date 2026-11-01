# Plan: one search query builder (Extend WP)

Status: **proposal** (2026-11-01). Part 1 of 3. The other two parts are
`filox/docs/unified-search-query-plan.md` and
`filox-advanced-search/docs/unified-search-query-plan.md`.

## Why

Three places turn "request param + field definition" into `tax_query` / `meta_query` clauses:

| Where | Code | Definitions come from |
| --- | --- | --- |
| Extend WP search filters | `EWP_Search::query_prepare()` (`includes/classes/ewp-search-filter/class-wp-search.php`) | `ewp_search` content rows (`query_fields`) |
| Filox availability engine | `Filox_Engine_Functions::construct_filter_data()` | search-bar fields with `query_callback` |
| Filox Advanced Search | `filox_advanced_search_tax_query()` / `_meta_query()` | taxonomy rewrite slugs + post-type metaboxes |

They parse values differently (separators, ranges, empty/`0` handling), so the same filter can
behave differently depending on the surface it goes through. Extend WP ships inside Filox
(`motivar/extend-wp`), so it is the one layer all three can depend on. **This plan makes Extend WP
own the builder.** Filox and FXA only describe their fields and decide where the result goes.

## Goals

1. **One pure builder:** definitions + params + limits → args fragment + chip terms. No globals,
   no session, no WPML switch, no cookies.
2. **Safe by construction:** the request can only touch keys a definition declares. Every value is
   cast according to its definition's `format`, and every size has a limit.
3. **Transparent for current callers:** `query_prepare()` keeps producing the same `WP_Query` args
   for every saved search filter (golden snapshot), and `ewp_search_query_filter` keeps its payload.
4. **No slower than today on large sites** (onparnassos: 30k `fxa_search` posts; corfuhomes: large
   multi-taxonomy archives). No extra SQL queries per request.

Out of scope: the search form, the JS and the result templates.

## Hardening findings in the current `query_prepare()` / `get_results()`

| # | Finding | Fix in this plan |
| --- | --- | --- |
| S1 | Only `id` is sanitized. Meta values, `s` and arrays of any size come in raw | per-format casting + `max_values` |
| S2 | A `post` field with `search_type: orderby` copies the raw request value (`rand` works, giving `ORDER BY RAND()`) | orderby only from configured sorting options |
| S3 | An unknown `ewp_sorting` key raises a PHP warning and sets a `null` orderby | ignore unknown keys |
| S4 | `switch_lang($params['lang'], true)` writes the WPML cookie from a public GET, and runs after the term names are resolved | validate against active codes, no cookie, switch before labels (caller-side) |
| S5 | `rest_ensure_response(new WP_REST_Response(false), 400)` drops the 400, so errors return 200 | `new WP_Error(..., ['status' => 400])` |
| S6 | `date('c', strtotime($bad))` becomes 1970-01-01 | skip clause on parse failure |
| P1 | `paged` uncapped (huge OFFSET); `posts_per_page` straight from config (`-1` allowed) | `max_paged`, `max_per_page` |
| P2 | `taxonomy_term_names()` runs one `get_term()` per id | one `get_terms(['include' => …])` |
| P3 | `rest_endpoints()` reads every `ewp_search` row on every REST request | cached route list, invalidated on save/delete |
| P4 | `no_found_rows` never set | option, set when the caller does not paginate |
| T1 | State lives in globals (`$ewp_search_query`, `$ewp_args`, …) | builder is pure; globals stay only in `get_results()` for the templates |

## Design

Namespace `EWP\Search\Query` (PSR-4, `includes/classes/Search/Query/`; loaded on every request
kind, because the front-end FXA path needs it).

```
Clause_Definition   value object: key, type (taxonomy|meta|post), target (taxonomy[] | meta_key | post field),
                    format, compare, relation, separator, meta_type, label, explanation, flags
Value_Parser        format → typed value or null (null = no clause)
Query_Builder       build(Clause_Definition[] $defs, array $params, Build_Options $opts): Query_Result
Query_Result        args fragment (tax_query, meta_query, s, date_query, orderby, order, paged, per_page),
                    terms (key → label), rejected (key → reason), defs used
Query_Merger        merge(array|WP_Query $target, Query_Result $r, string $relation = 'AND')
                    — handles a WP_Tax_Query object in tax_query (->queries), keeps existing clauses
Term_Labeler        resolves chip labels for all term ids in one get_terms() call, lazily
```

### Formats (`Value_Parser`)

| Format | Input examples | Output | Notes |
| --- | --- | --- | --- |
| `term_ids` | `12`, `12-15`, `12,15`, `[12,15]` | `int[]` | separator per definition (FXA `-`, EWP arrays). Drops `''`, `null`, `'all'`, `0`. Capped by `max_values` |
| `scalar` | `foo` | `string` | `sanitize_text_field`. A plain `'0'` is a reset, so no clause |
| `range` | `0-500`, `100-`, `-500` | `[min,max]` / `>=` / `<=` | numeric cast; `'0-500'` is a real value, `'-'` is ignored |
| `list` | `[a,b]` | `string[]` | for `IN` metas |
| `date` | `2026-10-04` | ISO 8601 | parse failure → no clause |
| `search` | free text | `string` | length cap |
| `enum` | `price_asc` | key of an allow-list | orderby/order/sorting |

The value rules are the same ones the FXA frontend already follows (CLAUDE.md "a value of `0`
never creates a filter"), so a value is parsed the same way on every surface.

### Compatibility profiles

A definition can carry `compat` flags, so callers can keep their current SQL **byte for byte**:

- `tax_nested_relation` — wraps each taxonomy clause in `['relation' => X, clause]` (FXA shape);
- `meta_scalar_like` — scalar metas compare with `like` (FXA shape) instead of `=`;
- `tax_value_as_is` — passes one value as `array($value)` without casting (core `query_callback` shape);
- `keep_operator_case` — `'or'` vs `'OR'` exactly as configured today.

Profiles exist only so the move is transparent. Each caller can drop them later with its own
release and changelog note.

### Limits (`Build_Options`)

`max_values` (default 50 per key), `max_paged` (default 500), `max_per_page` (default 100;
`-1` only when the caller passes `allow_unbounded`), `max_search_length` (200). Defaults go through
`ewp_query_builder_limits_filter` so a site can raise them.

### Hooks (new, declared in `HOOKS.md` + module readme)

- `ewp_query_builder_definitions_filter( Clause_Definition[] $defs, string $context, array $params )`
- `ewp_query_builder_result_filter( Query_Result $result, string $context, array $params )`
- `ewp_query_builder_limits_filter( array $limits, string $context )`

`$context` is `ewp_search:{id}`, `filox_availability` or `fxa_archive`, so one listener can tell
them apart. Existing hooks stay and keep firing with their old payloads: `ewp_search_query_filter`
still gets `['query' => $args, 'terms' => $terms]`.

### Surfaces (REST / CLI / ability in sync)

One read-only operation on `Search_Resource`: **explain**, which builds a query without running it.

- REST `GET extend-wp/v1/search/explain` (admin), CLI `wp ewp search explain <context> --params=<qs>`,
  ability `ewp/search-explain`.
- It returns the args fragment, the rejected keys, the generated SQL (`WP_Query` with
  `'fields' => 'ids', 'posts_per_page' => 1` and the `posts_request` filter) and the timing.

This is also the tool used to compare legacy and builder output on staging (parts 2 and 3).

## Steps

| Step | Work | Done when |
| --- | --- | --- |
| E0 | **Golden snapshot before touching code**: a PHPUnit test that saves `query_prepare()` output for fixture configs (taxonomy single/multi, meta, post orderby/search/dates, sorting, lang) and a fixed set of params | snapshot committed, green on current code |
| E1 | Add `Value_Parser`, `Clause_Definition`, `Query_Builder`, `Query_Result`, `Query_Merger`, `Term_Labeler` with PHPUnit per format, limit and merge case (including a `WP_Tax_Query` object) | tests green, no caller changed |
| E2 | `Search_Filter_Definitions::from_config($conf)` + `query_prepare()` becomes a thin adapter over the builder | E0 snapshot still byte-identical for every fixture that is not one of S1–S6 |
| E3 | Fix S1–S6 one at a time; each changes only its own snapshot rows, recorded in the changelog | each change is a separate commit with a snapshot diff |
| E4 | P2–P4: batch labels, cached route list (`ewp_search` transient group, flushed on content save/delete), `no_found_rows` option | bench (`tests/bench`) shows no extra queries and ≤ current time at 1000+ and 30k posts |
| E5 | `explain` surface (REST + CLI + ability), self-test case in `manifest.json`, `docs/surfaces.md` | self-test + parity tests green |
| E6 | Release 1.15.0; `composer update motivar/extend-wp` in Filox | Filox self-test green on the bundled copy |

### Tests

- PHPUnit: one test per format (valid, empty, `0`, oversize, wrong type), per limit, per compat
  profile (the output must equal the hand-written legacy shape), per hook (old payload unchanged).
- Bench (`tests/bench`): 30k posts × 5 taxonomies × 10 metas, 20 filters in one request. Budget:
  builder time < 2 ms, SQL query count equal to legacy.
- Self-test: the existing `class-search-filter-case` must still pass unchanged; new `explain` case.

## Backwards compatibility

- Saved `ewp_search` configs: unchanged. Output is identical except for the S1–S6 fixes, each listed
  in the changelog: invalid orderby → ignored; unknown sorting → ignored; error responses →
  real 400; bad dates → no clause; `lang` no longer sets the cookie.
- `ewp_search_query_filter`, `ewp_search_prepare_form_fields_filter`, `ewp_search_result_path` and the
  globals read by `templates/frontend/search/*`: unchanged.
- Version check for consumers: `defined('EWP_QUERY_BUILDER_VERSION')`. Filox and FXA fall back to
  their legacy code when it is missing, for example when an older standalone Extend WP loads first.

## Risks

- **Standalone Extend WP vs bundled copy**: whichever loads first wins. The version constant plus
  the consumers' fallback keep this safe.
- **Snapshot drift**: E2 must not change any fixture except through E3's listed fixes. If one
  changes, the adapter is wrong, not the snapshot.
