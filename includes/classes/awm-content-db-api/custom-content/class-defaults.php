<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}
/**
 * Extend WP Default Content Class
 * 
 * This class registers default content objects for the Extend WP plugin.
 * It handles dashboard widget management, admin menu configuration, and 
 * defines the settings fields for the plugin's administration interface.
 * 
 * @since 1.0.0
 */

class Extend_WP_Default_Content
{
  /**
   * Constructor
   * 
   * Initializes the class by registering hooks for admin menu and dashboard widgets.
   * 
   * @since 1.0.0
   * @return void
   */
  public function __construct()
  {
    add_filter('awm_add_options_boxes_filter', [$this, 'admin_menu']);
    add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets']);
  }
  
  /**
   * Remove Dashboard Widgets
   * 
   * Removes default WordPress dashboard widgets for non-administrator users
   * based on plugin settings.
   * 
   * @since 1.0.0
   * @return void
   */
  public function remove_dashboard_widgets()
  {
    // Get plugin settings or use empty array if not set
    $settings = get_option('ewp_general_settings') ?: array();
    
    // If dashboard widgets are allowed in settings, exit early
    if (isset($settings['ewp_allow_dashboard_core_widgets']) && $settings['ewp_allow_dashboard_core_widgets']) {
      return;
    }
    
    // Don't remove widgets for administrators
    if (current_user_can('administrator')) {
      return;
    }
    
    // Remove standard WordPress dashboard widgets
    remove_meta_box('dashboard_right_now', 'dashboard', 'normal');   // At a Glance
    remove_meta_box('dashboard_activity', 'dashboard', 'normal');    // Activity
    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');   // Quick Draft
    remove_meta_box('dashboard_primary', 'dashboard', 'side');       // WordPress Events and News
    remove_meta_box('dashboard_site_health', 'dashboard', 'normal'); // Site Health Status
    remove_meta_box('dashboard_secondary', 'dashboard', 'side');     // Other WordPress News
  }

  /**
   * Admin Menu Configuration
   * 
   * Adds the Extend WP menu item to the WordPress admin menu
   * based on user role permissions.
   * 
   * @since 1.0.0
   * @param array $options Existing menu options
   * @return array Modified menu options
   */
  public function admin_menu($options)
  {
    // Initialize array for allowed user roles
    $allowed_users = array();
    
    // Get plugin settings or use empty array if not set
    $settings = get_option('ewp_general_settings') ?: array();
    
    // Get allowed user roles from settings if available
    if (isset($settings['ewp_user_access']) && !empty($settings['ewp_user_access'])) {
      $allowed_users = $settings['ewp_user_access'];
    } 

    // Always allow administrators
    $allowed_users[] = 'administrator';
    
    // Get current user information
    $user = wp_get_current_user();
    if (!$user->ID) {
      return $options;
    }
    
    // Get user roles
    $user_roles = $user->roles;
    
    // Check if current user has permission to access the menu
    if (empty(array_intersect($user_roles, $allowed_users))) {
      return $options;
    }
  
    // Add Extend WP menu to options
    $options['extend-wp'] = array(
      'title' => apply_filters('ewp_whitelabel_filter', __('Extend WP', 'extend-wp')),
      'parent' => false,
      'cap' => 'manage_options',
      'icon' => 'dashicons-admin-generic',
      'library' => $this->admin_fields()
    );
    
    return $options;
  }

  /**
   * Admin Fields Configuration
   * 
   * Defines the settings fields and sections for the Extend WP admin interface.
   * Includes general settings, export/import configuration, and developer options.
   * 
   * @since 1.0.0
   * @return array Array of admin field configurations
   */
  public function admin_fields()
  {
    return apply_filters('ewp_admin_fields_filter', array(
        // General Settings Section
        'ewp_general_settings' => array(
          'case' => 'section',
          'label' => __('General Settings', 'extend-wp'),
          'include' => array(
            // User role access configuration
            'ewp_user_access' => array(
              'case' => 'user_roles',
              'exclude' => array('administrator'),
              'attributes' => array('multiple' => true),
              'label' => __('Select user roles with access to the plugin', 'extend-wp'),
            ),
            // Dashboard widgets permission
            'ewp_allow_dashboard_core_widgets' => array(
              'case' => 'input',
              'type' => 'checkbox',
              'label' => __('Allow dashboard core widgets to non administrators', 'extend-wp'),
            )
          )
        ),
        
        // Export Settings Section
        'ewp_auto_export_settings' => array(
          'case' => 'section',
          'label' => __('Export EWP Content Configuration', 'extend-wp'),
          'explanation' => __('Export your content to a file for backup or migration purposes. The filename will be <strong>ewp_configuration.json</strong>.', 'extend-wp'),
          'include' => array(
            // Auto export toggle
            'store' => array(
              'label' => __('Auto export', 'extend-wp'),
              'case' => 'input',
              'type' => 'checkbox',
              'after_message' => __('If enabled, the plugin will auto-export its content based on your configuration', 'extend-wp'),
            ),
            // Content types selection for export
            'types' => array(
              'label_class' => array('awm-needed'),
              'label' => __('Content Types', 'extend-wp'),
              'case' => 'ewp_content_types',
              'attributes' => array('multiple' => true),
              'show-when' => array('store' => array('values' => true))
            ),
            // Export file path configuration
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
        
        // Import Settings Section
        'ewp_auto_import_settings' => array(
          'case' => 'section',
          'label' => __('Import EWP Content Configuration', 'extend-wp'),
          'explanation' => __('Import EWP content based on the <strong>ewp_configuration.json</strong>. The file should exist in the path you are going to provide.', 'extend-wp'),
          'include' => array(
            // Import toggle
            'import' => array(
              'label' => __('Enable import', 'extend-wp'),
              'case' => 'input',
              'type' => 'checkbox',
              'after_message' => __('If enabled, the plugin will sync its content based on your configuration.', 'extend-wp'),
            ),
            // Import method selection
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
            // Import file path configuration
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
        
        // Developer Settings Section
        'ewp_dev_settings' => array(
          'case' => 'section',
          'label' => __('Developer Settings', 'extend-wp'),
          'include' => array(
            // Google Maps API key configuration
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

/**
 * Initialize the Extend_WP_Default_Content class
 */
new Extend_WP_Default_Content();