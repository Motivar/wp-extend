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

require_once(plugin_dir_path(__FILE__) . '/lib/autoload.php');

new \EWP\Setup();
