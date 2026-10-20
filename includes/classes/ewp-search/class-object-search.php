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
     * @param string $search      Search text (ignored when `include` is set).
     * @param array  $args        limit (20), search_meta (true), exclude (int[]), include (int[]: fetch these ids instead of searching).
     *
     * @return array|\WP_Error List of `{id, label, type}` results.
     *
     * @since 1.5.0
     * @since 1.5.8 `include`; posts of another type and posts the logged-in user cannot read are left out.
     */
    public function search($object_type, $search = '', array $args = [])
    {
        $parsed = self::parse_object_type($object_type);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        list($group, $slug) = $parsed;

        if (!function_exists('awm_object_search_query')) {
            return new \WP_Error('awm_object_search_unavailable', __('Object search is not available on this site.', 'extend-wp'), ['status' => 503]);
        }

        $results = awm_object_search_query($group, $slug, (string) $search, [
            'limit'       => isset($args['limit']) ? (absint($args['limit']) ?: 20) : 20,
            'search_meta' => isset($args['search_meta']) ? (bool) $args['search_meta'] : true,
            'exclude'     => self::ids(isset($args['exclude']) ? $args['exclude'] : []),
            'include'     => self::ids(isset($args['include']) ? $args['include'] : []),
        ]);

        $results = is_array($results) ? $results : [];
        return $group === 'post_type' ? $this->readable_posts($results, $slug) : $results;
    }

    /**
     * Posts of the searched type that the current user may read. Without a
     * logged-in user (unattended WP-CLI) every result of the type is kept.
     *
     * @param array  $results Results of awm_object_search_query().
     * @param string $slug    Post type searched.
     * @return array
     */
    private function readable_posts(array $results, $slug)
    {
        $check_user = function_exists('is_user_logged_in') && is_user_logged_in();
        return array_values(array_filter($results, function ($result) use ($slug, $check_user) {
            $id = isset($result['id']) ? (int) $result['id'] : 0;
            if (get_post_type($id) !== $slug) {
                return false;
            }
            return !$check_user || current_user_can('read_post', $id);
        }));
    }

    /**
     * Split and validate `{group}:{slug}`.
     *
     * @param string $object_type Object type.
     * @return array{0: string, 1: string}|\WP_Error
     *
     * @since 1.5.8
     */
    public static function parse_object_type($object_type)
    {
        $object_type = (string) $object_type;
        if (strpos($object_type, ':') === false) {
            return new \WP_Error('awm_invalid_object_type', __('Invalid object type format.', 'extend-wp'), ['status' => 400]);
        }

        list($group, $slug) = explode(':', $object_type, 2);
        if (!in_array($group, self::GROUPS, true) || $slug === '') {
            return new \WP_Error('awm_invalid_object_type', __('Unsupported object type.', 'extend-wp'), ['status' => 400]);
        }
        return [$group, $slug];
    }

    /**
     * Positive integer ids from a list or a comma-separated string.
     *
     * @param array|string $ids Ids.
     * @return int[]
     */
    private static function ids($ids)
    {
        $ids = is_string($ids) ? explode(',', $ids) : (array) $ids;
        return array_values(array_filter(array_map('absint', $ids)));
    }

    /**
     * Capability required to search an object type, filterable so the picker
     * can be used outside wp-admin.
     *
     * A post type asks for that type's `edit_posts` capability and a taxonomy
     * for its `assign_terms`, so authors and editors can use the block
     * editor's search control on the content they can edit. Custom content and
     * unknown types keep `manage_options`.
     *
     * @param string $object_type Optional. `{group}:{slug}` being searched. Default ''.
     * @return string
     *
     * @since 1.5.0
     * @since 1.5.8 Per object type; `$object_type` passed to the filter.
     */
    public static function capability($object_type = '')
    {
        /**
         * Filter the capability required to use the object search.
         *
         * @since 1.5.0
         * @since 1.5.8 Default depends on the object type; `$object_type` added.
         *
         * @param string $capability  Capability (post type `edit_posts`, taxonomy `assign_terms`, else `manage_options`).
         * @param string $object_type `{group}:{slug}` being searched, '' when unknown.
         */
        return (string) apply_filters('awm_object_search_capability', self::default_capability($object_type), (string) $object_type);
    }

    /**
     * The capability an object type asks for before the filter.
     *
     * @param string $object_type `{group}:{slug}`.
     * @return string
     */
    private static function default_capability($object_type)
    {
        $parsed = self::parse_object_type($object_type);
        if (is_wp_error($parsed)) {
            return 'manage_options';
        }
        list($group, $slug) = $parsed;
        if ($group === 'post_type' && ($object = get_post_type_object($slug))) {
            return $object->cap->edit_posts;
        }
        if ($group === 'taxonomy' && ($taxonomy = get_taxonomy($slug))) {
            return $taxonomy->cap->assign_terms;
        }
        return 'manage_options';
    }
}
