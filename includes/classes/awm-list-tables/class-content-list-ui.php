<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `@wordpress/dataviews` admin list for custom content types.
 *
 * A content type's list page renders either the legacy WP_List_Table
 * (AWM_List_Table) or this DataViews list, chosen per type by
 * `ewp_content_list_ui`. The DataViews list is a React module
 * (`assets/js/modules/ewp-dataviews-list.js`, bundled with DataViews 4.15,
 * the version WordPress 6.8 ships) loaded by the Dynamic Asset Loader when
 * its mount element is on the page. It reads and writes through the type's
 * REST routes (`{prefix}/{type}/query`, `/status`, `/delete`), i.e. the same
 * Content_Service as the CLI and abilities.
 *
 * Existing PHP extension points keep working: the `query` route, asked with
 * `render=true`, returns each row's legacy cells (`ewp_column_{type}_column_content_filter`)
 * and row actions (`ewp_row_actions_{type}_filter`) as HTML, which the list
 * shows; row-action links that point back to the list (delete, duplicate)
 * are still processed by AWM_List_Table::process_bulk_action().
 *
 * @package extend-wp
 * @since   1.7.0
 */
class EWP_Content_List_UI
{
    /** Script / style handle. */
    const HANDLE = 'ewp-dataviews-list';

    /** Mount element selector. */
    const SELECTOR = '.ewp-dataviews-list';

    /** Oldest WordPress whose @wordpress/components the bundled DataViews works with. */
    const MIN_WP = '6.8';

    /** Extend WP's own content types, switched to DataViews by default. */
    const DEFAULT_TYPES = array('ewp_fields', 'ewp_post_types', 'ewp_taxonomies', 'ewp_search', 'ewp_content_type');

    /** Cases whose options become DataViews filter elements. */
    const OPTION_CASES = array('select', 'radio', 'checkbox_multiple', 'awm_radio', 'awm_select');

    /** Multi-value cases (stored as serialised lists). */
    const MULTI_CASES = array('checkbox_multiple');

    /**
     * Hook the asset registration (must run before `init` priority 1, when the loader collects entries).
     *
     * @return void
     */
    public static function init()
    {
        add_filter('ewp_register_dynamic_assets', array(__CLASS__, 'register_assets'));
    }

    /**
     * Register the list module and its stylesheet with the Dynamic Asset Loader.
     *
     * @param array $assets Registered assets.
     * @return array Assets with the DataViews list entries.
     */
    public static function register_assets($assets)
    {
        $asset = self::asset_file();
        if ($asset === null) {
            return $assets;
        }
        $assets[] = array(
            'handle'       => self::HANDLE,
            'selector'     => self::SELECTOR,
            'type'         => 'script',
            'src'          => awm_url . 'build/modules/ewp-dataviews-list.js',
            'version'      => $asset['version'],
            'context'      => 'admin',
            'dependencies' => $asset['dependencies'],
            'in_footer'    => true,
        );
        $assets[] = array(
            'handle'       => self::HANDLE . '-style',
            'selector'     => self::SELECTOR,
            'type'         => 'style',
            'src'          => awm_url . 'build/modules/ewp-dataviews-list.css',
            'version'      => $asset['version'],
            'context'      => 'admin',
            'dependencies' => array('wp-components'),
        );
        return $assets;
    }

    /**
     * Which list a content type renders.
     *
     * @param string $type Content type id.
     * @return string `dataviews` or `legacy`.
     */
    public static function mode($type)
    {
        $default = in_array($type, self::DEFAULT_TYPES, true) ? 'dataviews' : 'legacy';
        /**
         * Filters which admin list a content type renders: the DataViews list or the legacy WP_List_Table.
         * The DataViews list also needs WordPress 6.8+ and a built module; otherwise the legacy list
         * renders whatever this returns. `?ewp_list_ui=legacy` on the list URL forces the legacy list.
         *
         * @since 1.7.0
         *
         * @param string $mode `dataviews` for Extend WP's own types, `legacy` for every other type.
         * @param string $type Content type id.
         */
        $mode = apply_filters('ewp_content_list_ui', $default, (string) $type);
        if ($mode !== 'dataviews' || !self::supported() || self::requested_legacy()) {
            return 'legacy';
        }
        return isset(AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]) ? 'dataviews' : 'legacy';
    }

    /**
     * Whether this site can run the DataViews list.
     *
     * @return bool True on WordPress 6.8+ with the module built.
     */
    public static function supported()
    {
        return version_compare(get_bloginfo('version'), self::MIN_WP, '>=') && self::asset_file() !== null;
    }

    /**
     * Whether the request asked for the legacy list (`?ewp_list_ui=legacy`).
     *
     * @return bool
     */
    private static function requested_legacy()
    {
        return isset($_GET['ewp_list_ui']) && sanitize_key(wp_unslash($_GET['ewp_list_ui'])) === 'legacy'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
    }

    /**
     * Built asset metadata (`build/modules/ewp-dataviews-list.asset.php`).
     *
     * @return array|null { dependencies, version }, null when not built.
     */
    private static function asset_file()
    {
        static $asset = false;
        if ($asset === false) {
            $file  = awm_path . 'build/modules/ewp-dataviews-list.asset.php';
            $asset = is_readable($file) ? (array) include $file : null;
        }
        return $asset;
    }

    /**
     * Render the list page: run legacy row actions (delete / duplicate links), enqueue the WordPress
     * packages the module expects as globals, and print the mount element.
     *
     * @param array $args List arguments (`awm_custom_lists_view_filter` entry plus `id`).
     * @return void
     */
    public static function render(array $args)
    {
        self::process_legacy_actions($args);
        foreach ((array) self::asset_file()['dependencies'] as $handle) {
            wp_enqueue_script($handle);
        }
        wp_enqueue_style('wp-components');

        $config = self::config((string) $args['id'], $args);
        include awm_path . 'templates/admin-view/content-list.php';
    }

    /**
     * Process `?action=delete|duplicate` row links (from the legacy actions or a filter) like the legacy list does.
     *
     * @param array $args List arguments.
     * @return void
     */
    private static function process_legacy_actions(array $args)
    {
        if (empty($_REQUEST['action']) || !class_exists('AWM_List_Table')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by process_bulk_action().
            return;
        }
        $table = new AWM_List_Table($args);
        $table->process_bulk_action();
    }

    /**
     * Configuration the module reads from the mount element's `data-config`.
     *
     * @param string $type Content type id.
     * @param array  $args List arguments.
     * @return array JSON-ready configuration.
     */
    public static function config($type, array $args)
    {
        $registry  = AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type];
        $fields    = self::fields($type, $args);
        $page_link = self::page_link($args);
        $statuses = array();
        foreach ((array) $registry['status'] as $value => $status) {
            $statuses[] = array('value' => (string) $value, 'label' => isset($status['label']) ? (string) $status['label'] : (string) $value);
        }

        return array(
            'type'        => $type,
            'restPath'    => '/' . $registry['rest_prefix'] . '/' . $registry['rest_base'],
            'fields'      => $fields,
            'statuses'    => $statuses,
            'writable'    => !empty($registry['writable']),
            'canDelete'   => !empty($registry['writable']) && !empty($registry['show_delete']),
            'editUrl'     => awm_edit_screen_link($args) . '&id=',
            /* the legacy duplicate row action, handled by AWM_List_Table::process_bulk_action() on this page; the nonce does not depend on the id */
            'duplicateUrl' => wp_nonce_url(admin_url($page_link . '&action=duplicate'), $page_link . '_duplicate') . '&id=',
            'newUrl'      => empty($args['disable_new']) ? awm_edit_screen_link($args) : '',
            'legacyUrl'   => add_query_arg('ewp_list_ui', 'legacy'),
            'storageKey'  => 'ewp-dataviews-' . $type,
            'defaultView' => array(
                'type'    => 'table',
                'search'  => '',
                'filters' => array(),
                'page'    => 1,
                'perPage' => 20,
                'sort'    => array('field' => 'created', 'direction' => 'desc'),
                'fields'  => array_values(array_diff(array_column($fields, 'id'), array('title', 'id'))),
                'titleField' => 'title',
                'layout'  => (object) array(),
            ),
            'labels'      => array(
                'title'    => isset($args['list_name']) ? (string) $args['list_name'] : $type,
                'singular' => isset($args['list_name_singular']) ? (string) $args['list_name_singular'] : '',
            ),
        );
    }

    /**
     * DataViews field definitions: the legacy list columns (so `ewp_column_*` cells keep showing), typed and made
     * sortable / filterable from Content_Service::view_fields().
     *
     * @param string $type Content type id.
     * @param array  $args List arguments.
     * @return array[] Field definitions: id, label, type, source, enableSorting, enableHiding, elements?, operators?, html.
     */
    public static function fields($type, array $args)
    {
        $service = new \EWP\Content\Content_Service();
        $view    = $service->view_fields($type);
        $library = $service->resolve_library($type);
        $fields  = array(array('id' => 'title', 'label' => __('Title', 'extend-wp'), 'type' => 'text', 'source' => 'column', 'enableSorting' => true, 'enableHiding' => false, 'html' => false));

        foreach (self::columns($args) as $key => $label) {
            $id = $key === 'ewp_date' ? 'created' : (string) $key;
            if ($id === 'title' || $id === 'cb') {
                continue;
            }
            $fields[] = self::field($id, $key, (string) $label, isset($view[$id]) ? $view[$id] : null, isset($library[$id]) ? $library[$id] : array());
        }
        if (!in_array('status', array_column($fields, 'id'), true)) {
            $fields[] = self::field('status', 'status', __('Status', 'extend-wp'), $view['status'], array());
        }
        foreach ($fields as &$field) {
            if ($field['id'] === 'status') {
                $field['elements']  = self::elements((array) AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]['status']);
                $field['operators'] = array('isAny', 'isNone');
            }
        }
        return $fields;
    }

    /**
     * One DataViews field.
     *
     * @param string     $id      Field id (a view field id when $view is set).
     * @param string     $column  Legacy column key (for the rendered cell).
     * @param string     $label   Column label.
     * @param array|null $view    View field from Content_Service::view_fields(), null for display-only columns.
     * @param array      $library Library definition when the column is a meta field.
     * @return array Field definition.
     */
    private static function field($id, $column, $label, $view, array $library)
    {
        $type  = $view !== null && in_array($view['type'], array('integer', 'datetime'), true) ? $view['type'] : 'text';
        $field = array(
            'id'            => $id,
            'column'        => $column,
            'label'         => wp_strip_all_tags($label),
            'type'          => $type,
            'source'        => $view !== null ? $view['source'] : 'render',
            'enableSorting' => $view !== null && !empty($view['sortable']),
            'enableHiding'  => true,
            'html'          => true,
        );
        $case = isset($library['case']) ? (string) $library['case'] : '';
        if ($view !== null && in_array($case, self::OPTION_CASES, true) && !empty($library['options'])) {
            $field['elements']  = self::elements((array) $library['options']);
            $field['operators'] = in_array($case, self::MULTI_CASES, true) ? array('isAll', 'isNotAll') : array('isAny', 'isNone');
        }
        return $field;
    }

    /**
     * DataViews elements from an options / statuses array.
     *
     * @param array $options Value => { label } or value => label.
     * @return array[] { value, label }.
     */
    private static function elements(array $options)
    {
        $elements = array();
        foreach ($options as $value => $option) {
            $label      = is_array($option) ? (isset($option['label']) ? $option['label'] : $value) : $option;
            $elements[] = array('value' => (string) $value, 'label' => wp_strip_all_tags((string) $label));
        }
        return $elements;
    }

    /**
     * Legacy list columns: the type's `columns` plus library fields flagged `admin_list` (as AWM_List_Table::get_all_columns()).
     *
     * @param array $args List arguments.
     * @return array Column key => label.
     */
    public static function columns(array $args)
    {
        $columns = array();
        foreach (isset($args['columns']) ? (array) $args['columns'] : array() as $key => $data) {
            $columns[$key] = isset($data['label']) ? $data['label'] : $key;
        }
        foreach (isset($args['metaboxes']) ? (array) $args['metaboxes'] : array() as $box_key => $box) {
            $library = awm_callback_library(awm_callback_library_options($box), $box_key);
            foreach ((array) $library as $key => $data) {
                if (isset($data['admin_list'])) {
                    $columns[$key] = isset($data['label']) ? $data['label'] : $key;
                }
            }
        }
        return $columns;
    }

    /**
     * Attach each row's legacy cells and row actions (HTML) to a query result, for the `render` flag of the
     * `{prefix}/{type}/query` route.
     *
     * @param array  $result Content_Service::query_items() result.
     * @param string $type   Content type id.
     * @return array Result whose items carry `rendered` { cells: column => HTML, actions: key => HTML }.
     */
    public static function render_rows(array $result, $type)
    {
        if (empty($result['items']) || !isset(AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type])) {
            return $result;
        }
        $args    = array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type], array('id' => $type));
        $columns = array_diff(array_keys(self::columns($args)), array('title', 'ewp_date', 'cb'));
        $raw     = array();
        foreach ((array) awm_get_db_content($type, array('include' => array_column($result['items'], 'id'))) as $row) {
            $raw[(int) $row['content_id']] = $row;
        }

        foreach ($result['items'] as &$item) {
            $row   = isset($raw[$item['id']]) ? $raw[$item['id']] : array();
            $cells = array();
            foreach ($columns as $column) {
                /** This filter is documented in includes/classes/awm-list-tables/class-list-table.php */
                $cells[$column] = wp_kses_post((string) apply_filters('ewp_column_' . $type . '_column_content_filter', '', $row, $column));
            }
            $item['rendered'] = array('cells' => (object) $cells, 'actions' => (object) self::row_actions($args, $row));
        }
        return $result;
    }

    /**
     * Admin path of the list page, as AWM_List_Table builds it (`parent&page=id` for a `?`-parent, else `admin.php?page=id`).
     *
     * @param array $args List arguments.
     * @return string Path relative to wp-admin.
     */
    private static function page_link(array $args)
    {
        return !empty($args['parent']) && strpos($args['parent'], '?') !== false ? $args['parent'] . '&page=' . $args['id'] : 'admin.php?page=' . $args['id'];
    }

    /**
     * Row actions as the legacy list builds them (Edit, Duplicate, Delete) after `ewp_row_actions_{type}_filter`.
     *
     * @param array $args List arguments.
     * @param array $row  Raw `_main` row.
     * @return array Action key => link HTML.
     */
    private static function row_actions(array $args, array $row)
    {
        if (empty($row['content_id'])) {
            return array();
        }
        $id        = (int) $row['content_id'];
        $page_link = self::page_link($args);
        $actions   = array(
            'edit'      => sprintf('<a href="%s">%s</a>', esc_url(awm_edit_screen_link($args) . '&id=' . $id), __('Edit', 'extend-wp')),
            'duplicate' => sprintf('<a href="%s">%s</a>', esc_url(wp_nonce_url(admin_url($page_link . '&id=' . $id . '&action=duplicate'), $page_link . '_duplicate')), __('Duplicate', 'extend-wp')),
        );
        if (!isset($args['show_delete']) || $args['show_delete']) {
            $actions['delete'] = sprintf('<a href="%s" class="awm-delete-content-id">%s</a>', esc_url(wp_nonce_url(admin_url($page_link . '&id=' . $id . '&action=delete'), $page_link . '_delete')), __('Delete', 'extend-wp'));
        }
        /** This filter is documented in includes/classes/awm-list-tables/class-list-table.php */
        $actions = (array) apply_filters('ewp_row_actions_' . $args['id'] . '_filter', $actions, $row);
        return array_map('wp_kses_post', array_map('strval', $actions));
    }
}

EWP_Content_List_UI::init();
