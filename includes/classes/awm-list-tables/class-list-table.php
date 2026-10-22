<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/screen.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}


require_once 'functions.php';
require_once 'class-list-form.php';

/**
 *
 * @author      Filox
 *
 * @version     0.0.1a * 
 * 
 * The class that dynamically creates and registers custom table lists on the left bar menu
 */
class AWM_List_Table extends WP_List_Table
{
    // Declare the property explicitly here
    protected $date_modified_key;

    public static $content_totals = array();
    public static $columns = array();
    /**
     * @var string $list_name - The name of the table list. Used for the menu page name and the slug url as well
     */
    private $list_name;
    private $page_id;
    private $page_link;
    /**
     * @var array $sortable_columns - The columns that the custom table list can be ordered by. Cann be one, some or all columns
     */
    private $list_filters;

    /**
     * @var string $table_name - The name of the SQL table to get the data from. Do NOT include table prefix
     */
    private $table_name;

    /**
     * @var int $results_per_page - The number of the results that will be shown per page
     */
    private $results_per_page;

    /**
     * @var string $icon_url - The url of the icon to display on the menu
     */
    private $icon_url;

    /**
     * @var string $icon_url - The url of the icon to display on the menu
     */
    private $db_search_key;

    /**
     * @var boolean $is_data_encrypted - Boolean value that determines whether the stored data is encrypted. Used to decrypt data before setting it to items or niot
     */
    private $is_data_encrypted;

    private $title_key;
    private $date_key;
    private $show_delete;

    /**
     * [REQUIRED] You must declare a constructor and give some basic params
     */

    /**
     * @dynamic list name (in singular. we will handle plural)
     */
    public function __construct($args)
    {
        // assign variables
        $this->page_id = $args['id'];
        if (!empty($args['parent']) && strpos($args['parent'], '?') === false) {
            $args['parent'] = false;
        }
        $this->page_link = $args['parent'] ? $args['parent'] . '&page=' . $args['id'] : 'admin.php?page=' . $args['id'];
        $this->list_name = $args['list_name'];
        $this->list_filters = $this->get_list_filters($args);
        $this->table_name = $args['table_name'];
        $this->title_key = isset($args['title_key']) ? $args['title_key'] : 'title';
        $this->date_key =  'created';
        $this->date_modified_key =  'modified';
        $this->results_per_page = isset($args['results_per_page']) ? $args['results_per_page'] : 50;
        $this->icon_url = $args['icon_url'];
        $this->is_data_encrypted = $args['is_data_encrypted'];
        $this->db_search_key = isset($args['db_search_key']) ? $args['db_search_key'] : 'id';
        $this->show_delete = isset($args['show_delete']) ? $args['show_delete'] : true;
        parent::__construct(array(
            'singular' => $args['list_name_singular'],
            'plural'   => $args['list_name'],
            'ajax' => true,
            'ewp_custom_args' => $args
        ));
    }


    /**
     * Render the restrict filters above the list (top navigation only).
     *
     * @param string $which `top` or `bottom`.
     * @return void
     */
    public function extra_tablenav($which)
    {
        if (!empty($this->list_filters) && $which == 'top') { ?>
<div class="alignleft actions">
 <div style="display: inline-block;">
  <?php echo awm_show_content($this->list_filters, 0, 'restrict_manage_posts'); ?>
 </div>
 <input type="hidden" name="awm_restict_custom_list" id="awm_restict_custom_list" value="<?php echo $this->page_id; ?>"
  class="">
 <?php submit_button(__('Filter', 'extend-wp'), 'secondary', 'action', false); ?>
</div>
<?php
        }
    }

    /**
     * Render the table navigation (bulk actions, filters, pagination).
     *
     * @param string $which `top` or `bottom`.
     * @return void
     */
    protected function display_tablenav($which)
    {

        $items = self::$content_totals;
        if ($items['items'] === 0) {
            return;
        }
        if ('top' === $which) {
        ?><div class="ewp-top-actions">
 <?php
                $this->views();
                $this->search_box(sprintf(__('Search %s', 'extend-wp'), $this->_args['plural']), $this->_args['ewp_custom_args']['id'] . '-search-box');
                wp_nonce_field('bulk-' . $this->_args['plural']);
                ?>
</div>
<?php
        } ?>
<div class="tablenav <?php echo esc_attr($which); ?>">

 <?php if ($this->has_items()) : ?>
 <div class="alignleft actions bulkactions">
  <?php $this->bulk_actions($which); ?>
 </div>
 <?php
            endif;

            $this->extra_tablenav($which);
            $this->pagination($which);

            ?>

 <br class="clear" />
</div>
<?php
    }

    /**
     * Render the search box (the `s` parameter; sort parameters are not carried over).
     *
     * @param string $text     Button label.
     * @param string $input_id Input id prefix.
     * @return void
     */
    public function search_box($text, $input_id)
    {
        /*

        if (!empty($_REQUEST['orderby'])) {
            echo '<input type="hidden" name="orderby" value="' . esc_attr($_REQUEST['orderby']) . '" />';
        }
        if (!empty($_REQUEST['order'])) {
            echo '<input type="hidden" name="order" value="' . esc_attr($_REQUEST['order']) . '" />';
        }
        if (!empty($_REQUEST['post_mime_type'])) {
            echo '<input type="hidden" name="post_mime_type" value="' . esc_attr($_REQUEST['post_mime_type']) . '" />';
        }
        if (!empty($_REQUEST['detached'])) {
            echo '<input type="hidden" name="detached" value="' . esc_attr($_REQUEST['detached']) . '" />';
        }*/

    ?>
<div class="ewp-search-box-admin">
 <div class="search-box">
  <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo $text; ?></label>
  <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" value="<?php _admin_search_query(); ?>" />
  <?php submit_button($text, '', '', false, array('id' => 'search-submit')); ?>
 </div>
</div>
<?php
    }



    /**
     * Render the status links above the list.
     *
     * @return string|void Empty string when the type has no statuses; otherwise echoes the links.
     */
    public function views()
    {
        if (empty($this->_args['ewp_custom_args']['status'])) {
            return '';
        }
        $labels = array();
        $items = $this->get_total_items();

        $labels[] = '<li "all"><a href="' . $this->page_link . '" class="' . (!isset($_REQUEST['ewp_status']) ? 'current' : '') . '">' . __('All', 'extend-wp') . ' (' . $items['items'] . ')</a></li>';
        foreach ($this->_args['ewp_custom_args']['status'] as $key => $label) {
            if (isset($items[$key])) {
                $labels[$key] = '<li class="' . $key . '"><a href="' . $this->page_link . '&ewp_status=' . $key . '" class="' . ((isset($_REQUEST['ewp_status']) && $_REQUEST['ewp_status'] == $key) ? 'current' : '') . '">' . $label['label'] . ' (' . $items[$key] . ')</a></li>';
            }
        }
        echo '<div id="ewp-statuses"><ul class="subsubsub" id="extend-wp-custom-views">' . implode(' | ', $labels) . '</ul></div>';
    }


    /**
     * Collect the list columns from the type's `columns` and metabox fields flagged for the list.
     *
     * @param array $args Content type registration.
     * @return array Column key => configuration.
     */
    public function get_all_columns($args)
    {
        $column_data = array('columns' => array('cb' =>  '<input type="checkbox" />'), 'sortable' => array());
        if (isset($args['columns'])) {
            foreach ($args['columns'] as $key => $data) {
                $column_data['columns'][$key] = isset($data['label']) ? $data['label'] : $key;
                if (isset($data['sortable'])) {
                    $column_data['sortable'][$key] = array($data['sortable'], true);
                }
            }
        }
        if (isset($args['metaboxes']) && !empty($args['metaboxes'])) {

            foreach ($args['metaboxes'] as $metaBoxKey => $metaBoxData) {
                $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                if (!empty($metaBoxData['library'])) {
                    foreach ($metaBoxData['library'] as $key => $data) {
                        if (isset($data['admin_list'])) {
                            $column_data['columns'][$key] = $data['label'];
                            if (isset($data['sortable'])) {
                                $column_data['sortable'][$key] = array($data['sortable'], true);
                            }
                        }
                    }
                }
            }
        }
        return $column_data;
    }


    /**
     * Collect the restrict filters from metabox fields flagged as list filters.
     *
     * @param array $args Content type registration.
     * @return array Field library of the filters.
     */
    public function get_list_filters($args)
    {
        $filters = array();
        if (isset($args['metaboxes']) && !empty($args['metaboxes'])) {
            foreach ($args['metaboxes'] as $metaBoxKey => $metaBoxData) {
                $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                if (!empty($metaBoxData['library'])) {
                    foreach ($metaBoxData['library'] as $key => $data) {
                        if (isset($data['restrict_content'])) {
                            $filters[$key] = $data;
                            $filters[$key]['exclude_meta'] = true;
                            $filters[$key]['removeEmpty'] = false;
                        }
                    }
                }
            }
        }
        return $filters;
    }


    /** LIST FUNCTIONS */


    /**
     * [REQUIRED] this is a default column renderer
     *
     * @param $item - row (key, value array)
     * @param $column_name - string (key)
     * @return HTML
     */

    function column_default($item, $column_name)
    {
        /**
         * Filters the cell content of a list column without its own renderer.
         * The dynamic part is the list page id (filox: `flx_booking`).
         *
         * @since 1.0.0
         *
         * @param string $content     Cell HTML (default empty).
         * @param array  $item        Row.
         * @param string $column_name Column key.
         */
        return apply_filters('ewp_column_' . $this->page_id . '_column_content_filter', '', $item, $column_name);
    }
    /**
     * [OPTIONAL] this is example, how to render column with actions,
     * when you hover row "Edit | Delete" links showed
     * 
     * Used for transactions
     *
     * @param $item - row (key, value array)
     * @return HTML
     */
    function column_title($item)
    {
        $title = '<strong>' . $item[$this->title_key] . '</strong>';
        $actions = $this->get_row_actions($item);
        return $title . $this->row_actions($actions);
    }


    /**
     * Get the row actions for an item
     * Uses centralized nonce handling for consistency
     * 
     * @param array $item The item data
     * @return array The row actions
     */
    public function get_row_actions($item)
    {
        $item_id = $item[$this->db_search_key];

        $actions = array(
            'edit' => sprintf('<a href="' . $this->page_link . '_form&id=%d">%s</a>', $item_id, __('Edit', 'extend-wp')),
            'duplicate' => sprintf(
                '<a href="%s" class="cstm_tbl_duplicate" id="duplicate-%s-%s">%s</a>',
                $this->get_nonce_url('duplicate', $item_id, 'row'),
                esc_attr($item_id),
                esc_attr($this->list_name),
                __('Duplicate', 'extend-wp')
            )
        );
        if ($this->show_delete) {
            $actions['delete'] = sprintf(
                '<a href="%s" class="cstm_tbl_delete awm-delete-content-id" id="delete-%s-%s">%s</a>',
                $this->get_nonce_url('delete', $item_id, 'row'),
                esc_attr($item_id),
                esc_attr($this->list_name),
                __('Delete', 'extend-wp')
            );
        }

        /**
         * Filters the row actions (Edit, Duplicate, Delete) of a list row.
         * The dynamic part is the list page id (filox: `flx_booking`).
         *
         * @since 1.0.0
         *
         * @param array $actions Action key => link HTML.
         * @param array $item    Row.
         */
        return apply_filters('ewp_row_actions_' . $this->page_id . '_filter', $actions, $item);
    }

    /**
     * Value of the date column.
     *
     * @param array $item Row.
     * @return string Date value of the row.
     */
    function column_ewp_date($item)
    {
        return $item[$this->date_key];
    }

    /**
     * [REQUIRED] this is how checkbox column renders
     *
     * @param $item - row (key, value array)
     * @return HTML
     */
    function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="id[]" value="%s" />',
            $item[$this->db_search_key]
        );
    }

    /**
     * [REQUIRED] This method return columns to display in table
     * you can skip columns that you do not want to show
     * like content, or description
     *
     * @return array
     */

    /** @dynamic the array of the columns that will get rendered */
    function get_columns($view = 'columns')
    {
        if (!empty(self::$columns)) {
            return self::$columns[$view];
        }

        $columns =  $this->get_all_columns($this->_args['ewp_custom_args']);
        self::$columns = $columns;
        return $columns[$view];
    }

    /**
     * [OPTIONAL] This method return columns that may be used to sort table
     * all strings in array - is column names
     * notice that true on name column means that its default sort
     *
     * @return array
     */



    /**
     * [OPTIONAL] Return array of bult actions if has any
     *
     * @return array
     */
    function get_bulk_actions()
    {
        $actions = array();
        if ($this->show_delete) {
            $actions['delete'] = __('Delete', 'extend-wp');
        }
        return $actions;
    }

    /**
     * Get the nonce action name for a specific action
     * This centralizes nonce handling for consistency across the application
     * 
     * @param string $action The action name (e.g., 'delete', 'duplicate')
     * @param string $type The type of nonce ('bulk' or 'row')
     * @return string The nonce action name
     */
    protected function get_nonce_action($action = '', $type = 'bulk')
    {
        if ($type === 'bulk') {
            // For bulk actions, use the standard WP format
            return 'bulk-' . $this->_args['plural'];
        } else {
            // For row actions, use our custom format
            return $this->page_link . '_' . $action;
        }
    }

    /**
     * Generate a URL with nonce for an action
     * 
     * @param string $action The action name (e.g., 'delete', 'duplicate')
     * @param int|string $item_id The ID of the item for the action
     * @param string $type The type of nonce ('bulk' or 'row')
     * @return string The URL with nonce
     */
    protected function get_nonce_url($action, $item_id, $type = 'row')
    {
        $nonce_action = $this->get_nonce_action($action, $type);
        return wp_nonce_url(admin_url($this->page_link . '&id=' . $item_id . '&action=' . $action), $nonce_action);
    }

    /**
     * Verify a nonce for an action
     * 
     * @param string $action The action name
     * @param string $type The type of nonce ('bulk' or 'row')
     * @return bool True if nonce is valid, false otherwise
     */
    protected function verify_nonce($action = '', $type = 'bulk')
    {
        $nonce_action = $this->get_nonce_action($action, $type);
        return check_admin_referer($nonce_action);
    }

    /**
     * Process bulk actions and row actions
     * Handles both bulk actions from the dropdown and row actions from item links
     * Uses centralized nonce verification for consistency
     * 
     * @return string Empty string if no action or action processing completed
     */
    function process_bulk_action()
    {
        // If no action is set or action is empty, return early (handles search requests)
        if (!isset($_REQUEST['action']) || empty($_REQUEST['action'])) {
            return '';
        }

        // Sanitize the action
        $action = sanitize_text_field($_REQUEST['action']);

        // Define valid actions
        $valid_actions = array('delete', 'duplicate');

        // Only process if it's a valid action
        if (!in_array($action, $valid_actions)) {
            return '';
        }

        // Determine if this is a row action or bulk action
        // Row actions have a single ID, bulk actions may have multiple IDs
        $is_row_action = isset($_REQUEST['id']) && !is_array($_REQUEST['id']);
        $nonce_type = $is_row_action ? 'row' : 'bulk';

        // Perform actions based on the sanitized action
        switch ($action) {
            case 'delete':
                // Verify nonce for delete action using our centralized method with correct type
                if (!$this->verify_nonce('delete', $nonce_type)) {
                    wp_die(__('Invalid delete request.', 'extend-wp'));
                }

                // Ensure IDs are provided and sanitize
                if (isset($_REQUEST['id'])) {
                    // Handle both array and string formats for IDs
                    $ids = [];

                    if (is_array($_REQUEST['id'])) {
                        // For bulk actions, IDs come as an array
                        foreach ($_REQUEST['id'] as $id) {
                            $ids[] = intval($id);
                        }
                    } else {
                        // For row actions, ID comes as a string
                        $ids[] = intval($_REQUEST['id']);
                    }

                    // Only proceed if we have valid IDs
                    if (!empty($ids)) {
                        $result = awm_custom_content_delete($this->_args['ewp_custom_args']['id'], $ids);

                        // Display success or failure message
                        if ($result) {
                            add_action('admin_notices', function () use ($ids) {
                                $count = count($ids);
                                echo '<div class="notice notice-success is-dismissible"><p>' .
                                    sprintf(__('Items deleted: %d', 'extend-wp'), $count) .
                                    '</p></div>';
                            });
                        } else {
                            add_action('admin_notices', function () {
                                echo '<div class="notice notice-error is-dismissible"><p>' .
                                    __('Failed to delete items.', 'extend-wp') .
                                    '</p></div>';
                            });
                        }
                    } else {
                        wp_die(__('Invalid item IDs for deletion.', 'extend-wp'));
                    }
                } else {
                    wp_die(__('No items selected for deletion.', 'extend-wp'));
                }
                break;

            case 'duplicate':
                // Verify nonce for duplicate action using our centralized method with correct type
                if (!$this->verify_nonce('duplicate', $nonce_type)) {
                    wp_die(__('Invalid duplicate request.', 'extend-wp'));
                }

                // Ensure ID is provided and sanitize
                if (isset($_REQUEST['id'])) {
                    // Handle both array and string formats for IDs
                    // Note: Duplicate typically works with a single ID, but we'll handle array case too
                    $id = 0;

                    if (is_array($_REQUEST['id'])) {
                        // For bulk actions, take the first ID if multiple are selected
                        if (!empty($_REQUEST['id'])) {
                            $id = intval($_REQUEST['id'][0]);
                        }
                    } else {
                        // For row actions, ID comes as a string
                        $id = intval($_REQUEST['id']);
                    }

                    // Only proceed if we have a valid ID
                    if ($id > 0) {
                        $new_id = awm_custom_content_duplicate($this->_args['ewp_custom_args']['id'], $id);

                        // Display success or failure message
                        if ($new_id) {
                            add_action('admin_notices', function () use ($new_id) {
                                echo '<div class="notice notice-success is-dismissible"><p>' .
                                    sprintf(__('Item duplicated successfully. New ID: %d', 'extend-wp'), $new_id) .
                                    '</p></div>';
                            });
                        } else {
                            add_action('admin_notices', function () {
                                echo '<div class="notice notice-error is-dismissible"><p>' .
                                    __('Failed to duplicate item.', 'extend-wp') .
                                    '</p></div>';
                            });
                        }
                    } else {
                        wp_die(__('Invalid item ID for duplication.', 'extend-wp'));
                    }
                } else {
                    wp_die(__('No item selected for duplication.', 'extend-wp'));
                }
                break;
        }
    }

    /**
     * Row counts per status (cached per request), for pagination and status links.
     *
     * @return array `items` => total, plus status => count.
     */
    public function get_total_items()
    {
        if (!empty(self::$content_totals)) {
            return self::$content_totals;
        }
        global $wpdb;
        $totals = array('items' => 0);
        $table_name = $this->table_name;
        // will be used in pagination settings
        $total_items = $wpdb->get_results("SELECT COUNT($this->db_search_key),status FROM $wpdb->prefix$table_name GROUP BY status");
        if (!empty($total_items)) {
            foreach ($total_items as $item) {
                $totals[$item->status] = $item->{"COUNT($this->db_search_key)"};
                $totals['items'] += $totals[$item->status];
            }
        }
        self::$content_totals = $totals;
        return $totals;
    }

    /**
     * [REQUIRED] This is the most important method
     *
     * It will get rows from database and prepare them to be showed in table
     */

    public function prepare_items($isDetailedPage = false)
    {

        // [OPTIONAL] process bulk action if any
        $this->process_bulk_action();
        $where = $this->get_list_where_clause();
        $totals = $this->get_total_items();
        /* the pager follows the filtered list, not the whole table */
        $total_items = empty($where) ? $totals['items'] : (int) AWM_DB_Creator::count_db_data($this->table_name, $where);
        $per_page = $this->results_per_page; // constant, how much records will be shown per page
        $columns = $this->get_columns();
        $hidden = array();


        $sortable = $this->get_columns('sortable');

        // here we configure table headers, defined in our methods
        $this->_column_headers = array($columns, $hidden, $sortable);


        // prepare query params, as usual current page, order by and order direction
        $paged = isset($_REQUEST['paged']) ? ($per_page * max(0, intval($_REQUEST['paged']) - 1)) : 0;


        $order_by = $this->requested_order_by($sortable);


        $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'desc';


        $this->items = AWM_DB_Creator::get_db_data($this->table_name, '*', $where, array('column' => $order_by, 'type' => $order), $per_page, $paged);

        if ($this->is_data_encrypted) {
            $this->items = $this->decrypt_data($this->items);
        }
        $this->prime_meta();


        // [REQUIRED] configure pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items, // total items defined above
            'per_page' => $per_page, // per page constant defined at top of method
            'total_pages' => ceil($total_items / $per_page) // calculate pages count
        ));
    }

    /**
     * ORDER BY column from `?orderby=`. WP_List_Table links carry the sortable
     * column's SQL name (`content_title`), not its key (`title`); both are
     * accepted. Anything else sorts by the search key.
     *
     * @param array $sortable Column key => array(sql column, initially descending).
     * @return string Column to sort by.
     */
    private function requested_order_by(array $sortable)
    {
        $requested = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : '';
        foreach ($sortable as $key => $definition) {
            $column = is_array($definition) ? (string) reset($definition) : (string) $definition;
            if ($requested !== '' && ($requested === $key || $requested === $column)) {
                return $column;
            }
        }
        return $this->db_search_key;
    }

    /**
     * Load the meta of every listed row of a content type with one query, so the
     * meta columns (`ewp_column_{id}_column_content_filter`) render without a query per row.
     * Does nothing for lists of other tables.
     *
     * @return void
     */
    private function prime_meta()
    {
        if (empty($this->items) || !is_array($this->items) || $this->db_search_key !== 'content_id' || !preg_match('/^(.+)_main$/', (string) $this->table_name, $m)) {
            return;
        }
        if (!\EWP\Db\Read_Cache::is_content_table($this->table_name)) {
            return;
        }
        awm_get_db_content_metas($m[1], array_column($this->items, 'content_id'));
    }

    /**
     * WHERE node from the request: status AND restrict filter AND search, where
     * search is (searchable columns LIKE … OR `content_id IN (SELECT … FROM
     * {type}_data WHERE meta_value LIKE …)`). Values are bound by EWP\Db\Where;
     * the search text is LIKE-escaped.
     *
     * @return array WHERE node, empty for none.
     */
    public function get_list_where_clause()
    {

        global $wpdb;

        $args    = $this->_args['ewp_custom_args'];
        $columns = isset($args['save_columns']) && is_array($args['save_columns']) ? $args['save_columns'] : array();
        $clauses = array();
        /* status, restrict filter and search narrow the list together (AND); before 1.7.0 they were OR'ed */
        if (isset($_REQUEST['ewp_status'])) {
            $clauses[] = array('column' => 'status', 'value' => sanitize_key(wp_unslash($_REQUEST['ewp_status'])), 'compare' => '=');
        }
        if (isset($_REQUEST['awm_restict_custom_list'])) {
            foreach ($columns as $column_key => $column_conf) {
                if (isset($column_conf['restrict_content']) && isset($_REQUEST[$column_key])) {
                    $sql_key = isset($column_conf['sql_key']) ? $column_conf['sql_key'] : $column_key;
                    $clauses[] = array('column' => $sql_key, 'value' => sanitize_text_field(wp_unslash($_REQUEST[$column_key])), 'compare' => '=');
                    break;
                }
            }
        }

        if (isset($_REQUEST['s']) && !empty($_REQUEST['s'])) {
            $search_value = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_REQUEST['s']))) . '%';
            $search = array();
            foreach ($columns as $column_key => $column_conf) {
                if (isset($column_conf['searchable'])) {
                    $search[] = array('column' => isset($column_conf['sql_key']) ? $column_conf['sql_key'] : $column_key, 'value' => $search_value, 'compare' => 'LIKE');
                }
            }
            if (isset($args['table_name_meta'])) {
                $search[] = array(
                    'column' => 'content_id',
                    'compare' => 'IN',
                    'subquery' => array(
                        'table' => $args['table_name_meta'],
                        'select' => 'content_id',
                        'where' => array('column' => 'meta_value', 'value' => $search_value, 'compare' => 'LIKE'),
                    ),
                );
            }
            $clauses[] = array('operator' => 'OR', 'clause' => $search);
        }
        $where_clause = empty($clauses) ? array() : array('clause' => array(array('operator' => 'AND', 'clause' => $clauses)));
        return $where_clause;
    }

    /**
     * Decrypt the rows of a type stored encrypted.
     *
     * @param array $encrypted_data Rows, or one row when $is_single_data.
     * @param bool  $is_single_data Whether $encrypted_data is a single row.
     * @return array Decrypted rows or row.
     */
    public static function decrypt_data($encrypted_data, $is_single_data = false)
    {
        $new_items = array();

        if ($is_single_data) {
            foreach ($encrypted_data as $index => $data) {
                $new_items[$index] = flx_transactions_decrypt($data);
            }
            return $new_items;
        }

        foreach ($encrypted_data as $index => $item) {
            $new_items[$index] = array();
            foreach ($item as $key => $data) {
                $new_items[$index][$key] = flx_transactions_decrypt($data);
            }
        }
        return $new_items;
    }
}