<?php
if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly.
}
/**
 * inside post layout
 *
 * @see     
 *
 * @author  Motivar
 *
 * @version 0.0.1
 */

global $ewp_args, $ewp_content_id;
$list_name = $ewp_args['list_name'];
$is_data_encrypted = $ewp_args['is_data_encrypted'];
$view = $ewp_args['page_hook'];
$display_id = isset($_REQUEST['id']) ? $_REQUEST['id'] : 'new';
?>


<div class="wrap" id="ewp-content-form">
 <div class=" icon32 icon32-posts-post" id="icon-edit"><br></div>
 <h2><?php _e(ucwords($list_name), 'extend-wp') ?> <a class="add-new-h2" href="<?php echo get_admin_url(get_current_blog_id(), 'admin.php?page=' . $ewp_args['id']); ?>"><?php _e('See all', 'extend-wp') ?></a>
  <?php if (!$ewp_args['disable_new']) { ?>
   <a class="add-new-h2" href="<?php echo awm_edit_screen_link($ewp_args); ?>"><?php echo sprintf(__('New %s', 'awm'), $ewp_args['list_name_singular']) ?></a>
  <?php } ?>
  <?php do_action('awm_edit_screen_h2_header_action', $ewp_args); ?>
 </h2>

 <form name="ewp-custom-list-form" method="post">
  <input type="hidden" name="table_id" id="ewp_content_id" value="<?php echo $display_id; ?>" />
  <?php wp_nonce_field($view, 'ewp_list_page_hook_nonce');

  /* Used to save closed meta boxes and their order */
  wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false);
  wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false); ?>

  <div id="poststuff">
   <div id="post-body" class="metabox-holder columns-<?php echo 1 == get_current_screen()->get_columns() ? '1' : '2'; ?>">
    <div id="postbox-container-1" class="postbox-container">
     <?php do_meta_boxes($view, 'side', null); ?>
    </div>
    <div id="postbox-container-2" class="postbox-container">
     <div id="titlediv">
      <div id="titlewrap">
       <?php echo awm_show_content(array('title' => array(
        'case' => 'input',
        'type' => 'text',
        'label' => __('Title', 'awm'),
        'label_class' => array('awm-needed'),
        'attributes' => array('placeholder' => __('Add your title', 'extend-wp'), 'value' => isset($ewp_args['item']['content_title']) ? $ewp_args['item']['content_title'] : '')
       )), $display_id, $ewp_args['id'] . '_main'); ?>
      </div>
     </div>
     <?php do_meta_boxes($view, 'normal', null); ?>
     <?php do_meta_boxes($view, 'advanced', null); ?>
    </div>
   </div> <!-- #post-body -->
  </div> <!-- #poststuff -->
 </form>
</div>