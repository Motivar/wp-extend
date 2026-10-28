<?php
/**
 * Phase 4 (DataViews query layer): EWP\Db\View_Query, Where casts,
 * AWM_DB_Creator::count_db_data(), Content_Service::query_items() /
 * set_status() / view_fields(), and the legacy list's AND-ed filters and sort.
 */

use EWP\Content\Content_Service;
use EWP\Db\Invalid_Where;
use EWP\Db\View_Query;
use EWP\Db\Where;

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Content_View_Query extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Content type with real tables and a numeric + a multi-value field. */
    private static $type;

    /** @var Content_Service */
    private $service;

    /**
     * Fixture type: example_field (text), required_field, price (number input), tags (checkbox multiple).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$type = self::create_content_type_fixture(array(
            'capability' => 'edit_posts',
            'metaboxes'  => array('main' => array('library' => array(
                'price' => array('case' => 'input', 'type' => 'number', 'label' => 'Price'),
                'tags'  => array('case' => 'checkbox_multiple', 'label' => 'Tags', 'options' => array('a' => array('label' => 'A'), 'b' => array('label' => 'B'))),
            ))),
        ));
    }

    /**
     * Admin user, shadow off (query counts), a service.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        add_filter('ewp_db_shadow_enabled', '__return_false');
        $this->service = new Content_Service();
    }

    /**
     * Save one row.
     *
     * @param string $title  Title.
     * @param array  $meta   Meta values.
     * @param string $status Status.
     * @return int Content id.
     */
    private function save($title, array $meta, $status = 'enabled')
    {
        return (int) awm_custom_content_save(self::$type, array_merge(array(
            'content_id'      => 'new',
            'title'           => $title,
            'status'          => $status,
            'awm_custom_meta' => array_merge(array('required_field'), array_keys($meta)),
            'required_field'  => 'x',
        ), $meta));
    }

    /**
     * Titles of a query result, in order.
     *
     * @param array $view View.
     * @return string[] Titles.
     */
    private function titles(array $view)
    {
        $result = $this->service->query_items(self::$type, $view, false);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        return array_column($result['items'], 'title');
    }

    /**
     * Three rows used by most tests.
     *
     * @return int[] Title => id.
     */
    private function seed()
    {
        return array(
            'Apple'  => $this->save('Apple', array('example_field' => 'red fruit', 'price' => '9', 'tags' => array('a'))),
            'Banana' => $this->save('Banana', array('example_field' => 'yellow fruit', 'price' => '10', 'tags' => array('a', 'b')), 'disabled'),
            'Cherry' => $this->save('Cherry', array('example_field' => 'red stone', 'price' => '100')),
        );
    }

    /**
     * The field map: columns sortable, meta not; number input typed; presentation cases skipped.
     *
     * @return void
     */
    public function test_view_fields_map()
    {
        $fields = $this->service->view_fields(self::$type);
        $this->assertSame(array('source' => 'column', 'key' => 'content_title'), array_intersect_key($fields['title'], array_flip(array('source', 'key'))));
        $this->assertTrue($fields['created']['sortable']);
        $this->assertSame('meta', $fields['price']['source']);
        $this->assertSame('number', $fields['price']['type']);
        $this->assertFalse($fields['price']['sortable']);
        $this->assertTrue($fields['example_field']['searchable']);
        $this->assertArrayNotHasKey('hash', $fields);
    }

    /**
     * Sort by a column, page through it, total and total_pages.
     *
     * @return void
     */
    public function test_sort_and_paging()
    {
        $this->seed();
        $this->assertSame(array('Cherry', 'Banana', 'Apple'), $this->titles(array('sort' => array('field' => 'title', 'direction' => 'desc'))));

        $page2 = $this->service->query_items(self::$type, array('sort' => array('field' => 'title', 'direction' => 'asc'), 'perPage' => 2, 'page' => 2), false);
        $this->assertSame(array('Cherry'), array_column($page2['items'], 'title'));
        $this->assertSame(3, $page2['total']);
        $this->assertSame(2, $page2['total_pages']);
        $this->assertSame(2, $page2['page']);
    }

    /**
     * Column filters: is / isNot / isAny / isNone on status.
     *
     * @return void
     */
    public function test_column_filters()
    {
        $this->seed();
        $sort = array('field' => 'title', 'direction' => 'asc');
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'status', 'operator' => 'is', 'value' => 'disabled')))));
        $this->assertSame(array('Apple', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'status', 'operator' => 'isNot', 'value' => 'disabled')))));
        $this->assertSame(array('Apple', 'Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'status', 'operator' => 'isAny', 'value' => array('enabled', 'disabled'))))));
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'status', 'operator' => 'isNone', 'value' => array('enabled'))))));
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'title', 'operator' => 'startsWith', 'value' => 'Ban')))));
    }

    /**
     * Meta filters through `_data`: contains / notContains, numeric comparisons with a cast, isAll on serialised lists.
     *
     * @return void
     */
    public function test_meta_filters()
    {
        $this->seed();
        $sort = array('field' => 'title', 'direction' => 'asc');
        $this->assertSame(array('Apple', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'example_field', 'operator' => 'contains', 'value' => 'red')))));
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'example_field', 'operator' => 'notContains', 'value' => 'red')))));
        // As strings '9' > '10' > '100'; with the number cast 9 < 10 < 100.
        $this->assertSame(array('Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'greaterThan', 'value' => 9)))));
        $this->assertSame(array('Apple', 'Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'between', 'value' => array(1, 50))))));
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'tags', 'operator' => 'isAll', 'value' => array('a', 'b'))))));
        $this->assertSame(array('Apple', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'tags', 'operator' => 'isNotAll', 'value' => array('a', 'b'))))), 'items without the key count as missing the values');
        $this->assertSame(array('Apple', 'Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'is', 'value' => '')))), 'a filter without a value is ignored');
    }

    /**
     * The conditions the DataViews panel sends for declared filters (since 1.12.0): a date range on a datetime
     * column (a date-only upper bound covers its whole day), number ranges, `contains` on a column.
     *
     * @return void
     */
    public function test_declared_filter_conditions()
    {
        $ids  = $this->seed();
        $sort = array('field' => 'title', 'direction' => 'asc');
        $row  = awm_get_db_content(self::$type, array('include' => array($ids['Apple'])));
        $day  = substr((string) $row[0]['created'], 0, 10);
        $next = gmdate('Y-m-d', strtotime($day . ' +1 day'));

        $this->assertSame(array('Apple', 'Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'created', 'operator' => 'between', 'value' => array($day, $day))))), 'a one-day range includes rows created during that day');
        $this->assertSame(array('Apple', 'Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'created', 'operator' => 'beforeInc', 'value' => $day)))), 'a date-only upper bound covers the day');
        $this->assertSame(array(), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'created', 'operator' => 'afterInc', 'value' => $next)))));
        $this->assertSame(array('Banana', 'Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'between', 'value' => array(10, 100))))));
        $this->assertSame(array('Cherry'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'greaterThanOrEqual', 'value' => 11)))));
        $this->assertSame(array('Apple', 'Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'price', 'operator' => 'lessThanOrEqual', 'value' => 10)))));
        $this->assertSame(array('Banana'), $this->titles(array('sort' => $sort, 'filters' => array(array('field' => 'title', 'operator' => 'contains', 'value' => 'nan')))));

        $parts = View_Query::build(self::$type, array('filters' => array(array('field' => 'created', 'operator' => 'beforeInc', 'value' => '2026-01-31 10:00:00'))), $this->service->view_fields(self::$type));
        $this->assertSame('2026-01-31 10:00:00', $parts['where']['clause'][0]['value'], 'a bound with a time is kept');
    }

    /**
     * Multi-select (since 1.13.0): `isAny` / `isNone` with several values on a column and on meta, the
     * declared filter's element values as an allowlist, and the cap on list values.
     *
     * @return void
     */
    public function test_multiselect_conditions()
    {
        $this->seed();
        $sort = array('field' => 'title', 'direction' => 'asc');
        $cond = function ($field, $operator, $value) use ($sort) {
            return array('sort' => $sort, 'filters' => array(array('field' => $field, 'operator' => $operator, 'value' => $value)));
        };

        $this->assertSame(array('Apple', 'Cherry'), $this->titles($cond('title', 'isAny', array('Apple', 'Cherry'))), 'column IN');
        $this->assertSame(array('Banana', 'Cherry'), $this->titles($cond('title', 'isNone', array('Apple'))), 'column NOT IN');
        $this->assertSame(array('Apple', 'Cherry'), $this->titles($cond('example_field', 'isAny', array('red fruit', 'red stone'))), 'meta IN');
        $this->assertSame(array('Banana', 'Cherry'), $this->titles($cond('example_field', 'isNone', array('red fruit'))), 'meta NOT IN');

        $parts = View_Query::build(self::$type, $cond('title', 'isAny', array('Apple', "x' OR 1=1 -- ")), $this->service->view_fields(self::$type));
        $this->assertSame(array('column' => 'content_title', 'compare' => 'IN', 'value' => array('Apple', "x' OR 1=1 -- ")), $parts['where']['clause'][0], 'values stay bound values');
        $sql = Where::build($parts['where'], array('content_title' => 'content_title'));
        $this->assertStringContainsString('IN (%s, %s)', str_replace('%s,%s', '%s, %s', $sql['sql']), 'one placeholder per value');
        $this->assertSame(array('content_title', 'Apple', "x' OR 1=1 -- "), $sql['values']);

        $too_many = $this->service->query_items(self::$type, $cond('title', 'isAny', array_map('strval', range(1, View_Query::MAX_VALUES + 1))), false);
        $this->assertSame(400, $too_many->get_error_data()['status'], 'more than MAX_VALUES values is a 400');

        $declare = function ($filters, $type) {
            return $type === self::$type ? array_merge($filters, array('title' => array('type' => 'select', 'multiple' => true, 'elements' => array('Apple' => 'Apple', 'Banana' => 'Banana')))) : $filters;
        };
        add_filter('ewp_content_list_filters', $declare, 10, 2);
        $fields  = $this->service->view_fields(self::$type);
        $allowed = $this->service->query_items(self::$type, $cond('title', 'isAny', array('Apple', 'Banana')), false);
        $refused = $this->service->query_items(self::$type, $cond('title', 'isNone', array('Apple', 'Cherry')), false);
        $search  = $this->service->query_items(self::$type, array('sort' => $sort, 'filters' => array(array('field' => 'title', 'operator' => 'contains', 'value' => 'Cher'))), false);
        remove_filter('ewp_content_list_filters', $declare, 10);

        $this->assertSame(array('Apple', 'Banana'), $fields['title']['values'], 'the declared elements are the field allowlist');
        $this->assertSame(array('Apple', 'Banana'), array_column($allowed['items'], 'title'));
        $this->assertWPError($refused, 'a value the declared filter does not offer is refused');
        $this->assertSame(400, $refused->get_error_data()['status']);
        $this->assertSame(array('Cherry'), array_column($search['items'], 'title'), 'operators that do not pick elements are not restricted');
        $this->assertArrayNotHasKey('values', $this->service->view_fields(self::$type)['title'], 'nothing is restricted without a declaration');
    }

    /**
     * Search covers the title, searchable meta and a numeric id, ANDed with filters.
     *
     * @return void
     */
    public function test_search()
    {
        $ids  = $this->seed();
        $sort = array('field' => 'title', 'direction' => 'asc');
        $this->assertSame(array('Banana'), $this->titles(array('search' => 'nan')));
        $this->assertSame(array('Banana'), $this->titles(array('search' => 'yellow')));
        $this->assertSame(array('Cherry'), $this->titles(array('search' => (string) $ids['Cherry'])));
        $this->assertSame(array('Cherry'), $this->titles(array('sort' => $sort, 'search' => 'red', 'filters' => array(array('field' => 'price', 'operator' => 'greaterThanOrEqual', 'value' => 50)))));
        $this->assertSame(array(), $this->titles(array('search' => '100%')), 'LIKE wildcards in the search are literal');
    }

    /**
     * Bad views are a 400, never a wider result.
     *
     * @return void
     */
    public function test_invalid_views_are_refused()
    {
        $bad = array(
            array('filters' => array(array('field' => 'nope', 'operator' => 'is', 'value' => 'x'))),
            array('filters' => array(array('field' => 'status', 'operator' => 'drop', 'value' => 'x'))),
            array('filters' => array(array('field' => 'status', 'operator' => 'is', 'value' => array('a', 'b')))),
            array('filters' => array(array('field' => 'price', 'operator' => 'between', 'value' => array(1)))),
            array('filters' => 'status=1'),
            array('sort' => array('field' => 'price', 'direction' => 'asc')),
            array('sort' => array('field' => 'title', 'direction' => 'sideways')),
        );
        foreach ($bad as $view) {
            $result = $this->service->query_items(self::$type, $view, false);
            $this->assertWPError($result, wp_json_encode($view));
            $this->assertSame(400, $result->get_error_data()['status']);
        }
    }

    /**
     * perPage is clamped; page below 1 is the first page.
     *
     * @return void
     */
    public function test_pagination_bounds()
    {
        $q = View_Query::build(self::$type, array('perPage' => 5000, 'page' => -3), $this->service->view_fields(self::$type));
        $this->assertSame(View_Query::MAX_PER_PAGE, $q['limit']);
        $this->assertSame(0, $q['offset']);
        $this->assertSame(array('column' => 'created', 'type' => 'DESC'), $q['order_by']);
    }

    /**
     * Where casts: numeric order on text, unknown casts refused, and the shadow comparison skips cast conditions.
     *
     * @return void
     */
    public function test_where_cast()
    {
        $columns = array('meta_value' => 'meta_value');
        $built   = Where::build(array('column' => 'meta_value', 'compare' => '>', 'value' => 9, 'cast' => 'number'), $columns);
        $this->assertSame('CAST(%i AS DECIMAL(30,10)) > %d', $built['sql']);
        $this->assertSame(array('meta_value', 9), $built['values']);

        $this->expectException(Invalid_Where::class);
        Where::build(array('column' => 'meta_value', 'value' => 1, 'cast' => 'CHAR); DROP'), $columns, array('strict' => true));
    }

    /**
     * Shadow mode cannot compare cast conditions.
     *
     * @return void
     */
    public function test_shadow_skips_casts()
    {
        $this->assertFalse(\EWP\Db\Shadow::comparable(array('clause' => array(array('clause' => array(array('column' => 'a', 'compare' => '>', 'value' => 1, 'cast' => 'number')))))));
    }

    /**
     * count_db_data() matches the rows and follows writes.
     *
     * @return void
     */
    public function test_count_db_data()
    {
        $this->seed();
        $where = array('column' => 'status', 'value' => 'enabled');
        $this->assertSame(2, AWM_DB_Creator::count_db_data(self::$type . '_main', $where));
        $this->save('Date', array(), 'enabled');
        $this->assertSame(3, AWM_DB_Creator::count_db_data(self::$type . '_main', $where));
        $this->assertFalse(AWM_DB_Creator::count_db_data('no_such_table_xyz'));
    }

    /**
     * Bulk status: changed ids, not-found ids, save hooks per row, unknown status refused.
     *
     * @return void
     */
    public function test_set_status()
    {
        $ids   = $this->seed();
        $fired = array();
        $hook  = function ($id) use (&$fired) {
            $fired[] = (int) $id;
        };
        add_action(self::$type . '_save_action', $hook);

        $result = $this->service->set_status(self::$type, array($ids['Apple'], $ids['Cherry'], 999999), 'disabled');
        remove_action(self::$type . '_save_action', $hook);

        $this->assertSame(2, $result['count']);
        $this->assertSame(array($ids['Apple'], $ids['Cherry']), $result['updated']);
        $this->assertSame(array(999999), $result['not_found']);
        $this->assertSame(array($ids['Apple'], $ids['Cherry']), $fired);
        $this->assertSame('red fruit', awm_get_db_content_meta(self::$type, $ids['Apple'], 'example_field'), 'meta untouched');
        $this->assertSame(array('Apple', 'Banana', 'Cherry'), $this->titles(array('sort' => array('field' => 'title', 'direction' => 'asc'), 'filters' => array(array('field' => 'status', 'operator' => 'is', 'value' => 'disabled')))));

        $this->assertWPError($this->service->set_status(self::$type, array($ids['Apple']), 'bogus'));
        $this->assertWPError($this->service->set_status(self::$type, array(), 'enabled'));
        $this->assertWPError($this->service->set_status(self::$type, array(999998), 'enabled'));
    }

    /**
     * A page of 20 with meta: rows + count + one batched meta query.
     *
     * @return void
     */
    public function test_query_with_meta_query_count()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->save('Row ' . $i, array('example_field' => 'v' . $i));
        }
        wp_cache_flush();
        \EWP\Db\Content_Meta::forget();
        $queries = array();
        $record  = function ($sql) use (&$queries) {
            $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $record);
        $result = $this->service->query_items(self::$type, array('perPage' => 20), true);
        remove_filter('query', $record);

        $this->assertSame(20, $result['count']);
        $this->assertSame(3, count($queries), implode(' || ', $queries));
        $this->assertSame('x', $result['items'][0]['meta']->required_field);
    }

    /**
     * Legacy list: status and search are ANDed (they were OR'ed), and `orderby` accepts the SQL column the list links carry.
     *
     * @return void
     */
    public function test_legacy_list_where_and_order()
    {
        $this->seed();
        set_current_screen('dashboard');
        $config = \AWM_Add_Content_DB_Setup::$ewp_data_configuration[self::$type];
        $list   = new AWM_List_Table(array_merge($config, array('id' => self::$type, 'icon_url' => '')));

        $_REQUEST['ewp_status'] = 'enabled';
        $_REQUEST['s']          = 'yellow';
        $where = $list->get_list_where_clause();
        $this->assertSame(array(), AWM_DB_Creator::get_db_data(self::$type . '_main', '*', $where), 'Banana matches the search but is disabled');

        $_REQUEST['s'] = 'red';
        $this->assertCount(2, AWM_DB_Creator::get_db_data(self::$type . '_main', '*', $list->get_list_where_clause()));

        unset($_REQUEST['ewp_status'], $_REQUEST['s']);
        $_REQUEST['orderby'] = 'content_title';
        $_REQUEST['order']   = 'asc';
        $list->prepare_items();
        $this->assertSame(array('Apple', 'Banana', 'Cherry'), array_column($list->items, 'content_title'));
        unset($_REQUEST['orderby'], $_REQUEST['order']);
    }
}
