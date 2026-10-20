<?php
/**
 * Fires a hook under its `mmwp_` name and bridges the deprecated `mwp_` name.
 *
 * @package MammothWP\SelfTest
 * @since   1.0.0
 */

namespace MammothWP\SelfTest\Compat;

if (!defined('ABSPATH')) {
    exit;
}

final class Hooks
{
    /** Version in which the old hook names are announced as deprecated. */
    const SINCE = '1.0.0';

    /**
     * Old hook name for a new `mmwp_self_test_*` name.
     *
     * @param string $new New hook name.
     *
     * @return string Old name (`mwp_self_test_*`).
     *
     * @since 1.0.0
     */
    public static function legacy_name($new)
    {
        return preg_replace('/^mmwp_/', 'mwp_', (string) $new);
    }

    /**
     * Fire an action under the new name, then under the deprecated one.
     *
     * Listeners on the old name still run; WordPress logs a deprecation
     * notice only when at least one is attached.
     *
     * @param string $new  New hook name.
     * @param array  $args Arguments passed to the listeners.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function action($new, array $args = [])
    {
        do_action_ref_array($new, $args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass mmwp_ names.
        do_action_deprecated(self::legacy_name($new), $args, self::SINCE, $new);
    }

    /**
     * Apply a filter under the new name, then under the deprecated one.
     *
     * @param string $new   New hook name.
     * @param mixed  $value Value to filter.
     * @param array  $args  Extra arguments passed to the callbacks.
     *
     * @return mixed Filtered value.
     *
     * @since 1.0.0
     */
    public static function filter($new, $value, array $args = [])
    {
        $value = apply_filters_ref_array($new, array_merge([$value], $args)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass mmwp_ names.

        return apply_filters_deprecated(self::legacy_name($new), array_merge([$value], $args), self::SINCE, $new);
    }
}
