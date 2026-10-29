<?php
/**
 * Leaf field types in isolation: message, function, awm_gallery, button,
 * image, map, and the Media_Field_Renderer behind awm_media_field_html().
 */

use EWP\Fields\Field_Context;
use EWP\Fields\Media_Field_Renderer;
use EWP\Fields\Template_Loader;
use EWP\Fields\Types\Button_Field;
use EWP\Fields\Types\Callback_Field;
use EWP\Fields\Types\Gallery_Field;
use EWP\Fields\Types\Image_Field;
use EWP\Fields\Types\Map_Field;
use EWP\Fields\Types\Message_Field;

if (!function_exists('ewp_leaf_test_callback')) {
    /**
     * Callback used by the `function` field tests.
     *
     * @param mixed $object_id Object id passed by the field.
     * @return string
     */
    function ewp_leaf_test_callback($object_id = 0)
    {
        return '<i>object ' . $object_id . '</i>';
    }
}

class Test_Field_Leaf_Types extends WP_UnitTestCase
{
    /**
     * Build a context for any case.
     *
     * @param string $case       Field case.
     * @param array  $field      Field definition (merged over the case and a label).
     * @param mixed  $value      Resolved value.
     * @param string $classes    Input classes.
     * @param array  $attributes Prepared attributes.
     * @param bool   $required   Required flag.
     * @return Field_Context
     */
    private function context($case, array $field = array(), $value = '', $classes = 'c', array $attributes = array(), $required = false)
    {
        return new Field_Context('_meta', 'meta-id', $value, $field + array('case' => $case, 'label' => 'Label'), $attributes, $classes, $required, 'post', 7, '<span class="awm-explanation">Why</span>');
    }

    /**
     * Create an image attachment.
     *
     * @return int Attachment id.
     */
    private function attachment()
    {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    /**
     * Each type reports its case.
     *
     * @return void
     */
    public function test_case_names()
    {
        $loader = new Template_Loader();
        $this->assertSame('message', (new Message_Field($loader))->case_name());
        $this->assertSame('function', (new Callback_Field($loader))->case_name());
        $this->assertSame('awm_gallery', (new Gallery_Field($loader))->case_name());
        $this->assertSame('button', (new Button_Field($loader))->case_name());
        $this->assertSame('image', (new Image_Field($loader))->case_name());
        $this->assertSame('map', (new Map_Field($loader))->case_name());
    }

    /**
     * A message renders its value in the box.
     *
     * @return void
     */
    public function test_message_renders_value()
    {
        $out = (new Message_Field(new Template_Loader()))->render($this->context('message', array('value' => '<b>Hi</b>')));
        $this->assertSame('<div class="awm-meta-message" id="meta-id"><div class="awm-meta-message-label">Label<span class="awm-explanation">Why</span></div><div class="awm-meta-message-inner"><b>Hi</b></div></div>', $out->html());
        $this->assertTrue($out->has_wrapper());
    }

    /**
     * An empty message renders nothing but keeps the wrapper.
     *
     * @return void
     */
    public function test_message_empty_renders_nothing()
    {
        $out = (new Message_Field(new Template_Loader()))->render($this->context('message'));
        $this->assertSame('', $out->html());
        $this->assertTrue($out->has_wrapper());
        $this->assertFalse($out->is_suppressed());
    }

    /**
     * Message bodies are developer markup and print raw (as before), so form
     * elements and scripts inside them survive.
     *
     * @return void
     */
    public function test_message_body_prints_raw()
    {
        $body = '<input type="text" name="x"><script>init()</script>';
        $out  = (new Message_Field(new Template_Loader()))->render($this->context('message', array('value' => $body)));
        $this->assertStringContainsString('<div class="awm-meta-message-inner">' . $body . '</div>', $out->html());
    }

    /**
     * The function field calls the callback with the object id.
     *
     * @return void
     */
    public function test_callback_calls_function_with_object_id()
    {
        $out = (new Callback_Field(new Template_Loader()))->render($this->context('function', array('callback' => 'ewp_leaf_test_callback')));
        $this->assertStringContainsString('<div class="awm-meta-message-inner"><i>object 7</i></div>', $out->html());
    }

    /**
     * An undefined callback renders nothing.
     *
     * @return void
     */
    public function test_callback_missing_function_renders_nothing()
    {
        $type = new Callback_Field(new Template_Loader());
        $this->assertSame('', $type->render($this->context('function', array('callback' => 'ewp_no_such_function')))->html());
        $this->assertSame('', $type->render($this->context('function'))->html());
    }

    /**
     * The button id strips leading underscores and attributes are escaped.
     *
     * @return void
     */
    public function test_button_data_shaping()
    {
        $context = $this->context('button', array('link' => 'https://example.com/x', 'label' => '<b>Go</b>'), '', 'btn big', array('data-x' => 'a"b'));
        $html    = (new Button_Field(new Template_Loader()))->render($context)->html();
        $this->assertSame('<a href="https://example.com/x" id="meta" title="Go" class="btn big" data-x="a&quot;b"><b>Go</b></a>', $html);
    }

    /**
     * The button link defaults to `#`.
     *
     * @return void
     */
    public function test_button_default_link()
    {
        $html = (new Button_Field(new Template_Loader()))->render($this->context('button'))->html();
        $this->assertStringContainsString('href="#"', $html);
    }

    /**
     * Map value is picked apart, with missing parts empty.
     *
     * @return void
     */
    public function test_map_normalise()
    {
        $this->assertSame(array('lat' => '1', 'lng' => '', 'address' => 'A'), Map_Field::normalise(array('lat' => '1', 'address' => 'A')));
        $this->assertSame(array('lat' => '', 'lng' => '', 'address' => ''), Map_Field::normalise('garbage'));
    }

    /**
     * The map renders its inputs, the required flag and the wrapper class.
     *
     * @return void
     */
    public function test_map_render()
    {
        $out = (new Map_Field(new Template_Loader()))->render($this->context('map', array(), array('lat' => '1.5', 'lng' => '2.5', 'address' => 'Athens "GR"'), 'c', array(), true));
        $this->assertSame(array('awm-cls-100'), $out->wrapper_classes());
        $this->assertStringContainsString('required="true" onkeypress="return noenter()"', $out->html());
        $this->assertStringContainsString('name="_meta[lat]" id="awm_mapmeta-id_lat" value="1.5"', $out->html());
        $this->assertStringContainsString('value="Athens &quot;GR&quot;"', $out->html());
        $this->assertStringContainsString('placeholder="Type to search"', $out->html());
    }

    /**
     * Single mode: input name without brackets, image wrapper class, label first.
     *
     * @return void
     */
    public function test_image_renders_single_media_field()
    {
        $id  = $this->attachment();
        $out = (new Image_Field(new Template_Loader()))->render($this->context('image', array(), $id));
        $this->assertSame(array('awm-custom-image-meta'), $out->wrapper_classes());
        $this->assertStringStartsWith('<label for="meta-id" class="awm-input-label">Label</label><span class="awm-explanation">Why</span><div class="awm-media-field awm-media-field--single"', $out->html());
        $this->assertStringContainsString('name="_meta" value="' . $id . '"', $out->html());
        $this->assertStringContainsString('Change Image', $out->html());
    }

    /**
     * Gallery mode: list input names, message box, a scalar value becomes one item.
     *
     * @return void
     */
    public function test_gallery_renders_unlimited_media_field()
    {
        $id  = $this->attachment();
        $out = (new Gallery_Field(new Template_Loader()))->render($this->context('awm_gallery', array(), $id));
        $this->assertStringStartsWith('<div class="awm-meta-message" id="meta-id"><div class="awm-meta-message-label">Label<span class="awm-explanation">Why</span></div><div class="awm-media-field"', $out->html());
        $this->assertStringContainsString('name="_meta[]" value="' . $id . '"', $out->html());
        $this->assertStringContainsString('data-max="0" data-library="image"', $out->html());
        $this->assertSame(array(), Gallery_Field::ids(''));
        $this->assertSame(array(3), Gallery_Field::ids(3));
        $this->assertSame(array(3, 4), Gallery_Field::ids(array(3, 4)));
    }

    /**
     * Single vs gallery markup and labels from the renderer.
     *
     * @return void
     */
    public function test_media_renderer_single_vs_gallery()
    {
        $renderer = new Media_Field_Renderer();
        $single   = $renderer->render('m', 0, 1);
        $gallery  = $renderer->render('m', array(), 0);
        $this->assertStringContainsString('awm-media-field awm-media-field--single', $single);
        $this->assertStringContainsString('data-library=""', $single);
        $this->assertStringContainsString('Select Image<', $single);
        $this->assertStringNotContainsString('--single', $gallery);
        $this->assertStringContainsString('data-library="image"', $gallery);
        $this->assertStringContainsString('Select Images<', $gallery);
        $this->assertSame('<ul class="awm-gallery-images-list"></ul>', substr($gallery, strpos($gallery, "<ul"), 41));
    }

    /**
     * Attachments that no longer exist are skipped; ids are normalised.
     *
     * @return void
     */
    public function test_media_renderer_skips_missing_attachments()
    {
        $this->assertSame(array(5, 6), array_values(Media_Field_Renderer::normalise_ids(array('5', 6, 0, 'x'))));
        $this->assertSame(array(), Media_Field_Renderer::normalise_ids(''));
        $html = (new Media_Field_Renderer())->render('m', array(999999), 0);
        $this->assertStringNotContainsString('awm-gallery-image"', $html);
    }

    /**
     * awm_media_field_html() and the renderer apply the filter with the same arguments.
     *
     * @return void
     */
    public function test_media_filter_still_applied()
    {
        $args = array();
        add_filter('awm_media_field_html_filter', function ($content, $meta, $images, $max, $types) use (&$args) {
            $args = array($meta, $images, $max, $types);
            return 'FILTERED';
        }, 10, 5);
        $this->assertSame('FILTERED', awm_media_field_html('k', array(3, 0), 0));
        $this->assertSame(array('k', array(0 => 3), 0, 'image'), $args);
        $this->assertSame('FILTERED', (new Media_Field_Renderer())->render('j', 4, 1, 'image'));
        $this->assertSame(array('j', array(4), 1, 'image'), $args);
    }

    /**
     * The wrapper function and the renderer return the same markup.
     *
     * @return void
     */
    public function test_wrapper_matches_renderer()
    {
        $this->assertSame((new Media_Field_Renderer())->render('k', array(), 0), awm_media_field_html('k', array(), 0));
    }
}
