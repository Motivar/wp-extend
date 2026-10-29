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
 * Case `object_id_filter`: a type selector (post type / taxonomy / custom
 * content) plus a remote-search multi-select of object ids. Stores two flat
 * meta keys — the type (`{field}`) and the ids (`{field}_ids` by default) —
 * so it emits its own pair of `awm_custom_meta[]` save markers instead of the
 * automatic one. Markup: templates/fields/object-id-filter.php.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Object_Id_Filter_Field implements Field_Type
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
        return 'object_id_filter';
    }

    /**
     * Render the picker, preloading saved ids server-side.
     *
     * @param Field_Context $context Resolved field state.
     * @return Field_Output
     */
    public function render(Field_Context $context)
    {
        $config       = awm_object_id_filter_config($context->field(), $context->name());
        $id_field     = $config['id_field_name'];
        $type_options = awm_get_object_type_options($config, $context->name());
        $optgroups    = isset($type_options['optgroups']) ? (array) $type_options['optgroups'] : array();
        unset($type_options['optgroups']);

        $saved_type = $context->value();
        if (is_array($saved_type)) {
            $saved_type = reset($saved_type);
        }
        $saved_ids  = awm_get_field_value($context->view(), $context->field(), $context->object_id(), $id_field);
        $saved_ids  = !empty($saved_ids) ? array_values(array_filter(array_map('absint', (array) $saved_ids))) : array();

        $html = $this->templates->render('fields/object-id-filter', array(
            'context'      => $context,
            'label'        => $context->field('label', ltrim($context->name(), '_')),
            'js_config'    => str_replace('"', '\'', wp_json_encode(self::js_config($context, $config))),
            'type_groups'  => self::type_groups($optgroups, $type_options, $saved_type),
            'id_field'     => $id_field,
            'min_chars'    => (int) $config['min_search_chars'],
            'disabled'     => empty($saved_type),
            'saved_ids'    => self::preloaded_options($saved_type, $saved_ids),
            'save_markers' => 'none' !== $context->view() ? array($context->name(), $id_field) : array(),
        ));
        return (new Field_Output($html))->without_meta_marker();
    }

    /**
     * Per-instance config for the JS module.
     *
     * @param Field_Context $context Resolved field state.
     * @param array         $config  awm_object_id_filter_config() result.
     * @return array
     */
    public static function js_config(Field_Context $context, array $config)
    {
        return array(
            'restUrl'        => esc_url_raw(rest_url('extend-wp/v1')),
            'minSearchChars' => $config['min_search_chars'],
            'maxResults'     => $config['max_results'],
            'searchMeta'     => $config['search_meta'],
            'typeFieldName'  => $context->name(),
            'idFieldName'    => $config['id_field_name'],
        );
    }

    /**
     * Type options grouped by optgroup, with the saved type selected.
     *
     * @param array  $optgroups    Group id => `label`.
     * @param array  $type_options Option key (`group:slug`) => `label`, `optgroup`.
     * @param mixed  $saved_type   Saved type key.
     * @return array Per group: `label`, `options` (each `value`, `label`, `selected`).
     */
    public static function type_groups(array $optgroups, array $type_options, $saved_type)
    {
        $groups = array();
        foreach ($optgroups as $group_id => $group) {
            $options = array();
            foreach ($type_options as $key => $option) {
                if (!isset($option['optgroup']) || $option['optgroup'] !== $group_id) {
                    continue;
                }
                $options[] = array(
                    'value'    => $key,
                    'label'    => isset($option['label']) ? $option['label'] : $key,
                    'selected' => '' !== $saved_type && $saved_type === $key,
                );
            }
            $groups[] = array('label' => $group['label'], 'options' => $options);
        }
        return $groups;
    }

    /**
     * Saved ids with their labels, in the saved order (`#{id}` when the
     * object no longer resolves).
     *
     * @param mixed $saved_type Saved type key (`group:slug`).
     * @param int[] $saved_ids  Saved ids.
     * @return array Each `id`, `label`.
     */
    public static function preloaded_options($saved_type, array $saved_ids)
    {
        if (empty($saved_type) || empty($saved_ids)) {
            return array();
        }
        $parts = explode(':', (string) $saved_type, 2);
        if (count($parts) !== 2) {
            return array();
        }
        $labels = array();
        foreach (awm_object_search_query($parts[0], $parts[1], '', array('include' => $saved_ids, 'limit' => count($saved_ids))) as $row) {
            $labels[$row['id']] = $row['label'];
        }
        $options = array();
        foreach ($saved_ids as $id) {
            /* translators: %d: object id */
            $options[] = array('id' => $id, 'label' => isset($labels[$id]) ? $labels[$id] : sprintf(__('#%d', 'extend-wp'), $id));
        }
        return $options;
    }
}
