<?php
/**
 * End to end: the custom-table CRUD (AWM_DB_Creator, awm_get_db_content,
 * the content REST list) binds every value and allow-lists every identifier.
 * Covers the injection vectors from the SQL hardening review (order_by and
 * status on a public_read content type, admin list status/search, raw
 * meta_query compare) and the fail-closed writes.
 */

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Db_Sql_Injection extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Raw table (without prefix). */
    private static $table;

    /** @var string Content type with real tables. */
    private static $type;

    /** @var string Content type registered with public_read. */
    private static $public_type;

    /**
     * Real tables, created before the per-test transaction starts.
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$table = 'ewp_inj_' . strtolower(wp_generate_password(6, false));
        new AWM_DB_Creator(array(self::$table => array(
            'data'       => array(
                'id'     => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'label'  => 'varchar(191) NOT NULL',
                'status' => 'varchar(20) NOT NULL',
                'amount' => 'int(11) NOT NULL DEFAULT 0',
            ),
            'primaryKey' => 'id',
            'version'    => '1',
        )));
        self::$type        = self::create_content_type_fixture(array('capability' => 'edit_posts'));
        self::$public_type = self::create_content_type_fixture(array('public_read' => true, 'capability' => 'manage_options'));
        self::refresh_rest_routes();
    }

    /**
     * Drop the raw table.
     *
     * @return void
     */
    public static function tear_down_after_class()
    {
        AWM_DB_Creator::drop_table(self::$table);
        parent::tear_down_after_class();
    }

    /**
     * Quiet $wpdb so failed statements do not print.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $GLOBALS['wpdb']->suppress_errors(true);
        // Content `_main` rows reference wp_users(ID) through a foreign key.
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
    }

    /**
     * Insert a raw row.
     *
     * @param string $label  Label.
     * @param string $status Status.
     * @param int    $amount Amount.
     * @return int Id.
     */
    private function row($label, $status = 'a', $amount = 1)
    {
        $result = AWM_DB_Creator::insert_db_data(self::$table, array('label' => $label, 'status' => $status, 'amount' => $amount));
        return (int) $result['id'];
    }

    /**
     * WHERE node with one condition.
     *
     * @param string $column  Column.
     * @param mixed  $value   Value.
     * @param string $compare Comparison.
     * @return array Node in the legacy shape.
     */
    private function where($column, $value, $compare = '=')
    {
        return array('clause' => array(array('operator' => 'AND', 'clause' => array(array('column' => $column, 'value' => $value, 'compare' => $compare)))));
    }

    /**
     * Rows of the raw table.
     *
     * @return int Count.
     */
    private function count_rows()
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . self::$table));
    }

    /**
     * Quotes and SQL in a value are data, in WHERE and in SET.
     *
     * Written through update_db_data(), which stores values as given
     * (insert_db_data() stores them with added slashes, see the CHANGELOG).
     *
     * @return void
     */
    public function test_values_with_quotes_are_bound()
    {
        $label = "O'Brien \"x\" ' OR '1'='1 -- ";
        $id    = $this->row('plain');
        $this->row('other');
        $this->assertTrue(AWM_DB_Creator::update_db_data(self::$table, array('label' => $label), $this->where('id', $id)));

        $rows = AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('label', $label));

        $this->assertCount(1, $rows);
        $this->assertSame($id, (int) $rows[0]['id']);
        $this->assertSame($label, $rows[0]['label']);
        $this->assertSame(array(), AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('label', "x' OR '1'='1")));
        $this->assertSame(2, $this->count_rows());
    }

    /**
     * A hand-built IN list that tries to break out matches nothing instead of everything.
     *
     * @return void
     */
    public function test_legacy_in_injection_matches_nothing()
    {
        $this->row('one', 'a');
        $this->row('two', 'b');

        $this->assertSame(array(), AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('status', "('a') OR '1'='1'", 'IN')));
        $this->assertCount(1, AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('status', "('a')", 'IN')));
    }

    /**
     * ORDER BY, SELECT and LIMIT injection is dropped; the query still runs.
     *
     * @return void
     */
    public function test_identifier_and_limit_injection_is_dropped()
    {
        $this->row('one');
        $this->row('two');

        $rows = AWM_DB_Creator::get_db_data(self::$table, '*', '', array('column' => 'id; DROP TABLE wp_users', 'type' => 'DESC'));
        $this->assertCount(2, $rows);
        $rows = AWM_DB_Creator::get_db_data(self::$table, '*', '', array('column' => 'id', 'type' => 'DESC, (SELECT SLEEP(5))'));
        $this->assertCount(2, $rows);

        $rows = AWM_DB_Creator::get_db_data(self::$table, array('label', '(SELECT user_pass FROM wp_users LIMIT 1)'));
        $this->assertSame(array('label'), array_keys($rows[0]));

        $this->assertCount(2, AWM_DB_Creator::get_db_data(self::$table, '*', '', array(), '1; DROP TABLE wp_users', 0));
        $this->assertFalse(AWM_DB_Creator::get_db_data(self::$table, array('nope')));
    }

    /**
     * prepare_only returns bound SQL.
     *
     * @return void
     */
    public function test_prepare_only_returns_escaped_sql()
    {
        $sql = AWM_DB_Creator::get_db_data(self::$table, array('id'), $this->where('label', "a'b"), '', '', 0, true);

        $this->assertStringContainsString("'a\\'b'", $sql);
        $this->assertStringContainsString('`id`', $sql);
    }

    /**
     * Updates fail closed: unknown columns, invalid or empty WHERE change nothing.
     *
     * @return void
     */
    public function test_updates_fail_closed()
    {
        $id = $this->row('keep');
        $this->row('keep too');

        $this->assertFalse(AWM_DB_Creator::update_db_data(self::$table, array('label' => 'x', 'id = 1, label' => 'y'), $this->where('id', $id)));
        $this->assertFalse(AWM_DB_Creator::update_db_data(self::$table, array('label' => 'all'), ''));
        $this->assertFalse(AWM_DB_Creator::update_db_data(self::$table, array('label' => 'all'), $this->where('nope', 1)));
        $this->assertFalse(AWM_DB_Creator::update_db_data(self::$table, array('label' => 'all'), $this->where('status', "('a') OR 1=1", 'IN')));
        $this->assertSame(array(), AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('label', 'all')));

        $this->assertTrue(AWM_DB_Creator::update_db_data(self::$table, array('label' => "it's", 'amount' => 5), $this->where('id', $id)));
        $row = AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('id', $id));
        $this->assertSame("it's", $row[0]['label']);
        $this->assertSame('5', (string) $row[0]['amount']);
        $this->assertCount(1, AWM_DB_Creator::get_db_data(self::$table, '*', $this->where('label', 'keep too')));
    }

    /**
     * Deletes fail closed and delete only what the WHERE matches.
     *
     * @return void
     */
    public function test_deletes_fail_closed()
    {
        $id = $this->row('gone');
        $this->row('stays');

        $this->assertFalse(AWM_DB_Creator::delete_db_data(self::$table, ''));
        $this->assertFalse(AWM_DB_Creator::delete_db_data(self::$table, $this->where('nope', 1)));
        $this->assertFalse(AWM_DB_Creator::delete_db_data(self::$table, $this->where('id', '1 OR 1=1', 'IN')));
        $this->assertSame(2, $this->count_rows());

        $this->assertSame(1, AWM_DB_Creator::delete_db_data(self::$table, $this->where('id', array($id), 'IN')));
        $this->assertSame(1, $this->count_rows());
    }

    /**
     * awm_get_db_content: status (string or array), include and title bind their values.
     *
     * @return void
     */
    public function test_content_query_filters()
    {
        $a = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'Alpha one', 'status' => 'enabled', 'awm_custom_meta' => array('example_field', 'required_field'), 'example_field' => 'red', 'required_field' => 'x'));
        $b = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'Beta', 'status' => 'disabled', 'awm_custom_meta' => array('example_field', 'required_field'), 'example_field' => 'blue', 'required_field' => 'x'));

        $this->assertSame(array($a), array_map('intval', array_column(awm_get_db_content(self::$type, array('status' => 'enabled')), 'content_id')));
        $this->assertCount(2, awm_get_db_content(self::$type, array('status' => array('enabled', 'disabled'))));
        $this->assertSame(array(), awm_get_db_content(self::$type, array('status' => "enabled') OR ('1'='1")));
        $this->assertSame(array($b), array_map('intval', array_column(awm_get_db_content(self::$type, array('include' => array($b, "0) OR (1=1"))), 'content_id')));
        $this->assertCount(1, awm_get_db_content(self::$type, array('title' => 'Alpha one')));
        $this->assertSame(array(), awm_get_db_content(self::$type, array('title' => "x' OR '1'='1")));
    }

    /**
     * meta_query binds key and value, allow-lists compare, honours relation.
     *
     * @return void
     */
    public function test_meta_query()
    {
        $a = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'A', 'status' => 'enabled', 'awm_custom_meta' => array('example_field', 'required_field'), 'example_field' => 'red', 'required_field' => 'x'));
        $b = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'B', 'status' => 'enabled', 'awm_custom_meta' => array('example_field', 'required_field'), 'example_field' => 'blue', 'required_field' => 'y'));

        $ids = function ($meta_query) {
            return array_map('intval', array_column((array) awm_get_db_content(self::$type, array('meta_query' => $meta_query)), 'content_id'));
        };

        $this->assertSame(array($a), $ids(array(array('key' => 'example_field', 'value' => 'red', 'compare' => '='))));
        $this->assertSame(array($b), $ids(array(array('key' => 'example_field', 'value' => '%lu%', 'compare' => 'LIKE'))));
        $this->assertSame(array(), $ids(array('relation' => 'AND', array('key' => 'example_field', 'value' => 'red'), array('key' => 'required_field', 'value' => 'y'))));
        $this->assertEqualsCanonicalizing(array($a, $b), $ids(array('relation' => 'OR', array('key' => 'example_field', 'value' => 'red'), array('key' => 'required_field', 'value' => 'y'))));
        $this->assertSame(array(), $ids(array(array('key' => 'example_field', 'value' => 'x', 'compare' => "= 'x' OR 1=1 OR meta_value ="))));
        $this->assertSame(array(), $ids(array(array('key' => "example_field' OR '1'='1", 'value' => 'red'))));
    }

    /**
     * Anonymous REST list on a public_read type: hostile order_by and status
     * are harmless (vector #1 of the review).
     *
     * @return void
     */
    public function test_public_read_rest_list_vectors()
    {
        awm_custom_content_save(self::$public_type, array('content_id' => 'new', 'title' => 'Public', 'status' => 'enabled', 'awm_custom_meta' => array('example_field', 'required_field'), 'example_field' => 'v', 'required_field' => 'v'));
        wp_set_current_user(0);
        $route = '/ewp/' . substr(self::$public_type, strlen('ewp_'));

        $request = new WP_REST_Request('GET', $route);
        $request->set_param('order_by', array('column' => 'created; DROP TABLE wp_users', 'type' => 'desc'));
        $request->set_param('status', array("enabled') OR ('1'='1"));
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(array(), $response->get_data(), 'the injected status must match nothing');

        $plain = new WP_REST_Request('GET', $route);
        $plain->set_param('order_by', array('column' => 'created; DROP TABLE wp_users', 'type' => 'desc'));
        $this->assertCount(1, rest_get_server()->dispatch($plain)->get_data(), 'a hostile ORDER BY is dropped, the list still works');
        $this->assertNotEmpty($GLOBALS['wpdb']->get_var("SHOW TABLES LIKE '" . $GLOBALS['wpdb']->users . "'"));
    }
}
