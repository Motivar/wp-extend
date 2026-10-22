<?php

namespace EWP\Surfaces;

use EWP\Content\Content_Service;
use MammothWP\WpKit\Field;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field declarations and output schemas shared by the content resources.
 *
 * Declared once here so the generic `content` resource (CLI + abilities)
 * and the per-type REST resources describe the same parameters.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Content_Schema
{
    /* ---------------------------------------------------------------------
     * Output schemas (abilities validate output strictly)
     * ------------------------------------------------------------------ */

    /**
     * One normalised content row.
     *
     * @return array
     *
     * @since 1.5.0
     */
    public static function row()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'           => ['type' => 'integer'],
                'content_type' => ['type' => 'string'],
                'title'        => ['type' => ['string', 'null']],
                'status'       => ['type' => ['string', 'null']],
                'created'      => ['type' => ['string', 'null']],
                'modified'     => ['type' => ['string', 'null']],
                'user_id'      => ['type' => ['integer', 'null']],
                'hash'         => ['type' => ['string', 'null']],
                'meta'         => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * A list of rows plus its count.
     *
     * @return array
     *
     * @since 1.5.0
     */
    public static function row_collection()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'items' => ['type' => 'array', 'items' => self::row()],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * The content type inventory.
     *
     * @return array
     *
     * @since 1.5.0
     */
    public static function types()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'types' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * The result of a delete.
     *
     * @return array
     *
     * @since 1.5.0
     */
    public static function delete_result()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count'     => ['type' => 'integer'],
                'deleted'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                'not_found' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * The result of a view query: one page of rows plus the pager numbers.
     *
     * @return array
     *
     * @since 1.7.0
     */
    public static function query_result()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'items'       => ['type' => 'array', 'items' => self::row()],
                'count'       => ['type' => 'integer', 'description' => 'Rows on this page.'],
                'total'       => ['type' => 'integer', 'description' => 'Rows matching the view on every page.'],
                'total_pages' => ['type' => 'integer'],
                'page'        => ['type' => 'integer'],
                'per_page'    => ['type' => 'integer'],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * The result of a bulk status change.
     *
     * @return array
     *
     * @since 1.7.0
     */
    public static function status_result()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count'     => ['type' => 'integer'],
                'updated'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                'not_found' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'additionalProperties' => true,
        ];
    }

    /* ---------------------------------------------------------------------
     * Input fields
     * ------------------------------------------------------------------ */

    /**
     * A DataViews view and whether rows carry their meta.
     *
     * @return Field[]
     *
     * @since 1.7.0
     */
    public static function view_fields()
    {
        return [
            Field::object('view')->describe(__('A DataViews view: {"search":"text","filters":[{"field":"status","operator":"isAny","value":["enabled"]}],"sort":{"field":"created","direction":"desc"},"page":1,"perPage":20}. Fields are id, title, status, created, modified, user_id, extra columns and the meta keys reported by list-content-types; operators: is, isNot, isAny, isNone, isAll, isNotAll, lessThan, greaterThan, lessThanOrEqual, greaterThanOrEqual, before, after, beforeInc, afterInc, between, contains, notContains, startsWith. Only columns sort. perPage is at most 200. Omit for the first 20 rows, newest first.', 'extend-wp')),
            Field::bool('with_meta')->default_value(true)->describe(__('Include every meta value for each row (one extra query for the page). Defaults to true.', 'extend-wp')),
        ];
    }

    /**
     * Ids and status of a bulk status change.
     *
     * @param callable|null $statuses Returns the allowed statuses; null accepts any string (checked by the service).
     *
     * @return Field[]
     *
     * @since 1.7.0
     */
    public static function status_fields(?callable $statuses = null)
    {
        $status = $statuses !== null ? Field::enum('status', $statuses) : Field::string('status');

        return [
            Field::int_list('ids')->required()->describe(__('Ids of the items to change, comma separated (at most 200).', 'extend-wp')),
            $status->required()->describe(__('The new status; one of the statuses reported for this content type.', 'extend-wp')),
        ];
    }

    /**
     * The `content_type` selector of the generic resource (`--type` on the CLI).
     *
     * @param Content_Service $service Service used to validate the type exists.
     *
     * @return Field
     *
     * @since 1.5.0
     */
    public static function content_type(Content_Service $service)
    {
        return Field::string('content_type')
            ->required()
            ->cli_name('type')
            ->describe(__('The content type id, for example ewp_fields. Call list-content-types (wp ewp content types) first to see what exists on this site.', 'extend-wp'))
            ->validate_with(function ($value) use ($service) {
                if ($service->get_config((string) $value) !== null) {
                    return true;
                }

                return new \WP_Error(
                    'ewp_abilities_unknown_content_type',
                    sprintf(
                        /* translators: %s: content type id. */
                        __('Unknown content type "%s". Use list-content-types to see the registered ones.', 'extend-wp'),
                        (string) $value
                    ),
                    ['status' => 404]
                );
            });
    }

    /**
     * Filters accepted by a list operation.
     *
     * @param bool $with_meta_default Whether rows include meta unless told otherwise.
     *
     * @return Field[]
     *
     * @since 1.5.0
     */
    public static function list_fields($with_meta_default = false)
    {
        $with_meta = Field::bool('with_meta')
            ->describe(__('Include every meta value for each item. Heavier; omit when you only need titles and ids.', 'extend-wp'));

        return [
            Field::array('status')->items('string')->describe(__('Restrict to these statuses. Omit for every status.', 'extend-wp')),
            Field::string('search')->describe(__('Match against the item title.', 'extend-wp')),
            Field::int_list('include')->describe(__('Return only these item ids.', 'extend-wp')),
            Field::int('limit')->min(1)->max(Content_Service::MAX_LIMIT)
                ->describe(sprintf(
                    /* translators: 1: default limit, 2: maximum limit. */
                    __('How many items to return. Defaults to %1$d, maximum %2$d.', 'extend-wp'),
                    Content_Service::DEFAULT_LIMIT,
                    Content_Service::MAX_LIMIT
                )),
            Field::object('order_by')->properties([
                Field::string('column')->describe(__('Column to sort by, for example created or modified.', 'extend-wp')),
                Field::enum('type', ['asc', 'desc', 'ASC', 'DESC'])->describe(__('Sort direction.', 'extend-wp')),
            ])->additional_properties(false)->describe(__('Sort column and direction, for example {"column":"created","type":"desc"}.', 'extend-wp')),
            $with_meta_default ? $with_meta->default_value(true) : $with_meta,
        ];
    }

    /**
     * The item id, positional on the CLI.
     *
     * @return Field
     *
     * @since 1.5.0
     */
    public static function id()
    {
        return Field::int('id')->required()->min(1)->positional()->describe(__('The item id.', 'extend-wp'));
    }

    /**
     * The ids to delete, positional on the CLI, comma separated over REST.
     *
     * @return Field
     *
     * @since 1.5.0
     */
    public static function ids()
    {
        return Field::int_list('ids')->required()->positional()->describe(__('Ids of the items to delete.', 'extend-wp'));
    }

    /**
     * Title, status and meta for create and update.
     *
     * @param bool          $title_required Whether title is mandatory (create) or optional (update).
     * @param callable|null $statuses       Returns the allowed statuses; null accepts any string.
     *
     * @return Field[]
     *
     * @since 1.5.0
     */
    public static function writable_fields($title_required, ?callable $statuses = null)
    {
        $title  = Field::string('title')->describe(__('The item title, shown in the admin list.', 'extend-wp'));
        $status = $statuses !== null ? Field::enum('status', $statuses) : Field::string('status');

        return [
            $title_required ? $title->required() : $title,
            $status->describe(__('One of the statuses reported for this content type. Defaults to the first registered status on create.', 'extend-wp')),
            Field::object('meta')->describe(__('Meta values keyed by field key.', 'extend-wp')),
        ];
    }
}
