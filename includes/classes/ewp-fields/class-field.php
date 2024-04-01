<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * with this class we set the custom meta post type for edit by users
 */
require_once 'ewp_field_functions.php';

class Extend_WP_Fields
{
 /**
  * var array $ewp_all_fields with 
  */
 public static $ewp_all_fields = array();

 private $skip_existing_fields;/*check if we have empty dynamic fields*/

 public function init()
 {
  $this->skip_existing_fields = false;
  add_filter('awm_register_content_db', [$this, 'register_defaults']);
  add_action('ewp_fields_save_action', [$this, 'clear_transients']);
  add_action('ewp_fields_delete_action', [$this, 'clear_transients']);
  add_filter('awm_add_meta_boxes_filter', array($this, 'dynamic_post_boxes'), PHP_INT_MAX);
  add_filter('awm_add_term_meta_boxes_filter', array($this, 'dynamic_term_boxes'), PHP_INT_MAX);
  add_filter('awm_add_user_boxes_filter', array($this, 'dynamic_user_boxes'), PHP_INT_MAX);
  add_filter('awm_add_options_boxes_filter', array($this, 'dynamic_option_pages'), PHP_INT_MAX);
  add_filter('awm_show_content_fields_filter', array($this, 'dynamic_existing_edits'), PHP_INT_MAX, 2);
  add_filter('awm_add_customizer_settings_filter', [$this, 'get_customizer_boxes'], PHP_INT_MAX);
  add_filter('ewp_gutenburg_blocks_filter', [$this, 'get_blocks'], PHP_INT_MAX);
 }


 public function get_blocks($blocks)
 {
  $new_blocks = awm_create_boxes('ewp_block', awm_get_fields('ewp_block'));
  if (empty($new_blocks)) {
   return $blocks;
  }

  $blocks += $new_blocks;
  return $blocks;
 }



 public function clear_transients()
 {
  update_option('ewp_user_caps_version', strtotime('now'), false);
  awm_delete_transient_group('awm_post_fields_transients');
  delete_option('ewp_user_caps_version_old');
  wp_cache_flush();
 }

 public function register_defaults($data)
 {
  /*register fields*/
  $data['fields'] = array(
   'parent' => 'extend-wp',
   'statuses' => array(
    'enabled' => array('label' => __('Enabled', 'extend-wp')),
    'disabled' => array('label' => __('Disabled', 'extend-wp')),
   ),
   'show_new' => false,
   'list_name' => __('Fields', 'extend-wp'),
   'list_name_singular' => __('Field', 'extend-wp'),
   'order' => 1,
   'capability' => 'activate_plugins',
   'version' => 0.01,
   'metaboxes' => $this->ewp_fields_metas()
  );
  return $data;
 }

 public function ewp_fields_metas()
 {
  $boxes = array();
  $boxes['awm_metas'] = array(
   'title' => __('Fields Configuration', 'extend-wp'),

   'context' => 'normal',
   'priority' => 'high',
   'callback' => 'awm_fields_configuration',
   'auto_translate' => true,
   'order' => 1,
  );
  $boxes['awm_position_settings'] = array(
   'title' => __('Fields Position', 'extend-wp'),
   'context' => 'normal',
   'priority' => 'low',
   'callback' => 'awm_fields_positions',
   'auto_translate' => true,
   'order' => 1,
  );
  $boxes['awm_php_usage'] = array(
   'title' => __('Php usage', 'extend-wp'),
   'context' => 'normal',
   'priority' => 'low',
   'callback' => 'awm_php_views',
   'auto_translate' => true,
   'order' => 1,
  );
  $boxes['awm_fields_usage'] = array(
   'title' => __('Fields Usage', 'extend-wp'),

   'context' => 'side',
   'priority' => 'low',
   'callback' => 'awm_fields_usages',
   'auto_translate' => true,
   'order' => 1,
  );
  $boxes['awm_fields_dev_notes'] = array(
   'title' => __('Developer notes', 'extend-wp'),
   'context' => 'side',
   'priority' => 'low',
   'callback' => 'awm_dev_notes',
   'auto_translate' => true,
   'order' => 1,
  );
  return $boxes;
 }


 public function dynamic_existing_edits($fields, $id)
 {
  if ($this->skip_existing_fields) {
   return $fields;
  }
  $new_fields = awm_get_fields('existing_awm_fields');
  if (empty($new_fields)) {
   $this->skip_existing_fields = true;
   return $fields;
  }
  if (isset($id)) {

   $new_fields = awm_get_fields('existing_awm_fields', $id);

   if (empty($new_fields)) {
    return $fields;
   }
   foreach ($new_fields as $field) {
    $fields += awm_create_library($field);
   }
  }
  return $fields;
 }

 public function dynamic_option_pages($boxes)
 {
  $boxes += awm_create_boxes('options', awm_get_fields('options'));
  return $boxes;
 }

 public function dynamic_user_boxes($boxes)
 {
  $boxes += awm_create_boxes('user', awm_get_fields('user'));
  return $boxes;
 }

 public function dynamic_term_boxes($boxes)
 {
  $boxes += awm_create_boxes('taxonomy', awm_get_fields('taxonomy'));
  return $boxes;
 }

 public function dynamic_post_boxes($boxes)
 {
  $boxes += awm_create_boxes('post_type', awm_get_fields('post_type'));
  return $boxes;
 }
}


$ewp_fields = new Extend_WP_Fields();
$ewp_fields->init();