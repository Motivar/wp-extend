<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one query layer behind every logger surface.
 *
 * REST, WP-CLI, the abilities and the diagnose box all used to map their
 * own input onto storage arguments, each with its own defaults, behaviour
 * vocabulary and subset of filters. This service owns that mapping once:
 * `args_from()` turns any surface's input into storage arguments, and the
 * read/aggregate/delete operations run on top of it.
 *
 * Surfaces keep their own defaults (an ability defaults to a 7-day window,
 * REST and CLI stay unbounded) by passing them in; the rules for reading
 * a parameter never differ.
 *
 * @package    EWP\Logger
 * @author     Motivar
 *
 * @since 1.5.0
 */
class EWP_Logger_Query
{
    /**
     * Rows returned when no limit is given.
     *
     * @var int
     */
    const DEFAULT_LIMIT = 50;

    /**
     * Ceiling on rows returned by a single read.
     *
     * @var int
     */
    const MAX_LIMIT = 500;

    /**
     * Lookback applied when a caller asks for a default window.
     *
     * @var int
     */
    const DEFAULT_WINDOW_DAYS = 7;

    /**
     * Ceiling used for internal aggregate scans.
     *
     * @var int
     */
    const AGGREGATE_SCAN_LIMIT = 10000;

    /**
     * Input keys handled explicitly; anything else registered through
     * `ewp_logger_filter_params` is forwarded untouched.
     *
     * @var string[]
     */
    const HANDLED = [
        'owner', 'action_type', 'object_type', 'behaviour', 'level', 'user_id',
        'object_id', 'object_filter', 'object_filter_ids', 'date_from', 'date_to',
        'request_id', 'search_text', 'limit', 'offset', 'order', 'page', 'per_page',
    ];

    /**
     * Logger facade, resolved lazily so the service can be built before init.
     *
     * @var EWP_Logger|null
     */
    private $logger;

    /**
     * @param EWP_Logger|null $logger Facade to query through; defaults to the singleton.
     *
     * @since 1.5.0
     */
    public function __construct(?EWP_Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /* ---------------------------------------------------------------------
     * Parameters
     * ------------------------------------------------------------------ */

    /**
     * The recognised filter parameter names.
     *
     * @return string[]
     *
     * @since 1.5.0
     */
    public static function params()
    {
        $params = [
            'owner',
            'action_type',
            'object_type',
            'object_filter',
            'object_filter_ids',
            'behaviour',
            'level',
            'user_id',
            'date_from',
            'date_to',
            'request_id',
            'search_text',
        ];

        /**
         * Filter the list of recognised filter parameter names.
         *
         * A plugin that adds a viewer filter registers it here so every
         * surface (REST, CLI, abilities, diagnose) forwards it to storage.
         *
         * @param array $params List of parameter names.
         *
         * @since 1.2.0
         */
        return apply_filters('ewp_logger_filter_params', $params);
    }

    /**
     * Resolve the date window of a request.
     *
     * @param array    $input        Surface input holding date_from / date_to.
     * @param int|null $default_days Lookback applied when date_from is missing; null keeps it open.
     *
     * @return array{date_from:string,date_to:string}
     *
     * @since 1.5.0
     */
    public function window(array $input, $default_days = null)
    {
        $from = !empty($input['date_from']) ? EWP_Logger_Formatter::normalize_date($input['date_from']) : '';
        $to   = !empty($input['date_to']) ? EWP_Logger_Formatter::normalize_date($input['date_to']) : '';

        if ($from === '' && $default_days !== null) {
            $from = gmdate('Y-m-d', strtotime('-' . (int) $default_days . ' days'));
        }

        if ($to === '' && $default_days !== null) {
            $to = gmdate('Y-m-d');
        }

        return ['date_from' => $from, 'date_to' => $to];
    }

    /**
     * Turn any surface's input into storage query arguments.
     *
     * Accepts what every surface sends today: comma separated or array
     * multi-values, `d-m-Y` or `Y-m-d` dates, behaviour as labels
     * (`error|success|warning`) or storage integers, the viewer's
     * `object_filter` / `object_filter_ids` pair, `page`/`per_page` as
     * well as `limit`/`offset`, and any extra parameter registered through
     * `ewp_logger_filter_params`.
     *
     * @param array $input    Surface input.
     * @param array $defaults `window` (int days|null), `limit` (int), `max` (int).
     *
     * @return array Storage arguments.
     *
     * @since 1.5.0
     */
    public function args_from(array $input, array $defaults = [])
    {
        $defaults = array_merge(['window' => null, 'limit' => self::DEFAULT_LIMIT, 'max' => self::MAX_LIMIT], $defaults);

        $args = $this->window($input, $defaults['window']);
        $args = $this->apply_multi_text($args, $input);
        $args = $this->apply_scalars($args, $input);
        $args = $this->apply_object_filters($args, $input);
        $args = $this->apply_behaviour($args, $input);
        $args = $this->apply_extras($args, $input);

        return $this->apply_pagination($args, $input, (int) $defaults['limit'], (int) $defaults['max']);
    }

    /* ---------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * Run prepared storage arguments and shape the entries.
     *
     * @param array  $args  Storage arguments from args_from() (possibly filtered by a surface).
     * @param string $shape `full` keeps the whole data payload; `compact` truncates it to a preview.
     *
     * @return array{entries:array,total:int}
     *
     * @since 1.5.0
     */
    public function fetch(array $args, $shape = 'full')
    {
        $logger  = $this->logger();
        $entries = $logger->get_logs($args);
        $total   = (int) $logger->count_logs($args);

        $shaped = [];
        foreach ($entries as $entry) {
            $shaped[] = $shape === 'compact'
                ? EWP_Logger_Formatter::compact_entry($entry)
                : EWP_Logger_Formatter::prepare_entry($entry);
        }

        return ['entries' => $shaped, 'total' => $total];
    }

    /**
     * Search entries from surface input.
     *
     * @param array $input    Surface input.
     * @param array $defaults args_from() defaults plus `shape` (`full`|`compact`).
     *
     * @return array total, returned, limit, offset, date_from, date_to, entries, hint?
     *
     * @since 1.5.0
     */
    public function search(array $input, array $defaults = [])
    {
        $shape = isset($defaults['shape']) ? $defaults['shape'] : 'full';
        unset($defaults['shape']);

        $args   = $this->args_from($input, $defaults);
        $result = $this->fetch($args, $shape);

        $out = [
            'total'     => $result['total'],
            'returned'  => count($result['entries']),
            'limit'     => (int) $args['limit'],
            'offset'    => (int) $args['offset'],
            'date_from' => $args['date_from'],
            'date_to'   => $args['date_to'],
            'entries'   => $result['entries'],
        ];

        if ($result['total'] > $args['offset'] + count($result['entries'])) {
            $out['hint'] = __('More entries match than were returned. Prefer narrowing the filters (date range, owner, behaviour) over paging through everything.', 'extend-wp');
        }

        return $out;
    }

    /**
     * Summarise volume over a window in a single scan.
     *
     * @param array $input Surface input: date_from, date_to, owner.
     * @param int|null $default_days Window applied when date_from is missing.
     *
     * @return array date_from, date_to, total, by_behaviour, by_level, by_owner, capped, hint?
     *
     * @since 1.5.0
     */
    public function stats(array $input = [], $default_days = self::DEFAULT_WINDOW_DAYS)
    {
        $args = array_merge($this->window($input, $default_days), [
            'limit'  => self::AGGREGATE_SCAN_LIMIT,
            'offset' => 0,
            'order'  => 'DESC',
        ]);

        if (!empty($input['owner'])) {
            $args['owner'] = $this->multi_text($input['owner']);
        }

        $entries = $this->logger()->get_logs($args);
        $scanned = count($entries);
        $capped  = $scanned >= self::AGGREGATE_SCAN_LIMIT;

        $by_behaviour = ['error' => 0, 'success' => 0, 'warning' => 0];
        $by_level     = [];
        $by_owner     = [];

        foreach ($entries as $entry) {
            $label = EWP_Logger_Formatter::behaviour_label(isset($entry['behaviour']) ? $entry['behaviour'] : 1);
            $by_behaviour[$label] = (isset($by_behaviour[$label]) ? $by_behaviour[$label] : 0) + 1;

            $level = (string) (isset($entry['level']) ? $entry['level'] : '');
            if ($level !== '') {
                $by_level[$level] = (isset($by_level[$level]) ? $by_level[$level] : 0) + 1;
            }

            $owner = (string) (isset($entry['owner']) ? $entry['owner'] : '');
            if ($owner !== '') {
                $by_owner[$owner] = (isset($by_owner[$owner]) ? $by_owner[$owner] : 0) + 1;
            }
        }

        arsort($by_owner);

        $result = [
            'date_from'    => $args['date_from'],
            'date_to'      => $args['date_to'],
            'total'        => (int) ($capped ? $this->logger()->count_logs($args) : $scanned),
            'by_behaviour' => array_map('intval', $by_behaviour),
            'by_level'     => array_map('intval', $by_level),
            'by_owner'     => array_map('intval', $by_owner),
            'capped'       => $capped,
        ];

        if ($capped) {
            $result['hint'] = sprintf(
                /* translators: %d: number of entries scanned */
                __('Breakdowns are based on the most recent %d entries only; narrow the date range for exact figures.', 'extend-wp'),
                self::AGGREGATE_SCAN_LIMIT
            );
        }

        return $result;
    }

    /**
     * Every registered owner, action type and object type, plus the valid
     * behaviour and level values.
     *
     * @return array owners, action_types, object_types, behaviours, levels
     *
     * @since 1.5.0
     */
    public function vocabulary()
    {
        $owners = [];
        foreach (EWP_Logger::get_registered_owners() as $owner) {
            $owners[] = ['slug' => (string) $owner, 'label' => (string) EWP_Logger::resolve_owner_label($owner)];
        }

        $action_types = [];
        foreach (EWP_Logger::get_registered_types() as $owner => $types) {
            foreach ($types as $type_key => $type_data) {
                $action_types[] = [
                    'key'         => (string) $type_key,
                    'owner'       => (string) $owner,
                    'label'       => (string) __($type_data['label'], 'extend-wp'),
                    'description' => (string) __(isset($type_data['description']) ? $type_data['description'] : '', 'extend-wp'),
                ];
            }
        }

        $object_types = [];
        foreach (['post_type', 'taxonomy', 'user', 'option', 'custom_content', 'database', 'system'] as $key) {
            $object_types[] = ['key' => $key, 'label' => (string) EWP_Logger::resolve_object_type_label($key)];
        }

        return [
            'owners'       => $owners,
            'action_types' => $action_types,
            'object_types' => $object_types,
            'behaviours'   => ['error', 'success', 'warning'],
            'levels'       => ['editor', 'developer'],
        ];
    }

    /**
     * One entry by id, with its full payload.
     *
     * @param string $log_id Entry id.
     * @param array  $input  Optional `date` (pins the day) or date_from / date_to.
     *
     * @return array found, entry, hint?
     *
     * @since 1.5.0
     */
    public function entry($log_id, array $input = [])
    {
        $log_id = (string) $log_id;

        if (!empty($input['date'])) {
            $date   = EWP_Logger_Formatter::normalize_date($input['date']);
            $window = ['date_from' => $date, 'date_to' => $date];
        } else {
            $window = $this->window($input, self::DEFAULT_WINDOW_DAYS);
        }

        $entries = $this->logger()->get_logs(array_merge($window, [
            'limit'  => self::AGGREGATE_SCAN_LIMIT,
            'offset' => 0,
            'order'  => 'DESC',
        ]));

        foreach ($entries as $entry) {
            if (isset($entry['log_id']) && (string) $entry['log_id'] === $log_id) {
                return ['found' => true, 'entry' => EWP_Logger_Formatter::prepare_entry($entry)];
            }
        }

        return [
            'found' => false,
            'entry' => null,
            'hint'  => __('No entry with that log_id in the searched window. Pass the entry date as "date" (Y-m-d), or widen date_from/date_to.', 'extend-wp'),
        ];
    }

    /**
     * Every entry recorded during one request, oldest first.
     *
     * @param string $request_id Request id.
     * @param array  $input      Optional date_from / date_to.
     *
     * @return array request_id, count, request_context, entries, hint?
     *
     * @since 1.5.0
     */
    public function trace($request_id, array $input = [])
    {
        $request_id = sanitize_text_field((string) $request_id);

        $entries = $this->logger()->get_logs(array_merge($this->window($input, self::DEFAULT_WINDOW_DAYS), [
            'request_id' => $request_id,
            'limit'      => self::MAX_LIMIT,
            'offset'     => 0,
            'order'      => 'ASC',
        ]));

        $prepared = [];
        foreach ($entries as $entry) {
            $prepared[] = EWP_Logger_Formatter::compact_entry($entry);
        }

        $result = [
            'request_id'      => $request_id,
            'count'           => count($prepared),
            'request_context' => $prepared ? (string) (isset($prepared[0]['request_context']) ? $prepared[0]['request_context'] : '') : '',
            'entries'         => $prepared,
        ];

        if ($prepared === []) {
            $result['hint'] = __('No entries found for that request_id in the searched window. Widen date_from/date_to if the request is older.', 'extend-wp');
        }

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Writes
     * ------------------------------------------------------------------ */

    /**
     * Delete entries matching prepared storage arguments.
     *
     * Pagination keys are ignored; an unbounded argument set deletes everything.
     *
     * @param array $args Storage arguments.
     *
     * @return int|\WP_Error Number of deleted entries.
     *
     * @since 1.5.0
     */
    public function delete_by_args(array $args)
    {
        unset($args['limit'], $args['offset'], $args['order']);

        $storage = $this->logger()->get_storage();
        if (!$storage) {
            return new \WP_Error('ewp_logger_no_storage', __('Logger storage is not initialized.', 'extend-wp'), ['status' => 500]);
        }

        $deleted = $storage->delete_by_filters($args);
        if ($deleted === -1) {
            return new \WP_Error('ewp_logger_delete_failed', __('Failed to delete log entries.', 'extend-wp'), ['status' => 500]);
        }

        return (int) $deleted;
    }

    /**
     * Delete entries matching surface input.
     *
     * @param array $input Surface input (filters only; no default window).
     *
     * @return int|\WP_Error
     *
     * @since 1.5.0
     */
    public function delete(array $input)
    {
        return $this->delete_by_args($this->args_from($input, ['window' => null]));
    }

    /**
     * Run the retention cleanup now.
     *
     * @param int $months Retention override in months; 0 keeps the configured value.
     *
     * @return array|\WP_Error Cleanup result: deleted, cutoff_date, retention_months.
     *
     * @since 1.5.0
     */
    public function cleanup($months = 0)
    {
        $storage = $this->logger()->get_storage();
        if (!$storage) {
            return new \WP_Error('ewp_logger_no_storage', __('Logger storage is not initialized.', 'extend-wp'), ['status' => 500]);
        }

        $cleanup = new EWP_Logger_Cleanup($storage);
        $result  = $cleanup->manual_cleanup(max(0, (int) $months));

        return is_array($result) ? $result : new \WP_Error('ewp_logger_cleanup_failed', __('Cleanup returned no results.', 'extend-wp'), ['status' => 500]);
    }

    /**
     * Write one entry.
     *
     * @param string $owner       Owner slug.
     * @param string $action_type Action type key.
     * @param string $message     Message.
     * @param array  $data        Payload.
     * @param string $level       editor|developer.
     * @param string $object_type Object type.
     * @param int    $behaviour   Behaviour constant.
     *
     * @return bool Whether the entry was queued.
     *
     * @since 1.5.0
     */
    public function write($owner, $action_type, $message, array $data = [], $level = 'editor', $object_type = '', $behaviour = 1)
    {
        return (bool) EWP_Logger::log($owner, $action_type, $message, $data, $level, $object_type, $behaviour);
    }

    /**
     * Capability required to read the logs.
     *
     * @return string
     *
     * @since 1.5.0
     */
    public function viewer_capability()
    {
        return EWP_Logger::get_viewer_capability();
    }

    /* ---------------------------------------------------------------------
     * args_from() steps
     * ------------------------------------------------------------------ */

    /**
     * @return EWP_Logger
     */
    private function logger()
    {
        if ($this->logger === null) {
            $this->logger = EWP_Logger::instance();
        }

        return $this->logger;
    }

    /**
     * Split a comma separated string or array into a clean list.
     *
     * @param mixed $value Raw value.
     *
     * @return string[]
     */
    public static function split_multi($value)
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $values), function ($item) {
            return $item !== '';
        }));
    }

    /**
     * Sanitised single value or list, the shape storage accepts.
     *
     * @param mixed $value Raw value.
     *
     * @return string|string[]
     */
    private function multi_text($value)
    {
        $values = array_map('sanitize_text_field', self::split_multi($value));

        return count($values) === 1 ? $values[0] : $values;
    }

    private function apply_multi_text(array $args, array $input)
    {
        foreach (['owner', 'action_type', 'object_type'] as $key) {
            if (!empty($input[$key])) {
                $args[$key] = $this->multi_text($input[$key]);
            }
        }

        return $args;
    }

    private function apply_scalars(array $args, array $input)
    {
        if (!empty($input['level'])) {
            $levels        = self::split_multi($input['level']);
            $args['level'] = $levels === [] ? '' : sanitize_text_field($levels[0]);
        }

        foreach (['request_id', 'search_text'] as $key) {
            if (!empty($input[$key])) {
                $args[$key] = sanitize_text_field((string) $input[$key]);
            }
        }

        if (!empty($input['user_id'])) {
            $args['user_id'] = absint($input['user_id']);
        }

        if (!empty($input['object_id'])) {
            $ids               = array_values(array_filter(array_map('absint', self::split_multi($input['object_id']))));
            $args['object_id'] = count($ids) === 1 ? $ids[0] : $ids;
        }

        return $args;
    }

    /**
     * The viewer's object_id_filter pair: the group becomes an object_type
     * constraint unless one was given, the ids become object_id.
     */
    private function apply_object_filters(array $args, array $input)
    {
        if (!empty($input['object_filter'])) {
            $parts = explode(':', (string) $input['object_filter'], 2);
            $group = !empty($parts[0]) ? sanitize_key($parts[0]) : '';
            if ($group !== '' && !isset($args['object_type'])) {
                $args['object_type'] = $group;
            }
        }

        if (!empty($input['object_filter_ids'])) {
            $ids = array_values(array_filter(array_map('absint', self::split_multi($input['object_filter_ids']))));
            if ($ids !== []) {
                $args['object_id'] = $ids;
            }
        }

        return $args;
    }

    /**
     * Behaviour arrives as labels (abilities), integers (viewer, CLI) or a mix.
     */
    private function apply_behaviour(array $args, array $input)
    {
        if (!isset($input['behaviour']) || $input['behaviour'] === '' || $input['behaviour'] === null) {
            return $args;
        }

        $mapped = [];
        foreach (self::split_multi($input['behaviour']) as $raw) {
            $value = is_numeric($raw)
                ? EWP_Logger::normalize_behaviour((int) $raw)
                : EWP_Logger_Formatter::behaviour_from_label($raw);
            if ($value !== null) {
                $mapped[] = (int) $value;
            }
        }

        if (count($mapped) === 1) {
            $args['behaviour'] = $mapped[0];
        } elseif (count($mapped) > 1) {
            $args['behaviour'] = array_values(array_unique($mapped));
        }

        return $args;
    }

    /**
     * Forward parameters a plugin registered via `ewp_logger_filter_params`.
     */
    private function apply_extras(array $args, array $input)
    {
        foreach (self::params() as $param) {
            if (in_array($param, self::HANDLED, true) || !isset($input[$param])) {
                continue;
            }

            $value = $input[$param];
            if ($value === '' || $value === null) {
                continue;
            }

            $args[$param] = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field((string) $value);
        }

        return $args;
    }

    private function apply_pagination(array $args, array $input, $default_limit, $max)
    {
        $limit = isset($input['limit']) ? absint($input['limit']) : (isset($input['per_page']) ? absint($input['per_page']) : $default_limit);
        $limit = max(1, min($max, $limit ?: $default_limit));

        $offset = isset($input['offset']) ? max(0, absint($input['offset'])) : 0;
        if (!isset($input['offset']) && isset($input['page'])) {
            $offset = max(0, (absint($input['page']) - 1) * $limit);
        }

        $args['limit']  = $limit;
        $args['offset'] = $offset;
        $args['order']  = isset($input['order']) && strtoupper((string) $input['order']) === 'ASC' ? 'ASC' : 'DESC';

        return $args;
    }
}
