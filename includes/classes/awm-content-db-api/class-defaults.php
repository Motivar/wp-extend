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
        'ewp_general_settings' => array(
          'case' => 'section',
          'label' => __('General Settings', 'extend-wp'),
          'include' => array(
            'ewp_user_access' => array(
              'case' => 'user_roles',
              'exclude' => array('administrator'),
              'attributes' => array('multiple' => true),
              'label' => __('Select user roles with access to the plugin', 'extend-wp'),
            ),
          )
        ),
        'ewp_import_export_settings' => array(
          'case' => 'section',
          'label' => __('Auto Store Content Configuration', 'extend-wp'),
          'include' => array(
            'store' => array(
              'label' => __('Auto Store Content', 'extend-wp'),
              'case' => 'input',
              'type' => 'checkbox',
              'after_message' => __('If enabled, the plugin will auto-export its content based on your configuration', 'extend-wp'),
            ),
            'types' => array(
              'label_class' => array('awm-needed'),
              'label' => __('Content Types', 'extend-wp'),
              'case' => 'ewp_content_types',
              'attributes' => array('multiple' => true),
              'show-when' => array('store' => array('values' => true))
            ),
            'path' => array(
              'label_class' => array('awm-needed'),
              'label' => __('Filepath save location', 'extend-wp'),
              'case' => 'input',
              'type' => 'text',
              'show-when' => array('store' => array('values' => true)),
              'explanation' => sprintf(
                __('Place the path following this address <strong>%s</strong>. Please also check that the path is writable by php!', 'extend-wp'),
                WP_CONTENT_DIR
              )
            ),
          ),
        ),

      ),

    );
  }
}


new Extend_WP_Default_Content();