<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger settings for the EWP Logger.
 *
 * Registers a "Logger Settings" section on the main Extend WP admin page
 * via the ewp_admin_fields_filter hook. Each field is stored as an
 * individual wp_option (ewp_logger_enabled, ewp_logger_storage, etc.).
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.2.0
 *
 * @since 1.0.0
 */
class EWP_Logger_Settings
{
    /**
     * Cached settings array.
     *
     * @var array|null
     */
    private static $settings_cache = null;

    /**
     * Initialize the settings hooks.
     *
     * Registers logger fields as a section on the main EWP admin page
     * via the ewp_admin_fields_filter hook.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_filter('ewp_admin_fields_filter', [$this, 'register_admin_fields'], 100);
    }

    /**
     * Add logger settings as a section on the main EWP admin page.
     *
     * Follows the same section + include pattern as ewp_dev_settings.
     * Each field key inside include is registered individually via
     * register_setting('extend-wp', $key) and stored as a separate wp_option.
     *
     * @param array $fields Existing admin fields from ewp_admin_fields_filter.
     *
     * @return array Modified fields array with logger section appended.
     *
     * @since 1.2.0
     */
    public function register_admin_fields($fields)
    {
        $fields['ewp_logger_settings'] = [
            'case'    => 'section',
            'label'   => __('Logger Settings', 'extend-wp'),
            'include' => $this->get_settings_fields(),
        ];

        return $fields;
    }

    /**
     * Return the settings fields definition for the options page.
     *
     * Uses the same structure as other EWP options pages (awm_show_content format).
     *
     * @return array Settings fields array.
     *
     * @since 1.0.0
     */
    public function get_settings_fields()
    {
        /**
         * Filter the logger settings fields.
         *
         * Allows external plugins to add extra settings to the logger config page.
         *
         * @param array $fields Settings field definitions.
         *
         * @since 1.0.0
         */
        return apply_filters('ewp_logger_settings_fields', [

            'enabled' => [
                'label'       => __('Enable Logging', 'extend-wp'),
                'case'        => 'input',
                'type'        => 'checkbox',
                'explanation' => __('Enable or disable the EWP logging system globally.', 'extend-wp'),
            ],
            'storage' => [
                'label'   => __('Storage Backend', 'extend-wp'),
                'case'    => 'select',
                'default' => 'file',
                'options' => [
                    'db'   => ['label' => __('Database', 'extend-wp')],
                    'file' => ['label' => __('Log File', 'extend-wp')],
                ],
                'explanation' => __('Choose where log entries are stored. Database is recommended for most sites.', 'extend-wp'),
            ],
            'retention_months' => [
                'label'       => __('Retention Period (months)', 'extend-wp'),
                'case'        => 'input',
                'type'        => 'number',
                'attributes'  => ['min' => 1, 'max' => 12],
                'default'     => 6,
                'explanation' => __('Number of months to keep log data. Older entries are automatically deleted.', 'extend-wp'),
            ]
        ]);
    }

    /**
     * Option key in wp_options (section stores fields as one serialised array).
     *
     * @var string
     */
    private static $option_key = 'ewp_logger_settings';

    /**
     * Get the current logger settings with defaults.
     *
     * Reads a single array option (ewp_logger_settings) and merges
     * with defaults derived from get_settings_fields().
     * Same pattern as ewp_auto_export_settings / ewp_auto_import_settings.
     *
     * @return array Associative array of setting key => value.
     *
     * @since 1.0.0
     */
    public static function get_settings()
    {
        if (self::$settings_cache !== null) {
            return self::$settings_cache;
        }

        // Stored option (single array)
        $stored = get_option(self::$option_key, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        // Derive defaults from field definitions (single source of truth)
        $instance = new self();
        $fields   = $instance->get_settings_fields();
        $settings = [];

        foreach ($fields as $key => $field) {
            $default        = isset($field['default']) ? $field['default'] : '';
            $settings[$key] = isset($stored[$key]) ? $stored[$key] : $default;
        }

        /**
         * Filter the resolved logger settings before caching.
         *
         * @param array $settings Resolved settings keyed by short field name.
         * @param array $fields   Raw field definitions from get_settings_fields().
         *
         * @since 1.2.0
         */
        $settings = apply_filters('ewp_logger_resolved_settings', $settings, $fields);

        self::$settings_cache = $settings;

        return $settings;
    }

    /**
     * Reset the settings cache. Useful after saving options.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function reset_cache()
    {
        self::$settings_cache = null;
    }
}