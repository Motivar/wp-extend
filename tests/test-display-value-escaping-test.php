<?php
/**
 * awm_display_meta_value() / Field_Value_Formatter escape everything they
 * build: their callers (post, term, user and custom content list columns)
 * echo the result as is.
 */

class Test_Display_Value_Escaping extends WP_UnitTestCase
{
    /** @var string Payload that breaks out of an attribute and opens a tag. */
    const PAYLOAD = '" onmouseover="alert(1)"><script>alert(2)</script>';

    /**
     * Assert no raw tag or attribute break-out from the payload survived.
     *
     * @param string $html Output.
     * @return void
     */
    private function assertSafe($html)
    {
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('" onmouseover=', $html);
    }

    /**
     * Url inputs: href through esc_url, text escaped.
     *
     * @return void
     */
    public function test_url_input()
    {
        $html = awm_display_meta_value('u', array('case' => 'input', 'type' => 'url'), 0, 'https://example.com/' . self::PAYLOAD);
        $this->assertSafe($html);
        $this->assertStringStartsWith('<a href="https://example.com/', $html);
        $js = awm_display_meta_value('u', array('case' => 'input', 'type' => 'url'), 0, 'javascript:alert(1)');
        $this->assertStringContainsString('href=""', $js, 'esc_url drops the javascript: scheme from the href');
    }

    /**
     * Plain text, unknown cases and lists are escaped text.
     *
     * @return void
     */
    public function test_text_values()
    {
        $this->assertSafe(awm_display_meta_value('t', array('case' => 'input', 'type' => 'text'), 0, self::PAYLOAD));
        $this->assertSafe(awm_display_meta_value('t', array('case' => 'textarea'), 0, self::PAYLOAD));
        $this->assertSame('a, b &amp; c', awm_display_meta_value('t', array('case' => 'map'), 0, array('a', 'b & c')));
    }

    /**
     * Post titles, user names and term names are escaped inside their links.
     *
     * @return void
     */
    public function test_object_names()
    {
        $post = self::factory()->post->create(array('post_title' => 'Title <b>x</b>'));
        $user = self::factory()->user->create(array('display_name' => 'Name <script>y</script>'));
        $term = self::factory()->term->create(array('taxonomy' => 'category', 'name' => 'Cat <i>z</i>'));

        $this->assertStringNotContainsString('<b>', awm_display_meta_value('p', array('case' => 'postType'), 0, $post));
        $this->assertSafe(awm_display_meta_value('u', array('case' => 'user'), 0, $user));
        /*WordPress strips tags from term names on insert; the name still goes through esc_html()*/
        $this->assertSame('Cat z', awm_display_meta_value('c', array('case' => 'term', 'taxonomy' => 'category'), 0, $term));
        $this->assertSafe(awm_display_meta_value('u', array('case' => 'user'), 0, self::PAYLOAD));
    }

    /**
     * Repeater labels are escaped; option labels keep safe markup only.
     *
     * @return void
     */
    public function test_repeater_and_options()
    {
        $repeater = array('case' => 'repeater', 'include' => array('k' => array('case' => 'input', 'type' => 'text', 'label' => 'L <b>', 'admin_list' => true)));
        $html     = awm_display_meta_value('r', $repeater, 0, array(array('k' => self::PAYLOAD)));
        $this->assertStringContainsString('L &lt;b&gt;: ', $html);
        $this->assertSafe($html);

        $select = array('case' => 'select', 'options' => array('a' => array('label' => '<span class="dashicons">A</span><script>x</script>')));
        $html   = awm_display_meta_value('s', $select, 0, 'a');
        $this->assertStringContainsString('<span class="dashicons">A</span>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * Developer markup (message/html value) stays raw.
     *
     * @return void
     */
    public function test_developer_markup_is_raw()
    {
        $this->assertSame('<em>dev</em>', awm_display_meta_value('m', array('case' => 'message', 'value' => '<em>dev</em>'), 0, 'x'));
    }
}
