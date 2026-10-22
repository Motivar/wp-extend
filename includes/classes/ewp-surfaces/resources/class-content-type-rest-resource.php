<?php

namespace EWP\Surfaces\Resources;

use EWP\Content\Content_Service;
use EWP\Surfaces\Content_Schema;
use MammothWP\WpKit\Field;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The REST routes of one registered content type.
 *
 * Every `awm_register_content_db` type gets `{prefix}/{type}` (list),
 * `{prefix}/{type}/{id}` (single) and, when writable, `/create/`,
 * `/update/{id}` and `/delete/`, all served by the same Content_Service
 * calls as the generic CLI commands and abilities.
 *
 * Reads use the type's capability, or are anonymous when the type opted in
 * with `public_read`; writes always use the type's capability.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Content_Type_Rest_Resource extends Resource
{
    /** @var string */
    private $content_type;
    /** @var string */
    private $prefix;
    /** @var string */
    private $data_id;
    /** @var Content_Service */
    private $service;

    /**
     * @param string          $content_type Content type id, e.g. `ewp_fields`.
     * @param string          $prefix       REST namespace, e.g. `ewp` or `flx`.
     * @param string          $data_id      Route base under the namespace, e.g. `fields`.
     * @param Content_Service $service      Shared content service.
     */
    public function __construct($content_type, $prefix, $data_id, Content_Service $service)
    {
        $this->content_type = (string) $content_type;
        $this->prefix       = (string) $prefix;
        $this->data_id      = (string) $data_id;
        $this->service      = $service;
    }

    public function name()
    {
        return $this->content_type;
    }

    public function service()
    {
        return $this->service;
    }

    public function rest_namespace()
    {
        return $this->prefix;
    }

    public function rest_base()
    {
        return '/' . $this->data_id;
    }

    public function capability()
    {
        return $this->service->get_capability($this->content_type);
    }

    public function operations()
    {
        $type     = $this->content_type;
        $service  = $this->service;
        $statuses = function () use ($service, $type) {
            return $service->get_statuses($type);
        };
        $read_cap = function () use ($service, $type) {
            return $service->is_public_read($type) ? true : $service->get_capability($type);
        };

        $ops = [
            'list' => Operation::read('list_items')
                ->label(sprintf(__('List %s items', 'extend-wp'), $type))
                ->description(sprintf(__('List rows of %s, newest first, with their meta. Filter by status, title text or ids.', 'extend-wp'), $type))
                ->input(Content_Schema::list_fields(true))
                ->args(function (array $input) use ($type) {
                    return [$type, $input];
                })
                ->transform(function ($result) {
                    return isset($result['items']) ? $result['items'] : $result;
                })
                ->capability($read_cap)
                ->rest('GET', ''),

            'query' => Operation::read('query_items')
                ->label(sprintf(__('Query %s items', 'extend-wp'), $type))
                ->description(sprintf(__('One page of %s rows for a DataViews view (search, filters on columns and meta, sort, page, perPage), with total and total_pages.', 'extend-wp'), $type))
                ->input(array_merge(Content_Schema::view_fields(), [
                    Field::bool('render')->describe(__('Also return each row\'s admin list cells and row actions as HTML (rendered.cells, rendered.actions), as the legacy list shows them. Used by the DataViews admin list.', 'extend-wp')),
                ]))
                ->args(function (array $input) use ($type) {
                    return [$type, isset($input['view']) ? (array) $input['view'] : [], !isset($input['with_meta']) || (bool) $input['with_meta']];
                })
                ->transform(function ($result, array $input) use ($type) {
                    if (empty($input['render']) || !is_array($result) || !class_exists('EWP_Content_List_UI')) {
                        return $result;
                    }

                    return \EWP_Content_List_UI::render_rows($result, $type);
                })
                ->capability($read_cap)
                ->rest('GET', 'query'),

            'get' => Operation::read('get_item')
                ->label(sprintf(__('Get one %s item', 'extend-wp'), $type))
                ->description(sprintf(__('Return one row of %s by id with every stored meta value; 404 when it does not exist.', 'extend-wp'), $type))
                ->input([Field::int('id')->required()->min(1)->describe(__('The item id.', 'extend-wp'))])
                ->args(function (array $input) use ($type) {
                    return [$type, (int) $input['id']];
                })
                ->capability($read_cap)
                ->rest('GET', '(?P<id>\d+)'),
        ];

        $config = $service->get_config($type);
        if (isset($config['writable']) && !$config['writable']) {
            return $ops;
        }

        $ops['create'] = Operation::write('create_item')
            ->label(sprintf(__('Create a %s item', 'extend-wp'), $type))
            ->description(sprintf(__('Create a row of %s. Meta keys must match its field library and every required field must be present.', 'extend-wp'), $type))
            ->input(Content_Schema::writable_fields(true, $statuses))
            ->args(function (array $input) use ($type) {
                return [$type, isset($input['title']) ? (string) $input['title'] : '', isset($input['status']) ? (string) $input['status'] : '', isset($input['meta']) ? (array) $input['meta'] : []];
            })
            ->rest('POST', 'create', 201);

        $ops['update'] = Operation::write('update_item')
            ->annotations(['idempotent' => true])
            ->label(sprintf(__('Update a %s item', 'extend-wp'), $type))
            ->description(sprintf(__('Patch a row of %s: only the title, status and meta keys sent are changed.', 'extend-wp'), $type))
            ->input(array_merge([Field::int('id')->required()->min(1)->describe(__('The item id.', 'extend-wp'))], Content_Schema::writable_fields(false, $statuses)))
            ->args(function (array $input) use ($type) {
                $patch = ['meta' => isset($input['meta']) ? (array) $input['meta'] : []];
                if (array_key_exists('title', $input)) {
                    $patch['title'] = (string) $input['title'];
                }
                if (array_key_exists('status', $input)) {
                    $patch['status'] = (string) $input['status'];
                }

                return [$type, (int) $input['id'], $patch];
            })
            ->rest('POST', 'update/(?P<id>\d+)');

        $ops['set-status'] = Operation::write('set_status')
            ->annotations(['idempotent' => true])
            ->label(sprintf(__('Set the status of %s items', 'extend-wp'), $type))
            ->description(sprintf(__('Change the status of up to 200 rows of %s through the normal save.', 'extend-wp'), $type))
            ->input(Content_Schema::status_fields($statuses))
            ->args(function (array $input) use ($type) {
                return [$type, (array) $input['ids'], (string) $input['status']];
            })
            ->rest('POST', 'status');

        $ops['delete'] = Operation::destructive('delete_items')
            ->label(sprintf(__('Delete %s items', 'extend-wp'), $type))
            ->description(sprintf(__('Permanently delete rows of %s together with their meta.', 'extend-wp'), $type))
            ->input([Field::int_list('ids')->required()->describe(__('Ids of the items to delete, comma separated.', 'extend-wp'))])
            ->args(function (array $input) use ($type) {
                return [$type, (array) $input['ids']];
            })
            ->rest('DELETE', 'delete');

        return $ops;
    }
}
