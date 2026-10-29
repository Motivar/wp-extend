<?php

namespace EWP\Fields\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration and type options of an `object_id_filter` field: the
 * implementation behind awm_object_id_filter_config() and
 * awm_get_object_type_options().
 *
 * @package EWP\Fields\Data
 * @since 1.11.0
 */
final class Object_Types
{
    /** @var array[] Per-request cache of built options, keyed by config hash. */
    private static $cache = array();

    /**
     * Normalise the configuration of an `object_id_filter` field.
     *
     * @param array  $a             The field definition.
     * @param string $original_meta The meta key the field is registered under.
     * @return array allowed_types, post_types, taxonomies, custom_content, id_field_name, min_search_chars, max_results, search_meta.
     */
    public static function config($a, $original_meta)
    {
        return array(
            'allowed_types'    => isset($a['allowed_types']) && is_array($a['allowed_types']) ? $a['allowed_types'] : array('post_types', 'taxonomies', 'custom_content'),
            'post_types'       => isset($a['post_types']) && is_array($a['post_types']) ? $a['post_types'] : array(),
            'taxonomies'       => isset($a['taxonomies']) && is_array($a['taxonomies']) ? $a['taxonomies'] : array(),
            'custom_content'   => isset($a['custom_content']) && is_array($a['custom_content']) ? $a['custom_content'] : array(),
            'id_field_name'    => isset($a['id_field_name']) && !empty($a['id_field_name']) ? $a['id_field_name'] : $original_meta . '_ids',
            'min_search_chars' => isset($a['min_search_chars']) ? absint($a['min_search_chars']) : 2,
            'max_results'      => isset($a['max_results']) ? absint($a['max_results']) : 20,
            'search_meta'      => isset($a['search_meta']) ? (bool) $a['search_meta'] : true,
        );
    }

    /**
     * Optgroup option structure for the type selector, filtered.
     *
     * Options are keyed by `"{group}:{slug}"` plus an `optgroups` definition.
     * The built options are cached per config for the request; the filters run
     * on every call.
     *
     * @param array  $config     Normalised config (see config()).
     * @param string $field_name Field name, used for the per-field filter hook.
     * @return array Options including an `optgroups` entry.
     */
    public static function options($config, $field_name = '')
    {
        $cache_key = md5(serialize($config));
        if (!isset(self::$cache[$cache_key])) {
            self::$cache[$cache_key] = self::build($config);
        }
        $options = self::$cache[$cache_key];

        /**
         * Filter the options of an object-type select (posts, terms, users, content rows).
         *
         * @since 1.0.0
         *
         * @param array  $options    Value => label.
         * @param array  $config     Field configuration.
         * @param string $field_name Field name, may be empty.
         */
        $options = apply_filters('awm_object_type_options', $options, $config, $field_name);
        if (empty($field_name)) {
            return $options;
        }
        /**
         * Filter the options of one named object-type select (`awm_object_type_options_{$field_name}`).
         *
         * @since 1.0.0
         *
         * @param array $options Value => label.
         * @param array $config  Field configuration.
         */
        return apply_filters('awm_object_type_options_' . $field_name, $options, $config);
    }

    /**
     * Forget the per-request option cache (tests).
     *
     * @return void
     */
    public static function reset()
    {
        self::$cache = array();
    }

    /**
     * Build the unfiltered options and optgroups for a config.
     *
     * @param array $config Normalised config.
     * @return array Options including `optgroups`.
     */
    private static function build($config)
    {
        $allowed   = $config['allowed_types'];
        $options   = array();
        $optgroups = array();

        if (in_array('post_types', $allowed, true)) {
            $optgroups['post_type'] = array('label' => __('Post Types', 'extend-wp'));
            $options = array_merge($options, self::objects_options('post_type', get_post_types(array('public' => true), 'objects'), $config['post_types']));
        }

        if (in_array('taxonomies', $allowed, true)) {
            $optgroups['taxonomy'] = array('label' => __('Taxonomies', 'extend-wp'));
            $options = array_merge($options, self::objects_options('taxonomy', get_taxonomies(array('public' => true), 'objects'), $config['taxonomies']));
        }

        if (in_array('custom_content', $allowed, true) && class_exists('AWM_Content_DB')) {
            $optgroups['custom_content'] = array('label' => __('Custom Content', 'extend-wp'));
            $options = array_merge($options, self::custom_content_options($config['custom_content']));
        }

        $options['optgroups'] = self::drop_empty_groups($optgroups, $options);
        return $options;
    }

    /**
     * Options for post types or taxonomies (objects with `labels->singular_name`).
     *
     * @param string   $group   post_type|taxonomy.
     * @param object[] $objects Slug => object.
     * @param string[] $only    Restrict to these slugs (empty: all).
     * @return array Option key => array(label, optgroup).
     */
    private static function objects_options($group, $objects, $only)
    {
        $options = array();
        foreach ($objects as $slug => $obj) {
            if (!empty($only) && !in_array($slug, $only, true)) {
                continue;
            }
            $options[$group . ':' . $slug] = array(
                'label'    => sprintf('%s (%s)', $obj->labels->singular_name, $slug),
                'optgroup' => $group,
            );
        }
        return $options;
    }

    /**
     * Options for the registered custom content types.
     *
     * @param string[] $only Restrict to these slugs (empty: all).
     * @return array Option key => array(label, optgroup).
     */
    private static function custom_content_options($only)
    {
        $options       = array();
        $content_types = \AWM_Content_DB::get_instance()->get_content_types();
        if (!is_array($content_types)) {
            return $options;
        }
        foreach ($content_types as $slug => $data) {
            if (!empty($only) && !in_array($slug, $only, true)) {
                continue;
            }
            $label = is_array($data) && isset($data['label']) ? $data['label'] : $slug;
            $options['custom_content:' . $slug] = array(
                'label'    => sprintf('%s (%s)', $label, $slug),
                'optgroup' => 'custom_content',
            );
        }
        return $options;
    }

    /**
     * Drop optgroups that have no option, so the selector stays tidy.
     *
     * @param array $optgroups Group id => definition.
     * @param array $options   Built options.
     * @return array The remaining optgroups.
     */
    private static function drop_empty_groups($optgroups, $options)
    {
        foreach (array_keys($optgroups) as $group_id) {
            if (!self::group_has_option($options, $group_id)) {
                unset($optgroups[$group_id]);
            }
        }
        return $optgroups;
    }

    /**
     * Whether an option belongs to the group.
     *
     * @param array  $options  Built options.
     * @param string $group_id Group id.
     * @return bool
     */
    private static function group_has_option($options, $group_id)
    {
        foreach ($options as $opt) {
            if (isset($opt['optgroup']) && $opt['optgroup'] === $group_id) {
                return true;
            }
        }
        return false;
    }
}
