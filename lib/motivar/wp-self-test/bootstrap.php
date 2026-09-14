<?php
/**
 * Motivar WP self-test bootstrap.
 *
 * The only file a consumer ever requires (Composer's autoload.files does
 * it). Safe to include from several active plugins that each bundle a copy:
 * every include registers its copy, and the newest boots on
 * `plugins_loaded`. Outside WordPress this file is a no-op.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists('Motivar\\SelfTest\\Loader', false)) {
    require __DIR__ . '/src/Loader.php';
}

if (!method_exists('Motivar\\SelfTest\\Loader', 'register')) {
    return;
}

$mwp_self_test_version = require __DIR__ . '/version.php';

\Motivar\SelfTest\Loader::register((string) $mwp_self_test_version, __DIR__);

unset($mwp_self_test_version);
