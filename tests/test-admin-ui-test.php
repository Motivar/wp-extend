<?php
/**
 * EWP_Admin_UI: which admin screens get the options-page look
 * (`body.ewp-admin-ui` + ewp-admin-ui.min.css).
 */

class Test_Admin_UI extends WP_UnitTestCase
{
    /** @var array|bool Options registry before the test. */
    private $registry;

    public function set_up()
    {
        parent::set_up();
        $this->registry = AWM_Meta::$ewp_options;
        AWM_Meta::$ewp_options = [
            'ewp-test-options' => ['title' => 'Test options', 'callback' => '__return_empty_array'],
            'ewp-test-custom' => ['title' => 'Custom page', 'ext_callback' => '__return_null'],
        ];
        $this->reset_cache();
    }

    public function tear_down()
    {
        AWM_Meta::$ewp_options = $this->registry;
        unset($_GET['page']);
        remove_all_filters('ewp_admin_ui_enabled');
        remove_all_filters('ewp_admin_ui_fields_enabled');
        $this->reset_cache();
        parent::tear_down();
    }

    /** is_enabled() / is_fields_enabled() cache per request; each test is its own request. */
    private function reset_cache()
    {
        foreach (['enabled', 'fields_enabled'] as $name) {
            $property = new ReflectionProperty('EWP_Admin_UI', $name);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }

    /**
     * Pretend to be on admin.php?page=$page.
     *
     * @param string $page
     */
    private function on_page($page)
    {
        $_GET['page'] = $page;
        set_current_screen('toplevel_page_' . $page);
    }

    public function test_options_page_rendered_by_settings_template_is_enabled()
    {
        $this->on_page('ewp-test-options');

        $this->assertTrue(EWP_Admin_UI::is_options_page());
        $this->assertTrue(EWP_Admin_UI::is_enabled());
    }

    public function test_page_with_its_own_callback_is_not_enabled()
    {
        $this->on_page('ewp-test-custom');

        $this->assertFalse(EWP_Admin_UI::is_options_page(), 'An ext_callback page renders its own markup.');
        $this->assertFalse(EWP_Admin_UI::is_enabled());
    }

    public function test_other_admin_screens_are_not_enabled()
    {
        unset($_GET['page']);
        set_current_screen('edit-post');

        $this->assertFalse(EWP_Admin_UI::is_enabled());
    }

    public function test_filter_turns_a_screen_on_and_receives_the_screen()
    {
        $this->on_page('ewp-test-custom');
        $seen = null;
        add_filter('ewp_admin_ui_enabled', function ($enabled, $screen) use (&$seen) {
            $seen = $screen;
            return true;
        }, 10, 2);

        $this->assertTrue(EWP_Admin_UI::is_enabled());
        $this->assertInstanceOf('WP_Screen', $seen);
    }

    public function test_filter_turns_an_options_page_off()
    {
        $this->on_page('ewp-test-options');
        add_filter('ewp_admin_ui_enabled', '__return_false');

        $this->assertFalse(EWP_Admin_UI::is_enabled());
    }

    public function test_body_class_is_added_only_when_enabled()
    {
        $ui = new EWP_Admin_UI();

        $this->on_page('ewp-test-options');
        $this->assertStringContainsString(' ewp-admin-ui ', $ui->body_class('wp-admin'));

        $this->reset_cache();
        $this->on_page('ewp-test-custom');
        $this->assertSame('wp-admin', $ui->body_class('wp-admin'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}> screen id => expected.
     */
    public function fields_screens()
    {
        return [
            'post edit' => ['post', true],
            'term edit' => ['edit-category', true],
            'user profile' => ['profile', true],
            'other user' => ['user-edit', true],
            'post list' => ['edit-post', false],
            'dashboard' => ['dashboard', false],
        ];
    }

    /**
     * @dataProvider fields_screens
     */
    public function test_fields_scope_follows_the_screen_base($screen_id, $expected)
    {
        unset($_GET['page']);
        set_current_screen($screen_id);
        if ($screen_id === 'edit-category') {
            // set_current_screen() names the term list `edit-tags`; term.php edits one.
            get_current_screen()->base = 'term';
        }

        $this->assertSame($expected, EWP_Admin_UI::is_fields_enabled(), get_current_screen()->base);
        $this->assertFalse(EWP_Admin_UI::is_enabled(), 'The whole-page look stays on options pages.');
    }

    public function test_fields_filter_turns_a_screen_off()
    {
        set_current_screen('post');
        add_filter('ewp_admin_ui_fields_enabled', '__return_false');

        $this->assertFalse(EWP_Admin_UI::is_fields_enabled());
    }

    public function test_fields_body_class_is_added_on_post_screens()
    {
        $ui = new EWP_Admin_UI();
        unset($_GET['page']);
        set_current_screen('post');

        $classes = $ui->body_class('wp-admin');
        $this->assertStringContainsString(' ewp-admin-ui-fields ', $classes);
        $this->assertStringNotContainsString(' ewp-admin-ui ', $classes);
    }

    public function test_fields_scope_is_on_for_content_edit_forms()
    {
        $hooks = AWM_Add_Custom_List::$form_hooks;
        AWM_Add_Custom_List::$form_hooks = ['extend-wp_page_ewp-test_form'];
        unset($_GET['page']);
        set_current_screen('extend-wp_page_ewp-test_form');

        $this->assertTrue(EWP_Admin_UI::is_content_form());
        $this->assertTrue(EWP_Admin_UI::is_fields_enabled());

        AWM_Add_Custom_List::$form_hooks = $hooks;
    }

    public function test_content_list_screens_are_not_forms()
    {
        $hooks = AWM_Add_Custom_List::$form_hooks;
        AWM_Add_Custom_List::$form_hooks = ['extend-wp_page_ewp-test_form'];
        set_current_screen('extend-wp_page_ewp-test');

        $this->assertFalse(EWP_Admin_UI::is_content_form());

        AWM_Add_Custom_List::$form_hooks = $hooks;
    }
}
