<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for providers bound to specific content types.
 *
 * Where the generic content provider takes `content_type` as input, these
 * providers pin it, so the ability name says what it manages and the input
 * schema can describe that content type's real fields. Schemas are derived
 * from the plugin's own field libraries, never restated.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
abstract class EWP_Abilities_Typed_Provider extends EWP_Abilities_Provider
{
    /**
     * Shared content service.
     *
     * @var EWP_Abilities_Content_Service
     */
    protected $service;

    /**
     * Constructor.
     *
     * @param EWP_Abilities_Content_Service $service Shared content service.
     *
     * @since 1.4.0
     */
    public function __construct(EWP_Abilities_Content_Service $service)
    {
        $this->service = $service;
    }

    /**
     * Entities this provider exposes.
     *
     * Each entity is an array with keys: `content_type`, `singular` (used in
     * ability names, for example `post-type`), `plural`, `label_singular`,
     * `label_plural`, `writable` and optional `descriptions` overrides keyed
     * by operation.
     *
     * @return array[]
     *
     * @since 1.4.0
     */
    abstract protected function entities();

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        $definitions = [];

        foreach ($this->entities() as $entity) {
            $definitions = array_merge($definitions, $this->entity_definitions($entity));
        }

        return $definitions;
    }

    /**
     * Build every ability definition for one entity.
     *
     * @param array $entity Entity descriptor.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function entity_definitions(array $entity)
    {
        $content_type = $entity['content_type'];
        $capability   = $this->service->get_capability($content_type);
        $definitions  = [
            $this->ability_name('list-' . $entity['plural']) => $this->definition(
                sprintf(__('List %s', 'extend-wp'), $entity['label_plural']),
                $this->describe($entity, 'list'),
                EWP_Abilities_Schema::input(EWP_Abilities_Schema::list_properties()),
                EWP_Abilities_Schema::row_collection(),
                $this->bind('run_list', $entity),
                $this->capability_permission($capability, $this->ability_name('list-' . $entity['plural'])),
                $this->meta_readonly()
            ),

            $this->ability_name('get-' . $entity['singular']) => $this->definition(
                sprintf(__('Get a %s', 'extend-wp'), $entity['label_singular']),
                $this->describe($entity, 'get'),
                EWP_Abilities_Schema::input($this->id_property($entity), ['id']),
                EWP_Abilities_Schema::row(),
                $this->bind('run_get', $entity),
                $this->capability_permission($capability, $this->ability_name('get-' . $entity['singular'])),
                $this->meta_readonly()
            ),
        ];

        if (empty($entity['writable'])) {
            return $definitions;
        }

        $meta_properties = $this->meta_properties($entity);

        $definitions[$this->ability_name('create-' . $entity['singular'])] = $this->definition(
            sprintf(__('Create a %s', 'extend-wp'), $entity['label_singular']),
            $this->describe($entity, 'create'),
            EWP_Abilities_Schema::input(
                array_merge($this->common_properties($entity), $meta_properties),
                array_merge(['title'], $this->required_keys($entity))
            ),
            EWP_Abilities_Schema::row(),
            $this->bind('run_create', $entity),
            $this->capability_permission($capability, $this->ability_name('create-' . $entity['singular'])),
            $this->meta_write(false)
        );

        $definitions[$this->ability_name('update-' . $entity['singular'])] = $this->definition(
            sprintf(__('Update a %s', 'extend-wp'), $entity['label_singular']),
            $this->describe($entity, 'update'),
            EWP_Abilities_Schema::input(
                array_merge($this->id_property($entity), $this->common_properties($entity), $meta_properties),
                ['id']
            ),
            EWP_Abilities_Schema::row(),
            $this->bind('run_update', $entity),
            $this->capability_permission($capability, $this->ability_name('update-' . $entity['singular'])),
            $this->meta_write(true)
        );

        $definitions[$this->ability_name('delete-' . $entity['singular'])] = $this->definition(
            sprintf(__('Delete %s', 'extend-wp'), $entity['label_plural']),
            $this->describe($entity, 'delete'),
            EWP_Abilities_Schema::input(
                array_merge(
                    [
                        'ids' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => __('Ids of the items to delete.', 'extend-wp'),
                        ],
                    ],
                    $this->confirm_property()
                ),
                ['ids', 'confirm']
            ),
            $this->delete_output_schema(),
            $this->bind('run_delete', $entity),
            $this->capability_permission($capability, $this->ability_name('delete-' . $entity['singular'])),
            $this->meta_destructive()
        );

        return $definitions;
    }

    /* ---------------------------------------------------------------------
     * Schema helpers
     * ------------------------------------------------------------------ */

    /**
     * Prefix an ability slug with this provider's category.
     *
     * @param string $slug Ability slug.
     *
     * @return string
     *
     * @since 1.4.0
     */
    protected function ability_name($slug)
    {
        return $this->category() . '/' . $slug;
    }

    /**
     * The `id` input property.
     *
     * @param array $entity Entity descriptor.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function id_property(array $entity)
    {
        return [
            'id' => [
                'type'        => 'integer',
                'description' => sprintf(
                    /* translators: %s: entity label. */
                    __('The %s id.', 'extend-wp'),
                    $entity['label_singular']
                ),
            ],
        ];
    }

    /**
     * Title and status properties.
     *
     * @param array $entity Entity descriptor.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function common_properties(array $entity)
    {
        $statuses = $this->service->get_statuses($entity['content_type']);

        $status = [
            'type'        => 'string',
            'description' => __('Item status. Defaults to the first registered status on create.', 'extend-wp'),
        ];

        if (!empty($statuses)) {
            $status['enum'] = $statuses;
        }

        return [
            'title'  => [
                'type'        => 'string',
                'description' => __('Administrative title shown in the wp-admin list.', 'extend-wp'),
            ],
            'status' => $status,
        ];
    }

    /**
     * Schema properties derived from the entity's field library.
     *
     * @param array $entity Entity descriptor.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_properties(array $entity)
    {
        return EWP_Abilities_Schema::library_to_properties($this->service->resolve_library($entity['content_type']));
    }

    /**
     * Required meta keys for this entity, as ability input requirements.
     *
     * @param array $entity Entity descriptor.
     *
     * @return string[]
     *
     * @since 1.4.0
     */
    protected function required_keys(array $entity)
    {
        $required = [];

        foreach ($this->service->describe_library($entity['content_type']) as $field) {
            if (!empty($field['required'])) {
                $required[] = $field['key'];
            }
        }

        return $required;
    }

    /**
     * Output schema for a delete call.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function delete_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count'     => ['type' => 'integer'],
                'deleted'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                'not_found' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Return the description for one operation.
     *
     * @param array  $entity    Entity descriptor.
     * @param string $operation Operation key.
     *
     * @return string
     *
     * @since 1.4.0
     */
    protected function describe(array $entity, $operation)
    {
        if (!empty($entity['descriptions'][$operation])) {
            return $entity['descriptions'][$operation];
        }

        return sprintf(
            /* translators: 1: operation, 2: entity label. */
            __('%1$s %2$s stored in the Extend WP custom content database.', 'extend-wp'),
            ucfirst($operation),
            $entity['label_plural']
        );
    }

    /**
     * Bind a handler to one entity.
     *
     * @param string $method Handler method name.
     * @param array  $entity Entity descriptor.
     *
     * @return callable
     *
     * @since 1.4.0
     */
    protected function bind($method, array $entity)
    {
        return function ($input = null) use ($method, $entity) {
            return $this->{$method}($entity, $input);
        };
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    /**
     * List rows of the entity.
     *
     * @param array $entity Entity descriptor.
     * @param mixed $input  Ability input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function run_list(array $entity, $input = null)
    {
        $result = $this->service->list_items($entity['content_type'], $this->normalize_input($input));

        foreach ($result['items'] as $index => $item) {
            $result['items'][$index] = $this->decorate_row($entity, $item);
        }

        return $result;
    }

    /**
     * Return one row.
     *
     * @param array $entity Entity descriptor.
     * @param mixed $input  Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    protected function run_get(array $entity, $input = null)
    {
        $input = $this->normalize_input($input);
        $row   = $this->service->get_item($entity['content_type'], isset($input['id']) ? $input['id'] : 0);

        if (is_wp_error($row)) {
            return $row;
        }

        return $this->decorate_row($entity, $row);
    }

    /**
     * Create a row.
     *
     * @param array $entity Entity descriptor.
     * @param mixed $input  Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    protected function run_create(array $entity, $input = null)
    {
        $input = $this->normalize_input($input);
        $meta  = $this->meta_from_input($entity, $input);

        $validated = $this->validate_entity($entity, $meta, true);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $row = $this->service->create_item(
            $entity['content_type'],
            isset($input['title']) ? $input['title'] : '',
            isset($input['status']) ? $input['status'] : '',
            $meta
        );

        if (is_wp_error($row)) {
            return $row;
        }

        return $this->decorate_row($entity, $row);
    }

    /**
     * Update a row.
     *
     * @param array $entity Entity descriptor.
     * @param mixed $input  Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    protected function run_update(array $entity, $input = null)
    {
        $input = $this->normalize_input($input);
        $meta  = $this->meta_from_input($entity, $input);

        $validated = $this->validate_entity($entity, $meta, false);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $patch = ['meta' => $meta];

        if (array_key_exists('title', $input)) {
            $patch['title'] = $input['title'];
        }

        if (array_key_exists('status', $input)) {
            $patch['status'] = $input['status'];
        }

        $row = $this->service->update_item($entity['content_type'], isset($input['id']) ? $input['id'] : 0, $patch);

        if (is_wp_error($row)) {
            return $row;
        }

        return $this->decorate_row($entity, $row);
    }

    /**
     * Delete rows.
     *
     * @param array $entity Entity descriptor.
     * @param mixed $input  Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    protected function run_delete(array $entity, $input = null)
    {
        $input     = $this->normalize_input($input);
        $confirmed = $this->require_confirm($input);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return $this->service->delete_items($entity['content_type'], isset($input['ids']) ? (array) $input['ids'] : []);
    }

    /* ---------------------------------------------------------------------
     * Extension points
     * ------------------------------------------------------------------ */

    /**
     * Pull the meta payload out of flat ability input.
     *
     * Typed providers accept field keys at the top level, so anything that is
     * not a reserved key is treated as meta.
     *
     * @param array $entity Entity descriptor.
     * @param array $input  Normalised input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_from_input(array $entity, array $input)
    {
        $reserved = ['id', 'title', 'status', 'confirm', 'ids'];
        $library  = $this->service->resolve_library($entity['content_type']);
        $meta     = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }

            if (!empty($library) && !array_key_exists($key, $library)) {
                continue;
            }

            $meta[$key] = $value;
        }

        return $meta;
    }

    /**
     * Extra validation applied before a write. Overridden by subclasses.
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
        unset($entity, $meta, $is_create);

        return true;
    }

    /**
     * Add entity specific fields to an output row. Overridden by subclasses.
     *
     * @param array $entity Entity descriptor.
     * @param array $row    Normalised row.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function decorate_row(array $entity, array $row)
    {
        unset($entity);

        return $row;
    }
}
