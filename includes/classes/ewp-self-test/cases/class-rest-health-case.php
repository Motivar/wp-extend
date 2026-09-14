<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;
use EWP\Surfaces\EWP_Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The REST route inventory must know this plugin on every surface.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Rest_Health_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('REST: GET /extend-wp/v1/rest-health/plugins — expect 200 and a row for wp-extend.', 'extend-wp'),
            __('REST: POST /extend-wp/v1/rest-health/endpoints with the wp-extend path — expect its routes.', 'extend-wp'),
            __('CLI: wp ewp rest-health plugins and wp ewp rest-health endpoints --plugins=<path>.', 'extend-wp'),
            __('Ability: ewp-rest-health/list-plugins and ewp-rest-health/list-endpoints.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $context = ['observed' => [], 'activated' => false, 'basename' => $this->plugin_basename()];
        $o       = &$context['observed'];


        /*
         * The inventory reads the active_plugins option. On a test database
         * nothing is active even though this plugin is loaded, so list it
         * for the duration of the case (and only when it is a real
         * top-level plugin, never when it runs bundled inside another one).
         */
        $context['activated'] = $this->ensure_listed_active($context['basename']);
        if ($context['basename'] === '') {
            return $context;
        }

        $o['rest_plugins']    = $this->rest('GET', '/extend-wp/v1/rest-health/plugins', ['refresh' => true]);
        $o['ability_plugins'] = $this->ability('ewp-rest-health/list-plugins');
        $o['cli_plugins']     = $this->cli(EWP_Surfaces::cli('rest-health', 'plugins'), [], ['format' => 'json']);

        $path = $this->plugin_path($o['rest_plugins']['data']);
        $context['path'] = $path;

        $o['rest_endpoints']    = $path ? $this->rest('POST', '/extend-wp/v1/rest-health/endpoints', ['plugins' => [$path]]) : null;
        $o['ability_endpoints'] = $path ? $this->ability('ewp-rest-health/list-endpoints', ['plugins' => [$path]]) : null;
        $o['cli_endpoints']     = $path ? $this->cli(EWP_Surfaces::cli('rest-health', 'endpoints'), [], ['plugins' => $path, 'format' => 'json']) : null;

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o    = $context['observed'];
        $path = isset($context['path']) ? $context['path'] : '';

        if (empty($context['basename'])) {
            $reason = __('Extend WP runs bundled inside another plugin here, so it is not a plugin the inventory can list.', 'extend-wp');
            return [
                $this->skip('rest', 'GET /rest-health/plugins lists wp-extend', $reason),
                $this->skip('ability', 'ewp-rest-health/list-plugins lists wp-extend', $reason),
                $this->skip('cli', 'wp ewp rest-health plugins prints the plugin list', $reason),
            ];
        }

        return [
            $this->check('rest', 'GET /rest-health/plugins lists wp-extend', $this->status($o, 'rest_plugins') === 200 && $path !== '', $path ?: $this->detail($o, 'rest_plugins')),
            $this->ability_check($o, 'ability_plugins', 'ewp-rest-health/list-plugins lists wp-extend', function ($data) {
                return $this->plugin_path($data) !== '';
            }),
            $this->cli_check($o, 'cli_plugins', 'wp ewp rest-health plugins prints the plugin list'),
            $this->check('rest', 'POST /rest-health/endpoints returns routes for wp-extend', $this->status($o, 'rest_endpoints') === 200 && !empty($o['rest_endpoints']['data']), $this->detail($o, 'rest_endpoints')),
            $this->ability_check($o, 'ability_endpoints', 'ewp-rest-health/list-endpoints returns routes', function ($data) {
                return is_array($data) && $data !== [];
            }),
            $this->cli_check($o, 'cli_endpoints', 'wp ewp rest-health endpoints prints routes'),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        if (empty($context['activated']) || empty($context['basename'])) {
            return [];
        }

        $active = (array) get_option('active_plugins', []);
        update_option('active_plugins', array_values(array_diff($active, [$context['basename']])));

        return [sprintf(__('Removed %s from active_plugins again.', 'extend-wp'), $context['basename'])];
    }

    /**
     * This plugin's basename when it is installed as a top-level plugin,
     * empty when it runs bundled inside another plugin's directory.
     *
     * @return string
     */
    private function plugin_basename()
    {
        if (!defined('awm_path')) {
            return '';
        }

        $basename = plugin_basename(awm_path . 'extend-wp.php');
        $is_top   = substr_count($basename, '/') === 1 && file_exists(trailingslashit(WP_PLUGIN_DIR) . $basename);

        return $is_top ? $basename : '';
    }

    /**
     * Add the plugin to active_plugins when missing.
     *
     * @param string $basename Plugin basename, or empty to do nothing.
     *
     * @return bool Whether it was added (and must be removed in cleanup).
     */
    private function ensure_listed_active($basename)
    {
        if ($basename === '') {
            return false;
        }

        $active = (array) get_option('active_plugins', []);
        if (in_array($basename, $active, true)) {
            return false;
        }

        $active[] = $basename;
        update_option('active_plugins', array_values($active));

        return true;
    }

    /**
     * The wp-extend plugin path from a plugins list.
     *
     * @param mixed $plugins Plugins list.
     *
     * @return string
     */
    private function plugin_path($plugins)
    {
        foreach ((array) $plugins as $plugin) {
            if (isset($plugin['path']) && strpos((string) $plugin['path'], 'extend-wp.php') !== false) {
                return (string) $plugin['path'];
            }
        }

        return '';
    }
}
