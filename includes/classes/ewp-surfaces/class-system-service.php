<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site-level orientation and maintenance behind `ewp-system/*`,
 * `wp ewp system info`, `wp ewp delete-cache` and `extend-wp/v1/system/*`.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class System_Service
{
    /** @var Content_Service */
    private $content;

    /**
     * @param Content_Service $content Shared content service.
     */
    public function __construct(Content_Service $content)
    {
        $this->content = $content;
    }

    /**
     * Summarise what Extend WP provides on this site.
     *
     * @return array plugin_version, wp_version, site_url, content_types, ewp_post_types,
     *               ewp_taxonomies, logger_enabled, abilities_enabled
     *
     * @since 1.5.0
     */
    public function site_info()
    {
        $content_types = [];
        foreach ($this->content->list_types() as $content_type => $config) {
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
            'logger_enabled'    => class_exists('EWP\\Logger\\EWP_Logger') ? \EWP\Logger\EWP_Logger::is_enabled() : false,
            'abilities_enabled' => class_exists('EWP\\Abilities\\EWP_Abilities') ? \EWP\Abilities\EWP_Abilities::is_enabled() : false,
        ];
    }

    /**
     * Clear the Extend WP transient caches and flush rewrite rules.
     *
     * @return array|\WP_Error flushed
     *
     * @since 1.5.0
     */
    public function flush_cache()
    {
        if (!function_exists('ewp_flush_cache')) {
            return new \WP_Error('ewp_abilities_flush_unavailable', __('The Extend WP cache helpers are not available on this site.', 'extend-wp'), ['status' => 503]);
        }

        return ['flushed' => (bool) ewp_flush_cache()];
    }
}
