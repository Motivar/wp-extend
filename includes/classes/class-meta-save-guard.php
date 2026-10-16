<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guards the post, term and user meta saves done by AWM_Meta.
 *
 * The edit forms post `awm_custom_meta[]` (the meta keys to save). That list
 * comes from the browser, so it is never trusted on its own:
 *
 * - the request must carry the core nonce of the screen being saved and the
 *   current user must be able to edit the object;
 * - only keys declared by the field libraries registered for that object
 *   (`awm_add_meta_boxes_filter` by post type, `awm_add_term_meta_boxes_filter`
 *   by taxonomy, `awm_add_user_boxes_filter`) are saved, plus any key added
 *   through the `ewp_allowed_meta_keys` filter;
 * - capability and role keys are never saved, whatever the filter returns;
 * - values sent by a user without `unfiltered_html` go through
 *   `wp_filter_post_kses()` (email, url and number inputs get their own
 *   sanitizer).
 *
 * @since 1.5.5
 */
class EWP_Meta_Save_Guard
{
    /**
     * Nonce field => nonce action pairs accepted per view. `%d` is replaced by the object ID.
     *
     * post: the classic and block editor post form; term: the edit-term form and the
     * add-term AJAX form; user: the profile / edit-user form and the add-user form.
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private static $nonce_sources = array(
        'post' => array(array('_wpnonce', 'update-post_%d')),
        'term' => array(array('_wpnonce', 'update-tag_%d'), array('_wpnonce_add-tag', 'add-tag')),
        'user' => array(array('_wpnonce', 'update-user_%d'), array('_wpnonce_create-user', 'create-user')),
    );

    /**
     * Save the meta keys posted with the current request, if the request is allowed to.
     *
     * @param string $view      Required. Object kind: 'post', 'term' or 'user'.
     * @param int    $object_id Required. ID of the post, term or user being saved.
     * @param string $subtype   Optional. Post type (post view) or taxonomy (term view); looked up
     *                          from the object when empty. Default ''.
     * @return string[] The keys that were saved (empty when nothing was saved).
     */
    public static function save($view, $object_id, $subtype = '')
    {
        if (empty($_POST['awm_custom_meta']) || !is_array($_POST['awm_custom_meta'])) {
            return array();
        }
        if (!self::request_authorised($view, (int) $object_id)) {
            return array();
        }
        $subtype = $subtype !== '' ? $subtype : self::subtype($view, (int) $object_id);
        $libraries = self::libraries($view, $subtype);
        $allowed = self::allowed_keys($view, (int) $object_id, $subtype, $libraries);
        $requested = array_map('strval', wp_unslash($_POST['awm_custom_meta']));
        $keys = array_values(array_unique(array_intersect($requested, $allowed)));
        if (empty($keys)) {
            return array();
        }
        $data = self::sanitize_request($_POST, $keys, self::field_definitions($libraries));
        awm_save_custom_meta($keys, $data, (int) $object_id, $view, $view === 'post' ? $subtype : '');
        return $keys;
    }

    /**
     * Whether the current request carries the screen's nonce and the user may edit the object.
     *
     * @param string $view      Required. 'post', 'term' or 'user'.
     * @param int    $object_id Required. Object ID.
     * @return bool
     */
    public static function request_authorised($view, $object_id)
    {
        if (!$object_id || !isset(self::$nonce_sources[$view]) || !self::user_can_edit($view, $object_id)) {
            return false;
        }
        foreach (self::$nonce_sources[$view] as $source) {
            list($field, $action) = $source;
            if (empty($_REQUEST[$field])) {
                continue;
            }
            $nonce = sanitize_text_field(wp_unslash($_REQUEST[$field]));
            if (wp_verify_nonce($nonce, sprintf($action, $object_id))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current user can edit the object.
     *
     * @param string $view      Required. 'post', 'term' or 'user'.
     * @param int    $object_id Required. Object ID.
     * @return bool
     */
    private static function user_can_edit($view, $object_id)
    {
        $caps = array('post' => 'edit_post', 'term' => 'edit_term', 'user' => 'edit_user');
        return isset($caps[$view]) && current_user_can($caps[$view], $object_id);
    }

    /**
     * Post type or taxonomy of an object.
     *
     * @param string $view      Required. 'post', 'term' or 'user'.
     * @param int    $object_id Required. Object ID.
     * @return string Post type, taxonomy, or '' for users and unknown objects.
     */
    private static function subtype($view, $object_id)
    {
        if ($view === 'post') {
            return (string) get_post_type($object_id);
        }
        if ($view === 'term') {
            $term = get_term($object_id);
            return ($term && !is_wp_error($term)) ? $term->taxonomy : '';
        }
        return '';
    }

    /**
     * Field libraries registered for an object kind, keyed by box ID.
     *
     * @param string $view    Required. 'post', 'term' or 'user'.
     * @param string $subtype Required. Post type (post), taxonomy (term) or '' (user).
     * @return array<string, array> Box ID => library (meta key => field definition).
     */
    public static function libraries($view, $subtype)
    {
        $scope_keys = array('post' => 'postTypes', 'term' => 'taxonomies', 'user' => '');
        if (!isset($scope_keys[$view])) {
            return array();
        }
        $scope_key = $scope_keys[$view];
        $boxes = self::boxes($view);
        $libraries = array();
        foreach ((array) $boxes as $box_id => $box) {
            if ($scope_key !== '' && !in_array($subtype, (array) ($box[$scope_key] ?? array()), true)) {
                continue;
            }
            $library = awm_callback_library(awm_callback_library_options($box), $box_id);
            if (is_array($library) && !empty($library)) {
                $libraries[$box_id] = $library;
            }
        }
        return $libraries;
    }

    /**
     * Boxes registered for an object kind, read from the same filters AWM_Meta renders from.
     *
     * Read fresh on each save (AWM_Meta caches its copy for the request).
     *
     * @param string $view Required. 'post', 'term' or 'user'.
     * @return array Box ID => box definition.
     */
    private static function boxes($view)
    {
        if ($view === 'post') {
            /**
             * Filters the post meta boxes (box ID => title, postTypes, library or callback, …).
             *
             * @since 1.0.0
             *
             * @param array $boxes Registered boxes.
             * @param int   $flag  Always 1 (legacy argument).
             */
            return (array) apply_filters('awm_add_meta_boxes_filter', array(), 1);
        }
        if ($view === 'term') {
            /**
             * Filters the term meta boxes (box ID => title, taxonomies, library or callback, …).
             *
             * @since 1.0.0
             *
             * @param array $boxes Registered boxes.
             */
            return (array) apply_filters('awm_add_term_meta_boxes_filter', array());
        }
        /**
         * Filters the user boxes (box ID => title, library or callback, …).
         *
         * @since 1.0.0
         *
         * @param array $boxes Registered boxes.
         * @param int   $flag  Always 1 (legacy argument).
         */
        return (array) apply_filters('awm_add_user_boxes_filter', array(), 1);
    }

    /**
     * Meta keys a save request may write for an object.
     *
     * @param string     $view      Required. 'post', 'term' or 'user'.
     * @param int        $object_id Required. Object ID.
     * @param string     $subtype   Optional. Post type or taxonomy; looked up when empty. Default ''.
     * @param array|null $libraries Optional. Libraries from libraries(); built when null. Default null.
     * @return string[]
     */
    public static function allowed_keys($view, $object_id, $subtype = '', $libraries = null)
    {
        $subtype = $subtype !== '' ? $subtype : self::subtype($view, (int) $object_id);
        $libraries = is_array($libraries) ? $libraries : self::libraries($view, $subtype);
        $keys = array();
        foreach ($libraries as $library) {
            $keys = array_merge($keys, self::library_keys($library));
        }

        /**
         * Filters the meta keys an AWM post, term or user save may write.
         *
         * By default these are the keys declared by the field libraries registered for
         * the object (by post type or taxonomy for posts and terms). Add a key here when
         * a form prints fields with awm_show_content() outside a registered box. Capability
         * and role keys are removed after this filter and can never be allowed.
         *
         * @since 1.5.5
         *
         * @param string[] $keys      Allowed meta keys.
         * @param string   $view      Object kind: 'post', 'term' or 'user'.
         * @param int      $object_id ID of the object being saved.
         * @param string   $subtype   Post type (post), taxonomy (term) or '' (user).
         */
        $keys = (array) apply_filters('ewp_allowed_meta_keys', $keys, $view, (int) $object_id, $subtype);

        return array_values(array_unique(array_filter(array_map('strval', $keys), function ($key) {
            return $key !== '' && !self::is_blocked_key($key);
        })));
    }

    /**
     * Meta keys a field library saves: its top-level keys, the IDs key of an
     * object_id_filter field and the child keys of a section with keep_inputs.
     *
     * @param array $library Required. Meta key => field definition.
     * @return string[]
     */
    public static function library_keys(array $library)
    {
        $keys = array();
        foreach ($library as $key => $field) {
            if ($key === 'awm-id' || !is_array($field)) {
                continue;
            }
            $keys[] = (string) $key;
            $case = isset($field['case']) ? $field['case'] : '';
            if ($case === 'object_id_filter' && function_exists('awm_object_id_filter_config')) {
                $config = awm_object_id_filter_config($field, $key);
                $keys[] = (string) $config['id_field_name'];
            }
            if ($case === 'section' && isset($field['keep_inputs']) && !empty($field['include']) && is_array($field['include'])) {
                $keys = array_merge($keys, array_map('strval', array_keys($field['include'])));
            }
        }
        return $keys;
    }

    /**
     * Whether a meta key may never be written through a field save (roles and capabilities).
     *
     * Checks the part before the first `[`, since nested keys are saved under it.
     *
     * @param string $key Required. Meta key, possibly nested (`name[a][b]`).
     * @return bool
     */
    public static function is_blocked_key($key)
    {
        global $wpdb;
        $parts = explode('[', (string) $key);
        $base = $parts[0];
        if ($base === 'session_tokens') {
            return true;
        }
        $prefix = preg_quote($wpdb->base_prefix, '/');
        return (bool) preg_match('/^' . $prefix . '(\d+_)?(capabilities|user_level)$/', $base);
    }

    /**
     * Field definitions by meta key, across libraries.
     *
     * @param array $libraries Required. Box ID => library.
     * @return array<string, array>
     */
    private static function field_definitions(array $libraries)
    {
        $fields = array();
        foreach ($libraries as $library) {
            foreach ($library as $key => $field) {
                if (is_array($field)) {
                    $fields[(string) $key] = $field;
                }
            }
        }
        return $fields;
    }

    /**
     * Copy of the (slashed) request with the saved keys sanitized.
     *
     * Users with unfiltered_html keep their values as sent, like post content.
     *
     * @param array $request Required. Slashed request data ($_POST).
     * @param array $keys    Required. Keys that will be saved.
     * @param array $fields  Required. Meta key => field definition.
     * @return array Slashed request data.
     */
    public static function sanitize_request(array $request, array $keys, array $fields)
    {
        if (current_user_can('unfiltered_html')) {
            return $request;
        }
        foreach ($keys as $key) {
            $parts = explode('[', $key);
            $base = $parts[0];
            if (!array_key_exists($base, $request)) {
                continue;
            }
            $field = isset($fields[$key]) ? $fields[$key] : (isset($fields[$base]) ? $fields[$base] : array());
            $request[$base] = self::sanitize_value($request[$base], $field);
        }
        return $request;
    }

    /**
     * Sanitize one slashed value for its field.
     *
     * @param mixed $value Required. Slashed value (string or nested array).
     * @param array $field Required. Field definition ('case', 'type'); may be empty.
     * @return mixed Slashed, sanitized value.
     */
    public static function sanitize_value($value, array $field)
    {
        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = self::sanitize_value($item, array());
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        $type = (isset($field['case']) && $field['case'] === 'input' && isset($field['type'])) ? $field['type'] : '';
        switch ($type) {
            case 'email':
                return wp_slash(sanitize_email(wp_unslash($value)));
            case 'url':
                return wp_slash(esc_url_raw(wp_unslash($value)));
            case 'number':
                return is_numeric(wp_unslash($value)) ? $value : '';
            default:
                return wp_filter_post_kses($value);
        }
    }
}
