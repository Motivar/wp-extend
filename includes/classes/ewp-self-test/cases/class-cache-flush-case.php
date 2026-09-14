<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks that `wp ewp delete-cache` and the ewp-system/flush-cache ability
 * do the same work: both must go through ewp_flush_cache(), which is the
 * only function that fires ewp_flush_cache_pre_action /
 * ewp_flush_cache_action.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Cache_Flush_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Attach counters to ewp_flush_cache_pre_action and ewp_flush_cache_action.', 'extend-wp'),
            __('CLI: run the wp ewp delete-cache handler (WP_CLI_Integration) — expect both hooks to fire once.', 'extend-wp'),
            __('Ability: execute ewp-system/flush-cache — expect both hooks to fire again and {flushed: true}.', 'extend-wp'),
            __('Ability: execute ewp-system/get-site-info — expect the plugin version and content-type list.', 'extend-wp'),
            __('Note: each flush also flushes rewrite rules and the object cache, like a real flush would.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $counts = ['pre' => 0, 'post' => 0];
        $pre    = function () use (&$counts) {
            $counts['pre']++;
        };
        $post   = function () use (&$counts) {
            $counts['post']++;
        };

        add_action('ewp_flush_cache_pre_action', $pre);
        add_action('ewp_flush_cache_action', $post);

        $context = ['observed' => []];

        if ($this->cli_available()) {
            $context['observed']['cli'] = $this->cli(function () {
                $integration = new \WP_CLI_Integration();
                $integration->awm_delete_transient_all();
            });
        }
        $context['observed']['after_cli'] = $counts;

        $context['observed']['ability_flush'] = $this->ability('ewp-system/flush-cache');
        $context['observed']['after_ability'] = $counts;

        $context['observed']['ability_info'] = $this->ability('ewp-system/get-site-info');

        remove_action('ewp_flush_cache_pre_action', $pre);
        remove_action('ewp_flush_cache_action', $post);

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        if (!$this->cli_available()) {
            $checks[] = $this->skip('cli', 'wp ewp delete-cache fires the full flush hooks', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        } else {
            $ok = !empty($o['cli']['ok']) && $o['after_cli']['pre'] === 1 && $o['after_cli']['post'] === 1;
            $checks[] = $this->check('cli', 'wp ewp delete-cache fires ewp_flush_cache_pre_action + ewp_flush_cache_action', $ok, wp_json_encode($o['after_cli']));
        }

        if (!$this->abilities_available()) {
            $checks[] = $this->skip('ability', 'ewp-system/flush-cache fires the full flush hooks', __('Abilities API not available on this site.', 'extend-wp'));
            $checks[] = $this->skip('ability', 'ewp-system/get-site-info reports the plugin version', __('Abilities API not available on this site.', 'extend-wp'));
            return $checks;
        }

        $base   = $this->cli_available() ? 1 : 0;
        $flush  = $o['ability_flush'];
        $fired  = $o['after_ability']['pre'] === $base + 1 && $o['after_ability']['post'] === $base + 1;
        $checks[] = $this->check('ability', 'ewp-system/flush-cache returns {flushed: true} and fires both hooks', !empty($flush['ok']) && !empty($flush['data']['flushed']) && $fired, $flush['error'] ?: wp_json_encode($o['after_ability']));

        $info = $o['ability_info'];
        $checks[] = $this->check('ability', 'ewp-system/get-site-info reports the plugin version', !empty($info['ok']) && isset($info['data']['plugin_version']), $info['error'] ?: (isset($info['data']['plugin_version']) ? 'version ' . $info['data']['plugin_version'] : ''));

        return $checks;
    }
}
