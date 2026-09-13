<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The vocabulary a field group may use: field cases, input sub-types,
 * position cases and usage types, each with the settings it takes.
 *
 * Backs `ewp-fields/list-field-vocabulary`.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Field_Vocabulary
{
    /**
     * @return array field_cases, input_types, position_cases, usage_types
     *
     * @since 1.5.0
     */
    public function describe()
    {
        return [
            'field_cases'    => $this->flatten_options(function_exists('awmInputFields') ? awmInputFields() : []),
            'input_types'    => $this->flatten_options(function_exists('awmInputFieldsTypes') ? awmInputFieldsTypes() : []),
            'position_cases' => $this->flatten_options(function_exists('awm_position_options') ? awm_position_options() : []),
            'usage_types'    => $this->usage_types(),
        ];
    }

    /**
     * Output schema of describe().
     *
     * @return array
     *
     * @since 1.5.0
     */
    public static function schema()
    {
        $group = ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]];

        return [
            'type'       => 'object',
            'properties' => [
                'field_cases'    => $group,
                'input_types'    => $group,
                'position_cases' => $group,
                'usage_types'    => $group,
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * @param mixed $options Options keyed by value.
     *
     * @return array
     */
    private function flatten_options($options)
    {
        if (!is_array($options)) {
            return [];
        }

        $flat = [];
        foreach ($options as $key => $option) {
            $entry = [
                'value' => (string) $key,
                'label' => is_array($option) && isset($option['label']) ? (string) $option['label'] : (string) $key,
            ];

            if (is_array($option) && !empty($option['field-choices']) && is_array($option['field-choices'])) {
                $entry['settings'] = $this->describe_choices($option['field-choices']);
            }

            $flat[] = $entry;
        }

        return $flat;
    }

    /**
     * @param array $choices Field choices library.
     *
     * @return array
     */
    private function describe_choices(array $choices)
    {
        $settings = [];

        foreach ($choices as $key => $field) {
            if (!is_array($field) || !empty($field['exclude_meta'])) {
                continue;
            }

            $settings[] = [
                'key'      => (string) $key,
                'label'    => isset($field['label']) ? (string) $field['label'] : (string) $key,
                'case'     => isset($field['case']) ? (string) $field['case'] : '',
                'required' => Content_Service::is_required($field),
            ];
        }

        return $settings;
    }

    /**
     * @return array
     */
    private function usage_types()
    {
        if (!function_exists('awm_fields_usages')) {
            return [];
        }

        $usages = awm_fields_usages();
        if (empty($usages['awm_type']['options'])) {
            return [];
        }

        $types = [];
        foreach ($usages['awm_type']['options'] as $value => $option) {
            $types[] = [
                'value'    => (string) $value,
                'label'    => isset($option['label']) ? (string) $option['label'] : (string) $value,
                'settings' => $this->usage_settings($usages, $value),
            ];
        }

        return $types;
    }

    /**
     * @param array  $usages The usage library.
     * @param string $value  Usage type value.
     *
     * @return array
     */
    private function usage_settings(array $usages, $value)
    {
        $settings = [];

        foreach ($usages as $key => $field) {
            if ($key === 'awm_type' || !is_array($field) || empty($field['show-when']['awm_type']['values'])) {
                continue;
            }

            if (!array_key_exists($value, $field['show-when']['awm_type']['values'])) {
                continue;
            }

            $settings[] = [
                'key'      => (string) $key,
                'label'    => isset($field['label']) ? (string) $field['label'] : (string) $key,
                'required' => !empty($field['label_class']) && in_array('awm-needed', (array) $field['label_class'], true),
            ];
        }

        return $settings;
    }
}
