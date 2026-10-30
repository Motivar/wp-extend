<?php
/**
 * The Fields edit form's "Php code" box: template markup with a data
 * attribute, no inline onclick (since 1.14.0).
 */

class Test_Php_Code_Box extends WP_UnitTestCase
{
    /**
     * Reset the global row id.
     *
     * @return void
     */
    public function tear_down()
    {
        unset($GLOBALS['ewp_content_id']);
        parent::tear_down();
    }

    /**
     * A saved field group gets the box; the button carries the row id as data.
     *
     * @return void
     */
    public function test_box_markup()
    {
        $GLOBALS['ewp_content_id'] = '12"onclick="x';
        $views = awm_php_views();
        $html  = $views['position_content']['value'];

        $this->assertSame('html', $views['position_content']['case']);
        $this->assertStringContainsString('data-ewp-php-code="12"', $html);
        $this->assertStringContainsString('<div id="awm-php-code"></div>', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * A new (unsaved) group gets no box.
     *
     * @return void
     */
    public function test_no_box_for_new_group()
    {
        $GLOBALS['ewp_content_id'] = 0;
        $this->assertSame(array(), awm_php_views());
    }
}
