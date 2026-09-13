<?php
/**
 * Tests for EWP\Content\Content_Service, the shared implementation
 * behind the generic content-type REST routes (AWM_Add_Content_DB_API),
 * the `wp ewp content` CLI commands (EWP_Content_CLI), and the
 * ewp-content/ewp-fields/ewp-wp-content abilities. Covering it here
 * covers all three surfaces' persistence and validation behaviour at
 * once.
 */
class Test_Content_Service extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string */
    private static $type;

    /** @var \EWP\Content\Content_Service */
    private $service;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$type = self::create_content_type_fixture();
    }

    public function set_up()
    {
        parent::set_up();
        $this->service = new \EWP\Content\Content_Service();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_create_item_requires_required_meta()
    {
        $result = $this->service->create_item(self::$type, 'Missing required field', '', []);

        $this->assertWPError($result);
        $this->assertSame('ewp_abilities_missing_required_meta', $result->get_error_code());
    }

    public function test_create_item_rejects_unknown_meta_key()
    {
        $result = $this->service->create_item(self::$type, 'Bad meta key', '', [
            'required_field' => 'value',
            'not_a_real_key' => 'value',
        ]);

        $this->assertWPError($result);
        $this->assertSame('ewp_abilities_unknown_meta_key', $result->get_error_code());
    }

    public function test_create_item_happy_path_defaults_status_and_normalizes_row()
    {
        $result = $this->service->create_item(self::$type, 'My Item', '', [
            'required_field' => 'hello',
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame(self::$type, $result['content_type']);
        $this->assertSame('My Item', $result['title']);
        // No status was given; the service falls back to the first registered status.
        $this->assertSame('enabled', $result['status']);
        $this->assertSame('hello', $result['meta']->required_field);
    }

    public function test_update_item_merges_patch_without_touching_other_fields()
    {
        $created = $this->service->create_item(self::$type, 'Original title', 'enabled', [
            'required_field' => 'hello',
        ]);
        $this->assertIsArray($created);

        $updated = $this->service->update_item(self::$type, $created['id'], ['status' => 'disabled']);

        $this->assertIsArray($updated);
        $this->assertSame('disabled', $updated['status']);
        // Title was not part of the patch, so it must be unchanged.
        $this->assertSame('Original title', $updated['title']);
    }

    public function test_update_item_rejects_unknown_status()
    {
        $created = $this->service->create_item(self::$type, 'Item', 'enabled', [
            'required_field' => 'hello',
        ]);

        $result = $this->service->update_item(self::$type, $created['id'], ['status' => 'not-a-real-status']);

        $this->assertWPError($result);
        $this->assertSame('ewp_abilities_invalid_status', $result->get_error_code());
    }

    public function test_delete_items_removes_the_row()
    {
        $created = $this->service->create_item(self::$type, 'To be deleted', 'enabled', [
            'required_field' => 'hello',
        ]);

        $deleted = $this->service->delete_items(self::$type, [$created['id']]);

        $this->assertIsArray($deleted);
        $this->assertSame([$created['id']], $deleted['deleted']);

        $after = $this->service->get_item(self::$type, $created['id']);
        $this->assertWPError($after);
        $this->assertSame('ewp_abilities_not_found', $after->get_error_code());
    }

    public function test_list_items_returns_created_rows()
    {
        $first  = $this->service->create_item(self::$type, 'First', 'enabled', ['required_field' => 'a']);
        $second = $this->service->create_item(self::$type, 'Second', 'enabled', ['required_field' => 'b']);

        $listed = $this->service->list_items(self::$type, ['limit' => 10]);

        $ids = array_map(function ($item) {
            return $item['id'];
        }, $listed['items']);
        $this->assertContains($first['id'], $ids);
        $this->assertContains($second['id'], $ids);
    }
}
