<?php
/**
 * EWP\Db\Shadow: the dev/staging comparison of the old and new builders.
 */

use EWP\Db\Shadow;

class Test_Db_Shadow extends WP_UnitTestCase
{
    /** @var string Raw table (without prefix). */
    private static $table;

    /**
     * Real table for the comparisons.
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$table = 'ewp_shadow_' . strtolower(wp_generate_password(6, false));
        new AWM_DB_Creator(array(self::$table => array(
            'data'       => array('id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'status' => 'varchar(20) NOT NULL'),
            'primaryKey' => 'id',
            'version'    => '1',
        )));
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public static function tear_down_after_class()
    {
        AWM_DB_Creator::drop_table(self::$table);
        parent::tear_down_after_class();
    }

    /**
     * Off without the constant (and never on production).
     *
     * @return void
     */
    public function test_disabled_by_default()
    {
        if (defined('EWP_DB_SHADOW')) {
            $this->markTestSkipped('EWP_DB_SHADOW is defined in this run.');
        }
        $this->assertFalse(Shadow::enabled());
    }

    /**
     * Row sets compare as multisets, ignoring order.
     *
     * @return void
     */
    public function test_same_rows_ignores_order_only()
    {
        $a = array(array('id' => '1'), array('id' => '2'));
        $this->assertTrue(Shadow::same_rows($a, array_reverse($a)));
        $this->assertFalse(Shadow::same_rows($a, array(array('id' => '1'))));
        $this->assertFalse(Shadow::same_rows($a, array(array('id' => '1'), array('id' => '1'))));
    }

    /**
     * Matching reads pass; a difference is reported with the shadow tag.
     *
     * @return void
     */
    public function test_compare_select_reports_differences()
    {
        global $wpdb;
        AWM_DB_Creator::insert_db_data(self::$table, array('status' => 'a'));
        AWM_DB_Creator::insert_db_data(self::$table, array('status' => 'b'));
        $where  = array('column' => 'status', 'value' => 'a', 'compare' => '=');
        $legacy = AWM_DB_Creator::legacy_select_sql(self::$table, '*', array('clause' => array(array('clause' => array($where)))), array(), '', 0);
        $rows   = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE status = %s', $wpdb->prefix . self::$table, 'a'), ARRAY_A);

        $this->assertTrue(Shadow::compare_select(self::$table, $legacy, $rows));

        $log = tempnam(get_temp_dir(), 'shadow');
        $old = ini_set('error_log', $log);
        $this->assertFalse(Shadow::compare_select(self::$table, $legacy, array()));
        ini_set('error_log', $old);
        $this->assertStringContainsString(Shadow::TAG . ' select on ' . self::$table . ' differs (1 old rows vs 0 new rows)', (string) file_get_contents($log));
        unlink($log);
    }

    /**
     * Write targets: equal WHEREs pass; an invalid new WHERE is left to the write's own refusal.
     *
     * @return void
     */
    public function test_compare_targets()
    {
        AWM_DB_Creator::insert_db_data(self::$table, array('status' => 'a'));
        $where = array('clause' => array(array('clause' => array(array('column' => 'status', 'value' => 'a', 'compare' => '=')))));

        $this->assertTrue(Shadow::compare_targets(self::$table, $where, AWM_DB_Creator::legacy_where_sql($where), 'update'));
        $this->assertTrue(Shadow::compare_targets(self::$table, array('column' => 'nope', 'value' => 1), '', 'delete'));
    }
}
