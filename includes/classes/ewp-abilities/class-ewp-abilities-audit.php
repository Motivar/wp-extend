<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Record every Extend WP write ability in the activity log.
 *
 * Hooking the core result filter once keeps auditing out of the individual
 * handlers and also covers abilities added through the definition filters.
 * Content writes are already logged by the logger's own content hooks; this
 * entry says an AI agent made the change and shares its request id.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Audit
{
    /**
     * Maximum serialized input length stored with an entry.
     *
     * @var int
     */
    const MAX_INPUT_LENGTH = 2000;

    /**
     * Ability that must not audit itself.
     *
     * @var string
     */
    const SKIP_ABILITY = 'ewp-logger/write-entry';

    /**
     * Register hooks.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function init()
    {
        add_filter('wp_ability_execute_result', [$this, 'record'], 10, 4);
    }

    /**
     * Log the result of a write ability, then return it untouched.
     *
     * @param mixed  $result       Result or WP_Error from the ability.
     * @param string $ability_name Ability name.
     * @param mixed  $input        Normalised input.
     * @param mixed  $ability      Ability instance.
     *
     * @return mixed The unmodified result.
     *
     * @since 1.4.0
     */
    public function record($result, $ability_name, $input = null, $ability = null)
    {
        if (!$this->should_record($ability_name, $ability)) {
            return $result;
        }

        $is_error = is_wp_error($result);

        ewp_log(
            EWP_Abilities::LOG_OWNER,
            EWP_Abilities::LOG_ACTION_TYPE,
            sprintf(
                /* translators: 1: ability name, 2: outcome. */
                __('Ability %1$s executed (%2$s)', 'extend-wp'),
                $ability_name,
                $is_error ? __('failed', 'extend-wp') : __('succeeded', 'extend-wp')
            ),
            [
                'ability' => (string) $ability_name,
                'input'   => $this->summarize_input($input),
                'result'  => $this->summarize_result($result, $is_error),
            ],
            'developer',
            'ability',
            $is_error ? 0 : 1
        );

        return $result;
    }

    /**
     * Whether this execution should be recorded.
     *
     * @param string $ability_name Ability name.
     * @param mixed  $ability      Ability instance.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    private function should_record($ability_name, $ability)
    {
        if (!is_string($ability_name) || strpos($ability_name, 'ewp-') !== 0) {
            return false;
        }

        if ($ability_name === self::SKIP_ABILITY) {
            return false;
        }

        if (!function_exists('ewp_log') || !class_exists('EWP\Logger\EWP_Logger')) {
            return false;
        }

        if (!\EWP\Logger\EWP_Logger::is_enabled()) {
            return false;
        }

        if ($this->is_readonly($ability)) {
            return false;
        }

        /**
         * Filter whether an ability execution is written to the activity log.
         *
         * @param bool   $record       Default true for write abilities.
         * @param string $ability_name The ability name.
         *
         * @since 1.4.0
         */
        return (bool) apply_filters('ewp_abilities_audit_enabled', true, $ability_name);
    }

    /**
     * Whether the ability declares itself read-only.
     *
     * @param mixed $ability Ability instance.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    private function is_readonly($ability)
    {
        if (!is_object($ability) || !method_exists($ability, 'get_meta')) {
            return false;
        }

        $meta = $ability->get_meta();

        return !empty($meta['annotations']['readonly']);
    }

    /**
     * Reduce the input to something safe and small enough to store.
     *
     * @param mixed $input Ability input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    private function summarize_input($input)
    {
        if (!is_array($input)) {
            return [];
        }

        if (class_exists('EWP\Logger\EWP_Logger')) {
            $input = \EWP\Logger\EWP_Logger::filter_wp_noise($input);
        }

        $encoded = wp_json_encode($input);

        if (is_string($encoded) && strlen($encoded) > self::MAX_INPUT_LENGTH) {
            return [
                'truncated' => true,
                'keys'      => array_keys($input),
                'preview'   => substr($encoded, 0, self::MAX_INPUT_LENGTH),
            ];
        }

        return $input;
    }

    /**
     * Reduce the result to an outcome summary.
     *
     * @param mixed $result   Ability result.
     * @param bool  $is_error Whether the result is a WP_Error.
     *
     * @return array
     *
     * @since 1.4.0
     */
    private function summarize_result($result, $is_error)
    {
        if ($is_error) {
            return [
                'error_code'    => $result->get_error_code(),
                'error_message' => $result->get_error_message(),
            ];
        }

        $summary = ['ok' => true];

        if (is_array($result)) {
            foreach (['id', 'count', 'deleted', 'flushed', 'logged'] as $key) {
                if (isset($result[$key])) {
                    $summary[$key] = $result[$key];
                }
            }
        }

        return $summary;
    }
}
