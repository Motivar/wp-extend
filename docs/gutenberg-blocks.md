# Dynamic Gutenberg blocks

Extend WP registers server-rendered blocks from plain PHP arrays. A plugin
adds its blocks through `ewp_gutenburg_blocks_filter`; `EWP_Dynamic_Blocks`
(`includes/classes/ewp-gutenburg/class-register.php`) registers them with
WordPress on `init`, adds a preview route per block
(`GET {namespace}/{name}/preview`) and hands the editor script
(`src/index.js` → `build/index.js`) the sidebar controls.

```php
add_filter('ewp_gutenburg_blocks_filter', function ($blocks) {
    $blocks['my_slider'] = array(
        'namespace'       => 'myplugin',
        'name'            => 'slider',
        'title'           => __('My slider', 'my-plugin'),
        'icon'            => 'images-alt2',
        'category'        => 'design',
        'version'         => '1.0.0',
        'render_callback' => array($this, 'render'),
        'attributes'      => $this->fields(), // EWP field library
    );
    return $blocks;
});
```

## Cost model (read this first)

The filter runs **on every request**, front end included, because WordPress
needs the block types registered to render saved content. Keep the
definitions cheap:

| What | When it runs |
|---|---|
| The filter callback itself | every request, once, on `init` |
| A select's `callback` / the shorthand cases `postType`, `term`, `user`, `taxonomies`, `post_types`, `ewp_content`, `ewp_content_types`, `ewp_option_pages` | **only** when the block editor payload is built (`enqueue_block_editor_assets`), once per request |
| A select with `object_search` | never: the editor searches over REST as the user types |
| `render_callback` | when a saved block renders, and for the editor preview |

Since 1.5.7 `register_block_type()` receives only `type` and `default` per
attribute, so labels and options never reach the block type registry or
`/wp/v2/block-types`. Before 1.5.7 option callbacks ran during registration on
every request; a select listing every post of a large site made each admin
request (list screens, `admin-ajax.php`) take seconds.

## Attribute types

Each attribute is an EWP field (`case`), mapped to an editor control:

| Field | Editor control | Attribute type |
|---|---|---|
| `input` (`text`, `number`, `color`, `checkbox`) | text, range, colour picker, toggle | `string`, `number`, `string`, `boolean` |
| `textarea` (`wp_editor` for rich text) | textarea / RichText | `string` |
| `select` | select | `string` |
| `select` + `attributes.multiple` | token field | `array` |
| `select` + `object_search` | search-as-you-type token field | `array` when multiple, else `string` |
| `awm_gallery` / `image` | media pickers | `array` / `string` |

### Dependent selects: `filter_options_by`

An option with `filter_values` is only offered while the attribute named by
the field's `filter_options_by` holds one of those values
(`'@post_type'` follows the post type being edited). Values that stop being
offered are cleared.

### Large option lists: `object_search` (1.5.7)

For choices that can grow without bound (posts, terms, custom content rows),
add `object_search` to a select. The editor never receives a list: it calls
`GET extend-wp/v1/objects/search` with the typed text (debounced, `limit`
results) and, once, with `include=<saved ids>` to label what is saved.

```php
'post_ids' => array(
    'label'         => __('Posts', 'my-plugin'),
    'case'          => 'select',
    'attributes'    => array('multiple' => true),
    'object_search' => array(
        'type_from'   => 'post_type', // attribute holding the post type slug
        // or a fixed type: 'object_type' => 'post_type:page',
        'group'       => 'post_type', // post_type (default) | taxonomy | custom_content
        'limit'       => 20,
        'search_meta' => false,
    ),
    // Optional: a callback is still honoured by EWP < 1.5.7, which does not
    // know object_search. EWP 1.5.7+ never calls it for this field.
    'callback'      => array($this, 'post_options'),
),
```

- The stored value is the same as a multiple select: an array of id strings.
- When the `type_from` attribute changes, the selection is cleared.
- Searching needs the post type's `edit_posts` (taxonomy: `assign_terms`,
  custom content: `manage_options`), filterable through
  `awm_object_search_capability`; posts the user cannot read are left out.

## Tests

`tests/test-block-lazy-options-test.php` covers the lazy loading and the
editor payload; `tests/test-block-performance-test.php` (`@group
performance`) seeds 1,500 posts and fails `composer test` — and therefore
`git push` — when block registration on an admin request runs an option
callback or exceeds `EWP_PERF_REGISTER_MS` (150 ms), a page of 12 blocks
renders slower than `EWP_PERF_RENDER_MS` (500 ms), or the editor payload is
slower than `EWP_PERF_EDITOR_MS` (3000 ms).
