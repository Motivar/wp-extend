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
 * Case `select` (single, `attributes.multiple`, `optgroups`).
 *
 * Shapes the option list into flat entries (option, optgroup start/end) that
 * templates/fields/select.php loops over. With no options the field renders
 * an empty select, and nothing at all in the `restrict_manage_posts` view.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Select_Field implements Field_Type
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
        return 'select';
    }

    /**
     * Render the select.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $options   = self::options($context);
        $selected  = self::selected_values($context->value());
        $optgroups = self::optgroups($context);

        $html = $this->templates->render('fields/select', array(
            'context'     => $context,
            'name'        => self::select_name($context),
            'attributes'  => Field_Attributes::render($context->attributes()),
            'placeholder' => empty($options) || $context->field('removeEmpty') ? null : self::placeholder($context),
            'entries'     => self::entries($options, $selected, $optgroups, !$context->field('no_style')),
        ));

        $output = new Field_Output($html);
        return self::is_hidden_in_view($context, $options) ? $output->suppressed() : $output;
    }

    /**
     * Whether the select renders nothing: no options in the list-table filter bar.
     *
     * @param Field_Context $context Resolved field state.
     * @param array         $options Options without the `optgroups` key.
     * @return bool
     */
    public static function is_hidden_in_view(Field_Context $context, array $options)
    {
        return empty($options) && 'restrict_manage_posts' === $context->view();
    }

    /**
     * The field's options, without the `optgroups` definition stored alongside them.
     *
     * @param Field_Context $context Resolved field state.
     * @return array<string|int, array> Option value => definition.
     */
    public static function options(Field_Context $context)
    {
        $options = $context->field('options', array());
        if (!is_array($options)) {
            return array();
        }
        unset($options['optgroups']);
        return $options;
    }

    /**
     * Optgroup definitions (`options.optgroups`, or a top-level `optgroups` key).
     *
     * @param Field_Context $context Resolved field state.
     * @return array<string, array> Group id => definition with a `label`.
     */
    public static function optgroups(Field_Context $context)
    {
        $options = $context->field('options', array());
        if (is_array($options) && isset($options['optgroups'])) {
            return (array) $options['optgroups'];
        }
        return (array) $context->field('optgroups', array());
    }

    /**
     * The stored value as a list of selected option values.
     *
     * @param mixed $value Resolved field value.
     * @return array
     */
    public static function selected_values($value)
    {
        if ($value != '' && !is_array($value)) {
            return array($value);
        }
        return is_array($value) ? $value : array();
    }

    /**
     * The `name` attribute: `[]` is appended for multiple selects.
     *
     * @param Field_Context $context Resolved field state.
     * @return string
     */
    public static function select_name(Field_Context $context)
    {
        $attributes = $context->field('attributes', array());
        $multiple   = is_array($attributes) && array_key_exists('multiple', $attributes) && $attributes['multiple'];
        return $context->name() . ($multiple ? '[]' : '');
    }

    /**
     * The empty first option, which keeps browsers from preselecting a real one.
     *
     * @param Field_Context $context Resolved field state.
     * @return array{label: string, data_html: string}
     */
    private static function placeholder(Field_Context $context)
    {
        $label = $context->field('label', '');
        return array(
            'label'     => $label,
            'data_html' => self::data_html($label),
        );
    }

    /**
     * Value of the `data-html` attribute (JSON string of the escaped label, single-quoted).
     *
     * @param string $label Label, may contain HTML.
     * @return string Safe to print inside a double-quoted attribute.
     */
    public static function data_html($label)
    {
        return str_replace('"', "'", wp_json_encode(htmlspecialchars((string) $label)));
    }

    /**
     * Flatten options and optgroups into template entries. Groups come first,
     * then the options that belong to no group.
     *
     * @param array<string|int, array> $options   Options (without `optgroups`).
     * @param array                    $selected  Selected values.
     * @param array<string, array>     $optgroups Group definitions.
     * @param bool                     $styled    Add `data-html` to options (false for `no_style`).
     * @return array<int, array<string, mixed>> Entries with a `kind` of option, group_start or group_end.
     */
    public static function entries(array $options, array $selected, array $optgroups, $styled)
    {
        $loose  = array();
        $groups = array();
        foreach ($options as $value => $definition) {
            $entry = self::option_entry($value, (array) $definition, $selected, $styled);
            $group = isset($definition['optgroup']) ? $definition['optgroup'] : null;
            if (null !== $group && isset($optgroups[$group])) {
                $groups[$group][] = $entry;
                continue;
            }
            $loose[] = $entry;
        }

        $entries = array();
        foreach ($optgroups as $group_id => $group) {
            if (empty($groups[$group_id])) {
                continue;
            }
            $group_label = isset($group['label']) ? $group['label'] : $group_id;
            $entries[]   = array(
                'kind'      => 'group_start',
                'id'        => $group_id,
                'label'     => $group_label,
                'data_html' => self::data_html($group_label),
                'options'   => implode(',', wp_list_pluck($groups[$group_id], 'value')),
            );
            $entries = array_merge($entries, $groups[$group_id], array(array('kind' => 'group_end')));
        }
        return array_merge($entries, $loose);
    }

    /**
     * Template entry of one option.
     *
     * @param string|int $value      Option value.
     * @param array      $definition Option definition (`label`, `extra`, `optgroup`).
     * @param array      $selected   Selected values.
     * @param bool       $styled     Add `data-html`.
     * @return array<string, mixed>
     */
    private static function option_entry($value, array $definition, array $selected, $styled)
    {
        $label = isset($definition['label']) ? $definition['label'] : $value;
        return array(
            'kind'      => 'option',
            'value'     => $value,
            'label'     => $label,
            'selected'  => !empty($selected) && in_array($value, $selected),
            'extra'     => isset($definition['extra']) ? Field_Attributes::render((array) $definition['extra']) : '',
            'data_html' => $styled ? self::data_html($label) : '',
        );
    }
}
