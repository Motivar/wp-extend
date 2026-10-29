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
     * get awm value for fields
     * @param string $view the view to user
     * @param array $fields all the fields
     * @paramint|string|array $id the post_id,term_id
     * @param string $original_meta the meta which used
     */
    function awm_get_field_value($view, $fields, $id, $original_meta)
    {
        switch ($view) {
            case 'widget':
                $val = isset($id[$fields['widget-key']]) ? $id[$fields['widget-key']] : '';
                break;
            case 'user':
                $val = get_user_meta($id, $original_meta, true) ?: '';
                break;
            case 'term':
                $val = get_term_meta($id, $original_meta, true) ?: '';
                break;
            case 'post':
                $val = get_post_meta($id, $original_meta, true) ?: '';
                break;
            case 'option':
                $val = get_option($original_meta) ?: '';
                break;
            case 'restrict_manage_posts':
                $val = isset($_GET[$original_meta]) ? $_GET[$original_meta] : '';
                break;
            default:
                $val = 0;
                break;
        }
        /**
         * Filter a field value read for display or editing.
         *
         * @since 1.0.0
         *
         * @param mixed  $val           Stored value.
         * @param int    $id            Object ID.
         * @param string $original_meta Meta key.
         * @param string $view          post|term|user|option|restrict_manage_posts.
         */
        $val = apply_filters('awm_show_content_value_filter', $val, $id, $original_meta, $view);
        return $val;
    }
}

if (!function_exists('awm_get_raw_field_value')) {
    /**
     * Get raw field value from database without decryption hooks.
     * Used to check if a value is encrypted before masking.
     *
     * @param string $view the view type (post, user, term, option)
     * @param int|string|array $id the object ID
     * @param string $original_meta the meta key
     * @return mixed Raw value from database
     *
     * @since 1.0.0
     */
    function awm_get_raw_field_value($view, $id, $original_meta)
    {
        switch ($view) {
            case 'user':
                return get_user_meta($id, $original_meta, true) ?: '';
            case 'term':
                return get_term_meta($id, $original_meta, true) ?: '';
            case 'post':
                return get_post_meta($id, $original_meta, true) ?: '';
            case 'option':
                return get_option($original_meta) ?: '';
            default:
                return '';
        }
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
        // Check if encryption is explicitly enabled
        if (!isset($field_config['encrypt']) || !$field_config['encrypt']) {
            return false;
        }

        // Only support input field types (not repeater, select, textarea, etc.)
        if (!isset($field_config['case']) || $field_config['case'] !== 'input') {
            return false;
        }

        // Validate input type is supported
        $supported_types = array('text', 'password', 'email', 'url', 'number');
        $input_type = isset($field_config['type']) ? $field_config['type'] : 'text';

        return in_array($input_type, $supported_types, true);
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
        // Skip empty values
        if (empty($value)) {
            return $value;
        }

        // Skip if already encrypted
        if (strpos($value, 'ewp_enc:') === 0) {
            return $value;
        }

        // Skip if value is a masked placeholder (user didn't change it)
        if (EWP_Encryption::is_masked($value)) {
            return $value;
        }

        // Get algorithm from field config or use default
        $algorithm = isset($field_config['encrypt_algorithm']) ? $field_config['encrypt_algorithm'] : '';

        // Apply filter before encryption
        /**
         * Filter a field value before it is encrypted.
         *
         * @since 1.0.0
         *
         * @param mixed $value        Plain value.
         * @param array $field_config Field definition (encrypt settings).
         */
        $value = apply_filters('ewp_encrypt_field_value', $value, $field_config);

        // Encrypt the value
        return EWP_Encryption::encrypt($value, $algorithm);
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
        // Skip empty values
        if (empty($value)) {
            return $value;
        }

        // Get algorithm from field config or use default
        $algorithm = isset($field_config['encrypt_algorithm']) ? $field_config['encrypt_algorithm'] : '';

        // Decrypt the value
        $decrypted = EWP_Encryption::decrypt($value, $algorithm);

        // For display, show masked value by default
        if ($for_display) {
            $show_masked = isset($field_config['show_masked']) ? $field_config['show_masked'] : true;

            if ($show_masked) {
                return EWP_Encryption::mask($value, $algorithm);
            }
        }

        // Apply filter after decryption
        /**
         * Filter a field value after it is decrypted.
         *
         * @since 1.0.0
         *
         * @param mixed $decrypted    Decrypted value.
         * @param array $field_config Field definition (encrypt settings).
         */
        $decrypted = apply_filters('ewp_decrypt_field_value', $decrypted, $field_config);

        return $decrypted;
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
        global $awm_field_configs;
        $config = isset($awm_field_configs[$meta_key]) ? (array) $awm_field_configs[$meta_key] : array();
        if (!is_string($value) || (!awm_should_encrypt_field($config) && !in_array($meta_key, $markers, true))) {
            return array('write', $value);
        }
        if (EWP_Encryption::is_masked($value)) {
            return array('skip', $value);
        }
        return array('write', awm_encrypt_field_value($value, $config));
    }
}

if (!function_exists('awm_encrypt_meta_on_save')) {
    /**
     * Encrypt meta values before they're saved to the database.
     * Hooks into update_post_meta, update_user_meta, update_term_meta.
     *
     * @param mixed $meta_id The meta ID (return value, not used for filtering)
     * @param int $object_id The object ID (post, user, or term)
     * @param string $meta_key The meta key
     * @param mixed $meta_value The meta value to save
     * @return mixed The (possibly encrypted) meta value
     *
     * @since 1.0.0
     */
    function awm_encrypt_meta_on_save($meta_id, $object_id, $meta_key, $meta_value)
    {
        global $awm_field_configs;

        // Get field config if available
        $field_config = isset($awm_field_configs[$meta_key]) ? $awm_field_configs[$meta_key] : array();

        // Check if field should be encrypted (config OR POST marker)
        $encrypt_fields = isset($_POST['ewp_encrypt']) ? (array)$_POST['ewp_encrypt'] : [];
        $should_encrypt = awm_should_encrypt_field($field_config) || in_array($meta_key, $encrypt_fields, true);

        if (!$should_encrypt) {
            return $meta_value;
        }

        // Don't encrypt if value is already encrypted
        if (EWP_Encryption::is_encrypted($meta_value)) {
            return $meta_value;
        }

        // Don't encrypt if value is masked (user didn't change it)
        if (EWP_Encryption::is_masked($meta_value)) {
            return $meta_value;
        }

        // Encrypt the value
        if (!empty($meta_value)) {
            $encrypted_value = awm_encrypt_field_value($meta_value, $field_config);

            // Log encryption event
            if (function_exists('ewp_log')) {
                ewp_log(
                    'extend-wp',
                    'field_encrypted',
                    sprintf('Meta field "%s" encrypted for object ID %d', $meta_key, $object_id),
                    [
                        'meta_key' => $meta_key,
                        'object_id' => $object_id,
                        'meta_id' => $meta_id,
                        'value_length' => strlen($meta_value),
                    ],
                    'developer',
                    '',
                    1
                );
            }

            return $encrypted_value;
        }

        return $meta_value;
    }
}

if (!function_exists('awm_decrypt_meta_on_retrieve')) {
    /**
     * Decrypt meta values when they're retrieved from the database.
     * Hooks into get_post_meta, get_user_meta, get_term_meta.
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
        global $awm_field_configs;

        // Get field config if available
        $field_config = isset($awm_field_configs[$meta_key]) ? $awm_field_configs[$meta_key] : array();

        // Check if field should be decrypted
        if (!awm_should_encrypt_field($field_config)) {
            return $meta_value;
        }

        // Decrypt the value (not for display, return plain text)
        if (!empty($meta_value)) {
            return awm_decrypt_field_value($meta_value, $field_config, false);
        }

        return $meta_value;
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
        if (strpos($field_path, '[') !== false) {
            // Parse bracket notation: "provider_config[openai_api_key]"
            preg_match('/^([^\[]+)\[([^\]]+)\]$/', $field_path, $matches);

            if (count($matches) !== 3) {
                return null; // Invalid format
            }

            return [
                'option' => $matches[1],
                'field'  => $matches[2],
            ];
        }

        // Simple field name (no brackets)
        return [
            'option' => null,
            'field'  => $field_path,
        ];
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
        // Skip if already encrypted
        if (EWP_Encryption::is_encrypted($value)) {
            return false;
        }

        // Skip if masked (user didn't change it)
        if (EWP_Encryption::is_masked($value)) {
            return false;
        }

        // Skip empty values
        if ('' === $value) {
            return false;
        }

        return true;
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
        // Validate option name matches (for bracket notation)
        if ($expected_option !== null && $expected_option !== $option_name) {
            return null;
        }

        // Check if this is an array option with the nested field
        if (!is_array($option_value) || !isset($option_value[$field_key])) {
            return null;
        }

        $raw = $option_value[$field_key];

        // Check if value should be encrypted
        if (!awm_should_encrypt_field_value($raw)) {
            return null;
        }

        // Encrypt the field
        $option_value[$field_key] = EWP_Encryption::encrypt($raw);

        // Log encryption event
        if (function_exists('ewp_log')) {
            ewp_log(
                'extend-wp',
                'field_encrypted',
                sprintf('Option field "%s[%s]" encrypted', $option_name, $field_key),
                [
                    'option_name' => $option_name,
                    'field_key' => $field_key,
                    'value_length' => strlen($raw),
                ],
                'developer',
                '',
                1
            );
        }

        return $option_value;
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
        // Only process if this is an EWP options page save
        if (!isset($_POST['awm_metabox_case']) || $_POST['awm_metabox_case'] !== 'option') {
            return;
        }

        // Get fields marked for encryption
        $encrypt_fields = isset($_POST['ewp_encrypt']) ? (array)$_POST['ewp_encrypt'] : [];

        if (empty($encrypt_fields)) {
            return;
        }

        // Get the current option value
        $current_value = get_option($option);
        $updated = false;

        foreach ($encrypt_fields as $field_path) {
            // Parse field path to extract option name and field key
            $parsed = awm_parse_encryption_field_path($field_path);

            if ($parsed === null) {
                continue;
            }

            // Encrypt the field
            $result = awm_encrypt_option_field(
                $current_value,
                $parsed['field'],
                $option,
                $parsed['option']
            );

            if ($result !== null) {
                $current_value = $result;
                $updated = true;
            }
        }

        // Update the option with encrypted values (avoid infinite loop by removing action)
        if ($updated) {
            remove_action('updated_option', 'awm_encrypt_option_after_save', 10);
            update_option($option, $current_value);
            add_action('updated_option', 'awm_encrypt_option_after_save', 10, 3);
        }
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
     * @param array  $a               The field's data.
     * @param string $awm_id          The id of the library.
     * @param bool   $resolve_options Optional. Run the field's options callback. Default true.
     * @return array The prepared field data.
     *
     * @since 1.5.8 Added `$resolve_options`; `ewp_content_types` and `ewp_option_pages` resolve through a callback.
     */
    function awm_prepare_field($a, $awm_id = '', $resolve_options = true)
    {

        switch ($a['case']) {
            case 'ewp_content_types':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $a['callback'] = 'awm_ewp_content_type_options';
                break;
            case 'ewp_option_pages':
                /**
                 * Custom field case that resolves option page choices at render time,
                 * avoiding recursion during field definition (options_boxes() cycle).
                 *
                 * @since 1.1.3
                 */
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $a['callback'] = 'awm_ewp_option_page_options';
                break;
            case 'ewp_content':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmEwpContentForInput';
                $a['callback_variables'] = array($a['content_type'], $args);
                break;
            case 'taxonomies':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmTaxObjectsForInput';
                $a['callback_variables'] = array($args);
                break;
            case 'post_types':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmPostObjectsForInput';
                $a['callback_variables'] = array($args);
                break;
            case 'postType':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmPostFieldsForInput';
                $a['callback_variables'] = array($a['post_type'], $number, $args);
                break;
            case 'term':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';

                $args = isset($a['args']) ? $a['args'] : array();
                $option_key = isset($a['option_key']) ? $a['option_key'] : 'term_id';
                $a['callback'] = 'awmTaxonomyFieldsForInput';
                $show_all = isset($a['show_all']) ? $a['show_all'] : false;
                $a['callback_variables'] = array($a['taxonomy'], $number,  $args, $option_key, $awm_id, $show_all);
                break;
            case 'user':
                $roles = isset($a['roles']) ? $a['roles'] : array();
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $number = isset($a['number']) ? $a['number'] : '-1';
                $args = isset($a['args']) ? $a['args'] : array();
                $a['callback'] = 'awmUserFieldsForInput';
                $a['callback_variables'] = array($roles, $number, $args);
                break;
            case 'user_roles':
                $a['case'] = isset($a['view']) ? $a['view'] : 'select';
                $exclude = isset($a['exclude']) ? $a['exclude'] : array();
                $a['callback'] = 'awmUserRolesFieldsForInput';
                $a['callback_variables'] = array($exclude);
                break;
            case 'date':
                $a['case'] = 'input';
                $a['type'] = 'text';
                $a['class'][] = 'awm_cl_date';
                if (isset($a['date-params'])) {
                    $a['attributes']['date-params'] = str_replace('"', '\'', json_encode(apply_filters('awm_cl_date_params', $a['date-params'], $a)));
                }
                break;
            default:
                break;
        }

        return $resolve_options ? awm_resolve_field_options($a) : $a;
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
     * @param array $a Field data, as returned by awm_prepare_field().
     * @return array The field with `options` filled.
     *
     * @since 1.5.8
     */
    function awm_resolve_field_options($a)
    {
        if (!isset($a['callback'])) {
            return $a;
        }
        $callback_options = array();
        if (!empty($a['callback_variables'])) {
            $callback_options = call_user_func_array($a['callback'], $a['callback_variables']);
        }
        $a['options'] = empty($callback_options) ? call_user_func($a['callback']) : $callback_options;
        return $a;
    }
}

if (!function_exists('awm_ewp_content_type_options')) {
    /**
     * Options of an `ewp_content_types` field: the registered content types.
     *
     * @return array Content type id => definition (with `label`).
     *
     * @since 1.5.8 Extracted from awm_prepare_field().
     */
    function awm_ewp_content_type_options()
    {
        return AWM_Content_DB::get_instance()->get_content_types();
    }
}

if (!function_exists('awm_ewp_option_page_options')) {
    /**
     * Options of an `ewp_option_pages` field: the exportable options pages,
     * labelled with their field count.
     *
     * @return array Page id => array('label' => "Title (N fields)").
     *
     * @since 1.5.8 Extracted from awm_prepare_field().
     */
    function awm_ewp_option_page_options()
    {
        $opts = array();
        foreach (\EWP_Options_Portability::instance()->get_exportable_pages() as $pk => $pd) {
            $cnt = isset($pd['field_count']) ? $pd['field_count'] : count($pd['fields']);
            $opts[$pk] = array(
                'label' => sprintf('%s (%d %s)', $pd['title'], $cnt, _n('field', 'fields', $cnt, 'extend-wp')),
            );
        }
        return $opts;
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
        return array(
            'allowed_types'    => isset($a['allowed_types']) && is_array($a['allowed_types']) ? $a['allowed_types'] : array('post_types', 'taxonomies', 'custom_content'),
            'post_types'       => isset($a['post_types']) && is_array($a['post_types']) ? $a['post_types'] : array(),
            'taxonomies'       => isset($a['taxonomies']) && is_array($a['taxonomies']) ? $a['taxonomies'] : array(),
            'custom_content'   => isset($a['custom_content']) && is_array($a['custom_content']) ? $a['custom_content'] : array(),
            'id_field_name'    => isset($a['id_field_name']) && !empty($a['id_field_name']) ? $a['id_field_name'] : $original_meta . '_ids',
            'min_search_chars' => isset($a['min_search_chars']) ? absint($a['min_search_chars']) : 2,
            'max_results'      => isset($a['max_results']) ? absint($a['max_results']) : 20,
            'search_meta'      => isset($a['search_meta']) ? (bool) $a['search_meta'] : true,
        );
    }
}


if (!function_exists('awm_get_object_type_options')) {
    /**
     * Build the optgroup option structure for an `object_id_filter` type selector.
     *
     * Returns options keyed by `"{group}:{slug}"` plus an `optgroups` definition,
     * matching the structure the standard `select` case expects
     * (see awm_show_content() select rendering).
     *
     * Honors the field config: `allowed_types` limits the visible groups and the
     * per-group slug arrays (`post_types`, `taxonomies`, `custom_content`) limit
     * which entries appear inside each group. Results are cached per-config for
     * the current request.
     *
     * @param array  $config     Normalized config (see awm_object_id_filter_config()).
     * @param string $field_name Field name, used for the per-field filter hook.
     * @return array Options array including an `optgroups` entry.
     * @since 1.4.0
     */
    function awm_get_object_type_options($config, $field_name = '')
    {
        static $cache = array();
        $cache_key = md5(serialize($config));
        if (isset($cache[$cache_key])) {
            $options = $cache[$cache_key];
        } else {
            $options   = array();
            $optgroups = array();

            $allowed = $config['allowed_types'];

            if (in_array('post_types', $allowed, true)) {
                $optgroups['post_type'] = array('label' => __('Post Types', 'extend-wp'));
                $post_types = get_post_types(array('public' => true), 'objects');
                foreach ($post_types as $slug => $obj) {
                    if (!empty($config['post_types']) && !in_array($slug, $config['post_types'], true)) {
                        continue;
                    }
                    $options['post_type:' . $slug] = array(
                        'label'    => sprintf('%s (%s)', $obj->labels->singular_name, $slug),
                        'optgroup' => 'post_type',
                    );
                }
            }

            if (in_array('taxonomies', $allowed, true)) {
                $optgroups['taxonomy'] = array('label' => __('Taxonomies', 'extend-wp'));
                $taxonomies = get_taxonomies(array('public' => true), 'objects');
                foreach ($taxonomies as $slug => $obj) {
                    if (!empty($config['taxonomies']) && !in_array($slug, $config['taxonomies'], true)) {
                        continue;
                    }
                    $options['taxonomy:' . $slug] = array(
                        'label'    => sprintf('%s (%s)', $obj->labels->singular_name, $slug),
                        'optgroup' => 'taxonomy',
                    );
                }
            }

            if (in_array('custom_content', $allowed, true) && class_exists('AWM_Content_DB')) {
                $optgroups['custom_content'] = array('label' => __('Custom Content', 'extend-wp'));
                $content_types = AWM_Content_DB::get_instance()->get_content_types();
                if (is_array($content_types)) {
                    foreach ($content_types as $slug => $data) {
                        if (!empty($config['custom_content']) && !in_array($slug, $config['custom_content'], true)) {
                            continue;
                        }
                        $label = is_array($data) && isset($data['label']) ? $data['label'] : $slug;
                        $options['custom_content:' . $slug] = array(
                            'label'    => sprintf('%s (%s)', $label, $slug),
                            'optgroup' => 'custom_content',
                        );
                    }
                }
            }

            // Drop empty optgroups so the selector stays tidy.
            foreach (array_keys($optgroups) as $group_id) {
                $has_option = false;
                foreach ($options as $opt) {
                    if (isset($opt['optgroup']) && $opt['optgroup'] === $group_id) {
                        $has_option = true;
                        break;
                    }
                }
                if (!$has_option) {
                    unset($optgroups[$group_id]);
                }
            }

            $options['optgroups'] = $optgroups;
            $cache[$cache_key]    = $options;
        }

        /**
         * Filter the options of an object-type select (posts, terms, users, content rows).
         *
         * @since 1.0.0
         *
         * @param array  $options    Value => label.
         * @param array  $config     Field configuration.
         * @param string $field_name Field name, may be empty.
         */
        $options = apply_filters('awm_object_type_options', $options, $config, $field_name);
        if (!empty($field_name)) {
            /**
             * Filter the options of one named object-type select (`awm_object_type_options_{$field_name}`).
             *
             * @since 1.0.0
             *
             * @param array $options Value => label.
             * @param array $config  Field configuration.
             */
            $options = apply_filters('awm_object_type_options_' . $field_name, $options, $config);
        }
        return $options;
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
        global $wpdb;

        $defaults = array(
            'limit'       => 20,
            'search_meta' => true,
            'exclude'     => array(),
            'include'     => array(),
        );
        $args = array_merge($defaults, $args);

        /**
         * Filter the arguments of an object search before it runs.
         *
         * @since 1.5.8
         *
         * @param array  $args   Query arguments (limit, …).
         * @param string $group  Object group: post_type|taxonomy|user|custom_content.
         * @param string $slug   Post type, taxonomy or content type.
         * @param string $search Search text.
         */
        $args        = apply_filters('awm_object_search_query_args', $args, $group, $slug, $search);
        /**
         * Filter the largest number of results one object search may return.
         *
         * @since 1.10.0
         *
         * @param int $max Maximum `limit` (and `include` ids). Default 100.
         */
        $max         = max(1, (int) apply_filters('awm_object_search_max_limit', 100));
        $limit       = min($max, max(1, absint($args['limit'])));
        $exclude     = array_filter(array_map('absint', (array) $args['exclude']));
        $include     = array_slice(array_filter(array_map('absint', (array) $args['include'])), 0, $max);
        /*an empty term would make the meta LIKE '%%' scan the whole meta table*/
        $search_meta = (bool) $args['search_meta'] && $search !== '';
        $type        = $group . ':' . $slug;
        $results     = array();

        switch ($group) {
            case 'post_type':
                if (!post_type_exists($slug)) {
                    break;
                }
                $ids = array();
                if (!empty($include)) {
                    $ids = $include;
                } else {
                    $ids = get_posts(array(
                        'post_type'        => $slug,
                        's'                => $search,
                        'posts_per_page'   => $limit,
                        'post__not_in'     => $exclude,
                        'fields'           => 'ids',
                        'post_status'      => 'any',
                        'suppress_filters' => false,
                    ));
                    if ($search_meta && count($ids) < $limit) {
                        $like     = '%' . $wpdb->esc_like($search) . '%';
                        $meta_ids = $wpdb->get_col($wpdb->prepare(
                            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                             WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')
                             AND pm.meta_value LIKE %s LIMIT %d",
                            $slug,
                            $like,
                            $limit
                        ));
                        $ids = array_merge($ids, array_map('absint', $meta_ids));
                    }
                    $ids = array_diff(array_unique($ids), $exclude);
                    $ids = array_slice($ids, 0, $limit);
                }
                /*one query for every title instead of one per id*/
                _prime_post_caches(array_map('absint', $ids), false, false);
                foreach ($ids as $pid) {
                    $title = get_the_title($pid);
                    $results[] = array(
                        'id'    => absint($pid),
                        'label' => $title !== '' ? $title : sprintf(__('#%d (no title)', 'extend-wp'), $pid),
                        'type'  => $type,
                    );
                }
                break;

            case 'taxonomy':
                if (!taxonomy_exists($slug)) {
                    break;
                }
                $term_args = array(
                    'taxonomy'   => $slug,
                    'hide_empty' => false,
                    'number'     => $limit,
                    'exclude'    => $exclude,
                );
                if (!empty($include)) {
                    $term_args['include'] = $include;
                    unset($term_args['exclude']);
                } else {
                    $term_args['search'] = $search;
                }
                $terms = get_terms($term_args);
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        $results[absint($term->term_id)] = array(
                            'id'    => absint($term->term_id),
                            'label' => $term->name,
                            'type'  => $type,
                        );
                    }
                }
                if (empty($include) && $search_meta && count($results) < $limit) {
                    $like     = '%' . $wpdb->esc_like($search) . '%';
                    $meta_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT tm.term_id FROM {$wpdb->termmeta} tm
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                         WHERE tt.taxonomy = %s AND tm.meta_value LIKE %s LIMIT %d",
                        $slug,
                        $like,
                        $limit
                    ));
                    foreach ($meta_ids as $tid) {
                        $tid = absint($tid);
                        if (isset($results[$tid]) || in_array($tid, $exclude, true)) {
                            continue;
                        }
                        $term = get_term($tid, $slug);
                        if ($term && !is_wp_error($term)) {
                            $results[$tid] = array('id' => $tid, 'label' => $term->name, 'type' => $type);
                        }
                    }
                }
                $results = array_slice(array_values($results), 0, $limit);
                break;

            case 'custom_content':
                /*the slug becomes a table name below: registered content types only*/
                if (!function_exists('awm_get_db_content') || $slug !== sanitize_key($slug) || !class_exists('AWM_Add_Content_DB_Setup') || !isset(AWM_Add_Content_DB_Setup::$ewp_data_configuration[$slug])) {
                    break;
                }
                $rows = array();
                if (!empty($include)) {
                    $rows = awm_get_db_content($slug, array('include' => $include));
                } else {
                    $rows = (array) awm_get_db_content($slug, array('title' => $search, 'limit' => $limit));
                    if ($search_meta && count($rows) < $limit) {
                        $like     = '%' . $wpdb->esc_like($search) . '%';
                        $meta_ids = $wpdb->get_col($wpdb->prepare(
                            "SELECT DISTINCT content_id FROM %i WHERE meta_value LIKE %s LIMIT %d",
                            $wpdb->prefix . $slug . '_data',
                            $like,
                            $limit
                        ));
                        $meta_ids = array_filter(array_map('absint', (array) $meta_ids));
                        if (!empty($meta_ids)) {
                            $rows = array_merge($rows, (array) awm_get_db_content($slug, array('include' => $meta_ids)));
                        }
                    }
                }
                $seen = array();
                foreach ((array) $rows as $row) {
                    $cid = isset($row['content_id']) ? absint($row['content_id']) : 0;
                    if (!$cid || isset($seen[$cid]) || in_array($cid, $exclude, true)) {
                        continue;
                    }
                    $seen[$cid] = true;
                    $label = isset($row['content_title']) && $row['content_title'] !== '' ? $row['content_title'] : sprintf(__('#%d', 'extend-wp'), $cid);
                    $results[] = array('id' => $cid, 'label' => $label, 'type' => $type);
                }
                $results = array_slice($results, 0, $limit);
                break;
        }

        /**
         * Filter object search results.
         *
         * @since 1.5.8
         *
         * @param array  $results Results (id, label).
         * @param string $group   Object group.
         * @param string $slug    Post type, taxonomy or content type.
         * @param string $search  Search text.
         * @param array  $args    Query arguments.
         */
        return apply_filters('awm_object_search_results', $results, $group, $slug, $search, $args);
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
    if (isset($data) && !empty($data)) {
        awm_custom_meta_update_vars($data, $dataa, $id, $view);
        /*check for translation */
        do_action('awm_custom_meta_update_action', $data, $dataa, $id, $view, $postType);
        awm_auto_translate($data, $dataa, $id, $view);
    }
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
        $autoTranslate = array();


        $metaboxData = awm_get_metabox_info($metabox, $case);

        if (isset($metaboxData['auto_translate']) && $metaboxData['auto_translate']) {
            $autoTranslate = array_keys($metaboxData['library']);
        }
        if (empty($autoTranslate)) {
            foreach ($metaboxData['library'] as $field => $field_data) {
                if (isset($field_data['auto_translate'])) {
                    $autoTranslate[] = $field;
                }
            }
        }
        /**
         * Filter the fields WPML should copy to translations.
         *
         * @since 1.0.0
         *
         * @param array  $autoTranslate Meta keys to translate.
         * @param string $case          Box type.
         * @param array  $metabox       Boxes searched.
         */
        return apply_filters('awm_auto_translate_fields_filter', $autoTranslate, $case, $metabox);
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
        $hidden = false;
        if (is_array($field) && isset($field['auto_translate']) && function_exists('icl_object_id') && defined('ICL_LANGUAGE_CODE')) {
            global $sitepress;
            if (is_object($sitepress) && method_exists($sitepress, 'get_default_language')) {
                $hidden = $sitepress->get_default_language() != ICL_LANGUAGE_CODE;
            }
        }
        /**
         * Filter whether a field is skipped in the current language.
         *
         * @param bool  $hidden Whether the field is skipped.
         * @param array $field  The field definition.
         */
        return (bool) apply_filters('awm_field_hidden_in_current_language', $hidden, $field);
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
    /*wpml check*/
    $autoTranslate = array();

    if (isset($dataa['awm_metabox']) && !empty($dataa['awm_metabox'])) {
        foreach ($dataa['awm_metabox'] as $metabox) {
            $autoTranslate = array_unique(array_merge($autoTranslate, awm_find_tranlsatable_fields($metabox, $dataa['awm_metabox_case'])));
        }
        if (!empty($autoTranslate)) {
            if (function_exists('icl_object_id')) {
                global $sitepress;
                $ids = awm_translated_ids($id, $dataa['awm_metabox_case']);
                if (!empty($ids) && isset($ids['original']) && isset($ids['translations'])) {
                    foreach ($autoTranslate as $key) {
                        $value = '';
                        switch ($dataa['awm_metabox_case']) {
                            case 'post':
                                $value = ($id == $ids['original'] && isset($dataa[$key])) ? $dataa[$key] : get_post_meta($ids['original'], $key, true);
                                break;
                            case 'term':
                                $value = ($id == $ids['original'] && isset($dataa[$key])) ? $dataa[$key] : get_term_meta($ids['original'], $key, true);
                                break;
                        }
                        foreach ($ids['translations'] as $trans) {
                            switch ($dataa['awm_metabox_case']) {
                                case 'post':
                                    if (empty($value)) {
                                        delete_post_meta($trans, $key);
                                        break;
                                    }
                                    update_post_meta($trans, $key, $value);
                                    break;
                                case 'term':
                                    if (empty($value)) {
                                        delete_term_meta($trans, $key);
                                        break;
                                    }

                                    update_term_meta($trans, $key, $value);
                                    break;
                            }
                        }
                    }
                }
            }
        }
    }
    return;
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
    global $awm_field_configs;

    foreach ($meta as $k) {
        $chk = '';
        // Roles and capabilities are never written through a field save, whoever calls this.
        if (class_exists('EWP_Meta_Save_Guard') && EWP_Meta_Save_Guard::is_blocked_key($k)) {
            continue;
        }

        if (strpos($k, '[') !== false) {
            $keys = explode('[', $k);
            $ref = &$metaa;
            $ref2 = &$arr;
            $count = 0;
            while ($key = array_shift($keys)) {
                if ($count == 0) {
                    $k = $key;
                }
                $key = str_replace(']', '', $key);
                $ref = &$ref[$key];
                $ref2 = &$ref2[$key];
                ++$count;
            }
            $ref2 = $ref;
            $val = $arr[$k];
        } else {
            if (isset($metaa[$k])) {
                $chk = $metaa[$k];
            }
            $val = isset($chk) ? $chk : '';
            $arr[$k] = $val;
        }

        /*encrypt flagged values here: the update_{$type}_meta hooks are actions, their return value never reached the database*/
        list($encryption, $val) = awm_meta_save_encryption($k, $val, isset($metaa['ewp_encrypt']) ? array_map('strval', (array) wp_unslash($metaa['ewp_encrypt'])) : array());
        if ('skip' === $encryption) {
            continue;
        }

        switch ($view) {
            case 'user':
                /*update user meta*/
                if (!empty($val)) {
                    update_user_meta($id, $k, $val);
                } else {
                    delete_user_meta($id, $k);
                }
                break;
            case 'term':
                /*update user meta*/
                if (!empty($val)) {
                    update_term_meta($id, $k, $val);
                } else {
                    delete_term_meta($id, $k);
                }
                break;
            default:
                /* update post type*/
                if (!empty($val)) {
                    update_post_meta($id, $k, $val);
                } else {
                    delete_post_meta($id, $k);
                }
                break;
        }
    }
    return $arr;
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
        global $sitepress;
        $default = $sitepress->get_default_language();
        $languages = array_keys(apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc'));
        $type = '';
        switch ($case) {
            case 'post':
                $type = get_post_type($post_id);
                break;
            case 'term':
                $term = get_term($post_id);
                if ($term && !is_wp_error($term)) {
                    $type = $term->taxonomy;
                }
                break;
        }
        foreach ($languages as $language) {
            $tran_id = (int) apply_filters('wpml_object_id', $post_id, $type, true, $language);
            if ($language == $default) {
                $ids['original'] = $tran_id;
                continue;
            }
            $ids['translations'][] = $tran_id;
        }

        /**
         * Filter the original and translated IDs of an object.
         *
         * @since 1.0.0
         *
         * @param array $ids `original` and `translations` IDs.
         */
        return apply_filters('awm_translated_ids_filter', $ids);
    }
}

/**
 * Display value of a field (list columns, read-only views).
 *
 * @param string $meta           Meta key.
 * @param array  $data           Field definition.
 * @param int    $postId         Optional. Object ID. Default 0.
 * @param mixed  $external_value Optional. Value to show instead of the stored one. Default false.
 * @param string $case           Optional. post_type|taxonomy|user|… Default post_type.
 * @return string HTML.
 */
function awm_display_meta_value($meta, $data, $postId = 0, $external_value = false, $case = 'post_type')
{
    global $awm_post_id;
    $awm_post_id = $postId;
    $case = $external_value ? 'external' : $case;
    switch ($case) {
        case 'post_type':
            $value = get_post_meta($postId, $meta, true) ?: false;
            break;
        case 'term':
            $value = get_term_meta($postId, $meta, true) ?: false;
            break;
        case 'user':
            $value = get_user_meta($postId, $meta, true) ?: false;
            break;
        case 'external':
            $value = $external_value;
            break;
    }
    $original_value = $value;
    $case = isset($data['admin_list_view']) ? $data['admin_list_view'] : $data['case'];
    switch ($case) {
        case 'ewp_content':
            $object = awm_get_db_content($data['content_type'], array('include' => $value));
            if (!$object || empty($object)) {
                break;
            }
            $object = $object[0];
            $value = $object['content_title'];
            break;
        case 'repeater':
            if (isset($data['include']) && !empty($data['include'])) {
                $finalShow = array();
                if (empty($value)) {
                    break;
                }
                foreach ($value as $val) {
                    $row_val = array();
                    foreach ($data['include'] as $key => $rep_data) {
                        if (!isset($rep_data['admin_list']) || !$rep_data['admin_list']) {
                            continue;
                        }
                        $rep_val = isset($val[$key]) ? $val[$key] : '';
                        if (!empty($rep_val)) {
                            $rep_val = awm_display_meta_value($key, $rep_data, $awm_post_id, $rep_val);
                        }

                        $row_val[] = $rep_data['label'] . ': ' . $rep_val;
                    }
                    $finalShow[] = implode(' | ', $row_val);
                }
                $value = implode('<br>', $finalShow);
            }
            break;
        case 'input':
            switch ($data['type']) {
                case 'checkbox':
                    $value = $value ? __('Yes', 'extend-wp') : __('No', 'extend-wp');
                    break;
                case 'url':
                    $value = $value != '' ? '<a href="' . $value . '" target="_blank">' . $value . '</a>' : '';
                    break;
                default:
                    break;
            }

            break;
        case 'term':
            $term = get_term($value, $data['taxonomy']);
            if ($term) {
                $value = $term->name;
            }
            break;
        case 'post_types':
            $values = !is_array($value) ? array($value) : $value;
            $msg = array();
            foreach ($values as $value) {
                $post_type = get_post_types(array('name' => $value), 'objects');
                $msg[] = $post_type[$value]->label;
            }
            $value = implode(', ', $msg);
            break;
        case 'user':
            $user = get_user_by('id', $value);
            if ($user && !is_wp_error($user)) {
                /*get edit link also*/
                $value = '<a href="' . admin_url('user-edit.php?user_id=' . $value) . '" target="_blank">' . $user->display_name . '</a>';
            }
            break;
        case 'postType':
            $values = empty($value) ? array() : (!is_array($value) ? array($value) : $value);
            $msg = array();
            if (!empty($values)) {
                foreach ($values as $pvalue) {
                    $msg[] = $pvalue != '' ? '<a href="' . admin_url('post.php?action=edit&post=' . $pvalue) . '" target="_blank">' . get_the_title($pvalue) . '</a>' : '-';
                }
            }
            $value = implode(",", $msg);
            break;
        case 'message':
        case 'html':
            $value = isset($data['value']) ? $data['value'] : '';
            if (isset($data['strip'])) {
                return $value;
            }
            break;
        case 'function':
            if (isset($data['callback']) && function_exists($data['callback'])) {
                $value = call_user_func_array($data['callback'], array($postId));
            }
            break;
        case 'select':
        case 'checkbox_multiple':
            $values = is_array($value) ? $value : array($value);
            $finalShow = array();
            if (isset($data['callback'])) {
                $callback_options = array();
                if (!empty($data['callback_variables'])) {
                    $callback_options = call_user_func_array($data['callback'], $data['callback_variables']);
                }
                $callback_results = empty($callback_options) ? call_user_func($data['callback']) : $callback_options;
                $data['options'] = $callback_results;
                if (isset($callback_results['optgroups'])) {
                    $data['optgroups'] = $callback_results['optgroups'];
                    $data['options'] = $callback_results['options'];
                }
            }
            foreach ($values as $value) {
                if (!empty($value) && array_key_exists($value, $data['options'])) {
                    $finalShow[] = $data['options'][$value]['label'];
                }
            }
            $value = implode('<br>', $finalShow);
            break;
    }

    /**
     * Filter the display value of a field (list columns, read-only views).
     *
     * @since 1.0.0
     *
     * @param mixed  $value          Display value (usually HTML) built for the field.
     * @param string $meta           Meta key.
     * @param mixed  $original_value Raw stored (or external) value before formatting.
     * @param array  $data           Field definition.
     * @param int    $postId         Object ID.
     */
    return apply_filters('awm_display_meta_value_filter', $value, $meta, $original_value, $data, $postId);
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