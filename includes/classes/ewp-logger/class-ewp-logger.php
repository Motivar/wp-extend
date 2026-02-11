<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-ewp-logger-storage.php';
require_once __DIR__ . '/class-ewp-logger-db.php';
require_once __DIR__ . '/class-ewp-logger-file.php';
require_once __DIR__ . '/class-ewp-logger-queue.php';
require_once __DIR__ . '/class-ewp-logger-settings.php';
require_once __DIR__ . '/class-ewp-logger-cleanup.php';
require_once __DIR__ . '/class-ewp-logger-api.php';
require_once __DIR__ . '/class-ewp-logger-viewer.php';
require_once __DIR__ . '/class-ewp-logger-cli.php';
require_once __DIR__ . '/logger-functions.php';

/**
 * Core singleton for the EWP Logger system.
 *
 * Provides the public API for logging, action type registration, and log querying.
 * Delegates writes to EWP_Logger_Queue which flushes to the configured storage backend.
 * Core data is stored raw (no __() at write time). Labels are translated on output only.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 *
 * @example
 * // Register an action type
 * EWP_Logger::register_action_type('filox', 'booking_created', 'Booking Created');
 *
 * // Log an entry
 * EWP_Logger::log('filox', 'booking_created', 'Booking #42 created', ['id' => 42], 'editor', 'custom_content', true);
 */
class EWP_Logger
{
    /**
     * Singleton instance.
     *
     * @var EWP_Logger|null
     */
    private static $instance = null;

    /**
     * Storage backend instance.
     *
     * @var EWP_Logger_Storage|null
     */
    private $storage = null;

    /**
     * Registered action types.
     *
     * Structure: [ 'owner' => [ 'type_key' => [ 'label' => '', 'description' => '' ] ] ]
     *
     * @var array
     */
    private static $registered_types = [];

    /**
     * Whether the logger has been initialized.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * Whether logging is enabled.
     *
     * @var bool
     */
    private static $enabled = true;

    /**
     * Unique identifier for the current PHP request.
     *
     * Generated once per request lifecycle. Groups all log entries
     * from the same page load / AJAX call / CLI command.
     *
     * @var string
     */
    private static $request_id = '';

    /**
     * Human-readable context of the current request.
     *
     * Contains HTTP method + URI, or 'WP-CLI: <command>' for CLI.
     * Generated once alongside request_id.
     *
     * @var string
     */
    private static $request_context = '';

    /**
     * WordPress boilerplate keys to strip from logged data arrays.
     *
     * @var array
     */
    private static $wp_noise_keys = [
        'ewp_list_page_hook_nonce',
        '_wp_http_referer',
        '_wpnonce',
        'meta-box-order-nonce',
        'closedpostboxesnonce',
        'save',
        'awm_metabox',
        'awm_metabox_case',
        'awm_user_id',
    ];

    /**
     * Private constructor — use ::instance() instead.
     *
     * @since 1.0.0
     */
    private function __construct()
    {
        // Intentionally empty — initialization happens in init()
    }

    /**
     * Get the singleton instance.
     *
     * @return EWP_Logger
     *
     * @since 1.0.0
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize the logger system.
     *
     * Sets up the storage backend, queue, settings, cleanup, auto-hooks, REST API, and CLI.
     * Should be called once during plugin bootstrap (e.g. from Setup.php).
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // Load settings and determine if logging is enabled
        $settings     = EWP_Logger_Settings::get_settings();
        self::$enabled = !empty($settings['ewp_logger_enabled']);

        // Initialize storage backend
        $this->storage = $this->resolve_storage_backend($settings['ewp_logger_storage'] ?? 'file');

        // Initialize the queue with our storage
        EWP_Logger_Queue::init($this->storage);

        // Initialize settings admin page
        $settings_page = new EWP_Logger_Settings();
        $settings_page->init();

        // Initialize cleanup cron
        $cleanup = new EWP_Logger_Cleanup($this->storage);
        $cleanup->init();

        // Initialize REST API
        $api = new EWP_Logger_API($this->storage);
        $api->init();

        // Initialize log viewer admin page
        $viewer = new EWP_Logger_Viewer();
        $viewer->init();

        // Initialize WP-CLI commands
        EWP_Logger_CLI::init();

        // Register built-in action types
        $this->register_builtin_types();

        // Hook into existing EWP actions for auto-logging
        $this->register_auto_hooks();

        /**
         * Fires after the EWP Logger system is fully initialized.
         *
         * Use this hook to register custom action types or configure the logger.
         *
         * @param EWP_Logger $logger The logger singleton instance.
         *
         * @since 1.0.0
         */
        do_action('ewp_logger_initialized', $this);
    }

    /**
     * Log an entry.
     *
     * Queues a log entry for batch storage on shutdown. Core data is stored raw.
     *
     * @param string $owner       Plugin slug that owns the log.
     * @param string $action_type Registered action type key.
     * @param string $message     Human-readable summary.
     * @param array  $data        Detailed payload (will be serialized).
     * @param string $level       'editor' or 'developer'. Default 'editor'.
     * @param string  $object_type WP object context (post_type, taxonomy, user, option, custom_content, etc.).
     * @param int|bool $behaviour   0 = error, 1 = success (default), 2 = warning. Booleans are cast for backwards compat.
     *
     * @return bool True if queued, false if logging is disabled or entry was cancelled by filter.
     *
     * @since 1.0.0
     */
    public static function log($owner, $action_type, $message, $data = [], $level = 'editor', $object_type = '', $behaviour = 1)
    {
        // Ensure the logger is initialized
        self::instance();

        if (!self::$enabled) {
            return false;
        }

        $entry = [
            'owner'           => sanitize_text_field($owner),
            'action_type'     => sanitize_text_field($action_type),
            'object_type'     => sanitize_text_field($object_type),
            'behaviour'       => self::normalize_behaviour($behaviour),
            'level'           => in_array($level, ['editor', 'developer'], true) ? $level : 'editor',
            'user_id'         => get_current_user_id(),
            'object_id'       => 0,
            'message'         => $message,
            'data'            => maybe_serialize($data),
            'request_id'      => self::get_request_id(),
            'request_context' => self::get_request_context(),
            'created_at'      => current_time('mysql'),
        ];

        // Allow object_id to be passed in data for convenience
        if (isset($data['object_id'])) {
            $entry['object_id'] = absint($data['object_id']);
        }

        /**
         * Filter a log entry before it is queued.
         *
         * Return false to cancel the log entry entirely.
         *
         * @param array|false $entry The log entry array, or false to cancel.
         *
         * @since 1.0.0
         */
        $entry = apply_filters('ewp_logger_before_log', $entry);

        if ($entry === false) {
            return false;
        }

        EWP_Logger_Queue::add($entry);

        return true;
    }

    /**
     * Register a loggable action type.
     *
     * Labels are stored as raw strings. They will be wrapped in __() on output.
     *
     * @param string $owner       Plugin slug that owns this type.
     * @param string $type_key    Unique action type key.
     * @param string $label       Human-readable label (stored raw, translated on output).
     * @param string $description Optional description.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function register_action_type($owner, $type_key, $label, $description = '')
    {
        $owner    = sanitize_text_field($owner);
        $type_key = sanitize_text_field($type_key);

        self::$registered_types[$owner][$type_key] = [
            'label'       => $label,
            'description' => $description,
        ];
    }

    /**
     * Get all registered action types.
     *
     * @return array Structure: [ 'owner' => [ 'type_key' => [ 'label' => '', 'description' => '' ] ] ]
     *
     * @since 1.0.0
     */
    public static function get_registered_types()
    {
        /**
         * Filter the registered action types before returning.
         *
         * @param array $types Registered action types.
         *
         * @since 1.0.0
         */
        return apply_filters('ewp_logger_registered_types', self::$registered_types);
    }

    /**
     * Get all unique owners from registered types.
     *
     * @return array Array of owner slugs.
     *
     * @since 1.0.0
     */
    public static function get_registered_owners()
    {
        return array_keys(self::$registered_types);
    }

    /**
     * Query log entries from the storage backend.
     *
     * @param array $args Query arguments (see EWP_Logger_Storage::query).
     *
     * @return array Array of log entry arrays.
     *
     * @since 1.0.0
     */
    public function get_logs(array $args = [])
    {
        if (!$this->storage) {
            return [];
        }

        return $this->storage->query($args);
    }

    /**
     * Count log entries from the storage backend.
     *
     * @param array $args Filter arguments.
     *
     * @return int Total count.
     *
     * @since 1.0.0
     */
    public function count_logs(array $args = [])
    {
        if (!$this->storage) {
            return 0;
        }

        return $this->storage->count($args);
    }

    /**
     * Get the active storage backend instance.
     *
     * @return EWP_Logger_Storage|null
     *
     * @since 1.0.0
     */
    public function get_storage()
    {
        return $this->storage;
    }

    /**
     * Check if logging is enabled.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is_enabled()
    {
        return self::$enabled;
    }

    /**
     * Resolve the storage backend from the settings value.
     *
     * @param string $backend_key 'db' or 'file'.
     *
     * @return EWP_Logger_Storage
     *
     * @since 1.0.0
     */
    private function resolve_storage_backend($backend_key)
    {
        /**
         * Filter the storage backend instance.
         *
         * Allows plugins to provide a custom storage backend.
         *
         * @param EWP_Logger_Storage|null $storage  Null by default, set to override.
         * @param string                  $backend_key The configured backend key.
         *
         * @since 1.0.0
         */
        $custom = apply_filters('ewp_logger_storage_backend', null, $backend_key);

        if ($custom instanceof EWP_Logger_Storage) {
            return $custom;
        }

        switch ($backend_key) {
            case 'file':
                return new EWP_Logger_File();
            case 'db':
            default:
                return new EWP_Logger_DB();
        }
    }

    /**
     * Register built-in action types for extend-wp auto-logging.
     *
     * @return void
     *
     * @since 1.0.0
     */
    private function register_builtin_types()
    {
        $owner = 'extend-wp';

        // Editor-level types
        self::register_action_type($owner, 'content_save', 'Content Save', 'A custom content item was saved.');
        self::register_action_type($owner, 'content_delete', 'Content Delete', 'A custom content item was deleted.');
        self::register_action_type($owner, 'content_duplicate', 'Content Duplicate', 'A custom content item was duplicated.');
        self::register_action_type($owner, 'meta_update', 'Meta Update', 'Post, term, user, or option meta was updated.');
        self::register_action_type($owner, 'options_save', 'Options Save', 'An EWP options page was saved.');
        self::register_action_type($owner, 'content_import', 'Content Import', 'Content was imported.');
        self::register_action_type($owner, 'content_export', 'Content Export', 'Content was exported.');

        // Developer-level types
        self::register_action_type($owner, 'db_update', 'DB Update', 'A database table was created or updated.');
        self::register_action_type($owner, 'db_error', 'DB Error', 'A database operation failed.');
        self::register_action_type($owner, 'cache_flush', 'Cache Flush', 'The EWP cache was flushed.');
        self::register_action_type($owner, 'gallery_save', 'Gallery Save', 'Gallery images were saved for a post.');
    }

    /**
     * Register auto-logging hooks on existing EWP actions.
     *
     * Each hook can be individually disabled via filter:
     * ewp_logger_auto_log_{$action_type}_enabled
     *
     * @return void
     *
     * @since 1.0.0
     */
    private function register_auto_hooks()
    {
        // A. Custom Content Save
        if ($this->is_auto_log_enabled('content_save')) {
            add_action('ewp_custom_content_save_action', function ($id, $object_id, $data) {
                self::log(
                    $id,
                    'content_save',
                    sprintf('Saved %s #%d', $id, $object_id),
                    [
                        'content_id' => $id,
                        'object_id'  => $object_id,
                        'fields'     => self::filter_wp_noise($data),
                    ],
                    'editor',
                    'custom_content',
                    true
                );
            }, 999, 3);
        }

        // A. Custom Content Delete
        if ($this->is_auto_log_enabled('content_delete')) {
            add_action('ewp_custom_content_delete_action', function ($field, $ids) {
                self::log(
                    $field,
                    'content_delete',
                    sprintf('Deleted %d items from %s', is_array($ids) ? count($ids) : 1, $field),
                    [
                        'content_id'  => $field,
                        'deleted_ids' => $ids,
                    ],
                    'editor',
                    'custom_content',
                    true
                );
            }, 999, 2);
        }

        // B. WordPress Meta Update
        if ($this->is_auto_log_enabled('meta_update')) {
            add_action('awm_custom_meta_update_action', function ($data, $dataa, $id, $view, $postType) {
                $object_type_map = [
                    'post' => 'post_type',
                    'term' => 'taxonomy',
                    'user' => 'user',
                ];
                $object_type = isset($object_type_map[$view]) ? $object_type_map[$view] : $view;

                self::log(
                    'extend-wp',
                    'meta_update',
                    sprintf('Updated meta for %s #%s', $view, $id),
                    [
                        'meta_keys'  => $data,
                        'object_id'  => $id,
                        'view'       => $view,
                        'post_type'  => $postType,
                    ],
                    'editor',
                    $object_type,
                    true
                );
            }, 999, 5);
        }

        // D. Database Updated
        if ($this->is_auto_log_enabled('db_update')) {
            add_action('ewp_database_updated', function ($table, $tableData, $version) {
                self::log(
                    'extend-wp',
                    'db_update',
                    sprintf('Table %s updated to v%s', $table, $version),
                    [
                        'table'   => $table,
                        'version' => $version,
                        'columns' => array_keys($tableData['data'] ?? []),
                    ],
                    'developer',
                    'database',
                    true
                );
            }, 999, 3);
        }

        // F. Cache Flush
        if ($this->is_auto_log_enabled('cache_flush')) {
            add_action('ewp_flush_cache_action', function () {
                self::log(
                    'extend-wp',
                    'cache_flush',
                    sprintf('Cache flushed by user #%d', get_current_user_id()),
                    [
                        'user_id' => get_current_user_id(),
                    ],
                    'developer',
                    'system',
                    true
                );
            }, 999);
        }

        // H. Gallery Save
        if ($this->is_auto_log_enabled('gallery_save')) {
            add_action('gallery_meta_box_save', function ($post_id, $post, $update) {
                self::log(
                    'extend-wp',
                    'gallery_save',
                    sprintf('Gallery updated for post #%d', $post_id),
                    [
                        'post_id' => $post_id,
                        'update'  => $update,
                    ],
                    'developer',
                    'post_type',
                    true
                );
            }, 999, 3);
        }
    }

    /**
     * Check if auto-logging is enabled for a specific action type.
     *
     * @param string $action_type The action type key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    private function is_auto_log_enabled($action_type)
    {
        /**
         * Filter whether auto-logging is enabled for a specific action type.
         *
         * @param bool   $enabled     Whether auto-logging is enabled. Default true.
         * @param string $action_type The action type key.
         *
         * @since 1.0.0
         */
        return (bool) apply_filters("ewp_logger_auto_log_{$action_type}_enabled", true, $action_type);
    }

    /**
     * Behaviour value: error.
     *
     * @var int
     */
    const BEHAVIOUR_ERROR = 0;

    /**
     * Behaviour value: success.
     *
     * @var int
     */
    const BEHAVIOUR_SUCCESS = 1;

    /**
     * Behaviour value: warning.
     *
     * @var int
     */
    const BEHAVIOUR_WARNING = 2;

    /**
     * Normalize a behaviour value to a valid integer (0, 1, or 2).
     *
     * Accepts int or bool for backwards compatibility.
     * - true  → 1 (success)
     * - false → 0 (error)
     * - 0     → 0 (error)
     * - 1     → 1 (success)
     * - 2     → 2 (warning)
     * - other → 1 (success, default)
     *
     * @param int|bool $value Raw behaviour value.
     *
     * @return int Normalized value: 0, 1, or 2.
     *
     * @since 1.2.0
     */
    public static function normalize_behaviour($value)
    {
        // Boolean backwards compat
        if ($value === true) {
            return self::BEHAVIOUR_SUCCESS;
        }

        if ($value === false) {
            return self::BEHAVIOUR_ERROR;
        }

        $int_val = (int) $value;

        if (in_array($int_val, [self::BEHAVIOUR_ERROR, self::BEHAVIOUR_SUCCESS, self::BEHAVIOUR_WARNING], true)) {
            return $int_val;
        }

        return self::BEHAVIOUR_SUCCESS;
    }

    /**
     * Get the unique request ID for the current PHP request.
     *
     * Generated once and cached for the lifetime of the process.
     *
     * @return string 32-char unique request identifier.
     *
     * @since 1.1.0
     */
    public static function get_request_id()
    {
        if (empty(self::$request_id)) {
            self::$request_id = substr(md5(uniqid('ewp_', true) . wp_rand()), 0, 16);
        }

        return self::$request_id;
    }

    /**
     * Get a human-readable context string for the current request.
     *
     * Returns 'GET /wp-admin/admin.php?page=...' for HTTP or 'WP-CLI: ewp log list' for CLI.
     *
     * @return string Request context, max 255 chars.
     *
     * @since 1.1.0
     */
    public static function get_request_context()
    {
        if (!empty(self::$request_context)) {
            return self::$request_context;
        }

        // WP-CLI context
        if (defined('WP_CLI') && WP_CLI) {
            $args = isset($_SERVER['argv']) ? implode(' ', $_SERVER['argv']) : 'wp';
            self::$request_context = 'WP-CLI: ' . substr($args, 0, 245);
            return self::$request_context;
        }

        // HTTP context
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'UNKNOWN';
        $uri    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        self::$request_context = substr($method . ' ' . $uri, 0, 255);

        return self::$request_context;
    }

    /**
     * Filter out WordPress boilerplate keys from a data array.
     *
     * Removes nonces, referers, meta-box internals, and other noise
     * so the logged payload contains only meaningful data.
     *
     * @param array $data Raw data array (e.g. from $_POST).
     *
     * @return array Cleaned data.
     *
     * @since 1.1.0
     */
    public static function filter_wp_noise(array $data)
    {
        /**
         * Filter the list of keys considered WordPress noise.
         *
         * @param array $keys Keys to strip from logged data.
         *
         * @since 1.1.0
         */
        $noise_keys = apply_filters('ewp_logger_wp_noise_keys', self::$wp_noise_keys);

        return array_diff_key($data, array_flip($noise_keys));
    }
}