<?php
/**
 * The kit loader picks the newest registered copy and serves late
 * on_ready() callbacks immediately once booted.
 */

use Motivar\WP\Kit;

class Test_Kit_Loader extends WP_UnitTestCase
{
    public function test_the_plugin_boots_its_own_copy_of_the_kit()
    {
        $this->assertTrue(Kit::is_booted());
        $this->assertSame(require dirname(__DIR__) . '/includes/kit/version.php', Kit::version());
        $this->assertSame(realpath(dirname(__DIR__) . '/includes/kit'), realpath(Kit::path()));
        $this->assertTrue(class_exists('Motivar\\WP\\Field'), 'autoloader must resolve kit classes');
    }

    public function test_newest_version_wins_regardless_of_registration_order()
    {
        $this->assertSame('/b', Kit::pick_newest(['/a' => '0.1.0', '/b' => '0.2.0', '/c' => '0.1.5']));
        $this->assertSame('/c', Kit::pick_newest(['/c' => '1.0.0', '/a' => '0.9.9']));
        $this->assertNull(Kit::pick_newest([]));
    }

    public function test_late_registration_does_not_move_the_booted_copy()
    {
        $before = Kit::path();
        Kit::register('99.0.0', '/tmp/never-booted');

        $this->assertSame($before, Kit::path());
        $this->assertArrayHasKey('/tmp/never-booted', Kit::copies());
    }

    public function test_on_ready_runs_immediately_after_boot()
    {
        $ran = false;
        Kit::on_ready(function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran);
    }
}
