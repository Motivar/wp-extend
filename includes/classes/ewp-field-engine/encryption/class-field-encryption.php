<?php

namespace EWP\Fields\Encryption;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field-level encryption rules behind the procedural awm_*encrypt* helpers.
 *
 * Decides which fields are encrypted (`encrypt => true` on a supported input
 * field, or an `ewp_encrypt[]` request marker), encrypts and decrypts values
 * through \EWP_Encryption (never duplicating the cipher), and re-saves
 * options after `updated_option` without recursing. Request globals
 * (`$awm_field_configs`, `$_POST['ewp_encrypt']`, `$_POST['awm_metabox_case']`)
 * are only read through the small accessors below.
 *
 * @package EWP\Fields\Encryption
 * @since 1.11.0
 */
final class Field_Encryption
{
    /** @var string[] Input types that can be encrypted. */
    const SUPPORTED_INPUT_TYPES = array('text', 'password', 'email', 'url', 'number');

    /** @var string Callback registered on `updated_option` (removed while re-saving). */
    const OPTION_CALLBACK = 'awm_encrypt_option_after_save';

    /** @var self|null Shared instance used by the awm_* wrappers. */
    private static $instance;

    /**
     * Shared instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Whether a field config asks for encryption on a supported input type.
     *
     * @param array $field_config Field configuration.
     * @return bool
     */
    public function should_encrypt_field($field_config)
    {
        if (!isset($field_config['encrypt']) || !$field_config['encrypt']) {
            return false;
        }
        if (!isset($field_config['case']) || $field_config['case'] !== 'input') {
            return false;
        }
        $input_type = isset($field_config['type']) ? $field_config['type'] : 'text';

        return in_array($input_type, self::SUPPORTED_INPUT_TYPES, true);
    }

    /**
     * Encrypt a value before it is stored.
     *
     * Empty, already encrypted and masked values are returned unchanged.
     * Applies the `ewp_encrypt_field_value` filter before encrypting.
     *
     * @param string $value        Plain-text value.
     * @param array  $field_config Field configuration.
     * @return string Ciphertext, or the original value when skipped.
     */
    public function encrypt_value($value, $field_config)
    {
        if (empty($value)) {
            return $value;
        }
        if (strpos($value, 'ewp_enc:') === 0) {
            return $value;
        }
        if (\EWP_Encryption::is_masked($value)) {
            return $value;
        }

        $algorithm = $this->algorithm($field_config);

        /**
         * Filter a field value before it is encrypted.
         *
         * @since 1.0.0
         *
         * @param mixed $value        Plain value.
         * @param array $field_config Field definition (encrypt settings).
         */
        $value = apply_filters('ewp_encrypt_field_value', $value, $field_config);

        return \EWP_Encryption::encrypt($value, $algorithm);
    }

    /**
     * Decrypt a stored value for display or editing.
     *
     * For display the masked form is returned unless `show_masked` is false.
     *
     * @param string $value        Stored (encrypted or plain) value.
     * @param array  $field_config Field configuration.
     * @param bool   $for_display  True for display (masked by default), false for editing.
     * @return string Decrypted, masked or original value.
     */
    public function decrypt_value($value, $field_config, $for_display = true)
    {
        if (empty($value)) {
            return $value;
        }

        $algorithm = $this->algorithm($field_config);
        $decrypted = \EWP_Encryption::decrypt($value, $algorithm);

        if ($for_display) {
            $show_masked = isset($field_config['show_masked']) ? $field_config['show_masked'] : true;
            if ($show_masked) {
                return \EWP_Encryption::mask($value, $algorithm);
            }
        }

        /**
         * Filter a field value after it is decrypted.
         *
         * @since 1.0.0
         *
         * @param mixed $decrypted    Decrypted value.
         * @param array $field_config Field definition (encrypt settings).
         */
        return apply_filters('ewp_decrypt_field_value', $decrypted, $field_config);
    }

    /**
     * How a field save must treat a value with respect to encryption.
     *
     * A masked value means the user left the field untouched, so the stored
     * ciphertext must be kept.
     *
     * @param string $meta_key Meta key being saved.
     * @param mixed  $value    Submitted value.
     * @param array  $markers  Keys posted in `ewp_encrypt[]`.
     * @return array{0: string, 1: mixed} `skip` (keep stored value) or `write`, and the value to store.
     */
    public function meta_save_encryption($meta_key, $value, array $markers = array())
    {
        $config = $this->field_config($meta_key);
        if (!is_string($value) || (!$this->should_encrypt_field($config) && !in_array($meta_key, $markers, true))) {
            return array('write', $value);
        }
        if (\EWP_Encryption::is_masked($value)) {
            return array('skip', $value);
        }
        return array('write', $this->encrypt_value($value, $config));
    }

    /**
     * Encrypt a meta value (legacy `update_*_meta` entry point).
     *
     * @param mixed  $meta_id    Meta ID (only logged).
     * @param int    $object_id  Post, user or term ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Value to save.
     * @return mixed The possibly encrypted value.
     */
    public function encrypt_meta_on_save($meta_id, $object_id, $meta_key, $meta_value)
    {
        $field_config   = $this->field_config($meta_key);
        $should_encrypt = $this->should_encrypt_field($field_config) || in_array($meta_key, $this->posted_markers(), true);

        if (!$should_encrypt) {
            return $meta_value;
        }
        if (\EWP_Encryption::is_encrypted($meta_value)) {
            return $meta_value;
        }
        if (\EWP_Encryption::is_masked($meta_value)) {
            return $meta_value;
        }
        if (empty($meta_value)) {
            return $meta_value;
        }

        $encrypted_value = $this->encrypt_value($meta_value, $field_config);
        $this->log(
            sprintf('Meta field "%s" encrypted for object ID %d', $meta_key, $object_id),
            array(
                'meta_key'     => $meta_key,
                'object_id'    => $object_id,
                'meta_id'      => $meta_id,
                'value_length' => strlen($meta_value),
            )
        );

        return $encrypted_value;
    }

    /**
     * Decrypt a meta value (legacy `get_*_meta` entry point).
     *
     * @param mixed  $meta_value Stored value.
     * @param int    $object_id  Post, user or term ID (unused).
     * @param string $meta_key   Meta key.
     * @param bool   $single     Whether a single value was requested (unused).
     * @return mixed The possibly decrypted value.
     */
    public function decrypt_meta_on_retrieve($meta_value, $object_id, $meta_key, $single)
    {
        $field_config = $this->field_config($meta_key);
        if (!$this->should_encrypt_field($field_config)) {
            return $meta_value;
        }
        if (empty($meta_value)) {
            return $meta_value;
        }

        return $this->decrypt_value($meta_value, $field_config, false);
    }

    /**
     * Whether an option value still needs encrypting (not encrypted, masked or empty).
     *
     * @param mixed $value Field value.
     * @return bool
     */
    public function should_encrypt_value($value)
    {
        if (\EWP_Encryption::is_encrypted($value)) {
            return false;
        }
        if (\EWP_Encryption::is_masked($value)) {
            return false;
        }

        return '' !== $value;
    }

    /**
     * Encrypt one key of an option array.
     *
     * @param array       $option_value    Current option value.
     * @param string      $field_key       Key to encrypt.
     * @param string      $option_name     Option name.
     * @param string|null $expected_option Option name a bracket path refers to (null: any).
     * @return array|null Updated option value, or null when the field is skipped.
     */
    public function encrypt_option_field($option_value, $field_key, $option_name, $expected_option = null)
    {
        if ($expected_option !== null && $expected_option !== $option_name) {
            return null;
        }
        if (!is_array($option_value) || !isset($option_value[$field_key])) {
            return null;
        }

        $raw = $option_value[$field_key];
        if (!$this->should_encrypt_value($raw)) {
            return null;
        }

        $option_value[$field_key] = \EWP_Encryption::encrypt($raw);
        $this->log(
            sprintf('Option field "%s[%s]" encrypted', $option_name, $field_key),
            array(
                'option_name'  => $option_name,
                'field_key'    => $field_key,
                'value_length' => strlen($raw),
            )
        );

        return $option_value;
    }

    /**
     * Encrypt the flagged fields of an option right after it was saved.
     *
     * Only runs for options-page saves (`awm_metabox_case` = option) that
     * posted `ewp_encrypt[]` markers. The re-save detaches the
     * `updated_option` callback so it does not recurse.
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Old value (unused).
     * @param mixed  $value     New value (unused).
     * @return void
     */
    public function encrypt_option_after_save($option, $old_value, $value)
    {
        if (!isset($_POST['awm_metabox_case']) || $_POST['awm_metabox_case'] !== 'option') {
            return;
        }
        $markers = $this->posted_markers();
        if (empty($markers)) {
            return;
        }

        $current_value = get_option($option);
        $updated       = false;
        foreach ($markers as $field_path) {
            $result = $this->encrypt_marked_path($current_value, $field_path, $option);
            if ($result === null) {
                continue;
            }
            $current_value = $result;
            $updated       = true;
        }
        if (!$updated) {
            return;
        }

        remove_action('updated_option', self::OPTION_CALLBACK, 10);
        update_option($option, $current_value);
        add_action('updated_option', self::OPTION_CALLBACK, 10, 3);
    }

    /**
     * Encrypt the field a single `ewp_encrypt[]` path points at.
     *
     * @param mixed  $current_value Current option value.
     * @param string $field_path    Marker path.
     * @param string $option        Option name.
     * @return array|null Updated option value, or null when skipped / malformed.
     */
    private function encrypt_marked_path($current_value, $field_path, $option)
    {
        $parsed = Option_Field_Path::parse($field_path);
        if ($parsed === null) {
            return null;
        }

        return $this->encrypt_option_field($current_value, $parsed['field'], $option, $parsed['option']);
    }

    /**
     * Encryption algorithm named by a field config.
     *
     * @param array $field_config Field configuration.
     * @return string Algorithm, empty for the default.
     */
    private function algorithm($field_config)
    {
        return isset($field_config['encrypt_algorithm']) ? $field_config['encrypt_algorithm'] : '';
    }

    /**
     * Field config registered for a meta key in the request-wide registry.
     *
     * @param string $meta_key Meta key.
     * @return array Config, empty when unknown.
     */
    private function field_config($meta_key)
    {
        global $awm_field_configs;

        return isset($awm_field_configs[$meta_key]) ? (array) $awm_field_configs[$meta_key] : array();
    }

    /**
     * Keys the form flagged in `ewp_encrypt[]`.
     *
     * @return array
     */
    private function posted_markers()
    {
        return isset($_POST['ewp_encrypt']) ? (array) $_POST['ewp_encrypt'] : array();
    }

    /**
     * Write a developer-level `field_encrypted` log entry, when the logger exists.
     *
     * @param string $message Log message.
     * @param array  $context Log context.
     * @return void
     */
    private function log($message, array $context)
    {
        if (!function_exists('ewp_log')) {
            return;
        }
        ewp_log('extend-wp', 'field_encrypted', $message, $context, 'developer', '', 1);
    }
}
