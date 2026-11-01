<?php
/**
 * Search form / terms templates and the options REST button use data-ewp-action
 * attributes instead of inline onclick handlers.
 */

class Test_Search_Templates extends WP_UnitTestCase
{
    /**
     * Render the search form template with the not_async buttons.
     *
     * @param string $async Async mode.
     * @return string HTML.
     */
    private function render_form($async)
    {
        return awm_parse_template(awm_path . 'templates/frontend/search/form.php', array(
            'id' => '12abc',
            'async' => $async,
            'orientation' => 'horizontal',
            'options' => array('show_results' => '#res', 'x' => '"q"'),
            'form_html' => '<input name="s">',
            'button_apply' => 'Apply <b>',
            'button_reset' => 'Reset',
        ));
    }

    /**
     * Wrapper keeps its ids/classes/attributes and the buttons use data attributes.
     *
     * @return void
     */
    public function test_form_not_async()
    {
        $html = $this->render_form('not_async');
        $this->assertStringContainsString('id="ewp-search-12"', $html);
        $this->assertStringContainsString('class="ewp-search-box not_async horizontal"', $html);
        $this->assertStringContainsString('search-id="12"', $html);
        $this->assertStringContainsString('id="ewp-search-form-12"', $html);
        $this->assertStringContainsString('options="{&#039;show_results&#039;:&#039;#res&#039;,', $html);
        $this->assertStringContainsString('data-ewp-action="search-apply" data-ewp-search-id="12">Apply &lt;b&gt;<', $html);
        $this->assertStringContainsString('data-ewp-action="search-reset" data-ewp-search-id="12"', $html);
        $this->assertStringContainsString('class="ewp-search-apply"', $html);
        $this->assertStringContainsString('class="ewp-search-reset"', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * Async forms render no action buttons.
     *
     * @return void
     */
    public function test_form_async_has_no_buttons()
    {
        $html = $this->render_form('async');
        $this->assertStringNotContainsString('ewp-search-actions', $html);
        $this->assertStringNotContainsString('data-ewp-action', $html);
    }

    /**
     * Terms template escapes the filter key into a data attribute.
     *
     * @return void
     */
    public function test_results_terms()
    {
        global $ewp_search_query_terms, $ewp_search_id;
        $ewp_search_query_terms = array("a'b\"c" => array('One', 'Two'));
        $ewp_search_id = '5';
        $html = awm_parse_template(awm_path . 'templates/frontend/search/results-terms.php');
        $this->assertStringContainsString('data-ewp-action="search-remove-filter"', $html);
        $this->assertStringContainsString('data-ewp-filter-key="a&#039;b&quot;c"', $html);
        $this->assertStringContainsString('data-ewp-search-id="5"', $html);
        $this->assertStringContainsString('class="action"', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * The options page REST button is declared with escaped data attributes.
     *
     * @return void
     */
    public function test_settings_button_source()
    {
        $source = file_get_contents(awm_path . 'templates/settings.php');
        $this->assertStringNotContainsString('onclick', $source);
        foreach (array('options-rest', 'data-ewp-form', 'data-ewp-endpoint', 'data-ewp-callback', 'data-ewp-method') as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
        $this->assertGreaterThanOrEqual(4, substr_count($source, 'esc_attr($'), 'every data attribute is esc_attr');
    }
}
