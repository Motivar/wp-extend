<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron-based data retention cleanup for the EWP Logger.
 *
 * Schedules a daily WordPress cron job to delete log entries older than
 * the configured retention period. Logs the cleanup action itself at developer level.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_Cleanup
{
    /**
     * Cron hook name.
     *
     * @var string
     */
    private static $cron_hook = 'ewp_logger_cleanup_hook';

    /**
     * Storage backend instance.
     *
     * @var EWP_Logger_Storage
     */
    private $storage;

    /**
     * Constructor.
     *
     * @param EWP_Logger_Storage $storage The storage backend.
     *
     * @since 1.0.0
     */
    public function __construct(EWP_Logger_Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Initialize the cleanup system.
     *
     * Registers the cron schedule and the cleanup callback.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_action('init', [$this, 'schedule_cleanup']);
        add_action(self::$cron_hook, [$this, 'perform_cleanup']);
    }

    /**
     * Schedule the daily cleanup cron job if not already scheduled.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function schedule_cleanup()
    {
        if (!wp_next_scheduled(self::$cron_hook)) {
            wp_schedule_event(time(), 'daily', self::$cron_hook);
        }
    }

    /**
     * Perform the cleanup by deleting entries older than the retention period.
     *
     * Reads the retention months from settings and delegates to the storage backend.
     * Logs the cleanup action itself at developer level.
     *
     * @return array {
     *   @type int    $deleted         Number of entries deleted.
     *   @type string $cutoff_date     The cutoff date used.
     *   @type int    $retention_months Retention period used.
     * }
     *
     * @since 1.0.0
     */
    public function perform_cleanup()
    {
        $settings = EWP_Logger_Settings::get_settings();

        /**
         * Filter the retention period (in months) used for cleanup.
         *
         * @param int $months Retention period in months.
         *
         * @since 1.0.0
         */
        $months = isset($settings['retention_months']) ? $settings['retention_months'] : 6;
        $months = max(1, $months);

        $deleted     = $this->storage->delete_older_than($months);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        $result = [
            'deleted'          => $deleted,
            'cutoff_date'      => $cutoff_date,
            'retention_months' => $months,
        ];

        // Log the cleanup action itself (only if something was deleted)
        if ($deleted > 0) {
            EWP_Logger::log(
                'extend-wp',
                'log_cleanup',
                sprintf('Automatic cleanup removed %d old log entries (older than %d months)', $deleted, $months),
                $result,
                'developer',
                'system',
                true
            );
        }

        /**
         * Fires after the logger cleanup is completed.
         *
         * @param array $result Cleanup results.
         *
         * @since 1.0.0
         */
        do_action('ewp_logger_cleanup_completed', $result);

        return $result;
    }

    /**
     * Manually trigger cleanup. Useful from WP-CLI or admin UI.
     *
     * @param int $months Optional override for retention months.
     *
     * @return array Cleanup results.
     *
     * @since 1.0.0
     */
    public function manual_cleanup($months = 0)
    {
        if ($months > 0) {
            add_filter('ewp_logger_retention_months', function () use ($months) {
                return $months;
            }, 9999);
        }

        return $this->perform_cleanup();
    }

    /**
     * Unschedule the cleanup cron job. Used on plugin deactivation.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function unschedule()
    {
        $timestamp = wp_next_scheduled(self::$cron_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::$cron_hook);
        }
    }
}