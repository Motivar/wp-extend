<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic CRUD abilities for any registered custom content type.
 *
 * The content type is an input rather than part of the ability name, so a
 * content type registered by another plugin through `awm_register_content_db`
 * is reachable without registering new abilities for it.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Content_Provider extends EWP_Abilities_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-content';

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
            'label'       => __('EWP Custom Content', 'extend-wp'),
            'description' => __('Read and write rows of any content type registered with the Extend WP custom content database.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        $content_type = [
            'content_type' => [
                'type'        => 'string',
                'description' => __('The content type id, for example ewp_fields. Call list-content-types first to see what exists on this site.', 'extend-wp'),
            ],
        ];

        return [
            self::CATEGORY . '/list-content-types' => $this->definition(
                __('List custom content types', 'extend-wp'),
                __('List every custom content type registered on this site with its statuses, required capability and field keys. Call this first: it tells you which content_type values and meta keys the other content abilities accept, so you never have to guess. Only types the current user may access are returned.', 'extend-wp'),
                EWP_Abilities_Schema::input([]),
                $this->types_output_schema(),
                [$this, 'run_list_content_types'],
                $this->capability_permission('read', self::CATEGORY . '/list-content-types'),
                $this->meta_readonly()
            ),

            self::CATEGORY . '/list-items' => $this->definition(
                __('List custom content items', 'extend-wp'),
                __('List rows of one content type, newest first. Filter by status, title text or explicit ids. Leave with_meta off unless you need every stored value: titles and ids alone are much cheaper. Returns 50 items by default, 200 at most.', 'extend-wp'),
                EWP_Abilities_Schema::input(
                    array_merge($content_type, EWP_Abilities_Schema::list_properties()),
                    ['content_type']
                ),
                EWP_Abilities_Schema::row_collection(),
                [$this, 'run_list_items'],
                [$this, 'check_content_permission'],
                $this->meta_readonly()
            ),

            self::CATEGORY . '/get-item' => $this->definition(
                __('Get a custom content item', 'extend-wp'),
                __('Return one row of a content type by id, including every stored meta value. Use this after list-items when you need the full configuration of a single item.', 'extend-wp'),
                EWP_Abilities_Schema::input(
                    array_merge($content_type, ['id' => ['type' => 'integer', 'description' => __('The item id.', 'extend-wp')]]),
                    ['content_type', 'id']
                ),
                EWP_Abilities_Schema::row(),
                [$this, 'run_get_item'],
                [$this, 'check_content_permission'],
                $this->meta_readonly()
            ),

            self::CATEGORY . '/create-item' => $this->definition(
                __('Create a custom content item', 'extend-wp'),
                __('Create a new row of a content type. Meta keys must match the field keys reported by list-content-types, and every required field must be present. The status defaults to the content type first registered status.', 'extend-wp'),
                EWP_Abilities_Schema::input(
                    array_merge($content_type, $this->writable_properties()),
                    ['content_type', 'title']
                ),
                EWP_Abilities_Schema::row(),
                [$this, 'run_create_item'],
                [$this, 'check_content_permission'],
                $this->meta_write(false)
            ),

            self::CATEGORY . '/update-item' => $this->definition(
                __('Update a custom content item', 'extend-wp'),
                __('Update an existing row. This is a patch: only the meta keys you send are written, everything else is left untouched. Title and status keep their stored values when omitted.', 'extend-wp'),
                EWP_Abilities_Schema::input(
                    array_merge(
                        $content_type,
                        ['id' => ['type' => 'integer', 'description' => __('The item id.', 'extend-wp')]],
                        $this->writable_properties()
                    ),
                    ['content_type', 'id']
                ),
                EWP_Abilities_Schema::row(),
                [$this, 'run_update_item'],
                [$this, 'check_content_permission'],
                $this->meta_write(true)
            ),

            self::CATEGORY . '/delete-item' => $this->definition(
                __('Delete custom content items', 'extend-wp'),
                __('Permanently delete rows of a content type together with all of their meta. This cannot be undone, so confirm must be true. Deleting a field library or post type definition removes it from the site immediately.', 'extend-wp'),
                EWP_Abilities_Schema::input(
                    array_merge(
                        $content_type,
                        [
                            'ids' => [
                                'type'        => 'array',
                                'items'       => ['type' => 'integer'],
                                'description' => __('Ids of the items to delete.', 'extend-wp'),
                            ],
                        ],
                        $this->confirm_property()
                    ),
                    ['content_type', 'ids', 'confirm']
                ),
                $this->delete_output_schema(),
                [$this, 'run_delete_item'],
                [$this, 'check_content_permission'],
                $this->meta_destructive()
            ),
        ];
    }

    /**
     * Input properties shared by create and update.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function writable_properties()
    {
        return [
            'title'  => [
                'type'        => 'string',
                'description' => __('The item title, shown in the admin list.', 'extend-wp'),
            ],
            'status' => [
                'type'        => 'string',
                'description' => __('One of the statuses reported for this content type.', 'extend-wp'),
            ],
            'meta'   => [
                'type'                 => 'object',
                'additionalProperties' => true,
                'description'          => __('Meta values keyed by field key.', 'extend-wp'),
            ],
        ];
    }

    /**
     * Output schema for the content type inventory.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function types_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'types' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Output schema for a delete call.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function delete_output_schema()
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

    /* ---------------------------------------------------------------------
     * Permission
     * ------------------------------------------------------------------ */

    /**
     * Permission callback resolving the capability from the content type.
     *
     * @param mixed $input Ability input.
     *
     * @return true|\WP_Error
     *
     * @since 1.4.0
     */
    public function check_content_permission($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = isset($input['content_type']) ? (string) $input['content_type'] : '';

        /*
         * Core replaces a permission error with a generic message, so an
         * unknown content type is authorised against a low baseline here and
         * reported properly by the handler instead.
         */
        if ($content_type === '' || $this->service->get_config($content_type) === null) {
            return $this->user_can('edit_posts', self::CATEGORY);
        }

        return $this->user_can($this->service->get_capability($content_type), self::CATEGORY);
    }

    /**
     * Resolve and validate the content type from ability input.
     *
     * @param array $input Normalised input.
     *
     * @return string|\WP_Error
     *
     * @since 1.4.0
     */
    protected function resolve_content_type(array $input)
    {
        $content_type = isset($input['content_type']) ? (string) $input['content_type'] : '';

        if ($content_type === '') {
            return $this->error('ewp_abilities_missing_content_type', __('A content_type is required.', 'extend-wp'));
        }

        if ($this->service->get_config($content_type) === null) {
            return $this->error(
                'ewp_abilities_unknown_content_type',
                sprintf(
                    /* translators: %s: content type id. */
                    __('Unknown content type "%s". Use list-content-types to see the registered ones.', 'extend-wp'),
                    $content_type
                ),
                404
            );
        }

        if (!current_user_can($this->service->get_capability($content_type))) {
            return $this->error(
                'ewp_abilities_forbidden',
                sprintf(
                    /* translators: %s: content type id. */
                    __('You do not have permission to work with "%s".', 'extend-wp'),
                    $content_type
                ),
                403
            );
        }

        return $content_type;
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    /**
     * Describe every content type the current user may access.
     *
     * @param mixed $input Unused.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public function run_list_content_types($input = null)
    {
        $types = [];

        foreach ($this->service->list_types() as $content_type => $config) {
            $capability = isset($config['capability']) ? $config['capability'] : 'edit_posts';

            if (!current_user_can($capability)) {
                continue;
            }

            $types[] = [
                'content_type' => (string) $content_type,
                'label'        => isset($config['list_name']) ? (string) $config['list_name'] : (string) $content_type,
                'singular'     => isset($config['list_name_singular']) ? (string) $config['list_name_singular'] : '',
                'capability'   => (string) $capability,
                'statuses'     => $this->service->get_statuses($content_type),
                'fields'       => $this->service->describe_library($content_type),
            ];
        }

        return ['count' => count($types), 'types' => $types];
    }

    /**
     * List rows.
     *
     * @param mixed $input Ability input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    public function run_list_items($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = $this->resolve_content_type($input);

        if (is_wp_error($content_type)) {
            return $content_type;
        }

        return $this->service->list_items($content_type, $input);
    }

    /**
     * Return one row.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_get_item($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = $this->resolve_content_type($input);

        if (is_wp_error($content_type)) {
            return $content_type;
        }

        return $this->service->get_item($content_type, isset($input['id']) ? $input['id'] : 0);
    }

    /**
     * Create a row.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_create_item($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = $this->resolve_content_type($input);

        if (is_wp_error($content_type)) {
            return $content_type;
        }

        return $this->service->create_item(
            $content_type,
            isset($input['title']) ? $input['title'] : '',
            isset($input['status']) ? $input['status'] : '',
            $this->meta_from_input($input)
        );
    }

    /**
     * Update a row.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_update_item($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = $this->resolve_content_type($input);

        if (is_wp_error($content_type)) {
            return $content_type;
        }

        $patch = ['meta' => $this->meta_from_input($input)];

        if (array_key_exists('title', $input)) {
            $patch['title'] = $input['title'];
        }

        if (array_key_exists('status', $input)) {
            $patch['status'] = $input['status'];
        }

        return $this->service->update_item($content_type, isset($input['id']) ? $input['id'] : 0, $patch);
    }

    /**
     * Delete rows.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_delete_item($input = null)
    {
        $input        = $this->normalize_input($input);
        $content_type = $this->resolve_content_type($input);

        if (is_wp_error($content_type)) {
            return $content_type;
        }

        $confirmed = $this->require_confirm($input);

        if (is_wp_error($confirmed)) {
            return $confirmed;
        }

        return $this->service->delete_items($content_type, isset($input['ids']) ? (array) $input['ids'] : []);
    }

    /**
     * Extract the meta payload from ability input.
     *
     * @param array $input Normalised input.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function meta_from_input(array $input)
    {
        if (empty($input['meta'])) {
            return [];
        }

        $meta = is_object($input['meta']) ? get_object_vars($input['meta']) : $input['meta'];

        return is_array($meta) ? $meta : [];
    }
}
