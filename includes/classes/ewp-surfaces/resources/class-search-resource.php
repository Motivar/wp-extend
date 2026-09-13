<?php

namespace EWP\Surfaces\Resources;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The UI-configured front-end search filters as `ewp-search/*`, read only.
 *
 * A search filter is rendered on the front end by a shortcode, and
 * building one from a description is far more error prone than reading
 * one, so creation stays in the admin UI (or the generic content resource).
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Search_Resource extends Typed_Content_Resource
{
    const CONTENT_TYPE = 'ewp_search';

    public function name()
    {
        return 'search';
    }

    public function ability_category()
    {
        return 'ewp-search';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Search Filters', 'extend-wp'),
            'description' => __('Read the front-end search filters configured with Extend WP, including their shortcode and REST endpoint.', 'extend-wp'),
        ];
    }

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
                    'list' => __('List the front-end search filters configured on this site. Each row includes the shortcode that renders it and the REST endpoint that serves its results.', 'extend-wp'),
                    'get'  => __('Return one search filter with its display fields, query configuration, shortcode and REST endpoint.', 'extend-wp'),
                ],
            ],
        ];
    }

    /**
     * Add the shortcode and REST endpoint to a row.
     *
     * {@inheritDoc}
     */
    protected function decorate_row(array $entity, array $row)
    {
        if (empty($row['id'])) {
            return $row;
        }

        $row['shortcode']     = sprintf('[ewp_search id="%d"]', (int) $row['id']);
        $row['rest_endpoint'] = rest_url('ewp-filter/' . (int) $row['id']);

        return $row;
    }
}
