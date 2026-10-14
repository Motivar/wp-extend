# Hooks

Custom actions and filters fired by Extend WP. Each entry lists the name, type, parameters and where it is fired; the docblock above the `apply_filters` / `do_action` call is the full reference. Started 2026-10-12 — older hooks are documented in `docs/` and are added here as their files are touched.

| Name | Type | Parameters | Fired in | Since |
|---|---|---|---|---|
| `ewp_request_is_front_end` | filter | `bool $front_end` — detected value | `EWP\Request_Context::is_front_end()` (`includes/classes/class-request-context.php`), at plugin load | 1.5.1 |
| `awm_case_fields_choices_filter` | filter | `array $fields` — `awmInputFields()` (case => label + `field-choices`); `string $meta` — meta key of the rows (`awm_fields` / `query_fields`); `string $db` — content type of the rows (`ewp_fields` / `ewp_search`) | `AWM_API::case_fields()` (`includes/classes/awm-api/class-awm-api.php`), behind `GET extend-wp/v1/get-case-fields` (the "Field type configuration" box) | 1.5.1 |
