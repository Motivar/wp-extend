<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoint for the EWP Logger.
 *
 * Provides a GET endpoint at /extend-wp/v1/logs for querying log entries.
 * Restricted to administrators only (manage_options capability).
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Logger_API
{
    /**
     * REST namespace.
     *
     * @var string
     */
    private static $namespace = 'extend-wp/v1';

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
     * Initialize REST API routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the /logs REST route.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        register_rest_route(self::$namespace, '/logs', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_logs'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => $this->get_endpoint_args(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_logs'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => $this->get_endpoint_args(),
            ],
        ]);

        register_rest_route(self::$namespace, '/logs/types', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_types'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route(self::$namespace, '/logs/owners', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_owners'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    /**
     * Permission callback: administrator only.
     *
     * @return bool|\WP_Error
     *
     * @since 1.0.0
     */
    public function check_permission()
    {
        if (!current_user_can(EWP_Logger::get_viewer_capability())) {
            return new \WP_Error(
                'ewp_logger_forbidden',
                __('You do not have permission to view logs.', 'extend-wp'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * GET /logs callback.
     *
     * Returns paginated, filtered log entries.
     *
     * @param \WP_REST_Request $request The REST request.
     *
     * @return \WP_REST_Response
     *
     * @since 1.0.0
     */
    public function get_logs(\WP_REST_Request $request)
    {
        $args = $this->extract_filter_args($request);

        // Pagination
        $per_page = $request->get_param('per_page') ?? 50;
        $page     = $request->get_param('page') ?? 1;

        $args['limit']  = $per_page;
        $args['offset'] = ($page - 1) * $per_page;
        $args['order']  = $request->get_param('order') ?? 'DESC';

        /**
         * Filter the REST API query args before execution.
         *
         * @param array            $args    Query arguments.
         * @param \WP_REST_Request $request The REST request.
         *
         * @since 1.0.0
         */
        $args = apply_filters('ewp_logger_rest_query_args', $args, $request);

        $data  = $this->storage->query($args);
        $total = $this->storage->count($args);

        $per_page = max(1, absint($args['limit']));
        $page     = (int) floor($args['offset'] / $per_page) + 1;

        // Enrich each entry with human-readable labels
        $data = array_map(function ($entry) {
            return $this->prepare_entry_for_output($entry);
        }, $data);

        /**
         * Filter the full prepared log data before building the REST response.
         *
         * Allows developers to modify, extend, or decorate the entire result set.
         *
         * @param array $data  Prepared log entries.
         * @param int   $total Total matching entries (unfiltered count).
         * @param array $args  Query arguments used.
         *
         * @since 1.2.0
         */
        $data = apply_filters('ewp_logger_rest_response_data', $data, $total, $args);

        return new \WP_REST_Response([
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ], 200);
    }

    /**
     * DELETE /logs callback.
     *
     * Deletes log entries matching the current filters.
     *
     * @param \WP_REST_Request $request The REST request.
     *
     * @return \WP_REST_Response
     *
     * @since 1.2.0
     */
    public function delete_logs(\WP_REST_Request $request)
    {
        $args = $this->extract_filter_args($request);

        /**
         * Filter the delete args before execution.
         *
         * @param array            $args    Filter arguments.
         * @param \WP_REST_Request $request The REST request.
         *
         * @since 1.2.0
         */
        $args = apply_filters('ewp_logger_rest_delete_args', $args, $request);

        $deleted = $this->storage->delete_by_filters($args);

        if ($deleted === -1) {
            return new \WP_REST_Response([
                'message' => __('Failed to delete log entries.', 'extend-wp'),
            ], 500);
        }

        return new \WP_REST_Response([
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted entries */
                __('%d log entries deleted.', 'extend-wp'),
                $deleted
            ),
        ], 200);
    }

    /**
     * GET /logs/types callback.
     *
     * Returns all registered action types with translated labels.
     *
     * @return \WP_REST_Response
     *
     * @since 1.0.0
     */
    public function get_types()
    {
        $types  = EWP_Logger::get_registered_types();
        $output = [];

        foreach ($types as $owner => $owner_types) {
            foreach ($owner_types as $type_key => $type_data) {
                $output[] = [
                    'owner'       => $owner,
                    'type_key'    => $type_key,
                    'label'       => __($type_data['label'], 'extend-wp'),
                    'description' => __($type_data['description'], 'extend-wp'),
                ];
            }
        }

        return new \WP_REST_Response(['data' => $output], 200);
    }

    /**
     * GET /logs/owners callback.
     *
     * Returns all registered owner slugs.
     *
     * @return \WP_REST_Response
     *
     * @since 1.0.0
     */
    public function get_owners()
    {
        return new \WP_REST_Response([
            'data' => EWP_Logger::get_registered_owners(),
        ], 200);
    }

    /**
     * Prepare a log entry for REST output.
     *
     * Unserializes data, resolves human-readable labels for owner,
     * action_type, object_type, and user via EWP_Logger resolvers.
     *
     * @param array $entry Raw log entry.
     *
     * @return array Prepared entry with *_label fields added.
     *
     * @since 1.0.0
     */
    private function prepare_entry_for_output(array $entry)
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

        // Cast behaviour to int and add label for JSON output
        $behaviour_int = (int) ($entry['behaviour'] ?? 1);
        $entry['behaviour'] = $behaviour_int;
        $behaviour_labels = [
            EWP_Logger::BEHAVIOUR_ERROR   => 'error',
            EWP_Logger::BEHAVIOUR_SUCCESS => 'success',
            EWP_Logger::BEHAVIOUR_WARNING => 'warning',
        ];
        $entry['behaviour_label'] = isset($behaviour_labels[$behaviour_int]) ? $behaviour_labels[$behaviour_int] : 'success';

        /**
         * Filter a single prepared log entry before REST output.
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
     * Return the recognized filter parameter names.
     *
     * Form field names match query arg names directly (e.g. 'owner', 'date_from').
     * Developers can add custom filter fields and register them here.
     *
     * @return array List of recognized filter parameter names.
     *
     * @since 1.2.0
     */
    private function get_filter_params()
    {
        $params = [
            'owner',
            'action_type',
            'object_type',
            'behaviour',
            'level',
            'user_id',
            'date_from',
            'date_to',
            'request_id',
        ];

        /**
         * Filter the list of recognized filter parameter names.
         *
         * Allows developers who add custom viewer filter fields
         * to register them so the REST API processes them.
         *
         * @param array $params List of parameter names.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_filter_params', $params);
    }

    /**
     * Extract filter arguments from a REST request.
     *
     * Reads recognized filter params from the request and handles
     * date format conversion (d-m-Y → Y-m-d).
     *
     * @param \WP_REST_Request $request The REST request.
     *
     * @return array Query arguments keyed by storage arg names.
     *
     * @since 1.2.0
     */
    private function extract_filter_args(\WP_REST_Request $request)
    {
        $params     = $this->get_filter_params();
        $all_params = $request->get_params();
        $args       = [];

        foreach ($params as $param) {
            $value = isset($all_params[$param]) ? $all_params[$param] : null;

            if ($value === null || $value === '') {
                continue;
            }

            $value = $this->parse_multi_param($value);

            // Convert date formats (d-m-Y → Y-m-d) for date fields
            if (in_array($param, ['date_from', 'date_to'], true)) {
                $value = $this->convert_date_format($value);
            }

            $args[$param] = $value;
        }

        return $args;
    }

    /**
     * Parse a value that may contain comma-separated multi-values.
     *
     * Returns a single string for single values, an array for multiple,
     * or empty string for null/empty.
     *
     * @param mixed $value Raw parameter value.
     *
     * @return string|array Parsed value.
     *
     * @since 1.2.0
     */
    private function parse_multi_param($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        if (strpos($value, ',') === false) {
            return $value;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), function ($v) {
            return $v !== '';
        }));
    }

    /**
     * Convert a date string from d-m-Y to Y-m-d format.
     *
     * Supports both d-m-Y and Y-m-d input. Returns the original
     * value if parsing fails.
     *
     * @param string $date Date string.
     *
     * @return string Date in Y-m-d format.
     *
     * @since 1.2.0
     */
    private function convert_date_format($date)
    {
        if (empty($date)) {
            return '';
        }

        // Already in Y-m-d format
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return $date;
        }

        // Convert d-m-Y → Y-m-d
        $parsed = \DateTime::createFromFormat('d-m-Y', $date);
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }

        return sanitize_text_field($date);
    }

    /**
     * Define endpoint argument schemas for pagination only.
     *
     * Filter fields arrive as raw form field names and are mapped
     * by extract_filter_args() via the filterable field-param map.
     *
     * @return array Argument definitions.
     *
     * @since 1.0.0
     */
    private function get_endpoint_args()
    {
        return [
            'page' => [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1,
            ],
            'per_page' => [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 50,
            ],
            'order' => [
                'type'              => 'string',
                'enum'              => ['ASC', 'DESC'],
                'default'           => 'DESC',
            ],
        ];
    }
}