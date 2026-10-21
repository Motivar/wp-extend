<?php
/**
 * tools/db-baseline: the temporary recorder that captures every extend-wp
 * DB-layer query before the AWM_DB_Creator rewrite.
 */

use EWP\Tools\Db_Baseline\Call_Site;
use EWP\Tools\Db_Baseline\Query_Recorder;
use EWP\Tools\Db_Baseline\Record_Writer;
use EWP\Tools\Db_Baseline\Shape;
use EWP\Tools\Db_Baseline\Summary;

class Test_Db_Baseline_Recorder extends WP_UnitTestCase
{
    /** @var string Throwaway records directory. */
    private $dir;

    /**
     * Load the tool classes (the plugin never loads them itself).
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        $base = dirname(__DIR__) . '/tools/db-baseline/';
        foreach (array('class-shape.php', 'class-call-site.php', 'class-record-writer.php', 'class-query-recorder.php', 'class-bootstrap.php', 'class-summary.php') as $file) {
            require_once $base . $file;
        }
    }

    /**
     * Fresh records directory per test.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $this->dir = get_temp_dir() . 'ewp-db-baseline-' . wp_generate_password(8, false);
    }

    /**
     * Remove the records directory.
     *
     * @return void
     */
    public function tear_down()
    {
        foreach ((array) glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tear_down();
    }

    /**
     * Data values become types; structural keys keep their values.
     *
     * @return void
     */
    public function test_shape_keeps_structure_and_hides_data()
    {
        $where = array(
            'operator' => 'AND',
            'clause'   => array(
                array('column' => 'id', 'compare' => '=', 'value' => 42),
                array('column' => 'status', 'compare' => 'IN', 'value' => "('a','b')"),
                array('column' => 'title', 'compare' => 'LIKE', 'value' => 'secret'),
            ),
        );

        $shape = Shape::of($where);

        $this->assertSame('AND', $shape['operator']);
        $this->assertSame(array('column' => 'id', 'compare' => '=', 'value' => 'integer'), $shape['clause'][0]);
        $this->assertSame('string:in-list', $shape['clause'][1]['value']);
        $this->assertSame('string', $shape['clause'][2]['value']);
    }

    /**
     * Same structure with different data shares one shape.
     *
     * @return void
     */
    public function test_shape_is_stable_across_values()
    {
        $this->assertSame(
            Shape::of(array('include' => array(1), 'status' => 'active')),
            Shape::of(array('include' => array(99), 'status' => 'draft'))
        );
    }

    /**
     * Samples cut long strings and name objects.
     *
     * @return void
     */
    public function test_sample_truncates_and_names_objects()
    {
        $sample = Shape::sample(array(str_repeat('x', 500), new stdClass()));

        $this->assertSame(Shape::MAX_STRING + strlen('…'), strlen($sample[0]));
        $this->assertSame('object:stdClass', $sample[1]);
    }

    /**
     * The outermost DB-layer frame is the entry, the innermost the runner.
     *
     * @return void
     */
    public function test_call_site_picks_outer_entry_and_inner_runner()
    {
        $frames = array(
            array('function' => 'query', 'class' => 'wpdb', 'file' => '/x/class-db-creator.php', 'line' => 470),
            array('function' => 'get_db_data', 'class' => 'AWM_DB_Creator', 'file' => '/x/content-functions.php', 'line' => 150, 'args' => array('t')),
            array('function' => 'awm_get_db_content', 'file' => '/w/wp-content/plugins/filox/coupons.php', 'line' => 12, 'args' => array('flx_coupon', array('status' => 'active'))),
            array('function' => 'flx_return_coupons', 'file' => '/w/wp-content/plugins/filox/x.php', 'line' => 3),
        );

        $site = Call_Site::resolve($frames);

        $this->assertSame('awm_get_db_content', $site['entry']);
        $this->assertSame('AWM_DB_Creator::get_db_data', $site['inner']);
        $this->assertSame(array('flx_coupon', array('status' => 'active')), $site['args']);
        $this->assertSame('plugins/filox/coupons.php', Call_Site::relative($site['file']));
        $this->assertSame(12, $site['line']);
    }

    /**
     * Queries that never pass through the DB layer are ignored.
     *
     * @return void
     */
    public function test_call_site_ignores_unrelated_frames()
    {
        $this->assertNull(Call_Site::resolve(array(
            array('function' => 'query', 'class' => 'wpdb'),
            array('function' => 'get_option'),
        )));
    }

    /**
     * Owners: plugin slug, bundled extend-wp marked with its host.
     *
     * @return void
     */
    public function test_owner_marks_bundled_copy()
    {
        $this->assertSame('filox-stripe', Call_Site::owner('/w/wp-content/plugins/filox-stripe/base.php'));
        $this->assertSame('extend-wp (bundled in filox)', Call_Site::owner('/w/wp-content/plugins/filox/includes/3rd-party/extend-wp/includes/x.php'));
        $this->assertSame('core', Call_Site::owner('/w/wp-includes/class-wpdb.php'));
    }

    /**
     * Recording never runs under PHPUnit, even when the site's mu-plugin stub
     * defines EWP_DB_BASELINE, and is off without the constant.
     *
     * @return void
     */
    public function test_bootstrap_disabled_in_tests()
    {
        $this->assertTrue(defined('WP_TESTS_DOMAIN'));
        $this->assertFalse(\EWP\Tools\Db_Baseline\Bootstrap::enabled());
        $this->assertNull(\EWP\Tools\Db_Baseline\Bootstrap::start());
    }

    /**
     * End to end: a real AWM_DB_Creator call is written with its results;
     * an unrelated query is not.
     *
     * @return void
     */
    public function test_recorder_writes_db_layer_queries_only()
    {
        $recorder = new Query_Recorder(new Record_Writer($this->dir), 'req-1');
        $recorder->register();

        AWM_DB_Creator::check_table_exists('ewp_baseline_missing_' . wp_generate_password(6, false));
        get_posts(array('numberposts' => 1, 'suppress_filters' => false));
        $recorder->flush();

        remove_filter('query', array($recorder, 'on_query'), PHP_INT_MAX);
        remove_action('shutdown', array($recorder, 'flush'), PHP_INT_MAX);

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('AWM_DB_Creator::check_table_exists', $records[0]['entry']);
        $this->assertSame('req-1', $records[0]['request']);
        $this->assertSame(1, $records[0]['rows']);
        $this->assertNotEmpty($records[0]['result_hash']);
        $this->assertStringContainsString('INFORMATION_SCHEMA', $records[0]['sql']);
        $this->assertFileExists($this->dir . '/index.php');
    }

    /**
     * Summary groups by fingerprint and keeps distinct samples.
     *
     * @return void
     */
    public function test_summary_groups_by_fingerprint()
    {
        $base = array(
            'fingerprint' => 'f1', 'owner' => 'filox', 'call' => 'plugins/filox/a.php:1',
            'entry' => 'awm_get_db_content', 'inner' => 'AWM_DB_Creator::get_db_data', 'shape' => array(),
            'context' => array('kind' => 'rest', 'target' => '/wp-json/x'), 'args' => array(1),
            'sql' => 'SELECT 1', 'rows' => 2, 'result_hash' => 'h1', 'error' => '',
        );

        $groups = Summary::add(array(), $base);
        $groups = Summary::add($groups, array('sql' => 'SELECT 2', 'rows' => 3, 'result_hash' => 'h2') + $base);
        $groups = Summary::add($groups, array('fingerprint' => 'f2', 'error' => 'boom') + $base);

        $this->assertSame(2, $groups['f1']['count']);
        $this->assertSame(array('SELECT 1', 'SELECT 2'), $groups['f1']['sql']);
        $this->assertSame(array(2, 3), $groups['f1']['rows']);
        $this->assertSame(array('boom'), $groups['f2']['errors']);
        $this->assertStringContainsString('2 distinct call shapes, 3 queries.', Summary::markdown($groups));
    }

    /**
     * Fixtures carry the group data and never a nonce value.
     *
     * @return void
     */
    public function test_fixtures_scrub_nonces()
    {
        $record = array(
            'fingerprint' => str_repeat('a', 40), 'owner' => 'filox', 'call' => 'plugins/filox/a.php:1',
            'entry' => 'AWM_DB_Creator::get_db_data', 'inner' => 'AWM_DB_Creator::get_db_data', 'shape' => array('string'),
            'context' => array('kind' => 'ajax', 'target' => '/wp-admin/admin-ajax.php?action=x&nonce=abc123&_wpnonce=def456'),
            'args' => array('flx_rates'), 'sql' => 'SELECT 1', 'rows' => 1, 'result_hash' => 'h', 'error' => '',
        );

        $fixtures = Summary::fixtures(Summary::add(array(), $record));

        $this->assertSame(str_repeat('a', 12), $fixtures[0]['id']);
        $this->assertSame(array('SELECT 1'), $fixtures[0]['sql']);
        $this->assertSame(array('ajax /wp-admin/admin-ajax.php?action=x&nonce=x&_wpnonce=x'), $fixtures[0]['requests']);
    }

    /**
     * Decoded records from the throwaway directory.
     *
     * @return array List of records.
     */
    private function records()
    {
        $records = array();
        foreach ((array) glob($this->dir . '/records-*.jsonl') as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $records[] = json_decode($line, true);
            }
        }
        return $records;
    }
}
