<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exercises the DataViews query and the bulk status change on every surface.
 *
 * Registers a throwaway content type with three rows (notes "alpha",
 * "beta", "alphabet"), then asks REST `{prefix}/{type}/query`,
 * `wp ewp content query` and `ewp-content/query-items` the same view (meta
 * filter `note contains alpha`, sorted by title, two per page) and changes
 * statuses through REST `{prefix}/{type}/status`, `wp ewp content
 * set-status` and `ewp-content/set-status`. All of them run
 * Content_Service::query_items() / set_status(), so the answers must match.
 *
 * @package EWP\SelfTest
 * @since   1.7.0
 */
class Content_Query_Case extends Case_Base
{
    use Fixture_Content_Type;

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register a temporary content type "ewp_selftest_<random>" with real tables and create three rows whose "note" is alpha, beta and alphabet.', 'extend-wp'),
            __('REST: GET /ewp/selftest_<random>/query with view {filters: note contains "alpha", sort: title asc, perPage: 1} — expect 1 row (the first by title), total 2, total_pages 2.', 'extend-wp'),
            __('CLI: wp ewp content query --type=… --view=… (search "beta") — expect exactly the "beta" row.', 'extend-wp'),
            __('Ability: ewp-content/query-items with an unknown field — expect an error; with status isAny [enabled] — expect 3 rows.', 'extend-wp'),
            __('REST: POST /ewp/selftest_<random>/status (ids of two rows, disabled); CLI wp ewp content set-status; ability ewp-content/set-status — expect each to report the changed ids.', 'extend-wp'),
            __('Cleanup: delete the rows, drop both fixture tables and their version options, flush the plugin cache.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $key          = 'selftest_' . substr(md5(uniqid('', true)), 0, 6);
        $prefix       = isset($this->args()['namespace']) ? (string) $this->args()['namespace'] : 'ewp';
        $content_type = $prefix . '_' . $key;
        $context      = ['content_type' => $content_type, 'ids' => [], 'observed' => []];

        $this->register_fixture_type($key, $prefix);
        $route = '/' . $prefix . '/' . $key;
        $o     = &$context['observed'];

        foreach (['A alpha' => 'alpha', 'B beta' => 'beta', 'C alphabet' => 'alphabet'] as $title => $note) {
            $created = $this->ability('ewp-content/create-item', ['content_type' => $content_type, 'title' => $title, 'meta' => ['required_field' => 'x', 'note' => $note]]);
            if (empty($created['data']['id'])) {
                $created = $this->rest('POST', $route . '/create', ['title' => $title, 'meta' => ['required_field' => 'x', 'note' => $note]]);
            }
            if (!empty($created['data']['id'])) {
                $context['ids'][$note] = (int) $created['data']['id'];
            }
        }
        $ids = $context['ids'];

        $o['rest_query'] = $this->rest('GET', $route . '/query', ['view' => [
            'filters' => [['field' => 'note', 'operator' => 'contains', 'value' => 'alpha']],
            'sort'    => ['field' => 'title', 'direction' => 'asc'],
            'perPage' => 1,
        ]]);
        $o['cli_query'] = $this->cli(['EWP_Content_CLI', 'query'], [], ['type' => $content_type, 'view' => wp_json_encode(['search' => 'beta']), 'format' => 'json']);
        $o['ability_query_bad'] = $this->ability('ewp-content/query-items', ['content_type' => $content_type, 'view' => ['filters' => [['field' => 'nope', 'operator' => 'is', 'value' => 'x']]]]);
        $o['ability_query']     = $this->ability('ewp-content/query-items', ['content_type' => $content_type, 'with_meta' => false, 'view' => ['filters' => [['field' => 'status', 'operator' => 'isAny', 'value' => ['enabled']]]]]);

        if (isset($ids['alpha'], $ids['beta'], $ids['alphabet'])) {
            $o['rest_status']    = $this->rest('POST', $route . '/status', ['ids' => $ids['alpha'] . ',' . $ids['beta'], 'status' => 'disabled']);
            $o['cli_status']     = $this->cli(['EWP_Content_CLI', 'set_status'], [], ['type' => $content_type, 'ids' => (string) $ids['alphabet'], 'status' => 'disabled']);
            $o['ability_status'] = $this->ability('ewp-content/set-status', ['content_type' => $content_type, 'ids' => [$ids['alpha']], 'status' => 'enabled']);
            $o['after_status']   = $this->ability('ewp-content/query-items', ['content_type' => $content_type, 'with_meta' => false, 'view' => ['filters' => [['field' => 'status', 'operator' => 'is', 'value' => 'disabled']]]]);
        }

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = isset($context['observed']) ? $context['observed'] : [];
        $ids    = isset($context['ids']) ? $context['ids'] : [];
        $checks = [];

        $checks[] = $this->check('ability', 'Three fixture rows created', count($ids) === 3, wp_json_encode($ids));

        $rest = isset($o['rest_query']['data']) ? $o['rest_query']['data'] : [];
        $checks[] = $this->check(
            'rest',
            'GET /{type}/query: meta filter + sort + paging (1 of 2, first by title)',
            $this->status($o, 'rest_query') === 200 && isset($rest['total'], $rest['items'][0]['title']) && (int) $rest['total'] === 2 && (int) $rest['total_pages'] === 2 && $rest['items'][0]['title'] === 'A alpha',
            $this->detail($o, 'rest_query')
        );

        $checks[] = $this->cli_check_printed($o, 'cli_query', 'wp ewp content query --view={"search":"beta"} returns only the beta row', function ($printed) {
            foreach ($printed as $chunk) {
                $rows = isset($chunk['items']) ? $chunk['items'] : (isset($chunk['value']['items']) ? $chunk['value']['items'] : null);
                if (is_array($rows)) {
                    return count($rows) === 1 && isset($rows[0]['title']) && $rows[0]['title'] === 'B beta';
                }
            }
            return false;
        });

        $bad = isset($o['ability_query_bad']) ? $o['ability_query_bad'] : [];
        $checks[] = $this->check('ability', 'ewp-content/query-items refuses an unknown field', !empty($bad['found']) && empty($bad['ok']), wp_json_encode($bad));
        $checks[] = $this->ability_check($o, 'ability_query', 'ewp-content/query-items: status isAny [enabled] → 3 rows', function ($data) {
            return isset($data['total']) && (int) $data['total'] === 3;
        });

        $checks[] = $this->check('rest', 'POST /{type}/status changes two rows', $this->status($o, 'rest_status') === 200 && isset($o['rest_status']['data']['count']) && (int) $o['rest_status']['data']['count'] === 2, $this->detail($o, 'rest_status'));
        $checks[] = $this->cli_check($o, 'cli_status', 'wp ewp content set-status reports success', 'Set 1');
        $checks[] = $this->ability_check($o, 'ability_status', 'ewp-content/set-status changes one row back', function ($data) {
            return isset($data['count']) && (int) $data['count'] === 1;
        });
        $checks[] = $this->ability_check($o, 'after_status', 'Two rows end up disabled (beta, alphabet)', function ($data) {
            return isset($data['total']) && (int) $data['total'] === 2;
        });

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        return $this->cleanup_fixture_type(isset($context['content_type']) ? (string) $context['content_type'] : '');
    }
}
