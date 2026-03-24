## Project
wp-extend WordPress plugin

## Task
Fix WordPress 6.7.0+ translation loading notice and PHP 8.1+ deprecation warnings

## Goal
Eliminate all notices and deprecation warnings in DDEV logs while maintaining backwards compatibility

## Current State
✅ All fixes completed:
- Translation loading prevented before `init` using `did_action('init')` checks (5 files)
- Field defaults separated from translations in logger settings
- All `strpos()` null parameter issues resolved (5 files)
- Custom content type access issue fixed
- Undefined `$fields` variable fixed
- CHANGELOG.md updated
- Status: DONE

## Constraints
- Maintain backwards compatibility
- Follow WordPress coding standards
- Use DRY principle
- No breaking changes

## Files Changed
1. `includes/classes/ewp-logger/class-ewp-logger-settings.php` - Added `did_action('init')` check in `register_admin_fields()` + created `get_field_defaults()` for untranslated defaults + fixed undefined `$fields` variable
2. `includes/classes/ewp-logger/class-ewp-logger.php` - Delayed `register_builtin_types()` to `init` action (priority 20)
3. `includes/classes/ewp-gutenburg/class-register.php` - Added `did_action('init')` check in `awm_position_options_filter()`
4. `includes/classes/ewp-fields/class-field.php` - Added `did_action('init')` check in `register_defaults()`
5. `includes/classes/ewp-wp-content/class-wp-content.php` - Added `did_action('init')` check in `register_defaults()`
6. `includes/classes/awm-list-tables/class-list-form.php` - Empty string instead of null for hidden submenu pages
7. `includes/classes/awm-list-tables/class-list-table.php` - Added null check before `strpos()`
8. `includes/classes/awm-list-tables/functions.php` - Added null checks (2 locations)
9. `templates/settings.php` - Added null check before `strpos()`
10. `CHANGELOG.md` - Documented all fixes

## Decisions
- Use `did_action('init')` checks in filter callbacks to prevent translation functions from executing before text domain loads
- Separate field structure from translations (create `get_field_defaults()` for untranslated defaults used during early initialization)
- Use empty string `''` instead of `null` for hidden submenu pages (WordPress accepts both, but empty string prevents `strpos()` deprecation in core)
- Add null checks before all `strpos()` calls for PHP 8.1+ compatibility

## Open Questions
None

## Next Step
Monitor DDEV logs to confirm all notices eliminated
