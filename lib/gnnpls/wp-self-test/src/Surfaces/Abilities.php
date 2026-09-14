<?php
/**
 * mwp-self-test/* abilities: the same Runner exposed to the WordPress
 * Abilities API (core 6.9+) so an AI client can list, preview, run, clean
 * up and read the report. run and cleanup are destructive and require
 * `confirm: true`.
 *
 * Self-contained on purpose: the package must not depend on any plugin's
 * ability helpers.
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */

namespace Gnnpls\SelfTest\Surfaces;

use Gnnpls\SelfTest\Config;
use Gnnpls\SelfTest\Runner;

if (!defined('ABSPATH')) {
    exit;
}

final class Abilities
{
    const CATEGORY = 'mwp-self-test';

    /** @var Runner */
    private static $runner;

    /**
     * Hook registration when the Abilities API exists.
     *
     * @param Runner $runner Shared runner.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public static function init(Runner $runner)
    {
        if (!function_exists('wp_register_ability') || !function_exists('wp_register_ability_category')) {
            return;
        }

        self::$runner = $runner;
        add_action('wp_abilities_api_categories_init', [__CLASS__, 'register_category']);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities']);
    }

    /** @return void */
    public static function register_category()
    {
        if (function_exists('wp_has_ability_category') && wp_has_ability_category(self::CATEGORY)) {
            return;
        }

        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Self-test', Config::TEXT_DOMAIN),
            'description' => __('Run the registered self-test suites: every REST route, WP-CLI command and ability a plugin exposes, exercised on this site with test data that can be removed afterwards.', Config::TEXT_DOMAIN),
        ]);
    }

    /** @return void */
    public static function register_abilities()
    {
        /**
         * Filter the self-test ability definitions before registration.
         *
         * @param array<string,array> $definitions Definitions keyed by ability name.
         *
         * @since 0.1.0
         */
        $definitions = apply_filters('mwp_self_test_ability_definitions', self::definitions());

        foreach ($definitions as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * @return array<string,array>
     */
    private static function definitions()
    {
        $ids = [
            'ids'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => __('Case ids from the registered manifests. Omit for every case.', Config::TEXT_DOMAIN)],
            'plugin' => ['type' => 'string', 'description' => __('Restrict to the cases registered by one plugin slug.', Config::TEXT_DOMAIN)],
        ];
        $confirm = ['confirm' => ['type' => 'boolean', 'description' => __('Must be true. Guards against accidental destructive calls.', Config::TEXT_DOMAIN)]];
        $cases   = ['type' => 'object', 'properties' => ['cases' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]]];
        $any     = ['type' => 'object', 'additionalProperties' => true];

        return [
            self::CATEGORY . '/list-cases' => self::definition(
                __('List self-test cases', Config::TEXT_DOMAIN),
                __('The cases every registered plugin declares, with their plugin, category, the surfaces (rest/cli/ability) they cover, whether they can run on this site and whether an earlier run left test data behind.', Config::TEXT_DOMAIN),
                ['type' => 'object', 'properties' => $ids],
                $cases,
                'run_list_cases',
                true
            ),
            self::CATEGORY . '/preview' => self::definition(
                __('Preview self-test steps', Config::TEXT_DOMAIN),
                __('The ordered steps each case would perform, without running anything.', Config::TEXT_DOMAIN),
                ['type' => 'object', 'properties' => $ids],
                $cases,
                'run_preview',
                true
            ),
            self::CATEGORY . '/run' => self::definition(
                __('Run self-tests', Config::TEXT_DOMAIN),
                __('Run the given cases (default: all), validate every surface and store the report. Creates test data on this site unless cleanup is true; pass confirm: true.', Config::TEXT_DOMAIN),
                ['type' => 'object', 'properties' => $ids + ['cleanup' => ['type' => 'boolean', 'description' => __('Remove the test data right after each case.', Config::TEXT_DOMAIN)]] + $confirm, 'required' => ['confirm']],
                $any,
                'run_run',
                false
            ),
            self::CATEGORY . '/cleanup' => self::definition(
                __('Remove self-test data', Config::TEXT_DOMAIN),
                __('Delete the test data left by earlier runs (default: every case with pending data). Pass confirm: true.', Config::TEXT_DOMAIN),
                ['type' => 'object', 'properties' => $ids + $confirm, 'required' => ['confirm']],
                $cases,
                'run_cleanup',
                false,
                true
            ),
            self::CATEGORY . '/get-report' => self::definition(
                __('Get the last self-test report', Config::TEXT_DOMAIN),
                __('The report stored by the most recent run: summary counts and per-case checks.', Config::TEXT_DOMAIN),
                ['type' => 'object', 'properties' => []],
                $any,
                'run_get_report',
                true
            ),
        ];
    }

    /**
     * @param string $label       Label.
     * @param string $description Description.
     * @param array  $input       Input schema.
     * @param array  $output      Output schema.
     * @param string $handler     Static method name.
     * @param bool   $readonly    Whether the ability only reads.
     * @param bool   $destructive Whether the ability deletes data.
     *
     * @return array wp_register_ability() arguments.
     */
    private static function definition($label, $description, array $input, array $output, $handler, $readonly, $destructive = false)
    {
        return [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => $input,
            'output_schema'       => $output,
            'execute_callback'    => [__CLASS__, $handler],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'meta'                => [
                'annotations'  => ['readonly' => $readonly, 'idempotent' => $readonly, 'destructive' => $destructive],
                'show_in_rest' => true,
            ],
        ];
    }

    /** @return true|\WP_Error */
    public static function check_permission()
    {
        if (current_user_can(Config::capability())) {
            return true;
        }

        return new \WP_Error('mwp_self_test_forbidden', __('You are not allowed to run the self-tests.', Config::TEXT_DOMAIN), ['status' => is_user_logged_in() ? 403 : 401]);
    }

    public static function run_list_cases($input = null)
    {
        return self::wrap(['cases' => self::$runner->cases(self::ids($input), self::plugin($input))]);
    }

    public static function run_preview($input = null)
    {
        return self::wrap(['cases' => self::$runner->preview(self::ids($input), self::plugin($input))]);
    }

    public static function run_run($input = null)
    {
        $input     = self::normalize($input);
        $confirmed = self::require_confirm($input);
        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return self::$runner->run(self::ids($input), !empty($input['cleanup']), self::plugin($input));
    }

    public static function run_cleanup($input = null)
    {
        $input     = self::normalize($input);
        $confirmed = self::require_confirm($input);
        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return self::wrap(['cases' => self::$runner->cleanup(self::ids($input))]);
    }

    public static function run_get_report($input = null)
    {
        unset($input);
        $report = self::$runner->report();

        return $report ?: ['results' => [], 'summary' => null, 'pending' => self::$runner->pending_ids()];
    }

    /* ------------------------------------------------------------------ */

    private static function normalize($input)
    {
        if (is_object($input)) {
            $input = get_object_vars($input);
        }

        return is_array($input) ? $input : [];
    }

    private static function ids($input)
    {
        $input = self::normalize($input);

        return isset($input['ids']) ? array_values(array_filter(array_map('sanitize_key', (array) $input['ids']))) : [];
    }

    private static function plugin($input)
    {
        $input = self::normalize($input);

        return isset($input['plugin']) ? sanitize_key((string) $input['plugin']) : '';
    }

    private static function require_confirm(array $input)
    {
        if (!empty($input['confirm']) && $input['confirm'] !== 'false') {
            return true;
        }

        return new \WP_Error('mwp_self_test_confirm_required', __('This action changes data on the site. Pass confirm: true to proceed.', Config::TEXT_DOMAIN), ['status' => 400]);
    }

    /** Pass a WP_Error through untouched, otherwise return the payload. */
    private static function wrap(array $payload)
    {
        foreach ($payload as $value) {
            if (is_wp_error($value)) {
                return $value;
            }
        }

        return $payload;
    }
}
