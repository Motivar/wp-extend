<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global helper functions for the EWP Logger.
 *
 * Thin wrappers around EWP\Logger\EWP_Logger static methods,
 * providing a simple functional API for logging and type registration.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */

if (!function_exists('ewp_log')) {
    /**
     * Log an entry to the EWP Logger.
     *
     * @param string $owner       Plugin slug that owns the log (e.g. 'filox', 'extend-wp').
     * @param string $action_type Registered action type key.
     * @param string $message     Human-readable summary.
     * @param array  $data        Detailed payload (will be serialized).
     * @param string $level       'editor' or 'developer'. Default 'editor'.
     * @param string   $object_type WP object context (post_type, taxonomy, user, option, custom_content, etc.).
     * @param int|bool $behaviour   0 = error, 1 = success (default), 2 = warning. Booleans accepted for backwards compat.
     *
     * @return bool True if queued, false if logging is disabled or cancelled.
     *
     * @since 1.0.0
     *
     * @example
     * // Log a successful content save at editor level
     * ewp_log('filox', 'booking_created', 'Booking #42 created', ['id' => 42], 'editor', 'custom_content', 1);
     *
     * // Log an error
     * ewp_log('filox', 'booking_created', 'Save failed: conflict', ['error' => 'conflict'], 'editor', 'custom_content', 0);
     *
     * // Log a warning
     * ewp_log('filox', 'sync_run', 'Partial sync — 3 skipped', $raw_data, 'developer', 'system', 2);
     */
    function ewp_log($owner, $action_type, $message, $data = [], $level = 'editor', $object_type = '', $behaviour = 1)
    {
        return \EWP\Logger\EWP_Logger::log($owner, $action_type, $message, $data, $level, $object_type, $behaviour);
    }
}

if (!function_exists('ewp_register_log_type')) {
    /**
     * Register a loggable action type.
     *
     * Labels are stored raw (no __() at write time). They will be wrapped
     * in __() on output, so developers can provide translations via their text domain.
     *
     * @param string $owner       Plugin slug that owns this type.
     * @param string $type_key    Unique action type key.
     * @param string $label       Human-readable label (stored raw, translated on output).
     * @param string $description Optional description.
     *
     * @return void
     *
     * @since 1.0.0
     *
     * @example
     * // Register action types during plugin init
     * ewp_register_log_type('filox', 'booking_created', 'Booking Created', 'A new booking was created');
     * ewp_register_log_type('filox', 'calendar_update', 'Calendar Update', 'Calendar rates changed');
     */
    function ewp_register_log_type($owner, $type_key, $label, $description = '')
    {
        \EWP\Logger\EWP_Logger::register_action_type($owner, $type_key, $label, $description);
    }
}
