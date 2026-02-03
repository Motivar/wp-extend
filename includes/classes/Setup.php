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
  add_action('init', function () {
   load_plugin_textdomain('extend-wp', false, awm_relative_path . '/languages');
  });
  require_once 'adminMessages/class-adminMessages.php';
  require_once 'gallery-meta-box/gallery-meta-box.php';
  require_once 'ewp-fields/class-field.php';
  require_once 'ewp-wp-content/class-wp-content.php';
  require_once 'ewp-wp-content/class-wp-content-installer.php';
  require_once 'ewp-search-filter/class-wp-search.php';
  require_once 'awm-api/class-awm-api.php';
  require_once 'awm-api/class-awm-dynamic-api.php';
  require_once 'awm-content-db-api/init.php';
  require_once 'class-extend-wp.php';
  require_once 'awm-db/class-db-creator.php';
  require_once 'awm-list-tables/class-list-table.php';
  require_once 'awm-customizer/class-customizer.php';
  require_once 'ewp-third-party/class-wpml.php';
  require_once 'ewp-gutenburg/class-register.php';
  require_once 'wp-cli/class-cli-commands.php';
  require_once 'dev-tools/init.php';
  require_once 'class-dynamic-asset-loader.php';
  
  // Initialize template system
  \EWP\TemplateSystem\AWM_Template_System::init();
 }
}