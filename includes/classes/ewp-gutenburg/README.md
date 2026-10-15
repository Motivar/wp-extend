# EWP Dynamic Blocks

Based on the provided PHP class `EWP_Dynamic_Blocks`, which extends Gutenberg block functionality with dynamic registration and REST API extensions, I will create a README.md file content that outlines how developers can use the `ewp_gutenburg_blocks_filter` to add custom Gutenberg blocks through your plugin. The README will cover the basics of using the filter, expected block array structure, and a simple example.


EWP Dynamic Blocks provides a framework for dynamically registering custom Gutenberg blocks and corresponding REST API endpoints in WordPress. This allows developers to easily extend Gutenberg with custom functionality, ensuring a secure and optimized manner of block registration and usage.

## How to Add a Custom Block

To add a custom Gutenberg block using EWP Dynamic Blocks, you need to utilize the `ewp_gutenburg_blocks_filter` filter. This filter allows you to add your block specifications to the list of blocks that EWP Dynamic Blocks manages.

### Using `ewp_gutenburg_blocks_filter`

To register your custom blocks, attach them to the `ewp_gutenburg_blocks_filter` filter provided by the EWP Dynamic Blocks plugin. Here's the basic structure you should follow:

```php
function my_custom_blocks($blocks) {
    $blocks['my_custom_block'] = array(
        'namespace' => 'my-namespace',
        'name' => 'my-custom-block',
        'title' => __('My Custom Block', 'text-domain'),
        'description' => __('A description of what my custom block does.', 'text-domain'),
        'category' => 'common',
        'icon' => 'admin-site',
        'script'=>'',
        'style'=>'',
        'attributes' => array(
            // Define attributes here
        ),
        'render_callback' => 'my_custom_block_render_callback',
        'version' => '1.0.0',
        'dependencies' => array('wp-blocks', 'wp-element'), // Additional script dependencies
    );

    return $blocks;
}
add_filter('ewp_gutenburg_blocks_filter', 'my_custom_blocks');
```

### Block Array Structure

- `namespace` and `name`: Unique identifiers for your block.
- `title`: The display name of your block.
- `description`: A brief description of what your block does.
- `category`: The category under which your block should appear.
- `style`: The style to use in admin & frontend. You should use the name of th handle of the style.
- `script`: The script to use in admin & frontend. You should use the name of th handle of the script.
- `icon`: Dashicon to use as the block icon.
- `attributes`: An array of attributes your block uses. Each attribute should specify its type and default value. The array is based on the awm inputs array. You can either add a callback (name of a function) or a function.
- `render_callback`: The function name that will be called to render the block on the front end.
- `version`: The version of your block.
- `dependencies`: An array of script dependencies required by your block.

### Select attributes in the block sidebar

A `select` attribute renders a `SelectControl` in the block sidebar. Three optional keys (since 1.5.2) make it react to the rest of the block and to the editor. They apply to block sidebars only; options pages and meta boxes ignore them.

**Dependent options: `filter_options_by` + `filter_values`.** Set `filter_options_by` to another attribute key and give options a `filter_values` array. An option is offered only while that attribute holds one of its values; options without `filter_values` (e.g. an empty "Select…" entry) are always offered. When the other attribute changes, a stored value that is no longer offered is reset in the same update, and the reset continues down the chain (post type → taxonomy → term).

```php
'post_type' => array(
    'label' => __('Post type', 'text-domain'),
    'case' => 'select',
    'options' => array(
        'post' => array('label' => __('Posts', 'text-domain')),
        'page' => array('label' => __('Pages', 'text-domain')),
    ),
),
'taxonomy' => array(
    'label' => __('Taxonomy', 'text-domain'),
    'case' => 'select',
    'filter_options_by' => 'post_type',
    'options' => array(
        ''         => array('label' => __('Select taxonomy', 'text-domain')),
        'category' => array('label' => __('Categories', 'text-domain'), 'filter_values' => array('post')),
        'post_tag' => array('label' => __('Tags', 'text-domain'), 'filter_values' => array('post')),
    ),
),
```

**Options that follow the edited post type: `filter_options_by => '@post_type'`.** `filter_values` then lists post types, and the sidebar offers the options matching the post type being edited. In the post editor that is the post's own type. In the Site Editor it comes from the template slug (`single-{type}`, `single-{type}-{slug}`, `archive-{type}`) and follows template navigation without a reload. Where the post type cannot be told (a page or index template, a template part, a pattern), every option is offered. Values starting with `@` name the editor context, not an attribute.

**Multiple selects: `'attributes' => array('multiple' => true)`.** The attribute is typed `array` (an array of option values, as strings). Since 1.5.3 the control is a `FormTokenField`: chosen options show as removable chips, and focusing the field opens a list of the remaining options that narrows as you type, like the SlimSelect multiselect on classic fields. Only offered options can be added; free text is rejected. Options that share a label show their value too ("Test (#12)"), and a stored value with no matching option shows as its raw value. Dependent filtering keeps only the items still offered. The editor preview request sends arrays as a comma-separated string, so a render callback must accept both shapes:

```php
$ids = is_array($attributes['post_ids']) ? $attributes['post_ids'] : explode(',', (string) $attributes['post_ids']);
```

A filtered select always lists the option holding its stored value, so the control shows what is saved. Stored values are never cleared on load, only when the attribute they depend on changes.

### Example

```php
function my_custom_block_render_callback($attributes) {
    // Render your block's HTML here
    return '<div class="my-custom-block">Hello, World!</div>';
}
```

### To run js code on change attributes
```js
document.addEventListener('ewp_dynamic_block_on_change', function (e) {
if (e.detail.block.namespace == 'my-namespace' && e.detail.block.name=='my-custom-block') {
 /*run your code*/
 }
});
```

This README provides a starting point for developers to integrate custom Gutenberg blocks into their WordPress site using the EWP Dynamic Blocks plugin. Remember to replace placeholders like `my_custom_blocks`, `my_custom_block_render_callback`, and `'text-domain'` with your specific values.
