<?php
/**
 * Second batch of audit fixes (1.10.0): object-free unserialize, the
 * `[ewp_search]` attributes, template paths, options page capability and
 * non-editable fields, scoped cache flushing, and object search bounds.
 */
class Test_Security_Hardening_Batch_2 extends WP_UnitTestCase
{
    /**
     * Serialized objects never become objects.
     *
     * @return void
     */
    public function test_serialized_objects_are_not_instantiated()
    {
        $object = serialize(new ArrayObject([1]));

        $this->assertInstanceOf('__PHP_Incomplete_Class', \EWP\Serialization::unserialize($object));
        $this->assertInstanceOf('__PHP_Incomplete_Class', \EWP\Serialization::unserialize_twice(serialize($object)));
        $this->assertSame(['a' => 1], \EWP\Serialization::unserialize_twice(serialize(serialize(['a' => 1]))));
        $this->assertSame('plain', \EWP\Serialization::unserialize('plain'));
        $this->assertFalse(\EWP\Serialization::unserialize(serialize(false)));

        $this->assertSame([], awm_safe_unserialize_array($object));
        $this->assertSame(['x' => '1'], awm_safe_unserialize_array(serialize(['x' => '1'])));
        $this->assertSame([], awm_safe_unserialize_array('not serialized'));
    }

    /**
     * A section field stored as a serialized object string renders without instantiating it.
     *
     * @return void
     */
    public function test_section_render_does_not_instantiate_stored_objects()
    {
        $post = self::factory()->post->create();
        update_post_meta($post, 'ewp_phpunit_section', serialize(new ArrayObject(['title' => 'x'])));

        $html = awm_show_content([
            'ewp_phpunit_section' => [
                'case'    => 'section',
                'label'   => 'Section',
                'include' => ['title' => ['case' => 'input', 'type' => 'text', 'label' => 'Title']],
            ],
        ], $post);

        $this->assertIsString($html);
        $this->assertStringNotContainsString('ArrayObject', $html);
    }

    /**
     * Shortcode attributes cannot inject markup.
     *
     * @return void
     */
    public function test_search_shortcode_attributes_cannot_inject_markup()
    {
        $html = do_shortcode('[ewp_search id=\'1" onmouseover="alert(1)\' hash=\'x"><script>\']');

        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * Taxonomy template paths stay inside wp-content, outside uploads, and are php files.
     *
     * @return void
     */
    public function test_taxonomy_template_path_is_confined()
    {
        $uploads = wp_upload_dir(null, false)['basedir'];
        wp_mkdir_p($uploads);
        file_put_contents($uploads . '/ewp-phpunit.php', '<?php');
        $relative_upload = ltrim(str_replace(realpath(WP_CONTENT_DIR), '', realpath($uploads)), '/') . '/ewp-phpunit.php';

        $this->assertSame('', EWP_WP_Content_Installer::content_template_path('../wp-config.php'));
        $this->assertSame('', EWP_WP_Content_Installer::content_template_path('../../../../etc/passwd'));
        $this->assertSame('', EWP_WP_Content_Installer::content_template_path($relative_upload));
        $this->assertSame('', EWP_WP_Content_Installer::content_template_path('plugins/wp-extend/README.md'));
        $this->assertSame(realpath(awm_path . 'extend-wp.php'), EWP_WP_Content_Installer::content_template_path('plugins/' . basename(awm_path) . '/extend-wp.php'));

        unlink($uploads . '/ewp-phpunit.php');
    }

    /**
     * Template source tokens with path characters resolve to nothing.
     *
     * @return void
     */
    public function test_template_source_rejects_path_slugs()
    {
        $this->assertSame('', EWP_Template_Resolver::locate('post_type:../../x', EWP_Template_Resolver::CONTEXT_SINGLE));
    }

    /**
     * Options pages: options.php requires the page capability, and non-editable fields are not registered.
     *
     * @return void
     */
    public function test_options_page_capability_and_non_editable_fields()
    {
        add_filter('awm_add_options_boxes_filter', function ($pages) {
            $pages['ewp_phpunit_opts'] = [
                'title'   => 'PHPUnit',
                'library' => [
                    'ewp_phpunit_open'   => ['case' => 'input', 'type' => 'text', 'label' => 'Open'],
                    'ewp_phpunit_locked' => ['case' => 'input', 'type' => 'text', 'label' => 'Locked', 'not_editable_by' => ['editor']],
                ],
            ];
            return $pages;
        });
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $cache = new ReflectionProperty('AWM_Meta', 'ewp_options');
        $cache->setAccessible(true);
        $cache->setValue(null, false); // options_boxes() caches the pages per request

        (new AWM_Meta())->awm_register_option_settings();
        $cache->setValue(null, false);

        // options.php applies "option_page_capability_{$option_page}" with a default of manage_options.
        $page = 'ewp_phpunit_opts';
        $this->assertSame('manage_options', apply_filters("option_page_capability_{$page}", 'edit_posts'));
        $registered = get_registered_settings();
        $this->assertArrayHasKey('ewp_phpunit_open', $registered);
        $this->assertArrayNotHasKey('ewp_phpunit_locked', $registered);
    }

    /**
     * Only configuration content types trigger the full cache flush.
     *
     * @return void
     */
    public function test_only_configuration_types_need_the_full_flush()
    {
        $this->assertTrue(AWM_Add_Content_DB_Setup::needs_full_flush('ewp_fields'));
        $this->assertTrue(AWM_Add_Content_DB_Setup::needs_full_flush('ewp_post_types'));
        $this->assertFalse(AWM_Add_Content_DB_Setup::needs_full_flush('flx_coupon'));

        add_filter('ewp_content_save_full_flush', '__return_true');
        $this->assertTrue(AWM_Add_Content_DB_Setup::needs_full_flush('flx_coupon'));
    }

    /**
     * Object search caps the limit.
     *
     * @return void
     */
    public function test_object_search_limit_is_capped()
    {
        self::factory()->post->create_many(7, ['post_title' => 'capped result']);
        add_filter('awm_object_search_max_limit', function () {
            return 5;
        });

        $results = awm_object_search_query('post_type', 'post', 'capped', ['limit' => 1000]);

        $this->assertCount(5, $results);
    }

    /**
     * Object search ignores content types that are not registered.
     *
     * @return void
     */
    public function test_object_search_ignores_unregistered_content_types()
    {
        global $wpdb;
        $wpdb->suppress_errors(false);
        $results = awm_object_search_query('custom_content', 'nope`; DROP TABLE x; --', 'a');

        $this->assertSame([], $results);
        $this->assertSame('', $wpdb->last_error);
    }
}
