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
    /**
     * Shared query layer used by every logger surface.
     *
     * @var EWP_Logger_Query
     */
    private $query;

    public function __construct(EWP_Logger_Storage $storage, ?EWP_Logger_Query $query = null)
    {
        $this->storage = $storage;
        $this->query   = $query ?: new EWP_Logger_Query();
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
        // REST stays unbounded in time and may page as far as storage allows.
        $args = $this->query->args_from($request->get_params(), ['window' => null, 'max' => 10000]);

        /**
         * Filter the REST API query args before execution.
         *
         * @param array            $args    Query arguments.
         * @param \WP_REST_Request $request The REST request.
         *
         * @since 1.0.0
         */
        $args = apply_filters('ewp_logger_rest_query_args', $args, $request);

        $result   = $this->query->fetch($args, 'full');
        $per_page = max(1, absint($args['limit']));
        $page     = (int) floor($args['offset'] / $per_page) + 1;

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
        $data = apply_filters('ewp_logger_rest_response_data', $result['entries'], $result['total'], $args);

        return new \WP_REST_Response([
            'data'     => $data,
            'total'    => $result['total'],
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
        $args = $this->query->args_from($request->get_params(), ['window' => null]);
        unset($args['limit'], $args['offset'], $args['order']);

        /**
         * Filter the delete args before execution.
         *
         * @param array            $args    Filter arguments.
         * @param \WP_REST_Request $request The REST request.
         *
         * @since 1.2.0
         */
        $args = apply_filters('ewp_logger_rest_delete_args', $args, $request);

        $deleted = $this->query->delete_by_args($args);

        if (is_wp_error($deleted)) {
            return new \WP_REST_Response([
                'message' => $deleted->get_error_message(),
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
        $output = [];

        foreach ($this->query->vocabulary()['action_types'] as $type) {
            $output[] = [
                'owner'       => $type['owner'],
                'type_key'    => $type['key'],
                'label'       => $type['label'],
                'description' => $type['description'],
            ];
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
        return EWP_Logger_Formatter::prepare_entry($entry);
    }

    /**
     * The recognised filter parameter names.
     *
     * Kept for callers that reached the list through the API class; the
     * list and its `ewp_logger_filter_params` filter live in
     * EWP_Logger_Query::params() since 1.5.0.
     *
     * @return array List of recognised filter parameter names.
     *
     * @since 1.2.0
     */
    public static function get_filter_params()
    {
        return EWP_Logger_Query::params();
    }

    /**
     * Define endpoint argument schemas for pagination only.
     *
     * Filter fields arrive as raw form field names and are mapped by
     * EWP_Logger_Query::args_from() (see EWP_Logger_Query::params()).
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