<?php
/**
 * Data shaping of the nested / stateful field types: section, awm_tab,
 * repeater, awm_modal and object_id_filter. Markup is covered by
 * tests/test-field-render-snapshot-test.php.
 */

use EWP\Fields\Field_Context;
use EWP\Fields\Template_Loader;
use EWP\Fields\Types\Modal_Field;
use EWP\Fields\Types\Object_Id_Filter_Field;
use EWP\Fields\Types\Repeater_Field;
use EWP\Fields\Types\Section_Field;
use EWP\Fields\Types\Tab_Field;

class Test_Field_Nested_Types extends WP_UnitTestCase
{
    /**
     * Build a context.
     *
     * @param string $case       Field case.
     * @param array  $field      Field definition (merged with the case).
     * @param mixed  $value      Value.
     * @param string $view       View.
     * @param string $context_id Context id.
     * @return Field_Context
     */
    private function context($case, array $field, $value = '', $view = 'post', $context_id = '')
    {
        return new Field_Context('f', 'f-id', $value, $field + array('case' => $case), array(), '', false, $view, 7, '', $context_id);
    }

    /**
     * Section fields post as `{id}[{key}]` with stored values; `keep_inputs`
     * keeps their own keys; configs land in the encryption registry.
     *
     * @return void
     */
    public function test_section_nested_fields()
    {
        $include = array('a' => array('case' => 'input', 'type' => 'text', 'encrypt' => true));

        $fields = Section_Field::nested_fields($this->context('section', array('include' => $include), array('a' => 'stored')));
        $this->assertSame(array('f-id[a]'), array_keys($fields));
        $this->assertSame('stored', $fields['f-id[a]']['attributes']['value']);
        $this->assertSame('a', $fields['f-id[a]']['attributes']['id']);
        $this->assertTrue($GLOBALS['awm_field_configs']['a']['encrypt']);

        $kept = Section_Field::nested_fields($this->context('section', array('include' => $include, 'keep_inputs' => true), array('a' => 'stored')));
        $this->assertSame(array('a'), array_keys($kept));
        $this->assertSame('f-id_a', $kept['a']['attributes']['id']);
        $this->assertArrayNotHasKey('value', $kept['a']['attributes']);
    }

    /**
     * A tab's `callback` receives `callback_variables` (legacy read them from
     * an undefined variable) and falls back to a plain call.
     *
     * @return void
     */
    public function test_tab_include_callbacks()
    {
        $with_args = Tab_Field::tab_include(array('callback' => function ($key) {
            return array($key => array('case' => 'input'));
        }, 'callback_variables' => array('from_args')));
        $this->assertSame(array('from_args'), array_keys($with_args));

        $plain = Tab_Field::tab_include(array('callback' => function () {
            return array('plain' => array('case' => 'input'));
        }));
        $this->assertSame(array('plain'), array_keys($plain));

        $fields = Tab_Field::tab_fields('f', 't1', array('k' => array('case' => 'input')), array('t1' => array('k' => 'v')));
        $this->assertSame('v', $fields['f[t1][k]']['attributes']['value']);
        $this->assertSame('f_t1_k', $fields['f[t1][k]']['attributes']['id']);
    }

    /**
     * Empty tab / repeater / modal definitions render nothing inside the wrapper.
     *
     * @return void
     */
    public function test_empty_definitions_render_nothing()
    {
        $loader = new Template_Loader();
        $this->assertSame('', (new Tab_Field($loader))->render($this->context('awm_tab', array()))->html());
        $this->assertSame('', (new Repeater_Field($loader))->render($this->context('repeater', array()))->html());
        $modal = (new Modal_Field($loader))->render($this->context('awm_modal', array()));
        $this->assertSame('', $modal->html());
        $this->assertFalse($modal->has_meta_marker());
    }

    /**
     * Stored rows win over prePopulated; template rows are disabled.
     *
     * @return void
     */
    public function test_repeater_rows()
    {
        $pre = array(array('k' => 'pre'));
        $this->assertSame($pre, Repeater_Field::stored_rows($this->context('repeater', array('prePopulated' => $pre))));
        $this->assertSame(array(array('k' => 'saved')), Repeater_Field::stored_rows($this->context('repeater', array('prePopulated' => $pre), array(5 => array('k' => 'saved')))));

        $field    = array('include' => Repeater_Field::with_row_key(array('k' => array('case' => 'input'))), 'attributes' => array('data-x' => 1));
        $template = Repeater_Field::row_fields('template', 'f', $field, array());
        $this->assertTrue($template['f[template][k]']['disabled']);
        $this->assertSame(1, $template['f[template][k]']['attributes']['data-x']);
        $this->assertArrayHasKey('f[template][awm_key]', $template);

        $row = Repeater_Field::row_fields(0, 'f', $field, array(array('k' => 'v')));
        $this->assertSame('v', $row['f[0][k]']['attributes']['value']);
        $this->assertArrayNotHasKey('disabled', $row['f[0][k]']);
    }

    /**
     * awm_show_actions_html receives all six arguments and can replace the buttons;
     * hide_buttons drops them.
     *
     * @return void
     */
    public function test_repeater_actions_filter()
    {
        $seen = array();
        $cb   = function ($html, $i, $meta, $field, $meta_id, $rows) use (&$seen) {
            $seen = array($i, $meta, $meta_id, is_array($field), $rows);
            return '<i>custom</i>';
        };
        add_filter('awm_show_actions_html', $cb, 10, 6);
        $repeater = new Repeater_Field(new Template_Loader());
        $field    = array('include' => array('k' => array('case' => 'input')));
        $html     = $repeater->row(0, 'f', $field, 'f-id', array(array('k' => 'v')));
        remove_filter('awm_show_actions_html', $cb, 10);

        $this->assertSame(array(0, 'f', 'f-id', true, array(array('k' => 'v'))), $seen);
        $this->assertStringContainsString('<div class="awm-actions"><i>custom</i></div>', $html);
        $this->assertStringNotContainsString('awm-actions', $repeater->row(0, 'f', $field + array('hide_buttons' => true), 'f-id', array()));
    }

    /**
     * awm_modal_field_args now changes the button; the options page id is
     * carried only for option views.
     *
     * @return void
     */
    public function test_modal_args()
    {
        $cb = function ($field) {
            $field['button_label'] = 'Filtered';
            return $field;
        };
        add_filter('awm_modal_field_args', $cb);
        $out = (new Modal_Field(new Template_Loader()))->render($this->context('awm_modal', array('label' => 'L', 'include' => array('x' => array()))));
        remove_filter('awm_modal_field_args', $cb);
        $this->assertStringContainsString('>Filtered</button>', $out->html());
        $this->assertFalse($out->has_meta_marker());

        $option = Modal_Field::button_args($this->context('awm_modal', array(), '', 'option', 'my_page'), array('modal_view' => 'option'));
        $this->assertSame('my_page', $option['option_page']);
        $none = Modal_Field::button_args($this->context('awm_modal', array(), '', 'none', 'my_page'), array());
        $this->assertSame('post', $none['view']);
        $this->assertSame('', $none['option_page']);
    }

    /**
     * Type options are grouped with the saved type selected; unresolved ids
     * keep their order with a `#id` label.
     *
     * @return void
     */
    public function test_object_id_filter_shaping()
    {
        $groups = Object_Id_Filter_Field::type_groups(
            array('g1' => array('label' => 'G1'), 'g2' => array('label' => 'G2')),
            array('a:x' => array('label' => 'X', 'optgroup' => 'g1'), 'b:y' => array('optgroup' => 'g2')),
            'b:y'
        );
        $this->assertSame('G1', $groups[0]['label']);
        $this->assertFalse($groups[0]['options'][0]['selected']);
        $this->assertSame(array('value' => 'b:y', 'label' => 'b:y', 'selected' => true), $groups[1]['options'][0]);

        $this->assertSame(array(), Object_Id_Filter_Field::preloaded_options('', array(1)));
        $this->assertSame(array(), Object_Id_Filter_Field::preloaded_options('no-colon', array(1)));
        $preloaded = Object_Id_Filter_Field::preloaded_options('post_type:post', array(999999, 999998));
        $this->assertSame(array(999999, 999998), wp_list_pluck($preloaded, 'id'));
    }

    /**
     * The picker posts its own two save markers (none in view `none`).
     *
     * @return void
     */
    public function test_object_id_filter_markers()
    {
        $type = new Object_Id_Filter_Field(new Template_Loader());
        $post = $type->render($this->context('object_id_filter', array('label' => 'L')));
        $this->assertFalse($post->has_meta_marker());
        $this->assertSame(2, substr_count($post->html(), 'name="awm_custom_meta[]"'));
        $this->assertStringContainsString('value="f_ids"', $post->html());

        $none = $type->render($this->context('object_id_filter', array('label' => 'L'), '', 'none'));
        $this->assertStringNotContainsString('awm_custom_meta', $none->html());
    }
}
