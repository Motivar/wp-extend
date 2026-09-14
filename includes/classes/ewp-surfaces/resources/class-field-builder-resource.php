<?php

namespace EWP\Surfaces\Resources;

use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The wp-admin field-builder and modal-field helpers as a REST-only
 * resource over AWM_API: settings markup for a field case, query type or
 * position, the PHP export of a field group, the Google Maps options of
 * the map field, and the modal field form (render + save).
 *
 * They return HTML for the admin scripts, which is why no command or
 * ability is generated; declaring them here still gives them typed and
 * described arguments, the shared authorization path and a place in the
 * surfaces inventory. Route paths and parameter names are unchanged.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Field_Builder_Resource extends Resource
{
    /** @var \AWM_API */
    private $service;

    /**
     * @param \AWM_API $service Field-builder service.
     */
    public function __construct(\AWM_API $service)
    {
        $this->service = $service;
    }

    /** {@inheritDoc} */
    public function name()
    {
        return 'field-builder';
    }

    /** {@inheritDoc} */
    public function label()
    {
        return __('Field builder helpers', 'extend-wp');
    }

    /** {@inheritDoc} */
    public function service()
    {
        return $this->service;
    }

    /** {@inheritDoc} */
    public function rest_namespace()
    {
        return 'extend-wp/v1';
    }

    /** The helpers live directly under the namespace, as they always did. @return string */
    public function rest_base()
    {
        return '';
    }

    /**
     * Everything here renders markup for the wp-admin scripts.
     *
     * @return string[]
     */
    public function surfaces()
    {
        return [Context::REST];
    }

    /**
     * @return string
     */
    public function surfaces_reason()
    {
        return __('renders admin form markup for the field builder and modal scripts', 'extend-wp');
    }

    /** Field groups are edited by anyone who can edit posts. @return string */
    public function capability()
    {
        return 'edit_posts';
    }

    /** {@inheritDoc} */
    public function operations()
    {
        $html  = ['type' => 'string'];
        $name  = Field::string('name')->default_value('')->describe(__('Input name of the row being configured; the markup nests its inputs under it.', 'extend-wp'));
        $id    = Field::int('id')->default_value(0)->describe(__('Row id whose stored values pre-fill the markup; 0 for a new row.', 'extend-wp'));
        $field = Field::string('field')->default_value('')->describe(__('Selected type; empty returns an empty string.', 'extend-wp'));
        $meta  = Field::string('meta')->default_value('awm_fields')->describe(__('Meta key holding the rows: awm_fields, or query_fields for search filters.', 'extend-wp'));

        return [
            'case-fields' => Operation::read('case_fields')
                ->label(__('Field case settings', 'extend-wp'))
                ->description(__('Settings markup for one field case (input type) of a field group, pre-filled from the stored row.', 'extend-wp'))
                ->input([$field, $name, $meta, $id])
                ->output($html)
                ->rest('GET', 'get-case-fields'),

            'query-fields' => Operation::read('query_fields')
                ->label(__('Query type settings', 'extend-wp'))
                ->description(__('Settings markup for one query type of a search filter, pre-filled from the stored row.', 'extend-wp'))
                ->input([$field, $name, $meta->default_value('query_fields'), $id])
                ->output($html)
                ->rest('GET', 'get-query-fields'),

            'position-fields' => Operation::read('position_fields')
                ->label(__('Position settings', 'extend-wp'))
                ->description(__('Settings markup for one position type (post type, taxonomy, options page, …) of a field group.', 'extend-wp'))
                ->input([
                    Field::string('position')->default_value('')->describe(__('Selected position type; empty returns an empty string.', 'extend-wp')),
                    $name,
                    $id,
                ])
                ->output($html)
                ->rest('GET', 'get-position-fields'),

            'php-code' => Operation::read('php_code')
                ->label(__('PHP export of a field group', 'extend-wp'))
                ->description(__('Highlighted PHP snippet that registers a UI-built field group through the matching awm_add_*_filter hook.', 'extend-wp'))
                ->input([Field::int('awm_post_id')->required()->describe(__('ewp_fields row id.', 'extend-wp'))])
                ->args(function (array $input) {
                    return [$input['awm_post_id']];
                })
                ->output($html)
                ->rest('GET', 'get-php-code'),

            'map-options' => Operation::read('map_options')
                ->label(__('Map field options', 'extend-wp'))
                ->description(__('Google Maps browser key, default centre and map options for the admin map field (filter awm_map_options_func_filter).', 'extend-wp'))
                ->capability([$this->service, 'map_options_capability'])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('GET', 'awm-map-options'),

            'modal-fields' => Operation::read('modal_fields')
                ->label(__('Modal field form', 'extend-wp'))
                ->description(__('Rendered modal and field markup for a modal-type field, with its stored values.', 'extend-wp'))
                ->input([
                    Field::string('meta_key')->required()->describe(__('Meta key of the modal field.', 'extend-wp')),
                    Field::enum('view', ['post', 'term', 'user', 'option', 'content_meta'])->default_value('post')->describe(__('Where the field lives.', 'extend-wp')),
                    Field::int('object_id')->default_value(0)->describe(__('Post, term, user or content row id; unused for options.', 'extend-wp')),
                    Field::string('modal_title')->default_value('')->describe(__('Modal header title.', 'extend-wp')),
                    Field::string('modal_id')->default_value('')->describe(__('Modal identifier; defaults to the meta key.', 'extend-wp')),
                    Field::string('option_page')->default_value('')->describe(__('Option page key for a direct lookup (option view).', 'extend-wp')),
                ])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('GET', 'modal-fields'),

            'modal-save' => Operation::write('save_modal_fields')
                ->annotations(['idempotent' => true])
                ->label(__('Save modal field values', 'extend-wp'))
                ->description(__('Store the values of a modal-type field on the post, term, user, option or content row.', 'extend-wp'))
                ->input([
                    Field::string('meta_key')->required()->describe(__('Meta key of the modal field.', 'extend-wp')),
                    Field::enum('view', ['post', 'term', 'user', 'option', 'content_meta'])->default_value('post')->describe(__('Where the field lives.', 'extend-wp')),
                    Field::int('object_id')->default_value(0)->describe(__('Post, term, user or content row id; unused for options.', 'extend-wp')),
                    Field::object('values')->additional_properties()->default_value([])->describe(__('Values keyed by field.', 'extend-wp')),
                ])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('POST', 'modal-save'),
        ];
    }
}
