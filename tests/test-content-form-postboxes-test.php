<?php
/**
 * The content edit form's postbox setup is an ES module loaded by the Dynamic
 * Asset Loader (since 1.14.0), not an inline footer script.
 */

class Test_Content_Form_Postboxes extends WP_UnitTestCase
{
    /**
     * The module is registered for #ewp-content-form, after WordPress's postbox script.
     *
     * @return void
     */
    public function test_module_is_registered()
    {
        $assets = apply_filters('ewp_register_dynamic_assets', array());
        $found  = wp_list_filter($assets, array('handle' => 'ewp-content-form-postboxes'));
        $this->assertCount(1, $found);
        $asset = reset($found);
        $this->assertSame('#ewp-content-form', $asset['selector']);
        $this->assertSame('admin', $asset['context']);
        $this->assertContains('postbox', $asset['dependencies']);
        $this->assertStringEndsWith('build/modules/ewp-content-form-postboxes.js', $asset['src']);
        $this->assertFileExists(awm_path . 'build/modules/ewp-content-form-postboxes.js');
    }

    /**
     * The deprecated footer method prints nothing.
     *
     * @return void
     */
    public function test_footer_script_is_gone()
    {
        $form = (new ReflectionClass('AWM_Add_Custom_List'))->newInstanceWithoutConstructor();
        ob_start();
        $form->on_page_footer();
        $this->assertSame('', ob_get_clean());
    }
}
