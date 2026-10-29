# Field engine (`EWP\Fields`)

OOP renderers behind `awm_show_content()` (`includes/functions/library.php`). Since 1.11.0 every field `case` renders here; the ~800-line `switch` that used to build the HTML as strings is gone. The procedural API stays the same, because filox, themes and other plugins call `awm_show_content()` (and `awm_media_field_html()`, `awm_repeater_content()`) directly.

## How a field renders

1. `awm_show_content()` does the shared preparation every field goes through: sorting, role and language visibility, `awm_prepare_field()`, value lookup and default, the `attributes.id` override, attributes and `required`, and the label.
2. It asks `Field_Registry::instance()->get( $case )` for a `Field_Type`.
   - **Registered type:** it builds a `Field_Context` and calls `$type->render( $context )`, which returns a `Field_Output`: the field's HTML plus its effect on the view's row wrapper (`div`, term `tr`, user `tr`) — skip it (`without_wrapper()`), add classes or attributes to it, or render nothing at all (`suppressed()`).
   - **No registered type** (an unknown case): nothing renders inside the row wrapper, as before.
   - A type can also drop the automatic `awm_custom_meta[]` save marker (`without_meta_marker()`), e.g. the modal, which saves through REST.
3. A type normalises data and renders a template under `templates/fields/` through `Template_Loader`. Markup belongs in templates, not in classes.

| Class | Role |
|---|---|
| `Field_Context` | Immutable, render-ready field state: name, DOM id, value, definition, attributes, classes, required, view, object id, explanation (pre-rendered HTML), context id (options page id). |
| `Field_Type` | Interface: `case_name()`, `render( Field_Context ): Field_Output`. |
| `Field_Output` | Immutable result: `html()`, plus row-wrapper settings (`without_wrapper()`, `with_wrapper_classes()`, `with_wrapper_attributes()`, `suppressed()`, `without_meta_marker()`). |
| `Field_Registry` | Maps a field `case` to its `Field_Type`. Built lazily; fires `ewp_register_field_types`. |
| `Template_Loader` | Resolves `fields/{name}`: the theme's `extend-wp/fields/{name}.php`, then the plugin's `templates/fields/{name}.php`, then the `ewp_field_template_path` filter. Renders with `awm_parse_template()`, so templates read `$variables`. Names are limited to `[a-z0-9_-]` segments. |
| `Field_Attributes` | `prepare()` turns array values into comma lists and translates `placeholder`. `render()` outputs escaped `key="value"` pairs. |
| `Types\Input_Field` | Case `input`. Templates: `fields/input`, `fields/input-hidden`. |
| `Types\Textarea_Field` | Case `textarea`, plain or `wp_editor`. Row wrapper gets `awm-cls-100` (+ `awm-wp-editor`). Template: `fields/textarea`. |
| `Types\Select_Field` | Case `select`: selected state, `multiple`, optgroups, `removeEmpty`, `no_style`. Suppressed without options in `restrict_manage_posts`. Template: `fields/select`. |
| `Types\Radio_Field` | Case `radio`: fires `awm_radio_value_label_filter` and `awm_radio_after_text`. Template: `fields/radio`. |
| `Media_Field_Renderer` | The media picker behind `awm_media_field_html()` (`image`, `awm_gallery`): `render()` (template `fields/media`, still filtered by `awm_media_field_html_filter`), `enqueue()`. |
| `Types\Message_Field`, `Types\Callback_Field`, `Types\Gallery_Field` | Cases `message`, `function` (callback output) and `awm_gallery`: one message box (`fields/message-box`). Bodies are developer markup and print raw. |
| `Types\Button_Field`, `Types\Image_Field`, `Types\Map_Field` | Cases `button`, `image` (wrapper `awm-custom-image-meta`) and `map` (wrapper `awm-cls-100`). Templates `fields/button`, `fields/image`, `fields/map`. |
| `Types\Section_Field` | Case `section`: nested fields as one array (or own keys with `keep_inputs`), rendered through `awm_show_content()`. Template `fields/section`. |
| `Types\Tab_Field` | Case `awm_tab`: one panel of nested fields per tab (`include` or `callback`). Template `fields/tabs`. |
| `Types\Repeater_Field` | Case `repeater`: rows plus a disabled clone-source row. `row()` backs `awm_repeater_content()`. Templates `fields/repeater`, `fields/repeater-row`, `fields/repeater-actions` (filtered by `awm_show_actions_html`). |
| `Types\Modal_Field` | Case `awm_modal`: trigger button; no save marker. Fires `awm_modal_field_args`. Template `fields/modal`. |
| `Types\Object_Id_Filter_Field` | Case `object_id_filter`: type select + remote-search id select, preloads saved ids, posts its own two save markers. Template `fields/object-id-filter`. |
| `Types\Checkbox_Multiple_Field` | Case `checkbox_multiple`: apply-all option (`toggle` or `exclusive`, which tags the row wrapper). Prints its own title and explanation. Template: `fields/checkbox-multiple`. |

## Migrated cases

| Case | Since | Templates |
|---|---|---|
| `input` | 1.11.0 | `fields/input.php`, `fields/input-hidden.php` |
| `textarea` | 1.11.0 | `fields/textarea.php` |
| `select` | 1.11.0 | `fields/select.php` |
| `radio` | 1.11.0 | `fields/radio.php` |
| `checkbox_multiple` | 1.11.0 | `fields/checkbox-multiple.php` (replaces `templates/frontend/inputs/checkbox_multiple.php`) |
| `message`, `function`, `awm_gallery` | 1.11.0 | `fields/message-box.php` (+ `fields/media.php` for the gallery) |
| `button`, `image`, `map` | 1.11.0 | `fields/button.php`, `fields/image.php` (+ `fields/media.php`), `fields/map.php` |
| `section`, `awm_tab` | 1.11.0 | `fields/section.php`, `fields/tabs.php` |
| `repeater` | 1.11.0 | `fields/repeater.php`, `fields/repeater-row.php`, `fields/repeater-actions.php` |
| `awm_modal`, `object_id_filter` | 1.11.0 | `fields/modal.php`, `fields/object-id-filter.php` |

`html` is still special-cased before preparation in `awm_show_content()` (it can replace the whole output with `strip`).

## Adding or replacing a type

```php
add_action( 'ewp_register_field_types', function ( \EWP\Fields\Field_Registry $registry ) {
	$registry->register( new My_Rating_Field() ); // implements \EWP\Fields\Field_Type, case_name() 'rating'
} );
```

Registering a built-in case name (e.g. `input`) replaces that type. To change only the markup, override the template in the theme instead (`extend-wp/fields/input.php`).

## Template rules

- Escape every value at output: `esc_attr()`, `esc_url()`, `wp_kses_post()`. Developer markup that may contain form elements or scripts (callback output, message bodies, `awm_radio_after_text`, `awm_show_actions_html`, nested `awm_show_content()` output) prints raw, with a `phpcs:ignore` saying why.
- Some templates keep inline handlers (`onclick`/`onkeypress` on tabs, repeater actions, map search) because the admin scripts call them; moving them to delegated listeners is a separate JS change.
- Emit no whitespace text nodes between elements. End lines with `?>`, which makes PHP drop the newline, and don't indent the line after it. The markup must stay identical to the legacy output; `tests/test-field-render-snapshot-test.php` checks this.

## Adding a built-in case

1. Cover it in `tests/test-field-render-snapshot-test.php` for every view.
2. Add `types/class-{case}-field.php` and its templates, require them in `init.php`, and register them in `Field_Registry::boot()`.
3. Run `composer test`. Any snapshot drift must be either fixed or intended. Rewrite snapshots with `EWP_UPDATE_SNAPSHOTS=1` and record the change in `CHANGELOG.md`.

## Loading

`init.php` is required by `library.php` itself rather than by `Setup.php`, because Composer may load `library.php` from another bundled copy of Extend WP. The first copy to load the engine wins (`class_exists` guard).
