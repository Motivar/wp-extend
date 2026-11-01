<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read and write custom content DB rows on behalf of the abilities.
 *
 * Every persistence path goes through the plugin's own helpers
 * (`awm_custom_content_save`, `awm_custom_content_delete`,
 * `awm_get_db_content`), so ability writes fire the same actions as the admin
 * UI: transients are flushed and the activity log records the change.
 *
 * @package    EWP\Content
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class Content_Service
{
    /**
     * Field cases that render markup or actions and never store a value.
     *
     * @var string[]
     */
    const PRESENTATION_CASES = ['html', 'message', 'button', 'function', 'awm_tab'];

    /**
     * Default number of rows returned by list_items().
     *
     * @var int
     */
    const DEFAULT_LIMIT = 50;

    /**
     * Ceiling for list_items() so no surface can pull a whole table at once.
     *
     * @var int
     */
    const MAX_LIMIT = 200;

    /**
     * Main table column keys that cannot be used as meta keys.
     *
     * @var string[]
     */
    const RESERVED_KEYS = ['content_id', 'title', 'status', 'ewp_date', 'modified', 'user_id', 'hash', 'awm_custom_meta'];

    /**
     * Cached merged libraries keyed by content type.
     *
     * @var array
     */
    private $libraries = [];

    /**
     * Return the resolved configuration for a content type.
     *
     * @param string $content_type Content type id, for example `ewp_fields`.
     *
     * @return array|null Null when the content type is not registered.
     *
     * @since 1.4.0
     */
    public function get_config($content_type)
    {
        if (empty($content_type) || !class_exists('AWM_Add_Content_DB_Setup')) {
            return null;
        }

        $configuration = \AWM_Add_Content_DB_Setup::$ewp_data_configuration;

        if (empty($configuration) && class_exists('AWM_Content_DB')) {
            \AWM_Content_DB::get_instance()->register_content();
            $configuration = \AWM_Add_Content_DB_Setup::$ewp_data_configuration;
        }

        return isset($configuration[$content_type]) ? $configuration[$content_type] : null;
    }

    /**
     * Return every registered content type id with its configuration.
     *
     * @return array Configurations keyed by content type id.
     *
     * @since 1.4.0
     */
    public function list_types()
    {
        if (!class_exists('AWM_Add_Content_DB_Setup')) {
            return [];
        }

        $configuration = \AWM_Add_Content_DB_Setup::$ewp_data_configuration;

        if (empty($configuration) && class_exists('AWM_Content_DB')) {
            \AWM_Content_DB::get_instance()->register_content();
            $configuration = \AWM_Add_Content_DB_Setup::$ewp_data_configuration;
        }

        return is_array($configuration) ? $configuration : [];
    }

    /**
     * Capability required to work with a content type.
     *
     * @param string $content_type Content type id.
     *
     * @return string
     *
     * @since 1.4.0
     */
    public function get_capability($content_type)
    {
        $config = $this->get_config($content_type);

        return isset($config['capability']) ? $config['capability'] : 'edit_posts';
    }

    /**
     * How a content type handles a generic action (its `surfaces` registration key, since 1.11.0).
     *
     * @param string $content_type Content type id.
     * @param string $surface      `duplicate`, `set_status`, `delete` or `generic_write`.
     *
     * @return bool|callable|null `duplicate` / `generic_write`: bool (default true). `set_status` / `delete`:
     *                            null for the generic implementation, a callable that replaces it, false when refused.
     *
     * @since 1.11.0
     */
    public function get_surface($content_type, $surface)
    {
        if (!class_exists('AWM_Add_Content_DB_Setup')) {
            return in_array($surface, ['duplicate', 'generic_write'], true) ? true : null;
        }
        $config   = $this->get_config($content_type);
        $surfaces = \AWM_Add_Content_DB_Setup::normalize_surfaces(isset($config['surfaces']) ? $config['surfaces'] : []);

        return array_key_exists($surface, $surfaces) ? $surfaces[$surface] : null;
    }

    /**
     * The error returned when a content type turned a generic action off.
     *
     * @param string $content_type Content type id.
     * @param string $surface      The refused action.
     *
     * @return \WP_Error `ewp_content_surface_disabled`, status 403.
     *
     * @since 1.11.0
     */
    private function surface_disabled($content_type, $surface)
    {
        return new \WP_Error(
            'ewp_content_surface_disabled',
            sprintf(
                /* translators: 1: action (duplicate, set_status, delete, create, update), 2: content type id. */
                __('The %2$s content type does not allow the generic "%1$s" action. Use the plugin that owns the type.', 'extend-wp'),
                $surface,
                $content_type
            ),
            ['status' => 403]
        );
    }

    /**
     * Refuse create / update when the type set `surfaces.generic_write` to false.
     *
     * @param string $content_type Content type id.
     * @param string $action       `create` or `update`, for the message.
     *
     * @return true|\WP_Error
     *
     * @since 1.11.0
     */
    private function check_generic_write($content_type, $action)
    {
        return $this->get_surface($content_type, 'generic_write') === false ? $this->surface_disabled($content_type, $action) : true;
    }

    /**
     * Status keys registered for a content type.
     *
     * @param string $content_type Content type id.
     *
     * @return string[]
     *
     * @since 1.4.0
     */
    public function get_statuses($content_type)
    {
        $config = $this->get_config($content_type);

        if (empty($config['status']) || !is_array($config['status'])) {
            return [];
        }

        return array_map('strval', array_keys($config['status']));
    }

    /**
     * Merge every metabox library registered for a content type.
     *
     * @param string $content_type Content type id.
     *
     * @return array Field library keyed by meta key.
     *
     * @since 1.4.0
     */
    public function resolve_library($content_type)
    {
        if (isset($this->libraries[$content_type])) {
            return $this->libraries[$content_type];
        }

        $config  = $this->get_config($content_type);
        $library = [];

        if (!empty($config['metaboxes']) && function_exists('awm_callback_library_options')) {
            foreach ($config['metaboxes'] as $box_key => $box) {
                $options = awm_callback_library_options($box);
                if (empty($options) || !is_array($options)) {
                    continue;
                }

                $resolved = awm_callback_library($options, $box_key);
                if (is_array($resolved)) {
                    $library = array_merge($library, $resolved);
                }
            }
        }

        $this->libraries[$content_type] = $library;

        return $library;
    }

    /**
     * Describe a content type's fields for an AI caller.
     *
     * @param string $content_type Content type id.
     *
     * @return array List of field descriptors.
     *
     * @since 1.4.0
     */
    public function describe_library($content_type)
    {
        $fields = [];

        foreach ($this->resolve_library($content_type) as $key => $field) {
            if (!is_array($field) || !empty($field['exclude_meta'])) {
                continue;
            }

            $case = isset($field['case']) ? (string) $field['case'] : '';
            if (in_array($case, self::PRESENTATION_CASES, true)) {
                continue;
            }

            $fields[] = [
                'key'      => (string) $key,
                'label'    => isset($field['label']) ? (string) $field['label'] : (string) $key,
                'case'     => $case,
                'required' => self::is_required($field),
            ];
        }

        return $fields;
    }

    /**
     * Validate a meta payload against the content type's library.
     *
     * @param string $content_type Content type id.
     * @param array  $meta         Meta values keyed by meta key.
     * @param bool   $check_required Whether required fields must be present.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    public function validate_meta($content_type, array $meta, $check_required = false)
    {
        $reserved = array_intersect(array_keys($meta), self::RESERVED_KEYS);
        if (!empty($reserved)) {
            return new \WP_Error(
                'ewp_abilities_reserved_meta_key',
                sprintf(
                    /* translators: %s: comma separated list of reserved keys. */
                    __('These keys are reserved by the content table and cannot be used as meta: %s.', 'extend-wp'),
                    implode(', ', $reserved)
                ),
                ['status' => 400]
            );
        }

        $library = $this->resolve_library($content_type);

        if (!empty($library)) {
            $unknown_check = $this->check_unknown_keys($content_type, $meta, $library);
            if (is_wp_error($unknown_check)) {
                return $unknown_check;
            }
        }

        if (!$check_required || empty($library)) {
            return true;
        }

        return $this->check_required_keys($meta, $library);
    }

    /**
     * Reject meta keys the content type does not declare.
     *
     * @param string $content_type Content type id.
     * @param array  $meta         Meta values.
     * @param array  $library      Field library.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    private function check_unknown_keys($content_type, array $meta, array $library)
    {
        /**
         * Filter whether meta keys outside the content type's library are accepted.
         *
         * @param bool   $allow        Default false.
         * @param string $content_type Content type id.
         *
         * @since 1.4.0
         */
        if (apply_filters('ewp_abilities_allow_unknown_meta', false, $content_type)) {
            return true;
        }

        $unknown = array_diff(array_keys($meta), array_keys($library));

        if (empty($unknown)) {
            return true;
        }

        return new \WP_Error(
            'ewp_abilities_unknown_meta_key',
            sprintf(
                /* translators: 1: comma separated unknown keys, 2: content type id. */
                __('Unknown meta keys for %2$s: %1$s. Use the matching list or describe ability to see the valid keys.', 'extend-wp'),
                implode(', ', $unknown),
                $content_type
            ),
            ['status' => 400]
        );
    }

    /**
     * Ensure every required field is present.
     *
     * @param array $meta    Meta values.
     * @param array $library Field library.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    private function check_required_keys(array $meta, array $library)
    {
        $missing = [];

        foreach ($library as $key => $field) {
            if (!is_array($field) || !self::is_required($field)) {
                continue;
            }

            if (!isset($meta[$key]) || $meta[$key] === '' || $meta[$key] === []) {
                $missing[] = (string) $key;
            }
        }

        if (empty($missing)) {
            return true;
        }

        return new \WP_Error(
            'ewp_abilities_missing_required_meta',
            sprintf(
                /* translators: %s: comma separated list of missing keys. */
                __('These required fields are missing: %s.', 'extend-wp'),
                implode(', ', $missing)
            ),
            ['status' => 400]
        );
    }

    /* ---------------------------------------------------------------------
     * CRUD
     * ------------------------------------------------------------------ */

    /**
     * List rows of a content type.
     *
     * @param string $content_type Content type id.
     * @param array  $args         Normalised ability input.
     *
     * @return array Collection payload.
     *
     * @since 1.4.0
     */
    public function list_items($content_type, array $args = [])
    {
        $limit = isset($args['limit']) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = ['limit' => $limit];

        if (!empty($args['status'])) {
            $query['status'] = (array) $args['status'];
        }

        if (!empty($args['search'])) {
            $query['title'] = (string) $args['search'];
        }

        if (!empty($args['include'])) {
            $query['include'] = array_map('intval', (array) $args['include']);
        }

        if (!empty($args['order_by']['column'])) {
            $query['order_by'] = [
                'column' => (string) $args['order_by']['column'],
                'type'   => isset($args['order_by']['type']) ? (string) $args['order_by']['type'] : 'desc',
            ];
        }

        $rows      = awm_get_db_content($content_type, $query);
        $rows      = is_array($rows) ? $rows : [];
        $with_meta = !empty($args['with_meta']);
        $items     = [];

        if ($with_meta && $rows !== []) {
            // One meta query for the page instead of one per row; normalize_row() then reads the primed meta.
            awm_get_db_content_metas($content_type, array_column($rows, 'content_id'));
        }

        foreach ($rows as $row) {
            $items[] = $this->normalize_row($content_type, $row, $with_meta);
        }

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * Query rows of a content type with a DataViews view: search, filters on
     * columns and meta, sort, pagination, plus the total for the pager.
     *
     * The shared implementation behind REST `{prefix}/{type}/query`,
     * `wp ewp content query` and the `ewp-content/query-items` ability.
     *
     * @param string $content_type Content type id.
     * @param array  $view         DataViews view: search (string), filters (list of {field, operator, value}),
     *                             sort ({field, direction}), page (int, from 1), perPage (int, 1–200, default 20).
     * @param bool   $with_meta    Include every meta value per row (one batched query).
     *
     * @return array|\WP_Error { items, count, total, total_pages, page, per_page }; 400 on an unknown field,
     *                         operator or sort.
     *
     * @since 1.7.0
     */
    public function query_items($content_type, array $view, $with_meta = true)
    {
        try {
            $query = \EWP\Db\View_Query::build($content_type, $view, $this->view_fields($content_type));
        } catch (\EWP\Db\Invalid_Where $e) {
            return new \WP_Error('ewp_content_invalid_view', $e->getMessage(), ['status' => 400]);
        }

        $table = $content_type . '_main';
        $rows  = \AWM_DB_Creator::get_db_data($table, '*', $query['where'], $query['order_by'], $query['limit'], $query['offset']);
        $rows  = is_array($rows) ? $rows : [];
        $total = (int) \AWM_DB_Creator::count_db_data($table, $query['where']);

        if ($with_meta && $rows !== []) {
            awm_get_db_content_metas($content_type, array_column($rows, 'content_id'));
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->normalize_row($content_type, $row, $with_meta);
        }

        return [
            'items'       => $items,
            'count'       => count($items),
            'total'       => $total,
            'total_pages' => (int) ceil($total / $query['per_page']),
            'page'        => $query['page'],
            'per_page'    => $query['per_page'],
        ];
    }

    /**
     * Fields a view may filter, search and sort on: the `_main` columns plus
     * every meta field of the type's library.
     *
     * @param string $content_type Content type id.
     *
     * @return array Field id => { source: column|meta, key, type, label, sortable, searchable }.
     *
     * @since 1.7.0
     */
    public function view_fields($content_type)
    {
        $fields = [
            'id'       => ['source' => 'column', 'key' => 'content_id', 'type' => 'integer', 'label' => __('ID', 'extend-wp'), 'sortable' => true, 'searchable' => false],
            'title'    => ['source' => 'column', 'key' => 'content_title', 'type' => 'text', 'label' => __('Title', 'extend-wp'), 'sortable' => true, 'searchable' => true],
            'status'   => ['source' => 'column', 'key' => 'status', 'type' => 'text', 'label' => __('Status', 'extend-wp'), 'sortable' => true, 'searchable' => false],
            'created'  => ['source' => 'column', 'key' => 'created', 'type' => 'datetime', 'label' => __('Created', 'extend-wp'), 'sortable' => true, 'searchable' => false],
            'modified' => ['source' => 'column', 'key' => 'modified', 'type' => 'datetime', 'label' => __('Modified', 'extend-wp'), 'sortable' => true, 'searchable' => false],
            'user_id'  => ['source' => 'column', 'key' => 'user_id', 'type' => 'integer', 'label' => __('Author', 'extend-wp'), 'sortable' => true, 'searchable' => false],
        ];

        $config = $this->get_config($content_type);
        $known  = array_merge(['content_id', 'title', 'ewp_date', 'modified', 'status', 'user_id', 'hash'], array_keys($fields));
        foreach (isset($config['save_columns']) ? (array) $config['save_columns'] : [] as $key => $column) {
            if (in_array($key, $known, true) || !is_array($column) || empty($column['sql'])) {
                continue;
            }
            $fields[$key] = [
                'source'     => 'column',
                'key'        => isset($column['sql_key']) ? (string) $column['sql_key'] : (string) $key,
                'type'       => self::field_type($column),
                'label'      => isset($column['label']) ? (string) $column['label'] : (string) $key,
                'sortable'   => true,
                'searchable' => !empty($column['searchable']),
            ];
        }

        foreach ($this->resolve_library($content_type) as $key => $field) {
            $case = is_array($field) && isset($field['case']) ? (string) $field['case'] : '';
            if (isset($fields[$key]) || !is_array($field) || !empty($field['exclude_meta']) || in_array($case, self::PRESENTATION_CASES, true)) {
                continue;
            }
            $type         = self::field_type($field);
            $fields[$key] = [
                'source'     => 'meta',
                'key'        => (string) $key,
                'type'       => $type,
                'label'      => isset($field['label']) ? (string) $field['label'] : (string) $key,
                'sortable'   => false,
                'searchable' => $type === 'text' && !in_array($case, ['repeater', 'section', 'image', 'gallery'], true),
            ];
        }

        /**
         * Filters the fields a DataViews view may use for a content type.
         *
         * @since 1.7.0
         *
         * @param array  $fields       Field id => { source: column|meta, key, type, label, sortable, searchable }.
         * @param string $content_type Content type id.
         */
        return (array) apply_filters('ewp_content_view_fields', $fields, $content_type);
    }

    /**
     * View field type of a library field or `_main` column.
     *
     * @param array $field Library field (`case`, `type` / `attributes.type`) or column (`sql`).
     *
     * @return string text|integer|number|date|datetime.
     *
     * @since 1.7.0
     */
    private static function field_type(array $field)
    {
        $input = isset($field['attributes']['type']) ? $field['attributes']['type'] : (isset($field['type']) ? $field['type'] : '');
        $map   = ['number' => 'number', 'range' => 'number', 'date' => 'date', 'datetime-local' => 'datetime'];
        if (isset($map[$input])) {
            return $map[$input];
        }
        $sql = isset($field['sql']) ? strtolower((string) $field['sql']) : '';
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $sql)) {
            return 'integer';
        }
        if (preg_match('/^(decimal|float|double)/', $sql)) {
            return 'number';
        }
        return preg_match('/^(datetime|timestamp)/', $sql) ? 'datetime' : (strpos($sql, 'date') === 0 ? 'date' : 'text');
    }

    /**
     * Set the status of several rows through the normal save pipeline, so
     * `{type}_save_action` and `ewp_custom_content_save_action` fire per row.
     * A type's `surfaces.set_status` callable replaces the save (since 1.11.0);
     * `false` refuses it with `ewp_content_surface_disabled` (403).
     *
     * The shared implementation behind REST `{prefix}/{type}/status`,
     * `wp ewp content set-status` and the `ewp-content/set-status` ability.
     *
     * @param string $content_type Content type id.
     * @param array  $ids          Row ids (at most MAX_LIMIT).
     * @param string $status       One of the type's statuses.
     *
     * @return array|\WP_Error { count, updated: int[], not_found: int[] }.
     *
     * @since 1.7.0
     */
    public function set_status($content_type, array $ids, $status)
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_LIMIT);
        if (empty($ids)) {
            return new \WP_Error('ewp_abilities_invalid_id', __('At least one item id is required.', 'extend-wp'), ['status' => 400]);
        }
        if ((string) $status === '') {
            return new \WP_Error('ewp_abilities_invalid_status', __('A status is required.', 'extend-wp'), ['status' => 400]);
        }
        $status = $this->resolve_status($content_type, $status);
        if (is_wp_error($status)) {
            return $status;
        }

        $route = $this->get_surface($content_type, 'set_status');
        if ($route === false) {
            return $this->surface_disabled($content_type, 'set_status');
        }
        if (is_callable($route)) {
            return $this->routed_result(call_user_func($route, $ids, $status, $content_type), $ids, 'updated');
        }

        $updated = $not_found = [];
        foreach ($ids as $id) {
            $result = $this->patch_item($content_type, $id, ['status' => $status]);
            if (is_wp_error($result) && $result->get_error_code() === 'ewp_abilities_not_found') {
                $not_found[] = $id;
                continue;
            }
            if (is_wp_error($result)) {
                return $result;
            }
            $updated[] = $id;
        }

        if (empty($updated)) {
            return new \WP_Error('ewp_abilities_not_found', __('None of the given ids exist.', 'extend-wp'), ['status' => 404]);
        }

        return ['count' => count($updated), 'updated' => $updated, 'not_found' => $not_found];
    }

    /**
     * Return one row with all of its meta.
     *
     * @param string $content_type Content type id.
     * @param int    $id           Row id.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function get_item($content_type, $id)
    {
        $id = (int) $id;

        if ($id <= 0) {
            return new \WP_Error('ewp_abilities_invalid_id', __('A positive item id is required.', 'extend-wp'), ['status' => 400]);
        }

        $rows = awm_get_db_content($content_type, ['include' => [$id], 'limit' => 1]);

        if (empty($rows) || !is_array($rows)) {
            return new \WP_Error(
                'ewp_abilities_not_found',
                sprintf(
                    /* translators: 1: item id, 2: content type id. */
                    __('No %2$s item found with id %1$d.', 'extend-wp'),
                    $id,
                    $content_type
                ),
                ['status' => 404]
            );
        }

        return $this->normalize_row($content_type, reset($rows), true);
    }

    /**
     * Create a row.
     *
     * @param string $content_type Content type id.
     * @param string $title        Item title.
     * @param string $status       Item status.
     * @param array  $meta         Meta values.
     *
     * @return array|\WP_Error The created row; `ewp_content_surface_disabled` (403) when the type set
     *                         `surfaces.generic_write` to false.
     *
     * @since 1.4.0
     */
    public function create_item($content_type, $title, $status, array $meta)
    {
        $allowed = $this->check_generic_write($content_type, 'create');
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $status = $this->resolve_status($content_type, $status);
        if (is_wp_error($status)) {
            return $status;
        }

        $validated = $this->validate_meta($content_type, $meta, true);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $saved = $this->save($content_type, 'new', (string) $title, $status, $meta);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return $this->get_item($content_type, $saved);
    }

    /**
     * Update a row, merging the patch over what is stored.
     *
     * @param string $content_type Content type id.
     * @param int    $id           Row id.
     * @param array  $patch        Keys `title`, `status` and `meta`.
     *
     * @return array|\WP_Error The updated row; `ewp_content_surface_disabled` (403) when the type set
     *                         `surfaces.generic_write` to false.
     *
     * @since 1.4.0
     */
    public function update_item($content_type, $id, array $patch)
    {
        $allowed = $this->check_generic_write($content_type, 'update');
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        return $this->patch_item($content_type, $id, $patch);
    }

    /**
     * Merge a patch over a stored row and save it (update_item() without the generic_write check;
     * set_status() uses it, since a status change is governed by `surfaces.set_status`).
     *
     * @param string $content_type Content type id.
     * @param int    $id           Row id.
     * @param array  $patch        Keys `title`, `status` and `meta`.
     *
     * @return array|\WP_Error The updated row.
     *
     * @since 1.11.0
     */
    private function patch_item($content_type, $id, array $patch)
    {
        $existing = $this->get_item($content_type, $id);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $meta = isset($patch['meta']) && is_array($patch['meta']) ? $patch['meta'] : [];

        $validated = $this->validate_meta($content_type, $meta, false);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $title  = array_key_exists('title', $patch) ? (string) $patch['title'] : (string) $existing['title'];
        $status = array_key_exists('status', $patch) && $patch['status'] !== ''
            ? $this->resolve_status($content_type, $patch['status'])
            : (string) $existing['status'];

        if (is_wp_error($status)) {
            return $status;
        }

        $saved = $this->save($content_type, (int) $id, $title, $status, $meta);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return $this->get_item($content_type, $id);
    }

    /**
     * Delete rows, or hand them to the type's `surfaces.delete` callable (since 1.11.0).
     *
     * @param string $content_type Content type id.
     * @param array  $ids          Row ids.
     *
     * @return array|\WP_Error Deletion summary { deleted, count, not_found }; `ewp_content_surface_disabled`
     *                         (403) when the type set `surfaces.delete` to false.
     *
     * @since 1.4.0
     */
    public function delete_items($content_type, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return new \WP_Error('ewp_abilities_invalid_id', __('At least one item id is required.', 'extend-wp'), ['status' => 400]);
        }

        if ($this->get_surface($content_type, 'delete') === false) {
            return $this->surface_disabled($content_type, 'delete');
        }

        $existing = awm_get_db_content($content_type, ['include' => $ids, 'fields' => ['content_id']]);
        $found    = [];

        if (!empty($existing) && is_array($existing)) {
            foreach ($existing as $row) {
                $found[] = (int) $row['content_id'];
            }
        }

        if (empty($found)) {
            return new \WP_Error(
                'ewp_abilities_not_found',
                __('None of the given ids exist.', 'extend-wp'),
                ['status' => 404]
            );
        }

        $route = $this->get_surface($content_type, 'delete');
        if (is_callable($route)) {
            $result = $this->routed_result(call_user_func($route, $found, $content_type), $found, 'deleted');
            if (is_array($result)) {
                $result['not_found'] = array_values(array_unique(array_merge($result['not_found'], array_diff($ids, $found))));
            }

            return $result;
        }

        $deleted = awm_custom_content_delete($content_type, $found);

        if (!$deleted) {
            return new \WP_Error('ewp_abilities_delete_failed', __('The items could not be deleted.', 'extend-wp'), ['status' => 500]);
        }

        return [
            'deleted'   => $found,
            'count'     => count($found),
            'not_found' => array_values(array_diff($ids, $found)),
        ];
    }

    /**
     * Duplicate a row with its meta (awm_custom_content_duplicate(), so `{type}_after_duplicate_action` fires),
     * unless the type set `surfaces.duplicate` to false. The shared implementation behind the Duplicate
     * row action of both admin lists.
     *
     * @param string $content_type Content type id.
     * @param int    $id           Row id to copy.
     *
     * @return array|\WP_Error The copy (normalized row); `ewp_content_surface_disabled` (403) when refused,
     *                         `ewp_abilities_not_found` (404) for an unknown id.
     *
     * @since 1.11.0
     */
    public function duplicate_item($content_type, $id)
    {
        if ($this->get_surface($content_type, 'duplicate') === false) {
            return $this->surface_disabled($content_type, 'duplicate');
        }

        $original = $this->get_item($content_type, $id);
        if (is_wp_error($original)) {
            return $original;
        }

        $new_id = awm_custom_content_duplicate($content_type, (int) $id);
        if (!$new_id) {
            return new \WP_Error('ewp_content_duplicate_failed', __('The item could not be duplicated.', 'extend-wp'), ['status' => 500]);
        }

        return $this->get_item($content_type, $new_id);
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Shape what a `surfaces.set_status` / `surfaces.delete` callable returned like the generic result.
     *
     * @param mixed  $result What the callable returned: an array with `{key}` (ids) and optional `not_found`,
     *                       true (every id handled), false, or a WP_Error.
     * @param int[]  $ids    Ids passed to the callable.
     * @param string $key    `updated` or `deleted`.
     *
     * @return array|\WP_Error { count, {key}: int[], not_found: int[] }.
     *
     * @since 1.11.0
     */
    private function routed_result($result, array $ids, $key)
    {
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === true) {
            $result = [$key => $ids];
        }
        if (!is_array($result)) {
            return new \WP_Error('ewp_content_routed_action_failed', __('The content type could not complete the action.', 'extend-wp'), ['status' => 500]);
        }

        $done      = isset($result[$key]) ? array_values(array_map('intval', (array) $result[$key])) : $ids;
        $not_found = isset($result['not_found']) ? array_values(array_map('intval', (array) $result['not_found'])) : [];

        return array_merge($result, [$key => $done, 'count' => count($done), 'not_found' => $not_found]);
    }

    /**
     * Persist a row through the plugin's save pipeline.
     *
     * Title and status are always sent because the main-table mapper treats
     * status as required and does not fail cleanly when it is missing.
     *
     * @param string     $content_type Content type id.
     * @param int|string $id           Row id, or 'new'.
     * @param string     $title        Item title.
     * @param string     $status       Item status.
     * @param array      $meta         Meta values.
     *
     * @return int|\WP_Error Saved row id.
     *
     * @since 1.4.0
     */
    private function save($content_type, $id, $title, $status, array $meta)
    {
        $data = [
            'content_id'      => $id,
            'title'           => $title,
            'status'          => $status,
            'awm_custom_meta' => array_keys($meta),
        ];

        $saved = awm_custom_content_save($content_type, array_merge($data, $meta));

        if (is_wp_error($saved)) {
            return $saved;
        }

        if (!$saved) {
            return new \WP_Error('ewp_abilities_save_failed', __('The item could not be saved.', 'extend-wp'), ['status' => 500]);
        }

        return (int) $saved;
    }

    /**
     * Validate a status against the content type, defaulting to the first one.
     *
     * @param string $content_type Content type id.
     * @param string $status       Requested status.
     *
     * @return string|\WP_Error
     *
     * @since 1.4.0
     */
    private function resolve_status($content_type, $status)
    {
        $statuses = $this->get_statuses($content_type);

        if (empty($statuses)) {
            return (string) $status;
        }

        if ($status === '' || $status === null) {
            return (string) reset($statuses);
        }

        if (!in_array((string) $status, $statuses, true)) {
            return new \WP_Error(
                'ewp_abilities_invalid_status',
                sprintf(
                    /* translators: 1: given status, 2: comma separated allowed statuses. */
                    __('Unknown status "%1$s". Allowed statuses: %2$s.', 'extend-wp'),
                    $status,
                    implode(', ', $statuses)
                ),
                ['status' => 400]
            );
        }

        return (string) $status;
    }

    /**
     * Shape a raw database row for ability output.
     *
     * @param string $content_type Content type id.
     * @param array  $row          Raw row.
     * @param bool   $with_meta    Whether to load the row's meta.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public function normalize_row($content_type, $row, $with_meta = false)
    {
        $row  = is_array($row) ? $row : [];
        $id   = isset($row['content_id']) ? (int) $row['content_id'] : 0;
        $meta = [];

        if ($with_meta && $id > 0) {
            $stored = awm_get_db_content_meta($content_type, $id);
            $meta   = is_array($stored) ? $stored : [];
        }

        $normalized = [
            'id'           => $id,
            'content_type' => (string) $content_type,
            'title'        => isset($row['content_title']) ? (string) $row['content_title'] : '',
            'status'       => isset($row['status']) ? (string) $row['status'] : '',
            'created'      => isset($row['created']) ? (string) $row['created'] : null,
            'modified'     => isset($row['modified']) ? (string) $row['modified'] : null,
            'user_id'      => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'hash'         => isset($row['hash']) ? (string) $row['hash'] : null,
        ];

        // Since 1.11.0: the type's own `_main` columns, under their registration key (never overriding the keys above).
        foreach ($this->custom_columns($content_type) as $key => $column) {
            if (!isset($normalized[$key]) && array_key_exists($column, $row)) {
                $normalized[$key] = $row[$column];
            }
        }

        $normalized['meta'] = (object) $meta;

        return $normalized;
    }

    /**
     * The type's own `_main` columns (registration `main_table_data.data`), beyond the default ones.
     *
     * @param string $content_type Content type id.
     *
     * @return array Registration key => stored column name (`sql_key` or the key).
     *
     * @since 1.11.0
     */
    public function custom_columns($content_type)
    {
        $config  = $this->get_config($content_type);
        $columns = [];
        foreach (isset($config['save_columns']) ? (array) $config['save_columns'] : [] as $key => $column) {
            if (in_array($key, self::RESERVED_KEYS, true) || !is_array($column) || empty($column['sql'])) {
                continue;
            }
            $columns[(string) $key] = isset($column['sql_key']) ? (string) $column['sql_key'] : (string) $key;
        }

        return $columns;
    }

    /**
     * Describe every content type the current user may access.
     *
     * The shape every surface returns for a type inventory: the abilities
     * expose it as `ewp-content/list-content-types`, the CLI as
     * `wp ewp content types`.
     *
     * @return array{count:int,types:array} Each type: content_type, label, singular,
     *                                       capability, statuses, fields, writable, public_read.
     *
     * @since 1.5.0
     */
    public function describe_types()
    {
        $types = [];

        foreach ($this->list_types() as $content_type => $config) {
            $capability = isset($config['capability']) ? $config['capability'] : 'edit_posts';

            if (!current_user_can($capability)) {
                continue;
            }

            $types[] = [
                'content_type' => (string) $content_type,
                'label'        => isset($config['list_name']) ? (string) $config['list_name'] : (string) $content_type,
                'singular'     => isset($config['list_name_singular']) ? (string) $config['list_name_singular'] : '',
                'capability'   => (string) $capability,
                'statuses'     => $this->get_statuses($content_type),
                'fields'       => $this->describe_library($content_type),
                'writable'     => !isset($config['writable']) || (bool) $config['writable'],
                'public_read'  => !empty($config['public_read']),
            ];
        }

        return ['count' => count($types), 'types' => $types];
    }

    /**
     * Whether a content type exposes its read routes to anonymous requests.
     *
     * Opt-in through `'public_read' => true` on the `awm_register_content_db`
     * definition; everything else uses the type's `capability`.
     *
     * @param string $content_type Content type id.
     *
     * @return bool
     *
     * @since 1.5.0
     */
    public function is_public_read($content_type)
    {
        $config = $this->get_config($content_type);

        return !empty($config['public_read']);
    }

    /**
     * Whether a field library entry is flagged required in the admin UI.
     *
     * A field only shown for certain values of another field cannot be
     * unconditionally required, so it is reported as optional.
     *
     * @param array $field Field definition.
     *
     * @return bool
     *
     * @since 1.5.0
     */
    public static function is_required(array $field)
    {
        if (!empty($field['show-when'])) {
            return false;
        }

        if (!empty($field['required'])) {
            return true;
        }

        if (empty($field['label_class']) || !is_array($field['label_class'])) {
            return false;
        }

        return in_array('awm-needed', $field['label_class'], true);
    }
}

/*
 * The service lived in the abilities module until 1.5.0. Keep the old name
 * resolvable for the abilities providers and any external caller.
 */
class_alias('EWP\\Content\\Content_Service', 'EWP\\Abilities\\EWP_Abilities_Content_Service');
