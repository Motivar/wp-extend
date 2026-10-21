<?php
/**
 * AWM_DB_Creator 1.5.9 performance fixes: autoloaded version options and
 * their one-time migration, table checks without INFORMATION_SCHEMA, and
 * no more $wpdb->show_errors() side effects.
 */

class Test_Db_Creator_Quick_Wins extends WP_UnitTestCase
{
    /** Table created for these tests (without prefix). */
    const TABLE = 'ewp_quickwins_test';

    /** @var string[] Queries captured by capture_queries(). */
    private $queries = array();

    /**
     * Create the test table outside the per-test transaction (CREATE TABLE
     * inside a test becomes a temporary table).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        new AWM_DB_Creator(array(
            self::TABLE => array(
                'data'       => array(
                    'id'    => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                    'label' => 'varchar(100) NOT NULL',
                ),
                'primaryKey' => 'id',
                'version'    => '1',
            ),
        ));
    }

    /**
     * Drop the test table and its version option.
     *
     * @return void
     */
    public static function tear_down_after_class()
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . self::TABLE) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(AWM_DB_Creator::VERSION_OPTION_PREFIX . self::TABLE);
        parent::tear_down_after_class();
    }

    /**
     * Fresh per-request state for every test.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        AWM_DB_Creator::reset();
        $this->queries = array();
    }

    /**
     * Stop capturing queries.
     *
     * @return void
     */
    public function tear_down()
    {
        remove_filter('query', array($this, 'record_query'));
        parent::tear_down();
    }

    /**
     * `query` filter: remember the SQL.
     *
     * @param string $sql Query about to run.
     * @return string Unchanged query.
     */
    public function record_query($sql)
    {
        $this->queries[] = $sql;
        return $sql;
    }

    /**
     * Start recording every query from now on.
     *
     * @return void
     */
    private function capture_queries()
    {
        // A real request has loaded the autoloaded options long before; the
        // test suite flushes the cache between tests.
        wp_load_alloptions();
        $this->queries = array();
        add_filter('query', array($this, 'record_query'));
    }

    /**
     * Autoload flag of an option as stored in the options table.
     *
     * @param string $name Option name.
     * @return string|null Raw `autoload` column value.
     */
    private function autoload_of($name)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    /**
     * dbUpdate() stores the schema version autoloaded.
     *
     * @return void
     */
    public function test_version_option_is_written_autoloaded()
    {
        $this->assertContains($this->autoload_of('ewp_version_' . self::TABLE), array('on', 'yes'));
    }

    /**
     * Version options written by older releases (autoload off) are switched
     * on once, and the flag is stored.
     *
     * @return void
     */
    public function test_migration_autoloads_existing_version_options()
    {
        add_option('ewp_version_ewp_legacy_table', '3', '', false);
        delete_option(AWM_DB_Creator::AUTOLOAD_MIGRATED_OPTION);
        $this->assertContains($this->autoload_of('ewp_version_ewp_legacy_table'), array('off', 'no'));

        AWM_DB_Creator::maybe_autoload_version_options();

        $this->assertContains($this->autoload_of('ewp_version_ewp_legacy_table'), array('on', 'yes'));
        $this->assertSame('1', (string) get_option(AWM_DB_Creator::AUTOLOAD_MIGRATED_OPTION));
        $this->assertContains($this->autoload_of(AWM_DB_Creator::AUTOLOAD_MIGRATED_OPTION), array('on', 'yes'));
    }

    /**
     * Once migrated, the check costs no query.
     *
     * @return void
     */
    public function test_migration_runs_once()
    {
        update_option(AWM_DB_Creator::AUTOLOAD_MIGRATED_OPTION, 1, true);
        $this->capture_queries();

        AWM_DB_Creator::maybe_autoload_version_options();
        AWM_DB_Creator::maybe_autoload_version_options();

        $this->assertSame(array(), $this->queries);
    }

    /**
     * A table created by AWM_DB_Creator is known from its autoloaded version
     * option: no query at all.
     *
     * @return void
     */
    public function test_known_table_needs_no_query()
    {
        $this->capture_queries();

        $this->assertTrue(AWM_DB_Creator::check_table_exists(self::TABLE));
        $this->assertSame(array(), $this->queries);
    }

    /**
     * Tables without a version option fall back to SHOW TABLES, never to
     * INFORMATION_SCHEMA, and the result is cached for the request.
     *
     * @return void
     */
    public function test_other_tables_use_show_tables_once()
    {
        $this->capture_queries();

        $this->assertTrue(AWM_DB_Creator::check_table_exists('posts'));
        $this->assertFalse(AWM_DB_Creator::check_table_exists('ewp_quickwins_missing'));
        $this->assertTrue(AWM_DB_Creator::check_table_exists('posts'));

        $show = array_filter($this->queries, function ($sql) {
            return stripos($sql, 'SHOW TABLES LIKE') !== false;
        });
        $this->assertCount(2, $show);
        $this->assertSame(array(), array_filter($this->queries, function ($sql) {
            return stripos($sql, 'INFORMATION_SCHEMA') !== false;
        }));
    }

    /**
     * `_` in a table name is matched literally, not as a LIKE wildcard.
     *
     * @return void
     */
    public function test_table_name_is_not_a_like_pattern()
    {
        $this->assertFalse(AWM_DB_Creator::check_table_exists('post_'));
    }

    /**
     * Reads and writes no longer switch $wpdb into printing SQL errors.
     *
     * @return void
     */
    public function test_crud_does_not_enable_show_errors()
    {
        global $wpdb;
        $previous = $wpdb->hide_errors();

        AWM_DB_Creator::insert_db_data(self::TABLE, array('label' => 'quick win'));
        AWM_DB_Creator::get_db_data(self::TABLE, '*');
        AWM_DB_Creator::update_db_data(self::TABLE, array('label' => 'renamed'), array(
            'clause' => array(array('clause' => array(array('column' => 'label', 'value' => 'quick win', 'compare' => '=')))),
        ));
        AWM_DB_Creator::delete_db_data(self::TABLE, array(
            'clause' => array(array('clause' => array(array('column' => 'label', 'value' => 'renamed', 'compare' => '=')))),
        ));

        $this->assertFalse($wpdb->show_errors);
        $wpdb->show_errors($previous);
    }
}
