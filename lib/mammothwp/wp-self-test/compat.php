<?php
/**
 * Backwards-compatibility shims for gnnpls/wp-self-test (pre-1.0).
 *
 * Loaded by Composer (autoload.files). Registers an autoloader that
 * resolves any `Gnnpls\SelfTest\*` class to its `MammothWP\SelfTest\*`
 * replacement with class_alias(), so consumer case classes that still
 * extend `Gnnpls\SelfTest\Case_Base` keep working for one major version.
 * Each old name is reported once per site, not on every request.
 *
 * @package MammothWP\SelfTest
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    return;
}

if (!function_exists('mmwp_self_test_legacy_autoload')) {
    /**
     * Alias an old `Gnnpls\SelfTest\*` class to the new namespace.
     *
     * @param string $class Fully qualified class name being autoloaded.
     *
     * @return void
     *
     * @since 1.0.0
     */
    function mmwp_self_test_legacy_autoload($class)
    {
        $old = 'Gnnpls\\SelfTest\\';
        if (strpos($class, $old) !== 0) {
            return;
        }

        $new = 'MammothWP\\SelfTest\\' . substr($class, strlen($old));
        if (!class_exists($new) && !interface_exists($new) && !trait_exists($new)) {
            return;
        }

        class_alias($new, $class);
        mmwp_self_test_legacy_notice($class, $new);
    }

    /**
     * Report a legacy class name once per site (kept in an option).
     *
     * @param string $old Old class name.
     * @param string $new Replacement class name.
     *
     * @return void
     *
     * @since 1.0.0
     */
    function mmwp_self_test_legacy_notice($old, $new)
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $seen = get_option('mmwp_self_test_legacy_notices', []);
        $seen = is_array($seen) ? $seen : [];
        if (in_array($old, $seen, true)) {
            return;
        }

        $seen[] = $old;
        update_option('mmwp_self_test_legacy_notices', $seen, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error,WordPress.Security.EscapeOutput.OutputNotEscaped -- developer notice for class names, WP_DEBUG only.
            trigger_error(sprintf('%s is deprecated, use %s (mammothwp/wp-self-test).', $old, $new), E_USER_DEPRECATED);
        }
    }

    spl_autoload_register('mmwp_self_test_legacy_autoload');
}
