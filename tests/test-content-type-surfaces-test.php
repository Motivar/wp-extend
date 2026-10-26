<?php
/**
 * 1.11.0 content-type registration gaps (Filox booking migration): ensure_tables(), keep_user,
 * awm_get_db_content() offset / title compare, custom `_main` columns in normalize_row(), per-type
 * `surfaces` (duplicate, set_status, delete, generic_write) across the service, REST and both admin
 * lists, capability checks of the legacy list actions, icon_url / results_per_page pass-through.
 */

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Content_Type_Surfaces extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Type with keep_user, a custom `_main` column, icon_url and results_per_page. */
    private static $plain;

    /** @var string Type that routes set_status / delete to callables and turns duplicate off. */
    private static $routed;

    /** @var string Type with generic_write, set_status and delete off. */
    private static $locked;

    /** @var string Type registered without tables, then created by ewp_content_ensure_tables(). */
    private static $lazy;

    /** @var bool Whether the lazy type's tables existed before ensure_tables(). */
    private static $lazy_existed;

    /** @var mixed What ewp_content_ensure_tables() returned for the lazy type. */
    private static $lazy_result;

    /** @var array Calls of the routed type's callables: [kind, ids, status?]. */
    public static $calls = array();

    /**
     * Fixture types; tables are created here (see EWP_Test_Content_Fixture).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$plain = self::create_content_type_fixture(array(
            'keep_user'        => true,
            'icon_url'         => 'dashicons-calendar',
            'results_per_page' => 25,
            'main_table_data'  => array('data' => array(
                'start_date' => array('sql' => 'date', 'label' => 'Start'),
                'room_ref'   => array('sql' => 'varchar(50)', 'sql_key' => 'room'),
            )),
        ));
        self::$routed = self::create_content_type_fixture(array('surfaces' => array(
            'duplicate'  => false,
            'set_status' => array(__CLASS__, 'route_status'),
            'delete'     => array(__CLASS__, 'route_delete'),
        )));
        self::$locked = self::create_content_type_fixture(array('surfaces' => array(
            'generic_write' => false,
            'set_status'    => false,
            'delete'        => false,
        )));

        $key   = 'phpunit_lazy_' . substr(md5(uniqid('', true)), 0, 6);
        $setup = new AWM_Add_Content_DB_Setup();
        $setup->get_table_conf(array('key' => $key, 'structure' => array(
            'list_name'          => 'Lazy',
            'list_name_singular' => 'Lazy item',
            'capability'         => 'edit_posts',
        )));
        self::$lazy         = 'ewp_' . $key;
        self::$lazy_existed = AWM_DB_Creator::check_table_exists(self::$lazy . '_main');
        self::$lazy_result  = ewp_content_ensure_tables(self::$lazy);

        self::refresh_rest_routes();
    }

    /**
     * Admin user, no recorded calls.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        self::$calls = array();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        add_filter('ewp_db_shadow_enabled', '__return_false');
    }

    /**
     * Routed set_status callable.
     *
     * @param int[]  $ids    Ids.
     * @param string $status Status.
     * @param string $type   Content type id.
     * @return array Result.
     */
    public static function route_status(array $ids, $status, $type)
    {
        self::$calls[] = array('status', $ids, $status, $type);
        return array('updated' => $ids, 'note' => 'routed');
    }

    /**
     * Routed delete callable (keeps the rows: a soft delete).
     *
     * @param int[]  $ids  Ids.
     * @param string $type Content type id.
     * @return bool
     */
    public static function route_delete(array $ids, $type)
    {
        self::$calls[] = array('delete', $ids, $type);
        return true;
    }

    /**
     * Save a row through the normal pipeline.
     *
     * @param string $type  Content type id.
     * @param array  $extra Posted values (title, status, columns, content_id).
     * @return int Row id.
     */
    private function save_row($type, array $extra = array())
    {
        $id = awm_custom_content_save($type, array_merge(array(
            'content_id'      => 'new',
            'title'           => 'Row',
            'status'          => 'enabled',
            'awm_custom_meta' => array('required_field'),
            'required_field'  => 'x',
        ), $extra));
        $this->assertIsInt((int) $id);
        $this->assertGreaterThan(0, (int) $id);
        return (int) $id;
    }

    /**
     * Raw `_main` row.
     *
     * @param string $type Content type id.
     * @param int    $id   Row id.
     * @return array Row.
     */
    private function raw($type, $id)
    {
        $rows = awm_get_db_content($type, array('include' => array($id)));
        return $rows[0];
    }

    public function test_ensure_tables_creates_the_tables_outside_admin_init()
    {
        $this->assertFalse(self::$lazy_existed);
        $this->assertTrue(self::$lazy_result);
        $this->assertTrue(AWM_DB_Creator::check_table_exists(self::$lazy . '_main'));
        $this->assertTrue(AWM_DB_Creator::check_table_exists(self::$lazy . '_data'));
        $this->assertFalse(ewp_content_ensure_tables('ewp_no_such_type'));

        $all = ewp_content_ensure_tables();
        $this->assertTrue($all[self::$plain]);
        $this->assertArrayNotHasKey('ewp_no_such_type', $all);
    }

    public function test_registration_defaults_and_pass_through()
    {
        $plain  = AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$plain];
        $routed = AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$routed];
        $lazy   = AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$lazy];

        $this->assertSame('dashicons-calendar', $plain['icon_url']);
        $this->assertSame(25, $plain['results_per_page']);
        $this->assertTrue($plain['keep_user']);
        $this->assertSame('', $lazy['icon_url']);
        $this->assertSame(50, $lazy['results_per_page']);
        $this->assertFalse($lazy['keep_user']);
        $this->assertSame(array('duplicate' => true, 'set_status' => null, 'delete' => null, 'generic_write' => true), $lazy['surfaces']);
        $this->assertFalse($routed['surfaces']['duplicate']);
        $this->assertIsCallable($routed['surfaces']['set_status']);
        // Anything that is neither callable nor false means the generic implementation.
        $this->assertNull(AWM_Add_Content_DB_Setup::normalize_surfaces(array('delete' => 'no_such_function'))['delete']);
    }

    public function test_keep_user_sets_the_author_on_insert_only()
    {
        $author = get_current_user_id();
        $id     = $this->save_row(self::$plain);

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $this->save_row(self::$plain, array('content_id' => $id, 'title' => 'Edited'));

        $row = $this->raw(self::$plain, $id);
        $this->assertSame('Edited', $row['content_title']);
        $this->assertSame($author, (int) $row['user_id']);
    }

    public function test_without_keep_user_an_update_takes_the_current_user()
    {
        $id     = $this->save_row(self::$routed);
        $editor = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($editor);
        $this->save_row(self::$routed, array('content_id' => $id));

        $this->assertSame($editor, (int) $this->raw(self::$routed, $id)['user_id']);
    }

    public function test_get_db_content_offset_and_title_compare()
    {
        $a = $this->save_row(self::$plain, array('title' => 'Alpha'));
        $b = $this->save_row(self::$plain, array('title' => 'Alphabet'));
        $c = $this->save_row(self::$plain, array('title' => 'Beta'));
        $order = array('column' => 'content_id', 'type' => 'asc');

        $page = awm_get_db_content(self::$plain, array('include' => array($a, $b, $c), 'order_by' => $order));
        $this->assertCount(3, $page);
        $second = awm_get_db_content(self::$plain, array('limit' => 1, 'offset' => 1, 'order_by' => $order, 'title' => 'Alpha%'));
        $this->assertSame($b, (int) $second[0]['content_id']);

        // LIKE stays the default for a string title; `=` and the array form match exactly.
        $this->assertCount(2, awm_get_db_content(self::$plain, array('title' => 'Alpha%')));
        $this->assertCount(0, awm_get_db_content(self::$plain, array('title' => 'Alpha%', 'title_compare' => '=')));
        $this->assertCount(1, awm_get_db_content(self::$plain, array('title' => 'Alpha', 'title_compare' => '=')));
        $this->assertCount(1, awm_get_db_content(self::$plain, array('title' => array('value' => 'Alpha'))));
        $this->assertCount(2, awm_get_db_content(self::$plain, array('title' => array('value' => 'Alpha%', 'compare' => 'LIKE'))));
    }

    public function test_normalize_row_returns_custom_main_columns()
    {
        $id   = $this->save_row(self::$plain, array('start_date' => '2026-12-24', 'room_ref' => 'R12'));
        $item = (new \EWP\Content\Content_Service())->get_item(self::$plain, $id);

        $this->assertSame('2026-12-24', $item['start_date']);
        $this->assertSame('R12', $item['room_ref']);
        $this->assertSame('x', $item['meta']->required_field);
        $this->assertSame(array('start_date' => 'start_date', 'room_ref' => 'room'), (new \EWP\Content\Content_Service())->custom_columns(self::$plain));
    }

    public function test_set_status_and_delete_are_routed_to_the_callables()
    {
        $service = new \EWP\Content\Content_Service();
        $id      = $this->save_row(self::$routed);

        $status = $service->set_status(self::$routed, array($id), 'disabled');
        $this->assertSame(array('status', array($id), 'disabled', self::$routed), self::$calls[0]);
        $this->assertSame(1, $status['count']);
        $this->assertSame(array($id), $status['updated']);
        $this->assertSame('routed', $status['note']);
        $this->assertSame('enabled', $this->raw(self::$routed, $id)['status'], 'the generic save did not run');

        $deleted = $service->delete_items(self::$routed, array($id, 999999));
        $this->assertSame(array('delete', array($id), self::$routed), self::$calls[1]);
        $this->assertSame(array($id), $deleted['deleted']);
        $this->assertSame(array(999999), $deleted['not_found']);
        $this->assertNotEmpty(awm_get_db_content(self::$routed, array('include' => array($id))), 'the callable kept the row');
    }

    public function test_disabled_surfaces_are_refused()
    {
        $service = new \EWP\Content\Content_Service();
        $id      = $this->save_row(self::$locked);

        foreach (array(
            $service->create_item(self::$locked, 'New', '', array('required_field' => 'x')),
            $service->update_item(self::$locked, $id, array('title' => 'Changed')),
            $service->set_status(self::$locked, array($id), 'disabled'),
            $service->delete_items(self::$locked, array($id)),
            $service->duplicate_item(self::$routed, $this->save_row(self::$routed)),
        ) as $result) {
            $this->assertWPError($result);
            $this->assertSame('ewp_content_surface_disabled', $result->get_error_code());
            $this->assertSame(403, $result->get_error_data()['status']);
        }
        $this->assertSame('Row', $this->raw(self::$locked, $id)['content_title']);
    }

    public function test_generic_write_off_keeps_the_generic_set_status()
    {
        $service = new \EWP\Content\Content_Service();
        $type    = self::$plain;
        $id      = $this->save_row($type);

        $saved = AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]['surfaces'];
        AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]['surfaces']['generic_write'] = false;
        $result = $service->set_status($type, array($id), 'disabled');
        AWM_Add_Content_DB_Setup::$ewp_data_configuration[$type]['surfaces'] = $saved;

        $this->assertSame(array($id), $result['updated']);
        $this->assertSame('disabled', $this->raw($type, $id)['status']);
    }

    public function test_duplicate_item_copies_when_allowed()
    {
        $service = new \EWP\Content\Content_Service();
        $id      = $this->save_row(self::$plain, array('title' => 'Original'));
        $copy    = $service->duplicate_item(self::$plain, $id);

        $this->assertIsArray($copy);
        $this->assertNotSame($id, $copy['id']);
        $this->assertSame('Original-copy', $copy['title']);
        $this->assertSame('ewp_abilities_not_found', $service->duplicate_item(self::$plain, 999999)->get_error_code());
    }

    public function test_rest_refuses_generic_writes_and_routes_deletes()
    {
        $id    = $this->save_row(self::$locked);
        $base  = '/ewp/' . substr(self::$locked, 4);

        $create = new WP_REST_Request('POST', $base . '/create');
        $create->set_body_params(array('title' => 'Refused', 'meta' => array('required_field' => 'x')));
        $response = rest_do_request($create);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('ewp_content_surface_disabled', $response->get_data()['code']);

        $update = new WP_REST_Request('POST', $base . '/update/' . $id);
        $update->set_body_params(array('title' => 'Refused'));
        $this->assertSame(403, rest_do_request($update)->get_status());

        $routed = $this->save_row(self::$routed);
        $delete = new WP_REST_Request('DELETE', '/ewp/' . substr(self::$routed, 4) . '/delete');
        $delete->set_query_params(array('ids' => (string) $routed));
        $response = rest_do_request($delete);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('delete', self::$calls[0][0]);
    }

    public function test_dataviews_config_and_row_actions_follow_surfaces()
    {
        $routed = EWP_Content_List_UI::config(self::$routed, array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$routed], array('id' => self::$routed)));
        $locked = EWP_Content_List_UI::config(self::$locked, array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$locked], array('id' => self::$locked)));
        $plain  = EWP_Content_List_UI::config(self::$plain, array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$plain], array('id' => self::$plain)));

        $this->assertFalse($routed['canDuplicate']);
        $this->assertTrue($routed['canSetStatus']);
        $this->assertTrue($routed['canDelete']);
        $this->assertFalse($locked['canSetStatus']);
        $this->assertFalse($locked['canDelete']);
        $this->assertTrue($plain['canDuplicate']);

        $id     = $this->save_row(self::$routed);
        $result = (new \EWP\Content\Content_Service())->query_items(self::$routed, array('filters' => array(array('field' => 'id', 'operator' => 'is', 'value' => $id))));
        $rows   = EWP_Content_List_UI::render_rows($result, self::$routed);
        $this->assertArrayNotHasKey('duplicate', (array) $rows['items'][0]['rendered']['actions']);
        $this->assertArrayHasKey('delete', (array) $rows['items'][0]['rendered']['actions']);
    }

    public function test_legacy_list_hides_and_refuses_disabled_actions()
    {
        set_current_screen('dashboard');
        $list = new AWM_List_Table(array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$locked], array('id' => self::$locked)));
        $this->assertArrayNotHasKey('delete', $list->get_bulk_actions());

        $routed = new AWM_List_Table(array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$routed], array('id' => self::$routed)));
        $actions = $routed->get_row_actions(array('content_id' => 7));
        $this->assertArrayNotHasKey('duplicate', $actions);
        $this->assertArrayHasKey('delete', $actions);
    }

    public function test_legacy_list_actions_need_the_type_capability()
    {
        set_current_screen('dashboard');
        $id = $this->save_row(self::$plain);
        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));
        $list = new AWM_List_Table(array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$plain], array('id' => self::$plain)));

        $_REQUEST['action']   = 'delete';
        $_REQUEST['id']       = (string) $id;
        $_REQUEST['_wpnonce'] = wp_create_nonce('admin.php?page=' . self::$plain . '_delete');
        try {
            $list->process_bulk_action();
            $this->fail('process_bulk_action() did not stop a user without the capability');
        } catch (WPDieException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        } finally {
            unset($_REQUEST['action'], $_REQUEST['id'], $_REQUEST['_wpnonce']);
        }
        $this->assertNotEmpty(awm_get_db_content(self::$plain, array('include' => array($id))));
    }

    public function test_export_pages_and_streams_to_a_file()
    {
        $ids = array();
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->save_row(self::$routed, array('title' => 'Export ' . $i));
        }
        $existing    = count(awm_get_db_content(self::$routed, array('fields' => array('content_id'))));
        $portability = new \EWP\Content\Content_Portability();

        $full = $portability->export(array(self::$routed));
        $this->assertCount($existing, $full[self::$routed]);
        $this->assertArrayNotHasKey('pagination', $full);
        $this->assertSame('x', $full[self::$routed][$ids[0]]['meta']['required_field']);

        $page = $portability->export(array(self::$routed), array('page' => 2, 'per_page' => 2));
        $this->assertSame(array('page' => 2, 'per_page' => 2, 'total' => $existing, 'total_pages' => (int) ceil($existing / 2)), $page['pagination'][self::$routed]);
        $all_ids = array_keys($full[self::$routed]);
        sort($all_ids);
        $this->assertSame(array_slice($all_ids, 2, 2), array_keys($page[self::$routed]));

        $empty = $portability->export(array(self::$routed), array('page' => 99, 'per_page' => 2));
        $this->assertArrayNotHasKey(self::$routed, $empty);
        $this->assertSame(99, $empty['pagination'][self::$routed]['page']);

        $file   = trailingslashit(sys_get_temp_dir()) . 'ewp-phpunit-export-' . uniqid() . '.json';
        $result = $portability->export(array(self::$routed), array('file' => $file));
        $this->assertSame($existing, $result['count']);
        $decoded = json_decode((string) file_get_contents($file), true);
        unlink($file);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(array_keys($full[self::$routed]), array_map('intval', array_keys($decoded[self::$routed])));
        $this->assertSame($full[self::$routed][$ids[2]]['content_title'], $decoded[self::$routed][$ids[2]]['content_title']);
        $this->assertArrayHasKey('modified', $decoded);

        $this->assertWPError($portability->export(array('ewp_no_such_type'), array('file' => $file)));
        $this->assertFileDoesNotExist($file);
    }

    public function test_list_form_save_needs_the_type_capability()
    {
        $form = (new ReflectionClass('AWM_Add_Custom_List'))->newInstanceWithoutConstructor();
        foreach (array('custom_id' => self::$plain, 'page_hook' => 'ewp_test_hook', 'page_id' => self::$plain, 'capability' => 'edit_posts') as $prop => $value) {
            $ref = new ReflectionProperty('AWM_Add_Custom_List', $prop);
            $ref->setAccessible(true);
            $ref->setValue($form, $value);
        }
        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));
        $_POST['ewp_list_page_hook_nonce'] = wp_create_nonce('ewp_test_hook');
        $_POST['title']                    = 'Should not save';
        try {
            $form->save_page();
            $this->fail('save_page() did not stop a user without the capability');
        } catch (WPDieException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        } finally {
            unset($_POST['ewp_list_page_hook_nonce'], $_POST['title']);
        }
        $this->assertEmpty(awm_get_db_content(self::$plain, array('title' => 'Should not save', 'title_compare' => '=')));
    }
}
