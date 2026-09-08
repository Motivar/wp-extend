<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSON schema builders shared by the Extend WP ability providers.
 *
 * Field-bearing abilities derive their schemas from the plugin's own field
 * libraries rather than restating them, so a library change is reflected in
 * the ability schema automatically.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Schema
{
    /**
     * Field cases that carry no stored value.
     *
     * @var string[]
     */
    const PRESENTATION_CASES = ['html', 'message', 'button', 'function', 'awm_tab'];

    /**
     * Hard ceiling on rows returned by a list ability.
     *
     * @var int
     */
    const MAX_LIMIT = 200;

    /**
     * Default number of rows returned by a list ability.
     *
     * @var int
     */
    const DEFAULT_LIMIT = 50;

    /**
     * Schema describing one normalised content row.
     *
     * Meta stays open because every content type stores a different shape and
     * core validates output strictly.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public static function row()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'           => ['type' => 'integer'],
                'content_type' => ['type' => 'string'],
                'title'        => ['type' => ['string', 'null']],
                'status'       => ['type' => ['string', 'null']],
                'created'      => ['type' => ['string', 'null']],
                'modified'     => ['type' => ['string', 'null']],
                'user_id'      => ['type' => ['integer', 'null']],
                'hash'         => ['type' => ['string', 'null']],
                'meta'         => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Schema for a list of rows plus its count.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public static function row_collection()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'items' => ['type' => 'array', 'items' => self::row()],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Shared input properties for a list ability.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public static function list_properties()
    {
        return [
            'status'    => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => __('Restrict to these statuses. Omit for every status.', 'extend-wp'),
            ],
            'search'    => [
                'type'        => 'string',
                'description' => __('Match against the item title.', 'extend-wp'),
            ],
            'include'   => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => __('Return only these item ids.', 'extend-wp'),
            ],
            'limit'     => [
                'type'        => 'integer',
                'minimum'     => 1,
                'maximum'     => self::MAX_LIMIT,
                'description' => sprintf(
                    /* translators: 1: default limit, 2: maximum limit. */
                    __('How many items to return. Defaults to %1$d, maximum %2$d.', 'extend-wp'),
                    self::DEFAULT_LIMIT,
                    self::MAX_LIMIT
                ),
            ],
            'order_by'  => [
                'type'       => 'object',
                'properties' => [
                    'column' => ['type' => 'string'],
                    'type'   => ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']],
                ],
                'additionalProperties' => false,
            ],
            'with_meta' => [
                'type'        => 'boolean',
                'description' => __('Include every meta value for each item. Heavier; omit when you only need titles and ids.', 'extend-wp'),
            ],
        ];
    }

    /**
     * Build an object input schema.
     *
     * @param array $properties Property definitions.
     * @param array $required   Required property names.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public static function input(array $properties, array $required = [])
    {
        $schema = [
            'type'       => 'object',
            'default'    => [],
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = array_values($required);
        }

        return $schema;
    }

    /**
     * Convert an Extend WP field library into JSON schema properties.
     *
     * Unknown or dynamic cases fall back to an open schema so a value the UI
     * writes is never rejected by the ability layer.
     *
     * @param array $library Field library keyed by meta key.
     *
     * @return array Schema properties keyed by meta key.
     *
     * @since 1.4.0
     */
    public static function library_to_properties(array $library)
    {
        $properties = [];

        foreach ($library as $key => $field) {
            if (!is_array($field) || !empty($field['exclude_meta'])) {
                continue;
            }

            $case = isset($field['case']) ? $field['case'] : '';
            if (in_array($case, self::PRESENTATION_CASES, true)) {
                continue;
            }

            $properties[$key] = self::field_to_schema($field);
        }

        return $properties;
    }

    /**
     * Convert one field definition into a schema fragment.
     *
     * @param array $field Field definition.
     *
     * @return array
     *
     * @since 1.4.0
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
                    'properties'           => self::library_to_properties($include),
                    'additionalProperties' => true,
                ],
            ]);
        }

        if (self::is_multiple($field)) {
            return array_merge($schema, [
                'type'  => 'array',
                'items' => ['type' => ['string', 'integer']],
            ]);
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
     * Whether a field stores multiple values.
     *
     * @param array $field Field definition.
     *
     * @return bool
     *
     * @since 1.4.0
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
     * Build a human readable description for a field.
     *
     * @param array $field Field definition.
     *
     * @return string
     *
     * @since 1.4.0
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

    /**
     * Whether a field library entry is flagged required in the admin UI.
     *
     * @param array $field Field definition.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    public static function is_required(array $field)
    {
        /*
         * A field that is only shown for certain values of another field
         * cannot be unconditionally required, so it is reported as optional.
         */
        if (!empty($field['show-when'])) {
            return false;
        }

        if (!empty($field['required'])) {
            return true;
        }

        if (empty($field['label_class']) || !is_array($field['label_class'])) {
            return false;
        }

        return in_array('awm-needed', $field['label_class'], true);
    }
}
