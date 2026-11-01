<?php
/**
 * The old REST API Health admin URL after the module moved to the
 * standalone MammothWP REST Health plugin.
 */
class Test_REST_Health_Moved extends WP_UnitTestCase
{
    /**
     * Active standalone plugin: the new Tools screen.
     *
     * @return void
     */
    public function test_redirect_target_when_standalone_is_active()
    {
        $this->assertSame(admin_url('tools.php?page=mmwp-rh'), EWP_REST_Health_Moved::redirect_url(true));
    }

    /**
     * Inactive standalone plugin: the plugins list carrying the notice flag.
     *
     * @return void
     */
    public function test_redirect_target_when_standalone_is_inactive()
    {
        $url = EWP_REST_Health_Moved::redirect_url(false);
        $this->assertStringStartsWith(admin_url('plugins.php'), $url);
        $this->assertStringContainsString(EWP_REST_Health_Moved::NOTICE_ARG . '=1', $url);
    }

    /**
     * The default detector is false when the plugin is not installed.
     *
     * @return void
     */
    public function test_default_detection_is_false_without_the_plugin()
    {
        $this->assertFalse(EWP_REST_Health_Moved::is_standalone_active());
    }

    /**
     * Unrelated pages are left alone (no redirect, no exit).
     *
     * @return void
     */
    public function test_other_pages_are_not_redirected()
    {
        $_GET['page'] = 'something-else';
        (new EWP_REST_Health_Moved(function () {
            $this->fail('detector must not run for other pages');
        }))->maybe_redirect();
        unset($_GET['page']);
        $this->assertTrue(true);
    }

    /**
     * The notice markup is escaped and translated.
     *
     * @return void
     */
    public function test_notice_markup_is_escaped()
    {
        add_filter('gettext', function ($text) {
            return strpos($text, 'REST API Health moved') === 0 ? '<script>alert(1)</script>' : $text;
        });
        $html = EWP_REST_Health_Moved::notice_html();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('notice-info', $html);
    }

    /**
     * The notice shows only with the flag and the capability.
     *
     * @return void
     */
    public function test_notice_requires_flag_and_capability()
    {
        $moved = new EWP_REST_Health_Moved();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        $moved->render_notice();
        $this->assertSame('', ob_get_clean());

        $_GET[EWP_REST_Health_Moved::NOTICE_ARG] = '1';
        ob_start();
        $moved->render_notice();
        $this->assertStringContainsString('MammothWP REST Health', ob_get_clean());
        unset($_GET[EWP_REST_Health_Moved::NOTICE_ARG]);
    }

    /**
     * The redirect runs on admin_page_access_denied: wp-admin/menu.php rejects
     * the removed page with wp_die() before admin_init would fire.
     *
     * @return void
     */
    public function test_hooked_before_access_denied_wp_die()
    {
        $moved = new EWP_REST_Health_Moved(function () {
            return false;
        });
        $moved->init();
        $this->assertSame(10, has_action('admin_page_access_denied', array($moved, 'maybe_redirect')));
        $this->assertFalse(has_action('admin_init', array($moved, 'maybe_redirect')));
        remove_action('admin_page_access_denied', array($moved, 'maybe_redirect'));
        remove_action('admin_notices', array($moved, 'render_notice'));
    }
}
