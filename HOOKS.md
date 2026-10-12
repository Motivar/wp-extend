# Hooks

Custom actions and filters fired by Extend WP. Each entry lists the name, type, parameters and where it is fired; the docblock above the `apply_filters` / `do_action` call is the full reference. Started 2026-10-12 — older hooks are documented in `docs/` and are added here as their files are touched.

| Name | Type | Parameters | Fired in | Since |
|---|---|---|---|---|
| `ewp_request_is_front_end` | filter | `bool $front_end` — detected value | `EWP\Request_Context::is_front_end()` (`includes/classes/class-request-context.php`), at plugin load | 1.5.1 |
