<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abilities wrapping the options portability module.
 *
 * Exports and imports the values of Extend WP options pages, reusing the same
 * service the admin screen, the REST routes and WP-CLI use, so URL rewriting,
 * validation and backups behave identically.
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities_Options_Provider extends EWP_Abilities_Provider
{
    /**
     * Ability category slug.
     *
     * @var string
     */
    const CATEGORY = 'ewp-options';

    /**
     * Capability required for every options ability.
     *
     * @var string
     */
    const CAPABILITY = 'manage_options';

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
            'label'       => __('EWP Options Portability', 'extend-wp'),
            'description' => __('Export and import the values stored on Extend WP options pages.', 'extend-wp'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function get_definitions()
    {
        return [
            self::CATEGORY . '/list-pages' => $this->definition(
                __('List exportable options pages', 'extend-wp'),
                __('List every Extend WP options page that can be exported, with its title and how many fields it holds. Call this first to learn the page keys the export ability expects.', 'extend-wp'),
                EWP_Abilities_Schema::input([]),
                $this->pages_output_schema(),
                [$this, 'run_list_pages'],
                $this->capability_permission(self::CAPABILITY, self::CATEGORY . '/list-pages'),
                $this->meta_readonly()
            ),

            self::CATEGORY . '/export' => $this->definition(
                __('Export options pages', 'extend-wp'),
                __('Export the stored values of one or more options pages as a portable payload, including the site URLs so an import elsewhere can rewrite them. The payload contains raw option values, so treat it as sensitive.', 'extend-wp'),
                EWP_Abilities_Schema::input([
                    'page_keys' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => __('Page keys to export, as reported by list-pages.', 'extend-wp'),
                    ],
                ], ['page_keys']),
                ['type' => 'object', 'additionalProperties' => true],
                [$this, 'run_export'],
                $this->capability_permission(self::CAPABILITY, self::CATEGORY . '/export'),
                $this->meta_readonly()
            ),

            self::CATEGORY . '/import' => $this->definition(
                __('Import options pages', 'extend-wp'),
                __('Import an export payload back into this site, overwriting the stored option values. Runs as a dry run by default, which reports exactly what would change without writing anything. To write, set dry_run to false and confirm to true.', 'extend-wp'),
                EWP_Abilities_Schema::input([
                    'data'             => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                        'description'          => __('An export payload produced by the export ability.', 'extend-wp'),
                    ],
                    'dry_run'          => [
                        'type'        => 'boolean',
                        'description' => __('Report the changes without applying them. Defaults to true.', 'extend-wp'),
                    ],
                    'skip_url_replace' => [
                        'type'        => 'boolean',
                        'description' => __('Keep the URLs from the source site instead of rewriting them to this one.', 'extend-wp'),
                    ],
                    'confirm'          => [
                        'type'        => 'boolean',
                        'description' => __('Must be true when dry_run is false.', 'extend-wp'),
                    ],
                ], ['data']),
                ['type' => 'object', 'additionalProperties' => true],
                [$this, 'run_import'],
                $this->capability_permission(self::CAPABILITY, self::CATEGORY . '/import'),
                $this->meta_destructive(false)
            ),
        ];
    }

    /**
     * Output schema for the page inventory.
     *
     * @return array
     *
     * @since 1.4.0
     */
    protected function pages_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'pages' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'key'         => ['type' => 'string'],
                            'title'       => ['type' => 'string'],
                            'field_count' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * Return the portability service, when the module is loaded.
     *
     * @return \EWP_Options_Portability|\WP_Error
     *
     * @since 1.4.0
     */
    protected function portability()
    {
        if (!class_exists('EWP_Options_Portability')) {
            return $this->error(
                'ewp_abilities_options_unavailable',
                __('The options portability module is not available on this site.', 'extend-wp'),
                503
            );
        }

        return \EWP_Options_Portability::instance();
    }

    /* ---------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------ */

    /**
     * List the exportable options pages.
     *
     * @param mixed $input Unused.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_list_pages($input = null)
    {
        $portability = $this->portability();

        if (is_wp_error($portability)) {
            return $portability;
        }

        $pages = [];

        foreach ($portability->get_exportable_pages() as $key => $page) {
            $pages[] = [
                'key'         => (string) $key,
                'title'       => isset($page['title']) ? (string) $page['title'] : (string) $key,
                'field_count' => isset($page['field_count']) ? (int) $page['field_count'] : 0,
            ];
        }

        return ['count' => count($pages), 'pages' => $pages];
    }

    /**
     * Export one or more options pages.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_export($input = null)
    {
        $portability = $this->portability();

        if (is_wp_error($portability)) {
            return $portability;
        }

        $input     = $this->normalize_input($input);
        $page_keys = isset($input['page_keys']) ? array_map('strval', (array) $input['page_keys']) : [];

        if (empty($page_keys)) {
            return $this->error('ewp_abilities_no_pages', __('At least one page key is required.', 'extend-wp'));
        }

        return $portability->export_options($page_keys, 'ability');
    }

    /**
     * Import an export payload.
     *
     * @param mixed $input Ability input.
     *
     * @return array|\WP_Error
     *
     * @since 1.4.0
     */
    public function run_import($input = null)
    {
        $portability = $this->portability();

        if (is_wp_error($portability)) {
            return $portability;
        }

        $input   = $this->normalize_input($input);
        $dry_run = array_key_exists('dry_run', $input) ? (bool) $input['dry_run'] : true;

        if (!$dry_run) {
            $confirmed = $this->require_confirm($input);
            if (is_wp_error($confirmed)) {
                return $confirmed;
            }
        }

        $data = isset($input['data']) ? $input['data'] : [];
        $data = is_object($data) ? json_decode(wp_json_encode($data), true) : $data;

        if (empty($data) || !is_array($data)) {
            return $this->error('ewp_abilities_invalid_import_data', __('A valid export payload is required in data.', 'extend-wp'));
        }

        return $portability->import_options($data, [
            'dry_run'          => $dry_run,
            'skip_url_replace' => !empty($input['skip_url_replace']),
            'actor'            => 'ability',
        ]);
    }
}
