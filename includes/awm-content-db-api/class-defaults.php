<?php
if (!defined('ABSPATH')) {
  exit;
}
/**
 * with this class we register defaut content objects for the extend wp plugin
 */

class Extend_WP_Default_Content
{

  public function __construct()
  {
    add_filter('awm_add_options_boxes_filter', [$this, 'admin_menu']);
  }
  public function admin_menu($options)
  {
    $options['extend-wp'] = array(
      'title' => apply_filters('ewp_whitelabel_filter', __('Extend WP', 'extend-wp')),
      'parent' => false,
      'cap' => 'edit_posts'
    );
    return $options;
  }
}


new Extend_WP_Default_Content();
