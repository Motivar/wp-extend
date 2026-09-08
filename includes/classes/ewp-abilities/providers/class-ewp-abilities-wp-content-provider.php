<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abilities for the post types and taxonomies registered through the UI.
 *
 * These rows drive `register_post_type()` and `register_taxonomy()` on the
 * next request, so creating one here really does add a post type to the site.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_WP_Content_Provider extends EWP_Abilities_Typed_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-wp-content';

    /**
     * Maximum length WordPress allows for a post type name.
     *
     * @var int
     */
    const MAX_POST_TYPE_LENGTH = 20;

    /**
     * Maximum length WordPress allows for a taxonomy name.
     *
     * @var int
     */
    const MAX_TAXONOMY_LENGTH = 32;

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
            'label'       => __('EWP Post Types & Taxonomies', 'extend-wp'),
            'description' => __('Create and inspect the post types and taxonomies that Extend WP registers with WordPress from its own configuration.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function entities()
    {
        return [
            [
                'content_type'   => 'ewp_post_types',
                'singular'       => 'post-type',
                'plural'         => 'post-types',
                'label_singular' => __('post type', 'extend-wp'),
                'label_plural'   => __('post types', 'extend-wp'),
                'writable'       => true,
                'name_key'       => 'post_name',
                'max_length'     => self::MAX_POST_TYPE_LENGTH,
                'descriptions'   => [
                    'list'   => __('List the post types Extend WP registers on this site. Each row includes the real registered name (prefix plus slug) and whether WordPress currently has it registered.', 'extend-wp'),
                    'get'    => __('Return one post type definition with every setting: labels, supported features, connected taxonomies, template sources, meta inheritance and role access.', 'extend-wp'),
                    'create' => __('Create a post type. post_name is the slug, and the registered name becomes prefix_post_name, which WordPress limits to 20 characters. plural and singular are the labels. args lists the WordPress supports features such as title, editor and thumbnail. The post type is registered on the next request.', 'extend-wp'),
                    'update' => __('Update a post type definition. Changing post_name or prefix changes the registered post type name, which orphans content already saved under the old name, so change those only on a new post type.', 'extend-wp'),
                    'delete' => __('Permanently delete post type definitions. WordPress stops registering the post type, and any posts stored under it become invisible in wp-admin although their rows remain. Requires confirm: true.', 'extend-wp'),
                ],
            ],
            [
                'content_type'   => 'ewp_taxonomies',
                'singular'       => 'taxonomy',
                'plural'         => 'taxonomies',
                'label_singular' => __('taxonomy', 'extend-wp'),
                'label_plural'   => __('taxonomies', 'extend-wp'),
                'writable'       => true,
                'name_key'       => 'taxonomy_name',
                'max_length'     => self::MAX_TAXONOMY_LENGTH,
                'descriptions'   => [
                    'list'   => __('List the taxonomies Extend WP registers on this site, with the real registered name and whether WordPress currently has it registered.', 'extend-wp'),
                    'get'    => __('Return one taxonomy definition with its labels, connected post types, template source and meta inheritance.', 'extend-wp'),
                    'create' => __('Create a taxonomy. taxonomy_name is the slug and the registered name becomes prefix_taxonomy_name, limited to 32 characters. name is the plural label and label the singular one. post_types lists the post types it applies to. The taxonomy is registered on the next request.', 'extend-wp'),
                    'update' => __('Update a taxonomy definition. Changing taxonomy_name or prefix changes the registered taxonomy name and orphans existing terms, so change those only on a new taxonomy.', 'extend-wp'),
                    'delete' => __('Permanently delete taxonomy definitions. WordPress stops registering the taxonomy and its terms become unreachable in wp-admin. Requires confirm: true.', 'extend-wp'),
                ],
            ],
        ];
    }

    /**
     * Validate the object name length and characters.
     *
     * @param array $entity    Entity descriptor.
     * @param array $meta      Meta payload.
     * @param bool  $is_create Whether this is a create call.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function validate_entity(array $entity, array $meta, $is_create)
    {
        $name_key = $entity['name_key'];

        if (!isset($meta[$name_key])) {
            return true;
        }

        $name = (string) $meta[$name_key];

        if ($name === '') {
            return $this->error(
                'ewp_abilities_invalid_object_name',
                sprintf(
                    /* translators: %s: meta key holding the slug. */
                    __('%s cannot be empty.', 'extend-wp'),
                    $name_key
                )
            );
        }

        if (!preg_match('/^[a-z0-9_\- ]+$/i', $name)) {
            return $this->error(
                'ewp_abilities_invalid_object_name',
                sprintf(
                    /* translators: %s: meta key holding the slug. */
                    __('%s may only contain letters, numbers, spaces, dashes and underscores.', 'extend-wp'),
                    $name_key
                )
            );
        }

        return $this->validate_registered_length($entity, $meta, $name);
    }

    /**
     * Ensure prefix plus slug fits inside the WordPress name limit.
     *
     * @param array  $entity Entity descriptor.
     * @param array  $meta   Meta payload.
     * @param string $name   Requested slug.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    protected function validate_registered_length(array $entity, array $meta, $name)
    {
        $prefix     = !empty($meta['prefix']) ? (string) $meta['prefix'] : 'ewp';
        $registered = $this->build_registered_name($prefix, $name);

        if (strlen($registered) <= $entity['max_length']) {
            return true;
        }

        return $this->error(
            'ewp_abilities_object_name_too_long',
            sprintf(
                /* translators: 1: resulting registered name, 2: character limit. */
                __('The resulting name "%1$s" is longer than the %2$d character limit WordPress allows. Use a shorter slug or prefix.', 'extend-wp'),
                $registered,
                (int) $entity['max_length']
            )
        );
    }

    /**
     * Build the name WordPress will actually register.
     *
     * @param string $prefix Configured prefix.
     * @param string $name   Configured slug.
     *
     * @return string
     *
     * @since 1.4.0
     */
    protected function build_registered_name($prefix, $name)
    {
        $clean = function_exists('awm_clean_string') ? awm_clean_string(strtolower($name)) : sanitize_key($name);

        return $prefix . '_' . $clean;
    }

    /**
     * Add the registered name and registration state to a row.
     *
     * @param array $entity Entity descriptor.
     * @param array $row    Normalised row.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function decorate_row(array $entity, array $row)
    {
        $meta = isset($row['meta']) ? (array) $row['meta'] : [];
        $name = isset($meta[$entity['name_key']]) ? (string) $meta[$entity['name_key']] : '';

        if ($name === '') {
            return $row;
        }

        $prefix     = !empty($meta['prefix']) ? (string) $meta['prefix'] : 'ewp';
        $registered = $this->build_registered_name($prefix, $name);

        $row['registered_name'] = $registered;
        $row['is_registered']   = $entity['content_type'] === 'ewp_post_types'
            ? post_type_exists($registered)
            : taxonomy_exists($registered);

        return $row;
    }
}
