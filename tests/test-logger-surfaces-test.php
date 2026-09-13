<?php
/**
 * The logger on all three surfaces, generated from Logger_Resource over
 * EWP_Logger_Query. Historical REST bodies and CLI callables must hold.
 */

use EWP\Logger\EWP_Logger;
use EWP\Logger\EWP_Logger_CLI;
use EWP\Logger\EWP_Logger_Queue;

class Test_Logger_Surfaces extends WP_UnitTestCase
{
    const OWNER = 'phpunit-logger';

    public function set_up()
    {
        parent::set_up();
        \WP_CLI\StubRecorder::reset();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue(EWP_Logger::is_enabled(), 'tests/bootstrap.php enables the logger');
        ewp_register_log_owner(self::OWNER, 'PHPUnit logger');
        ewp_register_log_type(self::OWNER, 'phpunit_action', 'PHPUnit action', 'Written by the logger surfaces test.');
    }

    private function rest($method, $route, array $params = [])
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function write_entry($message)
    {
        $result = wp_get_ability('ewp-logger/write-entry')->execute([
            'owner'       => self::OWNER,
            'action_type' => 'phpunit_action',
            'message'     => $message,
            'behaviour'   => 'success',
        ]);
        $this->assertNotWPError($result);
        $this->assertTrue($result['logged']);
        EWP_Logger_Queue::flush();

        return $result;
    }

    public function test_rest_list_keeps_its_historical_body_and_finds_a_written_entry()
    {
        $this->write_entry('Listed over REST');

        $response = $this->rest('GET', '/extend-wp/v1/logs', ['owner' => self::OWNER, 'per_page' => 5]);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(['data', 'total', 'page', 'per_page'], array_keys($body));
        $this->assertSame(5, $body['per_page']);
        $this->assertSame(1, $body['page']);
        $this->assertGreaterThanOrEqual(1, $body['total']);
        $this->assertSame(self::OWNER, $body['data'][0]['owner']);
        $this->assertArrayHasKey('owner_label', $body['data'][0], 'REST entries are fully prepared');
    }

    public function test_rest_types_and_owners_keep_their_historical_bodies()
    {
        $types = $this->rest('GET', '/extend-wp/v1/logs/types')->get_data();
        $this->assertArrayHasKey('data', $types);
        $this->assertSame(['owner', 'type_key', 'label', 'description'], array_keys($types['data'][0]));

        $owners = $this->rest('GET', '/extend-wp/v1/logs/owners')->get_data();
        $this->assertArrayHasKey('data', $owners);
        $this->assertIsString($owners['data'][0]);
    }

    public function test_new_rest_routes_stats_entry_and_trace_exist()
    {
        $written = $this->write_entry('Traced');

        $stats = $this->rest('GET', '/extend-wp/v1/logs/stats', ['owner' => self::OWNER]);
        $this->assertSame(200, $stats->get_status());
        $this->assertArrayHasKey('by_behaviour', $stats->get_data());

        $trace = $this->rest('GET', '/extend-wp/v1/logs/trace/' . $written['request_id']);
        $this->assertSame(200, $trace->get_status());
        $this->assertSame($written['request_id'], $trace->get_data()['request_id']);

        $log_id = $trace->get_data()['entries'][0]['log_id'];
        $entry  = $this->rest('GET', '/extend-wp/v1/logs/entry/' . $log_id);
        $this->assertSame(200, $entry->get_status());
        $this->assertTrue($entry->get_data()['found']);
    }

    public function test_anonymous_callers_get_401_on_every_log_route()
    {
        wp_set_current_user(0);

        $this->assertSame(401, $this->rest('GET', '/extend-wp/v1/logs')->get_status());
        $this->assertSame(401, $this->rest('GET', '/extend-wp/v1/logs/stats')->get_status());
        $this->assertSame(401, $this->rest('POST', '/extend-wp/v1/logs', ['owner' => 'x', 'action_type' => 'y', 'message' => 'z'])->get_status());
    }

    public function test_cli_callables_print_through_the_shared_presenters()
    {
        $this->write_entry('Listed on the CLI');

        EWP_Logger_CLI::list_logs([], ['owner' => self::OWNER, 'limit' => 5]);
        $printed = \WP_CLI\StubRecorder::$printed;
        $this->assertNotEmpty($printed);
        $this->assertSame('success', $printed[0]['items'][0]['Status'], 'the CLI shows the shared behaviour label, not OK/ERROR');

        \WP_CLI\StubRecorder::reset();
        EWP_Logger_CLI::types([], []);
        $this->assertContains('phpunit_action', array_column(\WP_CLI\StubRecorder::$printed[0]['items'], 'Type Key'));

        \WP_CLI\StubRecorder::reset();
        EWP_Logger_CLI::cleanup([], ['months' => 1200]);
        $this->assertStringContainsString('Cleanup completed', \WP_CLI\StubRecorder::$success[0]);
    }

    public function test_delete_requires_confirmation_on_abilities_and_cli_but_not_rest()
    {
        $this->write_entry('To be deleted');

        $refused = wp_get_ability('ewp-logger/delete-entries')->execute(['owner' => self::OWNER]);
        $this->assertWPError($refused);
        // Core rejects the missing required `confirm` at the schema level before the kit's own check runs.
        $this->assertContains($refused->get_error_code(), ['ability_invalid_input', 'mwp_confirm_required']);

        $this->expectException(\WP_CLI\ExitException::class);
        EWP_Logger_CLI::delete([], ['owner' => self::OWNER]);
    }

    public function test_rest_delete_removes_matching_entries()
    {
        $this->write_entry('Gone via REST');

        $response = $this->rest('DELETE', '/extend-wp/v1/logs', ['owner' => self::OWNER]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['deleted', 'message'], array_keys($response->get_data()));
        $this->assertGreaterThanOrEqual(1, $response->get_data()['deleted']);
    }

    public function test_every_logger_operation_is_registered_as_an_ability()
    {
        foreach (['search', 'get-stats', 'list-vocabulary', 'get-entry', 'get-request-trace', 'cleanup', 'delete-entries', 'write-entry'] as $slug) {
            $this->assertTrue(wp_has_ability('ewp-logger/' . $slug), "ewp-logger/{$slug} must be registered");
        }
    }
}
