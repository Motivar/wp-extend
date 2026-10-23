<?php

if (!defined('ABSPATH')) {
 exit;
}

/**
 * No-op stubs for the removed EWP logger.
 *
 * The logger was removed; consumer plugins (e.g. Filox) may still call these
 * functions, so they stay defined and do nothing.
 *
 * @package EWP
 *
 * @deprecated 1.10.1 The EWP logger was removed.
 */

if (!function_exists('ewp_log')) {
    /**
     * Log an entry (no-op, the logger was removed).
     *
     * @param string   $owner       Unused.
     * @param string   $action_type Unused.
     * @param string   $message     Unused.
     * @param array    $data        Unused.
     * @param string   $level       Unused.
     * @param string   $object_type Unused.
     * @param int|bool $behaviour   Unused.
     *
     * @return bool Always false.
     *
     * @deprecated 1.10.1 The EWP logger was removed.
     */
    function ewp_log($owner, $action_type, $message, $data = [], $level = 'editor', $object_type = '', $behaviour = 1)
    {
        return false;
    }
}

if (!function_exists('ewp_register_log_type')) {
    /**
     * Register a loggable action type (no-op, the logger was removed).
     *
     * @param string $owner       Unused.
     * @param string $type_key    Unused.
     * @param string $label       Unused.
     * @param string $description Unused.
     *
     * @return void
     *
     * @deprecated 1.10.1 The EWP logger was removed.
     */
    function ewp_register_log_type($owner, $type_key, $label, $description = '')
    {
    }
}

if (!function_exists('ewp_register_log_owner')) {
    /**
     * Register a log owner (no-op, the logger was removed).
     *
     * @param string $slug  Unused.
     * @param string $label Unused.
     *
     * @return void
     *
     * @deprecated 1.10.1 The EWP logger was removed.
     */
    function ewp_register_log_owner($slug, $label)
    {
    }
}
