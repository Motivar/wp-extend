<?php
/**
 * Custom post statuses no longer print inline scripts (since 1.14.0): the
 * block editor and list-screen behaviour lives in EWPCustomPostStatus
 * (awm-admin-script.js); the permalink slug input is a template.
 */

class Test_Custom_Status_Scripts extends WP_UnitTestCase
{
    /**
     * Neither the block editor hook nor the list footer prints a <script>.
     *
     * @return void
     */
    public function test_no_inline_scripts()
    {
        $this->assertFalse(has_action('admin_footer-edit.php', 'fix_custom_status_column_display'));
        foreach ($GLOBALS['wp_filter']['admin_footer-edit.php']->callbacks ?? array() as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                $this->assertFalse(is_array($function) && 'fix_custom_status_column_display' === $function[1], 'column script still hooked');
            }
        }

        ob_start();
        do_action('enqueue_block_editor_assets');
        $this->assertStringNotContainsString('<script', ob_get_clean());

        $installer = (new ReflectionClass('EWP_WP_Content_Installer'))->newInstanceWithoutConstructor();
        ob_start();
        $installer->fix_custom_status_column_display();
        $this->assertSame('', ob_get_clean());
    }

    /**
     * The slug input escapes the key, the value and the placeholder.
     *
     * @return void
     */
    public function test_slug_field_template()
    {
        $html = awm_parse_template(awm_path . 'templates/admin-view/slug-field.php', array('slug' => 'book"x', 'value' => 'bo"oks'));
        $this->assertStringContainsString('name="book&quot;x_slug"', $html);
        $this->assertStringContainsString('value="bo&quot;oks"', $html);
        $this->assertStringContainsString('placeholder="book&quot;x"', $html);
        $this->assertStringContainsString('class="regular-text"', $html);
    }
}
