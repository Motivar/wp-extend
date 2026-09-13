<?php

namespace EWP\Surfaces\Resources;

use EWP\Content\Content_Service;
use EWP\Surfaces\Content_Schema;
use EWP\Surfaces\Library_Fields;
use Motivar\WP\Context;
use Motivar\WP\Field;
use Motivar\WP\Operation;
use Motivar\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A resource pinned to one or more content types.
 *
 * Where the generic `content` resource takes `content_type` as input, a
 * typed resource names the entity in the ability (`create-post-type`) and
 * describes that content type's real fields, derived from its field
 * library. Meta keys are accepted flat at the top level of the input.
 *
 * Only the abilities are generated here: REST serves every content type
 * through its `{prefix}/{type}` routes and the CLI through
 * `wp ewp content --type=`, so those surfaces are deliberately excluded.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
abstract class Typed_Content_Resource extends Resource
{
    /** @var Content_Service */
    protected $service;

    /**
     * @param Content_Service $service Shared content service.
     */
    public function __construct(Content_Service $service)
    {
        $this->service = $service;
    }

    /**
     * Entities this resource exposes: content_type, singular, plural,
     * label_singular, label_plural, writable, descriptions[operation].
     *
     * @return array[]
     */
    abstract protected function entities();

    public function service()
    {
        return $this->service;
    }

    public function operations()
    {
        $operations = [];

        foreach ($this->entities() as $entity) {
            $operations = array_merge($operations, $this->entity_operations($entity));
        }

        return $operations;
    }

    /* ---------------------------------------------------------------------
     * Extension points
     * ------------------------------------------------------------------ */

    /**
     * Extra validation applied before a write.
     *
     * @param array $entity    Entity descriptor.
     * @param array $meta      Meta payload.
     * @param bool  $is_create Whether this is a create call.
     *
     * @return true|\WP_Error
     */
    protected function validate_entity(array $entity, array $meta, $is_create)
    {
        unset($entity, $meta, $is_create);

        return true;
    }

    /**
     * Add entity specific fields to an output row.
     *
     * @param array $entity Entity descriptor.
     * @param array $row    Normalised row.
     *
     * @return array
     */
    protected function decorate_row(array $entity, array $row)
    {
        unset($entity);

        return $row;
    }

    /* ---------------------------------------------------------------------
     * Operation builders
     * ------------------------------------------------------------------ */

    /**
     * @param array $entity Entity descriptor.
     *
     * @return array<string,Operation> Keyed by ability slug.
     */
    protected function entity_operations(array $entity)
    {
        $type       = $entity['content_type'];
        $capability = $this->service->get_capability($type);
        $reason     = __('served by the generic content resource ({prefix}/{type} routes, wp ewp content --type=)', 'extend-wp');
        $decorate   = function ($row) use ($entity) {
            return is_array($row) ? $this->decorate_row($entity, $row) : $row;
        };

        $ops = [
            'list-' . $entity['plural'] => Operation::read('list_items')
                ->label(sprintf(__('List %s', 'extend-wp'), $entity['label_plural']))
                ->description($this->describe($entity, 'list'))
                ->input(Content_Schema::list_fields())
                ->output(Content_Schema::row_collection())
                ->args(function (array $input) use ($type) {
                    return [$type, $input];
                })
                ->transform(function ($result) use ($decorate) {
                    if (is_array($result) && isset($result['items'])) {
                        $result['items'] = array_map($decorate, $result['items']);
                    }
                    return $result;
                })
                ->capability($capability)
                ->surfaces([Context::ABILITY], $reason)
                ->ability('list-' . $entity['plural']),

            'get-' . $entity['singular'] => Operation::read('get_item')
                ->label(sprintf(__('Get a %s', 'extend-wp'), $entity['label_singular']))
                ->description($this->describe($entity, 'get'))
                ->input([$this->id_field($entity)])
                ->output(Content_Schema::row())
                ->args(function (array $input) use ($type) {
                    return [$type, (int) $input['id']];
                })
                ->transform($decorate)
                ->capability($capability)
                ->surfaces([Context::ABILITY], $reason)
                ->ability('get-' . $entity['singular']),
        ];

        if (empty($entity['writable'])) {
            return $ops;
        }

        return array_merge($ops, $this->write_operations($entity, $capability, $reason, $decorate));
    }

    /**
     * @param array    $entity     Entity descriptor.
     * @param string   $capability Capability of the content type.
     * @param string   $reason     Why REST/CLI are excluded.
     * @param callable $decorate   Row decorator.
     *
     * @return array<string,Operation>
     */
    private function write_operations(array $entity, $capability, $reason, callable $decorate)
    {
        $type    = $entity['content_type'];
        $library = $this->service->resolve_library($type);

        return [
            'create-' . $entity['singular'] => Operation::write('create_item')
                ->label(sprintf(__('Create a %s', 'extend-wp'), $entity['label_singular']))
                ->description($this->describe($entity, 'create'))
                ->input(array_merge($this->common_fields($entity, true), Library_Fields::from_library($library, true)))
                ->output(Content_Schema::row())
                ->args(function (array $input) use ($entity) {
                    return $this->create_args($entity, $input);
                })
                ->transform($decorate)
                ->capability($capability)
                ->surfaces([Context::ABILITY], $reason)
                ->ability('create-' . $entity['singular']),

            'update-' . $entity['singular'] => Operation::write('update_item')
                ->annotations(['idempotent' => true])
                ->label(sprintf(__('Update a %s', 'extend-wp'), $entity['label_singular']))
                ->description($this->describe($entity, 'update'))
                ->input(array_merge([$this->id_field($entity)], $this->common_fields($entity, false), Library_Fields::from_library($library, false)))
                ->output(Content_Schema::row())
                ->args(function (array $input) use ($entity) {
                    return $this->update_args($entity, $input);
                })
                ->transform($decorate)
                ->capability($capability)
                ->surfaces([Context::ABILITY], $reason)
                ->ability('update-' . $entity['singular']),

            'delete-' . $entity['singular'] => Operation::destructive('delete_items')
                ->label(sprintf(__('Delete %s', 'extend-wp'), $entity['label_plural']))
                ->description($this->describe($entity, 'delete'))
                ->input([Field::int_list('ids')->required()->describe(__('Ids of the items to delete.', 'extend-wp'))])
                ->output(Content_Schema::delete_result())
                ->args(function (array $input) use ($type) {
                    return [$type, (array) $input['ids']];
                })
                ->capability($capability)
                ->surfaces([Context::ABILITY], $reason)
                ->ability('delete-' . $entity['singular']),
        ];
    }

    /* ---------------------------------------------------------------------
     * Fields and argument mapping
     * ------------------------------------------------------------------ */

    /**
     * @param array $entity Entity descriptor.
     *
     * @return Field
     */
    protected function id_field(array $entity)
    {
        return Field::int('id')->required()->describe(sprintf(
            /* translators: %s: entity label. */
            __('The %s id.', 'extend-wp'),
            $entity['label_singular']
        ));
    }

    /**
     * Title and status.
     *
     * @param array $entity         Entity descriptor.
     * @param bool  $title_required Whether title is mandatory.
     *
     * @return Field[]
     */
    protected function common_fields(array $entity, $title_required)
    {
        $type     = $entity['content_type'];
        $statuses = $this->service->get_statuses($type);

        $title  = Field::string('title')->describe(__('Administrative title shown in the wp-admin list.', 'extend-wp'));
        $status = $statuses !== []
            ? Field::enum('status', $statuses)
            : Field::string('status');

        return [
            $title_required ? $title->required() : $title,
            $status->describe(__('Item status. Defaults to the first registered status on create.', 'extend-wp')),
        ];
    }

    /**
     * @param array $entity Entity descriptor.
     * @param array $input  Normalised input.
     *
     * @return array|\WP_Error create_item() arguments.
     */
    protected function create_args(array $entity, array $input)
    {
        $meta      = $this->meta_from_input($entity, $input);
        $validated = $this->validate_entity($entity, $meta, true);
        if ($validated instanceof \WP_Error) {
            return $validated;
        }

        return [
            $entity['content_type'],
            isset($input['title']) ? (string) $input['title'] : '',
            isset($input['status']) ? (string) $input['status'] : '',
            $meta,
        ];
    }

    /**
     * @param array $entity Entity descriptor.
     * @param array $input  Normalised input.
     *
     * @return array|\WP_Error update_item() arguments.
     */
    protected function update_args(array $entity, array $input)
    {
        $meta      = $this->meta_from_input($entity, $input);
        $validated = $this->validate_entity($entity, $meta, false);
        if ($validated instanceof \WP_Error) {
            return $validated;
        }

        $patch = ['meta' => $meta];
        if (array_key_exists('title', $input)) {
            $patch['title'] = (string) $input['title'];
        }
        if (array_key_exists('status', $input)) {
            $patch['status'] = (string) $input['status'];
        }

        return [$entity['content_type'], (int) $input['id'], $patch];
    }

    /**
     * Meta keys arrive flat; anything that is not reserved and exists in
     * the library is meta.
     *
     * @param array $entity Entity descriptor.
     * @param array $input  Normalised input.
     *
     * @return array
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
     * @param array  $entity    Entity descriptor.
     * @param string $operation Operation key.
     *
     * @return string
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
     * Build a 400 error.
     *
     * @param string $code    Error code.
     * @param string $message Message.
     * @param int    $status  HTTP status.
     *
     * @return \WP_Error
     */
    protected function error($code, $message, $status = 400)
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
