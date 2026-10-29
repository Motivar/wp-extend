<?php
/**
 * EWP\Fields engine in isolation: attributes, template resolution, registry
 * extension point and the Input_Field type.
 */

use EWP\Fields\Field_Attributes;
use EWP\Fields\Field_Context;
use EWP\Fields\Field_Registry;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;
use EWP\Fields\Types\Input_Field;

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

        $hidden = $this->context(array('type' => 'hidden'), 'v');
        $this->assertFalse($type->has_wrapper($hidden));
        $this->assertStringNotContainsString('input-wrapper', $type->render($hidden));

        $password = $this->context(array('type' => 'password', 'encrypt' => true), 's');
        $html     = $type->render($password);
        $this->assertTrue($type->has_wrapper($password));
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
             * @return string
             */
            public function render(Field_Context $context)
            {
                return '<i>custom</i>';
            }

            /**
             * Always wrapped.
             *
             * @param Field_Context $context Ignored.
             * @return bool
             */
            public function has_wrapper(Field_Context $context)
            {
                return true;
            }
        };
        $cb = function (Field_Registry $registry) use ($custom) {
            $registry->register($custom);
        };
        add_action('ewp_register_field_types', $cb);
        Field_Registry::reset();

        $this->assertSame($custom, Field_Registry::instance()->get('input'));
        $this->assertNull(Field_Registry::instance()->get('select'), 'Unmigrated cases fall back to the legacy switch.');
        $html = awm_show_content(array('f' => array('case' => 'input', 'type' => 'text', 'label' => 'F')), 0, 'none');
        $this->assertStringContainsString('<i>custom</i>', $html);

        remove_action('ewp_register_field_types', $cb);
    }
}
