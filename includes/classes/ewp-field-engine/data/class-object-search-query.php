<?php

namespace EWP\Fields\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lookup of `object_id_filter` objects: the implementation behind
 * awm_object_search_query(), used by the REST search endpoint and the
 * server-side preload of selected options.
 *
 * With `include` it fetches exactly those ids (preload); otherwise it searches
 * by title/name (and meta when `search_meta` is true).
 *
 * @package EWP\Fields\Data
 * @since 1.11.0
 */
final class Object_Search_Query
{
    /**
     * Run the search.
     *
     * @param string $group  post_type|taxonomy|custom_content.
     * @param string $slug   The object slug (page, category, a registered content type).
     * @param string $search Search term (ignored when `include` is set).
     * @param array  $args   limit (20), search_meta (true), exclude (ids), include (ids).
     * @return array List of array('id' => int, 'label' => string, 'type' => "{group}:{slug}").
     */
    public static function search($group, $slug, $search, $args = array())
    {
        $defaults = array(
            'limit'       => 20,
            'search_meta' => true,
            'exclude'     => array(),
            'include'     => array(),
        );
        $args = array_merge($defaults, $args);

        /**
         * Filter the arguments of an object search before it runs.
         *
         * @since 1.5.8
         *
         * @param array  $args   Query arguments (limit, …).
         * @param string $group  Object group: post_type|taxonomy|user|custom_content.
         * @param string $slug   Post type, taxonomy or content type.
         * @param string $search Search text.
         */
        $args = apply_filters('awm_object_search_query_args', $args, $group, $slug, $search);
        /**
         * Filter the largest number of results one object search may return.
         *
         * @since 1.10.0
         *
         * @param int $max Maximum `limit` (and `include` ids). Default 100.
         */
        $max = max(1, (int) apply_filters('awm_object_search_max_limit', 100));
        $p = array(
            'search'      => $search,
            'limit'       => min($max, max(1, absint($args['limit']))),
            'exclude'     => array_filter(array_map('absint', (array) $args['exclude'])),
            'include'     => array_slice(array_filter(array_map('absint', (array) $args['include'])), 0, $max),
            /*an empty term would make the meta LIKE '%%' scan the whole meta table*/
            'search_meta' => (bool) $args['search_meta'] && $search !== '',
            'type'        => $group . ':' . $slug,
        );

        switch ($group) {
            case 'post_type':
                $results = self::post_type($slug, $p);
                break;
            case 'taxonomy':
                $results = self::taxonomy($slug, $p);
                break;
            case 'custom_content':
                $results = self::custom_content($slug, $p);
                break;
            default:
                $results = array();
                break;
        }

        /**
         * Filter object search results.
         *
         * @since 1.5.8
         *
         * @param array  $results Results (id, label).
         * @param string $group   Object group.
         * @param string $slug    Post type, taxonomy or content type.
         * @param string $search  Search text.
         * @param array  $args    Query arguments.
         */
        return apply_filters('awm_object_search_results', $results, $group, $slug, $search, $args);
    }

    /**
     * Posts of a post type.
     *
     * @param string $slug Post type.
     * @param array  $p    Normalised parameters (search, limit, exclude, include, search_meta, type).
     * @return array Results.
     */
    private static function post_type($slug, $p)
    {
        if (!post_type_exists($slug)) {
            return array();
        }
        $ids = !empty($p['include']) ? $p['include'] : self::post_ids($slug, $p);
        /*one query for every title instead of one per id*/
        _prime_post_caches(array_map('absint', $ids), false, false);
        $results = array();
        foreach ($ids as $pid) {
            $title = get_the_title($pid);
            $results[] = array(
                'id'    => absint($pid),
                'label' => $title !== '' ? $title : sprintf(__('#%d (no title)', 'extend-wp'), $pid),
                'type'  => $p['type'],
            );
        }
        return $results;
    }

    /**
     * Post ids matching a search by title/content and, optionally, meta.
     *
     * @param string $slug Post type.
     * @param array  $p    Normalised parameters.
     * @return int[] Ids, at most `limit`.
     */
    private static function post_ids($slug, $p)
    {
        global $wpdb;
        $limit = $p['limit'];
        $ids = get_posts(array(
            'post_type'        => $slug,
            's'                => $p['search'],
            'posts_per_page'   => $limit,
            'post__not_in'     => $p['exclude'],
            'fields'           => 'ids',
            'post_status'      => 'any',
            'suppress_filters' => false,
        ));
        if ($p['search_meta'] && count($ids) < $limit) {
            $like     = '%' . $wpdb->esc_like($p['search']) . '%';
            $meta_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                             WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')
                             AND pm.meta_value LIKE %s LIMIT %d",
                $slug,
                $like,
                $limit
            ));
            $ids = array_merge($ids, array_map('absint', $meta_ids));
        }
        $ids = array_diff(array_unique($ids), $p['exclude']);
        return array_slice($ids, 0, $limit);
    }

    /**
     * Terms of a taxonomy.
     *
     * @param string $slug Taxonomy.
     * @param array  $p    Normalised parameters.
     * @return array Results.
     */
    private static function taxonomy($slug, $p)
    {
        if (!taxonomy_exists($slug)) {
            return array();
        }
        $term_args = array(
            'taxonomy'   => $slug,
            'hide_empty' => false,
            'number'     => $p['limit'],
            'exclude'    => $p['exclude'],
        );
        if (!empty($p['include'])) {
            $term_args['include'] = $p['include'];
            unset($term_args['exclude']);
        } else {
            $term_args['search'] = $p['search'];
        }
        $results = array();
        $terms   = get_terms($term_args);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $results[absint($term->term_id)] = array(
                    'id'    => absint($term->term_id),
                    'label' => $term->name,
                    'type'  => $p['type'],
                );
            }
        }
        if (empty($p['include']) && $p['search_meta'] && count($results) < $p['limit']) {
            $results = self::add_terms_by_meta($results, $slug, $p);
        }
        return array_slice(array_values($results), 0, $p['limit']);
    }

    /**
     * Add the terms whose meta value matches the search.
     *
     * @param array  $results Results keyed by term id.
     * @param string $slug    Taxonomy.
     * @param array  $p       Normalised parameters.
     * @return array Results keyed by term id.
     */
    private static function add_terms_by_meta($results, $slug, $p)
    {
        global $wpdb;
        $like     = '%' . $wpdb->esc_like($p['search']) . '%';
        $meta_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tm.term_id FROM {$wpdb->termmeta} tm
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                         WHERE tt.taxonomy = %s AND tm.meta_value LIKE %s LIMIT %d",
            $slug,
            $like,
            $p['limit']
        ));
        foreach ($meta_ids as $tid) {
            $tid = absint($tid);
            if (isset($results[$tid]) || in_array($tid, $p['exclude'], true)) {
                continue;
            }
            $term = get_term($tid, $slug);
            if ($term && !is_wp_error($term)) {
                $results[$tid] = array('id' => $tid, 'label' => $term->name, 'type' => $p['type']);
            }
        }
        return $results;
    }

    /**
     * Rows of a registered custom content type.
     *
     * @param string $slug Content type (becomes a table name: registered types only).
     * @param array  $p    Normalised parameters.
     * @return array Results.
     */
    private static function custom_content($slug, $p)
    {
        /*the slug becomes a table name below: registered content types only*/
        if (!function_exists('awm_get_db_content') || $slug !== sanitize_key($slug) || !class_exists('AWM_Add_Content_DB_Setup') || !isset(\AWM_Add_Content_DB_Setup::$ewp_data_configuration[$slug])) {
            return array();
        }
        $rows = !empty($p['include']) ? awm_get_db_content($slug, array('include' => $p['include'])) : self::content_rows($slug, $p);
        $results = array();
        $seen    = array();
        foreach ((array) $rows as $row) {
            $cid = isset($row['content_id']) ? absint($row['content_id']) : 0;
            if (!$cid || isset($seen[$cid]) || in_array($cid, $p['exclude'], true)) {
                continue;
            }
            $seen[$cid] = true;
            $label = isset($row['content_title']) && $row['content_title'] !== '' ? $row['content_title'] : sprintf(__('#%d', 'extend-wp'), $cid);
            $results[] = array('id' => $cid, 'label' => $label, 'type' => $p['type']);
        }
        return array_slice($results, 0, $p['limit']);
    }

    /**
     * Rows matching a search by title and, optionally, meta value.
     *
     * @param string $slug Registered content type.
     * @param array  $p    Normalised parameters.
     * @return array Content rows.
     */
    private static function content_rows($slug, $p)
    {
        global $wpdb;
        $limit = $p['limit'];
        $rows  = (array) awm_get_db_content($slug, array('title' => $p['search'], 'limit' => $limit));
        if (!$p['search_meta'] || count($rows) >= $limit) {
            return $rows;
        }
        $like     = '%' . $wpdb->esc_like($p['search']) . '%';
        $meta_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT content_id FROM %i WHERE meta_value LIKE %s LIMIT %d",
            $wpdb->prefix . $slug . '_data',
            $like,
            $limit
        ));
        $meta_ids = array_filter(array_map('absint', (array) $meta_ids));
        if (empty($meta_ids)) {
            return $rows;
        }
        return array_merge($rows, (array) awm_get_db_content($slug, array('include' => $meta_ids)));
    }
}
