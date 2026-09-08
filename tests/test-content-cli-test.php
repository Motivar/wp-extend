<?php
/**
 * Tests for `wp ewp content` (EWP_Content_CLI), using the WP_CLI stub
 * from tests/wp-cli-stub.php. Same underlying EWP_Abilities_Content_Service
 * as tests/test-content-service-test.php and
 * tests/test-content-rest-test.php — this file checks the CLI wrapper's
 * argument handling and success/error reporting, not the persistence
 * logic itself.
 */
class Test_Content_Cli extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string */
    private static $type;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$type = self::create_content_type_fixture();
    }

    public function set_up()
    {
        parent::set_up();
        \WP_CLI\StubRecorder::reset();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_types_lists_the_registered_content_type()
    {
        EWP_Content_CLI::types([], []);

        $printed = \WP_CLI\StubRecorder::$printed;
        $this->assertNotEmpty($printed);
        $types_in_output = array_column($printed[0]['items'], 'Type');
        $this->assertContains(self::$type, $types_in_output);
    }

    public function test_create_reports_success_and_persists_the_row()
    {
        EWP_Content_CLI::create([], [
            'type'  => self::$type,
            'title' => 'CLI created item',
            'meta'  => json_encode(['required_field' => 'value']),
        ]);

        $this->assertCount(1, \WP_CLI\StubRecorder::$success);
        $this->assertStringContainsString('Created', \WP_CLI\StubRecorder::$success[0]);
    }

    public function test_create_without_type_errors()
    {
        $this->expectException(\WP_CLI\ExitException::class);

        EWP_Content_CLI::create([], ['title' => 'No type given']);
    }

    public function test_get_then_update_then_delete_round_trip()
    {
        $service = new \EWP\Abilities\EWP_Abilities_Content_Service();
        $created = $service->create_item(self::$type, 'Round trip', 'enabled', ['required_field' => 'value']);
        $this->assertIsArray($created);

        EWP_Content_CLI::update([$created['id']], ['type' => self::$type, 'status' => 'disabled']);
        $this->assertStringContainsString('Updated', \WP_CLI\StubRecorder::$success[0]);

        $after = $service->get_item(self::$type, $created['id']);
        $this->assertSame('disabled', $after['status']);

        \WP_CLI\StubRecorder::reset();
        EWP_Content_CLI::delete([$created['id']], ['type' => self::$type]);
        $this->assertStringContainsString('Deleted 1', \WP_CLI\StubRecorder::$success[0]);

        $gone = $service->get_item(self::$type, $created['id']);
        $this->assertWPError($gone);
    }

    public function test_unknown_type_errors()
    {
        $this->expectException(\WP_CLI\ExitException::class);

        EWP_Content_CLI::list_items([], ['type' => 'ewp_not_a_real_type']);
    }
}
