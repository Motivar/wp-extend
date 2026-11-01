<?php
/**
 * Helpers in includes/functions/main.php that used to print inline styles:
 * awm_show_featured_image() (admin list thumbnail) and ewp_debug().
 */

class Test_Inline_Style_Helpers extends WP_UnitTestCase
{
    /**
     * The thumbnail is WordPress's own sized, escaped <img>, no inline style.
     *
     * @return void
     */
    public function test_featured_image_has_no_inline_style()
    {
        $post = self::factory()->post->create();
        $this->assertSame('', awm_show_featured_image($post));

        $attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $post);
        set_post_thumbnail($post, $attachment);
        $html = awm_show_featured_image($post);

        $this->assertStringStartsWith('<img', $html);
        $this->assertStringContainsString('class="ewp-list-thumb', $html);
        $this->assertMatchesRegularExpression('/width="([1-4]?\d|50)"/', $html, 'constrained to 50px');
        $this->assertStringNotContainsString('style=', $html);
        wp_delete_attachment($attachment, true);
    }

    /**
     * ewp_debug() prints an escaped dump in a classed <pre> and enqueues its stylesheet.
     *
     * @return void
     */
    public function test_debug_output_and_style()
    {
        ob_start();
        ewp_debug(array('k' => '<b>v</b>'));
        $html = ob_get_clean();

        $this->assertStringStartsWith('<pre class="ewp-debug">', $html);
        $this->assertStringContainsString('&lt;b&gt;v&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertTrue(wp_style_is('ewp-debug', 'enqueued'));
        $this->assertFileExists(awm_path . 'assets/css/global/ewp-debug.min.css');
    }
}
