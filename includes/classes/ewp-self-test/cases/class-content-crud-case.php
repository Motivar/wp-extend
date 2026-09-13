<?php

namespace EWP\SelfTest\Cases;

use EWP\SelfTest\EWP_Self_Test_Case;
use EWP\SelfTest\WP_CLI_Shim;

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
class Content_Crud_Case extends EWP_Self_Test_Case
{
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

        if ($this->cli_available()) {
            WP_CLI_Shim::load_plugin_commands();
        }

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

        return $context;
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

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        global $wpdb;

        $type     = isset($context['content_type']) ? (string) $context['content_type'] : '';
        $messages = [];

        if ($type === '' || strpos($type, 'selftest_') === false) {
            return [__('Nothing to clean: no fixture content type recorded.', 'extend-wp')];
        }

        $rows = function_exists('awm_get_db_content') ? awm_get_db_content($type, ['fields' => ['content_id'], 'limit' => 500]) : [];
        if (!empty($rows) && is_array($rows)) {
            $ids = array_map(function ($row) {
                return (int) $row['content_id'];
            }, $rows);
            awm_custom_content_delete($type, $ids);
            $messages[] = sprintf(__('Deleted %d leftover row(s) from %s.', 'extend-wp'), count($ids), $type);
        }

        foreach (['_data', '_main'] as $suffix) {
            $table = $type . $suffix;
            $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . $table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
            delete_option('ewp_version_' . $table);
            $messages[] = sprintf(__('Dropped table %s.', 'extend-wp'), $wpdb->prefix . $table);
        }

        if (class_exists('AWM_Add_Content_DB_Setup') && isset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type])) {
            unset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]);
        }

        if (function_exists('ewp_flush_cache')) {
            ewp_flush_cache();
            $messages[] = __('Flushed the Extend WP cache.', 'extend-wp');
        }

        return $messages;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Register the fixture type and create its tables + REST routes now.
     *
     * @param string $key    Raw key (`selftest_xxxxxx`).
     * @param string $prefix Content prefix / REST namespace.
     *
     * @return void
     */
    private function register_fixture_type($key, $prefix)
    {
        $structure = [
            'list_name'          => 'Self-test fixture',
            'list_name_singular' => 'Self-test row',
            'capability'         => 'manage_options',
            'custom_prefix'      => $prefix,
            'statuses'           => [
                'enabled'  => ['label' => 'Enabled'],
                'disabled' => ['label' => 'Disabled'],
            ],
            'metaboxes'          => [
                'main' => [
                    'title'   => 'Main',
                    'library' => [
                        'required_field' => ['case' => 'input', 'label' => 'Required field', 'required' => true],
                        'note'           => ['case' => 'input', 'label' => 'Note', 'required' => false],
                    ],
                ],
            ],
        ];

        $setup = new \AWM_Add_Content_DB_Setup();
        $setup->init(['key' => $key, 'structure' => $structure]);
        $setup->on_load();

        rest_get_server();
        $setup->rest_endpoints();
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

    private function status(array $o, $key)
    {
        return isset($o[$key]['status']) ? (int) $o[$key]['status'] : 0;
    }

    private function detail(array $o, $key)
    {
        if (!isset($o[$key])) {
            return __('not executed', 'extend-wp');
        }

        return 'HTTP ' . $this->status($o, $key) . ' ' . wp_json_encode($o[$key]['data']);
    }

    private function cli_check(array $o, $key, $label, $expected_fragment)
    {
        if (!$this->cli_available()) {
            return $this->skip('cli', $label, __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        if (!isset($o[$key])) {
            return $this->check('cli', $label, false, __('not executed', 'extend-wp'));
        }

        $r    = $o[$key];
        $pass = !empty($r['ok']) && !empty($r['success'][0]) && strpos($r['success'][0], $expected_fragment) !== false;

        return $this->check('cli', $label, $pass, $pass ? $r['success'][0] : ($r['error'] ?: wp_json_encode($r['success'])));
    }

    private function cli_check_printed(array $o, $key, $label, callable $predicate)
    {
        if (!$this->cli_available()) {
            return $this->skip('cli', $label, __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        $r    = isset($o[$key]) ? $o[$key] : ['ok' => false, 'printed' => [], 'error' => 'not executed'];
        $pass = !empty($r['ok']) && $predicate(isset($r['printed']) ? $r['printed'] : []);

        return $this->check('cli', $label, $pass, $pass ? __('ok', 'extend-wp') : ($r['error'] ?: __('expected row not found in output', 'extend-wp')));
    }

    private function ability_check(array $o, $key, $label, callable $predicate)
    {
        if (!$this->abilities_available()) {
            return $this->skip('ability', $label, __('Abilities API not available on this site.', 'extend-wp'));
        }

        if (!isset($o[$key])) {
            return $this->check('ability', $label, false, __('not executed — an earlier step it depends on failed', 'extend-wp'));
        }

        $r = $o[$key];

        if (empty($r['found'])) {
            return $this->check('ability', $label, false, __('ability is not registered', 'extend-wp'));
        }

        $pass = !empty($r['ok']) && $predicate(is_array($r['data']) ? $r['data'] : []);

        return $this->check('ability', $label, $pass, $pass ? __('ok', 'extend-wp') : ($r['error'] ?: wp_json_encode($r['data'])));
    }
}
