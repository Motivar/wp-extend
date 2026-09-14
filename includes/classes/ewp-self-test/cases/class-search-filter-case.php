<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search filters: read surfaces on every layer, the `ewp-filter/{id}`
 * results route, and the guarantee that no write routes exist for them
 * (`writable => false` on the content type, mirrored by the read-only
 * ewp-search abilities). Uses the first configured filter; when the site
 * has none, the per-filter checks are reported as skipped.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Search_Filter_Case extends Case_Base
{
    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Look up the first configured search filter (ewp_search).', 'extend-wp'),
            __('REST: GET /ewp/search (list) and GET /ewp/search/{id} — expect 200.', 'extend-wp'),
            __('REST: confirm /ewp/search/create, /update and /delete are NOT registered (filters are read-only via API).', 'extend-wp'),
            __('REST: GET /ewp-filter/{id} — run the filter and expect 200.', 'extend-wp'),
            __('CLI: wp ewp content get {id} --type=ewp_search — expect the row.', 'extend-wp'),
            __('Ability: ewp-search/list-filters and ewp-search/get-filter — expect the row decorated with shortcode + rest_endpoint.', 'extend-wp'),
            __('No data is created; nothing to clean up.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $filters = function_exists('awm_get_db_content') ? awm_get_db_content('ewp_search', ['limit' => 1, 'fields' => ['content_id']]) : [];
        $id      = !empty($filters[0]['content_id']) ? (int) $filters[0]['content_id'] : 0;
        $o       = ['filter_id' => $id];


        $routes            = array_keys(rest_get_server()->get_routes());
        $o['rest_list']    = $this->rest('GET', '/ewp/search');
        $o['write_routes'] = array_values(array_filter($routes, function ($route) {
            return preg_match('#^/ewp/search/(create|update|delete)#', $route) === 1;
        }));

        if ($id) {
            $o['rest_get']     = $this->rest('GET', '/ewp/search/' . $id, [], ['id' => $id]);
            $o['rest_filter']  = $this->rest('GET', '/ewp-filter/' . $id, ['id' => $id]);
            $o['cli_get']      = $this->cli(['EWP_Content_CLI', 'get_item'], [$id], ['type' => 'ewp_search']);
            $o['ability_get']  = $this->ability('ewp-search/get-filter', ['id' => $id]);
        }

        $o['ability_list'] = $this->ability('ewp-search/list-filters');

        return ['observed' => $o];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $id     = (int) $o['filter_id'];
        $checks = [];

        $checks[] = $this->check('rest', 'GET /ewp/search returns 200', isset($o['rest_list']['status']) && $o['rest_list']['status'] === 200, 'HTTP ' . ($o['rest_list']['status'] ?? '-'));
        $checks[] = $this->check('rest', 'No /ewp/search create/update/delete routes are registered', empty($o['write_routes']), empty($o['write_routes']) ? __('read-only as configured', 'extend-wp') : implode(', ', $o['write_routes']));

        if ($this->abilities_available()) {
            $list = $o['ability_list'];
            $checks[] = $this->check('ability', 'ewp-search/list-filters executes', !empty($list['ok']), $list['error'] ?: sprintf(__('%d filter(s)', 'extend-wp'), isset($list['data']['count']) ? (int) $list['data']['count'] : 0));
        } else {
            $checks[] = $this->skip('ability', 'ewp-search/list-filters executes', __('Abilities API not available on this site.', 'extend-wp'));
        }

        if (!$id) {
            $reason = __('No search filter is configured on this site — add one under Extend WP > Search filters to exercise the per-filter surfaces.', 'extend-wp');
            $checks[] = $this->skip('rest', 'GET /ewp/search/{id} returns 200', $reason);
            $checks[] = $this->skip('rest', 'GET /ewp-filter/{id} runs the filter', $reason);
            $checks[] = $this->skip('cli', 'wp ewp content get --type=ewp_search returns the row', $reason);
            $checks[] = $this->skip('ability', 'ewp-search/get-filter returns the decorated row', $reason);
            return $checks;
        }

        $checks[] = $this->check('rest', 'GET /ewp/search/{id} returns 200', $o['rest_get']['status'] === 200, 'HTTP ' . $o['rest_get']['status']);
        $checks[] = $this->check('rest', 'GET /ewp-filter/{id} runs the filter and returns 200', $o['rest_filter']['status'] === 200, 'HTTP ' . $o['rest_filter']['status']);

        if ($this->cli_available()) {
            $cli = $o['cli_get'];
            $checks[] = $this->check('cli', 'wp ewp content get --type=ewp_search returns the row', !empty($cli['ok']) && !empty($cli['printed'][0]['id']), $cli['error'] ?: __('ok', 'extend-wp'));
        } else {
            $checks[] = $this->skip('cli', 'wp ewp content get --type=ewp_search returns the row', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        if ($this->abilities_available()) {
            $get = $o['ability_get'];
            $checks[] = $this->check('ability', 'ewp-search/get-filter returns the row with shortcode + rest_endpoint', !empty($get['ok']) && !empty($get['data']['shortcode']) && !empty($get['data']['rest_endpoint']), $get['error'] ?: ($get['data']['shortcode'] ?? ''));
        } else {
            $checks[] = $this->skip('ability', 'ewp-search/get-filter returns the decorated row', __('Abilities API not available on this site.', 'extend-wp'));
        }

        return $checks;
    }
}
