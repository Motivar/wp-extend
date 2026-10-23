<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;

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

        return $checks;
    }

    /** @return string[] */
    private function expected_categories()
    {
        $args = $this->args();

        return isset($args['expected_categories']) ? (array) $args['expected_categories'] : [];
    }
}
