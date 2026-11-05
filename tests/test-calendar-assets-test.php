<?php
/**
 * The datepicker is handed to the Dynamic Asset Loader instead of being enqueued up front.
 */
class Test_Calendar_Assets extends WP_UnitTestCase
{
    /**
     * Collect the calendar registrations as the loader's filter would.
     *
     * @return array Assets keyed by handle.
     */
    private function assets()
    {
        $by_handle = array();
        foreach ((new AWM_Meta())->register_calendar_assets(array()) as $asset) {
            $by_handle[$asset['handle']] = $asset;
        }
        return $by_handle;
    }

    public function test_registers_datepicker_chain_and_calendar_script()
    {
        $assets = $this->assets();

        $this->assertSame('.awm_cl_date', $assets['jquery-ui-datepicker']['selector']);
        $this->assertSame(array('jquery-ui-core'), $assets['jquery-ui-datepicker']['dependencies']);
        $this->assertStringContainsString('datepicker', $assets['jquery-ui-datepicker']['src']);
        $this->assertSame('frontend', $assets['jquery-ui-datepicker']['context']);
        $this->assertSame('style', $assets['jquery-ui-awm']['type']);
        $this->assertSame(array('jquery-ui-datepicker'), $assets['awm-calendar']['dependencies']);
        $this->assertStringContainsString('build/modules/awm-calendar.js', $assets['awm-calendar']['src']);
    }

    public function test_calendar_script_localizes_regional_defaults()
    {
        $regional = $this->assets()['awm-calendar']['localize']['data']['regional'];

        $this->assertCount(12, $regional['monthNames']);
        $this->assertCount(7, $regional['dayNames']);
        $this->assertIsInt($regional['firstDay']);
    }

    public function test_front_end_no_longer_enqueues_the_datepicker()
    {
        wp_dequeue_script('jquery-ui-datepicker');
        (new AWM_Meta())->enqueue_styles_script();

        $this->assertFalse(wp_script_is('jquery-ui-datepicker', 'enqueued'));
    }

    public function test_wp_rocket_keeps_jquery_out_of_delay()
    {
        $exclusions = (new EWP_WP_Rocket())->exclude_from_delay_js(array());

        $this->assertContains('/wp-includes/js/jquery/jquery.min.js', $exclusions);
    }
}
