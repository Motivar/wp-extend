<?php

namespace EWP\Surfaces\Resources;

use EWP\Surfaces\System_Service;
use Gnnpls\WP\Operation;
use Gnnpls\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site information and cache maintenance on every surface.
 *
 * The CLI base is `ewp` so the long-standing `wp ewp delete-cache`
 * keeps its name next to the new `wp ewp system info`.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class System_Resource extends Resource
{
    /** @var System_Service */
    private $service;

    /**
     * @param System_Service $service System service.
     */
    public function __construct(System_Service $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'system';
    }

    public function label()
    {
        return __('EWP System', 'extend-wp');
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
        return '/system';
    }

    public function cli_base()
    {
        return 'ewp';
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

    public function operations()
    {
        return [
            'info'  => Operation::read('site_info')
                ->label(__('Get Extend WP site information', 'extend-wp'))
                ->description(__('Summarise what Extend WP provides here: plugin and WordPress versions, the registered custom content types, the post types and taxonomies it registers, and whether logging and abilities are on. Cheap orientation call before doing anything else.', 'extend-wp'))
                ->capability('read')
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('GET', 'info')
                ->cli('system info', ['default_format' => 'json'])
                ->ability('get-site-info'),

            'flush' => Operation::write('flush_cache')
                ->annotations(['idempotent' => true])
                ->label(__('Flush Extend WP caches', 'extend-wp'))
                ->description(__('Clear the Extend WP transient caches and flush rewrite rules. Use this after changing definitions outside the normal save path, or when a new post type or field group is not showing up. Saving through the other abilities already flushes automatically, so this is rarely needed.', 'extend-wp'))
                ->capability('manage_options')
                ->output(['type' => 'object', 'properties' => ['flushed' => ['type' => 'boolean']], 'additionalProperties' => true])
                ->rest('POST', 'flush-cache')
                ->cli('delete-cache', ['success' => 'All ewp cache deleted.'])
                ->ability('flush-cache'),
        ];
    }
}
