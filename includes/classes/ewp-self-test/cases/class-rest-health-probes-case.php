<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The interactive REST-health routes (REST-only, generated from
 * Rest_Health_Probes_Resource): probe, history, monitor, payloads,
 * preferences and the OpenAPI export.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Rest_Health_Probes_Case extends Case_Base
{
    /** Route every probe in this case targets. */
    const ROUTE = '/extend-wp/v1/rest-health/plugins';

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('REST: POST /rest-health/test and /rest-health/batch against /rest-health/plugins — expect a status per probe.', 'extend-wp'),
            __('REST: GET /rest-health/history — expect the probe just made; DELETE — expect {cleared: true}.', 'extend-wp'),
            __('REST: POST /rest-health/monitor start then stop, GET /rest-health/monitor — expect active to toggle; GET and DELETE /rest-health/monitor/payloads.', 'extend-wp'),
            __('REST: POST /rest-health/preferences then GET — expect the saved plugins and methods back; cleared in cleanup.', 'extend-wp'),
            __('REST: GET /rest-health/openapi — expect an OpenAPI document with paths.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $o = [];
        $context = ['observed' => [], 'had_prefs' => get_user_meta(get_current_user_id(), 'ewp_rh_preferences', true)];

        $o['test']    = $this->rest('POST', '/extend-wp/v1/rest-health/test', ['route' => self::ROUTE, 'method' => 'GET']);
        $o['batch']   = $this->rest('POST', '/extend-wp/v1/rest-health/batch', ['routes_methods' => [['route' => self::ROUTE, 'method' => 'GET']]]);
        $o['history'] = $this->rest('GET', '/extend-wp/v1/rest-health/history', ['route' => self::ROUTE, 'method' => 'GET']);
        $o['clear']   = $this->rest('DELETE', '/extend-wp/v1/rest-health/history', ['route' => self::ROUTE, 'method' => 'GET']);

        $o['start']    = $this->rest('POST', '/extend-wp/v1/rest-health/monitor', ['action' => 'start', 'plugins' => []]);
        $o['stop']     = $this->rest('POST', '/extend-wp/v1/rest-health/monitor', ['action' => 'stop']);
        $o['monitor']  = $this->rest('GET', '/extend-wp/v1/rest-health/monitor');
        $o['payloads'] = $this->rest('GET', '/extend-wp/v1/rest-health/monitor/payloads');
        $o['discard']  = $this->rest('DELETE', '/extend-wp/v1/rest-health/monitor/payloads');

        $o['save_prefs'] = $this->rest('POST', '/extend-wp/v1/rest-health/preferences', ['plugins' => ['a/a.php'], 'methods' => ['GET']]);
        $o['prefs']      = $this->rest('GET', '/extend-wp/v1/rest-health/preferences');

        $o['openapi'] = $this->rest('GET', '/extend-wp/v1/rest-health/openapi', ['methods' => ['GET']]);

        $context['observed'] = $o;

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o = $context['observed'];

        return [
            $this->check('rest', 'POST /rest-health/test probes a route', $this->status($o, 'test') === 200 && isset($o['test']['data']['status']), $this->detail($o, 'test')),
            $this->check('rest', 'POST /rest-health/batch probes a list', $this->status($o, 'batch') === 200 && !empty($o['batch']['data']['results']), $this->detail($o, 'batch')),
            $this->check('rest', 'GET /rest-health/history lists the probe', $this->status($o, 'history') === 200 && is_array($o['history']['data']) && $o['history']['data'] !== [], $this->detail($o, 'history')),
            $this->check('rest', 'DELETE /rest-health/history clears it', $this->status($o, 'clear') === 200 && !empty($o['clear']['data']['cleared']), $this->detail($o, 'clear')),
            $this->check('rest', 'POST /rest-health/monitor start activates', $this->status($o, 'start') === 200 && !empty($o['start']['data']['active']), $this->detail($o, 'start')),
            $this->check('rest', 'POST /rest-health/monitor stop deactivates', $this->status($o, 'stop') === 200 && empty($o['stop']['data']['active']), $this->detail($o, 'stop')),
            $this->check('rest', 'GET /rest-health/monitor reports the state', $this->status($o, 'monitor') === 200 && array_key_exists('active', (array) $o['monitor']['data']), $this->detail($o, 'monitor')),
            $this->check('rest', 'GET /rest-health/monitor/payloads returns 200', $this->status($o, 'payloads') === 200, $this->detail($o, 'payloads')),
            $this->check('rest', 'DELETE /rest-health/monitor/payloads clears', $this->status($o, 'discard') === 200 && !empty($o['discard']['data']['cleared']), $this->detail($o, 'discard')),
            $this->check('rest', 'POST then GET /rest-health/preferences round-trips', $this->status($o, 'prefs') === 200 && ($o['prefs']['data']['plugins'] ?? null) === ['a/a.php'], $this->detail($o, 'prefs')),
            $this->check('rest', 'GET /rest-health/openapi returns a document', $this->status($o, 'openapi') === 200 && isset($o['openapi']['data']['openapi']), $this->detail($o, 'openapi')),
        ];
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        $user = get_current_user_id();
        if (!empty($context['had_prefs'])) {
            update_user_meta($user, 'ewp_rh_preferences', $context['had_prefs']);
        } else {
            delete_user_meta($user, 'ewp_rh_preferences');
        }
        update_option('ewp_rh_monitor_active', false, false);

        return [__('Restored the REST-health preferences and stopped monitoring.', 'extend-wp')];
    }
}
