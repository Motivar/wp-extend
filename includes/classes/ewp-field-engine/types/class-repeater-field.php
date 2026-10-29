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
 * Case `repeater`: rows of nested fields stored as a list
 * (`{meta}[{row}][{key}]`), plus a disabled `template` row the admin script
 * clones to add rows.
 *
 * Markup: templates/fields/repeater.php (container), repeater-row.php (one
 * row) and repeater-actions.php (row buttons, filtered by
 * `awm_show_actions_html`). awm_repeater_content() wraps row().
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Repeater_Field implements Field_Type
{
    /** @var string Counter of the clone-source row. */
    const TEMPLATE_ROW = 'template';

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
        return 'repeater';
    }

    /**
     * Render the repeater; nothing (inside the wrapper) without `include`.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $field = $context->field();
        if (empty($field['include'])) {
            return new Field_Output('');
        }

        $field_count      = count($field['include']);
        $rows             = self::stored_rows($context);
        $field['include'] = self::with_row_key($field['include']);

        $html = '';
        $count = !empty($rows) ? count($rows) : absint($context->field('minrows', 0));
        for ($i = 0; $i < $count; ++$i) {
            $html .= $this->row($i, $context->name(), $field, $context->id(), $rows);
        }
        if (null === $context->field('no_template')) {
            $html .= $this->row(self::TEMPLATE_ROW, $context->name(), $field, $context->id(), array());
        }

        return new Field_Output($this->templates->render('fields/repeater', array(
            'context'     => $context,
            'field_count' => $field_count,
            'max_rows'    => null !== $context->field('maxrows') ? absint($context->field('maxrows')) : '',
            'rows'        => $html,
        )));
    }

    /**
     * Stored rows as a list, or `prePopulated` when nothing is stored.
     *
     * @param Field_Context $context Resolved field state.
     * @return array
     */
    public static function stored_rows(Field_Context $context)
    {
        $rows = $context->value() ? array_values(awm_safe_unserialize_array($context->value())) : array();
        if (empty($rows) && null !== $context->field('prePopulated')) {
            return (array) $context->field('prePopulated');
        }
        return $rows;
    }

    /**
     * Add the hidden `awm_key` input every row carries (unique row id set by the admin script).
     *
     * @param array $include Row fields.
     * @return array
     */
    public static function with_row_key(array $include)
    {
        $include['awm_key'] = array(
            'case'       => 'input',
            'type'       => 'hidden',
            'attributes' => array('data-unique' => true),
        );
        return $include;
    }

    /**
     * Render one row.
     *
     * @param int|string $i                Row index, or `template` for the clone-source row.
     * @param string     $original_meta    Input name of the repeater.
     * @param array      $field            Repeater definition (`include` already has `awm_key`).
     * @param string     $original_meta_id Repeater element id.
     * @param array      $rows             Stored rows.
     * @return string Row HTML.
     */
    public function row($i, $original_meta, array $field, $original_meta_id, $rows)
    {
        $is_template = self::is_template_row($i);
        $actions     = null;
        if (!isset($field['hide_buttons'])) {
            $actions = $this->actions($i, $original_meta, $field, $original_meta_id, $rows, $is_template);
        }

        return $this->templates->render('fields/repeater-row', array(
            'dom_id'      => 'awm-' . str_replace('[', '_', str_replace(']', '', str_replace('][', '_', $original_meta))) . '-' . $i,
            'counter'     => $i,
            'data_id'     => $original_meta_id,
            'is_template' => $is_template,
            'title'       => isset($field['row_title']) && is_int($i) ? sprintf($field['row_title'], $i + 1) : '',
            'inputs'      => awm_show_content(self::row_fields($i, $original_meta, $field, $rows)),
            'actions'     => $actions,
        ));
    }

    /**
     * Nested library of one row: names `{meta}[{row}][{key}]`, ids
     * `{meta}_{row}_{key}`, the repeater's attributes merged in, template
     * rows disabled.
     *
     * @param int|string $i             Row index or `template`.
     * @param string     $original_meta Input name of the repeater.
     * @param array      $field         Repeater definition.
     * @param array      $rows          Stored rows.
     * @return array Field library for awm_show_content().
     */
    public static function row_fields($i, $original_meta, array $field, $rows)
    {
        $is_template = self::is_template_row($i);
        $id_prefix   = str_replace(']', '_', str_replace('[', '_', $original_meta)) . '_' . $i . '_';
        $shared      = isset($field['attributes']) ? (array) $field['attributes'] : array();
        $fields      = array();
        foreach ($field['include'] as $key => $data) {
            $data['attributes'] = (isset($data['attributes']) ? $data['attributes'] : array()) + $shared;
            if (isset($rows[$i][$key])) {
                $data['attributes']['value'] = awm_repeater_check_quotes($rows[$i][$key]);
            }
            $data['attributes']['exclude_meta'] = true;
            $data['attributes']['id']           = $id_prefix . $key;
            $data['attributes']['input-name']   = $original_meta;
            $data['attributes']['input-key']    = $key;
            if ($is_template) {
                $data['attributes']['disabled']     = true;
                $data['attributes']['awm-template'] = true;
                $data['attributes']['readonly']     = true;
                $data['disabled']                   = true;
            }
            $fields[$original_meta . '[' . $i . '][' . $key . ']'] = $data;
        }
        return $fields;
    }

    /**
     * Row buttons (clone, move, remove; add on the template row), filtered.
     *
     * @param int|string $i                Row index or `template`.
     * @param string     $original_meta    Input name of the repeater.
     * @param array      $field            Repeater definition.
     * @param string     $original_meta_id Repeater element id.
     * @param array      $rows             Stored rows.
     * @param bool       $is_template      Whether this is the clone-source row.
     * @return string Actions HTML (inner markup of `div.awm-actions`).
     */
    private function actions($i, $original_meta, array $field, $original_meta_id, $rows, $is_template)
    {
        $html = $this->templates->render('fields/repeater-actions', array(
            'item'        => isset($field['item_name']) ? $field['item_name'] : __('Row', 'extend-wp'),
            'is_template' => $is_template,
            'data_id'     => $original_meta_id,
        ));

        /**
         * Filter the action buttons of a repeater row.
         *
         * @since 1.0.0
         *
         * @param string     $html             Buttons HTML (clone, move up/down, remove; add on the template row).
         * @param int|string $i                Row index, or `template`.
         * @param string     $original_meta    Input name of the repeater.
         * @param array      $field            Repeater definition.
         * @param string     $original_meta_id Repeater element id.
         * @param array      $rows             Stored rows.
         */
        return (string) apply_filters('awm_show_actions_html', $html, $i, $original_meta, $field, $original_meta_id, $rows);
    }

    /**
     * Whether a row counter is the clone-source row.
     *
     * @param int|string $i Row index or `template`.
     * @return bool
     */
    private static function is_template_row($i)
    {
        return !is_int($i) && self::TEMPLATE_ROW === $i;
    }
}
