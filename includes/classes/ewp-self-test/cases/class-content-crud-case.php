<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exercises the generic custom-content CRUD surfaces end to end.
 *
 * Registers a throwaway content type (`ewp_selftest_xxxxxx`, real tables)
 * and runs the full create → read → update → delete cycle through the
 * REST routes (AWM_Add_Content_DB_API), the `wp ewp content` commands
 * (EWP_Content_CLI) and the `ewp-content/*` abilities — all of which sit on
 * the same EWP_Abilities_Content_Service, so a divergence between surfaces
 * shows up here. cleanup() drops the fixture tables again.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Content_Crud_Case extends Case_Base
{
    use Fixture_Content_Type;

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register a temporary content type "ewp_selftest_<random>" with real database tables (one required field, one optional field, statuses enabled/disabled).', 'extend-wp'),
            __('REST: POST /ewp/selftest_<random>/create with a title and the required field — expect 201 and the created row.', 'extend-wp'),
            __('REST: GET /ewp/selftest_<random>/{id} — expect 200.', 'extend-wp'),
            __('CLI: wp ewp content update {id} --type=ewp_selftest_<random> --status=disabled — expect "Updated" and the row to read back as disabled.', 'extend-wp'),
            __('Ability: ewp-content/get-item and ewp-content/update-item (new title) — expect the same row, updated.', 'extend-wp'),
            __('REST: POST /ewp/selftest_<random>/update/{id} with a new optional-field value — expect 200 and the new value.', 'extend-wp'),
            __('Create two more rows, one via wp ewp content create and one via ewp-content/create-item.', 'extend-wp'),
            __('Delete one row on each surface: REST DELETE /ewp/selftest_<random>/delete, wp ewp content delete, ewp-content/delete-item (confirm: true).', 'extend-wp'),
            __('Confirm the type lists as writable in wp ewp content types and ewp-content/list-items reports no rows left.', 'extend-wp'),
            __('Register a second temporary type whose registration turns generic writes off (surfaces: generic_write false, set_status false, delete routed to a callback); write one row through awm_custom_content_save().', 'extend-wp'),
            __('On that type: REST POST /create, wp ewp content create, ewp-content/create-item, ewp-content/update-item, REST POST /status and ewp-content/set-status — expect each to be refused (ewp_content_surface_disabled, 403 on REST); REST DELETE /delete — expect 200 and the row deleted by the type\'s callback.', 'extend-wp'),
            __('Cleanup: delete any remaining rows, drop both fixture tables and their version options, flush the plugin cache.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $key          = 'selftest_' . substr(md5(uniqid('', true)), 0, 6);
        $prefix       = isset($this->args()['namespace']) ? (string) $this->args()['namespace'] : 'ewp';
        $content_type = $prefix . '_' . $key;
        $context      = [
            'key'          => $key,
            'prefix'       => $prefix,
            'content_type' => $content_type,
            'ids'          => [],
            'observed'     => [],
        ];

        $this->register_fixture_type($key, $prefix);


        $route = '/' . $prefix . '/' . $key;
        $o     = &$context['observed'];

        // REST create
        $create = $this->rest('POST', $route . '/create', ['title' => 'Self-test row A', 'meta' => ['required_field' => 'a']]);
        $o['rest_create'] = $create;
        $id_a = isset($create['data']['id']) ? (int) $create['data']['id'] : 0;
        if ($id_a) {
            $context['ids'][] = $id_a;
        }

        // REST read single
        $o['rest_get'] = $id_a ? $this->rest('GET', $route . '/' . $id_a, [], ['id' => $id_a]) : null;

        // CLI update
        $o['cli_update'] = $id_a
            ? $this->cli(['EWP_Content_CLI', 'update'], [$id_a], ['type' => $content_type, 'status' => 'disabled'])
            : null;
        $o['after_cli_update'] = $id_a ? $this->rest('GET', $route . '/' . $id_a, [], ['id' => $id_a]) : null;

        // Ability get + update
        $o['ability_get']    = $id_a ? $this->ability('ewp-content/get-item', ['content_type' => $content_type, 'id' => $id_a]) : null;
        $o['ability_update'] = $id_a ? $this->ability('ewp-content/update-item', ['content_type' => $content_type, 'id' => $id_a, 'title' => 'Self-test row A (ability)']) : null;

        // REST update (patch the optional field)
        $o['rest_update'] = $id_a
            ? $this->rest('POST', $route . '/update/' . $id_a, ['meta' => ['note' => 'patched via REST']], ['id' => $id_a])
            : null;

        // CLI create (row B)
        $o['cli_create'] = $this->cli(['EWP_Content_CLI', 'create'], [], ['type' => $content_type, 'title' => 'Self-test row B', 'meta' => wp_json_encode(['required_field' => 'b'])]);
        $id_b = $this->id_from_cli_success($o['cli_create']);
        if ($id_b) {
            $context['ids'][] = $id_b;
        }

        // Ability create (row C)
        $o['ability_create'] = $this->ability('ewp-content/create-item', ['content_type' => $content_type, 'title' => 'Self-test row C', 'meta' => ['required_field' => 'c']]);
        $id_c = isset($o['ability_create']['data']['id']) ? (int) $o['ability_create']['data']['id'] : 0;
        if ($id_c) {
            $context['ids'][] = $id_c;
        }

        // Deletes, one per surface
        $o['rest_delete']    = $id_a ? $this->rest('DELETE', $route . '/delete', ['ids' => (string) $id_a]) : null;
        $o['cli_delete']     = $id_b ? $this->cli(['EWP_Content_CLI', 'delete'], [$id_b], ['type' => $content_type]) : null;
        $o['ability_delete'] = $id_c ? $this->ability('ewp-content/delete-item', ['content_type' => $content_type, 'ids' => [$id_c], 'confirm' => true]) : null;

        // Listing surfaces
        $o['cli_types']     = $this->cli(['EWP_Content_CLI', 'types'], [], ['format' => 'json']);
        $o['ability_list']  = $this->ability('ewp-content/list-items', ['content_type' => $content_type]);
        $o['rest_list']     = $this->rest('GET', $route);

        $this->run_routed_type($context);

        return $context;
    }

    /**
     * A type that turns the generic writes off (`surfaces`, since 1.11.0): every generic create / update /
     * set-status is refused on REST, CLI and abilities, and the generic delete goes through the type's callback.
     *
     * @param array $context Run context (receives routed_type and observations).
     *
     * @return void
     */
    private function run_routed_type(array &$context)
    {
        $key    = 'selftest_' . substr(md5(uniqid('', true)), 0, 6);
        $prefix = $context['prefix'];
        $type   = $prefix . '_' . $key;
        $calls  = new \ArrayObject();
        $o      = &$context['observed'];

        $context['routed_type'] = $type;
        $this->register_fixture_type($key, $prefix, ['surfaces' => [
            'generic_write' => false,
            'set_status'    => false,
            'duplicate'     => false,
            'delete'        => function (array $ids, $content_type) use ($calls) {
                $calls[] = $ids;
                return awm_custom_content_delete($content_type, $ids);
            },
        ]]);

        $id    = (int) awm_custom_content_save($type, ['content_id' => 'new', 'title' => 'Routed row', 'status' => 'enabled', 'awm_custom_meta' => ['required_field'], 'required_field' => 'r']);
        $route = '/' . $prefix . '/' . $key;

        $o['routed_rest_create']    = $this->rest('POST', $route . '/create', ['title' => 'Refused', 'meta' => ['required_field' => 'x']]);
        $o['routed_cli_create']     = $this->cli(['EWP_Content_CLI', 'create'], [], ['type' => $type, 'title' => 'Refused', 'meta' => wp_json_encode(['required_field' => 'x'])]);
        $o['routed_ability_create'] = $this->ability('ewp-content/create-item', ['content_type' => $type, 'title' => 'Refused', 'meta' => ['required_field' => 'x']]);
        $o['routed_ability_update'] = $this->ability('ewp-content/update-item', ['content_type' => $type, 'id' => $id, 'title' => 'Refused']);
        $o['routed_rest_status']    = $this->rest('POST', $route . '/status', ['ids' => (string) $id, 'status' => 'disabled']);
        $o['routed_ability_status'] = $this->ability('ewp-content/set-status', ['content_type' => $type, 'ids' => [$id], 'status' => 'disabled']);
        $o['routed_rest_delete']    = $this->rest('DELETE', $route . '/delete', ['ids' => (string) $id]);
        $o['routed_delete_calls']   = count($calls);
        $o['routed_rows_left']      = count((array) awm_get_db_content($type, ['fields' => ['content_id']]));
    }

    /**
     * Checks of run_routed_type().
     *
     * @param array $o Observations.
     *
     * @return array[] Checks.
     */
    private function validate_routed_type(array $o)
    {
        $ability_refused = function ($key, $label) use ($o) {
            if (!$this->abilities_available()) {
                return $this->skip('ability', $label, __('Abilities API not available on this site.', 'extend-wp'));
            }
            $r    = isset($o[$key]) ? $o[$key] : [];
            $pass = !empty($r['found']) && empty($r['ok']) && strpos((string) $r['error'], 'ewp_content_surface_disabled') === 0;
            return $this->check('ability', $label, $pass, isset($r['error']) ? (string) $r['error'] : __('not executed', 'extend-wp'));
        };
        $rest_refused = function ($key, $label) use ($o) {
            $pass = $this->status($o, $key) === 403 && isset($o[$key]['data']['code']) && $o[$key]['data']['code'] === 'ewp_content_surface_disabled';
            return $this->check('rest', $label, $pass, $this->detail($o, $key));
        };
        $checks   = [];
        $checks[] = $rest_refused('routed_rest_create', 'generic_write false: POST /create is refused with 403');
        if (!$this->cli_available()) {
            $checks[] = $this->skip('cli', 'generic_write false: wp ewp content create is refused', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        } else {
            $r        = isset($o['routed_cli_create']) ? $o['routed_cli_create'] : ['ok' => true, 'error' => ''];
            $checks[] = $this->check('cli', 'generic_write false: wp ewp content create is refused', empty($r['ok']) && strpos((string) $r['error'], 'does not allow') !== false, (string) $r['error']);
        }
        $checks[] = $ability_refused('routed_ability_create', 'generic_write false: ewp-content/create-item is refused');
        $checks[] = $ability_refused('routed_ability_update', 'generic_write false: ewp-content/update-item is refused');
        $checks[] = $rest_refused('routed_rest_status', 'set_status false: POST /status is refused with 403');
        $checks[] = $ability_refused('routed_ability_status', 'set_status false: ewp-content/set-status is refused');
        $checks[] = $this->check('rest', 'surfaces.delete: DELETE /delete runs the type\'s callback', $this->status($o, 'routed_rest_delete') === 200 && $o['routed_delete_calls'] === 1 && $o['routed_rows_left'] === 0, $this->detail($o, 'routed_rest_delete'));

        return $checks;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = isset($context['observed']) ? $context['observed'] : [];
        $type   = $context['content_type'];
        $checks = [];

        $checks[] = $this->check('rest', 'POST /create returns 201 with the new row', $this->status($o, 'rest_create') === 201 && !empty($o['rest_create']['data']['id']), $this->detail($o, 'rest_create'));
        $checks[] = $this->check('rest', 'GET /{id} returns 200', $this->status($o, 'rest_get') === 200, $this->detail($o, 'rest_get'));

        $checks[] = $this->cli_check($o, 'cli_update', 'wp ewp content update reports success', 'Updated');
        // GET /{id} answers with the single normalised row (since 1.5.0; it used to return a one-item list).
        $checks[] = $this->check('cli', 'Row reads back as "disabled" after the CLI update', isset($o['after_cli_update']['data']['status']) && $o['after_cli_update']['data']['status'] === 'disabled', $this->detail($o, 'after_cli_update'));

        $checks[] = $this->ability_check($o, 'ability_get', 'ewp-content/get-item returns the row', function ($data) {
            return isset($data['status']) && $data['status'] === 'disabled';
        });
        $checks[] = $this->ability_check($o, 'ability_update', 'ewp-content/update-item changes the title', function ($data) {
            return isset($data['title']) && $data['title'] === 'Self-test row A (ability)';
        });

        $checks[] = $this->check('rest', 'POST /update/{id} patches meta and returns 200', $this->status($o, 'rest_update') === 200 && isset($o['rest_update']['data']['meta']['note']) && $o['rest_update']['data']['meta']['note'] === 'patched via REST', $this->detail($o, 'rest_update'));

        $checks[] = $this->cli_check($o, 'cli_create', 'wp ewp content create reports the new id', 'Created');
        $checks[] = $this->ability_check($o, 'ability_create', 'ewp-content/create-item returns a row with an id', function ($data) {
            return !empty($data['id']);
        });

        $checks[] = $this->check('rest', 'DELETE /delete returns 200', $this->status($o, 'rest_delete') === 200, $this->detail($o, 'rest_delete'));
        $checks[] = $this->cli_check($o, 'cli_delete', 'wp ewp content delete reports success', 'Deleted 1');
        $checks[] = $this->ability_check($o, 'ability_delete', 'ewp-content/delete-item (confirm: true) deletes the row', function ($data) {
            return isset($data['count']) && (int) $data['count'] === 1;
        });

        $checks[] = $this->cli_check_printed($o, 'cli_types', 'wp ewp content types lists the fixture type as writable', function ($printed) use ($type) {
            foreach ($printed as $chunk) {
                if (!isset($chunk['items'])) {
                    continue;
                }
                foreach ($chunk['items'] as $row) {
                    if (isset($row['Type']) && $row['Type'] === $type) {
                        return isset($row['Writable']) && $row['Writable'] === 'yes';
                    }
                }
            }
            return false;
        });
        $checks[] = $this->ability_check($o, 'ability_list', 'ewp-content/list-items reports no rows left', function ($data) {
            return isset($data['count']) && (int) $data['count'] === 0;
        });
        $checks[] = $this->check('rest', 'GET /{type} (list) returns 200', $this->status($o, 'rest_list') === 200, $this->detail($o, 'rest_list'));

        return array_merge($checks, $this->validate_routed_type($o));
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        $messages = $this->cleanup_fixture_type(isset($context['content_type']) ? (string) $context['content_type'] : '');
        if (!empty($context['routed_type'])) {
            $messages = array_merge($messages, $this->cleanup_fixture_type((string) $context['routed_type']));
        }

        return $messages;
    }

    /**
     * Parse "Created ewp_x item #17." into 17.
     *
     * @param array|null $cli Result of cli().
     *
     * @return int
     */
    private function id_from_cli_success($cli)
    {
        if (empty($cli['success'][0]) || !preg_match('/#(\d+)/', $cli['success'][0], $m)) {
            return 0;
        }

        return (int) $m[1];
    }
}
