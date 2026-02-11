<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * In-memory queue for the EWP Logger.
 *
 * Collects log entries during a request and flushes them in a single batch
 * on the WordPress 'shutdown' hook. If the queue exceeds a configurable threshold
 * mid-request, an early flush is triggered to prevent memory issues.
 *
 * Falls back to error_log() if the storage flush fails, ensuring no data is lost silently.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_Queue
{
    /**
     * Queued log entries waiting to be flushed.
     *
     * @var array
     */
    private static $queue = [];

    /**
     * Whether the shutdown hook has been registered.
     *
     * @var bool
     */
    private static $shutdown_registered = false;

    /**
     * Maximum queue size before an early flush is triggered.
     *
     * @var int
     */
    private static $flush_threshold = 50;

    /**
     * The storage backend instance used for flushing.
     *
     * @var EWP_Logger_Storage|null
     */
    private static $storage = null;

    /**
     * Initialize the queue with a storage backend.
     *
     * Registers the shutdown hook for automatic flushing.
     *
     * @param EWP_Logger_Storage $storage The storage backend to flush entries to.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function init(EWP_Logger_Storage $storage)
    {
        self::$storage = $storage;

        /**
         * Filter the queue flush threshold.
         *
         * Controls how many entries can accumulate before an early flush is triggered.
         *
         * @param int $threshold Default 50.
         *
         * @since 1.0.0
         */
        self::$flush_threshold = (int) apply_filters('ewp_logger_queue_flush_threshold', 50);

        if (!self::$shutdown_registered) {
            add_action('shutdown', [__CLASS__, 'flush'], 9999);
            self::$shutdown_registered = true;
        }
    }

    /**
     * Add a log entry to the queue.
     *
     * If the queue exceeds the flush threshold, triggers an early flush.
     *
     * @param array $entry Log entry array with keys:
     *   owner, action_type, object_type, behaviour, level, user_id, object_id, message, data, created_at.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function add(array $entry)
    {
        self::$queue[] = $entry;

        // Early flush if threshold exceeded
        if (count(self::$queue) >= self::$flush_threshold) {
            self::flush();
        }
    }

    /**
     * Flush all queued entries to the storage backend.
     *
     * Called automatically on shutdown or when the threshold is exceeded.
     * Falls back to error_log() if the storage insert fails.
     *
     * @return bool True if flush succeeded (or queue was empty), false on failure.
     *
     * @since 1.0.0
     */
    public static function flush()
    {
        if (empty(self::$queue)) {
            return true;
        }

        if (!self::$storage instanceof EWP_Logger_Storage) {
            self::fallback_log();
            self::$queue = [];
            return false;
        }

        $entries     = self::$queue;
        self::$queue = [];

        $result = self::$storage->insert($entries);

        if (!$result) {
            // Fallback: write entries to PHP error log so data is not lost
            self::fallback_log($entries);
            return false;
        }

        return true;
    }

    /**
     * Get the current queue size.
     *
     * @return int Number of entries in the queue.
     *
     * @since 1.0.0
     */
    public static function get_queue_size()
    {
        return count(self::$queue);
    }

    /**
     * Write entries to PHP error_log as a safety fallback.
     *
     * @param array $entries Entries to log. If empty, uses current queue.
     *
     * @return void
     *
     * @since 1.0.0
     */
    private static function fallback_log(array $entries = [])
    {
        $items = !empty($entries) ? $entries : self::$queue;

        foreach ($items as $entry) {
            error_log(sprintf(
                '[EWP Logger Fallback] owner=%s action=%s object_type=%s behaviour=%d level=%s message=%s',
                $entry['owner'] ?? '',
                $entry['action_type'] ?? '',
                $entry['object_type'] ?? '',
                $entry['behaviour'] ?? 1,
                $entry['level'] ?? 'editor',
                $entry['message'] ?? ''
            ));
        }
    }

    /**
     * Reset the queue state. Used primarily for testing.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function reset()
    {
        self::$queue              = [];
        self::$storage            = null;
        self::$shutdown_registered = false;
    }
}
