<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers EWP Logger read abilities with the WordPress Abilities API.
 *
 * Exposes the log store as a small set of schema-described, read-only
 * abilities so AI tooling (the in-admin diagnose box, the MCP adapter,
 * or any future consumer) can query logs without new transport code.
 *
 * All abilities are read-only by design; log deletion is deliberately
 * not exposed.
 *
 * @package    EWP\Logger
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.3.0
 */
class EWP_Logger_Abilities
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-logger';

    /**
     * Default lookback window, in days, when no date range is supplied.
     *
     * Keeps unbounded queries from scanning every log file on the site.
     *
     * @var int
     */
    const DEFAULT_WINDOW_DAYS = 7;

    /**
     * Default number of entries returned by a search.
     *
     * @var int
     */
    const DEFAULT_LIMIT = 50;

    /**
     * Hard ceiling on entries returned in a single call.
     *
     * Well below the storage ceiling of 10000 so an AI caller cannot
     * pull the whole log store into context in one request.
     *
     * @var int
     */
    const MAX_LIMIT = 200;

    /**
     * Ceiling used for internal aggregate scans.
     *
     * @var int
     */
    const AGGREGATE_SCAN_LIMIT = 10000;

    /**
     * Register hooks.
     *
     * @return void
     *
     * @since 1.3.0
     */
    /**
     * Shared query layer used by every logger surface.
     *
     * @var EWP_Logger_Query
     */
    private $query;

    /**
     * @param EWP_Logger_Query|null $query Query layer; defaults to a fresh instance.
     *
     * @since 1.5.0
     */
    public function __construct(?EWP_Logger_Query $query = null)
    {
        $this->query = $query ?: new EWP_Logger_Query();
    }

    public function init()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    /**
     * Register the ability category.
     *
     * @return void
     *
     * @since 1.3.0
     */
    public function register_category()
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('EWP Logger', 'extend-wp'),
            'description' => __('Read-only access to the EWP activity log for diagnosing issues.', 'extend-wp'),
        ]);
    }

    /**
     * Register all logger abilities.
     *
     * @return void
     *
     * @since 1.3.0
     */
    public function register_abilities()
    {
        foreach ($this->get_definitions() as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * Return the ability definitions.
     *
     * Declared as one table so schemas and handlers stay together and
     * cannot drift apart.
     *
     * @return array Ability definitions keyed by ability name.
     *
     * @since 1.3.0
     */
    private function get_definitions()
    {
        $common_meta = [
            'annotations' => [
                'readonly'   => true,
                'idempotent' => true,
            ],
            'show_in_rest' => true,
        ];

        $definitions = [
            self::CATEGORY . '/list-vocabulary' => [
                'label'               => __('List log vocabulary', 'extend-wp'),
                'description'         => __('List every owner, action type and object type registered with the EWP Logger on this site, plus the valid behaviour and level values. Call this first when diagnosing an issue: it tells you which filter values actually exist here, so later searches use real terms instead of guesses. Cheap to call.', 'extend-wp'),
                'category'            => self::CATEGORY,
                'input_schema'        => ['type' => 'object', 'additionalProperties' => false, 'default' => []],
                'output_schema'       => $this->vocabulary_output_schema(),
                'execute_callback'    => [$this, 'run_list_vocabulary'],
                'permission_callback' => [$this, 'check_permission'],
                'meta'                => $common_meta,
            ],

            self::CATEGORY . '/get-stats' => [
                'label'               => __('Get log statistics', 'extend-wp'),
                'description'         => __('Summarise log volume over a date window: totals broken down by behaviour (error/success/warning), by level, and by owner. Use this to find out what is failing and which plugin owns it before running a detailed search. Cheaper than searching because it returns counts rather than entries. Defaults to the last 7 days.', 'extend-wp'),
                'category'            => self::CATEGORY,
                'input_schema'        => $this->stats_input_schema(),
                'output_schema'       => $this->stats_output_schema(),
                'execute_callback'    => [$this, 'run_get_stats'],
                'permission_callback' => [$this, 'check_permission'],
                'meta'                => $common_meta,
            ],

            self::CATEGORY . '/search' => [
                'label'               => __('Search log entries', 'extend-wp'),
                'description'         => __('Search log entries with filters and pagination. Narrowing by date_from/date_to, owner or behaviour is cheap; a broad search_text across a wide date range is expensive because it scans every entry in the window. Data payloads come back truncated as data_preview - use the get-entry ability for the full payload of a specific entry. Defaults to the last 7 days and 50 entries.', 'extend-wp'),
                'category'            => self::CATEGORY,
                'input_schema'        => $this->search_input_schema(),
                'output_schema'       => $this->search_output_schema(),
                'execute_callback'    => [$this, 'run_search'],
                'permission_callback' => [$this, 'check_permission'],
                'meta'                => $common_meta,
            ],

            self::CATEGORY . '/get-request-trace' => [
                'label'               => __('Get request trace', 'extend-wp'),
                'description'         => __('Return every log entry recorded during a single HTTP request or CLI command, oldest first, given its request_id. This is the most useful diagnostic step after finding an error: it shows everything that happened around the failure in the same request. Get a request_id from the search ability first.', 'extend-wp'),
                'category'            => self::CATEGORY,
                'input_schema'        => $this->trace_input_schema(),
                'output_schema'       => $this->trace_output_schema(),
                'execute_callback'    => [$this, 'run_get_request_trace'],
                'permission_callback' => [$this, 'check_permission'],
                'meta'                => $common_meta,
            ],

            self::CATEGORY . '/get-entry' => [
                'label'               => __('Get a single log entry', 'extend-wp'),
                'description'         => __('Return one log entry by its log_id, including the complete untruncated data payload. This is the only ability that returns full payloads, so use it on the one or two entries that matter rather than in a loop. Pass the date (Y-m-d) of the entry when known to make the lookup much faster.', 'extend-wp'),
                'category'            => self::CATEGORY,
                'input_schema'        => $this->entry_input_schema(),
                'output_schema'       => $this->entry_output_schema(),
                'execute_callback'    => [$this, 'run_get_entry'],
                'permission_callback' => [$this, 'check_permission'],
                'meta'                => $common_meta,
            ],
        ];

        /**
         * Filter the EWP Logger ability definitions before registration.
         *
         * @param array $definitions Ability definitions keyed by ability name.
         *
         * @since 1.3.0
         */
        return apply_filters('ewp_logger_ability_definitions', $definitions);
    }

    /**
     * Permission callback shared by every logger ability.
     *
     * Reuses the logger's single source of truth for viewer access.
     *
     * @return bool|\WP_Error True when permitted, WP_Error otherwise.
     *
     * @since 1.3.0
     */
    public function check_permission()
    {
        if (!current_user_can(EWP_Logger::get_viewer_capability())) {
            return new \WP_Error(
                'ewp_logger_forbidden',
                __('You do not have permission to read the EWP logs.', 'extend-wp'),
                ['status' => 403]
            );
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    /**
     * Return every registered owner, action type and object type.
     *
     * @param array|null $input Unused.
     *
     * @return array Vocabulary payload.
     *
     * @since 1.3.0
     */
    public function run_list_vocabulary($input = null)
    {
        return $this->query->vocabulary();
    }

    /**
     * Summarise log volume over a window (single scan, 7-day default).
     *
     * @param array|null $input Ability input.
     *
     * @return array Statistics payload.
     *
     * @since 1.3.0
     */
    public function run_get_stats($input = null)
    {
        return $this->query->stats(is_array($input) ? $input : [], self::DEFAULT_WINDOW_DAYS);
    }

    /**
     * Search log entries with compact payload previews.
     *
     * @param array|null $input Ability input.
     *
     * @return array Search results payload.
     *
     * @since 1.3.0
     */
    public function run_search($input = null)
    {
        return $this->query->search(is_array($input) ? $input : [], [
            'shape'  => 'compact',
            'window' => self::DEFAULT_WINDOW_DAYS,
            'limit'  => self::DEFAULT_LIMIT,
            'max'    => self::MAX_LIMIT,
        ]);
    }

    /**
     * Return every entry sharing a request id.
     *
     * @param array|null $input Ability input.
     *
     * @return array|\WP_Error Trace payload, or WP_Error when request_id is missing.
     *
     * @since 1.3.0
     */
    public function run_get_request_trace($input = null)
    {
        $input      = is_array($input) ? $input : [];
        $request_id = isset($input['request_id']) ? sanitize_text_field($input['request_id']) : '';

        if ($request_id === '') {
            return new \WP_Error(
                'ewp_logger_missing_request_id',
                __('A request_id is required. Run the search ability first and take request_id from one of its entries.', 'extend-wp')
            );
        }

        return $this->query->trace($request_id, $input);
    }

    /**
     * Return a single entry with its full payload.
     *
     * @param array|null $input Ability input.
     *
     * @return array|\WP_Error Entry payload, or WP_Error when log_id is missing.
     *
     * @since 1.3.0
     */
    public function run_get_entry($input = null)
    {
        $input  = is_array($input) ? $input : [];
        $log_id = isset($input['log_id']) ? sanitize_text_field($input['log_id']) : '';

        if ($log_id === '') {
            return new \WP_Error(
                'ewp_logger_missing_log_id',
                __('A log_id is required. Run the search ability first and take log_id from one of its entries.', 'extend-wp')
            );
        }

        return $this->query->entry($log_id, $input);
    }

    /* ---------------------------------------------------------------------
     * Schemas
     * ------------------------------------------------------------------ */

    /**
     * Shared date range schema properties.
     *
     * @return array Schema properties.
     *
     * @since 1.3.0
     */
    private function window_properties()
    {
        return [
            'date_from' => [
                'type'        => 'string',
                'description' => __('Start of the date range, as Y-m-d. Defaults to 7 days ago.', 'extend-wp'),
            ],
            'date_to' => [
                'type'        => 'string',
                'description' => __('End of the date range, as Y-m-d. Defaults to today.', 'extend-wp'),
            ],
        ];
    }

    /**
     * Schema describing a compacted log entry.
     *
     * Left open to additional properties because the
     * ewp_logger_prepare_entry_for_output filter lets other plugins
     * decorate entries.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function entry_item_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'log_id'            => ['type' => 'string'],
                'created_at'        => ['type' => 'string'],
                'owner'             => ['type' => 'string'],
                'owner_label'       => ['type' => 'string'],
                'action_type'       => ['type' => 'string'],
                'action_type_label' => ['type' => 'string'],
                'object_type'       => ['type' => 'string'],
                'object_type_label' => ['type' => 'string'],
                'object_id'         => ['type' => ['integer', 'string', 'null']],
                'behaviour'         => ['type' => 'integer'],
                'behaviour_label'   => ['type' => 'string'],
                'level'             => ['type' => 'string'],
                'user_id'           => ['type' => ['integer', 'string', 'null']],
                'user_display_name' => ['type' => 'string'],
                'message'           => ['type' => 'string'],
                'request_id'        => ['type' => 'string'],
                'request_context'   => ['type' => 'string'],
                'data_preview'      => ['type' => 'string'],
                'data_truncated'    => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * Output schema for the vocabulary ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function vocabulary_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'owners' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'slug'  => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
                'action_types' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'key'         => ['type' => 'string'],
                            'owner'       => ['type' => 'string'],
                            'label'       => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                    ],
                ],
                'object_types' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'key'   => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
                'behaviours' => ['type' => 'array', 'items' => ['type' => 'string']],
                'levels'     => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * Input schema for the stats ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function stats_input_schema()
    {
        return [
            'type'       => 'object',
            'default'    => [],
            'properties' => array_merge($this->window_properties(), [
                'owner' => [
                    'type'        => 'string',
                    'description' => __('Restrict the summary to a single owner slug.', 'extend-wp'),
                ],
            ]),
        ];
    }

    /**
     * Output schema for the stats ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function stats_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date_from'    => ['type' => 'string'],
                'date_to'      => ['type' => 'string'],
                'total'        => ['type' => 'integer'],
                'by_behaviour' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'by_level'     => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'by_owner'     => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'capped'       => ['type' => 'boolean'],
                'hint'         => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Input schema for the search ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function search_input_schema()
    {
        return [
            'type'       => 'object',
            'default'    => [],
            'properties' => array_merge($this->window_properties(), [
                'owner' => [
                    'type'        => ['string', 'array'],
                    'items'       => ['type' => 'string'],
                    'description' => __('Owner slug, or a list of them. Use the list-vocabulary ability for valid values.', 'extend-wp'),
                ],
                'action_type' => [
                    'type'        => ['string', 'array'],
                    'items'       => ['type' => 'string'],
                    'description' => __('Action type key, or a list of them.', 'extend-wp'),
                ],
                'object_type' => [
                    'type'        => ['string', 'array'],
                    'items'       => ['type' => 'string'],
                    'description' => __('Object type, e.g. post_type, taxonomy, user, option, custom_content, database, system.', 'extend-wp'),
                ],
                'behaviour' => [
                    'type'        => ['string', 'array'],
                    'items'       => ['type' => 'string', 'enum' => ['error', 'success', 'warning']],
                    'description' => __('Filter by outcome: error, success or warning. Use "error" to find failures.', 'extend-wp'),
                ],
                'level' => [
                    'type'        => 'string',
                    'enum'        => ['editor', 'developer'],
                    'description' => __('Filter by log level.', 'extend-wp'),
                ],
                'user_id' => [
                    'type'        => 'integer',
                    'description' => __('Only entries recorded for this WordPress user ID.', 'extend-wp'),
                ],
                'object_id' => [
                    'type'        => ['integer', 'array'],
                    'items'       => ['type' => 'integer'],
                    'description' => __('Only entries about this object ID, or any of these IDs.', 'extend-wp'),
                ],
                'request_id' => [
                    'type'        => 'string',
                    'description' => __('Only entries from this request. Prefer the get-request-trace ability for full traces.', 'extend-wp'),
                ],
                'search_text' => [
                    'type'        => 'string',
                    'description' => __('Free-text search across message, owner, action, data payload and user. Expensive over wide date ranges - narrow the dates first.', 'extend-wp'),
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'description' => __('Entries to return, 1-200. Defaults to 50.', 'extend-wp'),
                ],
                'offset' => [
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => __('Entries to skip, for paging.', 'extend-wp'),
                ],
                'order' => [
                    'type'        => 'string',
                    'enum'        => ['ASC', 'DESC'],
                    'description' => __('Sort by date. Defaults to DESC (newest first).', 'extend-wp'),
                ],
            ]),
        ];
    }

    /**
     * Output schema for the search ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function search_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'total'     => ['type' => 'integer'],
                'returned'  => ['type' => 'integer'],
                'limit'     => ['type' => 'integer'],
                'offset'    => ['type' => 'integer'],
                'date_from' => ['type' => 'string'],
                'date_to'   => ['type' => 'string'],
                'hint'      => ['type' => 'string'],
                'entries'   => [
                    'type'  => 'array',
                    'items' => $this->entry_item_schema(),
                ],
            ],
        ];
    }

    /**
     * Input schema for the request trace ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function trace_input_schema()
    {
        return [
            'type'       => 'object',
            'default'    => [],
            'properties' => array_merge($this->window_properties(), [
                'request_id' => [
                    'type'        => 'string',
                    'description' => __('The request identifier to trace, taken from a search result entry.', 'extend-wp'),
                ],
            ]),
            'required'   => ['request_id'],
        ];
    }

    /**
     * Output schema for the request trace ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function trace_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'request_id'      => ['type' => 'string'],
                'count'           => ['type' => 'integer'],
                'request_context' => ['type' => 'string'],
                'hint'            => ['type' => 'string'],
                'entries'         => [
                    'type'  => 'array',
                    'items' => $this->entry_item_schema(),
                ],
            ],
        ];
    }

    /**
     * Input schema for the single entry ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function entry_input_schema()
    {
        return [
            'type'       => 'object',
            'default'    => [],
            'properties' => array_merge($this->window_properties(), [
                'log_id' => [
                    'type'        => 'string',
                    'description' => __('The log_id of the entry to fetch, taken from a search result.', 'extend-wp'),
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => __('The Y-m-d date of the entry, when known. Pins the lookup to one day file and is much faster.', 'extend-wp'),
                ],
            ]),
            'required'   => ['log_id'],
        ];
    }

    /**
     * Output schema for the single entry ability.
     *
     * @return array Schema.
     *
     * @since 1.3.0
     */
    private function entry_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'found' => ['type' => 'boolean'],
                'hint'  => ['type' => 'string'],
                'entry' => ['type' => ['object', 'null']],
            ],
        ];
    }
}
