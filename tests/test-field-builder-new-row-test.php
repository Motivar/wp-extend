<?php
/**
 * Field-builder helper routes on an unsaved row: the field-group and
 * search-filter editors send `id=new`, which an integer `id` rejected with
 * 400 (empty "Field type configuration" box on new field groups).
 */

class Test_Field_Builder_New_Row extends WP_UnitTestCase
{
    /**
     * Logged-in editor (the routes need edit_posts).
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
    }

    /**
     * GET a route with parameters.
     *
     * @param string $route  Route.
     * @param array  $params Query parameters.
     * @return WP_REST_Response Response.
     */
    private function get($route, array $params)
    {
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    /**
     * Each helper answers 200 with markup for id=new, 0, a numeric string and junk.
     *
     * @dataProvider ids
     *
     * @param mixed $id `id` as sent by a client.
     * @return void
     */
    public function test_helpers_accept_new_rows($id)
    {
        $case     = $this->get('/extend-wp/v1/get-case-fields', array('field' => 'input', 'name' => 'awm_fields[0][case]', 'meta' => 'awm_fields', 'id' => $id));
        $query    = $this->get('/extend-wp/v1/get-query-fields', array('field' => 'post_type', 'name' => 'query_fields[0][query_type]', 'meta' => 'query_fields', 'id' => $id));
        $position = $this->get('/extend-wp/v1/get-position-fields', array('position' => 'post_type', 'name' => 'awm_positions[0][case]', 'id' => $id));

        $this->assertSame(200, $case->get_status(), wp_json_encode($case->get_data()));
        $this->assertStringContainsString('awm_fields', (string) $case->get_data());
        $this->assertSame(200, $query->get_status(), wp_json_encode($query->get_data()));
        $this->assertSame(200, $position->get_status(), wp_json_encode($position->get_data()));
        $this->assertStringContainsString('awm_positions', (string) $position->get_data());
    }

    /**
     * `id` values clients send.
     *
     * @return array[] Cases.
     */
    public function ids()
    {
        return array('new' => array('new'), 'zero' => array(0), 'numeric string' => array('0'), 'junk' => array('abc'));
    }

    /**
     * The sanitiser maps ids as documented.
     *
     * @return void
     */
    public function test_row_id_normalisation()
    {
        $this->assertSame(0, \EWP\Surfaces\Resources\Field_Builder_Resource::row_id('new'));
        $this->assertSame(0, \EWP\Surfaces\Resources\Field_Builder_Resource::row_id(''));
        $this->assertSame(0, \EWP\Surfaces\Resources\Field_Builder_Resource::row_id('-4'));
        $this->assertSame(9, \EWP\Surfaces\Resources\Field_Builder_Resource::row_id('9'));
        $this->assertSame(9, \EWP\Surfaces\Resources\Field_Builder_Resource::row_id(9));
    }
}
