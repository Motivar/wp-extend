<?php

namespace EWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-time cleanup of the data left behind by the removed REST API Health
 * screen and EWP logger.
 *
 * Deletes only the exact option, user meta, cron and transient names those
 * features used. No tables are dropped and no files are touched: existing log
 * and capture files stay on disk.
 *
 * @package EWP
 *
 * @since 1.10.1
 */
class Removed_Features_Cleanup
{
    /**
     * Option that records the cleanup already ran.
     *
     * @var string
     */
    const DONE_OPTION = 'ewp_removed_features_cleanup_done';

    /**
     * Options created by REST API Health and the logger.
     *
     * @var string[]
     */
    const OPTIONS = [
        'ewp_rh_monitor_active',
        'ewp_rh_monitor_started',
        'ewp_rh_monitor_count',
        'ewp_rh_monitor_namespaces',
        'ewp_logger_settings',
        'ewp_logger_db_table_dropped',
        'ewp_version_ewp_logs',
    ];

    /**
     * Per-user preferences stored by REST API Health.
     *
     * @var string
     */
    const USER_META = 'ewp_rh_preferences';

    /**
     * Cron hook scheduled by the logger's retention cleanup.
     *
     * @var string
     */
    const CRON_HOOK = 'ewp_logger_cleanup_hook';

    /**
     * Prefix of the namespace-map transients REST API Health cached.
     *
     * @var string
     */
    const TRANSIENT_PREFIX = 'ewp_rh_ns_map_';

    /**
     * Register the one-time run.
     *
     * @return void
     */
    public function init()
    {
        add_action('init', [$this, 'maybe_run']);
    }

    /**
     * Run the cleanup once per site.
     *
     * @return void
     */
    public function maybe_run()
    {
        if (get_option(self::DONE_OPTION)) {
            return;
        }

        $this->run();
        update_option(self::DONE_OPTION, '1.10.1', true);
    }

    /**
     * Delete the leftover options, user meta, cron events and transients.
     *
     * @return void
     */
    public function run()
    {
        global $wpdb;

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }

        delete_metadata('user', 0, self::USER_META, '', true);
        wp_unschedule_hook(self::CRON_HOOK);

        $like = $wpdb->esc_like('_transient_' . self::TRANSIENT_PREFIX) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like));
    }
}
