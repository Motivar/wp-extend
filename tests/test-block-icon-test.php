<?php
/**
 * Block icon: EWP_Dynamic_Blocks::register_blocks() registers a block with
 * its own `icon` and falls back to `admin-site` only when none is set.
 */

class Test_Block_Icon extends WP_UnitTestCase
{
    /**
     * Run the private block_icon() on one block definition.
     *
     * @param array $block Block definition.
     * @return string|array Icon passed to register_block_type().
     */
    private function icon(array $block)
    {
        $reflection = new ReflectionMethod(EWP_Dynamic_Blocks::instance(), 'block_icon');
        $reflection->setAccessible(true);
        return $reflection->invoke(EWP_Dynamic_Blocks::instance(), $block);
    }

    public function test_a_block_icon_is_used()
    {
        $this->assertSame('slides', $this->icon(['name' => 'slider', 'icon' => 'slides']));
    }

    public function test_missing_or_empty_icon_falls_back_to_admin_site()
    {
        $this->assertSame('admin-site', $this->icon(['name' => 'slider']));
        $this->assertSame('admin-site', $this->icon(['name' => 'slider', 'icon' => '']));
    }
}
