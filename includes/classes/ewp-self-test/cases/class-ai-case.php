<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abilities API registration and AI readiness.
 *
 * Verifies every ewp-* ability category the plugin declares is registered
 * with core and counts the abilities, then reports whether an AI provider
 * is configured (the same check the log viewer's "Diagnose" box uses). The
 * AI checks are reported as skipped — not failed — when no provider is
 * configured, and no paid AI completion is ever requested.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Ai_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function availability()
    {
        if (!class_exists('EWP\\Abilities\\EWP_Abilities') || !\EWP\Abilities\EWP_Abilities::is_supported()) {
            $reason = class_exists('EWP\\Abilities\\EWP_Abilities') ? \EWP\Abilities\EWP_Abilities::get_unsupported_reason() : '';

            return ['available' => false, 'reason' => $reason ?: __('The WordPress Abilities API (6.9+) is not available on this site.', 'extend-wp')];
        }

        return ['available' => true, 'reason' => ''];
    }

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Confirm the Abilities API is supported and Extend WP abilities are enabled.', 'extend-wp'),
            sprintf(__('Confirm each expected category is registered with core: %s.', 'extend-wp'), implode(', ', $this->expected_categories())),
            __('Count every registered ewp-* ability.', 'extend-wp'),
            __('Check wp_supports_ai() and whether an AI provider has credentials configured (EWP_Logger_Diagnose::is_available()).', 'extend-wp'),
            __('If a provider is configured: confirm the POST /extend-wp/v1/logs/diagnose route is registered. No AI request is sent (it would cost tokens).', 'extend-wp'),
            __('No data is created; nothing to clean up.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $o = [
            'enabled'    => \EWP\Abilities\EWP_Abilities::is_enabled(),
            'categories' => [],
            'abilities'  => [],
        ];

        foreach ($this->expected_categories() as $slug) {
            $o['categories'][$slug] = function_exists('wp_has_ability_category') && wp_has_ability_category($slug);
        }

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = is_object($ability) && method_exists($ability, 'get_name') ? $ability->get_name() : '';
                if (strpos($name, 'ewp-') === 0) {
                    $o['abilities'][] = $name;
                }
            }
        }

        $o['wp_supports_ai']      = function_exists('wp_supports_ai') && wp_supports_ai();
        $o['provider_configured'] = class_exists('EWP\\Logger\\EWP_Logger_Diagnose') && \EWP\Logger\EWP_Logger_Diagnose::is_available();
        $o['diagnose_route']      = array_key_exists('/extend-wp/v1/logs/diagnose', rest_get_server()->get_routes());

        return ['observed' => $o];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        $checks[] = $this->check('ability', 'Extend WP abilities are enabled (ewp_abilities_enabled)', !empty($o['enabled']));

        foreach ($o['categories'] as $slug => $present) {
            $checks[] = $this->check('ability', sprintf('Category %s is registered with core', $slug), (bool) $present);
        }

        $count = count($o['abilities']);
        $checks[] = $this->check('ability', 'ewp-* abilities are registered', $count > 0, sprintf(__('%d abilities', 'extend-wp'), $count));

        if (empty($o['wp_supports_ai'])) {
            $checks[] = $this->skip('ai', 'AI provider configured', __('wp_supports_ai() is false — the WordPress AI client is not available on this site.', 'extend-wp'));
            return $checks;
        }

        if (empty($o['provider_configured'])) {
            $checks[] = $this->skip('ai', 'AI provider configured', __('No AI provider has credentials configured; the log Diagnose feature and AI-driven ability calls cannot be exercised here.', 'extend-wp'));
            return $checks;
        }

        $checks[] = $this->check('ai', 'AI provider configured', true, __('a provider with credentials is available', 'extend-wp'));
        $checks[] = $this->check('rest', 'POST /extend-wp/v1/logs/diagnose is registered', !empty($o['diagnose_route']));

        return $checks;
    }

    /** @return string[] */
    private function expected_categories()
    {
        $args = $this->args();

        return isset($args['expected_categories']) ? (array) $args['expected_categories'] : [];
    }
}
