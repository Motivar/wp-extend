<?php
if (!defined('ABSPATH')) {
 exit;
}
/**
 * with this class we register defaut content objects for the extend wp plugin
 */

class Extend_WP_Import_Export
{
 private $version = '1.1';

 public function __construct()
 {
  add_filter('awm_add_options_boxes_filter', [$this, 'admin_menu']);
  add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
  add_action('rest_api_init', [$this, 'rest_endpoints'], 10);
  add_action('ewp_custom_content_save_action', [$this, 'auto_export_content'], 100);
  add_action('ewp_custom_content_delete_action', [$this, 'auto_export_content'], 100);
  add_action('admin_init', [$this, 'auto_import_content'], 100);
 }

 public function get_import_options()
 {
  /**
   * get the options for the import export settings
   * @return array
   */
  return apply_filters('ewp_auto_import_settings_filter', get_option('ewp_auto_import_settings', array()));
 }

 public function auto_import_content()
 {
  try {
   $options = $this->get_import_options();

   // Ensure import option is enabled and the configuration is correct
   if (empty($options) || empty($options['import']) || empty($options['path']) || $options['import_type'] !== 'auto') {
    return;
   }

   // Ensure path exists
   $path = WP_CONTENT_DIR . trailingslashit($options['path']);
   if (!file_exists($path)) {
    throw new Exception(sprintf(__('The path %s does not exist.', 'extend-wp'), $path));
   }

   // Check if the configuration file exists
   $file = $path . 'ewp_configuration.json';
   if (!file_exists($file)) {
    throw new Exception(sprintf(__('The file %s does not exist.', 'extend-wp'), $file));
   }

   // Check file signature to prevent duplicate imports
   $file_signature = md5_file($file);
   $signature = get_option('ewp_file_import_signature') ?: false;
   if ($signature && $signature === $file_signature) {
    return; // No changes detected
   }

   // Read and decode the JSON file
   $content = file_get_contents($file);
   $data = json_decode($content, true);

   if (json_last_error() !== JSON_ERROR_NONE) {
    throw new Exception(__('Invalid JSON in the configuration file.', 'extend-wp'));
   }

   $all_hashes = array();
   // Loop through the content types and call the import function

   foreach ($data as $content_type => $items) {
    if ($content_type === 'modified') {
     continue; // Skip metadata
    }
    $import = $this->import_content($content_type, $items);
    if (is_wp_error($import) || !$import) {

     // throw an error with the related messsage
     throw new Exception(sprintf(__('Failed to import content of type %s.', 'extend-wp'), $content_type));
    }
    $all_hashes[$content_type] = $import;
   }

   // Update the file signature to prevent re-imports

   $old_hashes = get_option('ewp_auto_imported_hashes') ?: array();
   if (!$old_hashes || empty($old_hashes)) {
    foreach ($old_hashes as $content_type => $hashes) {
     if (empty($hashes) || !$hashes || !is_array($hashes)) {
      continue;
     }
     /*check if $old_hashes[$content_type] are different than $all_hashes[$content_type] and delete the non used anymore*/
     $new_array = isset($all_hashes[$content_type]) ? $all_hashes[$content_type] : array();
     // Find the hashes that exist in old but not in new
     $unused_hashes = array_diff($hashes, $new_array);
     if (empty($unused_hashes)) {
      awm_custom_content_delete($content_type, $unused_hashes, 'hash');
     }
    }
   }
   update_option('ewp_file_import_signature', $file_signature, false);
   update_option('ewp_auto_imported_hashes', $all_hashes, false);


   // Add a dismissible admin notice
   add_action('admin_notices', function () {
    echo '<div class="notice notice-success is-dismissible">';
    echo '<p>' . __('EWP Content has been successfully imported.', 'extend-wp') . '</p>';
    echo '</div>';
   });
   ewp_flush_cache();
   return true;
  } catch (Exception $e) {
   // Handle exceptions and log the error
   error_log(sprintf(__('Import error: %s', 'extend-wp'), $e->getMessage()));

   // Add a dismissible admin notice for the error
   add_action('admin_notices', function () use ($e) {
    echo '<div class="notice notice-error">';
    echo '<p>' . sprintf(__('Import failed: %s. Please check your php error_logs.', 'extend-wp'), $e->getMessage()) . '</p>';
    echo '</div>';
   });
   ewp_flush_cache();
  }
 }



 public function get_export_options()
 {
  /**
   * get the options for the import export settings
   * @return array
   */
  return apply_filters('ewp_auto_export_settings_filter', get_option('ewp_auto_export_settings ', array()));
 }


 public function auto_export_content($id)
 {
  try {
   $options = $this->get_export_options();

   if (empty($options) || !isset($options['store']) || !$options['store']) {
    return;
   }

   // Ensure store option is enabled
   if (empty($options['path']) || empty($options['types'])) {
    throw new Exception(__('Export settings are incomplete or disabled.', 'extend-wp'));
   }

   if (!in_array($id, $options['types'])) {
    return;
   }

   // Ensure path exists
   $path = WP_CONTENT_DIR . trailingslashit($options['path']);
   if (!file_exists($path)) {
    throw new Exception(sprintf(__('The path %s does not exist.', 'extend-wp'), $path));
   }

   // Ensure path is writable
   if (!is_writable($path)) {
    throw new Exception(sprintf(__('The path %s is not writable.', 'extend-wp'), $path));
   }

   // Directly call the export_content function
   $data = $this->export_content($options['types']);
   if (is_wp_error($data)) {
    throw new Exception(sprintf(__('Failed to export content: %s', 'extend-wp'), $data->get_error_message()));
   }

   // Convert data to JSON
   $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
   if ($json_data === false) {
    throw new Exception(__('Failed to encode export data to JSON.', 'extend-wp'));
   }

   // Save JSON data to file
   $file_path = $path . 'ewp_configuration.json';
   $result = file_put_contents($file_path, $json_data);

   if ($result === false) {
    throw new Exception(sprintf(__('Failed to save exported content to %s.', 'extend-wp'), $file_path));
   }

   // Log success
   error_log(sprintf(__('Exported content saved to %s.', 'extend-wp'), $file_path));
  } catch (Exception $e) {
   // Handle exceptions and log the error
   error_log(sprintf(__('Export error: %s', 'extend-wp'), $e->getMessage()));

   // Optionally stop execution and show an error to the user
   wp_die($e->getMessage(), __('Export Error', 'extend-wp'), array('response' => 500));
  }
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
   ewp_flush_cache();
   return true;
  }
  return new WP_Error('no_params', 'No parameters provided', array('status' => 400));
 }

 public function import_content($content_type, $data)
 {
  $successful_imported = array();
  foreach ($data as $content_data) {
   $meta = isset($content_data['meta']) ? $content_data['meta'] : array();
   unset($content_data['meta']);
   $content_data['user_id'] = get_current_user_id(); // Set the user ID to the current user
   $import_id = awm_insert_db_content($content_type, $content_data, array('hash'));

   if (!$import_id) {
    return new WP_Error('not_imported_id', 'Id:' . $content_data['content_id'], array('status' => 400));
   }
   if (!empty($meta)) {
    awm_insert_db_content_meta($content_type, $import_id, $meta);
   }
   $successful_imported[] = $content_data['hash'];
  }
  return $successful_imported;
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