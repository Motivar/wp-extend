<?php

namespace EWP\Fields\Save;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WPML support for field saves: the implementation behind
 * awm_find_tranlsatable_fields(), awm_field_hidden_in_current_language(),
 * awm_auto_translate() and awm_translated_ids().
 *
 * Fields flagged `auto_translate` (or whole boxes flagged so) are copied from
 * the original object to its translations after a save. Everything is inert
 * without WPML.
 *
 * @package EWP\Fields\Save
 * @since 1.11.0
 */
final class Translation_Sync
{
    /**
     * Auto-translatable meta keys of a metabox.
     *
     * @param string $metabox Metabox id.
     * @param string $case    Box type.
     * @return array Meta keys.
     */
    public function translatable_fields($metabox, $case)
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

    /**
     * Whether a field is left out of the current request because it is
     * auto-translated and the admin works in a secondary WPML language.
     *
     * @param array $field Field definition from a library.
     * @return bool
     */
    public function hidden_in_current_language($field)
    {
        $hidden = $this->is_secondary_language($field);
        /**
         * Filter whether a field is skipped in the current language.
         *
         * @param bool  $hidden Whether the field is skipped.
         * @param array $field  The field definition.
         */
        return (bool) apply_filters('awm_field_hidden_in_current_language', $hidden, $field);
    }

    /**
     * WPML state check: active, a secondary language, and the field is auto-translated.
     *
     * @param mixed $field Field definition.
     * @return bool
     */
    private function is_secondary_language($field)
    {
        if (!is_array($field) || !isset($field['auto_translate']) || !function_exists('icl_object_id') || !defined('ICL_LANGUAGE_CODE')) {
            return false;
        }
        $sitepress = $this->sitepress();
        if (!is_object($sitepress) || !method_exists($sitepress, 'get_default_language')) {
            return false;
        }
        return $sitepress->get_default_language() != ICL_LANGUAGE_CODE;
    }

    /**
     * The WPML global.
     *
     * @return object|null
     */
    private function sitepress()
    {
        global $sitepress;
        return $sitepress;
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
    public function auto_translate($data, $dataa, $id, $view)
    {
        if (!isset($dataa['awm_metabox']) || empty($dataa['awm_metabox'])) {
            return;
        }
        $case = $dataa['awm_metabox_case'];
        $keys = array();
        foreach ($dataa['awm_metabox'] as $metabox) {
            $keys = array_unique(array_merge($keys, awm_find_tranlsatable_fields($metabox, $case)));
        }
        if (empty($keys) || !function_exists('icl_object_id')) {
            return;
        }
        $ids = awm_translated_ids($id, $case);
        if (empty($ids) || !isset($ids['original']) || !isset($ids['translations'])) {
            return;
        }
        foreach ($keys as $key) {
            $value = $this->source_value($case, $id, $ids['original'], $key, $dataa);
            foreach ($ids['translations'] as $trans) {
                $this->write_translation($case, $trans, $key, $value);
            }
        }
    }

    /**
     * The value to copy: the posted one when saving the original, else the stored original.
     *
     * @param string $case     post|term (other cases yield an empty value).
     * @param int    $id       Saved object ID.
     * @param int    $original Original object ID.
     * @param string $key      Meta key.
     * @param array  $dataa    Posted values.
     * @return mixed
     */
    public function source_value($case, $id, $original, $key, array $dataa)
    {
        $posted = ($id == $original && isset($dataa[$key]));
        switch ($case) {
            case 'post':
                return $posted ? $dataa[$key] : get_post_meta($original, $key, true);
            case 'term':
                return $posted ? $dataa[$key] : get_term_meta($original, $key, true);
        }
        return '';
    }

    /**
     * Write (or delete, when empty) one translated meta value.
     *
     * @param string $case  post|term (other cases write nothing).
     * @param int    $trans Translation object ID.
     * @param string $key   Meta key.
     * @param mixed  $value Value to copy.
     * @return void
     */
    public function write_translation($case, $trans, $key, $value)
    {
        switch ($case) {
            case 'post':
                empty($value) ? delete_post_meta($trans, $key) : update_post_meta($trans, $key, $value);
                break;
            case 'term':
                empty($value) ? delete_term_meta($trans, $key) : update_term_meta($trans, $key, $value);
                break;
        }
    }

    /**
     * Original and translated IDs of an object, from the WPML language list.
     *
     * @param int    $post_id Post or term ID.
     * @param string $case    post|term.
     * @return array `original` and `translations` IDs.
     */
    public function translated_ids($post_id, $case)
    {
        $sitepress = $this->sitepress();
        $default = $sitepress->get_default_language();
        $languages = array_keys(apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc'));
        $type = $this->object_type($post_id, $case);
        $ids = array();
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

    /**
     * The post type or taxonomy WPML needs to translate an ID.
     *
     * @param int    $post_id Post or term ID.
     * @param string $case    post|term.
     * @return string Post type / taxonomy, empty for other cases.
     */
    private function object_type($post_id, $case)
    {
        if ('post' === $case) {
            return get_post_type($post_id);
        }
        if ('term' !== $case) {
            return '';
        }
        $term = get_term($post_id);
        return ($term && !is_wp_error($term)) ? $term->taxonomy : '';
    }
}
