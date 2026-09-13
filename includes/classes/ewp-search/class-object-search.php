<?php

namespace EWP\Search;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight `{id, label, type}` lookup of posts, terms and custom content
 * rows by name, behind the `object_id_filter` picker, `GET
 * extend-wp/v1/objects/search`, `wp ewp objects search` and
 * `ewp-system/search-objects`.
 *
 * @package    EWP\Search
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Object_Search
{
    const GROUPS = ['post_type', 'taxonomy', 'custom_content'];

    /**
     * @param string $object_type `{group}:{slug}`, e.g. `post_type:post`, `taxonomy:category`, `custom_content:ewp_fields`.
     * @param string $search      Search text.
     * @param array  $args        limit (20), search_meta (true), exclude (int[]).
     *
     * @return array|\WP_Error List of `{id, label, type}` results.
     *
     * @since 1.5.0
     */
    public function search($object_type, $search = '', array $args = [])
    {
        $object_type = (string) $object_type;
        if (strpos($object_type, ':') === false) {
            return new \WP_Error('awm_invalid_object_type', __('Invalid object type format.', 'extend-wp'), ['status' => 400]);
        }

        list($group, $slug) = explode(':', $object_type, 2);
        if (!in_array($group, self::GROUPS, true) || $slug === '') {
            return new \WP_Error('awm_invalid_object_type', __('Unsupported object type.', 'extend-wp'), ['status' => 400]);
        }

        if (!function_exists('awm_object_search_query')) {
            return new \WP_Error('awm_object_search_unavailable', __('Object search is not available on this site.', 'extend-wp'), ['status' => 503]);
        }

        $results = awm_object_search_query($group, $slug, (string) $search, [
            'limit'       => isset($args['limit']) ? (absint($args['limit']) ?: 20) : 20,
            'search_meta' => isset($args['search_meta']) ? (bool) $args['search_meta'] : true,
            'exclude'     => isset($args['exclude']) ? array_values(array_filter(array_map('absint', (array) $args['exclude']))) : [],
        ]);

        return is_array($results) ? $results : [];
    }

    /**
     * Capability required to search, filterable so the picker can be used
     * outside wp-admin.
     *
     * @return string
     *
     * @since 1.5.0
     */
    public static function capability()
    {
        /**
         * Filter the capability required to use the object search.
         *
         * @param string $capability Default manage_options.
         */
        return (string) apply_filters('awm_object_search_capability', 'manage_options');
    }
}
