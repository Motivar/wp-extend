<?php

namespace EWP\Logger;

use EWP\Private_Files;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File storage backend for the EWP Logger.
 *
 * Writes log entries as JSON-lines (one JSON object per line) to daily log files
 * in uploads/ewp-logs/ (filter `ewp_logger_file_directory`). Files are named
 * `ewp-log-{date}-{key}[-{part}].php` with the per-site key of Private_Files and
 * start with `<?php exit; ?>`, so they can neither be guessed nor downloaded on
 * any web server. A day rotates to a new part every max_entries_per_file() entries.
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
     * Directories initialized in this request (path => true).
     *
     * @var array<string, bool>
     */
    private static $initialized_dirs = [];

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
     * Ensure the log directory exists with deny files, and move legacy logs once.
     *
     * @return bool True if directory is ready, false on failure.
     *
     * @since 1.0.0
     * @since 1.9.0 Deny files come from Private_Files; legacy `.log` files are migrated.
     */
    private function init_directory()
    {
        if (isset(self::$initialized_dirs[$this->log_dir])) {
            return true;
        }

        if (!Private_Files::secure_dir($this->log_dir)) {
            error_log('EWP Logger: Failed to create log directory: ' . $this->log_dir);
            return false;
        }

        self::$initialized_dirs[$this->log_dir] = true;
        $this->migrate_legacy_files();
        return true;
    }

    /**
     * Move public `ewp-log-YYYY-MM-DD.log` files (before 1.9.0) to guarded, keyed `.php` files.
     *
     * Each legacy file becomes the next free part of its day. Runs under the
     * index lock; a file that cannot be copied stays where it is and is
     * retried on the next request.
     *
     * @return void
     *
     * @since 1.9.0
     */
    private function migrate_legacy_files()
    {
        $legacy = glob($this->log_dir . '/ewp-log-*.log');
        if (empty($legacy)) {
            return;
        }

        $this->with_lock(function (array $index) use ($legacy) {
            foreach ($legacy as $file) {
                if (!preg_match('/^ewp-log-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                    continue;
                }
                $parts  = $this->parts_for_date($m[1]);
                $target = $this->get_file_path($m[1], empty($parts) ? 1 : max(array_keys($parts)) + 1);
                $in     = fopen($file, 'r');
                $out    = $in ? fopen($target, 'x') : false;
                if (!$in || !$out) {
                    if ($in) {
                        fclose($in);
                    }
                    continue;
                }
                fwrite($out, Private_Files::GUARD);
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                unlink($file);
                unset($index[$m[1]]);
            }
            return $index;
        });
    }

    /**
     * Get the log file path for a date and part.
     *
     * Part 1 is `ewp-log-{date}-{key}.php`, later parts `ewp-log-{date}-{key}-{n}.php`.
     *
     * @param string $date Date string (Y-m-d). Defaults to today.
     * @param int    $part Optional. Part number, 1-based. Default 1.
     *
     * @return string Absolute file path.
     *
     * @since 1.0.0
     * @since 1.9.0 Keyed `.php` name and `$part`.
     */
    private function get_file_path($date = '', $part = 1)
    {
        if (empty($date)) {
            $date = current_time('Y-m-d');
        }
        $suffix = $part > 1 ? '-' . (int) $part : '';

        return $this->log_dir . '/ewp-log-' . sanitize_file_name($date) . '-' . Private_Files::key() . $suffix . '.php';
    }

    /**
     * Parse a log file name.
     *
     * Accepts any 32-hex key so logs survive a reset of the key option.
     *
     * @param string $file Required. Path or base name.
     *
     * @return array|null `['date' => 'Y-m-d', 'part' => int]`, or null for other files.
     *
     * @since 1.9.0
     */
    private static function parse_file_name($file)
    {
        if (!preg_match('/^ewp-log-(\d{4}-\d{2}-\d{2})-[a-f0-9]{32}(?:-(\d+))?\.php$/', basename($file), $m)) {
            return null;
        }
        return ['date' => $m[1], 'part' => isset($m[2]) ? (int) $m[2] : 1];
    }

    /**
     * Every log file, oldest day and part first.
     *
     * @return array<string, array> Path => parsed name (see parse_file_name()).
     *
     * @since 1.9.0
     */
    private function list_files()
    {
        $files = [];
        foreach ((array) glob($this->log_dir . '/ewp-log-*.php') as $file) {
            $parsed = self::parse_file_name($file);
            if ($parsed !== null) {
                $files[$file] = $parsed;
            }
        }
        uasort($files, function ($a, $b) {
            return [$a['date'], $a['part']] <=> [$b['date'], $b['part']];
        });
        return $files;
    }

    /**
     * Existing parts of one day.
     *
     * @param string $date Required. Date (Y-m-d).
     *
     * @return array<int, string> Part number => path.
     *
     * @since 1.9.0
     */
    private function parts_for_date($date)
    {
        $parts = [];
        foreach ($this->list_files() as $file => $parsed) {
            if ($parsed['date'] === $date) {
                $parts[$parsed['part']] = $file;
            }
        }
        return $parts;
    }

    /**
     * Maximum number of entries in one log file before a new part of the day starts.
     *
     * Setting "Max entries per log file" (default 5000), filtered by
     * `ewp_logger_max_entries_per_file`; never below 100.
     *
     * @return int
     *
     * @since 1.9.0
     */
    public static function max_entries_per_file()
    {
        $settings = EWP_Logger_Settings::get_settings();
        $max      = isset($settings['max_entries_per_file']) ? (int) $settings['max_entries_per_file'] : 5000;

        /**
         * Filter the maximum number of entries per log file.
         *
         * When a day's current file holds this many entries, new entries go to
         * the next part (`ewp-log-{date}-{key}-2.php`, `-3`, …). Values below
         * 100 are raised to 100.
         *
         * @param int $max Entries per file (setting `max_entries_per_file`, default 5000).
         *
         * @since 1.9.0
         */
        return max(100, (int) apply_filters('ewp_logger_max_entries_per_file', $max));
    }

    /**
     * Run a callback while holding the exclusive index lock.
     *
     * The index (`ewp-log-index-{key}.php`) maps each recent day to its
     * current part and that part's entry count, so a write never has to
     * count lines. Holding its lock also serialises writers and rewrites.
     *
     * @param callable $callback Required. Receives the index array, returns the index to store.
     *
     * @return bool False when the index file could not be opened or locked.
     *
     * @since 1.9.0
     */
    private function with_lock(callable $callback)
    {
        $handle = fopen($this->log_dir . '/ewp-log-index-' . Private_Files::key() . '.php', 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) {
                fclose($handle);
            }
            return false;
        }

        $index = json_decode(Private_Files::strip_guard((string) stream_get_contents($handle)), true);
        $index = $callback(is_array($index) ? $index : []);

        // Keep the last week of days; older days never receive entries.
        krsort($index);
        $index = array_slice((array) $index, 0, 7, true);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, Private_Files::GUARD . wp_json_encode($index));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return true;
    }

    /**
     * Current part of a day and its entry count, from the index or the files.
     *
     * @param array  $index Required. Index read under the lock.
     * @param string $date  Required. Date (Y-m-d).
     *
     * @return int[] `[part, count]`.
     *
     * @since 1.9.0
     */
    private function part_state(array $index, $date)
    {
        if (isset($index[$date]) && is_array($index[$date]) && count($index[$date]) === 2) {
            return [max(1, (int) $index[$date][0]), max(0, (int) $index[$date][1])];
        }

        $parts = $this->parts_for_date($date);
        if (empty($parts)) {
            return [1, 0];
        }
        $part = max(array_keys($parts));
        return [$part, self::count_entries($parts[$part])];
    }

    /**
     * Count the entries (non-empty lines after the guard) of a log file.
     *
     * @param string $file Required. Absolute path.
     *
     * @return int
     *
     * @since 1.9.0
     */
    private static function count_entries($file)
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            return 0;
        }
        $count = 0;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    /**
     * Encode one entry as a JSON line.
     *
     * @param array $entry Required. Entry from the queue.
     *
     * @return string JSON without a trailing newline.
     *
     * @since 1.9.0
     */
    private static function encode_entry(array $entry)
    {
        return wp_json_encode([
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
    }

    /**
     * Append lines to a log file, writing the guard first when the file is new.
     *
     * @param string $file  Required. Absolute path.
     * @param string $lines Required. Newline-terminated JSON lines.
     *
     * @return bool
     *
     * @since 1.9.0
     */
    private static function append($file, $lines)
    {
        $prefix = file_exists($file) ? '' : Private_Files::GUARD;
        if (file_put_contents($file, $prefix . $lines, FILE_APPEND | LOCK_EX) === false) {
            error_log('EWP Logger: Failed to write to log file: ' . $file);
            return false;
        }
        return true;
    }

    /**
     * Insert one or more log entries by appending to the day's current log file.
     *
     * A day's file holds at most max_entries_per_file() entries; the rest go
     * to the next part.
     *
     * @param array $entries Array of log entry arrays.
     *
     * @return bool True on success, false on failure.
     *
     * @since 1.0.0
     * @since 1.9.0 Rotates to a new part at max_entries_per_file().
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

        $max     = self::max_entries_per_file();
        $success = true;

        $locked = $this->with_lock(function (array $index) use ($grouped, $max, &$success) {
            foreach ($grouped as $date => $date_entries) {
                list($part, $count) = $this->part_state($index, $date);
                $by_part = [];

                foreach ($date_entries as $entry) {
                    if ($count >= $max) {
                        $part++;
                        $count = 0;
                    }
                    $by_part[$part] = ($by_part[$part] ?? '') . self::encode_entry($entry) . "\n";
                    $count++;
                }

                foreach ($by_part as $n => $lines) {
                    $success = self::append($this->get_file_path($date, $n), $lines) && $success;
                }
                $index[$date] = [$part, $count];
            }
            return $index;
        });

        if (!$locked) {
            error_log('EWP Logger: Failed to lock the log index in ' . $this->log_dir);
            return false;
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

        foreach ($this->list_files() as $file => $parsed) {
            $file_time = strtotime($parsed['date']);

            if ($file_time !== false && $file_time < $cutoff && unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete log entries matching the given filter arguments.
     *
     * Reads each file in the date range, rewrites it keeping only
     * entries that do NOT match the filters. Deletes empty files.
     *
     * @param array $args Filter arguments (same as query, without limit/offset/order).
     *
     * @return int Number of entries deleted, or -1 on failure.
     *
     * @since 1.2.0
     */
    public function delete_by_filters(array $args = [])
    {
        if (!$this->init_directory()) {
            return -1;
        }

        $args  = $this->normalize_query_args($args);
        $files = $this->get_files_in_range($args['date_from'], $args['date_to']);

        if (empty($files)) {
            return 0;
        }

        $deleted = 0;

        // Under the index lock so no entry is appended between the read and the rewrite.
        $this->with_lock(function (array $index) use ($files, $args, &$deleted) {
            foreach ($files as $file) {
                $deleted += $this->delete_from_file($file, $args);
            }
            // Counts changed; part_state() recounts the touched days on the next write.
            return array_diff_key($index, array_flip(array_map(function ($file) {
                return self::parse_file_name($file)['date'];
            }, $files)));
        });

        return $deleted;
    }

    /**
     * Remove the entries matching the filters from one file; delete it when nothing is left.
     *
     * @param string $file Required. Absolute path.
     * @param array  $args Required. Normalized filter arguments.
     *
     * @return int Number of entries removed.
     *
     * @since 1.9.0 Extracted from delete_by_filters().
     */
    private function delete_from_file($file, array $args)
    {
        $deleted = 0;
        $handle  = fopen($file, 'r');
        if (!$handle) {
            return 0;
        }

        $keep_lines = [];

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if (empty($trimmed) || $trimmed === trim(Private_Files::GUARD)) {
                continue;
            }

            $entry = json_decode($trimmed, true);
            if (!is_array($entry)) {
                $keep_lines[] = $trimmed;
                continue;
            }

            // Entry matches filters → delete it (don't keep)
            if ($this->entry_matches_filters($entry, $args)) {
                $deleted++;
                continue;
            }

            $keep_lines[] = $trimmed;
        }

        fclose($handle);

        // Rewrite or remove the file
        if (empty($keep_lines)) {
            unlink($file);
            return $deleted;
        }

        file_put_contents($file, Private_Files::GUARD . implode("\n", $keep_lines) . "\n", LOCK_EX);

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
        $from     = empty($date_from) ? '' : substr($date_from, 0, 10);
        $to       = empty($date_to) ? '' : substr($date_to, 0, 10);
        $filtered = [];

        foreach ($this->list_files() as $file => $parsed) {
            if (($from !== '' && $parsed['date'] < $from) || ($to !== '' && $parsed['date'] > $to)) {
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

        if (!empty($args['object_id'])) {
            $entry_object_id = absint($entry['object_id'] ?? 0);
            $allowed         = array_map('absint', (array) $args['object_id']);
            if (!in_array($entry_object_id, $allowed, true)) {
                return false;
            }
        }

        if (!empty($args['search_text'])) {
            $search = strtolower($args['search_text']);
            $searchable_fields = [
                $entry['message'] ?? '',
                $entry['owner'] ?? '',
                $entry['action_type'] ?? '',
                $entry['object_type'] ?? '',
                $entry['request_context'] ?? '',
            ];

            // Add data payload (handle JSON/serialized)
            if (!empty($entry['data'])) {
                $data_str = is_string($entry['data']) ? $entry['data'] : wp_json_encode($entry['data']);
                $searchable_fields[] = $data_str;
            }

            // Add user display name if user_id exists
            if (!empty($entry['user_id'])) {
                $user = get_userdata(absint($entry['user_id']));
                if ($user) {
                    $searchable_fields[] = $user->display_name;
                    $searchable_fields[] = $user->user_login;
                    $searchable_fields[] = $user->user_email;
                }
            }

            // Check if search term appears in any field (case-insensitive)
            $found = false;
            foreach ($searchable_fields as $field) {
                if (stripos($field, $search) !== false) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        /**
         * Filter whether a log entry matches the current query arguments.
         *
         * Runs after all built-in filters have passed. Plugins that register
         * extra filter fields (via `ewp_logger_filter_params`) use this to
         * apply them, since the built-in matcher only understands core args.
         * Return false to exclude the entry.
         *
         * @param bool  $matches True when the entry passed all built-in filters.
         * @param array $entry   The log entry being tested.
         * @param array $args    Normalized query arguments.
         *
         * @since 1.3.0
         */
        return (bool) apply_filters('ewp_logger_entry_matches_filters', true, $entry, $args);
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
