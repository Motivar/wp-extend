<?php
/**
 * awm_custom_content_duplicate(): the copy gets its own title suffix, meta and creation time.
 */

require_once __DIR__ . '/includes/trait-content-fixture.php';

class Test_Content_Duplicate extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string Content type with real tables. */
    private static $type;

    /**
     * Fixture type with the default fields.
     *
     * @return void
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();
        self::$type = self::create_content_type_fixture(array('capability' => 'edit_posts'));
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
    }

    /**
     * The copy is stamped with the current time instead of the zero date, and keeps the meta.
     *
     * @return void
     */
    public function test_copy_has_creation_time_and_meta()
    {
        $id   = (int) awm_custom_content_save(self::$type, array('content_id' => 'new', 'title' => 'Original', 'status' => 'enabled', 'awm_custom_meta' => array('required_field'), 'required_field' => 'kept'));
        $copy = (int) awm_custom_content_duplicate(self::$type, $id);

        $this->assertGreaterThan($id, $copy);
        $row = awm_get_db_content(self::$type, array('include' => array($copy)))[0];
        $this->assertSame('Original-copy', $row['content_title']);
        $this->assertNotSame('0000-00-00 00:00:00', $row['created']);
        $this->assertLessThan(120, abs(strtotime($row['created']) - strtotime(current_time('mysql'))), 'created is now');
        $this->assertSame('kept', awm_get_db_content_meta(self::$type, $copy, 'required_field'));
    }
}
