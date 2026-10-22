<?php
/**
 * awm_field_hidden_in_current_language(): auto-translated fields are skipped only
 * in a secondary WPML language, so options pages neither render nor register them.
 */

class Test_Field_Hidden_Language extends WP_UnitTestCase
{
    /**
     * Without WPML nothing is hidden, auto_translate or not.
     *
     * @return void
     */
    public function test_not_hidden_without_wpml()
    {
        $this->assertFalse(awm_field_hidden_in_current_language(array('case' => 'image', 'auto_translate' => '1')));
        $this->assertFalse(awm_field_hidden_in_current_language(array('case' => 'image')));
        $this->assertFalse(awm_field_hidden_in_current_language('not-an-array'));
    }

    /**
     * The filter can force the decision (e.g. a secondary language).
     *
     * @return void
     */
    public function test_filter_overrides()
    {
        $cb = function ($hidden, $field) {
            return isset($field['auto_translate']);
        };
        add_filter('awm_field_hidden_in_current_language', $cb, 10, 2);
        $this->assertTrue(awm_field_hidden_in_current_language(array('auto_translate' => '1')));
        $this->assertFalse(awm_field_hidden_in_current_language(array('case' => 'input')));
        remove_filter('awm_field_hidden_in_current_language', $cb, 10);
    }
}
