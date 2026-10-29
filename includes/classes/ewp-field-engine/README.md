# Field engine (`EWP\Fields`)

OOP renderers behind `awm_show_content()` (`includes/functions/library.php`). This module is replacing the ~800-line `switch` in that function one field `case` at a time. The procedural API stays the same, because filox, themes and other plugins call `awm_show_content()` directly.

## How a field renders

1. `awm_show_content()` does the shared preparation every field goes through: sorting, role and language visibility, `awm_prepare_field()`, value lookup and default, the `attributes.id` override, attributes and `required`, and the label.
2. It asks `Field_Registry::instance()->get( $case )` for a `Field_Type`.
   - **Registered type:** it builds a `Field_Context` and appends `$type->render( $context )`. `$type->has_wrapper()` decides whether the view's row wrapper is added (`div`, term `tr`, user `tr`).
   - **No registered type:** the legacy switch renders the field, until that case is migrated.
3. A type normalises data and renders a template under `templates/fields/` through `Template_Loader`. Markup belongs in templates, not in classes.

| Class | Role |
|---|---|
| `Field_Context` | Immutable, render-ready field state: name, DOM id, value, definition, attributes, classes, required, view, object id. |
| `Field_Type` | Interface: `case_name()`, `render()`, `has_wrapper()`. |
| `Field_Registry` | Maps a field `case` to its `Field_Type`. Built lazily; fires `ewp_register_field_types`. |
| `Template_Loader` | Resolves `fields/{name}`: the theme's `extend-wp/fields/{name}.php`, then the plugin's `templates/fields/{name}.php`, then the `ewp_field_template_path` filter. Renders with `awm_parse_template()`, so templates read `$variables`. Names are limited to `[a-z0-9_-]` segments. |
| `Field_Attributes` | `prepare()` turns array values into comma lists and translates `placeholder`. `render()` outputs escaped `key="value"` pairs. |
| `Types\Input_Field` | Case `input`. Templates: `fields/input`, `fields/input-hidden`. |

## Migrated cases

| Case | Since | Templates |
|---|---|---|
| `input` | 1.11.0 | `fields/input.php`, `fields/input-hidden.php` |

## Adding or replacing a type

```php
add_action( 'ewp_register_field_types', function ( \EWP\Fields\Field_Registry $registry ) {
	$registry->register( new My_Rating_Field() ); // implements \EWP\Fields\Field_Type, case_name() 'rating'
} );
```

Registering a built-in case name (e.g. `input`) replaces that type. To change only the markup, override the template in the theme instead (`extend-wp/fields/input.php`).

## Template rules

- Escape every value at output: `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Emit no whitespace text nodes between elements. End lines with `?>`, which makes PHP drop the newline, and don't indent the line after it. The markup must stay identical to the legacy output; `tests/test-field-render-snapshot-test.php` checks this.

## Migrating the next case

1. Make sure the case is covered in `tests/test-field-render-snapshot-test.php` for every view.
2. Add `types/class-{case}-field.php` and its templates, require them in `init.php`, and register them in `Field_Registry::boot()`.
3. Delete the case from the legacy switch in `awm_show_content()`.
4. Run `composer test`. Any snapshot drift must be either fixed or intended. Rewrite snapshots with `EWP_UPDATE_SNAPSHOTS=1` and record the change in `CHANGELOG.md`.

## Loading

`init.php` is required by `library.php` itself rather than by `Setup.php`, because Composer may load `library.php` from another bundled copy of Extend WP. The first copy to load the engine wins (`class_exists` guard).
