<?php
/**
 * The admin Thumb column keeps the responsive attributes wp_get_attachment_image() prints
 * (wp_kses_post() dropped srcset, sizes and decoding; seen on the 16-site compare 2026-11-04).
 */
class Test_Gallery_Thumb_Column extends WP_UnitTestCase
{
    public function test_thumb_keeps_responsive_attributes()
    {
        $img = '<img width="50" height="50" src="https://example.org/a-150x150.jpg" class="ewp-list-thumb" alt="A" decoding="async" loading="lazy" fetchpriority="low" srcset="https://example.org/a-150x150.jpg 150w, https://example.org/a-50x50.jpg 50w" sizes="(max-width: 50px) 100vw, 50px" />';
        $out = wp_kses($img, EWP_Gallery_Meta_Box::thumb_allowed_html());
        foreach (array('srcset="', 'sizes="', 'decoding="async"', 'loading="lazy"', 'fetchpriority="low"', 'class="ewp-list-thumb"') as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function test_thumb_still_strips_scripts_and_handlers()
    {
        $out = wp_kses('<img src="https://example.org/a.jpg" onerror="alert(1)"><script>alert(1)</script>', EWP_Gallery_Meta_Box::thumb_allowed_html());
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<script', $out);
    }
}
