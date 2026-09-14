<?php

namespace EWP\Surfaces\Resources;

use EWP\Search\Object_Search;
use Gnnpls\WP\Context;
use Gnnpls\WP\Field;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Object lookup by name on every surface: the `object_id_filter` picker's
 * `GET extend-wp/v1/objects/search`, `wp ewp objects search` and
 * `ewp-system/search-objects`, so an agent can resolve a post, term or
 * custom content id from a title.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Object_Search_Resource extends Resource
{
    /** @var Object_Search */
    private $service;

    /**
     * @param Object_Search $service Search service.
     */
    public function __construct(Object_Search $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'objects';
    }

    public function label()
    {
        return __('EWP Object Search', 'extend-wp');
    }

    public function service()
    {
        return $this->service;
    }

    public function rest_namespace()
    {
        return 'extend-wp/v1';
    }

    public function rest_base()
    {
        return '/objects';
    }

    public function cli_base()
    {
        return 'ewp objects';
    }

    public function ability_category()
    {
        return 'ewp-system';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP System', 'extend-wp'),
            'description' => __('Inspect what Extend WP provides on this site and clear its caches.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        return function () {
            return Object_Search::capability();
        };
    }

    public function operations()
    {
        return [
            'search' => Operation::read('search')
                ->label(__('Search objects by name', 'extend-wp'))
                ->description(__('Find posts, terms or custom content rows by name and get lightweight {id, label, type} results. object_type is group:slug, e.g. post_type:post, taxonomy:category or custom_content:ewp_fields. Use it to resolve ids before calling an ability that needs one.', 'extend-wp'))
                ->input([
                    Field::string('object_type')->required()->cli_name('type')->describe(__('group:slug, e.g. post_type:post, taxonomy:category, custom_content:ewp_fields.', 'extend-wp')),
                    Field::string('search')->positional()->describe(__('Text to match against the object name.', 'extend-wp')),
                    Field::int_list('exclude')->describe(__('Ids to leave out of the results.', 'extend-wp')),
                    Field::int('limit')->min(1)->describe(__('Maximum results. Defaults to 20.', 'extend-wp')),
                    Field::bool('search_meta')->describe(__('Also match meta values. Defaults to true.', 'extend-wp')),
                ])
                ->args(function (array $input) {
                    return [
                        $input['object_type'],
                        isset($input['search']) ? (string) $input['search'] : '',
                        [
                            'limit'       => isset($input['limit']) ? (int) $input['limit'] : 20,
                            'search_meta' => array_key_exists('search_meta', $input) ? (bool) $input['search_meta'] : true,
                            'exclude'     => isset($input['exclude']) ? (array) $input['exclude'] : [],
                        ],
                    ];
                })
                ->transform([$this, 'search_transform'])
                ->output($this->output_schema())
                ->rest('GET', 'search')
                ->cli('search', ['columns' => ['id', 'label', 'type']])
                ->ability('search-objects'),
        ];
    }

    /**
     * REST keeps `{success, data}`; the CLI table reads `items`; abilities get `{count, results}`.
     *
     * @param array   $results Results.
     * @param array   $input   Input.
     * @param Context $ctx     Invocation context.
     *
     * @return array
     */
    public function search_transform($results, array $input, Context $ctx)
    {
        $results = is_array($results) ? array_values($results) : [];

        if ($ctx->surface() === Context::REST) {
            return ['success' => true, 'data' => $results];
        }

        if ($ctx->surface() === Context::CLI) {
            return ['count' => count($results), 'items' => $results];
        }

        return ['count' => count($results), 'results' => $results];
    }

    /**
     * @return array
     */
    private function output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count'   => ['type' => 'integer'],
                'results' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            ],
            'additionalProperties' => true,
        ];
    }
}
