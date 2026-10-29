<?php

namespace EWP\Fields\Types;

use EWP\Fields\Field_Attributes;
use EWP\Fields\Field_Context;
use EWP\Fields\Field_Output;
use EWP\Fields\Field_Type;
use EWP\Fields\Template_Loader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case `checkbox_multiple`: a titled group of checkboxes with an optional
 * apply-all option in front.
 *
 * `apply_all_mode` `toggle` (default) makes the first option check and uncheck
 * every other one; `exclusive` makes it an "All" choice that means "no
 * filter", checked while nothing else is, and tags the row wrapper with
 * `data-apply-all-mode="exclusive"`. `disable_apply_all` removes the option and
 * `apply_all_label` renames it. Prints its own title and explanation (the
 * shared label code skips this case). Markup: templates/fields/checkbox-multiple.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Checkbox_Multiple_Field implements Field_Type
{
    /** @var string Option key and DOM id part of the apply-all checkbox. */
    const APPLY_ALL = 'awm_apply_all';

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
        return 'checkbox_multiple';
    }

    /**
     * Render the checkbox group.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $options = $context->field('options');
        $rows    = empty($options) ? array() : self::rows($context);
        $html    = $this->templates->render('fields/checkbox-multiple', array(
            'context' => $context,
            'rows'    => $rows,
        ));

        $output = new Field_Output($html);
        if (!empty($options) && self::has_exclusive_apply_all($context)) {
            return $output->with_wrapper_attributes(array('data-apply-all-mode="exclusive"'));
        }
        return $output;
    }

    /**
     * Whether the apply-all option is shown and works as an exclusive "All".
     *
     * @param Field_Context $context Resolved field state.
     * @return bool
     */
    public static function has_exclusive_apply_all(Field_Context $context)
    {
        return 'exclusive' === self::apply_all_mode($context) && self::apply_all_enabled($context);
    }

    /**
     * `exclusive` or `toggle` (default).
     *
     * @param Field_Context $context Resolved field state.
     * @return string
     */
    public static function apply_all_mode(Field_Context $context)
    {
        return 'exclusive' === $context->field('apply_all_mode') ? 'exclusive' : 'toggle';
    }

    /**
     * Whether the apply-all option is shown (`disable_apply_all` not set).
     *
     * @param Field_Context $context Resolved field state.
     * @return bool
     */
    public static function apply_all_enabled(Field_Context $context)
    {
        return null === $context->field('disable_apply_all');
    }

    /**
     * Checked values as a list.
     *
     * @param mixed $value Resolved field value.
     * @return array
     */
    public static function checked_values($value)
    {
        return is_array($value) ? $value : array($value);
    }

    /**
     * Whether at least one real option (not the apply-all one) is checked.
     *
     * @param array $options Field options.
     * @param array $checked Checked values.
     * @return bool
     */
    public static function has_selection(array $options, array $checked)
    {
        foreach ($options as $key => $unused) {
            if (in_array($key, $checked)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Template rows for the apply-all option (when enabled) and every option.
     *
     * @param Field_Context $context Resolved field state.
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Field_Context $context)
    {
        $options     = (array) $context->field('options');
        $checked     = self::checked_values($context->value());
        $mode        = self::apply_all_mode($context);
        $all_checked = 'exclusive' === $mode && !self::has_selection($options, $checked);
        $all         = self::apply_all_enabled($context) ? array(self::APPLY_ALL => self::apply_all_definition($context, $mode)) : array();
        $attributes  = Field_Attributes::render($context->attributes());

        $rows = array();
        foreach ($all + $options as $key => $definition) {
            $is_all = self::APPLY_ALL === $key;
            $rows[] = array(
                'id'          => $context->id() . '_' . $key . '_' . rand(10, 100),
                'name'        => $is_all ? '' : $context->name() . '[]',
                'value'       => $is_all ? '' : $key,
                'data_value'  => $key,
                'checked'     => $is_all ? $all_checked : in_array($key, $checked),
                'attributes'  => $attributes,
                'extra'       => $is_all ? self::apply_all_attribute($mode, $definition) : '',
                'label'       => isset($definition['label']) ? $definition['label'] : $key,
            );
        }
        return $rows;
    }

    /**
     * Definition of the apply-all option.
     *
     * @param Field_Context $context Resolved field state.
     * @param string        $mode    `exclusive` or `toggle`.
     * @return array{label: string, extra_label?: string}
     */
    private static function apply_all_definition(Field_Context $context, $mode)
    {
        $label      = $context->field('apply_all_label');
        $definition = array('label' => (null !== $label && '' !== $label) ? $label : __('Select All', 'extend-wp'));
        if ('toggle' === $mode) {
            $definition['extra_label'] = __('Deselect All', 'extend-wp');
        }
        return $definition;
    }

    /**
     * Data attribute of the apply-all input: the mode marker (exclusive) or
     * the "Deselect All" label (toggle).
     *
     * @param string $mode       `exclusive` or `toggle`.
     * @param array  $definition Apply-all definition.
     * @return string Safe attribute markup, '' for none.
     */
    private static function apply_all_attribute($mode, array $definition)
    {
        if ('exclusive' === $mode) {
            return 'data-apply-all-mode="exclusive"';
        }
        return isset($definition['extra_label']) ? 'data-extra="' . esc_attr($definition['extra_label']) . '"' : '';
    }
}
