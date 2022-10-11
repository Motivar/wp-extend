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
 * @author      Motivar
 *
 * @version     0.0.1a * 
 * 
 * The class that dynamically creates and registers custom table lists on the left bar menu
 */
class AWM_List_Table extends WP_List_Table
{

    /**
     * @var string $list_name - The name of the table list. Used for the menu page name and the slug url as well
     */
    private $list_name;
    private $page_id;
    private $page_link;
    /**
     * @var array $columns - The columns of the custom table list. They can be less or the same amount as the columns of the respective SQL table
     */
    private $columns;

    /**
     * @var array $sortable_columns - The columns that the custom table list can be ordered by. Cann be one, some or all columns
     */
    private $sortable_columns;
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
    private $delete_callback;
    private $delete_callback_args;

    /**
     * @var boolean $is_data_encrypted - Boolean value that determines whether the stored data is encrypted. Used to decrypt data before setting it to items or niot
     */
    private $is_data_encrypted;

    private $title_key;

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
        if (strpos($args['parent'], '?') === false) {
            $args['parent'] = false;
        }

        $this->page_link = $args['parent'] ? $args['parent'] . '&page=' . $args['id'] : 'admin.php?page=' . $args['id'];
        $this->list_name = $args['list_name'];
        $this->columns  = $this->get_all_columns($args['columns'], $args);
        $this->sortable_columns = $args['sortable'];
        $this->list_filters = $this->get_list_filters($args);
        $this->table_name = $args['table_name'];
        $this->title_key = isset($args['title_key']) ? $args['title_key'] : 'title';
        $this->results_per_page = isset($args['results_per_page']) ? $args['results_per_page'] : 50;
        $this->icon_url = $args['icon_url'];
        $this->is_data_encrypted = $args['is_data_encrypted'];
        $this->db_search_key = isset($args['db_search_key']) ? $args['db_search_key'] : 'id';
        $this->delete_callback = isset($args['delete_callback']) ? $args['delete_callback'] : false;
        $this->delete_callback_args = isset($args['delete_callback_args']) ? $args['delete_callback_args'] : array();
        parent::__construct(array(
            'singular' => $args['list_name_singular'],
            'plural'   => $args['list_name'],
            'ajax' => true,
        ));
    }


    public function extra_tablenav($which)
    {
        if (!empty($this->list_filters) && $which == 'top') { ?>
            <div class="alignleft actions">
                <div style="display: inline-block;">
                    <?php echo awm_show_content($this->list_filters, 0, 'restrict_manage_posts'); ?>
                </div>
                <input type="hidden" name="awm_restict_custom_list" id="awm_restict_custom_list" value="<?php echo $this->page_id; ?>" class="">
                <?php submit_button(__('Filter', 'awm'), 'secondary', 'action', false); ?>
            </div>
        <?php
        }
    }

    protected function display_tablenav($which)
    {
        if ('top' === $which) {
            wp_nonce_field('bulk-' . $this->_args['plural']);
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


    public function get_all_columns($columns, $args)
    {
        if (isset($args['metaboxes']) && !empty($args['metaboxes'])) {

            foreach ($args['metaboxes'] as $metaBoxKey => $metaBoxData) {
                $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
                if (!empty($metaBoxData['library'])) {
                    foreach ($metaBoxData['library'] as $key => $data) {
                        if (isset($data['admin_list'])) {
                            $columns[$key] = $data['label'];
                        }
                    }
                }
            }
        }
        return $columns;
    }


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

        $output = apply_filters('awm_column_' . $this->page_id . '_column_content_filter', '', $item, $column_name);
        return $output;
    }
    /**
     * [OPTIONAL] this is example, how to render column with actions,
     * when you hover row "Edit | Delete" links showed
     *
     * @param $item - row (key, value array)
     * @return HTML
     */


    function column_id($item)
    {
        // links going to /admin.php?page=[your_plugin_page][&other_params]
        // notice how we used $_REQUEST['page'], so action will be done on curren page
        // also notice how we use $this->_args['singular'] so in this example it will
        // be something like &person=2
        $actions = apply_filters(
            'column_id_actions_filter',
            array(
                'edit' => sprintf('<a href="' . $this->page_link . '_form&id=%s">%s</a>', $item[$this->db_search_key], __('Edit', '-meta')),
                'delete' => sprintf('<span class="cstm_tbl_delete" id="delete-' . $item[$this->db_search_key] . '-' . $this->id . '" style="color: red;">%s</span>', __('Delete', 'extend-wp')), $item
            )
        );
        return sprintf(
            '%s %s',
            $item[$this->db_search_key],
            $this->row_actions($actions)
        );
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

        $actions = array(
            'edit' => sprintf('<a href="' . $this->page_link . '_form&id=%d">%s</a>', $item[$this->db_search_key], __('Edit', 'extend-wp')),
            'delete' => sprintf('<a href="%s" class="cstm_tbl_delete" id="delete-' . $item[$this->db_search_key] . '-' . $this->list_name . '" style="color: red;">%s</a>', wp_nonce_url(admin_url($this->page_link . '&id=' . $item[$this->db_search_key] . '&action=delete'), $this->page_link . '_delete'), __('Delete', 'extend-wp'))
        );
        return $title . $this->row_actions($actions);
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
    function get_columns()
    {
        $columns = array('cb' => '<input type="checkbox" />') + $this->columns;
        return $columns;
    }

    /**
     * [OPTIONAL] This method return columns that may be used to sort table
     * all strings in array - is column names
     * notice that true on name column means that its default sort
     *
     * @return array
     */

    /** @dynamic the arra of the columns that will be sortable */
    function get_sortable_columns()
    {
        return $this->sortable_columns;
    }

    /**
     * [OPTIONAL] Return array of bult actions if has any
     *
     * @return array
     */
    function get_bulk_actions()
    {
        $actions = array(
            'delete' => __('Delete', 'extend-wp'),
        );
        return $actions;
    }

    /**
     * [OPTIONAL] This method processes bulk actions
     * it can be outside of class
     * it can not use wp_redirect coz there is output already
     * in this example we are processing delete action
     * message about successful deletion will be shown on page in next part
     */
    function process_bulk_action()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table_name; // do not forget about tables prefix

        if ('delete' === $this->current_action()) {
            $ids = isset($_REQUEST['id']) ? $_REQUEST['id'] : array();
            if (is_array($ids)) $ids = implode(',', $ids);

            if ($this->delete_callback && function_exists($this->delete_callback)) {
                call_user_func_array($this->delete_callback, array($ids, $this->delete_callback_args));
                $ids = array();
            }


            if (!empty($ids)) {
                $wpdb->query("DELETE FROM $table_name WHERE $this->db_search_key IN($ids)");
            }
        }
    }

    /**
     * [REQUIRED] This is the most important method
     *
     * It will get rows from database and prepare them to be showed in table
     */

    function prepare_items($isDetailedPage = false)
    {
        global $wpdb;
        $table_name = $this->table_name;

        $per_page = $this->results_per_page; // constant, how much records will be shown per page
        $columns = $this->get_columns();
        $hidden = array();


        $sortable = $this->get_sortable_columns();

        // here we configure table headers, defined in our methods
        $this->_column_headers = array($columns, $hidden, $sortable);

        // [OPTIONAL] process bulk action if any
        $this->process_bulk_action();

        // will be used in pagination settings
        $total_items = $wpdb->get_var("SELECT COUNT($this->db_search_key) FROM $wpdb->prefix$table_name");

        // prepare query params, as usual current page, order by and order direction
        $paged = isset($_REQUEST['paged']) ? ($per_page * max(0, intval($_REQUEST['paged']) - 1)) : 0;


        $order_by = (isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns()))) ? $_REQUEST['orderby'] : $this->db_search_key;


        $order = (isset($_REQUEST['order']) && in_array($_REQUEST['order'], array('asc', 'desc'))) ? $_REQUEST['order'] : 'asc';

        $this->items = AWM_DB_Creator::get_filox_db_data($this->table_name, '*', '', array('column' => $order_by, 'type' => $order), $per_page, $paged);

        if ($this->is_data_encrypted) {
            $this->items = $this->decrypt_data($this->items);
        }


        // [REQUIRED] configure pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items, // total items defined above
            'per_page' => $per_page, // per page constant defined at top of method
            'total_pages' => ceil($total_items / $per_page) // calculate pages count
        ));
    }

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
