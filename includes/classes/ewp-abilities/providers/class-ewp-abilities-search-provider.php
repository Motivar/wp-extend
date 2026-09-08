<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read abilities for the UI-configured front-end search filters.
 *
 * Read only: a search filter is rendered on the front end by a shortcode, and
 * building one from a description is far more error prone than reading one, so
 * creation stays in the admin UI. Use the generic ewp-content abilities if you
 * really need to write these rows.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Search_Provider extends EWP_Abilities_Typed_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-search';

    /**
     * Content type backing this provider.
     *
     * @var string
     */
    const CONTENT_TYPE = 'ewp_search';

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
            'label'       => __('EWP Search Filters', 'extend-wp'),
            'description' => __('Read the front-end search filters configured with Extend WP, including their shortcode and REST endpoint.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function entities()
    {
        return [
            [
                'content_type'   => self::CONTENT_TYPE,
                'singular'       => 'filter',
                'plural'         => 'filters',
                'label_singular' => __('search filter', 'extend-wp'),
                'label_plural'   => __('search filters', 'extend-wp'),
                'writable'       => false,
                'descriptions'   => [
                    'list'   => __('List the front-end search filters configured on this site. Each row includes the shortcode that renders it and the REST endpoint that serves its results.', 'extend-wp'),
                    'get'    => __('Return one search filter with its display fields, query configuration, shortcode and REST endpoint.', 'extend-wp'),
                ],
            ],
        ];
    }

    /**
     * Add the shortcode and REST endpoint to a row.
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
        unset($entity);

        if (empty($row['id'])) {
            return $row;
        }

        $row['shortcode']     = sprintf('[ewp_search id="%d"]', (int) $row['id']);
        $row['rest_endpoint'] = rest_url('ewp-filter/' . (int) $row['id']);

        return $row;
    }
}
