<?php
/**
 * EWP\Fields engine in isolation: attributes, template resolution, registry
 * extension point and the Input_Field type.
 */

use EWP\Fields\Field_Attributes;
use EWP\Fields\Field_Context;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Registry;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;
use EWP\Fields\Types\Checkbox_Multiple_Field;
use EWP\Fields\Types\Input_Field;
use EWP\Fields\Types\Radio_Field;
use EWP\Fields\Types\Select_Field;
use EWP\Fields\Types\Textarea_Field;

class Test_Field_Engine extends WP_UnitTestCase
{
    /**
     * Fresh registry per test so actions added here do not leak.
     *
     * @return void
     */
    public function tear_down()
    {
        Field_Registry::reset();
        parent::tear_down();
    }

    /**
     * Build a context for an input field.
     *
     * @param array $field Field definition.
     * @param mixed $value Value.
     * @return Field_Context
     */
    private function context(array $field, $value = '')
    {
        return new Field_Context('f', 'f-id', $value, $field + array('case' => 'input'), array(), 'c', false, 'post', 1);
    }

    /**
     * Build a context for any case, view and value.
     *
     * @param string $case  Field case.
     * @param array  $field Field definition (merged over the case).
     * @param mixed  $value Value.
     * @param string $view  Render view.
     * @param array  $attributes Prepared attributes.
     * @return Field_Context
     */
    private function context_for($case, array $field, $value = '', $view = 'post', array $attributes = array())
    {
        return new Field_Context('f', 'f-id', $value, $field + array('case' => $case, 'label' => 'Label'), $attributes, 'c', false, $view, 1, '<span class="awm-explanation">Why</span>');
    }

    /**
     * Arrays become comma lists, values are escaped on render.
     *
     * @return void
     */
    public function test_attributes_prepare_and_render()
    {
        $prepared = Field_Attributes::prepare(array('data-x' => array('a', 'b'), 'title' => 'say "hi"'));
        $this->assertSame(array('data-x' => 'a,b', 'title' => 'say "hi"'), $prepared);
        $this->assertSame('data-x="a,b" title="say &quot;hi&quot;"', Field_Attributes::render($prepared));
        $this->assertSame('', Field_Attributes::render(array()));
    }

    /**
     * Template names that could traverse out of the templates dir resolve to nothing.
     *
     * @return void
     */
    public function test_template_loader_rejects_invalid_names()
    {
        $loader = new Template_Loader();
        $this->assertSame('', $loader->locate('../extend-wp'));
        $this->assertSame('', $loader->locate('fields/Input'));
        $this->assertSame('', $loader->locate('fields/missing-template'));
        $this->assertStringEndsWith('templates/fields/input.php', $loader->locate('fields/input'));
    }

    /**
     * ewp_field_template_path can swap the file a template renders from.
     *
     * @return void
     */
    public function test_template_path_filter_overrides()
    {
        $override = get_temp_dir() . 'ewp-input-override.php';
        file_put_contents($override, '<?php echo "override:" . $variables["x"];');
        $cb = function ($path, $name) use ($override) {
            return 'fields/input' === $name ? $override : $path;
        };
        add_filter('ewp_field_template_path', $cb, 10, 2);
        $this->assertSame('override:1', (new Template_Loader())->render('fields/input', array('x' => 1)));
        remove_filter('ewp_field_template_path', $cb, 10);
        unlink($override);
    }

    /**
     * Value normalisation per input type.
     *
     * @return void
     */
    public function test_input_normalise_value()
    {
        $this->assertSame(42, Input_Field::normalise_value('number', '42abc'));
        $this->assertSame(1, Input_Field::normalise_value('checkbox', ''));
        $this->assertSame('00:00', Input_Field::normalise_value('time', '2026-10-29'));
        $this->assertSame('2026-10-29 14:35:00', Input_Field::normalise_value('time', '2026-10-29 14:35:00'), 'Values containing ":" pass through (legacy behaviour).');
        $this->assertSame('09:00', Input_Field::normalise_value('time', '09:00'));
        $this->assertSame('x', Input_Field::normalise_value('text', 'x'));
    }

    /**
     * Hidden inputs render bare; others get the wrapper, eye and encryption marker.
     *
     * @return void
     */
    public function test_input_render_variants()
    {
        $type = new Input_Field(new Template_Loader());

        $hidden = $type->render($this->context(array('type' => 'hidden'), 'v'));
        $this->assertFalse($hidden->has_wrapper());
        $this->assertStringNotContainsString('input-wrapper', $hidden->html());

        $password = $type->render($this->context(array('type' => 'password', 'encrypt' => true), 's'));
        $html     = $password->html();
        $this->assertTrue($password->has_wrapper());
        $this->assertStringContainsString('<div class="eye" data-toggle="password" data-id="f-id">', $html);
        $this->assertStringContainsString('name="ewp_encrypt[]" value="f"', $html);
        $this->assertStringNotContainsString(">\n<", $html, 'Template must not emit whitespace between elements.');
    }

    /**
     * ewp_register_field_types can add or replace types.
     *
     * @return void
     */
    public function test_registry_extension_point()
    {
        $custom = new class implements Field_Type {
            /**
             * Replaces the built-in input type.
             *
             * @return string
             */
            public function case_name()
            {
                return 'input';
            }

            /**
             * Fixed marker markup.
             *
             * @param Field_Context $context Ignored.
             * @return Field_Output
             */
            public function render(Field_Context $context)
            {
                return new Field_Output('<i>custom</i>');
            }
        };
        $cb = function (Field_Registry $registry) use ($custom) {
            $registry->register($custom);
        };
        add_action('ewp_register_field_types', $cb);
        Field_Registry::reset();

        $this->assertSame($custom, Field_Registry::instance()->get('input'));
        $this->assertNull(Field_Registry::instance()->get('image'), 'Unmigrated cases fall back to the legacy switch.');
        $html = awm_show_content(array('f' => array('case' => 'input', 'type' => 'text', 'label' => 'F')), 0, 'none');
        $this->assertStringContainsString('<i>custom</i>', $html);

        remove_action('ewp_register_field_types', $cb);
    }

    /**
     * Textarea: plain mode strips tags on request, escapes the value, and adds the full-width class.
     *
     * @return void
     */
    public function test_textarea_plain_and_strip_html()
    {
        $type   = new Textarea_Field(new Template_Loader());
        $output = $type->render($this->context_for('textarea', array('awm_strip_html' => true), '<b>x</b> & <i>y</i>'));
        $this->assertStringContainsString('>x &amp; y</textarea>', $output->html());
        $this->assertSame(array('awm-cls-100'), $output->wrapper_classes());

        $raw = $type->render($this->context_for('textarea', array(), '<b>x</b>'));
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;</textarea>', $raw->html());
    }

    /**
     * Textarea with wp_editor: wrapper gets awm-cls-100 and awm-wp-editor, output holds the editor's textarea id.
     *
     * @return void
     */
    public function test_textarea_wp_editor_wrapper_classes()
    {
        $type   = new Textarea_Field(new Template_Loader());
        $output = $type->render($this->context_for('textarea', array('wp_editor' => true), 'Hello &amp; bye'));
        $this->assertSame(array('awm-cls-100', 'awm-wp-editor'), $output->wrapper_classes());
        $this->assertStringContainsString('id="f-id"', $output->html());
        $this->assertTrue(Textarea_Field::uses_editor($this->context_for('textarea', array('attributes' => array('wp_editor' => true)))));
        $this->assertFalse(Textarea_Field::uses_editor($this->context_for('textarea', array())));
    }

    /**
     * Select: selected state, multiple name suffix and the placeholder / removeEmpty switch.
     *
     * @return void
     */
    public function test_select_selected_multiple_and_placeholder()
    {
        $options = array('a' => array('label' => 'A'), 'b' => array('label' => 'B'));
        $type    = new Select_Field(new Template_Loader());

        $multi = $this->context_for('select', array('options' => $options, 'attributes' => array('multiple' => true)), array('a'), 'post', array('multiple' => true));
        $html  = $type->render($multi)->html();
        $this->assertStringContainsString('name="f[]"', $html);
        $this->assertStringContainsString('<option value="a" selected', $html);
        $this->assertStringNotContainsString('<option value="b" selected', $html);
        $this->assertStringContainsString('data-placeholder="true"', $html);

        $bare = $type->render($this->context_for('select', array('options' => $options, 'removeEmpty' => true, 'no_style' => true), 'b'))->html();
        $this->assertStringNotContainsString('data-placeholder', $bare);
        $this->assertStringNotContainsString('data-html', $bare);
        $this->assertStringContainsString('<option value="b" selected', $bare);
        $this->assertSame(array('b'), Select_Field::selected_values('b'));
        $this->assertSame(array(), Select_Field::selected_values(''));
    }

    /**
     * Select: options with an optgroup are moved into their group; groups come first and unused groups are dropped.
     *
     * @return void
     */
    public function test_select_optgroup_grouping()
    {
        $options = array(
            'a'         => array('label' => 'A', 'optgroup' => 'g1'),
            'b'         => array('label' => 'B'),
            'c'         => array('label' => 'C', 'optgroup' => 'g1'),
            'optgroups' => array('g1' => array('label' => 'One'), 'g2' => array('label' => 'Unused')),
        );
        $context = $this->context_for('select', array('options' => $options));
        $entries = Select_Field::entries(Select_Field::options($context), array(), Select_Field::optgroups($context), true);

        $this->assertSame(array('group_start', 'option', 'option', 'group_end', 'option'), wp_list_pluck($entries, 'kind'));
        $this->assertSame('a,c', $entries[0]['options']);
        $this->assertSame('b', $entries[4]['value']);
        $this->assertArrayNotHasKey('optgroups', Select_Field::options($context));
    }

    /**
     * Select: no options suppresses the field only in the list-table filter view.
     *
     * @return void
     */
    public function test_select_empty_options_suppressed_in_restrict_manage_posts()
    {
        $type = new Select_Field(new Template_Loader());
        $this->assertTrue($type->render($this->context_for('select', array('options' => array()), '', 'restrict_manage_posts'))->is_suppressed());
        $this->assertFalse($type->render($this->context_for('select', array('options' => array()), '', 'post'))->is_suppressed());
        $this->assertFalse($type->render($this->context_for('select', array('options' => array('a' => array('label' => 'A'))), '', 'restrict_manage_posts'))->is_suppressed());
    }

    /**
     * Select: extra option attributes are escaped and option labels pass through kses.
     *
     * @return void
     */
    public function test_select_extra_attributes_are_escaped()
    {
        $options = array('a' => array('label' => 'A<script>x</script>', 'extra' => array('title' => 'say "hi"')));
        $html    = (new Select_Field(new Template_Loader()))->render($this->context_for('select', array('options' => $options)))->html();
        $this->assertStringContainsString('title="say &quot;hi&quot;"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * Radio: checked state, required on the first option only, wrapper toggle and the label / after-text filters.
     *
     * @return void
     */
    public function test_radio_checked_required_wrapper_and_filters()
    {
        $options = array('a' => array('label' => 'A'), 'b' => array('label' => 'B'));
        $type    = new Radio_Field(new Template_Loader());
        $label   = function ($text, $vkey) {
            return $text . '-' . $vkey;
        };
        $after   = function () {
            return '<p class="after">x</p>';
        };
        add_filter('awm_radio_value_label_filter', $label, 10, 2);
        add_filter('awm_radio_after_text', $after);

        $required = new Field_Context('f', 'f-id', 'b', array('case' => 'radio', 'options' => $options), array(), 'c', true, 'post', 1);
        $html     = $type->render($required)->html();
        $this->assertSame(1, substr_count($html, 'required="true"'));
        $this->assertStringContainsString('value="b" checked="checked"', $html);
        $this->assertStringContainsString('>A-a</span>', $html);
        $this->assertStringContainsString('<div class="awm-radio-option">', $html);
        $this->assertStringEndsWith('</div><p class="after">x</p>', $html);

        $bare = $type->render($this->context_for('radio', array('options' => $options, 'disable_wrapper' => true), 'a'))->html();
        $this->assertStringNotContainsString('awm-radio-option"', $bare);

        remove_filter('awm_radio_value_label_filter', $label, 10);
        remove_filter('awm_radio_after_text', $after);
    }

    /**
     * Checkbox multiple (toggle): apply-all first with its "Deselect All" label, checked options marked.
     *
     * @return void
     */
    public function test_checkbox_multiple_toggle_apply_all()
    {
        $options = array('a' => array('label' => 'A'), 'b' => array('label' => 'B'));
        $output  = (new Checkbox_Multiple_Field(new Template_Loader()))->render($this->context_for('checkbox_multiple', array('options' => $options), array('b')));
        $html    = $output->html();

        $this->assertSame(array(), $output->wrapper_attributes());
        $this->assertStringContainsString('data-extra="Deselect All"', $html);
        $this->assertStringContainsString('<span class="awm-explanation">Why</span>', $html);
        $this->assertSame(1, preg_match_all('/\schecked\s/', $html), 'Only option b is checked.');
        $this->assertLessThan(strpos($html, 'data-value="a"'), strpos($html, 'data-value="awm_apply_all"'));
    }

    /**
     * Checkbox multiple (exclusive): wrapper attribute, "All" checked only while nothing else is.
     *
     * @return void
     */
    public function test_checkbox_multiple_exclusive_apply_all()
    {
        $options = array('a' => array('label' => 'A'), 'b' => array('label' => 'B'));
        $field   = array('options' => $options, 'apply_all_mode' => 'exclusive', 'apply_all_label' => 'Everything');
        $type    = new Checkbox_Multiple_Field(new Template_Loader());

        $none = $type->render($this->context_for('checkbox_multiple', $field, array()));
        $this->assertSame(array('data-apply-all-mode="exclusive"'), $none->wrapper_attributes());
        $this->assertStringContainsString('<span>Everything</span>', $none->html());
        $this->assertMatchesRegularExpression('/value=""\s+checked/', $none->html());

        $some = $type->render($this->context_for('checkbox_multiple', $field, array('a')));
        $this->assertDoesNotMatchRegularExpression('/value=""\s+checked/', $some->html());
        $this->assertMatchesRegularExpression('/value="a"\s+checked/', $some->html());

        $rows = Checkbox_Multiple_Field::rows($this->context_for('checkbox_multiple', $field, array('a')));
        $this->assertFalse($rows[0]['checked']);
        $this->assertTrue($rows[1]['checked']);
    }

    /**
     * Checkbox multiple: disable_apply_all drops the option and the exclusive wrapper attribute; no options render only the title.
     *
     * @return void
     */
    public function test_checkbox_multiple_disable_apply_all_and_empty()
    {
        $type   = new Checkbox_Multiple_Field(new Template_Loader());
        $field  = array('options' => array('a' => array('label' => 'A')), 'apply_all_mode' => 'exclusive', 'disable_apply_all' => true);
        $output = $type->render($this->context_for('checkbox_multiple', $field, array()));

        $this->assertStringNotContainsString('awm_apply_all', $output->html());
        $this->assertSame(array(), $output->wrapper_attributes());

        $empty = $type->render($this->context_for('checkbox_multiple', array('options' => array())));
        $this->assertStringContainsString('<div class="awm-options-wrapper"></div>', $empty->html());
        $this->assertStringStartsWith('<label class="awm-checkboxes-title">', $empty->html());
    }
}
