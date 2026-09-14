<?php

namespace EWP\Surfaces\Resources;

use EWP\Logger\EWP_Logger;
use EWP\Logger\EWP_Logger_Query;
use EWP\Logger\EWP_Logger_Settings;
use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The activity log on REST (`extend-wp/v1/logs*`), WP-CLI (`wp ewp log *`)
 * and the Abilities API (`ewp-logger/*`), all over EWP_Logger_Query.
 *
 * Surface gating follows the logger's own rules: reads and cleanup exist
 * only while logging is enabled, and their ability only while AI access is
 * enabled; the write ability is always registered so an agent gets a clear
 * 503 rather than a missing ability when logging is off.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Logger_Resource extends Resource
{
    /** @var EWP_Logger_Query */
    private $query;

    /**
     * @param EWP_Logger_Query $query Shared query layer.
     */
    public function __construct(EWP_Logger_Query $query)
    {
        $this->query = $query;
    }

    public function name()
    {
        return 'logger';
    }

    public function label()
    {
        return __('EWP Logger', 'extend-wp');
    }

    public function service()
    {
        return $this->query;
    }

    public function rest_namespace()
    {
        return 'extend-wp/v1';
    }

    public function rest_base()
    {
        return '/logs';
    }

    public function cli_base()
    {
        return 'ewp log';
    }

    public function ability_category()
    {
        return 'ewp-logger';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Logger', 'extend-wp'),
            'description' => __('Read and write the EWP activity log.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        $query = $this->query;

        return function () use ($query) {
            return $query->viewer_capability();
        };
    }

    public function operations()
    {
        $reads  = [$this, 'read_surfaces'];
        $writes = [$this, 'write_surfaces'];

        return [
            'search'     => Operation::read('search_by_args')
                ->label(__('Search log entries', 'extend-wp'))
                ->description(__('Search log entries with filters and pagination. Narrowing by date_from/date_to, owner or behaviour is cheap; a broad search_text across a wide date range is expensive because it scans every entry in the window. Data payloads come back truncated as data_preview - use the get-entry ability for the full payload of a specific entry. Defaults to the last 7 days and 50 entries.', 'extend-wp'))
                ->input(array_merge($this->filter_fields(), $this->pagination_fields()))
                ->allow_extra()
                ->args([$this, 'search_args'])
                ->transform([$this, 'search_transform'])
                ->output($this->search_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('GET', '')
                ->cli('list', ['presenter' => [$this, 'present_entries']])
                ->ability('search'),

            'stats'      => Operation::read('stats')
                ->label(__('Get log statistics', 'extend-wp'))
                ->description(__('Summarise log volume over a date window: totals broken down by behaviour (error/success/warning), by level, and by owner. Use this to find out what is failing and which plugin owns it before running a detailed search. Cheaper than searching because it returns counts rather than entries. Defaults to the last 7 days.', 'extend-wp'))
                ->input([
                    $this->date_field('date_from'),
                    $this->date_field('date_to'),
                    Field::string('owner')->describe(__('Restrict the summary to a single owner slug.', 'extend-wp')),
                ])
                ->args(function (array $input) {
                    return [$input, EWP_Logger_Query::DEFAULT_WINDOW_DAYS];
                })
                ->output($this->stats_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('GET', 'stats')
                ->cli('stats', ['presenter' => [$this, 'present_stats']])
                ->ability('get-stats'),

            'vocabulary' => Operation::read('vocabulary')
                ->label(__('List log vocabulary', 'extend-wp'))
                ->description(__('List every owner, action type and object type registered with the EWP Logger on this site, plus the valid behaviour and level values. Call this first when diagnosing an issue: it tells you which filter values actually exist here, so later searches use real terms instead of guesses. Cheap to call.', 'extend-wp'))
                ->transform([$this, 'vocabulary_transform'])
                ->output($this->vocabulary_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('GET', 'types')
                ->rest_alias('GET', 'owners')
                ->cli('types', ['presenter' => [$this, 'present_types']])
                ->ability('list-vocabulary'),

            'entry'      => Operation::read('entry')
                ->label(__('Get a single log entry', 'extend-wp'))
                ->description(__('Return one log entry by its log_id, including the complete untruncated data payload. This is the only ability that returns full payloads, so use it on the one or two entries that matter rather than in a loop. Pass the date (Y-m-d) of the entry when known to make the lookup much faster.', 'extend-wp'))
                ->input([
                    Field::string('log_id')->required()->positional()->describe(__('The log_id of the entry to fetch, taken from a search result.', 'extend-wp')),
                    Field::string('date')->describe(__('The Y-m-d date of the entry, when known. Pins the lookup to one day file and is much faster.', 'extend-wp')),
                    $this->date_field('date_from'),
                    $this->date_field('date_to'),
                ])
                ->args(function (array $input) {
                    return [$input['log_id'], $input];
                })
                ->output($this->entry_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('GET', 'entry/(?P<log_id>[^/]+)')
                ->cli('get', ['default_format' => 'json'])
                ->ability('get-entry'),

            'trace'      => Operation::read('trace')
                ->label(__('Get request trace', 'extend-wp'))
                ->description(__('Return every log entry recorded during a single HTTP request or CLI command, oldest first, given its request_id. This is the most useful diagnostic step after finding an error: it shows everything that happened around the failure in the same request. Get a request_id from the search ability first.', 'extend-wp'))
                ->input([
                    Field::string('request_id')->required()->positional()->describe(__('The request identifier to trace, taken from a search result entry.', 'extend-wp')),
                    $this->date_field('date_from'),
                    $this->date_field('date_to'),
                ])
                ->args(function (array $input) {
                    return [$input['request_id'], $input];
                })
                ->output($this->trace_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('GET', 'trace/(?P<request_id>[^/]+)')
                ->cli('trace', ['default_format' => 'json'])
                ->ability('get-request-trace'),

            'cleanup'    => Operation::destructive('cleanup')
                ->label(__('Run log retention cleanup', 'extend-wp'))
                ->description(__('Delete entries older than the configured retention period now, instead of waiting for the daily job. Pass months to override the retention period for this run only. Requires confirm: true.', 'extend-wp'))
                ->input([Field::int('months')->min(0)->describe(__('Retention override in months; 0 keeps the configured value.', 'extend-wp'))])
                ->args(function (array $input) {
                    return [isset($input['months']) ? (int) $input['months'] : 0];
                })
                ->output($this->cleanup_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('POST', 'cleanup')
                ->cli('cleanup', ['success' => 'Cleanup completed: %deleted% entries deleted (cutoff: %cutoff_date%, retention: %retention_months% months).'])
                ->ability('cleanup'),

            'delete'     => Operation::destructive('delete_by_args')
                ->confirm([Context::ABILITY, Context::CLI])
                ->label(__('Delete log entries', 'extend-wp'))
                ->description(__('Permanently delete every entry matching the filters. With no filters this empties the log. Requires confirm: true (or --yes on the command line).', 'extend-wp'))
                ->input($this->filter_fields())
                ->allow_extra()
                ->args([$this, 'delete_args'])
                ->transform([$this, 'delete_transform'])
                ->output($this->delete_output_schema())
                ->surfaces($reads, __('logging is switched off, or AI access to the logs is disabled', 'extend-wp'))
                ->rest('DELETE', '')
                ->cli('delete', ['success' => '%deleted% log entries deleted.'])
                ->ability('delete-entries'),

            'write'      => Operation::write('write')
                ->label(__('Write a log entry', 'extend-wp'))
                ->description(__('Write one entry to the EWP activity log. Use it to record what an automated task did, so the change is auditable next to everything else on the site. Call the logger list-vocabulary ability first to reuse an owner and action type that already exist here rather than inventing new ones. Set behaviour to error when recording a failure.', 'extend-wp'))
                ->input($this->write_fields())
                ->args([$this, 'write_args'])
                ->output($this->write_output_schema())
                ->surfaces($writes, __('logging is switched off', 'extend-wp'))
                ->rest('POST', '', 201)
                ->cli('write', ['success' => 'Logged an entry for %owner% (request %request_id%).'])
                ->ability('write-entry'),
        ];
    }

    /* ---------------------------------------------------------------------
     * Surface gating
     * ------------------------------------------------------------------ */

    /**
     * @return string[]
     */
    public function read_surfaces()
    {
        if (!EWP_Logger::is_enabled()) {
            return [];
        }

        $surfaces = [Context::REST, Context::CLI];
        if (EWP_Logger_Settings::is_ai_enabled()) {
            $surfaces[] = Context::ABILITY;
        }

        return $surfaces;
    }

    /**
     * @return string[]
     */
    public function write_surfaces()
    {
        return EWP_Logger::is_enabled() ? Operation::SURFACES : [Context::ABILITY];
    }

    /* ---------------------------------------------------------------------
     * Argument mappers and transforms (per-surface defaults live here)
     * ------------------------------------------------------------------ */

    /**
     * REST stays unbounded in time and pages up to the storage ceiling; the
     * CLI is unbounded with the service ceiling; abilities default to a
     * 7-day window, 50 entries, 200 at most, and compact payloads.
     *
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array search_by_args($args, $shape)
     */
    public function search_args(array $input, Context $ctx)
    {
        if ($ctx->surface() === Context::REST) {
            $args = $this->query->args_from($input, ['window' => null, 'max' => 10000]);

            /**
             * Filter the REST API query args before execution.
             *
             * @param array            $args    Query arguments.
             * @param \WP_REST_Request $request The REST request.
             *
             * @since 1.0.0
             */
            return [apply_filters('ewp_logger_rest_query_args', $args, $ctx->raw()), 'full'];
        }

        if ($ctx->surface() === Context::CLI) {
            return [$this->query->args_from($input, ['window' => null]), 'full'];
        }

        return [$this->query->args_from($input, ['window' => EWP_Logger_Query::DEFAULT_WINDOW_DAYS, 'limit' => 50, 'max' => 200]), 'compact'];
    }

    /**
     * REST keeps its historical `{data, total, page, per_page}` body and the
     * `ewp_logger_rest_response_data` filter; other surfaces get the search payload.
     *
     * @param array   $result Search payload (with `args`).
     * @param array   $input  Normalised input.
     * @param Context $ctx    Invocation context.
     *
     * @return array
     */
    public function search_transform($result, array $input, Context $ctx)
    {
        if (!is_array($result)) {
            return $result;
        }

        $args = isset($result['args']) ? $result['args'] : [];
        unset($result['args']);

        if ($ctx->surface() !== Context::REST) {
            return $result;
        }

        $per_page = max(1, (int) $result['limit']);

        /**
         * Filter the full prepared log data before building the REST response.
         *
         * @param array $data  Prepared log entries.
         * @param int   $total Total matching entries (unfiltered count).
         * @param array $args  Query arguments used.
         *
         * @since 1.2.0
         */
        $data = apply_filters('ewp_logger_rest_response_data', $result['entries'], $result['total'], $args);

        return [
            'data'     => $data,
            'total'    => $result['total'],
            'page'     => (int) floor($result['offset'] / $per_page) + 1,
            'per_page' => $per_page,
        ];
    }

    /**
     * `/logs/types` and `/logs/owners` keep their historical bodies.
     *
     * @param array   $vocabulary Vocabulary payload.
     * @param array   $input      Normalised input.
     * @param Context $ctx        Invocation context.
     *
     * @return array
     */
    public function vocabulary_transform($vocabulary, array $input, Context $ctx)
    {
        if ($ctx->surface() !== Context::REST || !is_array($vocabulary)) {
            return $vocabulary;
        }

        $route = $ctx->raw() instanceof \WP_REST_Request ? $ctx->raw()->get_route() : '';
        if (substr($route, -7) === '/owners') {
            return ['data' => array_column($vocabulary['owners'], 'slug')];
        }

        $types = [];
        foreach ($vocabulary['action_types'] as $type) {
            $types[] = [
                'owner'       => $type['owner'],
                'type_key'    => $type['key'],
                'label'       => $type['label'],
                'description' => $type['description'],
            ];
        }

        return ['data' => $types];
    }

    /**
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array delete_by_args($args)
     */
    public function delete_args(array $input, Context $ctx)
    {
        $args = $this->query->args_from($input, ['window' => null]);
        unset($args['limit'], $args['offset'], $args['order']);

        if ($ctx->surface() === Context::REST) {
            /**
             * Filter the delete args before execution.
             *
             * @param array            $args    Filter arguments.
             * @param \WP_REST_Request $request The REST request.
             *
             * @since 1.2.0
             */
            $args = apply_filters('ewp_logger_rest_delete_args', $args, $ctx->raw());
        }

        return [$args];
    }

    /**
     * @param int     $deleted Deleted count.
     * @param array   $input   Normalised input.
     * @param Context $ctx     Invocation context.
     *
     * @return array
     */
    public function delete_transform($deleted, array $input, Context $ctx)
    {
        return [
            'deleted' => (int) $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted entries */
                __('%d log entries deleted.', 'extend-wp'),
                (int) $deleted
            ),
        ];
    }

    /**
     * @param array $input Normalised input.
     *
     * @return array write($owner, $action_type, $message, $data, $level, $object_type, $behaviour)
     */
    public function write_args(array $input)
    {
        return [
            (string) $input['owner'],
            (string) $input['action_type'],
            (string) $input['message'],
            isset($input['data']) ? (array) $input['data'] : [],
            isset($input['level']) ? (string) $input['level'] : 'editor',
            isset($input['object_type']) ? (string) $input['object_type'] : '',
            isset($input['behaviour']) ? (string) $input['behaviour'] : 'success',
        ];
    }

    /* ---------------------------------------------------------------------
     * CLI presenters
     * ------------------------------------------------------------------ */

    /**
     * @param array       $result Search payload.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_entries($result, array $input, $format)
    {
        $entries = isset($result['entries']) ? $result['entries'] : [];
        if ($entries === []) {
            \WP_CLI::success('No log entries found matching the criteria.');
            return;
        }

        $rows = array_map(function ($entry) {
            return [
                'ID'          => isset($entry['log_id']) ? $entry['log_id'] : '-',
                'Date'        => isset($entry['created_at']) ? $entry['created_at'] : '',
                'Owner'       => isset($entry['owner']) ? $entry['owner'] : '',
                'Action'      => isset($entry['action_type']) ? $entry['action_type'] : '',
                'Object Type' => isset($entry['object_type']) ? $entry['object_type'] : '',
                'Level'       => isset($entry['level']) ? $entry['level'] : '',
                'Status'      => isset($entry['behaviour_label']) ? $entry['behaviour_label'] : '',
                'User'        => !empty($entry['user_display_name']) ? $entry['user_display_name'] : (isset($entry['user_id']) ? $entry['user_id'] : 0),
                'Message'     => mb_substr(isset($entry['message']) ? $entry['message'] : '', 0, 80),
            ];
        }, $entries);

        \WP_CLI\Utils\format_items($format ?: 'table', $rows, array_keys($rows[0]));
    }

    /**
     * @param array       $stats  Stats payload.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_stats($stats, array $input, $format)
    {
        $rows = [
            ['Metric' => 'Window', 'Count' => $stats['date_from'] . ' → ' . $stats['date_to']],
            ['Metric' => 'Total Entries', 'Count' => $stats['total']],
            ['Metric' => 'Success', 'Count' => $stats['by_behaviour']['success']],
            ['Metric' => 'Errors', 'Count' => $stats['by_behaviour']['error']],
            ['Metric' => 'Warnings', 'Count' => $stats['by_behaviour']['warning']],
        ];
        foreach ($stats['by_level'] as $level => $count) {
            $rows[] = ['Metric' => ucfirst($level) . ' Level', 'Count' => $count];
        }
        foreach ($stats['by_owner'] as $owner => $count) {
            $rows[] = ['Metric' => "Owner: {$owner}", 'Count' => $count];
        }
        if (!empty($stats['capped'])) {
            \WP_CLI::warning($stats['hint']);
        }

        \WP_CLI\Utils\format_items($format ?: 'table', $rows, ['Metric', 'Count']);
    }

    /**
     * @param array       $vocabulary Vocabulary payload.
     * @param array       $input      Input.
     * @param string|null $format     Requested --format.
     *
     * @return void
     */
    public function present_types($vocabulary, array $input, $format)
    {
        $types = isset($vocabulary['action_types']) ? $vocabulary['action_types'] : [];
        if ($types === []) {
            \WP_CLI::success('No action types registered.');
            return;
        }

        $rows = array_map(function ($type) {
            return ['Owner' => $type['owner'], 'Type Key' => $type['key'], 'Label' => $type['label'], 'Description' => $type['description']];
        }, $types);

        \WP_CLI\Utils\format_items($format ?: 'table', $rows, ['Owner', 'Type Key', 'Label', 'Description']);
    }

    /* ---------------------------------------------------------------------
     * Fields
     * ------------------------------------------------------------------ */

    /**
     * @param string $name date_from|date_to
     *
     * @return Field
     */
    private function date_field($name)
    {
        $text = $name === 'date_from'
            ? __('Start of the date range, as Y-m-d (d-m-Y accepted). Abilities default to 7 days ago; REST and the CLI are unbounded.', 'extend-wp')
            : __('End of the date range, as Y-m-d (d-m-Y accepted). Abilities default to today.', 'extend-wp');

        return Field::string($name)->describe($text);
    }

    /**
     * The filters shared by search and delete.
     *
     * @return Field[]
     */
    private function filter_fields()
    {
        $multi = function ($name, $description) {
            return Field::custom($name, ['type' => ['string', 'array'], 'items' => ['type' => 'string'], 'description' => $description]);
        };

        return [
            $this->date_field('date_from'),
            $this->date_field('date_to'),
            $multi('owner', __('Owner slug, or a list of them (comma separated on REST and the CLI). Use list-vocabulary for valid values.', 'extend-wp')),
            $multi('action_type', __('Action type key, or a list of them.', 'extend-wp')),
            $multi('object_type', __('Object type, e.g. post_type, taxonomy, user, option, custom_content, database, system.', 'extend-wp')),
            Field::custom('behaviour', ['type' => ['string', 'array'], 'items' => ['type' => 'string', 'enum' => ['error', 'success', 'warning']], 'description' => __('Filter by outcome: error, success or warning (storage integers 0, 1, 2 also accepted). Use "error" to find failures.', 'extend-wp')]),
            Field::enum('level', ['editor', 'developer'])->describe(__('Filter by log level.', 'extend-wp')),
            Field::int('user_id')->describe(__('Only entries recorded for this WordPress user ID.', 'extend-wp')),
            Field::custom('object_id', ['type' => ['integer', 'array'], 'items' => ['type' => 'integer'], 'description' => __('Only entries about this object ID, or any of these IDs.', 'extend-wp')]),
            Field::string('request_id')->describe(__('Only entries from this request. Prefer get-request-trace for full traces.', 'extend-wp')),
            Field::string('search_text')->describe(__('Free-text search across message, owner, action, data payload and user. Expensive over wide date ranges - narrow the dates first.', 'extend-wp')),
            Field::string('object_filter')->describe(__('Viewer object filter as group:type, e.g. custom_content:ewp_fields.', 'extend-wp')),
            Field::string('object_filter_ids')->describe(__('Viewer object ids, comma separated.', 'extend-wp')),
        ];
    }

    /**
     * @return Field[]
     */
    private function pagination_fields()
    {
        return [
            Field::int('limit')->min(1)->max(200)->describe(__('Entries to return, 1-200. Defaults to 50.', 'extend-wp')),
            Field::int('offset')->min(0)->describe(__('Entries to skip, for paging.', 'extend-wp')),
            Field::enum('order', ['ASC', 'DESC'])->describe(__('Sort by date. Defaults to DESC (newest first).', 'extend-wp')),
            Field::int('page')->min(1)->describe(__('Page number (REST paging alternative to offset).', 'extend-wp')),
            Field::int('per_page')->min(1)->max(10000)->describe(__('Page size for REST paging; up to the storage ceiling of 10000.', 'extend-wp')),
        ];
    }

    /**
     * @return Field[]
     */
    private function write_fields()
    {
        return [
            Field::string('owner')->required()->describe(__('Which plugin or subsystem the entry belongs to, for example filox or extend-wp.', 'extend-wp')),
            Field::string('action_type')->required()->describe(__('A short machine readable action key, for example content_save or import_run.', 'extend-wp')),
            Field::string('message')->required()->describe(__('A human readable one line summary of what happened.', 'extend-wp')),
            Field::object('data')->describe(__('Structured payload stored with the entry. Do not put credentials or personal data here.', 'extend-wp')),
            Field::enum('level', ['editor', 'developer'])->describe(__('Audience for the entry. Defaults to editor.', 'extend-wp')),
            Field::string('object_type')->describe(__('What the entry is about, for example post, term or custom_content.', 'extend-wp')),
            Field::enum('behaviour', ['error', 'success', 'warning'])->describe(__('Outcome of the action. Defaults to success.', 'extend-wp')),
        ];
    }

    /* ---------------------------------------------------------------------
     * Output schemas (core validates ability output strictly)
     * ------------------------------------------------------------------ */

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
                'entries'   => ['type' => 'array', 'items' => $this->entry_item_schema()],
            ],
        ];
    }

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

    private function vocabulary_output_schema()
    {
        $pair = function (array $keys) {
            $properties = [];
            foreach ($keys as $key) {
                $properties[$key] = ['type' => 'string'];
            }
            return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties]];
        };

        return [
            'type'       => 'object',
            'properties' => [
                'owners'       => $pair(['slug', 'label']),
                'action_types' => $pair(['key', 'owner', 'label', 'description']),
                'object_types' => $pair(['key', 'label']),
                'behaviours'   => ['type' => 'array', 'items' => ['type' => 'string']],
                'levels'       => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function trace_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'request_id'      => ['type' => 'string'],
                'count'           => ['type' => 'integer'],
                'request_context' => ['type' => 'string'],
                'hint'            => ['type' => 'string'],
                'entries'         => ['type' => 'array', 'items' => $this->entry_item_schema()],
            ],
        ];
    }

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

    private function cleanup_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'deleted'          => ['type' => 'integer'],
                'cutoff_date'      => ['type' => 'string'],
                'retention_months' => ['type' => 'integer'],
            ],
            'additionalProperties' => true,
        ];
    }

    private function delete_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'deleted' => ['type' => 'integer'],
                'message' => ['type' => 'string'],
            ],
        ];
    }

    private function write_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'logged'     => ['type' => 'boolean'],
                'owner'      => ['type' => 'string'],
                'request_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => true,
        ];
    }
}
