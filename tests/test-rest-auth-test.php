<?php
/**
 * Authorization of the REST surfaces after the 1.5.0 security fixes.
 *
 * Every custom content type's read routes were public because
 * AWM_Dynamic_API defaulted a missing permission_callback to `return true`;
 * writes required manage_options regardless of the type's own capability.
 * Now reads and writes both use the type's capability, `public_read` is an
 * explicit opt-in, and the dynamic API denies by default.
 */
class Test_Rest_Auth extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string */
    private static $type;

    /** @var string */
    private static $public_type;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$type        = self::create_content_type_fixture(['capability' => 'edit_posts']);
        self::$public_type = self::create_content_type_fixture(['public_read' => true, 'capability' => 'manage_options']);
        self::refresh_rest_routes();
    }

    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(0);
    }

    private function dispatch($method, $path, array $params = [])
    {
        $request = new WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function route($type, $suffix = '')
    {
        return '/ewp/' . substr($type, strlen('ewp_')) . $suffix;
    }

    public function test_anonymous_cannot_read_a_content_type_that_did_not_opt_in()
    {
        $this->assertSame(401, $this->dispatch('GET', $this->route(self::$type))->get_status());
        $this->assertSame(401, $this->dispatch('GET', $this->route(self::$type, '/1'))->get_status());
    }

    public function test_public_read_opt_in_serves_anonymous_reads_but_never_writes()
    {
        $this->assertSame(200, $this->dispatch('GET', $this->route(self::$public_type))->get_status());

        $create = $this->dispatch('POST', $this->route(self::$public_type, '/create'), ['title' => 'x', 'meta' => ['required_field' => 'v']]);
        $this->assertSame(401, $create->get_status());
    }

    public function test_reads_and_writes_use_the_content_types_own_capability()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->dispatch('GET', $this->route(self::$type))->get_status());
        $this->assertSame(403, $this->dispatch('POST', $this->route(self::$type, '/create'), ['title' => 'x', 'meta' => ['required_field' => 'v']])->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(200, $this->dispatch('GET', $this->route(self::$type))->get_status());
        $created = $this->dispatch('POST', $this->route(self::$type, '/create'), ['title' => 'Author row', 'meta' => ['required_field' => 'v']]);
        $this->assertSame(201, $created->get_status(), 'edit_posts is the type capability, so an author may create');

        $deleted = $this->dispatch('DELETE', $this->route(self::$type, '/delete'), ['ids' => (string) $created->get_data()['id']]);
        $this->assertSame(200, $deleted->get_status());
        $this->assertSame(1, $deleted->get_data()['count'], 'REST delete now goes through the shared service');
    }

    public function test_dynamic_api_denies_by_default_and_honours_the_public_flag()
    {
        $api = new AWM_Dynamic_API([
            'closed' => ['endpoint' => 'auth-test-closed', 'namespace' => 'ewp-test/v1', 'method' => 'get', 'php_callback' => '__return_empty_array'],
            'open'   => ['endpoint' => 'auth-test-open', 'namespace' => 'ewp-test/v1', 'method' => 'get', 'php_callback' => '__return_empty_array', 'public' => true],
        ]);
        // Routes must be registered on rest_api_init; re-fire it on a fresh server.
        add_action('rest_api_init', [$api, 'register_routes']);
        self::refresh_rest_routes();

        $this->assertSame(401, $this->dispatch('GET', '/ewp-test/v1/auth-test-closed')->get_status());
        $this->assertSame(200, $this->dispatch('GET', '/ewp-test/v1/auth-test-open')->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(200, $this->dispatch('GET', '/ewp-test/v1/auth-test-closed')->get_status());
    }

    public function test_field_builder_helpers_and_map_options_need_a_user()
    {
        $this->assertSame(401, $this->dispatch('GET', '/extend-wp/v1/get-case-fields')->get_status());
        $this->assertSame(401, $this->dispatch('GET', '/extend-wp/v1/awm-map-options')->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->dispatch('GET', '/extend-wp/v1/get-case-fields')->get_status(), 'field builder helpers need edit_posts');
        $this->assertSame(200, $this->dispatch('GET', '/extend-wp/v1/awm-map-options')->get_status(), 'map options only need a logged-in user');
    }
}
