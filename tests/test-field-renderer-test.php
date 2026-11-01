<?php
/**
 * EWP\Fields\Field_Renderer paths the HTML snapshots cannot reach: role
 * rules, `specific`, the admin tooltip, filter arguments and globals.
 */

use EWP\Fields\Field_Renderer;

class Test_Field_Renderer extends WP_UnitTestCase
{
    /**
     * Restore the screen and the shared renderer.
     *
     * @return void
     */
    public function tear_down()
    {
        set_current_screen('front');
        Field_Renderer::reset();
        parent::tear_down();
    }

    /**
     * A two-field library.
     *
     * @param array $first Extra keys for the first field.
     * @return array
     */
    private function library(array $first = array())
    {
        return array(
            'r_one' => $first + array('case' => 'input', 'type' => 'text', 'label' => 'One'),
            'r_two' => array('case' => 'input', 'type' => 'text', 'label' => 'Two'),
        );
    }

    /**
     * not_visible_by hides the field and drops it from the library the filter sees.
     *
     * @return void
     */
    public function test_not_visible_by_hides_field()
    {
        $seen = null;
        $cb   = function ($fragments, $id, $library) use (&$seen) {
            $seen = array_keys($library);
            return $fragments;
        };
        add_filter('awm_show_content_filter', $cb, 10, 3);
        $html = (new Field_Renderer(null, null, array('editor')))->render($this->library(array('not_visible_by' => 'editor')), 0, 'option');
        remove_filter('awm_show_content_filter', $cb, 10);

        $this->assertStringNotContainsString('r_one', $html);
        $this->assertStringContainsString('r_two', $html);
        $this->assertSame(array('r_two'), $seen);
    }

    /**
     * not_editable_by renders the field disabled, read-only and without a save marker.
     *
     * @return void
     */
    public function test_not_editable_by_disables_field()
    {
        $html = (new Field_Renderer(null, null, array('editor')))->render($this->library(array('not_editable_by' => array('editor'))), 0, 'option');
        $this->assertMatchesRegularExpression('/id="r_one"[^>]*disabled="disabled"[^>]*readonly="readonly"/', $html);
        $this->assertStringNotContainsString('name="awm_custom_meta[]" value="r_one"', $html);
        $this->assertStringContainsString('name="awm_custom_meta[]" value="r_two"', $html);
    }

    /**
     * Administrators are exempt from role rules.
     *
     * @return void
     */
    public function test_administrator_is_exempt()
    {
        $html = (new Field_Renderer(null, null, array('administrator', 'editor')))->render($this->library(array('not_visible_by' => 'editor')), 0, 'option');
        $this->assertStringContainsString('id="r_one"', $html);
    }

    /**
     * `specific` renders one field; its leading underscore is ignored.
     *
     * @return void
     */
    public function test_specific_field()
    {
        $library = array('_r_secret' => array('case' => 'input', 'type' => 'text', 'label' => 'S')) + $this->library();
        $html    = awm_show_content($library, 0, 'option', 'edit', true, 'r_secret');
        $this->assertStringContainsString('id="_r_secret"', $html);
        $this->assertStringNotContainsString('r_one', $html);
        $this->assertStringContainsString('count="1"', $html);
    }

    /**
     * The `information` tooltip shows in wp-admin only, escaped.
     *
     * @return void
     */
    public function test_information_tooltip_in_admin()
    {
        $library = $this->library(array('information' => 'Say "hi"'));
        $this->assertStringNotContainsString('flx-tippy-admin-message', awm_show_content($library, 0, 'option'));

        set_current_screen('edit-post');
        $this->assertStringContainsString('data-message="Say &quot;hi&quot;"', awm_show_content($library, 0, 'option'));
    }

    /**
     * The filter gets the caller's labels flag and the library sorted by order;
     * $awm_post_id is set for legacy readers (filox-multi-vendor).
     *
     * @return void
     */
    public function test_filter_arguments_and_globals()
    {
        $seen = array();
        $cb   = function ($fragments, $id, $library, $view, $target, $label) use (&$seen) {
            $seen = array($id, array_keys($library), $view, $target, $label, count($fragments));
            return $fragments;
        };
        add_filter('awm_show_content_filter', $cb, 10, 6);
        $library = $this->library(array('order' => 5));
        awm_show_content(array('r_two' => $library['r_two'] + array('order' => 1)) + $library, 42, 'none', 'display', false);
        remove_filter('awm_show_content_filter', $cb, 10);

        $this->assertSame(array(42, array('r_two', 'r_one'), 'none', 'display', false, 2), $seen);
        $this->assertSame(42, $GLOBALS['awm_post_id']);
    }

    /**
     * Empty libraries keep the legacy return values.
     *
     * @return void
     */
    public function test_empty_results()
    {
        $this->assertSame(array(), awm_show_content(array()));
        $this->assertSame(array(), awm_show_content(array('awm-id' => 'only-id')));
        $this->assertSame('', awm_show_content(array('x' => array('case' => 'input', 'type' => 'text', 'show' => 0)), 0, 'option'));
    }

    /**
     * Fields render through the registry: nested libraries (section) re-enter
     * the shared renderer safely.
     *
     * @return void
     */
    public function test_reentrant_nested_render()
    {
        $html = awm_show_content(array(
            'r_sec' => array('case' => 'section', 'label' => 'Sec', 'include' => array('in' => array('case' => 'input', 'type' => 'text', 'label' => 'In'))),
            'r_two' => array('case' => 'input', 'type' => 'text', 'label' => 'Two'),
        ), 0, 'option');
        $this->assertSame(2, substr_count($html, 'class="awm-show-content"'));
        $this->assertStringContainsString('count="2"', $html);
        $this->assertStringContainsString('name="r_sec[in]"', $html);
    }
}
