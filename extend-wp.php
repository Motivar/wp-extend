<?php
/*
Plugin Name: Extend WP
Plugin URI: https://motivar.io
Description: extend WP in various ways with simple UI
Version: 1.15.0
Requires at least: 6.2
Author: Giannopoulos Nikolaos
Text Domain:       extend-wp
*/

if (!defined('WPINC')) {
    die;
}


/*
 * Own-location constants. Another copy of this plugin (e.g. one bundled in a theme or another plugin)
 * may already have defined awm_path / awm_url pointing at ITS files, so everything this copy loads
 * (templates, build, assets) resolves from these instead. Kept for add-ons: awm_path / awm_url.
 */
if (!defined('EWP_PLUGIN_DIR')) {
    define('EWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('EWP_PLUGIN_URL')) {
    define('EWP_PLUGIN_URL', plugin_dir_url(__FILE__));
}
// Guarded: when another copy loaded first these keep ITS values (as they effectively did before), without a redefinition warning.
if (!defined('awm_path')) {
    define('awm_path', plugin_dir_path(__FILE__));
}
if (!defined('awm_url')) {
    define('awm_url', plugin_dir_url(__FILE__));
}
if (!defined('awm_relative_path')) {
    define('awm_relative_path', dirname(plugin_basename(__FILE__)));
}
define('EWP_USERS_VERSION', 0.1);

/** Asset version — sourced from build/version.php which webpack generates from package.json. */
$_awm_version_file = plugin_dir_path(__FILE__) . 'build/version.php';
define('AWM_ASSET_VERSION', file_exists($_awm_version_file) ? require $_awm_version_file : '1.0.0');
unset($_awm_version_file);

require_once(plugin_dir_path(__FILE__) . '/lib/autoload.php');

new \EWP\Setup();
