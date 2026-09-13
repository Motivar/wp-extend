<?php
/**
 * Content export/import, object search and the REST-health inventory on
 * their new surfaces, with the historical REST bodies preserved.
 */
class Test_New_Scope_Surfaces extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string */
    private static $type;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$type = self::create_content_type_fixture();
        self::refresh_rest_routes();
    }

    public function set_up()
    {
        parent::set_up();
        \WP_CLI\StubRecorder::reset();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function rest($method, $route, array $params = [])
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    public function test_content_export_keeps_the_rest_string_contract_and_returns_an_object_to_abilities()
    {
        $service = new \EWP\Content\Content_Service();
        $service->create_item(self::$type, 'Exported row', 'enabled', ['required_field' => 'v']);

        $rest = $this->rest('GET', '/ewp/v1/export', ['content_types' => [self::$type]]);
        $this->assertSame(200, $rest->get_status());
        $this->assertIsString($rest->get_data(), 'REST answers with the JSON-encoded payload, as the admin screen expects');
        $this->assertStringContainsString('Exported row', $rest->get_data());

        $ability = wp_get_ability('ewp-content/export')->execute(['content_types' => [self::$type]]);
        $this->assertNotWPError($ability);
        $this->assertArrayHasKey(self::$type, $ability);
    }

    public function test_content_import_upserts_by_hash_and_rest_answers_true()
    {
        $service = new \EWP\Content\Content_Service();
        $service->create_item(self::$type, 'Round trip', 'enabled', ['required_field' => 'v']);
        $payload = wp_get_ability('ewp-content/export')->execute(['content_types' => [self::$type]]);
        $rows    = array_values($payload[self::$type]);
        $before  = $service->list_items(self::$type, ['limit' => 50])['count'];

        $rest = $this->rest('POST', '/ewp/v1/import', ['content_type' => self::$type, 'content' => $rows]);
        $this->assertSame(200, $rest->get_status());
        $this->assertTrue($rest->get_data(), 'REST keeps answering true');
        $this->assertSame($before, $service->list_items(self::$type, ['limit' => 50])['count'], 'same hash means no duplicate row');

        $refused = wp_get_ability('ewp-content/import')->execute(['content_type' => self::$type, 'content' => $rows]);
        $this->assertWPError($refused);

        $result = wp_get_ability('ewp-content/import')->execute(['content_type' => self::$type, 'content' => $rows, 'confirm' => true]);
        $this->assertNotWPError($result);
        $this->assertSame(count($rows), $result['count']);
    }

    public function test_object_search_finds_a_post_on_rest_and_ability_with_their_own_shapes()
    {
        $post_id = self::factory()->post->create(['post_title' => 'Needle in the haystack']);

        $rest = $this->rest('GET', '/extend-wp/v1/objects/search', ['object_type' => 'post_type:post', 'search' => 'Needle']);
        $this->assertSame(200, $rest->get_status());
        $body = $rest->get_data();
        $this->assertTrue($body['success']);
        $this->assertSame($post_id, (int) $body['data'][0]['id']);

        $ability = wp_get_ability('ewp-system/search-objects')->execute(['object_type' => 'post_type:post', 'search' => 'Needle']);
        $this->assertNotWPError($ability);
        $this->assertSame(1, $ability['count']);
        $this->assertSame($post_id, (int) $ability['results'][0]['id']);

        $bad = wp_get_ability('ewp-system/search-objects')->execute(['object_type' => 'nonsense', 'search' => 'x']);
        $this->assertWPError($bad);
        $this->assertSame('awm_invalid_object_type', $bad->get_error_code());
    }

    public function test_rest_health_inventory_lists_this_plugin_on_every_surface()
    {
        grant_super_admin(get_current_user_id());
        // The test bootstrap requires the plugin directly; the inventory reads the active_plugins option.
        update_option('active_plugins', ['wp-extend/extend-wp.php']);

        $plugins = $this->rest('GET', '/extend-wp/v1/rest-health/plugins', ['refresh' => true]);
        $this->assertSame(200, $plugins->get_status());
        $paths = array_column($plugins->get_data(), 'path');
        $mine  = array_values(array_filter($paths, function ($path) {
            return strpos($path, 'extend-wp.php') !== false;
        }));
        $this->assertNotEmpty($mine, 'wp-extend must appear in its own inventory');

        $endpoints = $this->rest('POST', '/extend-wp/v1/rest-health/endpoints', ['plugins' => [$mine[0]]]);
        $this->assertSame(200, $endpoints->get_status());
        $this->assertNotEmpty($endpoints->get_data());

        $ability = wp_get_ability('ewp-rest-health/list-endpoints')->execute(['plugins' => [$mine[0]]]);
        $this->assertNotWPError($ability);
        $this->assertNotEmpty($ability);

        $this->assertSame(400, $this->rest('POST', '/extend-wp/v1/rest-health/endpoints', ['plugins' => []])->get_status());
    }

    public function test_new_commands_and_abilities_are_registered()
    {
        foreach (['ewp-content/export', 'ewp-content/import', 'ewp-system/search-objects', 'ewp-rest-health/list-plugins', 'ewp-rest-health/list-endpoints'] as $name) {
            $this->assertTrue(wp_has_ability($name), "{$name} must be registered");
        }
    }
}
