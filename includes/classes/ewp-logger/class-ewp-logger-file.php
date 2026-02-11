<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File storage backend for the EWP Logger.
 *
 * Writes log entries as JSON-lines (one JSON object per line) to daily log files
 * in the wp-content/ewp-logs/ directory. Includes security protections (.htaccess, index.php).
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_File extends EWP_Logger_Storage
{
    /**
     * Log directory path.
     *
     * @var string
     */
    private $log_dir;

    /**
     * Whether the log directory has been initialized.
     *
     * @var bool
     */
    private static $dir_initialized = false;

    /**
     * Constructor.
     *
     * Sets the log directory path and ensures it exists with security files.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $default_dir = $upload_dir['basedir'] . '/ewp-logs';

        /**
         * Filter the EWP Logger file storage directory path.
         *
         * Default: {uploads}/ewp-logs — works in standard WP and Bedrock.
         *
         * @param string $log_dir Absolute path to the log directory.
         *
         * @since 1.0.0
         */
        $this->log_dir = apply_filters(
            'ewp_logger_file_directory',
            $default_dir
        );
    }

    /**
     * Ensure the log directory exists with security protections.
     *
     * Creates .htaccess and index.php to prevent direct browsing.
     *
     * @return bool True if directory is ready, false on failure.
     *
     * @since 1.0.0
     */
    private function init_directory()
    {
        if (self::$dir_initialized) {
            return true;
        }

        if (!file_exists($this->log_dir)) {
            $created = wp_mkdir_p($this->log_dir);
            if (!$created) {
                error_log('EWP Logger: Failed to create log directory: ' . $this->log_dir);
                return false;
            }
        }

        // Security: .htaccess to deny direct access (Apache)
        $htaccess = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Deny direct access to log files\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
            );
        }

        // Security: index.php to prevent directory listing
        $index = $this->log_dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        // Security: empty index.html fallback for servers ignoring index.php
        $index_html = $this->log_dir . '/index.html';
        if (!file_exists($index_html)) {
            file_put_contents($index_html, '');
        }

        self::$dir_initialized = true;
        return true;
    }

    /**
     * Get the log file path for a given date.
     *
     * @param string $date Date string (Y-m-d). Defaults to today.
     *
     * @return string Absolute file path.
     *
     * @since 1.0.0
     */
    private function get_file_path($date = '')
    {
        if (empty($date)) {
            $date = current_time('Y-m-d');
        }

        return $this->log_dir . '/ewp-log-' . sanitize_file_name($date) . '.log';
    }

    /**
     * Insert one or more log entries by appending to the daily log file.
     *
     * @param array $entries Array of log entry arrays.
     *
     * @return bool True on success, false on failure.
     *
     * @since 1.0.0
     */
    public function insert(array $entries)
    {
        if (empty($entries)) {
            return true;
        }

        if (!$this->init_directory()) {
            return false;
        }

        // Group entries by date for efficient file writes
        $grouped = [];
        foreach ($entries as $entry) {
            $date = substr($entry['created_at'] ?? current_time('Y-m-d'), 0, 10);
            $grouped[$date][] = $entry;
        }

        $success = true;

        foreach ($grouped as $date => $date_entries) {
            $file_path = $this->get_file_path($date);
            $lines     = '';

            foreach ($date_entries as $entry) {
                $line = wp_json_encode([
                    'log_id'          => uniqid('ewp_', true),
                    'owner'           => sanitize_text_field($entry['owner'] ?? ''),
                    'action_type'     => sanitize_text_field($entry['action_type'] ?? ''),
                    'object_type'     => sanitize_text_field($entry['object_type'] ?? ''),
                    'behaviour'       => isset($entry['behaviour']) ? (int) $entry['behaviour'] : 1,
                    'level'           => sanitize_text_field($entry['level'] ?? 'editor'),
                    'user_id'         => absint($entry['user_id'] ?? 0),
                    'object_id'       => absint($entry['object_id'] ?? 0),
                    'message'         => $entry['message'] ?? '',
                    'data'            => $entry['data'] ?? '',
                    'request_id'      => sanitize_text_field($entry['request_id'] ?? ''),
                    'request_context' => sanitize_text_field($entry['request_context'] ?? ''),
                    'created_at'      => $entry['created_at'] ?? current_time('mysql'),
                ]);

                $lines .= $line . "\n";
            }

            $result = file_put_contents($file_path, $lines, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log('EWP Logger: Failed to write to log file: ' . $file_path);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Query log entries from log files with filtering and pagination.
     *
     * Reads and parses JSON-lines files within the date range, applies filters,
     * then returns the requested page of results.
     *
     * @param array $args Query arguments (see EWP_Logger_Storage::query).
     *
     * @return array Array of log entry arrays.
     *
     * @since 1.0.0
     */
    public function query(array $args = [])
    {
        if (!$this->init_directory()) {
            return [];
        }

        $args    = $this->normalize_query_args($args);
        $entries = $this->read_and_filter($args);

        // Sort by created_at
        usort($entries, function ($a, $b) use ($args) {
            $cmp = strcmp($a['created_at'], $b['created_at']);
            return ($args['order'] === 'DESC') ? -$cmp : $cmp;
        });

        // Apply pagination
        return array_slice($entries, $args['offset'], $args['limit']);
    }

    /**
     * Count log entries matching the given filters.
     *
     * @param array $args Filter arguments.
     *
     * @return int Total count.
     *
     * @since 1.0.0
     */
    public function count(array $args = [])
    {
        if (!$this->init_directory()) {
            return 0;
        }

        $args    = $this->normalize_query_args($args);
        $entries = $this->read_and_filter($args);

        return count($entries);
    }

    /**
     * Delete log files older than a given number of months.
     *
     * @param int $months Number of months.
     *
     * @return int Number of files deleted, or -1 on failure.
     *
     * @since 1.0.0
     */
    public function delete_older_than($months)
    {
        if (!$this->init_directory()) {
            return -1;
        }

        $months  = max(1, absint($months));
        $cutoff  = strtotime("-{$months} months");
        $deleted = 0;

        $files = glob($this->log_dir . '/ewp-log-*.log');
        if (empty($files)) {
            return 0;
        }

        foreach ($files as $file) {
            // Extract date from filename: ewp-log-YYYY-MM-DD.log
            $basename = basename($file, '.log');
            $date_str = str_replace('ewp-log-', '', $basename);
            $file_time = strtotime($date_str);

            if ($file_time === false) {
                continue;
            }

            if ($file_time < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Read log files and filter entries based on query args.
     *
     * @param array $args Normalized query arguments.
     *
     * @return array Filtered log entries.
     *
     * @since 1.0.0
     */
    private function read_and_filter(array $args)
    {
        $files = $this->get_files_in_range($args['date_from'], $args['date_to']);

        if (empty($files)) {
            return [];
        }

        $entries = [];

        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    continue;
                }

                if (!$this->entry_matches_filters($entry, $args)) {
                    continue;
                }

                $entries[] = $entry;
            }

            fclose($handle);
        }

        return $entries;
    }

    /**
     * Get log files within a date range.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     *
     * @return array Array of file paths.
     *
     * @since 1.0.0
     */
    private function get_files_in_range($date_from, $date_to)
    {
        $files = glob($this->log_dir . '/ewp-log-*.log');
        if (empty($files)) {
            return [];
        }

        // No date filter — return all
        if (empty($date_from) && empty($date_to)) {
            return $files;
        }

        $filtered = [];
        foreach ($files as $file) {
            $basename  = basename($file, '.log');
            $date_str  = str_replace('ewp-log-', '', $basename);
            $file_date = strtotime($date_str);

            if ($file_date === false) {
                continue;
            }

            if (!empty($date_from) && $file_date < strtotime(substr($date_from, 0, 10))) {
                continue;
            }

            if (!empty($date_to) && $file_date > strtotime(substr($date_to, 0, 10))) {
                continue;
            }

            $filtered[] = $file;
        }

        return $filtered;
    }

    /**
     * Check if a log entry matches all active filters.
     *
     * @param array $entry Log entry.
     * @param array $args  Normalized query arguments.
     *
     * @return bool True if the entry matches.
     *
     * @since 1.0.0
     */
    private function entry_matches_filters(array $entry, array $args)
    {
        if (!empty($args['owner']) && !$this->value_matches($entry['owner'] ?? '', $args['owner'])) {
            return false;
        }

        if (!empty($args['action_type']) && !$this->value_matches($entry['action_type'] ?? '', $args['action_type'])) {
            return false;
        }

        if (!empty($args['object_type']) && !$this->value_matches($entry['object_type'] ?? '', $args['object_type'])) {
            return false;
        }

        if ($args['behaviour'] !== null && $args['behaviour'] !== '') {
            $entry_val = (int) ($entry['behaviour'] ?? 1);
            if (is_array($args['behaviour'])) {
                if (!in_array($entry_val, array_map('intval', $args['behaviour']), true)) {
                    return false;
                }
            } elseif ($entry_val !== (int) $args['behaviour']) {
                return false;
            }
        }

        if (!empty($args['level'])) {
            $entry_level = $entry['level'] ?? '';
            if (is_array($args['level'])) {
                if (!in_array($entry_level, $args['level'], true)) {
                    return false;
                }
            } elseif ($entry_level !== $args['level']) {
                return false;
            }
        }

        if (!empty($args['user_id']) && absint($entry['user_id'] ?? 0) !== $args['user_id']) {
            return false;
        }

        if (!empty($args['date_from']) && ($entry['created_at'] ?? '') < $args['date_from']) {
            return false;
        }

        if (!empty($args['date_to']) && ($entry['created_at'] ?? '') > $args['date_to']) {
            return false;
        }

        if (!empty($args['request_id']) && ($entry['request_id'] ?? '') !== $args['request_id']) {
            return false;
        }

        return true;
    }

    /**
     * Check if an entry value matches a scalar or array filter.
     *
     * @param string       $entry_value Value from the log entry.
     * @param string|array $filter      Scalar or array of allowed values.
     *
     * @return bool True if matched.
     *
     * @since 1.2.0
     */
    private function value_matches($entry_value, $filter)
    {
        if (is_array($filter)) {
            return in_array($entry_value, $filter, true);
        }

        return $entry_value === $filter;
    }
}
