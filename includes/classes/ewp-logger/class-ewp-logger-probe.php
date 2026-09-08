<?php

namespace EWP\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log-source discovery for external diagnostics (mtv-probe).
 *
 * Answers one question for a read-only collector running outside WordPress:
 * "where does this site log, in which format, and how do I correlate lines?".
 * The answer is built once by {@see EWP_Logger_Probe::get_sources()} and exposed on
 * every surface, all thin wrappers around that one method:
 *
 * - filter  `mtv_probe_log_sources` — other plugins (Filox, …) append their own
 *   sources; Extend WP contributes its activity-log files at priority 5.
 * - REST    `GET /extend-wp/v1/probe/sources`
 * - WP-CLI  `wp ewp probe sources [--format=<json|yaml|table>]`
 * - ability `ewp-logger/probe-sources` (read-only, registered with the other logger abilities)
 *
 * This module loads even when logging is disabled: the files written while it was
 * enabled still exist and the collector must be able to find them.
 *
 * Source entry contract (each key of the returned array is a source slug):
 *
 *     'extend-wp' => [
 *         'label'   => string,                       human label
 *         'format'  => 'ewp-jsonl',                  one JSON object per line, keys as written by EWP_Logger_File
 *         'files'   => [
 *             'dir'         => string,               absolute directory
 *             'pattern'     => 'ewp-log-{date}.log', file name pattern, {date} = date_format
 *             'date_format' => 'Y-m-d',
 *         ],
 *         'fields'  => [
 *             'timestamp'   => 'created_at',         key holding the time of the entry
 *             'timezone'    => string,               timezone of that timestamp (the site's)
 *             'level'       => 'behaviour',          key holding the severity
 *             'levels'      => ['0' => 'error', '1' => 'info', '2' => 'warning'],
 *             'channel'     => 'action_type',        key used as channel
 *             'owner'       => 'owner',              key naming the plugin that wrote the line
 *             'message'     => 'message',
 *             'context'     => 'request_context',    "METHOD /uri" of the request that logged
 *             'correlation' => ['request_id', 'object_id', 'user_id'],
 *             'payload'     => 'data',               PHP-serialised string; never exported verbatim
 *         ],
 *         'enabled'          => bool,                logging currently on
 *         'retention_months' => int,
 *         'owners'           => [slug => label],     registered log owners
 *         'action_types'     => [owner => [type => label]],
 *         'extra'            => [name => absolute path], other on-disk diagnostics (REST health)
 *     ]
 *
 * Other plugins add `channels` (action_type => channel), `correlation` (business keys such as
 * a booking id), `endpoints`, `tables` and `model` entries; see Filox_Probe for the reference.
 *
 * @package EWP\Logger
 * @since   1.6.0
 */
class EWP_Logger_Probe
{
    /** Filter name shared with mtv-probe and every MTV plugin. */
    const FILTER = 'mtv_probe_log_sources';

    /** REST namespace. */
    const REST_NAMESPACE = 'extend-wp/v1';

    /**
     * Register every surface.
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function init()
    {
        add_filter(self::FILTER, [$this, 'add_base_source'], 5);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('ewp_logger_ability_definitions', [$this, 'add_ability']);

        if (class_exists('WP_CLI')) {
            \WP_CLI::add_command('ewp probe sources', [$this, 'cli_sources'], [
                'shortdesc' => 'Print where this site logs (files, format, correlation keys) for mtv-probe.',
                'synopsis'  => [
                    [
                        'type'        => 'assoc',
                        'name'        => 'format',
                        'description' => 'Output format.',
                        'optional'    => true,
                        'default'     => 'json',
                        'options'     => ['json', 'yaml', 'table'],
                    ],
                ],
                'longdesc'  => "## EXAMPLES\n\n    wp ewp probe sources\n    wp ewp probe sources --format=table",
            ]);
        }
    }

    /**
     * The one implementation: every declared log source on this site.
     *
     * Runs the `mtv_probe_log_sources` filter, whose first callback (priority 5) is
     * {@see add_base_source()}. Plugins hooking later may add or amend entries.
     *
     * @return array<string,array> Sources keyed by slug (see the class docblock for the shape).
     *
     * @since 1.6.0
     */
    public static function get_sources()
    {
        /**
         * Declare log sources for external, read-only diagnostics (mtv-probe).
         *
         * @param array<string,array> $sources Sources keyed by slug.
         *
         * @since 1.6.0
         */
        $sources = apply_filters(self::FILTER, []);

        return is_array($sources) ? $sources : [];
    }

    /**
     * Filter callback (priority 5): Extend WP's own activity log.
     *
     * @param array $sources Sources declared so far.
     *
     * @return array
     *
     * @since 1.6.0
     */
    public function add_base_source($sources)
    {
        if (!is_array($sources)) {
            $sources = [];
        }

        $uploads = wp_upload_dir(null, false);
        $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

        /** This filter is defined in EWP_Logger_File; applying it here keeps one source of truth for the directory. */
        $dir      = apply_filters('ewp_logger_file_directory', trailingslashit($basedir) . 'ewp-logs');
        $settings = class_exists(__NAMESPACE__ . '\\EWP_Logger_Settings') ? EWP_Logger_Settings::get_settings() : [];
        $types    = [];

        foreach ((array) EWP_Logger::get_registered_types() as $owner => $owner_types) {
            foreach ((array) $owner_types as $key => $meta) {
                $types[$owner][$key] = is_array($meta) && isset($meta['label']) ? (string) $meta['label'] : (string) $key;
            }
        }

        $sources['extend-wp'] = [
            'label'   => apply_filters('ewp_whitelabel_filter', 'Extend WP') . ' activity log',
            'format'  => 'ewp-jsonl',
            'files'   => [
                'dir'         => (string) $dir,
                'pattern'     => 'ewp-log-{date}.log',
                'date_format' => 'Y-m-d',
            ],
            'fields'  => [
                'timestamp'   => 'created_at',
                'timezone'    => wp_timezone_string(),
                'level'       => 'behaviour',
                'levels'      => ['0' => 'error', '1' => 'info', '2' => 'warning'],
                'channel'     => 'action_type',
                'owner'       => 'owner',
                'message'     => 'message',
                'context'     => 'request_context',
                'correlation' => ['request_id', 'object_id', 'user_id'],
                'payload'     => 'data',
            ],
            'enabled'          => EWP_Logger::is_enabled(),
            'retention_months' => isset($settings['retention_months']) ? (int) $settings['retention_months'] : 6,
            'owners'           => (array) EWP_Logger::get_registered_owner_labels(),
            'action_types'     => $types,
            'extra'            => [
                'rest_health_payloads'    => trailingslashit($basedir) . 'ewp-rest-health/payloads.json',
                'rest_health_history_dir' => trailingslashit($basedir) . 'ewp-rest-health/history',
            ],
        ];

        return $sources;
    }

    /**
     * Register `GET /extend-wp/v1/probe/sources`.
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function register_routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/probe/sources', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'rest_sources'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [],
            ],
        ]);
    }

    /**
     * Permission: the logger viewer capability (filterable via `ewp_logger_viewer_capability`).
     *
     * @return bool|\WP_Error
     *
     * @since 1.6.0
     */
    public function check_permission()
    {
        if (!current_user_can(EWP_Logger::get_viewer_capability())) {
            return new \WP_Error('ewp_logger_forbidden', __('You do not have permission to view log sources.', 'extend-wp'), ['status' => 403]);
        }

        return true;
    }

    /**
     * REST wrapper.
     *
     * @param \WP_REST_Request $request Unused; the route takes no parameters.
     *
     * @return \WP_REST_Response `{ "sources": {...}, "generated_at": "Y-m-d H:i:s" }`
     *
     * @since 1.6.0
     */
    public function rest_sources($request)
    {
        return new \WP_REST_Response($this->envelope(), 200);
    }

    /**
     * WP-CLI wrapper: `wp ewp probe sources [--format=<json|yaml|table>]`.
     *
     * @param array $args       Positional arguments (none).
     * @param array $assoc_args `format` (string, optional, default `json`): json | yaml | table.
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function cli_sources($args, $assoc_args)
    {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'json';
        $data   = $this->envelope();

        if ($format === 'table') {
            $rows = [];
            foreach ($data['sources'] as $slug => $source) {
                $rows[] = [
                    'source'  => $slug,
                    'format'  => isset($source['format']) ? $source['format'] : '-',
                    'dir'     => isset($source['files']['dir']) ? $source['files']['dir'] : '-',
                    'enabled' => !empty($source['enabled']) ? 'yes' : 'no',
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['source', 'format', 'dir', 'enabled']);
            return;
        }

        \WP_CLI::print_value($data, ['format' => $format === 'yaml' ? 'yaml' : 'json']);
    }

    /**
     * Ability wrapper, appended to the logger ability table.
     *
     * @param array $definitions Ability definitions keyed by name.
     *
     * @return array
     *
     * @since 1.6.0
     */
    public function add_ability($definitions)
    {
        $definitions[EWP_Logger_Abilities::CATEGORY . '/probe-sources'] = [
            'label'               => __('List log sources', 'extend-wp'),
            'description'         => __('Where this site logs: directories, file patterns, line format, severity mapping and correlation keys for every plugin that declares a source (Extend WP, Filox, …). Call this before reading raw log files from outside WordPress. Cheap, no parameters.', 'extend-wp'),
            'category'            => EWP_Logger_Abilities::CATEGORY,
            'input_schema'        => ['type' => 'object', 'additionalProperties' => false, 'default' => []],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'sources'      => ['type' => 'object', 'description' => __('Sources keyed by slug; each has format, files{dir,pattern,date_format}, fields{timestamp,timezone,level,levels,channel,owner,correlation}, and plugin-specific channels/correlation/endpoints/model.', 'extend-wp')],
                    'generated_at' => ['type' => 'string', 'description' => __('Site-local time the answer was built (Y-m-d H:i:s).', 'extend-wp')],
                ],
            ],
            'execute_callback'    => [$this, 'run_ability'],
            'permission_callback' => [$this, 'check_permission'],
            'meta'                => ['annotations' => ['readonly' => true, 'idempotent' => true], 'show_in_rest' => true],
        ];

        return $definitions;
    }

    /**
     * Ability execute callback.
     *
     * @param array $input Unused.
     *
     * @return array
     *
     * @since 1.6.0
     */
    public function run_ability($input = [])
    {
        return $this->envelope();
    }

    /**
     * Common response shape for REST, CLI and the ability.
     *
     * @return array{sources:array,generated_at:string}
     */
    private function envelope()
    {
        return [
            'sources'      => self::get_sources(),
            'generated_at' => current_time('mysql'),
        ];
    }
}

// Bootstrapped from Setup.php right after the logger; the instance is stateless.
(new EWP_Logger_Probe())->init();
