<?php
/**
 * Backwards-compatibility layer for `gnnpls/wp-kit` 0.x consumers.
 *
 * Loaded by Composer after bootstrap.php. Aliases the old `Gnnpls\WP\*`
 * class names lazily; the old `mwp_*` hooks are bridged where they fire.
 * Scheduled for removal in the next major version.
 *
 * @package MammothWP\WpKit
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists('MammothWP\\WpKit\\Compat\\Legacy_Aliases', false)) {
    require_once __DIR__ . '/src/Compat/Legacy_Aliases.php';
}

\MammothWP\WpKit\Compat\Legacy_Aliases::register();
