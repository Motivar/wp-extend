<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared output formatting for EWP Logger entries.
 *
 * Single source of truth for turning a raw storage row into a labelled,
 * consumer-ready entry. Used by the REST API and by the registered
 * abilities so the two representations cannot drift apart.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.3.0
 */
class EWP_Logger_Formatter
{
    /**
     * Default number of characters kept when previewing a data payload.
     *
     * @var int
     */
    const DEFAULT_PREVIEW_CHARS = 500;

    /**
     * Prepare a raw log entry for output.
     *
     * Unserializes the data payload and resolves human-readable labels for
     * owner, action_type, object_type, user and behaviour.
     *
     * @param array $entry Raw log entry.
     *
     * @return array Prepared entry with *_label fields added.
     *
     * @since 1.3.0
     */
    public static function prepare_entry(array $entry)
    {
        // Unserialize data payload
        if (!empty($entry['data'])) {
            $entry['data'] = maybe_unserialize($entry['data']);
        }

        // Owner label
        $entry['owner_label'] = EWP_Logger::resolve_owner_label($entry['owner'] ?? '');

        // Action type label (searches all registered owners)
        $entry['action_type_label'] = EWP_Logger::resolve_action_type_label($entry['action_type'] ?? '');

        // Object type label
        $entry['object_type_label'] = EWP_Logger::resolve_object_type_label($entry['object_type'] ?? '');

        // User display name
        $user_id = absint($entry['user_id'] ?? 0);
        $entry['user_display_name'] = '';
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            $entry['user_display_name'] = $user ? $user->display_name : sprintf('User #%d', $user_id);
        }

        // Cast behaviour to int and add label for output
        $behaviour_int = (int) ($entry['behaviour'] ?? 1);
        $entry['behaviour'] = $behaviour_int;
        $entry['behaviour_label'] = self::behaviour_label($behaviour_int);

        /**
         * Filter a single prepared log entry before output.
         *
         * Allows developers to add or modify fields on each entry.
         *
         * @param array $entry Prepared entry with label fields.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_prepare_entry_for_output', $entry);
    }

    /**
     * Prepare an entry and replace its data payload with a truncated preview.
     *
     * Keeps result sets small enough to stay useful as AI context. The full
     * payload remains reachable through a single-entry lookup.
     *
     * @param array $entry         Raw log entry.
     * @param int   $preview_chars Maximum characters of payload to keep.
     *
     * @return array Prepared entry with data_preview instead of data.
     *
     * @since 1.3.0
     */
    public static function compact_entry(array $entry, $preview_chars = self::DEFAULT_PREVIEW_CHARS)
    {
        $entry = self::prepare_entry($entry);

        $payload = isset($entry['data']) ? $entry['data'] : '';
        unset($entry['data']);

        if ($payload === '' || $payload === null || $payload === []) {
            $entry['data_preview']   = '';
            $entry['data_truncated'] = false;

            return $entry;
        }

        $payload_str = is_string($payload) ? $payload : wp_json_encode($payload);
        $payload_str = (string) $payload_str;

        $preview_chars = max(0, absint($preview_chars));
        $truncated     = mb_strlen($payload_str) > $preview_chars;

        $entry['data_preview']   = $truncated ? mb_substr($payload_str, 0, $preview_chars) : $payload_str;
        $entry['data_truncated'] = $truncated;

        return $entry;
    }

    /**
     * Map a behaviour integer to its string label.
     *
     * @param int $behaviour Behaviour constant value.
     *
     * @return string One of 'error', 'success', 'warning'.
     *
     * @since 1.3.0
     */
    public static function behaviour_label($behaviour)
    {
        $labels = [
            EWP_Logger::BEHAVIOUR_ERROR   => 'error',
            EWP_Logger::BEHAVIOUR_SUCCESS => 'success',
            EWP_Logger::BEHAVIOUR_WARNING => 'warning',
        ];

        $behaviour = (int) $behaviour;

        return isset($labels[$behaviour]) ? $labels[$behaviour] : 'success';
    }

    /**
     * Map a behaviour string label back to its integer constant.
     *
     * @param string $label One of 'error', 'success', 'warning'.
     *
     * @return int|null Behaviour constant, or null when unrecognised.
     *
     * @since 1.3.0
     */
    public static function behaviour_from_label($label)
    {
        $map = [
            'error'   => EWP_Logger::BEHAVIOUR_ERROR,
            'success' => EWP_Logger::BEHAVIOUR_SUCCESS,
            'warning' => EWP_Logger::BEHAVIOUR_WARNING,
        ];

        $label = is_string($label) ? strtolower(trim($label)) : '';

        return isset($map[$label]) ? $map[$label] : null;
    }

    /**
     * Convert a date string to Y-m-d format.
     *
     * Supports both d-m-Y and Y-m-d input. Returns a sanitized value
     * unchanged when parsing fails.
     *
     * @param string $date Date string.
     *
     * @return string Date in Y-m-d format.
     *
     * @since 1.3.0
     */
    public static function normalize_date($date)
    {
        if (empty($date)) {
            return '';
        }

        // Already in Y-m-d format
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return $date;
        }

        // Convert d-m-Y to Y-m-d
        $parsed = \DateTime::createFromFormat('d-m-Y', $date);
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        return sanitize_text_field($date);
    }
}
