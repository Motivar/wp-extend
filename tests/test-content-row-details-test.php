<?php
/**
 * AWM_Add_Custom_List::row_details() and the `ewp_content_row_details` filter that
 * feed the "Row details" disclosure of a custom content edit form, plus how
 * templates/admin-view/update-box.php prints the entries.
 */

class Test_Content_Row_Details extends WP_UnitTestCase
{
    /**
     * Remove filters added by a test.
     *
     * @return void
     */
    public function tear_down()
    {
        remove_all_filters('ewp_content_row_details');
        parent::tear_down();
    }

    /**
     * Render the update box template with the given details.
     *
     * @param array $details Normalized entries.
     * @return string
     */
    private function render(array $details)
    {
        return awm_parse_template(awm_path . 'templates/admin-view/update-box.php', array(
            'status_html' => '',
            'delete_url'  => '',
            'save_text'   => 'Update',
            'details'     => $details,
        ));
    }

    /**
     * A saved row gets its id and hash; a new row gets nothing.
     *
     * @return void
     */
    public function test_defaults()
    {
        $details = AWM_Add_Custom_List::row_details(array('content_id' => '39', 'hash' => 'abc123'), 'ewp_fields');
        $this->assertSame(array('content_id', 'hash'), array_keys($details));
        $this->assertSame(array('label' => 'ID', 'value' => '39', 'code' => true, 'html' => false), $details['content_id']);
        $this->assertSame(array(), AWM_Add_Custom_List::row_details(array(), 'ewp_fields'));
    }

    /**
     * The filter gets the row and content type, can add and remove entries;
     * malformed entries are dropped and flags default to false.
     *
     * @return void
     */
    public function test_filter_adds_removes_and_normalizes()
    {
        $seen = array();
        add_filter('ewp_content_row_details', function ($details, $item, $type) use (&$seen) {
            $seen = array($item['status'], $type);
            unset($details['hash']);
            $details['created'] = array('label' => 'Created', 'value' => '2026-10-30');
            $details['broken']  = array('label' => 'No value');
            $details['empty']   = array('label' => 'Empty', 'value' => '');
            $details['array']   = array('label' => 'Array', 'value' => array('x'));
            $details['scalar']  = 'not an entry';
            return $details;
        }, 10, 3);

        $details = AWM_Add_Custom_List::row_details(array('content_id' => 7, 'hash' => 'h', 'status' => 'enabled'), 'flx_booking');
        $this->assertSame(array('enabled', 'flx_booking'), $seen);
        $this->assertSame(array('content_id', 'created'), array_keys($details));
        $this->assertSame(array('label' => 'Created', 'value' => '2026-10-30', 'code' => false, 'html' => false), $details['created']);
    }

    /**
     * Returning an empty array (or a non-array) hides the disclosure.
     *
     * @return void
     */
    public function test_empty_hides_disclosure()
    {
        add_filter('ewp_content_row_details', '__return_false');
        $details = AWM_Add_Custom_List::row_details(array('content_id' => 7), 'x');
        $this->assertSame(array(), $details);
        $this->assertStringNotContainsString('<details', $this->render($details));
    }

    /**
     * Text values are escaped, html values kses'd, code values wrapped in <code>.
     *
     * @return void
     */
    public function test_template_escaping()
    {
        add_filter('ewp_content_row_details', function ($details) {
            $details['text'] = array('label' => 'Text <b>', 'value' => '<script>x</script>&');
            $details['link'] = array('label' => 'Link', 'value' => '<a href="https://example.com">View</a><script>bad()</script>', 'html' => true);
            return $details;
        });
        $html = $this->render(AWM_Add_Custom_List::row_details(array('content_id' => 39, 'hash' => 'abc'), 'x'));

        $this->assertStringContainsString('<summary>Row details</summary>', $html);
        $this->assertMatchesRegularExpression('#data-detail="content_id">\s*<code>39</code>#', $html);
        $this->assertStringContainsString('<dt>Text &lt;b&gt;</dt>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;&amp;', $html);
        $this->assertStringContainsString('<a href="https://example.com">View</a>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
