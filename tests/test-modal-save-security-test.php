<?php
/**
 * POST extend-wp/v1/modal-save and GET /modal-fields (1.9.0 fix).
 *
 * The routes only require `edit_posts`. Before 1.9.0 the save wrote any
 * meta key on any post, term, user or option, so a contributor could
 * write `wp_capabilities` on their own user and become administrator.
 * Now the key must be a registered modal field, the user needs the
 * object-level capability, role keys are refused, and only the modal's
 * declared fields are stored.
 */
class Test_Modal_Save_Security extends WP_UnitTestCase
{
    /** Option the injected modal definition answers for. */
    const OPTION = 'ewp_phpunit_modal';

    /** @var int */
    private $contributor;

    /** @var int */
    private $admin;

    /**
     * Per-test fixture.
     *
     * @return void
     */
    public function set_up()
    {
        parent::set_up();
        $this->contributor = self::factory()->user->create(['role' => 'contributor']);
        $this->admin       = self::factory()->user->create(['role' => 'administrator']);

        add_filter('awm_modal_field_definition_lookup', function ($found, $meta_key) {
            return in_array($meta_key, [self::OPTION, 'wp_capabilities', 'ewp_phpunit_user_modal'], true)
                ? ['note' => ['label' => 'Note', 'case' => 'input', 'type' => 'text']]
                : $found;
        }, 10, 2);
    }

    /**
     * Dispatch a REST request as a user.
     *
     * @param int    $user_id User to act as.
     * @param string $method  HTTP method.
     * @param string $path    Route path.
     * @param array  $params  Parameters.
     * @return WP_REST_Response
     */
    private function dispatch($user_id, $method, $path, array $params)
    {
        wp_set_current_user($user_id);
        $request = new WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    /**
     * Contributor cannot write capabilities on their own user.
     *
     * @return void
     */
    public function test_contributor_cannot_write_capabilities_on_their_own_user()
    {
        global $wpdb;
        $key      = $wpdb->get_blog_prefix() . 'capabilities';
        $response = $this->dispatch($this->contributor, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key'  => $key,
            'view'      => 'user',
            'object_id' => $this->contributor,
            'values'    => ['administrator' => '1'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertFalse(user_can($this->contributor, 'manage_options'));
    }

    /**
     * Contributor cannot write options.
     *
     * @return void
     */
    public function test_contributor_cannot_write_options()
    {
        $response = $this->dispatch($this->contributor, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key' => self::OPTION,
            'view'     => 'option',
            'values'   => ['note' => 'x'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertFalse(get_option(self::OPTION));
    }

    /**
     * Contributor cannot write meta on someone elses post.
     *
     * @return void
     */
    public function test_contributor_cannot_write_meta_on_someone_elses_post()
    {
        $post = self::factory()->post->create(['post_author' => $this->admin, 'post_status' => 'publish']);

        $response = $this->dispatch($this->contributor, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key'  => self::OPTION,
            'view'      => 'post',
            'object_id' => $post,
            'values'    => ['note' => 'x'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('', get_post_meta($post, self::OPTION, true));
    }

    /**
     * Contributor cannot read meta of someone elses post.
     *
     * @return void
     */
    public function test_contributor_cannot_read_meta_of_someone_elses_post()
    {
        $post = self::factory()->post->create(['post_author' => $this->admin, 'post_status' => 'draft']);
        update_post_meta($post, self::OPTION, ['note' => 'secret']);

        $response = $this->dispatch($this->contributor, 'GET', '/extend-wp/v1/modal-fields', [
            'meta_key'  => self::OPTION,
            'view'      => 'post',
            'object_id' => $post,
        ]);

        $this->assertSame(403, $response->get_status());
    }

    /**
     * Unregistered key is not found even for an admin.
     *
     * @return void
     */
    public function test_unregistered_key_is_not_found_even_for_an_admin()
    {
        $response = $this->dispatch($this->admin, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key' => 'blogname',
            'view'     => 'option',
            'values'   => ['note' => 'x'],
        ]);

        $this->assertSame(404, $response->get_status());
        $this->assertNotSame(['note' => 'x'], get_option('blogname'));
    }

    /**
     * Admin saves only the declared fields.
     *
     * @return void
     */
    public function test_admin_saves_only_the_declared_fields()
    {
        $response = $this->dispatch($this->admin, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key' => self::OPTION,
            'view'     => 'option',
            'values'   => ['note' => 'hello', 'injected' => 'nope'],
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['note' => 'hello'], get_option(self::OPTION));
    }

    /**
     * User saves a registered modal on their own profile.
     *
     * @return void
     */
    public function test_user_saves_a_registered_modal_on_their_own_profile()
    {
        $response = $this->dispatch($this->contributor, 'POST', '/extend-wp/v1/modal-save', [
            'meta_key'  => 'ewp_phpunit_user_modal',
            'view'      => 'user',
            'object_id' => $this->contributor,
            'values'    => ['note' => 'mine'],
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['note' => 'mine'], get_user_meta($this->contributor, 'ewp_phpunit_user_modal', true));
    }
}
