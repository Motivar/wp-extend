<?php
/**
 * Verifies `wp ewp delete-cache` (WP_CLI_Integration) was fixed to call
 * the full ewp_flush_cache() rather than the narrower
 * awm_delete_transient_all(), so it does the same work as the
 * ewp-system/flush-cache ability
 * (EWP_Abilities_System_Provider::run_flush_cache()).
 */
class Test_Cache_Flush_Cli extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        \WP_CLI\StubRecorder::reset();
    }

    public function test_delete_cache_command_triggers_the_full_flush_cache_hooks()
    {
        $pre_fired  = false;
        $post_fired = false;

        add_action('ewp_flush_cache_pre_action', function () use (&$pre_fired) {
            $pre_fired = true;
        });
        add_action('ewp_flush_cache_action', function () use (&$post_fired) {
            $post_fired = true;
        });

        $integration = new WP_CLI_Integration();
        $integration->awm_delete_transient_all();

        $this->assertTrue($pre_fired, 'ewp_flush_cache_pre_action must fire — only ewp_flush_cache() dispatches it, the narrower awm_delete_transient_all() does not.');
        $this->assertTrue($post_fired, 'ewp_flush_cache_action must fire for the same reason.');
        $this->assertNotEmpty(\WP_CLI\StubRecorder::$success);
    }
}
