<?php


if (!defined('ABSPATH')) {
    exit;
}





class AWM_Add_Custom_Lists
{


    public function __construct()
    {
        add_action('admin_menu', [$this, 'set_custom_lists']);
    }

    /**
     * check if we have ustom lists to add and meet the expectations
     */
    public function set_custom_lists()
    {
        $custom_lists = $this->custom_lists();

        if (!empty($custom_lists)) {
            foreach ($custom_lists as $custom_id => $custom_list) {
                new AWM_Add_Custom_List(array('custom_id' => $custom_id, 'custom_list' => $custom_list));
            }
        }
    }
    /**
     * get all the registered options pages
     *
     * @return array
     */
    public function custom_lists()
    {
        $custom_lists = apply_filters('awm_custom_lists_view_filter', array());
        /**
         * sort settings by order
         */
        if (!empty($custom_lists)) {
            uasort($custom_lists, function ($a, $b) {
                $first = isset($a['order']) ? $a['order'] : 100;
                $second = isset($b['order']) ? $b['order'] : 100;
                return $first - $second;
            });
        }

        return $custom_lists;
    }
}
new AWM_Add_Custom_Lists();




/**
 * The actuall callback function for the add_menu_page in flx_register_custom_list_view
 * 
 * @see flx_register_custom_list_view
 * 
 * @param string $list_name - The name of the table list. Used for the menu page name and the slug url as well
 * @param array $columns - The columns of the custom table list. They can be less or the same amount as the columns of the respective SQL table
 * @param array $sortable - The columns that the custom table list can be ordered by. Cann be one, some or all columns
 * @param string $table_name - The name of the SQL table to get the data from. Do NOT include table prefix
 * @param int $results_per_page - The number of the results that will be shown per page
 * @param string $icon_url - The url of the icon to display on the menu
 * @param boolean $is_data_encrypted - Boolean value to determine whether the data to be retrieved is stored encrypted in the database
 * 
 */
function flx_table_list_page_handler($args)
{
    // Initialize the custom Table List class
    $table = new AWM_List_Table($args);


    // Handling for detailed page. Renders a slightly different view
    if (isset($_REQUEST['details']) && $_REQUEST['details'] == 'true') {
        $table->prepare_items(true);
    } else {
        $table->prepare_items(false);
    }


    $message = '';
    if ('delete' === $table->current_action()) {
        $message = '<div class="updated below-h2" id="message"><p>' . sprintf(__('Items deleted: %d', 'custom_table_example'), count($_REQUEST['id'])) . '</p></div>';
    }
?>
    <div class="wrap filox-custom-list">

        <div class="icon32 icon32-posts-post" id="icon-edit"><br></div>
        <!-- capitalize the first lettet for better UI. a tag leads to the respective form page AKA add new / edit page -->

        <h2><?php
            _e(ucwords($args['list_name']), 'awm') ?>
            <?php
            if (!$args['disable_new']) { ?>
                <a class="add-new-h2" href="<?php echo  awm_edit_screen_link($args); ?>"><?php echo sprintf(__('New %s', 'awm'), $args['list_name_singular']); ?></a>
            <?php } ?>
        </h2>

        <?php echo $message; ?>

        <form id="<?php echo $args['id']; ?>-table" method="GET">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <?php if (isset($args['parent']) && strpos($args['parent'], 'post_type')) {
                $post_type = explode('post_type=', $args['parent'])[1]; ?>
                <input type="hidden" name="post_type" value="<?php echo $post_type; ?>" />
            <?php } ?>
            <?php $table->display() ?>
        </form>

    </div>
<?php

}


function awm_edit_screen_link($args)
{
    if (strpos($args['parent'], '?') === false) {
        $args['parent'] = false;
    }

    return get_admin_url(get_current_blog_id(), (!$args['parent'] ? 'admin.php?page=' : $args['parent'] . '&page=') . $args['id'] . '_form');
}
