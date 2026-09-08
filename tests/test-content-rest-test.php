<?php
/**
 * Tests for the generic content-type REST routes
 * (AWM_Add_Content_DB_API / AWM_Add_Content_DB_Setup::rest_endpoints()).
 *
 * Covers the bug fix (create/update used to be a dead stub and a fatal
 * error respectively) and the `writable` flag that keeps REST from
 * exposing write routes for read-only content types like search filters.
 */
class Test_Content_Rest extends WP_UnitTestCase
{
    use EWP_Test_Content_Fixture;

    /** @var string */
    private static $type;

    /** @var string */
    private static $read_only_type;

    /** @var string */
    private $namespace_endpoint;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$type           = self::create_content_type_fixture();
        self::$read_only_type = self::create_content_type_fixture(['writable' => false]);
        self::refresh_rest_routes();
    }

    public function set_up()
    {
        parent::set_up();

        // 'ewp_phpunit_ab12cd' -> 'phpunit_ab12cd', matching how
        // AWM_Add_Content_DB_Setup builds the route path from the raw key.
        $this->namespace_endpoint = substr(self::$type, strlen('ewp_'));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function request($method, $path)
    {
        return new WP_REST_Request($method, '/ewp/' . $this->namespace_endpoint . $path);
    }

    public function test_create_returns_201_with_normalized_row()
    {
        $req = $this->request('POST', '/create');
        $req->set_param('title', 'REST created item');
        $req->set_param('meta', ['required_field' => 'value']);

        $response = rest_get_server()->dispatch($req);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('REST created item', $data['title']);
        $this->assertGreaterThan(0, $data['id']);
    }

    public function test_update_merges_patch()
    {
        $create = $this->request('POST', '/create');
        $create->set_param('title', 'Original');
        $create->set_param('meta', ['required_field' => 'value']);
        $created = rest_get_server()->dispatch($create)->get_data();

        $update = $this->request('POST', '/update/' . $created['id']);
        $update->set_url_params(['id' => $created['id']]);
        $update->set_param('status', 'disabled');

        $response = rest_get_server()->dispatch($update);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('disabled', $data['status']);
        $this->assertSame('Original', $data['title']);
    }

    public function test_create_without_required_meta_returns_error_response()
    {
        $req = $this->request('POST', '/create');
        $req->set_param('title', 'Missing required field');

        $response = rest_get_server()->dispatch($req);

        $this->assertSame(400, $response->get_status());
    }

    public function test_delete_removes_the_row()
    {
        $create = $this->request('POST', '/create');
        $create->set_param('title', 'To delete');
        $create->set_param('meta', ['required_field' => 'value']);
        $created = rest_get_server()->dispatch($create)->get_data();

        $delete = $this->request('DELETE', '/delete');
        $delete->set_param('ids', (string) $created['id']);
        $response = rest_get_server()->dispatch($delete);

        $this->assertSame(200, $response->get_status());
    }

    public function test_non_writable_content_type_exposes_no_write_routes()
    {
        $endpoint = substr(self::$read_only_type, strlen('ewp_'));

        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/ewp/' . $endpoint, $routes, 'The read route must still be registered.');
        $this->assertArrayNotHasKey('/ewp/' . $endpoint . '/create', $routes);
        $this->assertArrayNotHasKey('/ewp/' . $endpoint . '/delete', $routes);
    }
}
