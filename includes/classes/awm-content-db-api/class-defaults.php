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

    $allowed_users = get_option('ewp_user_access', array());
    $allowed_users[] = 'administrator';
    /*check if current user can access the page based on the user role get first the user role and then check if in array*/
    $user = wp_get_current_user();
    $user_role = $user->roles[0];
    if (!in_array($user_role, $allowed_users)) {
      return $options;
    }
  
    $options['extend-wp'] = array(
      'title' => apply_filters('ewp_whitelabel_filter', __('Extend WP', 'extend-wp')),
      'parent' => false,
      'cap' => 'manage_options',
      'icon' => 'dashicons-admin-generic',

      'library' => $this->admin_fields()
    );
    return $options;
  }

  public function admin_fields()
  {
    return apply_filters('ewp_admin_fields_filter', array(
      'ewp_user_access' => array(
        'case' => 'user_roles',
        'exclude' => array('administrator'),
        'attributes' => array('multiple' => true),
        'label' => __('Select user roles with access to the plugin', 'extend-wp'),
      ),

    ));
  }
}


new Extend_WP_Default_Content();