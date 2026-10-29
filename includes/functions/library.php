<?php

if (!defined('ABSPATH')) {
    exit;
}

/*OOP field renderers (EWP\Fields) used by awm_show_content()*/
require_once dirname(__DIR__) . '/classes/ewp-field-engine/init.php';

// Initialize global field config registry for encryption/decryption
if (!isset($GLOBALS['awm_field_configs'])) {
    $GLOBALS['awm_field_configs'] = array();
}

if (!function_exists('awm_safe_unserialize_array')) {
    /**
     * Stored section / repeater value as an array, unserialized without creating objects.
     *
     * Self-contained (no EWP\Serialization dependency) because Composer may load
     * this file from another bundled copy of Extend WP.
     *
     * @since 1.10.0
     *
     * @param mixed $value Required. Stored value (array or serialized string).
     * @return array The value as an array; anything else becomes an empty array.
     */
    function awm_safe_unserialize_array($value)
    {
        if (is_string($value) && is_serialized($value)) {
            $value = @unserialize(trim($value), array('allowed_classes' => false));
        }
        return is_array($value) ? $value : array();
    }
}

if (!function_exists('awm_show_explanation')) {
    /**
     * with this we show the explanation for the user
     * @param array $data the data for the box
     */
    function awm_show_explanation($data)
    {
        if (isset($data['explanation']) && !empty($data['explanation'])) {
            return '<div class="awm-box-explanation">' . __($data['explanation'], 'extend-wp') . '</div>';
        }
        return '';
    }
}

if (!function_exists('awm_get_field_value')) {
    /**
     * Read a field's stored value for a view (filtered by `awm_show_content_value_filter`).
     *
     * Wrapper around \EWP\Fields\Data\Field_Value_Reader::value().
     *
     * @param string           $view          widget|user|term|post|option|restrict_manage_posts.
     * @param array            $fields        The field definition (`widget-key` for view `widget`).
     * @param int|string|array $id            Post/term/user id, or the widget instance.
     * @param string           $original_meta The meta key.
     * @return mixed The value; 0 for an unknown view.
     */
    function awm_get_field_value($view, $fields, $id, $original_meta)
    {
        return \EWP\Fields\Data\Field_Value_Reader::value($view, $fields, $id, $original_meta);
    }
}

if (!function_exists('awm_get_raw_field_value')) {
    /**
     * Get raw field value from database without decryption hooks.
     * Used to check if a value is encrypted before masking.
     *
     * Wrapper around \EWP\Fields\Data\Field_Value_Reader::raw().
     *
     * @param string           $view          The view type (post, user, term, option).
     * @param int|string|array $id            The object ID.
     * @param string           $original_meta The meta key.
     * @return mixed Raw value from database.
     *
     * @since 1.0.0
     */
    function awm_get_raw_field_value($view, $id, $original_meta)
    {
        return \EWP\Fields\Data\Field_Value_Reader::raw($view, $id, $original_meta);
    }
}

if (!function_exists('awm_should_encrypt_field')) {
    /**
     * Check if a field should have encryption applied.
     *
     * Validates that the field has 'encrypt' => true and is a supported type
     * (input fields like text, password, email, url, etc.).
     *
     * @param array $field_config The field configuration array
     * @return bool True if field should be encrypted, false otherwise
     *
     * @since 1.3.0
     */
    function awm_should_encrypt_field($field_config)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->should_encrypt_field($field_config);
    }
}

if (!function_exists('awm_encrypt_field_value')) {
    /**
     * Encrypt a field value before saving to database.
     *
     * Skips encryption if value is empty, already encrypted, or is a masked value.
     * Applies 'ewp_encrypt_field_value' filter before encryption.
     *
     * @param string $value The plain-text value to encrypt
     * @param array $field_config The field configuration array
     * @return string Encrypted value or original value if skipped
     *
     * @since 1.3.0
     */
    function awm_encrypt_field_value($value, $field_config)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->encrypt_value($value, $field_config);
    }
}

if (!function_exists('awm_decrypt_field_value')) {
    /**
     * Decrypt a field value for display or editing.
     *
     * When displaying (for_display=true), returns masked value by default unless
     * show_masked is explicitly set to false.
     *
     * @param string $value The encrypted or plain value from database
     * @param array $field_config The field configuration array
     * @param bool $for_display Whether this is for display (true) or editing (false)
     * @return string Decrypted, masked, or original value
     *
     * @since 1.3.0
     */
    function awm_decrypt_field_value($value, $field_config, $for_display = true)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->decrypt_value($value, $field_config, $for_display);
    }
}

if (!function_exists('awm_meta_save_encryption')) {
    /**
     * How a field save must treat a value with respect to encryption.
     *
     * A key is encrypted when its field config (from the request-wide
     * `$awm_field_configs` registry) asks for it, or when the form posted its
     * `ewp_encrypt[]` marker. A masked value means the user left the field
     * untouched, so the stored ciphertext must be kept.
     *
     * @since 1.11.0
     *
     * @param string $meta_key Required. Meta key being saved.
     * @param mixed  $value    Required. Submitted (unslashed or slashed) value.
     * @param array  $markers  Optional. Keys posted in `ewp_encrypt[]`. Default empty.
     * @return array{0: string, 1: mixed} [`skip` (keep stored value), `write` (store the returned value)], and the value to store.
     */
    function awm_meta_save_encryption($meta_key, $value, array $markers = array())
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->meta_save_encryption($meta_key, $value, $markers);
    }
}

if (!function_exists('awm_encrypt_meta_on_save')) {
    /**
     * Encrypt meta values before they're saved to the database.
     * Formerly hooked into update_post_meta, update_user_meta, update_term_meta
     * (an action whose return value WordPress ignores, so nothing was encrypted).
     *
     * @deprecated 1.11.0 Use awm_meta_save_encryption(), called from the save path.
     *
     * @param mixed $meta_id The meta ID (only logged)
     * @param int $object_id The object ID (post, user, or term)
     * @param string $meta_key The meta key
     * @param mixed $meta_value The meta value to save
     * @return mixed The (possibly encrypted) meta value
     *
     * @since 1.0.0
     */
    function awm_encrypt_meta_on_save($meta_id, $object_id, $meta_key, $meta_value)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->encrypt_meta_on_save($meta_id, $object_id, $meta_key, $meta_value);
    }
}

if (!function_exists('awm_decrypt_meta_on_retrieve')) {
    /**
     * Decrypt meta values when they're retrieved from the database.
     * No longer registered on get_post_meta, get_user_meta, get_term_meta;
     * decryption is explicit at read time.
     *
     * @deprecated 1.11.0 Use awm_decrypt_field_value( $value, $field_config, false ).
     *
     * @param mixed $meta_value The meta value from the database
     * @param int $object_id The object ID (post, user, or term)
     * @param string $meta_key The meta key
     * @param bool $single Whether a single value was requested
     * @return mixed The (possibly decrypted) meta value
     *
     * @since 1.0.0
     */
    function awm_decrypt_meta_on_retrieve($meta_value, $object_id, $meta_key, $single)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->decrypt_meta_on_retrieve($meta_value, $object_id, $meta_key, $single);
    }
}

if (!function_exists('awm_parse_encryption_field_path')) {
    /**
     * Parse encryption field path to extract option name and field key.
     * Handles both bracket notation "option[field]" and simple field names.
     *
     * @param string $field_path Field path from ewp_encrypt[] marker
     * @return array|null Array with 'option' and 'field' keys, or null if invalid
     *
     * @since 1.0.0
     */
    function awm_parse_encryption_field_path($field_path)
    {
        return \EWP\Fields\Encryption\Option_Field_Path::parse($field_path);
    }
}

if (!function_exists('awm_should_encrypt_field_value')) {
    /**
     * Check if a field value should be encrypted.
     * Skips already encrypted, masked, or empty values.
     *
     * @param mixed $value Field value to check
     * @return bool True if value should be encrypted
     *
     * @since 1.0.0
     */
    function awm_should_encrypt_field_value($value)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->should_encrypt_value($value);
    }
}

if (!function_exists('awm_encrypt_option_field')) {
    /**
     * Encrypt a single field in an option array.
     *
     * @param array $option_value Current option value array
     * @param string $field_key Field key to encrypt
     * @param string $option_name Option name for validation
     * @param string|null $expected_option Expected option name (for bracket notation)
     * @return array|null Updated option value array, or null if field should be skipped
     *
     * @since 1.0.0
     */
    function awm_encrypt_option_field($option_value, $field_key, $option_name, $expected_option = null)
    {
        return \EWP\Fields\Encryption\Field_Encryption::instance()->encrypt_option_field($option_value, $field_key, $option_name, $expected_option);
    }
}

if (!function_exists('awm_encrypt_option_after_save')) {
    /**
     * Encrypt option values after they're saved to the database.
     * Uses hidden input markers (ewp_encrypt[]) to determine which fields to encrypt.
     * Hooks into updated_option action.
     *
     * @param string $option The option name
     * @param mixed $old_value The old option value
     * @param mixed $value The new option value
     *
     * @since 1.0.0
     */
    function awm_encrypt_option_after_save($option, $old_value, $value)
    {
        \EWP\Fields\Encryption\Field_Encryption::instance()->encrypt_option_after_save($option, $old_value, $value);
    }
}

if (!function_exists('awm_get_wp_editor_args')) {
    /**
     * Get default wp_editor arguments with filter support
     * 
     * @param string $textarea_name The name attribute for the textarea
     * @param string $editor_class CSS class for the editor
     * @param string $editor_id The ID for the editor instance
     * @return array Filtered wp_editor arguments
     */
    function awm_get_wp_editor_args($textarea_name, $editor_class = '', $editor_id = '')
    {
        $default_args = array(
            'textarea_name' => $textarea_name,
            'editor_class' => $editor_class,
            'textarea_rows' => 10,
            'wpautop' => true,
            'teeny' => false,
            'dfw' => false,
            'default_editor' => 'tinymce',
            // Do not force-show on init to avoid auto-scrolling to editor
            'init_instance_callback' => '',
            'tinymce' => array(
                'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
                'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                'content_css' => false,
                'forced_root_block' => 'p',
                'force_p_newlines' => true,
                'remove_linebreaks' => false,
                'convert_newlines_to_brs' => false,
                'remove_redundant_brs' => false,
                // Save handlers only; do not auto-show to prevent scroll jump
                'setup' => 'function(editor) {
                    editor.on("change", function(){ editor.save(); });
                    editor.on("blur", function(){ editor.save(); });
                }',
            ),
            'quicktags' => array(
                'buttons' => 'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close'
            ),
            'media_buttons' => true,
            'drag_drop_upload' => true
        );

        /**
         * Filter wp_editor arguments for AWM fields
         * 
         * Allows developers to customize wp_editor settings for all AWM textarea fields
         * with wp_editor enabled.
         * 
         * @param array  $default_args   Default wp_editor arguments
         * @param string $textarea_name  The textarea name attribute
         * @param string $editor_class   CSS class for the editor
         * @param string $editor_id      The editor ID
         * 
         * @return array Modified wp_editor arguments
         * 
         * @example
         * // Add custom TinyMCE settings
         * add_filter('awm_wp_editor_args', function($args, $name, $class, $id) {
         *     $args['tinymce']['toolbar1'] = 'bold,italic,link';
         *     $args['default_editor'] = 'tinymce';
         *     return $args;
         * }, 10, 4);
         */
        return apply_filters('awm_wp_editor_args', $default_args, $textarea_name, $editor_class, $editor_id);
    }
}

if (!function_exists('awm_prepare_field')) {
    /**
     * Prepare an EWP field for rendering, depending on its case.
     *
     * Shorthand cases (`postType`, `term`, `user`, `taxonomies`, …) become a
     * plain `select` (or their `view`) with a `callback` that lists the
     * choices. With `$resolve_options` the callback runs and fills `options`;
     * without it the callback stays on the field, unresolved, so a caller that
     * only needs the field's shape (block registration on every request) does
     * not query the options. awm_resolve_field_options() resolves it later.
     *
     * Wrapper around \EWP\Fields\Data\Field_Preparer::prepare().
     *
     * @param array  $a               The field's data.
     * @param string $awm_id          The id of the library.
     * @param bool   $resolve_options Optional. Run the field's options callback. Default true.
     * @return array The prepared field data.
     *
     * @since 1.5.8 Added `$resolve_options`; `ewp_content_types` and `ewp_option_pages` resolve through a callback.
     */
    function awm_prepare_field($a, $awm_id = '', $resolve_options = true)
    {
        return \EWP\Fields\Data\Field_Preparer::prepare($a, $awm_id, $resolve_options);
    }
}

if (!function_exists('awm_resolve_field_options')) {
    /**
     * Fill a prepared field's `options` from its `callback`.
     *
     * The callback runs with `callback_variables` as arguments when they are
     * set; when that returns nothing (or there are none) it runs again without
     * arguments. A field without a callback is returned unchanged.
     *
     * Wrapper around \EWP\Fields\Data\Field_Preparer::resolve_options().
     *
     * @param array $a Field data, as returned by awm_prepare_field().
     * @return array The field with `options` filled.
     *
     * @since 1.5.8
     */
    function awm_resolve_field_options($a)
    {
        return \EWP\Fields\Data\Field_Preparer::resolve_options($a);
    }
}

if (!function_exists('awm_ewp_content_type_options')) {
    /**
     * Options of an `ewp_content_types` field: the registered content types.
     *
     * Wrapper around \EWP\Fields\Data\Field_Preparer::content_type_options().
     *
     * @return array Content type id => definition (with `label`).
     *
     * @since 1.5.8 Extracted from awm_prepare_field().
     */
    function awm_ewp_content_type_options()
    {
        return \EWP\Fields\Data\Field_Preparer::content_type_options();
    }
}

if (!function_exists('awm_ewp_option_page_options')) {
    /**
     * Options of an `ewp_option_pages` field: the exportable options pages,
     * labelled with their field count.
     *
     * Wrapper around \EWP\Fields\Data\Field_Preparer::option_page_options().
     *
     * @return array Page id => array('label' => "Title (N fields)").
     *
     * @since 1.5.8 Extracted from awm_prepare_field().
     */
    function awm_ewp_option_page_options()
    {
        return \EWP\Fields\Data\Field_Preparer::option_page_options();
    }
}


if (!function_exists('awm_object_id_filter_config')) {
    /**
     * Normalize the configuration of an `object_id_filter` field.
     *
     * Reads the developer-supplied field array and fills in defaults so the
     * rest of the pipeline (render case, server-side preload, JS module) can
     * rely on a consistent shape.
     *
     * Wrapper around \EWP\Fields\Data\Object_Types::config().
     *
     * @param array  $a             The field definition.
     * @param string $original_meta The meta key the field is registered under.
     * @return array {
     *     @type array  $allowed_types  Subset of post_types|taxonomies|custom_content.
     *     @type array  $post_types     Explicit post type slugs (empty = all public).
     *     @type array  $taxonomies     Explicit taxonomy slugs (empty = all public).
     *     @type array  $custom_content Explicit custom content slugs (empty = all).
     *     @type string $id_field_name  Meta key for the selected IDs.
     *     @type int    $min_search_chars
     *     @type int    $max_results
     *     @type bool   $search_meta
     * }
     * @since 1.4.0
     */
    function awm_object_id_filter_config($a, $original_meta)
    {
        return \EWP\Fields\Data\Object_Types::config($a, $original_meta);
    }
}


if (!function_exists('awm_get_object_type_options')) {
    /**
     * Build the optgroup option structure for an `object_id_filter` type selector.
     *
     * Returns options keyed by `"{group}:{slug}"` plus an `optgroups` definition,
     * matching the structure the standard `select` case expects.
     *
     * Honors the field config: `allowed_types` limits the visible groups and the
     * per-group slug arrays (`post_types`, `taxonomies`, `custom_content`) limit
     * which entries appear inside each group. Results are cached per-config for
     * the current request.
     *
     * Wrapper around \EWP\Fields\Data\Object_Types::options(); fires
     * `awm_object_type_options` and `awm_object_type_options_{$field_name}`.
     *
     * @param array  $config     Normalized config (see awm_object_id_filter_config()).
     * @param string $field_name Field name, used for the per-field filter hook.
     * @return array Options array including an `optgroups` entry.
     * @since 1.4.0
     */
    function awm_get_object_type_options($config, $field_name = '')
    {
        return \EWP\Fields\Data\Object_Types::options($config, $field_name);
    }
}




if (!function_exists('awm_object_search_query')) {
    /**
     * Shared lookup for `object_id_filter` objects.
     *
     * Used both by the REST search endpoint and by the server-side preload that
     * renders already-selected options on edit screens. When `include` is passed
     * it fetches exactly those IDs (preload); otherwise it searches by title/name
     * (and meta when `search_meta` is true).
     *
     * Wrapper around \EWP\Fields\Data\Object_Search_Query::search(); fires
     * `awm_object_search_query_args`, `awm_object_search_max_limit` and
     * `awm_object_search_results`.
     *
     * @param string $group One of post_type|taxonomy|custom_content.
     * @param string $slug  The object slug (e.g. page, category, flx_booking).
     * @param string $search Search term (ignored when `include` is set).
     * @param array  $args {
     *     @type int   $limit       Max results. Default 20.
     *     @type bool  $search_meta Whether to also search meta values. Default true.
     *     @type array $exclude     IDs to exclude from search results.
     *     @type array $include     IDs to fetch directly (preload mode).
     * }
     * @return array List of `array('id' => int, 'label' => string, 'type' => "{group}:{slug}")`.
     * @since 1.4.0
     */
    function awm_object_search_query($group, $slug, $search, $args = array())
    {
        return \EWP\Fields\Data\Object_Search_Query::search($group, $slug, $search, $args);
    }
}


if (!function_exists('awm_show_content')) {
    /**
     * Render a field library (meta boxes, term/user fields, options pages, front-end forms).
     *
     * Thin wrapper around \EWP\Fields\Field_Renderer::render() (since 1.11.0); see
     * includes/classes/ewp-field-engine/README.md.
     *
     * @param array     $arrs       Field library (meta key => definition), optional `awm-id` container id.
     * @param int|array $id         Post/term/user id, 0, or the widget instance (view `widget`).
     * @param string    $view       post|term|user|option|widget|restrict_manage_posts|none.
     * @param string    $target     edit|display (passed to `awm_show_content_filter`).
     * @param bool      $label      Labels flag (passed to `awm_show_content_filter`).
     * @param string    $specific   Render only this field key, '' for all.
     * @param string    $sep        Separator for multi-value display (passed to the filter).
     * @param string    $context_id Context id, e.g. the options page key (modal fields).
     * @return string|array HTML; '' when nothing rendered; an empty array for an empty library.
     */
    function awm_show_content($arrs, $id = 0, $view = 'post', $target = 'edit', $label = true, $specific = '', $sep = ', ', $context_id = '')
    {
        return \EWP\Fields\Field_Renderer::instance()->render((array) $arrs, $id, $view, $target, $label, $specific, $sep, $context_id);
    }
}

/**
 * Render one row of a repeater field.
 *
 * @param int|string $i                Row index, or `template` for the JS row template.
 * @param string     $original_meta    Input name of the repeater.
 * @param array      $a                Repeater definition.
 * @param string     $original_meta_id Repeater element id.
 * @param array      $val              Stored rows.
 * @return string Row HTML.
 */
function awm_repeater_content($i, $original_meta, $a, $original_meta_id, $val)
{
    return (new \EWP\Fields\Types\Repeater_Field(new \EWP\Fields\Template_Loader()))->row($i, $original_meta, (array) $a, $original_meta_id, $val);
}


/**
 * Save the posted values of a field library to an object.
 *
 * @param array  $data     Meta keys to save.
 * @param array  $dataa    Posted values.
 * @param int    $id       Object ID.
 * @param string $view     Optional. post|term|user. Default post.
 * @param string $postType Optional. Post type or taxonomy. Default empty.
 * @return void
 */
function awm_save_custom_meta($data, $dataa, $id, $view = 'post', $postType = '')
{
    (new \EWP\Fields\Save\Meta_Saver())->save($data, $dataa, $id, $view, $postType);
}

if (!function_exists('awm_find_tranlsatable_fields')) {
    /**
     * with this function we look for auto translatable fields
     * @param string $metabox the metabox id
     * @param string $case the case
     * 
     * @return an array with all the keys
     */
    function awm_find_tranlsatable_fields($metabox, $case)
    {
        return (new \EWP\Fields\Save\Translation_Sync())->translatable_fields($metabox, $case);
    }
}

if (!function_exists('awm_field_hidden_in_current_language')) {
    /**
     * Whether a field is left out of the current request because it is
     * auto-translated (copied from the default language) and the admin is
     * working in a secondary WPML language.
     *
     * Used both when rendering a library (the field is not shown) and when an
     * options page registers its settings (the field is not registered, so
     * options.php does not overwrite the stored value with null on save).
     *
     * @param array $field Field definition from a library. Required. The field
     *                     is affected when it has an `auto_translate` key.
     * @return bool True when WPML is active, the current language differs from
     *              the default one and the field has `auto_translate`.
     */
    function awm_field_hidden_in_current_language($field)
    {
        return (new \EWP\Fields\Save\Translation_Sync())->hidden_in_current_language($field);
    }
}

/**
 * Copy auto-translated fields from an object to its WPML translations.
 *
 * @param array  $data  Meta keys saved.
 * @param array  $dataa Posted values.
 * @param int    $id    Object ID.
 * @param string $view  post|term|user.
 * @return void
 */
function awm_auto_translate($data, $dataa, $id, $view)
{
    (new \EWP\Fields\Save\Translation_Sync())->auto_translate($data, $dataa, $id, $view);
}


/**
 * Write each posted meta key to the object, encrypting flagged fields.
 *
 * @param array  $meta  Meta keys to save.
 * @param array  $metaa Posted values.
 * @param int    $id    Object ID.
 * @param string $view  post|term|user.
 * @return void
 */
function awm_custom_meta_update_vars($meta, $metaa, $id, $view)
{
    return (new \EWP\Fields\Save\Meta_Saver())->update_vars($meta, $metaa, $id, $view);
}


/**
 * Legacy single-image uploader field.
 *
 * @deprecated 2.0.0 Use awm_media_field_html() instead.
 *
 * @param string $name     Input name attribute.
 * @param string $id       Input id attribute.
 * @param string $value    Current attachment ID.
 * @param bool   $multiple Whether multiple selection is allowed (ignored — use awm_gallery for multi).
 * @param string $required Required attribute string.
 * @return string HTML output.
 */
function awm_custom_image_image_uploader_field($name, $id, $value = '', $multiple = false, $required = '')
{
    _deprecated_function(__FUNCTION__, '2.0.0', 'awm_media_field_html');
    return awm_media_field_html($name, $value, 1);
}




if (!function_exists('awm_translated_ids')) {
    /**
     * get translations ids based on wpml code
     * @param  int $content_id the post_id,$term_id
     * @param string $case the cse to use
     */
    function awm_translated_ids($post_id, $case)
    {
        return (new \EWP\Fields\Save\Translation_Sync())->translated_ids($post_id, $case);
    }
}

/**
 * Display value of a field (list columns, read-only views).
 *
 * Wrapper around \EWP\Fields\Data\Field_Value_Formatter::display(); fires
 * `awm_display_meta_value_filter`.
 *
 * @param string $meta           Meta key.
 * @param array  $data           Field definition.
 * @param int    $postId         Optional. Object ID. Default 0.
 * @param mixed  $external_value Optional. Value to show instead of the stored one. Default false.
 * @param string $case           Optional. post_type|term|user. Default post_type.
 * @return string HTML.
 */
function awm_display_meta_value($meta, $data, $postId = 0, $external_value = false, $case = 'post_type')
{
    return \EWP\Fields\Data\Field_Value_Formatter::display($meta, $data, $postId, $external_value, $case);
}



/**
 * this funciton is used to creat a form for the fields we add
 * @param array $data all the data needed
 */
function awm_create_form($options)
{

    $defaults = array(
        'library' => '',
        'id' => '',
        'method' => 'post',
        'action' => '',
        'submit' => true,
        'submit_label' => __('Register', 'awm'),
        'nonce' => true
    );

    $settings = array_merge($defaults, $options);
    $library = $settings['library'];

    ob_start();
?>
<form id="<?php echo esc_attr($settings['id']); ?>" action="<?php echo esc_url($settings['action']); ?>" method="<?php echo esc_attr($settings['method']); ?>">
 <?php
        if ($settings['nonce']) {
            wp_nonce_field($settings['id'], 'awm_form_nonce_field');
        }
        ?>
 <?php $form_fields = awm_show_content($library); echo is_string($form_fields) ? $form_fields : ''; ?>
 <?php if ($settings['submit']) {
        ?>
 <input type="submit" id="awm-submit-<?php echo $settings['id'] ?>" value="<?php echo $settings['submit_label']; ?>" />
 <?php
        }
        ?>
</form>
<?php
    $content = ob_get_contents();
    ob_end_clean();
    return $content;
}



if (!function_exists('awm_callback_library')) {
    /**
     * get all library data
     * @param array $library the data to display
     * @param string $id the id of the library
     */
    function awm_callback_library($library, $id)
    {
        /**
         * Filter the field library returned for a library id.
         *
         * @since 1.0.0
         *
         * @param array  $library Field library.
         * @param string $id      Library id.
         */
        return apply_filters('awm_show_content_fields_filter', $library, $id);
    }
}


if (!function_exists('awm_callback_library_options')) {
    /**
     * return options library
     * @param array $library the library either for the metas or the options
     */
    function awm_callback_library_options($library)
    {
        if (isset($library['library'])) {
            return $library['library'];
        }
        if (isset($library['options']) && !empty($library['options'])) {
            return $library['options'];
        }

        if (!isset($library['library'])) {

            if (isset($library['callback'])) {
                if (!((is_string($library['callback']) && function_exists($library['callback'])) || (is_array($library['callback']) && method_exists($library['callback'][0], $library['callback'][1])))) {
                    return '';
                }
                $callback_options = array();
                if (!empty($library['callback_variables'])) {
                    $callback_options = call_user_func_array($library['callback'], $library['callback_variables']);
                }
                $library = empty($callback_options) ? call_user_func($library['callback']) : $callback_options;
                return $library;
            }
        }
        return '';
    }
}


/**
 * Unified media field HTML for single image and gallery modes.
 *
 * Renders a container with image previews, remove buttons, hidden inputs,
 * and an add/select button. The JavaScript class AWMMediaField handles
 * the wp.media frame, pre-selection, sorting, and removal.
 *
 * @param string    $meta          The meta key / field name used for input names.
 * @param mixed     $val           Current value — single attachment ID or array of IDs.
 * @param int       $max_images    Maximum number of images (0 = unlimited gallery, 1 = single image).
 * @param string    $allowed_types Comma-separated MIME types for the media library filter.
 *                                 Use 'image' for images only (gallery default),
 *                                 or empty string '' for all file types (image default).
 * @since 1.0.0
 * @return string   HTML output.
 *
 * @example
 * // Gallery (unlimited, images only)
 * echo awm_media_field_html('ewp_gallery', $gallery_ids, 0, 'image');
 *
 * // Single file (any type)
 * echo awm_media_field_html('my_file', $file_id, 1, '');
 *
 * // Single image only
 * echo awm_media_field_html('my_image', $image_id, 1, 'image');
 */
function awm_media_field_html($meta, $val, $max_images = 0, $allowed_types = '')
{
    return (new \EWP\Fields\Media_Field_Renderer())->render($meta, $val, $max_images, $allowed_types);
}


/**
 * Legacy gallery meta box HTML wrapper.
 *
 * @deprecated 2.0.0 Use awm_media_field_html() instead.
 *
 * @param string $meta Meta key.
 * @param mixed  $val  Array of image IDs.
 * @return string HTML output.
 */
function awm_gallery_meta_box_html($meta, $val)
{
    _deprecated_function(__FUNCTION__, '2.0.0', 'awm_media_field_html');
    return awm_media_field_html($meta, $val, 0);
}


/**
 * REST permission callback: the user can manage options.
 *
 * @return bool
 */
function ewp_rest_check_user_is_admin()
{
    return current_user_can('manage_options');
}

/**
 * Normalise quotes in repeater values, recursively.
 *
 * @param mixed $value Value or array of values.
 * @return mixed
 */
function awm_repeater_check_quotes($value)
{
    if (is_array($value)) {
        foreach ($value as $key => &$val) {
            $val = awm_repeater_check_quotes($val);
        }
        return $value;
    }
    return str_replace('"', '&quot;', $value);
}