<?php

namespace EWP\Surfaces\Resources;

use EWP\Surfaces\Field_Vocabulary;
use Motivar\WP\Context;
use Motivar\WP\Operation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The UI-configured field libraries (`ewp_fields`) as `ewp-fields/*`.
 *
 * A field group is what the plugin injects into post meta boxes, term and
 * user forms, options pages, the customizer, Gutenberg blocks and custom
 * content forms, so these abilities are how an agent adds custom fields.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Fields_Resource extends Typed_Content_Resource
{
    const CONTENT_TYPE = 'ewp_fields';

    public function name()
    {
        return 'fields';
    }

    public function ability_category()
    {
        return 'ewp-fields';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Custom Fields', 'extend-wp'),
            'description' => __('Create and inspect the custom field libraries that Extend WP injects into post types, taxonomies, users, options pages, the customizer and blocks.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        return $this->service->get_capability(self::CONTENT_TYPE);
    }

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

    public function operations()
    {
        $operations = parent::operations();

        $operations['list-field-vocabulary'] = Operation::read('describe')
            ->on(new Field_Vocabulary())
            ->label(__('List field vocabulary', 'extend-wp'))
            ->description(__('List every field type (case), input sub-type, position type and usage type this site accepts, each with the extra settings it takes. Call this before creating or updating a field group: it is the only way to know which case values are valid here, since other plugins can add their own.', 'extend-wp'))
            ->output(Field_Vocabulary::schema())
            ->surfaces([Context::ABILITY], __('vocabulary is an ability-only helper for agents', 'extend-wp'))
            ->ability('list-field-vocabulary');

        return $operations;
    }

    /**
     * Reject field groups that could never render.
     *
     * {@inheritDoc}
     */
    protected function validate_entity(array $entity, array $meta, $is_create)
    {
        if ($is_create && empty($meta['awm_fields'])) {
            return $this->error('ewp_abilities_fields_required', __('awm_fields must contain at least one field, each with a key, a label and a case.', 'extend-wp'));
        }

        if (isset($meta['awm_fields'])) {
            $fields_valid = $this->validate_fields((array) $meta['awm_fields']);
            if ($fields_valid instanceof \WP_Error) {
                return $fields_valid;
            }
        }

        if ($is_create && empty($meta['awm_positions'])) {
            return $this->error('ewp_abilities_positions_required', __('awm_positions must contain at least one position, otherwise the fields are never rendered.', 'extend-wp'));
        }

        if (isset($meta['awm_positions'])) {
            return $this->validate_positions((array) $meta['awm_positions']);
        }

        return true;
    }

    /**
     * @param array $fields Field items.
     *
     * @return true|\WP_Error
     */
    private function validate_fields(array $fields)
    {
        $cases = function_exists('awmInputFields') ? array_keys(awmInputFields()) : [];

        foreach ($fields as $index => $field) {
            if (!is_array($field) || empty($field['key'])) {
                return $this->error('ewp_abilities_field_key_required', sprintf(
                    /* translators: %d: item position. */
                    __('Field #%d has no key. Every field needs a unique meta key.', 'extend-wp'),
                    (int) $index + 1
                ));
            }

            if (empty($field['case']) || ($cases !== [] && !in_array($field['case'], $cases, true))) {
                return $this->error('ewp_abilities_invalid_field_case', sprintf(
                    /* translators: 1: field key, 2: comma separated valid cases. */
                    __('Field "%1$s" has an invalid case. Valid cases: %2$s.', 'extend-wp'),
                    (string) $field['key'],
                    implode(', ', $cases)
                ));
            }
        }

        return true;
    }

    /**
     * @param array $positions Position items.
     *
     * @return true|\WP_Error
     */
    private function validate_positions(array $positions)
    {
        $cases = function_exists('awm_position_options') ? array_keys(awm_position_options()) : [];

        foreach ($positions as $index => $position) {
            $case = is_array($position) && !empty($position['case']) ? $position['case'] : '';

            if ($case === '' || ($cases !== [] && !in_array($case, $cases, true))) {
                return $this->error('ewp_abilities_invalid_position_case', sprintf(
                    /* translators: 1: item position, 2: comma separated valid cases. */
                    __('Position #%1$d has an invalid case. Valid cases: %2$s.', 'extend-wp'),
                    (int) $index + 1,
                    implode(', ', $cases)
                ));
            }
        }

        return true;
    }
}
