<?php

namespace EWP\Surfaces\Resources;

use EWP\Surfaces\Rest_Health_Inventory;
use Motivar\WP\Field;
use Motivar\WP\Operation;
use Motivar\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The REST route inventory on every surface: which active plugins register
 * REST namespaces and which routes each exposes. The remaining REST-health
 * features (testing, batches, history, monitoring, OpenAPI) stay REST-only
 * in EWP_REST_Health_Controller.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Rest_Health_Resource extends Resource
{
    /** @var Rest_Health_Inventory */
    private $service;

    /**
     * @param Rest_Health_Inventory $service Inventory service.
     */
    public function __construct(Rest_Health_Inventory $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'rest-health';
    }

    public function label()
    {
        return __('EWP REST Health', 'extend-wp');
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
        return '/rest-health';
    }

    public function cli_base()
    {
        return 'ewp rest-health';
    }

    public function ability_category()
    {
        return 'ewp-rest-health';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP REST Health', 'extend-wp'),
            'description' => __('Discover which REST namespaces and routes the active plugins register on this site.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        return function () {
            return is_super_admin();
        };
    }

    public function operations()
    {
        $list = ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]];

        return [
            'plugins'   => Operation::read('plugins')
                ->label(__('List plugins with REST namespaces', 'extend-wp'))
                ->description(__('List the active plugins and the REST namespaces attributed to each. Pass refresh to rescan plugin sources. Call this first to learn the plugin paths the endpoints operation expects.', 'extend-wp'))
                ->input([Field::bool('refresh')->describe(__('Clear the namespace scan cache and rescan.', 'extend-wp'))])
                ->args(function (array $input) {
                    return [!empty($input['refresh'])];
                })
                ->output($list)
                ->rest('GET', 'plugins')
                ->cli('plugins', ['columns' => ['path', 'name', 'dir']])
                ->ability('list-plugins'),

            'endpoints' => Operation::read('endpoints')
                ->label(__('List REST routes of plugins', 'extend-wp'))
                ->description(__('List every REST route the given plugins register, with methods, arguments and URL parameters, as the REST-health page shows them.', 'extend-wp'))
                ->input([Field::array('plugins')->items('string')->required()->describe(__('Plugin paths as reported by list-plugins, comma separated on REST and the CLI.', 'extend-wp'))])
                ->output($list)
                ->rest('POST', 'endpoints')
                ->rest_alias('GET', 'endpoints')
                ->cli('endpoints', ['default_format' => 'json'])
                ->ability('list-endpoints'),
        ];
    }
}
