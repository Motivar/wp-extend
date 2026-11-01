<?php
if (!defined('ABSPATH')) {
 exit;
}
/**
 * with this class we register defaut content objects for the extend wp plugin
 */

require_once dirname(__DIR__, 2) . '/ewp-content/class-content-portability.php';
require_once dirname(__DIR__, 2) . '/ewp-content/class-config-sync.php';

class Extend_WP_Import_Export
{
 private $version = '1.1';

 /**
  * Shared implementation behind the REST routes, `wp ewp content export|import`,
  * the `ewp-content/export|import` abilities and the automatic file sync below.
  *
  * @var \EWP\Content\Content_Portability
  */
 private $portability;

 /**
  * Automatic configuration sync (auto export on save, auto import on admin_init).
  *
  * @var \EWP\Content\Config_Sync
  */
 private $sync;

 public function __construct()
 {
  $this->portability = new \EWP\Content\Content_Portability();
  $this->sync = new \EWP\Content\Config_Sync($this->portability);
  add_filter('awm_add_options_boxes_filter', [$this, 'admin_menu']);
  add_filter('ewp_register_dynamic_assets', [$this, 'register_dynamic_assets']);
  /* The ewp/v1 import and export routes are generated from EWP\Surfaces\Resources\Content_Portability_Resource. */
  add_action('ewp_custom_content_save_action', [$this, 'auto_export_content'], 100, 3);
  add_action('ewp_custom_content_delete_action', [$this, 'auto_export_content_deleted'], 100, 2);
  add_action('admin_init', [$this, 'auto_import_content'], 100);
  add_action('admin_notices', ['\EWP\Content\Config_Sync', 'render_notices']);
 }

 /**
  * The effective auto import settings (stored option through `ewp_auto_import_settings_filter`).
  *
  * @return array
  */
 public function get_import_options()
 {
  return $this->sync->import_settings();
 }

 /**
  * Import the configuration at the configured path when it changed (auto import): the per object
  * `ewp-config/` folder, or the legacy `ewp_configuration.json`.
  *
  * Runs on `admin_init` for administrators only, never on admin-ajax.
  *
  * @return true|null True after a successful import.
  * @since 1.10.0 Administrators only; no cache flush when the import fails.
  * @since 1.15.0 Delegates to \EWP\Content\Config_Sync; per object layout; stale rows are deleted correctly.
  */
 public function auto_import_content()
 {
  return $this->sync->auto_import();
 }

 /**
  * The effective auto export settings (stored option, dormant guard, `ewp_auto_export_settings_filter`).
  *
  * @return array
  * @since 1.15.0 Reads the stored option (its name used to carry a trailing space).
  */
 public function get_export_options()
 {
  return $this->sync->export_settings();
 }

 /**
  * A content row was saved: refresh its configuration file.
  *
  * @param string $content_type Content type id.
  * @param int    $object_id    Saved content id.
  *
  * @return true|null|\WP_Error
  * @since 1.15.0 Receives the saved id; never ends the request on a bad path.
  */
 public function auto_export_content($content_type, $object_id = 0)
 {
  return $this->sync->handle_saved($content_type, $object_id);
 }

 /**
  * Rows were deleted: drop their configuration files.
  *
  * @param string $content_type Content type id.
  *
  * @return true|null|\WP_Error
  * @since 1.15.0
  */
 public function auto_export_content_deleted($content_type)
 {
  return $this->sync->handle_deleted($content_type);
 }

 /**
  * Import rows into a content type (upsert by hash) and flush caches.
  *
  * @param string $content_type Content type id.
  * @param array  $data         Rows as exported.
  *
  * @return array|WP_Error Imported hashes, or an error.
  */
 public function import_content($content_type, $data)
 {
  $result = $this->portability->import((string) $content_type, is_array($data) ? $data : array());

  return is_wp_error($result) ? $result : $result['hashes'];
 }

 /**
  * Export every row of the given content types with their meta.
  *
  * @param array $content_types Content type ids.
  *
  * @return array|WP_Error
  */
 public function export_content($content_types)
 {
  return $this->portability->export(array_values((array) $content_types));
 }

 /**
  * Register import/export script with Dynamic Asset Loader
  * 
  * This method uses the Dynamic Asset Loader to load the import-export script
  * only when the import/export admin page is active, improving performance.
  * 
  * @param array $assets Existing registered assets
  * @return array Modified assets array with import/export script
  * 
  * @hook ewp_register_dynamic_assets
  */
 public function register_dynamic_assets($assets)
 {
  $version = defined('AWM_ASSET_VERSION') ? AWM_ASSET_VERSION : $this->version;
  $selector = '.wrap[data-page="ewp-import-export"]';
  $assets[] = array(
   'handle' => 'extend-wp-import-export',
   'selector' => $selector,
   'type' => 'script',
   'src' => awm_url . 'build/modules/ewp-import-export.js',
   'version' => $version,
   'context' => 'admin',
   'dependencies' => array(),
   'in_footer' => true,
   'defer' => true,
   'localize' => array(
    'objectName' => 'ewpImportExport',
    'data' => array(
     'restUrl' => rest_url('ewp/v1/'),
     'nonce' => wp_create_nonce('wp_rest'),
     'strings' => array(
      'importing' => __('Importing...', 'extend-wp'),
      'exporting' => __('Exporting...', 'extend-wp'),
      'success' => __('Operation completed successfully', 'extend-wp'),
      'error' => __('An error occurred', 'extend-wp'),
      'copied' => __('Copied', 'extend-wp'),
      /* translators: %s: comma separated content type ids. */
      'skipped' => __('Skipped (no PHP export for these content types): %s', 'extend-wp'),
      'noFile' => __('Please upload a file.', 'extend-wp'),
      'invalidJson' => __('Invalid JSON file.', 'extend-wp'),
     )
    )
   )
  );
  $assets[] = array(
   'handle' => 'extend-wp-import-export-style',
   'selector' => $selector,
   'type' => 'style',
   'src' => awm_url . 'assets/css/admin/ewp-import-export.min.css',
   'version' => $version,
   'context' => 'admin',
  );

  return $assets;
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
  $fields = array(
   'case' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Action', 'extend-wp'),
    'case' => 'select',
    'options' => array(
     'export' => array('label' => __('Export', 'extend-wp')),
     'import' => array('label' => __('Import', 'extend-wp')),
     'export_options' => array('label' => __('Export Options', 'extend-wp')),
     'import_options' => array('label' => __('Import Options', 'extend-wp'))
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
     'php' => array('label' => __('PHP', 'extend-wp')),
    ),
    'explanation' => __('JSON can be imported on another site. PHP is code that registers the exported field groups, post types and taxonomies; other content types are skipped.', 'extend-wp'),
    'show-when' => array('case' => array('values' => array('export' => true))),
   ),
   'export_preview' => array(
    'case' => 'html',
    'show-when' => array('case' => array('values' => array('export' => true))),
    'value' => awm_parse_template(awm_path . 'templates/admin-view/export-preview.php', array('title' => __('Export preview', 'extend-wp'))),
    'exclude_meta' => true,
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
   ),
   /* Options Portability fields */
   'option_pages' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Option Pages', 'extend-wp'),
    'case' => 'ewp_option_pages',
    'attributes' => array('multiple' => true),
    'show-when' => array('case' => array('values' => array('export_options' => true))),
   ),
   'options_file' => array(
    'label_class' => array('awm-needed'),
    'label' => __('Options Import File', 'extend-wp'),
    'case' => 'input',
    'type' => 'file',
    'attributes' => array('accept' => '.json'),
    'show-when' => array('case' => array('values' => array('import_options' => true))),
    'explanation' => __('Upload a JSON file exported from EWP Options Portability', 'extend-wp'),
   ),
   'options_import_message' => array(
    'case' => 'html',
    'show-when' => array('case' => array('values' => array('import_options' => true))),
    'value' => '<div class="ewp-options-portability-wrap"><div id="options-import-message"></div></div>',
    'exclude_meta' => true,
   )
  );

  /**
   * Filter the import/export fields to allow other modules to extend them.
   *
   * @param array $fields The current import/export field definitions.
   * @return array Modified fields array.
   *
   * @since 1.1.3
   */
  return apply_filters('ewp_import_export_fields_filter', $fields);
 }
}


new Extend_WP_Import_Export();