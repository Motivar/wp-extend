<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Class for handling custom list views in WordPress admin
 * 
 * @since 1.0.0
 */
class AWM_Add_Custom_List
{
  /**
   * Static item data storage
   * 
   * @var array
   */
  private static $item_data = array();
  
  /**
   * Meta boxes configuration
   * 
   * @var array
   */
  private $meta_boxes;
  
  /**
   * Page hook for the main page
   * 
   * @var string
   */
  private $page_hook;
  
  /**
   * Update metas configuration
   * 
   * @var array
   */
  private $update_metas;
  
  /**
   * Page ID
   * 
   * @var string
   */
  private $page_id;
  
  /**
   * Page link URL
   * 
   * @var string
   */
  private $page_link;
  
  /**
   * Custom ID
   * 
   * @var string
   */
  private $custom_id;
  
  /**
   * Custom list configuration
   * 
   * @var array
   */
  private $custom_list;
  
  /**
   * Page hook for submenu page
   * 
   * @var string
   */
  private $pagehook;

  private $show_delete;

  public function __construct($args)
  {
    $this->custom_id = $args['custom_id'];
    $this->custom_list = $args['custom_list'];
    $this->show_delete = isset($this->custom_list['show_delete']) ? $this->custom_list['show_delete'] : true;
    $this->flx_register_custom_list_view($this->custom_id, $this->custom_list);
    add_action('load-' . $this->pagehook, array($this, 'on_load_page'));
    add_action('admin_footer-' . $this->pagehook, array($this, 'on_page_footer'), 100);
    add_action('admin_init', array($this, 'save_page'), 10);
    add_action('admin_notices', [$this, 'show_message'], 100);
  }

  public function show_message()
  {
    if (isset($_REQUEST['ewp_updated'])) { ?>
<div class="notice notice-success">
 <p><?php _e('Content updated!', 'extend-wp'); ?></p>
</div>
<?php
      unset($_REQUEST['ewp_updated']);
    }
  }


  /**
   * with this function we register the custom list for the users
   * @param string $id the key for the menu 
   * @param array $menu_args the arguments for the list
   */

  public function flx_register_custom_list_view($id, $menu_args)
  {

    $defaults = array(
      'id' => $id,
      'parent' => false,
      'results_per_page' => 50,
      'list_name' => $id,
      'icon_url' => '',
      'capability' => 'activate_plugins',
      'show_new' => true,
      'disable_new' => false,
    );
    $args = array_merge($defaults, $menu_args);

    if (isset($args)) {
      $parent = $id = $args['id'];

      // if the new page is a sub list then a sub menu page is created instead of a new menu page
      if ($args['parent']) {
        add_submenu_page(
          $args['parent'],
          ucwords($args['list_name']),
          ucwords($args['list_name']),
          $args['capability'],
          $id,
          function () use ($args) {
            flx_table_list_page_handler($args);
          }
        );
        $parent = $args['parent'];
      } else {
        add_menu_page(
          ucwords($args['list_name']),
          ucwords($args['list_name']),
          $args['capability'],
          $id,
          // Declare an inline function that calls the handler function with arguments. Inline function uses parameters
          function () use ($args) {
            flx_table_list_page_handler($args);
          }
        );
      }
      // Edit page form

      /**
       * Add submenu page only when show_new is not explicitly set to false
       * Prevents null values from being passed to WordPress functions
       */
      if (!isset($args['show_new']) || $args['show_new'] !== false) {
        $menu_title = isset($args['list_name_singular']) ? sprintf(__('New %s', 'extend-wp'), $args['list_name_singular']) : __('New Item', 'extend-wp');
        $page_title = $menu_title;

        $this->pagehook = add_submenu_page(
          $parent,
          $page_title,
          $menu_title,
          $args['capability'],
          $id . '_form',
          function () use ($args) {
            $this->flx_table_list_sub_page_handler($args);
          }
        );
      }

      $this->meta_boxes = apply_filters('awm_content_db_metaboxes_filter', isset($args['metaboxes']) ? $args['metaboxes'] : array(), $id);

      $this->page_hook = $this->pagehook;
      $this->update_metas = isset($args['update_metas']) ? $args['update_metas'] : array();
      $this->page_id = $args['id'];


      // Create a method to handle parent path validation
      $pre = $this->validateParentPath($args);
      
      // Ensure we're not passing null to str_replace (used in admin_url)
      $this->page_link = !empty($pre) ? $pre . '&page=' . $args['id'] : 'admin.php?page=' . $args['id'];
    }
  }

  private function validateParentPath($args)
  {
    // Default to empty string
    $parentPath = '';

    // Check if parent is set and not empty
    if (isset($args['parent']) && !empty($args['parent'])) {
      $parentPath = (string)$args['parent'];

      // Only keep parent path if it contains 'edit.php'
      if (strpos($parentPath, 'edit.php') === false) {
        $parentPath = '';
      }
    }

    return $parentPath;
  }


  public function save_page()
  {
    /*save function*/
    if (isset($_POST['ewp_list_page_hook_nonce']) && wp_verify_nonce($_POST['ewp_list_page_hook_nonce'], $this->page_hook)) {
      $id = awm_custom_content_save($this->custom_id, $_POST);
      if (is_wp_error($id)) {
        echo $id->get_error_message();
        exit;
      }
      wp_redirect($this->page_link . '_form&id=' . $id . '&ewp_updated=1');
      exit;
    }
    if (isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], $this->page_id . '_delete') && isset($_REQUEST['id'])) {
      awm_custom_content_delete($this->custom_id, explode(',', $_REQUEST['id']));
      wp_redirect($this->page_link . '&deleted=1');
      exit;
    }
  }

  public function on_page_footer()
  {
    ?>
<script type="text/javascript">
//<![CDATA[
jQuery(document).ready(function($) {
 // close postboxes that should be closed
 $('.if-js-closed').removeClass('if-js-closed').addClass('closed');
 // postboxes setup
 postboxes.add_postbox_toggles('<?php echo $this->page_hook; ?>');
});
//]]>
</script>
<?php
  }

  public function get_current_view_data()
  {
    if (!empty(self::$item_data)) {
      return self::$item_data;
    }
    $current_id = isset($_REQUEST['id']) ? $_REQUEST['id'] : '';
    self::$item_data['item'] = self::$item_data['meta'] = array();
    if (!empty($current_id)) {
      self::$item_data['item'] = awm_get_db_content($this->custom_id, array('include' => array($current_id)))[0];
      self::$item_data['meta'] = awm_get_db_content_meta($this->custom_id, $current_id);
    }
    return self::$item_data;
  }

  public function on_load_page()
  {
    global $ewp_content_id;
    $item_data = $this->get_current_view_data();
    $ewp_content_id = isset($_REQUEST['id']) ? $_REQUEST['id'] : false;
    wp_enqueue_script('common');
    wp_enqueue_script('wp-lists');
    wp_enqueue_script('postbox');
    $metaboxes = $this->meta_boxes;
    $update_metas = $this->update_metas;
    if (!empty($metaboxes)) {

      foreach ($metaboxes as $metaBoxKey => $metaBoxData) {
        if ($metaBoxKey != 'basics') {
          $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);

          if (!empty($metaBoxData['library'])) {

            if (isset($item_data['meta'])) {

              foreach ($metaBoxData['library'] as $key => &$meta) {
                /*get the value from meta item*/
                $value = isset($item_data['meta'][$key]) ? $item_data['meta'][$key] : '';
                if (isset($meta['sql_key'])) {
                  /*check if value is stored in the main table*/
                  $value = isset($item_data['item'][$meta['sql_key']]) ? $item_data['item'][$meta['sql_key']] : '';
                }
                $meta['attributes']['value'] = $value;
              }
            }
            $metaBoxData['post'] = isset($_REQUEST['id']) ? $_REQUEST['id'] : '';;
            $metaBoxData['id'] = $metaBoxKey;
            add_meta_box(
              $metaBoxKey,
              $metaBoxData['title'], // $title
              function () use ($metaBoxData) {

                $view = isset($metaBoxData['view']) ? $metaBoxData['view'] : 'post';
                $metaBoxData['library']['awm-id'] = $metaBoxData['id'];
                $content = apply_filters('awm_add_meta_boxes_filter_content', awm_show_content($metaBoxData['library'], $metaBoxData['post'], $view), $metaBoxData['id']);
                if (!empty($content)) {
                  echo $content;
                echo '<input type="hidden" name="awm_metabox[]" value="' . $metaBoxData['id'] . '"/>';
                echo '<input type="hidden" name="awm_metabox_case" value="post"/>';
                }
              },
              $this->page_hook, // $page
              $metaBoxData['context'], // $context
              $metaBoxData['priority'] // $priority
            );
          }
        }
      }
    }
    add_meta_box(
      'flx_list_custom_submit_' . $this->page_hook,
      __('Update actions', 'extend-wp'), // $title
      function () use ($update_metas) {
        echo $this->update_box($update_metas);
      },
      array($this->page_hook), // $page
      'side', // $context
      'high'
    ); // $priority
  }

  public function update_box($data)
  {
    global $ewp_args;
    $item_data = $this->get_current_view_data();

    $select_box = '';
    if (isset($ewp_args['status']) && !empty($ewp_args['status'])) {
      $select = array(
        'status' => array(
          'case' => 'select',
          'label' => __('Status', 'extend-wp'),
          'removeEmpty' => true,
          'options' => $ewp_args['status'],
          'label_class' => array('awm-needed'),
          'attributes' => array('value' => isset($item_data['item']['status']) ? $item_data['item']['status'] : '')
        )
      );
      $select_box = awm_show_content($select);
    }
    $id = isset($item_data['item']['content_id']) ? absint($item_data['item']['content_id']) : '';

    $save_text = $id != ''  ? __('Update', 'extend-wp') : __('Save', 'extend-wp');
    $dev_notes = '';
    if ($id) {
      $dev_notes = array(
        'content_id: ' . $id,
        'hash: ' . $item_data['item']['hash']
      );
      $dev_notes = '<div class="ewp-dev-notes" style="font-size:90%;opacity:0.8;word-">' . implode('<br>', $dev_notes) . '</div>';
    }
    $delete_button = '';
    if ($this->show_delete) {
      $delete_button = $id != '' ? '<a class="submitdelete deletion awm-delete-content-id" href="' . wp_nonce_url(admin_url($this->page_link . '&id=' . $id . '&action=delete'), $this->page_id . '_delete') . '">' . __('Delete ', 'extend-wp') . '</a>' : '';
    }
    return '<div class="submitbox ewp-status-box"><div id="major-publishing-actions">' . $select_box . '
    <div>
        <div id="delete-action">' . $delete_button . '</div>
        <div id="publishing-action">
        <span class="spinner"></span>
                <input name="save" type="submit" class="button button-primary button-large" id="publish" value="' . $save_text . '">
        </div>
        </div>
        <div class="clear"></div>
        ' . $dev_notes . '
         <div class="clear"></div>
        </div>
        </div>';
  }


  /**
   * Callback function used to add the add new / edit pages for the custom filox table lists
   * 
   * @param string $list_name - The name of the table list. Used for the menu page name and the slug url as well
   * @param string $table_name - The name of the SQL table to get the data from. Do NOT include table prefix
   */
  public function flx_table_list_sub_page_handler($args)
  {
    global $ewp_args;
    $args['page_hook'] = $this->page_hook;
    $ewp_args = $args + $this->get_current_view_data();
    echo awm_parse_template(awm_path . 'templates/admin-view/edit-post.php');
  }
}