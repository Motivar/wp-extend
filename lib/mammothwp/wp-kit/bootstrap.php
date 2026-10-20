<?php
/**
 * Mammoth WP Kit bootstrap.
 *
 * The only file a consumer ever requires. It is safe to include from several
 * active plugins: each include registers its own copy of the kit, and the
 * newest copy boots on `plugins_loaded` while the others stay dormant. That
 * avoids the "first Composer autoloader wins" problem that arises when
 * multiple plugins commit their vendor directories.
 *
 * Outside WordPress this file is a no-op.
 *
 * @package MammothWP\WpKit
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists('MammothWP\\WpKit\\Kit', false)) {
    require __DIR__ . '/src/Kit.php';
}

/*
 * An older, incompatible copy of the loader may already be declared by
 * another plugin. Never fatal on that; the newest compatible copy wins.
 */
if (!method_exists('MammothWP\\WpKit\\Kit', 'register')) {
    return;
}

$mmwp_kit_version = require __DIR__ . '/version.php';

\MammothWP\WpKit\Kit::register((string) $mmwp_kit_version, __DIR__);

unset($mmwp_kit_version);
