<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * with this class we set the custom meta post type for edit by users
 */


class Extend_WP_Custom_Content_UI
{
 /**
  * var array $ewp_all_fields with 
  */


 public function init()
 {
  add_filter('awm_register_content_db', [$this, 'register_defaults'], PHP_INT_MAX);
 }

 public function dynamic_content_registration($data) {}


 public function register_defaults($data)
 {
  /*register fields*/
  $data['content_type'] = array(
   'parent' => 'extend-wp',
   'statuses' => array(
    'enabled' => array('label' => __('Enabled', 'extend-wp')),
    'disabled' => array('label' => __('Disabled', 'extend-wp')),
   ),
   'show_new' => false,
   'list_name' => __('Content types', 'extend-wp'),
   'list_name_singular' => __('Content type', 'extend-wp'),
   'order' => 1,
   'capability' => 'activate_plugins',
   'version' => 0.01,
   'metaboxes' => $this->content_metas()
  );

  $args = array(
   'status' => array('enabled'),
   'limit' => -1,
  );
  $dynamic_content_types = awm_get_db_content('ewp_content_type', $args);
  if (empty($dynamic_content_types)) {
   return $data;
  }
  foreach ($dynamic_content_types as $content_type) {

   $meta_data = awm_get_db_content_meta('ewp_content_type', $content_type['content_id']);
   $db_object = strtolower(awm_clean_string($meta_data['key']));
   $statuses = array();
   if (!empty($meta_data['statuses'])) {
    foreach ($meta_data['statuses'] as $status) {
     $statuses[$status['awm_key']] = array('label' => $status['status']);
    }
   }
   $data[$db_object] = array(
    'parent' => $meta_data['parent'],
    'list_name' => $meta_data['plural'],
    'list_name_singular' => $meta_data['singular'],
    'capability' => 'activate_plugins',
    'version' => $meta_data['version'],
    'statuses' => $statuses,
    'metaboxes' => array()
   );
  }

  return $data;
 }

 public function content_metas()
 {
  $boxes = array();
  $boxes['content_metas'] = array(
   'title' => __('Content Type Configuration', 'extend-wp'),
   'context' => 'normal',
   'priority' => 'high',
   'library' => $this->configuration_metas(),
   'auto_translate' => true,
   'order' => 1,
  );
  return $boxes;
 }

 public function configuration_metas()
 {
  return array(
   'key' => array(
    'case' => 'input',
    'label' => __('DB Table name', 'extend-wp'),
    'type' => 'text',
    'explanation' => __('DB Table name for the content type. Two tables will be created based on this name.', 'extend-wp'),
    'label_class' => array('awm-needed'),
    'admin_list' => 1
   ),
   'version' => array(
    'case' => 'input',
    'label' => __('Version', 'extend-wp'),
    'type' => 'number',
    'explanation' => __('Version of the content type. Be careful because changes will affect db structure.', 'extend-wp'),
    'attributes' => array('min' => 1),
    'label_class' => array('awm-needed'),
    'admin_list' => 1
   ),
   'singular' => array(
    'case' => 'input',
    'label' => __('Singular', 'extend-wp'),
    'type' => 'text',
    'explanation' => __('Singular label for the content type', 'extend-wp'),
    'label_class' => array('awm-needed'),
    'admin_list' => 1
   ),
   'plural' => array(
    'case' => 'input',
    'label' => __('Plural', 'extend-wp'),
    'type' => 'text',
    'explanation' => __('Plural label for the content type', 'extend-wp'),
    'label_class' => array('awm-needed'),
    'admin_list' => 1
   ),
   'parent' => array(
    'case' => 'input',
    'label' => __('Parent', 'extend-wp'),
    'type' => 'text',
    'explanation' => __('Parent location of the content type. Leave empty for placing this in admin menu.', 'extend-wp'),
   ),
   'statuses' => array(
    'case' => 'repeater',
    'label' => __('Statuses', 'extend-wp'),
    'explanation' => __('Statuses for the content type, seperated by comma. Leave empty for the defaults (public/private)', 'extend-wp'),
    'item_name' => __('Status', 'extend-wp'),
    'include' => array(
     'status' => array(
      'case' => 'input',
      'label' => __('Status', 'extend-wp'),
      'type' => 'text',
      'explanation' => __('Status label', 'extend-wp'),
      'label_class' => array('awm-needed')
     ),
    )
   )
  );
 }
}


$ewp_fields = new Extend_WP_Custom_Content_UI();
$ewp_fields->init();