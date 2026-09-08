<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abilities for the UI-configured field libraries (`ewp_fields`).
 *
 * A field library is what the plugin injects into post meta boxes, term and
 * user forms, options pages, the customizer, Gutenberg blocks and custom
 * content forms, so these abilities are how an agent adds custom fields to a
 * site.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Fields_Provider extends EWP_Abilities_Typed_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-fields';

    /**
     * Content type backing this provider.
     *
     * @var string
     */
    const CONTENT_TYPE = 'ewp_fields';

    /**
     * {@inheritDoc}
     */
    public function category()
    {
        return self::CATEGORY;
    }

    /**
     * {@inheritDoc}
     */
    public function category_args()
    {
        return [
            'label'       => __('EWP Custom Fields', 'extend-wp'),
            'description' => __('Create and inspect the custom field libraries that Extend WP injects into post types, taxonomies, users, options pages, the customizer and blocks.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function entities()
    {
        return [
            [
                'content_type'   => self::CONTENT_TYPE,
                'singular'       => 'field-group',
                'plural'         => 'field-groups',
                'label_singular' => __('field group', 'extend-wp'),
                'label_plural'   => __('field groups', 'extend-wp'),
                'writable'       => true,
                'descriptions'   => [
                    'list'   => __('List the custom field groups configured on this site, newest first. Each group holds one or more fields and one or more positions saying where those fields appear. Turn with_meta on to see the field and position definitions themselves.', 'extend-wp'),
                    'get'    => __('Return one field group with its complete field list, positions and usage type. Use this before updating a group so your patch keeps the parts you are not changing.', 'extend-wp'),
                    'create' => __('Create a custom field group. awm_fields lists the fields themselves, each needing a key, a label and a case (the field type). awm_positions says where they appear, each item needing a case such as post_type or options plus that position own settings. Call list-field-vocabulary first to see the valid case values, then flush nothing: the group is live immediately.', 'extend-wp'),
                    'update' => __('Update a field group. This is a patch, but awm_fields and awm_positions are stored whole: send the complete array for either one you change, or the fields you leave out are removed.', 'extend-wp'),
                    'delete' => __('Permanently delete field groups. The fields stop being rendered immediately; values already saved against them stay in the database but become unreachable through the UI. Requires confirm: true.', 'extend-wp'),
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        $definitions = parent::get_definitions();

        $definitions[self::CATEGORY . '/list-field-vocabulary'] = $this->definition(
            __('List field vocabulary', 'extend-wp'),
            __('List every field type (case), input sub-type, position type and usage type this site accepts, each with the extra settings it takes. Call this before creating or updating a field group: it is the only way to know which case values are valid here, since other plugins can add their own.', 'extend-wp'),
            EWP_Abilities_Schema::input([]),
            $this->vocabulary_output_schema(),
            [$this, 'run_list_vocabulary'],
            $this->capability_permission(
                $this->service->get_capability(self::CONTENT_TYPE),
                self::CATEGORY . '/list-field-vocabulary'
            ),
            $this->meta_readonly()
        );

        return $definitions;
    }

    /**
     * Output schema for the vocabulary ability.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function vocabulary_output_schema()
    {
        $group = [
            'type'  => 'array',
            'items' => [
                'type'                 => 'object',
                'additionalProperties' => true,
            ],
        ];

        return [
            'type'       => 'object',
            'properties' => [
                'field_cases'     => $group,
                'input_types'     => $group,
                'position_cases'  => $group,
                'usage_types'     => $group,
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Return the valid field, position and usage vocabulary.
     *
     * @param mixed $input Unused.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public function run_list_vocabulary($input = null)
    {
        return [
            'field_cases'    => $this->flatten_options(function_exists('awmInputFields') ? awmInputFields() : []),
            'input_types'    => $this->flatten_options(function_exists('awmInputFieldsTypes') ? awmInputFieldsTypes() : []),
            'position_cases' => $this->flatten_options(function_exists('awm_position_options') ? awm_position_options() : []),
            'usage_types'    => $this->usage_types(),
        ];
    }

    /**
     * Convert an option map into a list of descriptors.
     *
     * @param array $options Options keyed by value.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function flatten_options($options)
    {
        $flat = [];

        if (!is_array($options)) {
            return $flat;
        }

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
     * Describe the extra settings a case accepts.
     *
     * @param array $choices Field choices library.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function describe_choices(array $choices)
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
                'required' => EWP_Abilities_Schema::is_required($field),
            ];
        }

        return $settings;
    }

    /**
     * Return the usage types with the extra keys each one needs.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function usage_types()
    {
        if (!function_exists('awm_fields_usages')) {
            return [];
        }

        $usages = awm_fields_usages();
        $types  = [];

        if (empty($usages['awm_type']['options'])) {
            return $types;
        }

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
     * Keys that become relevant for one usage type.
     *
     * @param array  $usages The usage library.
     * @param string $value  Usage type value.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function usage_settings(array $usages, $value)
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

    /**
     * Reject field groups that could never render.
     *
     * @param array $entity    Entity descriptor.
     * @param array $meta      Meta payload.
     * @param bool  $is_create Whether this is a create call.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function validate_entity(array $entity, array $meta, $is_create)
    {
        if ($is_create && empty($meta['awm_fields'])) {
            return $this->error(
                'ewp_abilities_fields_required',
                __('awm_fields must contain at least one field, each with a key, a label and a case.', 'extend-wp')
            );
        }

        if (isset($meta['awm_fields'])) {
            $fields_valid = $this->validate_fields((array) $meta['awm_fields']);
            if (is_wp_error($fields_valid)) {
                return $fields_valid;
            }
        }

        if ($is_create && empty($meta['awm_positions'])) {
            return $this->error(
                'ewp_abilities_positions_required',
                __('awm_positions must contain at least one position, otherwise the fields are never rendered.', 'extend-wp')
            );
        }

        if (isset($meta['awm_positions'])) {
            return $this->validate_positions((array) $meta['awm_positions']);
        }

        return true;
    }

    /**
     * Validate each field item against the registered cases.
     *
     * @param array $fields Field items.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function validate_fields(array $fields)
    {
        $cases = function_exists('awmInputFields') ? array_keys(awmInputFields()) : [];

        foreach ($fields as $index => $field) {
            if (!is_array($field) || empty($field['key'])) {
                return $this->error(
                    'ewp_abilities_field_key_required',
                    sprintf(
                        /* translators: %d: item position. */
                        __('Field #%d has no key. Every field needs a unique meta key.', 'extend-wp'),
                        (int) $index + 1
                    )
                );
            }

            if (empty($field['case']) || (!empty($cases) && !in_array($field['case'], $cases, true))) {
                return $this->error(
                    'ewp_abilities_invalid_field_case',
                    sprintf(
                        /* translators: 1: field key, 2: comma separated valid cases. */
                        __('Field "%1$s" has an invalid case. Valid cases: %2$s.', 'extend-wp'),
                        (string) $field['key'],
                        implode(', ', $cases)
                    )
                );
            }
        }

        return true;
    }

    /**
     * Validate each position item against the registered position cases.
     *
     * @param array $positions Position items.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function validate_positions(array $positions)
    {
        $cases = function_exists('awm_position_options') ? array_keys(awm_position_options()) : [];

        foreach ($positions as $index => $position) {
            $case = is_array($position) && !empty($position['case']) ? $position['case'] : '';

            if ($case === '' || (!empty($cases) && !in_array($case, $cases, true))) {
                return $this->error(
                    'ewp_abilities_invalid_position_case',
                    sprintf(
                        /* translators: 1: item position, 2: comma separated valid cases. */
                        __('Position #%1$d has an invalid case. Valid cases: %2$s.', 'extend-wp'),
                        (int) $index + 1,
                        implode(', ', $cases)
                    )
                );
            }
        }

        return true;
    }
}
