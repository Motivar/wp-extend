<?php
/*
Plugin Name: Extend WP
Plugin URI: https://motivar.io
Description: extend WP in various ways with simple UI
Version: 1.1.2
Author: Giannopoulos Nikolaos
Text Domain:       extend-wp
*/

if (!defined('WPINC')) {
    die;
}


define('awm_path', plugin_dir_path(__FILE__));
define('awm_url', plugin_dir_url(__FILE__));
define('awm_relative_path', dirname(plugin_basename(__FILE__)));
define('EWP_USERS_VERSION', 0.1);

/** Asset version — sourced from build/version.php which webpack generates from package.json. */
$_awm_version_file = plugin_dir_path(__FILE__) . 'build/version.php';
define('AWM_ASSET_VERSION', file_exists($_awm_version_file) ? require $_awm_version_file : '1.0.0');
unset($_awm_version_file);

require_once(plugin_dir_path(__FILE__) . '/lib/autoload.php');

new \EWP\Setup();
