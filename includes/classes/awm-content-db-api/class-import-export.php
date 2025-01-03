<?php
if (!defined('ABSPATH')) {
 exit;
}
/**
 * with this class we register defaut content objects for the extend wp plugin
 */

class Extend_WP_Import_Export
{
 private $version = '1.0';

 public function __construct()
 {
  add_filter('awm_add_options_boxes_filter', [$this, 'admin_menu']);
  add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
  add_action('rest_api_init', [$this, 'rest_endpoints'], 10);
 }

 /**
  * register the endpoint based on each search
  */
 public function rest_endpoints()
 {
  $rest = array();
  $rest['ewp-import'] = array(
   'endpoint' => 'import',
   'namespace' =>  'ewp/v1',
   'method' => 'post',
   'php_callback' => [$this, 'import'],
   'permission_callback' => 'ewp_rest_check_user_is_admin',
   'args' => array(
    'content_type' => array(
     'required' => true,
     'type' => 'string',
     'validate_callback' => 'rest_validate_request_arg',
    ),
    'content' => array(
     'required' => true,
     'type' => 'array',
     'validate_callback' => 'rest_validate_request_arg',
    )
   ),
  );
  
  $rest['ewp-export'] = array(
   'endpoint' => 'export',
   'namespace' =>  'ewp/v1',
   'method' => 'get',
   'php_callback' => [$this, 'export'],
   'permission_callback' => 'ewp_rest_check_user_is_admin',
   'args' => array(
    'content_types' => array(
     'required' => true,
     'type' => 'array',
     'validate_callback' => 'rest_validate_request_arg',
    ),
    'method' => array(
     'required' => true,
     'type' => 'string',
     'sanitize_callback' => 'sanitize_text_field',
     'validate_callback' => 'rest_validate_request_arg',
    ),
   )
  );
  /*call class*/
  $d_api = new AWM_Dynamic_API($rest);
  $d_api->register_routes();
 }

 public function import($request)
 {
  if (isset($request)) {
   $params = $request->get_params();
   $content_type = $params['content_type'];
   $content = $params['content'];
   if (empty($content_type) || empty($content)) {
    return new WP_Error('no_content', 'No content provided', array('status' => 400));
   }
   $this->import_content($content_type, $content);
   return true;
  }
  return new WP_Error('no_params', 'No parameters provided', array('status' => 400));
 }

 public function import_content($content_type, $data)
 {
  foreach ($data as $content_data) {
   $meta = isset($content_data['meta']) ? $content_data['meta'] : array();
   unset($content_data['meta']);
   $import_id = awm_insert_db_content($content_type, $content_data, array('hash'));

   if (!$import_id) {
    return new WP_Error('not_imported_id', 'Id:' . $content_data['content_id'], array('status' => 400));
   }
   if (!empty($meta)) {
    awm_insert_db_content_meta($content_type, $import_id, $meta);
   }
  }
  return true;
 }



 /**
  * insert new coupons
  */
 public function export($request)
 {
  if (isset($request)) {
   $params = $request->get_params();
   $content_types = $params['content_types'];
   $method = $params['method'] ?? 'json';
   if (empty($content_types)) {
    return new WP_Error('no_content_types', 'No content types provided', array('status' => 400));
   }
   $data = $this->export_content($content_types);
   if (is_wp_error($data)) {
    return $data;
   }

   if ($method === 'json') {
    return rest_ensure_response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
   } elseif ($method === 'php') {
    return rest_ensure_response(serialize($data));
   } else {
    return new WP_Error('invalid_method', 'Invalid export method.', array('status' => 400));
   }
  }
  return new WP_Error('no_params', 'No parameters provided', array('status' => 400));
 }

 public function export_content($content_types)
 {
  $data = array();
  foreach ($content_types as $content_type) {
   $contents = awm_get_db_content($content_type);
   if (empty($contents)) {
    continue;
   }
   $data[$content_type] = array();
   foreach ($contents as $content) {
    $content_data = $content;
    if (!isset($content_data['hash']) || empty($content_data['hash'])) {
     /* hash check for older version and update*/
     $hash = md5(serialize($content));
     $content_data['hash'] = $hash;
     awm_insert_db_content($content_type, $content_data);
    }
    $content_data['meta'] = awm_get_db_content_meta($content_type, $content['content_id']);
    $data[$content_type][$content['content_id']] = $content_data;
   }
  }
  if (empty($data)) {
   return new WP_Error('no_data', 'No data to export', array('status' => 400));
  }
  $data['modified'] = date('Y-m-d H:i:s');

  return $data;
 }



 public function admin_scripts()
 {
  /*check if we are in the correct options page*/
  if (!isset($_GET['page']) || $_GET['page'] != 'ewp-import-export') {
   return;
  }
  wp_enqueue_script('extend-wp-import-export', awm_url . '/assets/js/admin/import-export.js', array(), $this->version, true);
 }

 public function admin_menu($options)
 {
  $options['ewp-import-export'] = array(
   'title' => __('Import/Export', 'extend-wp'),
   'parent' => 'extend-wp',
   'cap' => 'manage_options',
   'library' => $this->import_export_fields(),
   'submit_label' => __('Perform Action', 'extend-wp')
  );
  return $options;
 }

 public function import_export_fields()
 {
  return array(
   'case' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Action', 'extend-wp'),
    'case' => 'select',
    'options' => array(
     'export' => array('label' => __('Export', 'extend-wp')),
     'import' => array('label' => __('Import', 'extend-wp'))
    )
   ),
   'content_types' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Content Types', 'extend-wp'),
    'case' => 'ewp_content_types',
    'attributes' => array('multiple' => true),
    'show-when' => array('case' => array('values' => array('export' => true))),
   ),
   'method' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Export method', 'extend-wp'),
    'case' => 'select',
    'options' => array(
     'json' => array('label' => __('JSON', 'extend-wp')),
     //'php' => array('label' => __('PHP', 'extend-wp')),
    ),
    'show-when' => array('case' => array('values' => array('export' => true))),
   ),
   'file' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Import File', 'extend-wp'),
    'case' => 'input',
    'type' => 'file',
    'attributes' => array('accept' => '.json'),
    'show-when' => array('case' => array('values' => array('import' => true))),
    'explanation' => __('Please upload a JSON file', 'extend-wp')
   ),
   'import_message' => array(
    'case' => 'html',
    'show-when' => array('case' => array('values' => array('import' => true))),
    'value' => '<div id="import-message"></div>',
    'exclude_meta' => true,
   )
  );
 }
}


new Extend_WP_Import_Export();