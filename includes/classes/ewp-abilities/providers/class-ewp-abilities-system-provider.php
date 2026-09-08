<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site level maintenance and orientation abilities.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_System_Provider extends EWP_Abilities_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-system';

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
            'label'       => __('EWP System', 'extend-wp'),
            'description' => __('Inspect what Extend WP provides on this site and clear its caches.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        return [
            self::CATEGORY . '/get-site-info' => $this->definition(
                __('Get Extend WP site information', 'extend-wp'),
                __('Summarise what Extend WP provides here: plugin and WordPress versions, the registered custom content types, the post types and taxonomies it registers, and whether logging and abilities are on. Cheap orientation call before doing anything else.', 'extend-wp'),
                EWP_Abilities_Schema::input([]),
                ['type' => 'object', 'additionalProperties' => true],
                [$this, 'run_get_site_info'],
                $this->capability_permission('read', self::CATEGORY . '/get-site-info'),
                $this->meta_readonly()
            ),

            self::CATEGORY . '/flush-cache' => $this->definition(
                __('Flush Extend WP caches', 'extend-wp'),
                __('Clear the Extend WP transient caches and flush rewrite rules. Use this after changing definitions outside the normal save path, or when a new post type or field group is not showing up. Saving through the other abilities already flushes automatically, so this is rarely needed.', 'extend-wp'),
                EWP_Abilities_Schema::input([]),
                [
                    'type'       => 'object',
                    'properties' => ['flushed' => ['type' => 'boolean']],
                    'additionalProperties' => true,
                ],
                [$this, 'run_flush_cache'],
                $this->capability_permission('manage_options', self::CATEGORY . '/flush-cache'),
                $this->meta_write(true)
            ),
        ];
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    /**
     * Describe the Extend WP surface on this site.
     *
     * @param mixed $input Unused.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public function run_get_site_info($input = null)
    {
        $content_types = [];

        foreach ($this->service->list_types() as $content_type => $config) {
            $content_types[] = [
                'content_type' => (string) $content_type,
                'label'        => isset($config['list_name']) ? (string) $config['list_name'] : (string) $content_type,
                'capability'   => isset($config['capability']) ? (string) $config['capability'] : 'edit_posts',
            ];
        }

        return [
            'plugin_version'    => defined('AWM_ASSET_VERSION') ? (string) AWM_ASSET_VERSION : '',
            'wp_version'        => get_bloginfo('version'),
            'site_url'          => get_site_url(),
            'content_types'     => $content_types,
            'ewp_post_types'    => array_keys(apply_filters('epw_get_post_types', [])),
            'ewp_taxonomies'    => array_keys(apply_filters('epw_get_taxonomies', [])),
            'logger_enabled'    => class_exists('EWP\Logger\EWP_Logger') ? \EWP\Logger\EWP_Logger::is_enabled() : false,
            'abilities_enabled' => EWP_Abilities::is_enabled(),
        ];
    }

    /**
     * Flush the plugin caches.
     *
     * @param mixed $input Unused.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_flush_cache($input = null)
    {
        if (!function_exists('ewp_flush_cache')) {
            return $this->error(
                'ewp_abilities_flush_unavailable',
                __('The Extend WP cache helpers are not available on this site.', 'extend-wp'),
                503
            );
        }

        return ['flushed' => (bool) ewp_flush_cache()];
    }
}
