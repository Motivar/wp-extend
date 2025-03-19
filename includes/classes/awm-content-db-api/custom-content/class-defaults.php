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
    $allowed_users = array();
    $settings = get_option('ewp_general_settings') ?: array();
    if (isset($settings['ewp_user_access']) && !empty($settings['ewp_user_access'])) {
      $allowed_users = $settings['ewp_user_access'];
    } 

    $allowed_users[] = 'administrator';
    /*check if current user can access the page based on the user role get first the user role and then check if in array*/
    $user = wp_get_current_user();
    if (!$user->ID) {
      return $options;
    }
    $user_roles = $user->roles;
    /*check if $user_roles and $allowed_user have intersection*/
    if (empty(array_intersect($user_roles, $allowed_users))) {
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
        'ewp_auto_export_settings' => array(
          'case' => 'section',
          'label' => __('Export EWP Content Configuration', 'extend-wp'),
          'explanation' => __('Export your content to a file for backup or migration purposes. The filename will be <strong>ewp_configuration.json</strong>.', 'extend-wp'),
          'include' => array(
            'store' => array(
              'label' => __('Auto export', 'extend-wp'),
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
                __('Place just the folder path following this address <strong>%s</strong>. Filename is auto-configured. Please also check that the path is writable by php!', 'extend-wp'),
                WP_CONTENT_DIR
              )
            ),
          ),
        ),
        'ewp_auto_import_settings' => array(
          'case' => 'section',
          'label' => __('Import EWP Content Configuration', 'extend-wp'),
          'explanation' => __('Import EWP content based on the <strong>ewp_configuration.json</strong>. The file should exist in the path you are going to provide.', 'extend-wp'),
          'include' => array(
            'import' => array(
              'label' => __('Enable import', 'extend-wp'),
              'case' => 'input',
              'type' => 'checkbox',
              'after_message' => __('If enabled, the plugin will sync its content based on your configuration.', 'extend-wp'),
            ),
            'import_type' => array(
              'label_class' => array('awm-needed'),
              'label' => __('Import method', 'extend-wp'),
              'case' => 'select',
              'options' => array(
               // 'manual' => array('label' => __('Manual', 'extend-wp')),
                'auto' => array('label' => __('Auto', 'extend-wp')),
              ),
              'show-when' => array('import' => array('values' => true)),
            ),
            'path' => array(
              'label_class' => array('awm-needed'),
              'label' => __('Filepath load location', 'extend-wp'),
              'case' => 'input',
              'type' => 'text',
              'show-when' => array('import' => array('values' => true)),
              'explanation' => sprintf(
                __('Place just the folder path following this address <strong>%s</strong>. Filename is auto-configured.', 'extend-wp'),
                WP_CONTENT_DIR
              )
            ),
          ),
        ),
        'ewp_dev_settings' => array(
          'case' => 'section',
          'label' => __('Developer Settings', 'extend-wp'),
          'include' => array(
            'google_maps_api_key' => array(
              'case' => 'input',
              'type' => 'text',
              'label' => __('Google Maps API key', 'extend-wp'),
            ),
          )
        ),

      ),

    );
  }
}


new Extend_WP_Default_Content();