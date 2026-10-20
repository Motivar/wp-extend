<?php
/**
 * wp-self-test bootstrap.
 *
 * The only file a consumer ever requires (Composer's autoload.files does
 * it). Safe to include from several active plugins that each bundle a copy:
 * every include registers its copy, and the newest boots on
 * `plugins_loaded`. Outside WordPress this file is a no-op.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists('MammothWP\\SelfTest\\Loader', false)) {
    require __DIR__ . '/src/Loader.php';
}

if (!method_exists('MammothWP\\SelfTest\\Loader', 'register')) {
    return;
}

$mmwp_self_test_version = require __DIR__ . '/version.php';

\MammothWP\SelfTest\Loader::register((string) $mmwp_self_test_version, __DIR__);

unset($mmwp_self_test_version);
