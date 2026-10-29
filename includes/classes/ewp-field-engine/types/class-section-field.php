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
 * Case `section`: a header plus nested fields stored as one array value
 * (`{meta}[{key}]`), or as separate keys with `keep_inputs`.
 *
 * Nested fields render through awm_show_content(), so every case, filter
 * and wrapper applies to them. Markup: templates/fields/section.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Section_Field implements Field_Type
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
        return 'section';
    }

    /**
     * Render the section and its nested fields.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $html = $this->templates->render('fields/section', array(
            'context' => $context,
            'inner'   => awm_show_content(self::nested_fields($context)),
        ));
        return (new Field_Output($html))->with_wrapper_classes(array('awm-section-field'));
    }

    /**
     * The nested field library: input names, ids and stored values per key.
     *
     * Without `keep_inputs` each nested field is posted as `{dom id}[{key}]`
     * and gets its value from the section's stored array; with it, the
     * nested field keeps its own key and reads its own value.
     *
     * @param Field_Context $context Resolved field state.
     * @return array Field library for awm_show_content().
     */
    public static function nested_fields(Field_Context $context)
    {
        $keep_inputs = null !== $context->field('keep_inputs');
        $stored      = $context->value() ? awm_safe_unserialize_array($context->value()) : array();
        $fields      = array();
        foreach ((array) $context->field('include', array()) as $key => $data) {
            $data['attributes']['id']           = $keep_inputs ? $context->id() . '_' . $key : $key;
            $data['attributes']['exclude_meta'] = true;
            if (!$keep_inputs && isset($stored[$key])) {
                $data['attributes']['value'] = $stored[$key];
            }
            $fields[$keep_inputs ? $key : $context->id() . '[' . $key . ']'] = $data;
            self::register_field_config($key, $data);
        }
        return $fields;
    }

    /**
     * Record a nested field's config in the request-wide registry the
     * encryption save path reads (global `$awm_field_configs`), so masking
     * works for fields inside sections.
     *
     * @param string $key  Nested field key.
     * @param array  $data Nested field definition.
     * @return void
     */
    private static function register_field_config($key, array $data)
    {
        $GLOBALS['awm_field_configs'][$key] = $data;
    }
}
