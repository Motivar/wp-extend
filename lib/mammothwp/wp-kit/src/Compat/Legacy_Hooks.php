<?php
/**
 * Bridges the pre-1.0 `mwp_*` hooks onto their `mmwp_kit_*` replacements.
 *
 * @package MammothWP\WpKit
 * @since   1.0.0
 */

namespace MammothWP\WpKit\Compat;

if (!defined('ABSPATH')) {
    exit;
}

final class Legacy_Hooks
{
    /**
     * Version in which the old hook names were deprecated.
     *
     * @var string
     */
    const DEPRECATED_IN = '1.0.0';

    /**
     * Old hook name for each new hook name.
     *
     * @var array<string,string>
     */
    const MAP = [
        'mmwp_kit_booted'                => 'mwp_kit_booted',
        'mmwp_kit_registry_ready'        => 'mwp_registry_ready',
        'mmwp_kit_operation_capability'  => 'mwp_operation_capability',
        'mmwp_kit_operation_before_run'  => 'mwp_operation_before_run',
        'mmwp_kit_operation_result'      => 'mwp_operation_result',
        'mmwp_kit_ability_definitions'   => 'mwp_ability_definitions',
    ];

    /**
     * Old name of a new hook, or null when the hook is new in 1.0.0.
     *
     * @param string $new_hook New hook name.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public static function old_name($new_hook)
    {
        return isset(self::MAP[$new_hook]) ? self::MAP[$new_hook] : null;
    }

    /**
     * Fire the deprecated twin of an action. Notices only when something listens to it.
     *
     * @param string $new_hook New action name (already fired by the caller).
     * @param array  $args     Action arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function action($new_hook, array $args)
    {
        $old = self::old_name($new_hook);
        if ($old === null || !function_exists('do_action_deprecated')) {
            return;
        }

        do_action_deprecated($old, $args, self::DEPRECATED_IN, $new_hook);
    }

    /**
     * Run the deprecated twin of a filter over a value. Notices only when something listens to it.
     *
     * @param string $new_hook New filter name.
     * @param array  $args     Filter arguments; the first one is the filtered value.
     *
     * @return mixed The (possibly changed) first argument.
     *
     * @since 1.0.0
     */
    public static function filter($new_hook, array $args)
    {
        $old = self::old_name($new_hook);
        if ($old === null || !function_exists('apply_filters_deprecated')) {
            return $args[0];
        }

        return apply_filters_deprecated($old, $args, self::DEPRECATED_IN, $new_hook);
    }
}
