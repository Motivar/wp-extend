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
 * Case `awm_tab`: tab links plus one panel of nested fields per tab, all
 * stored in one array value (`{meta}[{tab}][{key}]`).
 *
 * A tab lists its fields in `include`, or returns them from `callback`
 * (called with `callback_variables` when given). Markup:
 * templates/fields/tabs.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Tab_Field implements Field_Type
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
        return 'awm_tab';
    }

    /**
     * Render the tab set; nothing (inside the wrapper) when no tabs are defined.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $tabs = $context->field('awm_tabs');
        if (empty($tabs) || !is_array($tabs)) {
            return new Field_Output('');
        }

        $stored = is_array($context->value()) ? $context->value() : array();
        $panels = array();
        foreach ($tabs as $tab_id => $tab) {
            $panels[] = array(
                'id'    => $tab_id,
                'label' => isset($tab['label']) ? $tab['label'] : $tab_id,
                'inner' => awm_show_content(self::tab_fields($context->name(), $tab_id, self::tab_include($tab), $stored)),
            );
        }

        return new Field_Output($this->templates->render('fields/tabs', array(
            'context' => $context,
            'panels'  => $panels,
        )));
    }

    /**
     * Fields of one tab: its `include`, or what its `callback` returns.
     *
     * @param array $tab Tab definition (`label`, `include` | `callback` [+ `callback_variables`]).
     * @return array Field library.
     */
    public static function tab_include(array $tab)
    {
        if (!isset($tab['callback'])) {
            return isset($tab['include']) ? (array) $tab['include'] : array();
        }
        $fields = array();
        if (!empty($tab['callback_variables'])) {
            $fields = call_user_func_array($tab['callback'], (array) $tab['callback_variables']);
        }
        return (array) (empty($fields) ? call_user_func($tab['callback']) : $fields);
    }

    /**
     * Nested library of one tab: names `{meta}[{tab}][{key}]`, ids
     * `{meta}_{tab}_{key}`, values from the stored array.
     *
     * @param string $name    Field meta key.
     * @param string $tab_id  Tab key.
     * @param array  $include Tab fields.
     * @param array  $stored  Stored value of the whole field.
     * @return array Field library for awm_show_content().
     */
    public static function tab_fields($name, $tab_id, array $include, array $stored)
    {
        $fields = array();
        foreach ($include as $key => $data) {
            $data['attributes']['id'] = $name . '_' . $tab_id . '_' . $key;
            if (isset($stored[$tab_id][$key])) {
                $data['attributes']['value'] = $stored[$tab_id][$key];
            }
            $data['attributes']['exclude_meta'] = true;
            $fields[$name . '[' . $tab_id . '][' . $key . ']'] = $data;
        }
        return $fields;
    }
}
