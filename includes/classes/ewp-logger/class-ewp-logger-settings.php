<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page for the EWP Logger.
 *
 * Registers an options page via the awm_add_options_boxes_filter hook,
 * allowing administrators to configure storage backend, retention period,
 * default log level, and enable/disable logging.
 *
 * Settings are stored as a single WP option: ewp_logger_settings.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_Settings
{
    /**
     * Option name in wp_options.
     *
     * @var string
     */
    private static $option_key = 'ewp_logger_settings';

    /**
     * Cached settings array.
     *
     * @var array|null
     */
    private static $settings_cache = null;

    /**
     * Initialize the settings hooks.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_filter('awm_add_options_boxes_filter', [$this, 'register_options_page'], 100);
    }

    /**
     * Register the logger settings as an EWP options page.
     *
     * @param array $options Existing options pages.
     *
     * @return array Modified options pages array.
     *
     * @since 1.0.0
     */
    public function register_options_page($options)
    {
        $options['ewp-logger-settings'] = [
            'title'    => __('Logger Settings', 'extend-wp'),
            'callback' => [$this, 'get_settings_fields'],
            'parent'   => 'extend-wp',
            'order'    => 9000000000000000000,
            'cap'      => 'manage_options',
        ];

        return $options;
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
     
            'ewp_logger_enabled' => [
                'label'       => __('Enable Logging', 'extend-wp'),
                'case'        => 'input',
                'type'        => 'checkbox',
                'explanation' => __('Enable or disable the EWP logging system globally.', 'extend-wp'),
            ],
            'ewp_logger_storage' => [
                'label'   => __('Storage Backend', 'extend-wp'),
                'case'    => 'select',
                'default'=>'file',
                'options' => [
                    'db'   => ['label' => __('Database', 'extend-wp')],
                    'file' => ['label' => __('Log File', 'extend-wp')],
                ],
                'explanation' => __('Choose where log entries are stored. Database is recommended for most sites.', 'extend-wp'),
            ],
            'ewp_logger_retention_months' => [
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
     * Modify how option values are returned for the logger settings page.
     *
     * When the current options page is ewp-logger-settings, values come from
     * the nested ewp_logger_settings option array instead of individual options.
     *
     * @param mixed $value   The value from get_option.
     * @param array $data    The field data.
     * @param array $options All options for this options page.
     *
     * @return mixed Modified value.
     *
     * @since 1.0.0
     */
    public function change_option_value($value, $data, $options)
    {
        if ($options['id'] !== 'ewp-logger-settings') {
            return $value;
        }

        return isset($data['attributes']['value']) ? $data['attributes']['value'] : '';
    }

    /**
     * Get the current logger settings with defaults.
     *
     * Dynamically reads all field keys and defaults from get_settings_fields().
     * Each field key is read from wp_options via get_option().
     * No hardcoded keys — the single source of truth is get_settings_fields().
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

        // Single source of truth: derive keys + defaults from field definitions
        $instance = new self();
        $fields   = $instance->get_settings_fields();
        $settings = [];

        foreach ($fields as $option_key => $field) {
            $default            = isset($field['default']) ? $field['default'] : '';
            $settings[$option_key] = get_option($option_key, $default);
        }

        /**
         * Filter the resolved logger settings before caching.
         *
         * @param array $settings Resolved settings keyed by option name.
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