<?php
/**
 * Regression tests for the meta save and field rendering paths: request
 * data must not choose which meta keys get written, saves must carry the
 * screen's core nonce and capability, values are filtered for users
 * without unfiltered_html, and stored field values are escaped on output.
 */

class Test_Meta_Save_Security extends WP_UnitTestCase
{
    /** Meta key of the post field registered by register_post_box(). */
    const POST_FIELD = 'ewp_sec_post_field';

    /** Meta key of the user field registered by register_user_box(). */
    const USER_FIELD = 'ewp_sec_user_field';

    /**
     * Clear request data and the current user after each test.
     *
     * @return void
     */
    public function tear_down()
    {
        $_POST = array();
        $_REQUEST = array();
        wp_set_current_user(0);
        parent::tear_down();
    }

    /**
     * Name of the capabilities user meta key for the test site's prefix.
     *
     * @return string
     */
    private function capabilities_key()
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'capabilities';
    }

    /**
     * Put a request into $_POST and $_REQUEST, slashed the way WordPress does.
     *
     * @param array $data Request fields.
     * @return void
     */
    private function set_request(array $data)
    {
        $_POST = wp_slash($data);
        $_REQUEST = $_POST;
    }

    /**
     * Register a post meta box on `post` with one text field (and optional extras).
     *
     * @param array $extra Extra library entries keyed by meta key.
     * @return void
     */
    private function register_post_box(array $extra = array())
    {
        add_filter('awm_add_meta_boxes_filter', function ($boxes) use ($extra) {
            $boxes['ewp_sec_box'] = array(
                'title'     => 'Security box',
                'postTypes' => array('post'),
                'library'   => array(
                    self::POST_FIELD => array('label' => 'Field', 'case' => 'input', 'type' => 'text'),
                ) + $extra,
            );
            return $boxes;
        });
    }

    /**
     * Register a user box with one text field.
     *
     * @return void
     */
    private function register_user_box()
    {
        add_filter('awm_add_user_boxes_filter', function ($boxes) {
            $boxes['ewp_sec_user_box'] = array(
                'title'   => 'Security user box',
                'library' => array(
                    self::USER_FIELD => array('label' => 'User field', 'case' => 'input', 'type' => 'text'),
                ),
            );
            return $boxes;
        });
    }

    /**
     * Save a post through wp_update_post(), which fires save_post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    private function save_post_now($post_id)
    {
        wp_update_post(array('ID' => $post_id, 'post_title' => 'Updated ' . wp_generate_password(4, false)));
    }

    /**
     * A subscriber saving their own profile, with a valid profile nonce,
     * cannot grant themselves a role.
     *
     * @return void
     */
    public function test_subscriber_cannot_write_capabilities_on_profile_update()
    {
        $user_id = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($user_id);
        $key = $this->capabilities_key();
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-user_' . $user_id),
            'awm_custom_meta' => array($key),
            $key              => array('administrator' => '1'),
        ));

        awm_profile_update($user_id);

        $user = new WP_User($user_id);
        $this->assertSame(array('subscriber'), array_values($user->roles));
        $this->assertFalse(user_can($user_id, 'manage_options'));
    }

    /**
     * An anonymous public registration cannot register an administrator.
     *
     * @return void
     */
    public function test_anonymous_registration_cannot_write_capabilities()
    {
        $key = $this->capabilities_key();
        $this->set_request(array(
            'awm_custom_meta' => array($key),
            $key              => array('administrator' => '1'),
        ));

        $user_id = self::factory()->user->create(array('role' => 'subscriber'));
        awm_profile_update($user_id);

        $this->assertFalse(user_can($user_id, 'manage_options'));
    }

    /**
     * A contributor saving their own post, with a valid nonce, cannot write
     * meta keys that no registered field declares.
     *
     * @return void
     */
    public function test_contributor_cannot_write_unregistered_post_meta()
    {
        $user_id = self::factory()->user->create(array('role' => 'contributor'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id, 'post_status' => 'draft'));
        $this->set_request(array(
            '_wpnonce'              => wp_create_nonce('update-post_' . $post_id),
            'awm_custom_meta'       => array('_ewp_unregistered_key'),
            '_ewp_unregistered_key' => 'injected',
        ));

        $this->save_post_now($post_id);

        $this->assertSame('', get_post_meta($post_id, '_ewp_unregistered_key', true));
    }

    /**
     * A registered post field still saves for an editor with a valid nonce.
     *
     * @return void
     */
    public function test_registered_post_field_saves_with_nonce()
    {
        $this->register_post_box();
        $user_id = self::factory()->user->create(array('role' => 'editor'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id));
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-post_' . $post_id),
            'awm_custom_meta' => array(self::POST_FIELD),
            self::POST_FIELD  => 'saved value',
        ));

        $this->save_post_now($post_id);

        $this->assertSame('saved value', get_post_meta($post_id, self::POST_FIELD, true));
    }

    /**
     * A field from a box whose library comes from a `callback` saves too.
     *
     * @return void
     */
    public function test_callback_library_field_saves()
    {
        add_filter('awm_add_meta_boxes_filter', function ($boxes) {
            $boxes['ewp_sec_cb_box'] = array(
                'title'     => 'Callback box',
                'postTypes' => array('post'),
                'callback'  => array(__CLASS__, 'callback_library'),
            );
            return $boxes;
        });
        $user_id = self::factory()->user->create(array('role' => 'editor'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id));
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-post_' . $post_id),
            'awm_custom_meta' => array('ewp_sec_cb_field'),
            'ewp_sec_cb_field' => 'from callback',
        ));

        $this->save_post_now($post_id);

        $this->assertSame('from callback', get_post_meta($post_id, 'ewp_sec_cb_field', true));
    }

    /**
     * Library returned by the callback box in test_callback_library_field_saves().
     *
     * @return array
     */
    public static function callback_library()
    {
        return array('ewp_sec_cb_field' => array('label' => 'Callback field', 'case' => 'input', 'type' => 'text'));
    }

    /**
     * A post field registered for another post type does not save on `post`.
     *
     * @return void
     */
    public function test_field_registered_for_other_post_type_does_not_save()
    {
        add_filter('awm_add_meta_boxes_filter', function ($boxes) {
            $boxes['ewp_sec_page_box'] = array(
                'title'     => 'Page box',
                'postTypes' => array('page'),
                'library'   => array('ewp_sec_page_field' => array('label' => 'Page', 'case' => 'input', 'type' => 'text')),
            );
            return $boxes;
        });
        $user_id = self::factory()->user->create(array('role' => 'editor'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id));
        $this->set_request(array(
            '_wpnonce'           => wp_create_nonce('update-post_' . $post_id),
            'awm_custom_meta'    => array('ewp_sec_page_field'),
            'ewp_sec_page_field' => 'wrong screen',
        ));

        $this->save_post_now($post_id);

        $this->assertSame('', get_post_meta($post_id, 'ewp_sec_page_field', true));
    }

    /**
     * Without the screen's nonce nothing is saved, even a registered field.
     *
     * @return void
     */
    public function test_registered_post_field_needs_nonce()
    {
        $this->register_post_box();
        $user_id = self::factory()->user->create(array('role' => 'editor'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id));
        $this->set_request(array(
            'awm_custom_meta' => array(self::POST_FIELD),
            self::POST_FIELD  => 'no nonce',
        ));

        $this->save_post_now($post_id);

        $this->assertSame('', get_post_meta($post_id, self::POST_FIELD, true));
    }

    /**
     * A registered user field saves on the user's own profile.
     *
     * @return void
     */
    public function test_registered_user_field_saves_on_own_profile()
    {
        $this->register_user_box();
        $user_id = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($user_id);
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-user_' . $user_id),
            'awm_custom_meta' => array(self::USER_FIELD),
            self::USER_FIELD  => 'mine',
        ));

        awm_profile_update($user_id);

        $this->assertSame('mine', get_user_meta($user_id, self::USER_FIELD, true));
    }

    /**
     * A subscriber cannot write fields on another user's profile.
     *
     * @return void
     */
    public function test_subscriber_cannot_write_another_users_field()
    {
        $this->register_user_box();
        $attacker = self::factory()->user->create(array('role' => 'subscriber'));
        $victim = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($attacker);
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-user_' . $victim),
            'awm_custom_meta' => array(self::USER_FIELD),
            self::USER_FIELD  => 'not yours',
        ));

        awm_profile_update($victim);

        $this->assertSame('', get_user_meta($victim, self::USER_FIELD, true));
    }

    /**
     * Register a term box on `category` with one text field.
     *
     * @return void
     */
    private function register_term_box()
    {
        add_filter('awm_add_term_meta_boxes_filter', function ($boxes) {
            $boxes['ewp_sec_term_box'] = array(
                'title'      => 'Security term box',
                'taxonomies' => array('category'),
                'library'    => array('ewp_sec_term_field' => array('label' => 'Term field', 'case' => 'input', 'type' => 'text')),
            );
            return $boxes;
        });
    }

    /**
     * A registered term field saves when a category is edited (edit_term
     * passes the term-taxonomy ID second and the taxonomy third).
     *
     * @return void
     */
    public function test_registered_term_field_saves_on_edit()
    {
        $this->register_term_box();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $term_id = self::factory()->category->create();
        $this->set_request(array(
            '_wpnonce'           => wp_create_nonce('update-tag_' . $term_id),
            'awm_custom_meta'    => array('ewp_sec_term_field', '_ewp_unregistered_key'),
            'ewp_sec_term_field' => 'term value',
            '_ewp_unregistered_key' => 'injected',
        ));

        wp_update_term($term_id, 'category', array('description' => 'updated'));

        $this->assertSame('term value', get_term_meta($term_id, 'ewp_sec_term_field', true));
        $this->assertSame('', get_term_meta($term_id, '_ewp_unregistered_key', true));
    }

    /**
     * A registered term field saves when a category is created from the
     * add-term form (add-tag nonce).
     *
     * @return void
     */
    public function test_registered_term_field_saves_on_create()
    {
        $this->register_term_box();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $this->set_request(array(
            '_wpnonce_add-tag'   => wp_create_nonce('add-tag'),
            'awm_custom_meta'    => array('ewp_sec_term_field'),
            'ewp_sec_term_field' => 'new term value',
        ));

        $term = wp_insert_term('Security term ' . wp_generate_password(4, false), 'category');

        $this->assertSame('new term value', get_term_meta($term['term_id'], 'ewp_sec_term_field', true));
    }

    /**
     * The ewp_allowed_meta_keys filter can allow an extra key, but never a
     * capability or role key.
     *
     * @return void
     */
    public function test_allowed_keys_filter_cannot_allow_capability_keys()
    {
        $key = $this->capabilities_key();
        add_filter('ewp_allowed_meta_keys', function ($keys) use ($key) {
            $keys[] = 'ewp_sec_filtered_key';
            $keys[] = $key;
            return $keys;
        });
        $user_id = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($user_id);
        $this->set_request(array(
            '_wpnonce'             => wp_create_nonce('update-user_' . $user_id),
            'awm_custom_meta'      => array('ewp_sec_filtered_key', $key),
            'ewp_sec_filtered_key' => 'allowed by filter',
            $key                   => array('administrator' => '1'),
        ));

        awm_profile_update($user_id);

        $this->assertSame('allowed by filter', get_user_meta($user_id, 'ewp_sec_filtered_key', true));
        $this->assertFalse(user_can($user_id, 'manage_options'));
    }

    /**
     * Allowed keys include the second key of an object_id_filter field.
     *
     * @return void
     */
    public function test_allowed_keys_include_object_id_filter_ids_key()
    {
        $library = array(
            'ewp_sec_objects' => array('label' => 'Objects', 'case' => 'object_id_filter'),
            'ewp_sec_plain'   => array('label' => 'Plain', 'case' => 'input', 'type' => 'text'),
        );

        $keys = EWP_Meta_Save_Guard::library_keys($library);

        $this->assertContains('ewp_sec_objects', $keys);
        $this->assertContains('ewp_sec_objects_ids', $keys);
        $this->assertContains('ewp_sec_plain', $keys);
    }

    /**
     * Event handlers and scripts are stripped from values saved by a user
     * without unfiltered_html.
     *
     * @return void
     */
    public function test_saved_value_is_filtered_without_unfiltered_html()
    {
        $this->register_post_box();
        $user_id = self::factory()->user->create(array('role' => 'author'));
        wp_set_current_user($user_id);
        $post_id = self::factory()->post->create(array('post_author' => $user_id));
        $this->set_request(array(
            '_wpnonce'        => wp_create_nonce('update-post_' . $post_id),
            'awm_custom_meta' => array(self::POST_FIELD),
            self::POST_FIELD  => 'Hello <img src=x onerror=alert(1)><script>alert(2)</script>',
        ));

        $this->save_post_now($post_id);

        $saved = get_post_meta($post_id, self::POST_FIELD, true);
        $this->assertStringStartsWith('Hello', $saved);
        $this->assertStringNotContainsString('onerror', $saved);
        $this->assertStringNotContainsString('<script', $saved);
    }

    /**
     * A stored input value containing markup is escaped when rendered.
     *
     * @return void
     */
    public function test_input_field_output_escapes_stored_value()
    {
        $post_id = self::factory()->post->create();
        $payload = '"><img src=x onerror=alert(1)>';
        update_post_meta($post_id, 'ewp_xss_probe', $payload);

        $html = awm_show_content(
            array(
                'ewp_xss_probe' => array(
                    'label' => 'Probe',
                    'case'  => 'input',
                    'type'  => 'text',
                ),
            ),
            $post_id,
            'post'
        );

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString(esc_attr($payload), $html);
    }

    /**
     * A stored hidden input value is escaped when rendered.
     *
     * @return void
     */
    public function test_hidden_field_output_escapes_stored_value()
    {
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'ewp_xss_hidden', '"><script>alert(1)</script>');

        $html = awm_show_content(
            array('ewp_xss_hidden' => array('case' => 'input', 'type' => 'hidden')),
            $post_id,
            'post'
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * A button's link and label cannot break out of their attributes.
     *
     * @return void
     */
    public function test_button_output_escapes_link_and_title()
    {
        $html = awm_show_content(array(
            'ewp_xss_button' => array(
                'case'  => 'button',
                'label' => 'Go" onmouseover="alert(1)',
                'link'  => 'javascript:alert(1)',
            ),
        ));

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    /**
     * awm_create_form() prints the configured method (POST by default), so
     * form data never ends up in the URL.
     *
     * @return void
     */
    public function test_create_form_uses_post_method()
    {
        $html = awm_create_form(array('id' => 'ewp-sec-form', 'library' => array()));

        $this->assertMatchesRegularExpression('/<form[^>]*method="post"/', $html);
    }
}
