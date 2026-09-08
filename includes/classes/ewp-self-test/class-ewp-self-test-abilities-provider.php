<?php

namespace EWP\SelfTest;

use EWP\Abilities\EWP_Abilities_Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ewp-self-test/* abilities — the same runner exposed to the Abilities API
 * so an AI client can list, preview, run, clean up and read the report.
 *
 * Registered through the `ewp_abilities_providers` filter; run and cleanup
 * are destructive and require `confirm: true` like every other write
 * ability in the plugin.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test_Abilities_Provider extends EWP_Abilities_Provider
{
    const CATEGORY = 'ewp-self-test';

    /** @var EWP_Self_Test_Runner */
    private $runner;

    public function __construct(EWP_Self_Test_Runner $runner)
    {
        $this->runner = $runner;
    }

    /** {@inheritDoc} */
    public function category()
    {
        return self::CATEGORY;
    }

    /** {@inheritDoc} */
    public function category_args()
    {
        return [
            'label'       => __('EWP Self-test', 'extend-wp'),
            'description' => __('Run the Extend WP self-test suite: every REST route, WP-CLI command and ability, exercised on this site with test data that can be removed afterwards.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function get_definitions()
    {
        $capability = EWP_Self_Test::capability();
        $ids        = [
            'ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => __('Case ids from manifest.json. Omit for every case.', 'extend-wp'),
            ],
        ];

        return [
            self::CATEGORY . '/list-cases' => $this->definition(
                __('List self-test cases', 'extend-wp'),
                __('The cases in the self-test manifest with their category, the surfaces (rest/cli/ability) they cover, whether they can run on this site and whether an earlier run left test data behind.', 'extend-wp'),
                ['type' => 'object', 'properties' => $ids],
                ['type' => 'object', 'properties' => ['cases' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]]],
                [$this, 'run_list_cases'],
                $this->capability_permission($capability, self::CATEGORY . '/list-cases'),
                $this->meta_readonly()
            ),
            self::CATEGORY . '/preview' => $this->definition(
                __('Preview self-test steps', 'extend-wp'),
                __('The ordered steps each case would perform, without running anything.', 'extend-wp'),
                ['type' => 'object', 'properties' => $ids],
                ['type' => 'object', 'properties' => ['cases' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]]],
                [$this, 'run_preview'],
                $this->capability_permission($capability, self::CATEGORY . '/preview'),
                $this->meta_readonly()
            ),
            self::CATEGORY . '/run' => $this->definition(
                __('Run self-tests', 'extend-wp'),
                __('Run the given cases (default: all), validate every surface and store the report. Creates test data on this site unless cleanup is true; pass confirm: true.', 'extend-wp'),
                [
                    'type'       => 'object',
                    'properties' => $ids + [
                        'cleanup' => ['type' => 'boolean', 'description' => __('Remove the test data right after each case.', 'extend-wp')],
                    ] + $this->confirm_property(),
                    'required'   => ['confirm'],
                ],
                ['type' => 'object', 'additionalProperties' => true],
                [$this, 'run_run'],
                $this->capability_permission($capability, self::CATEGORY . '/run'),
                $this->meta_write(false)
            ),
            self::CATEGORY . '/cleanup' => $this->definition(
                __('Remove self-test data', 'extend-wp'),
                __('Delete the test data left by earlier runs (default: every case with pending data). Pass confirm: true.', 'extend-wp'),
                [
                    'type'       => 'object',
                    'properties' => $ids + $this->confirm_property(),
                    'required'   => ['confirm'],
                ],
                ['type' => 'object', 'properties' => ['cases' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]]],
                [$this, 'run_cleanup'],
                $this->capability_permission($capability, self::CATEGORY . '/cleanup'),
                $this->meta_destructive()
            ),
            self::CATEGORY . '/get-report' => $this->definition(
                __('Get the last self-test report', 'extend-wp'),
                __('The report stored by the most recent run: summary counts and per-case checks.', 'extend-wp'),
                ['type' => 'object', 'properties' => []],
                ['type' => 'object', 'additionalProperties' => true],
                [$this, 'run_get_report'],
                $this->capability_permission($capability, self::CATEGORY . '/get-report'),
                $this->meta_readonly()
            ),
        ];
    }

    public function run_list_cases($input = null)
    {
        return $this->wrap(['cases' => $this->runner->cases($this->ids($input))]);
    }

    public function run_preview($input = null)
    {
        return $this->wrap(['cases' => $this->runner->preview($this->ids($input))]);
    }

    public function run_run($input = null)
    {
        $input     = $this->normalize_input($input);
        $confirmed = $this->require_confirm($input);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return $this->runner->run($this->ids($input), !empty($input['cleanup']));
    }

    public function run_cleanup($input = null)
    {
        $input     = $this->normalize_input($input);
        $confirmed = $this->require_confirm($input);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return $this->wrap(['cases' => $this->runner->cleanup($this->ids($input))]);
    }

    public function run_get_report($input = null)
    {
        unset($input);

        $report = $this->runner->report();

        return $report ?: ['results' => [], 'summary' => null, 'pending' => $this->runner->pending_ids()];
    }

    /* ------------------------------------------------------------------ */

    private function ids($input)
    {
        $input = $this->normalize_input($input);

        return isset($input['ids']) ? array_values(array_filter(array_map('sanitize_key', (array) $input['ids']))) : [];
    }

    /** Pass WP_Error through untouched, otherwise return the payload. */
    private function wrap(array $payload)
    {
        foreach ($payload as $value) {
            if (is_wp_error($value)) {
                return $value;
            }
        }

        return $payload;
    }
}
