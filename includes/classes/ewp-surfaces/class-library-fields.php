<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;
use Gnnpls\WP\Field;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns an Extend WP field library into kit Fields.
 *
 * Every field becomes a `Field::custom()` carrying the JSON Schema the
 * typed abilities have always advertised, so schemas are derived from the
 * libraries and never restated. Unknown or dynamic cases fall back to an
 * open schema so a value the UI writes is never rejected.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Library_Fields
{
    /**
     * @param array $library       Field library keyed by meta key.
     * @param bool  $mark_required Whether UI-required fields become required inputs (create).
     *
     * @return Field[]
     *
     * @since 1.5.0
     */
    public static function from_library(array $library, $mark_required = false)
    {
        $fields = [];

        foreach (self::to_properties($library) as $key => $schema) {
            $field = Field::custom($key, $schema);
            if ($mark_required && isset($library[$key]) && is_array($library[$key]) && Content_Service::is_required($library[$key])) {
                $field = $field->required();
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * JSON Schema properties for a library, keyed by meta key.
     *
     * @param array $library Field library keyed by meta key.
     *
     * @return array<string,array>
     *
     * @since 1.5.0
     */
    public static function to_properties(array $library)
    {
        $properties = [];

        foreach ($library as $key => $field) {
            if (!is_array($field) || !empty($field['exclude_meta'])) {
                continue;
            }

            $case = isset($field['case']) ? $field['case'] : '';
            if (in_array($case, Content_Service::PRESENTATION_CASES, true)) {
                continue;
            }

            $properties[$key] = self::field_to_schema($field);
        }

        return $properties;
    }

    /**
     * @param array $field Field definition.
     *
     * @return array
     */
    private static function field_to_schema(array $field)
    {
        $case   = isset($field['case']) ? $field['case'] : '';
        $schema = ['description' => self::field_description($field)];

        if ($case === 'repeater') {
            $include = isset($field['include']) && is_array($field['include']) ? $field['include'] : [];

            return array_merge($schema, [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => self::to_properties($include),
                    'additionalProperties' => true,
                ],
            ]);
        }

        if (self::is_multiple($field)) {
            return array_merge($schema, ['type' => 'array', 'items' => ['type' => ['string', 'integer']]]);
        }

        if ($case === 'input' && isset($field['type']) && $field['type'] === 'checkbox') {
            return array_merge($schema, ['type' => ['string', 'boolean', 'integer', 'null']]);
        }

        if ($case === 'input' && isset($field['type']) && $field['type'] === 'number') {
            return array_merge($schema, ['type' => ['number', 'string', 'null']]);
        }

        if (in_array($case, ['section', 'awm_modal', 'map'], true)) {
            return array_merge($schema, ['type' => ['object', 'array', 'null'], 'additionalProperties' => true]);
        }

        return array_merge($schema, ['type' => ['string', 'integer', 'number', 'boolean', 'null']]);
    }

    /**
     * @param array $field Field definition.
     *
     * @return bool
     */
    private static function is_multiple(array $field)
    {
        $case = isset($field['case']) ? $field['case'] : '';

        if (in_array($case, ['checkbox_multiple', 'awm_gallery'], true)) {
            return true;
        }

        return !empty($field['attributes']['multiple']);
    }

    /**
     * @param array $field Field definition.
     *
     * @return string
     */
    private static function field_description(array $field)
    {
        $parts = [];

        if (!empty($field['label'])) {
            $parts[] = (string) $field['label'];
        }

        if (!empty($field['explanation'])) {
            $parts[] = (string) $field['explanation'];
        }

        if (!empty($field['case'])) {
            $parts[] = sprintf(
                /* translators: %s: field type. */
                __('Field type: %s.', 'extend-wp'),
                $field['case'] . (isset($field['type']) ? '/' . $field['type'] : '')
            );
        }

        if (!empty($field['options']) && is_array($field['options'])) {
            $keys = array_slice(array_keys($field['options']), 0, 20);
            if (!empty($keys)) {
                $parts[] = sprintf(
                    /* translators: %s: comma separated list of allowed values. */
                    __('Allowed values: %s.', 'extend-wp'),
                    implode(', ', array_map('strval', $keys))
                );
            }
        }

        return implode(' ', $parts);
    }
}
