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
 * Case `awm_modal`: a trigger button that opens a modal whose nested fields
 * load and save through the REST API (looked up server-side by meta key and
 * optional options page). It posts nothing with the form, so it never emits
 * the `awm_custom_meta[]` save marker. Markup: templates/fields/modal.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Modal_Field implements Field_Type
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
        return 'awm_modal';
    }

    /**
     * Render the trigger button; nothing (inside the wrapper) without `include`.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        if (empty($context->field('include'))) {
            return (new Field_Output(''))->without_meta_marker();
        }

        /**
         * Filter modal field arguments before rendering.
         *
         * @since 1.2.0 Fired before the button arguments are read since 1.11.0, so they can be changed.
         *
         * @param array  $field     Field configuration.
         * @param string $meta_key  Meta key.
         * @param string $view      View type.
         * @param mixed  $object_id Object ID.
         */
        $field = (array) apply_filters('awm_modal_field_args', $context->field(), $context->name(), $context->view(), $context->object_id());

        $html = $this->templates->render('fields/modal', self::button_args($context, $field));
        return (new Field_Output($html))->without_meta_marker();
    }

    /**
     * Data the trigger button carries for the modal script.
     *
     * @param Field_Context $context Resolved field state.
     * @param array         $field   Filtered field definition.
     * @return array {
     *     @type string $modal_id     Element id.
     *     @type string $meta_key     Meta key.
     *     @type string $view         View the modal loads/saves in (`modal_view`, `none` → `post`).
     *     @type mixed  $object_id    Object id.
     *     @type string $title        Modal title (`modal_title`, else the label).
     *     @type string $button_label Button text (`button_label`, else the label).
     *     @type string $button_class Button classes (default `button button-secondary`).
     *     @type string $option_page  Options page id for option views, '' otherwise.
     * }
     */
    public static function button_args(Field_Context $context, array $field)
    {
        $label = isset($field['label']) ? $field['label'] : '';
        $view  = isset($field['modal_view']) ? $field['modal_view'] : $context->view();
        if ('none' === $view) {
            $view = 'post';
        }
        return array(
            'modal_id'     => $context->id(),
            'meta_key'     => $context->name(),
            'view'         => $view,
            'object_id'    => $context->object_id(),
            'title'        => isset($field['modal_title']) ? $field['modal_title'] : $label,
            'button_label' => isset($field['button_label']) ? $field['button_label'] : $label,
            'button_class' => isset($field['button_class']) ? $field['button_class'] : 'button button-secondary',
            'option_page'  => 'option' === $view ? $context->context_id() : '',
        );
    }
}
