<?php

namespace EWP\Fields\Types;

use EWP\Fields\Field_Context;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case `textarea`: a plain textarea, or WordPress' editor when the field sets
 * `wp_editor` (definition or `attributes.wp_editor`).
 *
 * The row wrapper always gets `awm-cls-100`, plus `awm-wp-editor` for the
 * editor. Markup lives in templates/fields/textarea.php; the editor HTML is
 * WordPress core output captured here and printed by the template.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Textarea_Field implements Field_Type
{
    /** @var Template_Loader Template resolver. */
    private $templates;

    /**
     * @param Template_Loader $templates Template resolver.
     */
    public function __construct(Template_Loader $templates)
    {
        $this->templates = $templates;
    }

    /**
     * Field case handled.
     *
     * @return string
     */
    public function case_name()
    {
        return 'textarea';
    }

    /**
     * Render the textarea or the editor.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        if (self::uses_editor($context)) {
            $html = $this->templates->render('fields/textarea', array(
                'context'     => $context,
                'editor_html' => self::editor_html($context),
            ));
            return (new Field_Output($html))->with_wrapper_classes(array('awm-cls-100', 'awm-wp-editor'));
        }

        $html = $this->templates->render('fields/textarea', array(
            'context'     => $context->with_value(self::plain_value($context)),
            'editor_html' => null,
        ));
        return (new Field_Output($html))->with_wrapper_classes(array('awm-cls-100'));
    }

    /**
     * Whether the field renders as the WordPress editor.
     *
     * @param Field_Context $context Resolved field state.
     * @return bool
     */
    public static function uses_editor(Field_Context $context)
    {
        $attributes = $context->field('attributes', array());
        $attributes = is_array($attributes) ? $attributes : array();
        return (bool) $context->field('wp_editor', isset($attributes['wp_editor']) ? $attributes['wp_editor'] : false);
    }

    /**
     * Value shown in a plain textarea: tags stripped when `awm_strip_html` is set.
     *
     * @param Field_Context $context Resolved field state.
     * @return mixed
     */
    public static function plain_value(Field_Context $context)
    {
        if (null === $context->field('awm_strip_html')) {
            return $context->value();
        }
        return strip_tags($context->value());
    }

    /**
     * The editor's HTML, captured from wp_editor(). The value is entity-decoded
     * first so wp_editor() does not encode it twice.
     *
     * @param Field_Context $context Resolved field state.
     * @return string
     */
    private static function editor_html(Field_Context $context)
    {
        $args = awm_get_wp_editor_args($context->name(), $context->classes(), $context->id());
        if ($context->field('disabled', false)) {
            $args['tinymce']['readonly'] = 1;
        }
        $decoded = html_entity_decode($context->value(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        ob_start();
        wp_editor($decoded, $context->id(), $args);
        return (string) ob_get_clean();
    }
}
