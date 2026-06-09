<?php

namespace EWP;

/**
 * create the class to add all libraries so we can use them in the plugin
 *
 * @see         link
 *
 * @author      Motivar
 *
 * @version     1.0.0
 */
if (!defined('ABSPATH')) {
 exit;
}

class Setup
{
 public function __construct()
 {
  // Register encryption hooks for meta and options
  add_filter('update_post_meta', 'awm_encrypt_meta_on_save', 10, 4);
  add_filter('update_user_meta', 'awm_encrypt_meta_on_save', 10, 4);
  add_filter('update_term_meta', 'awm_encrypt_meta_on_save', 10, 4);
  add_action('updated_option', 'awm_encrypt_option_after_save', 10, 3);

  add_action('init', function () {
   load_plugin_textdomain('extend-wp', false, awm_relative_path . '/languages');
  });

  // Initialize the EWP Logger system early (priority 0) so viewer can register
  // assets before Dynamic Asset Loader collects them (priority 1)
  add_action('init', function () {
   $logger = \EWP\Logger\EWP_Logger::instance();
   $logger->init();
  }, 0);
  require_once 'adminMessages/class-adminMessages.php';
  require_once 'ewp-gallery/class-ewp-gallery.php';
  require_once 'ewp-fields/class-field.php';
  require_once 'ewp-wp-content/class-wp-content.php';
  require_once 'ewp-wp-content/class-slug-manager.php';
  require_once 'ewp-wp-content/class-wp-content-installer.php';
  require_once 'ewp-search-filter/class-wp-search.php';
  require_once 'awm-api/class-awm-api.php';
  require_once 'awm-api/class-awm-dynamic-api.php';
  require_once 'awm-api/class-awm-object-search-api.php';
  require_once 'awm-content-db-api/init.php';
  require_once 'class-extend-wp.php';
  require_once 'awm-db/class-db-creator.php';
  require_once 'awm-list-tables/class-list-table.php';
  require_once 'awm-customizer/class-customizer.php';
  require_once 'ewp-third-party/class-wpml.php';
  require_once 'ewp-third-party/class-wp-rocket.php';
  require_once 'ewp-gutenburg/class-register.php';
  require_once 'wp-cli/class-cli-commands.php';
  require_once 'dev-tools/init.php';
  require_once 'class-dynamic-asset-loader.php';
  require_once 'ewp-logger/class-ewp-logger.php';
  require_once 'ewp-options-portability/class-options-portability.php';
  require_once 'ewp-ai-content/class-ewp-ai-content.php';
  require_once 'ewp-rest-health/class-ewp-rest-health-discovery.php';
  require_once 'ewp-rest-health/class-ewp-rest-health-runner.php';
  require_once 'ewp-rest-health/class-ewp-rest-health-openapi.php';
  require_once 'ewp-rest-health/class-ewp-rest-health-controller.php';
  require_once 'ewp-rest-health/class-ewp-rest-health.php';
 }
}