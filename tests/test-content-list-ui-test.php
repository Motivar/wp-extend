<?php
/**
 * Phase 5 (DataViews UI): EWP_Content_List_UI — mode switch, field configuration,
 * legacy cells / row actions through `{prefix}/{type}/query?render=1`.
 */

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Content_List_UI extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Content type with a select field shown in the list. */
    private static $type;

    /**
     * Fixture type with an `admin_list` select field.
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$type = self::create_content_type_fixture(array(
            'capability' => 'edit_posts',
            'metaboxes'  => array('main' => array('library' => array(
                'colour' => array('case' => 'select', 'label' => 'Colour', 'admin_list' => true, 'options' => array('red' => array('label' => 'Red'), 'blue' => array('label' => 'Blue'))),
                'size'   => array('case' => 'select', 'label' => 'Size', 'restrict_content' => true, 'options' => array('s' => array('label' => 'Small'), 'l' => array('label' => 'Large'))),
            ))),
        ));
        self::refresh_rest_routes();
    }

    /**
     * Admin user, shadow off.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        add_filter('ewp_db_shadow_enabled', '__return_false');
        // No refresh_rest_routes() here: WP_UnitTestCase restores hooks to the first test's snapshot, so the
        // fixture's rest_api_init hook is gone after this class's first test and a fresh server would have no routes.
    }

    /**
     * List arguments as the admin menu passes them.
     *
     * @return array
     */
    private function args()
    {
        return array_merge(AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$type], array('id' => self::$type, 'icon_url' => ''));
    }

    /**
     * DataViews by default for every type (when supported); the filter, the WordPress version and
     * `?ewp_list_ui=legacy` decide.
     *
     * @return void
     */
    public function test_mode()
    {
        $expected = EWP_Content_List_UI::supported() ? 'dataviews' : 'legacy';
        $this->assertSame($expected, EWP_Content_List_UI::mode(self::$type), 'every registered type defaults to DataViews');

        $_GET['ewp_list_ui'] = 'legacy';
        $this->assertSame('legacy', EWP_Content_List_UI::mode(self::$type), '?ewp_list_ui=legacy forces the legacy list');
        unset($_GET['ewp_list_ui']);

        $off = function ($mode, $type) {
            return $type === self::$type ? 'legacy' : $mode;
        };
        add_filter('ewp_content_list_ui', $off, 10, 2);
        $this->assertSame('legacy', EWP_Content_List_UI::mode(self::$type), 'a type opts out through the filter');
        remove_filter('ewp_content_list_ui', $off, 10);

        add_filter('ewp_content_list_ui', function () {
            return 'dataviews';
        });
        $this->assertSame('legacy', EWP_Content_List_UI::mode('not_registered_type'), 'unknown types never get DataViews');
    }

    /**
     * DataViews needs WordPress 6.8+ with the module built.
     *
     * @return void
     */
    public function test_supported_requires_wp_68()
    {
        $this->assertSame(version_compare(get_bloginfo('version'), '6.8', '>=') && is_readable(awm_path . 'build/modules/ewp-dataviews-list.asset.php'), EWP_Content_List_UI::supported());
    }

    /**
     * A `restrict_content` field that is not a column becomes a hidden, filter-only field with its options.
     *
     * @return void
     */
    public function test_restrict_filters_become_hidden_filter_fields()
    {
        $fields = array_column(EWP_Content_List_UI::fields(self::$type, $this->args()), null, 'id');

        $this->assertArrayHasKey('size', $fields);
        $this->assertTrue($fields['size']['hidden']);
        $this->assertFalse($fields['size']['html']);
        $this->assertSame(array('s', 'l'), array_column($fields['size']['elements'], 'value'));
        $this->assertSame(array('isAny', 'isNone'), $fields['size']['operators']);

        $config = EWP_Content_List_UI::config(self::$type, $this->args());
        $this->assertNotContains('size', $config['defaultView']['fields'], 'filter-only fields are not shown as columns');
        $this->assertContains('colour', $config['defaultView']['fields']);
    }

    /**
     * Fields: title first, legacy columns mapped (`ewp_date` → created), status and select fields filterable, columns sortable.
     *
     * @return void
     */
    public function test_fields_config()
    {
        $fields = array_column(EWP_Content_List_UI::fields(self::$type, $this->args()), null, 'id');

        $this->assertSame('title', array_key_first($fields));
        $this->assertTrue($fields['title']['enableSorting']);
        $this->assertArrayHasKey('created', $fields, 'ewp_date column maps to the created view field');
        $this->assertTrue($fields['created']['enableSorting']);
        $this->assertSame('datetime', $fields['created']['type']);

        $this->assertSame(array('isAny', 'isNone'), $fields['status']['operators']);
        $this->assertSame(array('enabled', 'disabled'), array_column($fields['status']['elements'], 'value'));

        $this->assertSame('meta', $fields['colour']['source']);
        $this->assertFalse($fields['colour']['enableSorting'], 'meta fields do not sort');
        $this->assertSame(array(array('value' => 'red', 'label' => 'Red'), array('value' => 'blue', 'label' => 'Blue')), $fields['colour']['elements']);
    }

    /**
     * Declared filters (since 1.12.0): `list_filters` and `ewp_content_list_filters` attach a normalised `filter`
     * to a column or add a filter-only field; anything outside the view fields or of an unknown type is dropped.
     *
     * @return void
     */
    public function test_declared_filters()
    {
        $declare = function ($filters, $type) {
            if ($type !== self::$type) {
                return $filters;
            }
            return array_merge($filters, array(
                'created' => array('type' => 'date'),
                'when'    => array('type' => 'date', 'label' => 'When', 'fields' => array('created' => 'Created', 'modified' => 'Modified', 'nope' => 'Nope')),
                'colour'  => array('type' => 'select', 'elements' => array(array('value' => 'red', 'label' => 'Red')), 'operators' => array('is', 'isAll'), 'isPrimary' => true),
                'title'   => array('type' => 'text'),
                'ghost'   => array('type' => 'number'),
                'odd'     => array('type' => 'colour', 'field' => 'title'),
                'empty'   => array('type' => 'multiselect', 'field' => 'status'),
            ));
        };
        add_filter('ewp_content_list_filters', $declare, 10, 2);
        $fields = EWP_Content_List_UI::fields(self::$type, $this->args());
        remove_filter('ewp_content_list_filters', $declare, 10);
        $by_id = array_column($fields, null, 'id');

        $this->assertSame(array('type' => 'date', 'field' => 'created', 'fields' => array(), 'isPrimary' => false), $by_id['created']['filter']);
        $this->assertSame(array('created', 'modified'), array_column($by_id['when']['filter']['fields'], 'value'), 'range targets outside the view fields are dropped');
        $this->assertTrue($by_id['when']['hidden'], 'an id that is not a column becomes a filter-only field');
        $this->assertFalse($by_id['when']['enableHiding']);
        $this->assertSame('When', $by_id['when']['label']);
        $this->assertSame(array('is'), $by_id['colour']['filter']['operators'], 'operators outside the type allowlist are dropped');
        $this->assertTrue($by_id['colour']['filter']['isPrimary']);
        $this->assertSame('text', $by_id['title']['filter']['type']);
        $this->assertArrayNotHasKey('ghost', $by_id, 'a filter on an unknown view field is dropped');
        $this->assertArrayNotHasKey('odd', $by_id, 'an unknown type is dropped');
        $this->assertArrayNotHasKey('empty', $by_id, 'an element filter without elements is dropped');

        $plain = array_column(EWP_Content_List_UI::fields(self::$type, $this->args()), null, 'id');
        $this->assertArrayNotHasKey('filter', $plain['created'], 'nothing changes for a type without declarations');
        $this->assertSame(array('isAny', 'isNone'), $plain['status']['operators']);

        $own = EWP_Content_List_UI::normalize_filter('total', array('type' => 'number', 'fields' => array(array('value' => 'total', 'label' => 'Total'))));
        $this->assertSame('total', $own['fields'][0]['value'], 'without a view field map (own REST adapter) nothing is checked');
    }

    /**
     * The module configuration carries the REST path, URLs, statuses and a default view of known fields.
     *
     * @return void
     */
    public function test_config()
    {
        $config = EWP_Content_List_UI::config(self::$type, $this->args());
        $parts  = explode('_', self::$type, 2);

        $this->assertSame('/' . $parts[0] . '/' . $parts[1], $config['restPath']);
        $this->assertStringContainsString('page=' . self::$type . '_form', $config['editUrl']);
        $this->assertStringContainsString('_wpnonce=', $config['duplicateUrl']);
        $this->assertTrue($config['writable']);
        $this->assertSame(array('created', 'desc'), array($config['defaultView']['sort']['field'], $config['defaultView']['sort']['direction']));
        $this->assertEmpty(array_diff($config['defaultView']['fields'], array_column($config['fields'], 'id')));
        $this->assertNotFalse(wp_json_encode($config));
    }

    /**
     * `query?render=1` adds the legacy cells (`ewp_column_{type}_column_content_filter`) and row actions
     * (`ewp_row_actions_{type}_filter`); without it the rows are unchanged.
     *
     * @return void
     */
    public function test_query_render_returns_legacy_cells_and_actions()
    {
        $id    = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'Painted', 'status' => 'enabled', 'awm_custom_meta' => array('colour', 'required_field'), 'colour' => 'red', 'required_field' => 'x'));
        $extra = function ($actions, $item) {
            $actions['invoice'] = '<a href="https://example.org/invoice/' . (int) $item['content_id'] . '">Invoice</a><script>alert(1)</script>';
            return $actions;
        };
        add_filter('ewp_row_actions_' . self::$type . '_filter', $extra, 10, 2);
        $cell = function ($content, $item, $column) {
            return $column === 'colour' ? '<em>Red #' . (int) $item['content_id'] . '</em><script>x()</script>' : $content;
        };
        add_filter('ewp_column_' . self::$type . '_column_content_filter', $cell, 20, 3);

        $parts = explode('_', self::$type, 2);
        // Register the type's routes on the current server explicitly: hooks added in set_up_before_class()
        // do not survive WP_UnitTestCase's hook restore when another test class ran first.
        \EWP\Surfaces\EWP_Surfaces::instance()->register_content_type_routes(self::$type, $parts[0], $parts[1]);
        $request = new WP_REST_Request('GET', '/' . $parts[0] . '/' . $parts[1] . '/query');
        $request->set_param('render', true);
        $data = rest_get_server()->dispatch($request)->get_data();
        remove_filter('ewp_row_actions_' . self::$type . '_filter', $extra, 10);
        remove_filter('ewp_column_' . self::$type . '_column_content_filter', $cell, 20);

        $this->assertArrayHasKey('items', (array) $data, wp_json_encode($data));
        $this->assertSame($id, $data['items'][0]['id']);
        $rendered = (array) $data['items'][0]['rendered'];
        $cells    = (array) $rendered['cells'];
        $actions  = (array) $rendered['actions'];
        $this->assertSame('<em>Red #' . $id . '</em>x()', $cells['colour'], 'the legacy cell filter output, kses-filtered, with the raw _main row');
        $this->assertArrayHasKey('edit', $actions);
        $this->assertStringContainsString('/invoice/' . $id, $actions['invoice']);
        $this->assertStringNotContainsString('<script', $actions['invoice'], 'filter HTML is kses-filtered');

        $plain = rest_get_server()->dispatch(new WP_REST_Request('GET', '/' . $parts[0] . '/' . $parts[1] . '/query'))->get_data();
        $this->assertArrayHasKey('items', $plain, wp_json_encode($plain));
        $this->assertArrayNotHasKey('rendered', $plain['items'][0]);
    }

    /**
     * The module and its stylesheet are registered with the Dynamic Asset Loader on the mount selector.
     *
     * @return void
     */
    public function test_assets_registered()
    {
        $assets = EWP_Content_List_UI::register_assets(array());
        if (!is_readable(awm_path . 'build/modules/ewp-dataviews-list.asset.php')) {
            $this->assertSame(array(), $assets);
            return;
        }
        $this->assertSame(array('ewp-dataviews-list', 'ewp-dataviews-list-style'), array_column($assets, 'handle'));
        $this->assertSame(array('.ewp-dataviews-list', '.ewp-dataviews-list'), array_column($assets, 'selector'));
        $this->assertContains('wp-element', $assets[0]['dependencies']);
    }
}
