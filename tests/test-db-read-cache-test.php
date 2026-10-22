<?php
/**
 * Phase 3 (store-level performance): EWP\Db\Read_Cache, EWP\Db\Content_Meta
 * (batched meta) and the `_data` (meta_key, meta_value(191)) index.
 */

use EWP\Db\Content_Meta;
use EWP\Db\Read_Cache;

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Db_Read_Cache extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Content type with real tables. */
    private static $type;

    /** @var string[] SQL run by the last queries() call. */
    private $last_queries = array();

    /** @var string Plain custom table (not a content type). */
    private static $plain;

    /**
     * Content type and a plain table, created once (real tables, see the fixture trait).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$type  = self::create_content_type_fixture(array('capability' => 'edit_posts'));
        self::$plain = 'ewp_cache_' . strtolower(wp_generate_password(6, false));
        new AWM_DB_Creator(array(self::$plain => array(
            'data'       => array('id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'label' => 'varchar(20) NOT NULL'),
            'primaryKey' => 'id',
            'version'    => '1',
        )));
    }

    /**
     * Drop the plain table.
     *
     * @return void
     */
    public static function tear_down_after_class()
    {
        AWM_DB_Creator::drop_table(self::$plain);
        parent::tear_down_after_class();
    }

    /**
     * Admin user (content saves record user_id, a foreign key), a clean primed store, shadow off.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        Content_Meta::forget();
        // Query counts: the shadow comparison (EWP_DB_SHADOW, e.g. from a dev mu-plugin) adds a legacy SELECT per read.
        add_filter('ewp_db_shadow_enabled', '__return_false');
    }

    /**
     * Save one item of the fixture type.
     *
     * @param string $value example_field value.
     * @return int Content id.
     */
    private function save_item($value)
    {
        return (int) awm_custom_content_save(self::$type, array(
            'content_id'      => 'new',
            'title'           => 'Item ' . $value,
            'status'          => 'enabled',
            'awm_custom_meta' => array('example_field', 'required_field'),
            'example_field'   => $value,
            'required_field'  => 'x',
        ));
    }

    /**
     * Number of queries a callback runs.
     *
     * @param callable $callback Code to measure.
     * @return int Queries.
     */
    private function queries(callable $callback)
    {
        $this->last_queries = array();
        $record = function ($sql) {
            $this->last_queries[] = $sql;
            return $sql;
        };
        add_filter('query', $record);
        $callback();
        remove_filter('query', $record);
        return count($this->last_queries);
    }

    /**
     * Only content-type tables are cached by default; the filter opts others in or out.
     *
     * @return void
     */
    public function test_enabled_for_content_tables_and_filter()
    {
        $this->assertTrue(Read_Cache::enabled_for(self::$type . '_main'));
        $this->assertTrue(Read_Cache::enabled_for(self::$type . '_data'));
        $this->assertFalse(Read_Cache::enabled_for(self::$plain), 'tables written with raw $wpdb (filox rates, sessions) must stay uncached');
        $this->assertFalse(Read_Cache::enabled_for('unregistered_main'));

        $opt_in = function ($enabled, $table) {
            return $table === self::$plain ? true : $enabled;
        };
        add_filter('ewp_db_cache_table', $opt_in, 10, 2);
        $this->assertTrue(Read_Cache::enabled_for(self::$plain));
        remove_filter('ewp_db_cache_table', $opt_in, 10);
    }

    /**
     * A repeated read is served from the cache; every kind of write makes it fresh again.
     *
     * @return void
     */
    public function test_cached_read_is_invalidated_by_writes()
    {
        $id    = $this->save_item('red');
        $where = array('column' => 'content_id', 'value' => $id);
        $read  = function () use ($where) {
            return AWM_DB_Creator::get_db_data(self::$type . '_main', '*', $where);
        };

        $first = $read();
        $this->assertSame(0, $this->queries($read), 'second identical read hits the cache');
        $this->assertSame($first, $read());

        AWM_DB_Creator::update_db_data(self::$type . '_main', array('content_title' => 'Renamed'), $where);
        $rows = $read();
        $this->assertSame('Renamed', $rows[0]['content_title'], 'update invalidates');

        $other = $this->save_item('blue');
        $all   = AWM_DB_Creator::get_db_data(self::$type . '_main', '*', array('column' => 'content_id', 'compare' => 'IN', 'value' => array($id, $other)));
        $this->assertCount(2, $all, 'insert invalidates');

        awm_custom_content_delete(self::$type, array($other));
        $this->assertCount(1, AWM_DB_Creator::get_db_data(self::$type . '_main', '*', array('column' => 'content_id', 'compare' => 'IN', 'value' => array($id, $other))), 'delete invalidates');
    }

    /**
     * Uncached tables query every time, so raw `$wpdb` writes are always seen.
     *
     * @return void
     */
    public function test_plain_table_is_not_cached()
    {
        global $wpdb;
        AWM_DB_Creator::insert_db_data(self::$plain, array('label' => 'a'));
        $read = function () {
            return AWM_DB_Creator::get_db_data(self::$plain, '*', array('column' => 'label', 'value' => 'a'));
        };
        $read();
        $this->assertSame(1, $this->queries($read));

        $wpdb->update($wpdb->prefix . self::$plain, array('label' => 'b'), array('label' => 'a'));
        $this->assertSame(array(), $read(), 'raw write visible immediately');
    }

    /**
     * A read with a meta sub-query depends on `_data` too: a meta write makes it fresh.
     *
     * @return void
     */
    public function test_subquery_read_follows_data_table_writes()
    {
        $id   = $this->save_item('green');
        $find = function ($value) {
            return awm_get_db_content(self::$type, array('meta_query' => array(array('key' => 'example_field', 'value' => $value))));
        };
        $this->assertCount(1, $find('green'));
        $this->assertSame(array(), $find('teal'));

        awm_insert_db_content_meta(self::$type, $id, array('example_field' => 'teal'));
        $this->assertCount(1, $find('teal'), '_data write invalidates the _main read that sub-queries it');
        $this->assertSame(array(), $find('green'));

        $this->assertSame(array(self::$type . '_main', self::$type . '_data'), Read_Cache::tables_of(self::$type . '_main', awm_db_content_meta_query_where(self::$type, array(array('key' => 'k', 'value' => 'v')))));
    }

    /**
     * Read_Cache::invalidate() is the hook for raw writers that opted a table in.
     *
     * @return void
     */
    public function test_invalidate_for_raw_writers()
    {
        global $wpdb;
        $opt_in = function ($enabled, $table) {
            return $table === self::$plain ? true : $enabled;
        };
        add_filter('ewp_db_cache_table', $opt_in, 10, 2);
        AWM_DB_Creator::insert_db_data(self::$plain, array('label' => 'x'));
        $read = function () {
            return AWM_DB_Creator::get_db_data(self::$plain, '*', array('column' => 'label', 'value' => 'x'));
        };
        $this->assertCount(1, $read());

        $wpdb->update($wpdb->prefix . self::$plain, array('label' => 'y'), array('label' => 'x'));
        $this->assertCount(1, $read(), 'opted-in table without invalidate(): stale, as documented');
        Read_Cache::invalidate(self::$plain);
        $this->assertSame(array(), $read());
        remove_filter('ewp_db_cache_table', $opt_in, 10);
    }

    /**
     * Batched meta: one query for many items, then per-item reads without queries, same values.
     *
     * @return void
     */
    public function test_prime_loads_meta_in_one_query()
    {
        $ids = array();
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->save_item('v' . $i);
        }
        $expected = array();
        foreach ($ids as $id) {
            $expected[$id] = awm_get_db_content_meta(self::$type, $id);
        }
        Content_Meta::forget();
        wp_cache_flush();

        $primed = null;
        $this->assertSame(1, $this->queries(function () use ($ids, &$primed) {
            $primed = awm_get_db_content_metas(self::$type, $ids);
        }));
        $this->assertSame($expected, $primed);

        $this->assertSame(0, $this->queries(function () use ($ids, $expected) {
            foreach ($ids as $id) {
                $this->assertSame($expected[$id], awm_get_db_content_meta(self::$type, $id));
                $this->assertSame($expected[$id]['example_field'], awm_get_db_content_meta(self::$type, $id, 'example_field'));
                $this->assertFalse(awm_get_db_content_meta(self::$type, $id, 'missing_key'));
            }
        }));
    }

    /**
     * Primed meta goes stale with a `_data` write; items without meta stay `false`.
     *
     * @return void
     */
    public function test_primed_meta_follows_writes()
    {
        $id    = $this->save_item('one');
        $empty = (int) awm_insert_db_content(self::$type, array('content_title' => 'No meta', 'status' => 'enabled', 'user_id' => get_current_user_id()));
        awm_get_db_content_metas(self::$type, array($id, $empty));

        $this->assertFalse(awm_get_db_content_meta(self::$type, $empty), 'no meta → false, as before');
        awm_insert_db_content_meta(self::$type, $id, array('example_field' => 'two'));
        $this->assertSame('two', awm_get_db_content_meta(self::$type, $id, 'example_field'));
        $this->assertSame(array(), awm_get_db_content_metas(self::$type, array()), 'no ids, no query');
    }

    /**
     * Content list with meta: one `_main` query plus one `_data` query, whatever the row count.
     *
     * @return void
     */
    public function test_service_list_with_meta_is_two_queries()
    {
        for ($i = 0; $i < 50; $i++) {
            $this->save_item('n' . $i);
        }
        wp_cache_flush();
        Content_Meta::forget();

        $result = null;
        $count  = $this->queries(function () use (&$result) {
            $result = (new \EWP\Content\Content_Service())->list_items(self::$type, array('limit' => 50, 'with_meta' => true));
        });
        $this->assertSame(50, $result['count']);
        $this->assertSame(2, $count, 'one row query + one batched meta query: ' . implode(' || ', $this->last_queries));
        $this->assertSame('x', $result['items'][0]['meta']->required_field);
    }

    /**
     * The `_data` table carries the (meta_key, meta_value(191)) index, and a table from
     * before 1.6.2 gets it on the version bump.
     *
     * @return void
     */
    public function test_data_table_has_meta_value_index()
    {
        global $wpdb;
        $this->assertContains('meta_key,meta_value(191)', $this->index_signatures(self::$type . '_data'));

        $legacy = 'ewp_cache_idx_' . strtolower(wp_generate_password(6, false)) . '_data';
        $schema = array(
            'data'       => array('content_id' => 'bigint(20) NOT NULL', 'meta_key' => 'varchar(100) NOT NULL', 'meta_value' => 'LONGTEXT'),
            'primaryKey' => 'content_id,meta_key',
            'index'      => 'meta_key',
            'version'    => '1.0.1.0.1',
        );
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        new AWM_DB_Creator(array($legacy => $schema));
        $this->assertNotContains('meta_key,meta_value(191)', $this->index_signatures($legacy));

        $schema['index']   = array('meta_key', array('meta_key', 'meta_value(191)'));
        $schema['version'] = '1.0.1.0.2';
        new AWM_DB_Creator(array($legacy => $schema));
        $signatures = $this->index_signatures($legacy);
        AWM_DB_Creator::drop_table($legacy);

        $this->assertContains('meta_key,meta_value(191)', $signatures);
        $this->assertSame(1, count(array_keys($signatures, 'meta_key', true)), 'no duplicate meta_key index');
    }

    /**
     * Index column signatures of a table.
     *
     * @param string $table Table without prefix.
     * @return string[] Index name => `col,col(len)`.
     */
    private function index_signatures($table)
    {
        global $wpdb;
        $indexes = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $wpdb->prefix . $table)) as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = strtolower($row->Column_name) . ($row->Sub_part ? '(' . (int) $row->Sub_part . ')' : '');
        }
        return array_map(function ($columns) {
            ksort($columns);
            return implode(',', $columns);
        }, $indexes);
    }
}
