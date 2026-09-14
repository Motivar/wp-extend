<?php

namespace EWP\Surfaces\Resources;

use EWP\Content\Content_Service;
use EWP\Surfaces\Content_Schema;
use Gnnpls\WP\Context;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic CRUD over any registered custom content type.
 *
 * The content type is an input (`content_type`, `--type` on the CLI), so a
 * type registered by another plugin through `awm_register_content_db` is
 * reachable without new declarations. Exposed as `wp ewp content *` and
 * the `ewp-content/*` abilities; REST serves each type under its own
 * `{prefix}/{type}` routes (see Content_Type_Rest_Resource).
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Content_Resource extends Resource
{
    /** @var Content_Service */
    private $service;

    /**
     * @param Content_Service $service Shared content service.
     */
    public function __construct(Content_Service $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'content';
    }

    public function label()
    {
        return __('EWP Custom Content', 'extend-wp');
    }

    public function service()
    {
        return $this->service;
    }

    public function cli_base()
    {
        return 'ewp content';
    }

    public function ability_category()
    {
        return 'ewp-content';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Custom Content', 'extend-wp'),
            'description' => __('Read and write rows of any content type registered with the Extend WP custom content database.', 'extend-wp'),
        ];
    }

    /**
     * Capability of the content type named in the input.
     *
     * An unknown type is authorised against a low baseline so the handler
     * can report it properly; core would replace a permission error with a
     * generic message.
     *
     * @return callable
     */
    public function capability()
    {
        $service = $this->service;

        return function (Operation $op, array $input) use ($service) {
            $type = isset($input['content_type']) ? (string) $input['content_type'] : '';
            if ($type === '' || $service->get_config($type) === null) {
                return 'edit_posts';
            }

            return $service->get_capability($type);
        };
    }

    /**
     * REST is served per content type by Content_Type_Rest_Resource, so this
     * resource exposes CLI and abilities only.
     *
     * @return string[]
     */
    public function surfaces()
    {
        return [Context::CLI, Context::ABILITY];
    }

    /**
     * @return string
     */
    public function surfaces_reason()
    {
        return __('the type inventory is global; REST serves each type under its own {prefix}/{type} routes', 'extend-wp');
    }

    public function operations()
    {
        $service = $this->service;
        $type    = Content_Schema::content_type($service);

        return [
            'types' => Operation::read('describe_types')
                ->label(__('List custom content types', 'extend-wp'))
                ->description(__('List every custom content type registered on this site with its statuses, required capability and field keys. Call this first: it tells you which content_type values and meta keys the other content abilities accept, so you never have to guess. Only types the current user may access are returned.', 'extend-wp'))
                ->capability('read')
                ->output(Content_Schema::types())
                ->cli('types', ['presenter' => [$this, 'present_types']])
                ->ability('list-content-types'),

            'list' => Operation::read('list_items')
                ->label(__('List custom content items', 'extend-wp'))
                ->description(__('List rows of one content type, newest first. Filter by status, title text or explicit ids. Leave with_meta off unless you need every stored value: titles and ids alone are much cheaper. Returns 50 items by default, 200 at most.', 'extend-wp'))
                ->input(array_merge([$type], Content_Schema::list_fields()))
                ->output(Content_Schema::row_collection())
                ->cli('list', ['columns' => ['id', 'title', 'status', 'modified']])
                ->ability('list-items'),

            'get' => Operation::read('get_item')
                ->label(__('Get a custom content item', 'extend-wp'))
                ->description(__('Return one row of a content type by id, including every stored meta value. Use this after list-items when you need the full configuration of a single item.', 'extend-wp'))
                ->input([$type, Content_Schema::id()])
                ->output(Content_Schema::row())
                ->cli('get', ['default_format' => 'json'])
                ->ability('get-item'),

            'create' => Operation::write('create_item')
                ->label(__('Create a custom content item', 'extend-wp'))
                ->description(__('Create a new row of a content type. Meta keys must match the field keys reported by list-content-types, and every required field must be present. The status defaults to the content type first registered status.', 'extend-wp'))
                ->input(array_merge([$type], Content_Schema::writable_fields(true)))
                ->output(Content_Schema::row())
                ->args([$this, 'create_args'])
                ->cli('create', ['success' => 'Created %content_type% item #%id%.'])
                ->ability('create-item'),

            'update' => Operation::write('update_item')
                ->annotations(['idempotent' => true])
                ->label(__('Update a custom content item', 'extend-wp'))
                ->description(__('Update an existing row. This is a patch: only the meta keys you send are written, everything else is left untouched. Title and status keep their stored values when omitted.', 'extend-wp'))
                ->input(array_merge([$type, Content_Schema::id()], Content_Schema::writable_fields(false)))
                ->output(Content_Schema::row())
                ->args([$this, 'update_args'])
                ->cli('update', ['success' => 'Updated %content_type% item #%id%.'])
                ->ability('update-item'),

            'delete' => Operation::destructive('delete_items')
                ->label(__('Delete custom content items', 'extend-wp'))
                ->description(__('Permanently delete rows of a content type together with all of their meta. This cannot be undone, so confirm must be true. Deleting a field library or post type definition removes it from the site immediately.', 'extend-wp'))
                ->input([$type, Content_Schema::ids()])
                ->output(Content_Schema::delete_result())
                ->args([$this, 'delete_args'])
                ->cli('delete', ['success' => 'Deleted %count% %content_type% item(s).'])
                ->ability('delete-item'),
        ];
    }

    /* ---------------------------------------------------------------------
     * Argument mappers (service signatures do not match input names 1:1)
     * ------------------------------------------------------------------ */

    /**
     * @param array $input Normalised input.
     *
     * @return array create_item($content_type, $title, $status, array $meta)
     */
    public function create_args(array $input)
    {
        return [
            $input['content_type'],
            isset($input['title']) ? (string) $input['title'] : '',
            isset($input['status']) ? (string) $input['status'] : '',
            isset($input['meta']) ? (array) $input['meta'] : [],
        ];
    }

    /**
     * @param array $input Normalised input.
     *
     * @return array update_item($content_type, $id, array $patch)
     */
    public function update_args(array $input)
    {
        $patch = ['meta' => isset($input['meta']) ? (array) $input['meta'] : []];

        if (array_key_exists('title', $input)) {
            $patch['title'] = (string) $input['title'];
        }

        if (array_key_exists('status', $input)) {
            $patch['status'] = (string) $input['status'];
        }

        return [$input['content_type'], (int) $input['id'], $patch];
    }

    /**
     * @param array $input Normalised input.
     *
     * @return array delete_items($content_type, array $ids)
     */
    public function delete_args(array $input)
    {
        return [$input['content_type'], isset($input['ids']) ? (array) $input['ids'] : []];
    }

    /* ---------------------------------------------------------------------
     * CLI presentation
     * ------------------------------------------------------------------ */

    /**
     * Render the type inventory as a table.
     *
     * @param array       $result Result of describe_types().
     * @param array       $input  Input (unused).
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_types($result, array $input, $format)
    {
        $rows = [];
        foreach (isset($result['types']) ? $result['types'] : [] as $type) {
            $rows[] = [
                'Type'        => $type['content_type'],
                'Label'       => $type['label'],
                'Capability'  => $type['capability'],
                'Writable'    => !empty($type['writable']) ? 'yes' : 'no',
                'Public read' => !empty($type['public_read']) ? 'yes' : 'no',
            ];
        }

        if ($rows === []) {
            \WP_CLI::success('No content types registered.');
            return;
        }

        \WP_CLI\Utils\format_items($format ?: 'table', $rows, ['Type', 'Label', 'Capability', 'Writable', 'Public read']);
    }
}
