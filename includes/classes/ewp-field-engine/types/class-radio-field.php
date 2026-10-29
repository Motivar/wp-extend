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
 * Case `radio`: one radio input per option, each in its own wrapper unless
 * `disable_wrapper` is set. The field label is added by awm_show_content().
 *
 * Fires the `awm_radio_value_label_filter` and `awm_radio_after_text` filters
 * (see HOOKS.md). Markup lives in templates/fields/radio.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Radio_Field implements Field_Type
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
        return 'radio';
    }

    /**
     * Render the radio group.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $options = self::options($context);

        /**
         * Filter the markup appended after the options of a radio field.
         *
         * @since 1.0.0
         *
         * @param string $text  Markup appended after the options (default '').
         * @param array  $field Field definition.
         */
        $after_text = apply_filters('awm_radio_after_text', '', $context->field());

        return new Field_Output($this->templates->render('fields/radio', array(
            'context'    => $context,
            'wrapper'    => null === $context->field('disable_wrapper'),
            'options'    => $options,
            'after_text' => $after_text,
        )));
    }

    /**
     * Template rows of the radio options: value, DOM id, checked state, the
     * `required` attribute (first option only) and the filtered label.
     *
     * @param Field_Context $context Resolved field state.
     * @return array<int, array<string, mixed>>
     */
    public static function options(Field_Context $context)
    {
        $definitions = $context->field('options', array());
        $rows        = array();
        if (!is_array($definitions)) {
            return $rows;
        }
        foreach ($definitions as $value => $definition) {
            /**
             * Filter the label of one radio option.
             *
             * @since 1.0.0
             *
             * @param string $label            Option label.
             * @param string $vkey             Option value.
             * @param string $original_meta_id Field DOM id.
             */
            $label  = apply_filters('awm_radio_value_label_filter', isset($definition['label']) ? $definition['label'] : $value, $value, $context->id());
            $rows[] = array(
                'value'    => $value,
                'id'       => $context->id() . '_' . $value,
                'checked'  => $value == $context->value(),
                'required' => empty($rows) && $context->required(),
                'label'    => $label,
            );
        }
        return $rows;
    }
}
