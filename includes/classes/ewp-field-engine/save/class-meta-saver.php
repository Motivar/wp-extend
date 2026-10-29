<?php

namespace EWP\Fields\Save;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists the posted values of a field library: the implementation behind
 * awm_save_custom_meta() and awm_custom_meta_update_vars().
 *
 * Per meta key: the blocked-key guard (EWP_Meta_Save_Guard), nested-key
 * (`a[b][c]`) resolution against the posted data, encryption of flagged
 * values (awm_meta_save_encryption()), then a per-view write — an empty value
 * deletes the meta, anything else updates it.
 *
 * @package EWP\Fields\Save
 * @since 1.11.0
 */
final class Meta_Saver
{
    /**
     * Save a field library's posted values, then fire the update action and
     * run the WPML auto-translation.
     *
     * @param array  $data     Meta keys to save.
     * @param array  $dataa    Posted values.
     * @param int    $id       Object ID.
     * @param string $view     post|term|user.
     * @param string $postType Post type or taxonomy.
     * @return void
     */
    public function save($data, $dataa, $id, $view = 'post', $postType = '')
    {
        if (!isset($data) || empty($data)) {
            return;
        }
        $this->update_vars($data, $dataa, $id, $view);
        /*check for translation */
        /**
         * Fires after the meta values of a library were saved.
         *
         * @since 1.0.0
         *
         * @param array  $data     Meta keys saved.
         * @param array  $dataa    Posted values.
         * @param int    $id       Object ID.
         * @param string $view     post|term|user.
         * @param string $postType Post type or taxonomy.
         */
        do_action('awm_custom_meta_update_action', $data, $dataa, $id, $view, $postType);
        awm_auto_translate($data, $dataa, $id, $view);
    }

    /**
     * Write each posted meta key to the object, encrypting flagged fields.
     *
     * @param array  $meta  Meta keys to save.
     * @param array  $metaa Posted values.
     * @param int    $id    Object ID.
     * @param string $view  post|term|user.
     * @return array Resolved values keyed by meta key (nested keys as arrays).
     */
    public function update_vars($meta, $metaa, $id, $view)
    {
        $arr = array();
        foreach ($meta as $k) {
            // Roles and capabilities are never written through a field save, whoever calls this.
            if (class_exists('EWP_Meta_Save_Guard') && \EWP_Meta_Save_Guard::is_blocked_key($k)) {
                continue;
            }

            list($k, $val) = $this->resolve_value($k, $metaa, $arr);

            /*encrypt flagged values here: the update_{$type}_meta hooks are actions, their return value never reached the database*/
            list($encryption, $val) = awm_meta_save_encryption($k, $val, $this->encryption_markers($metaa));
            if ('skip' === $encryption) {
                continue;
            }

            $this->write($view, $id, $k, $val);
        }
        return $arr;
    }

    /**
     * Resolve the base key and value of a meta key against the posted data.
     *
     * @param string $k     Meta key, possibly nested (`a[b][c]`).
     * @param array  $metaa Posted values.
     * @param array  $arr   Accumulated resolved values (written to).
     * @return array Two items: the base key and the value to store.
     */
    public function resolve_value($k, array $metaa, array &$arr)
    {
        if (strpos($k, '[') !== false) {
            return $this->resolve_nested($k, $metaa, $arr);
        }
        $val = isset($metaa[$k]) ? $metaa[$k] : '';
        $arr[$k] = $val;
        return array($k, $val);
    }

    /**
     * Resolve a nested meta key (`a[b][c]`): walk the posted data and mirror
     * the path into the accumulated values.
     *
     * @param string $k     Nested meta key.
     * @param array  $metaa Posted values.
     * @param array  $arr   Accumulated resolved values (written to).
     * @return array Two items: the base key (`a`) and its (nested) value.
     */
    private function resolve_nested($k, array $metaa, array &$arr)
    {
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
        return array($k, $arr[$k]);
    }

    /**
     * The `ewp_encrypt` markers posted with the form.
     *
     * @param array $metaa Posted values.
     * @return string[] Marker names flagged for encryption.
     */
    private function encryption_markers(array $metaa)
    {
        if (!isset($metaa['ewp_encrypt'])) {
            return array();
        }
        return array_map('strval', (array) wp_unslash($metaa['ewp_encrypt']));
    }

    /**
     * Write one value to the object for the given view; an empty value deletes the meta.
     *
     * @param string $view post|term|user (anything else writes post meta).
     * @param int    $id   Object ID.
     * @param string $k    Meta key.
     * @param mixed  $val  Value to store.
     * @return void
     */
    public function write($view, $id, $k, $val)
    {
        $empty = empty($val);
        switch ($view) {
            case 'user':
                $empty ? delete_user_meta($id, $k) : update_user_meta($id, $k, $val);
                break;
            case 'term':
                $empty ? delete_term_meta($id, $k) : update_term_meta($id, $k, $val);
                break;
            default:
                $empty ? delete_post_meta($id, $k) : update_post_meta($id, $k, $val);
                break;
        }
    }
}
