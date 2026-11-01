<?php

namespace EWP\Surfaces\Resources;

use EWP\Content\Config_Sync;
use MammothWP\WpKit\Context;
use MammothWP\WpKit\Field;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The automatic configuration sync (auto export / auto import files) on every surface:
 * inspect it, re-export, import now, and migrate from the single file to one file per object.
 *
 * REST lives under `ewp/v1/config-sync/*`, the CLI under `wp ewp content sync *`, abilities as
 * `ewp-content/sync-*`. Every operation needs `manage_options`.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.15.0
 */
final class Config_Sync_Resource extends Resource
{
    /** @var Config_Sync */
    private $service;

    /**
     * @param Config_Sync $service Configuration sync service.
     */
    public function __construct(Config_Sync $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'config-sync';
    }

    public function label()
    {
        return __('EWP Configuration Sync', 'extend-wp');
    }

    public function service()
    {
        return $this->service;
    }

    public function rest_namespace()
    {
        return 'ewp/v1';
    }

    public function rest_base()
    {
        return '/config-sync';
    }

    public function cli_base()
    {
        return 'ewp content';
    }

    public function ability_category()
    {
        return 'ewp-content';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Custom Content', 'extend-wp'),
            'description' => __('Read and write rows of any content type registered with the Extend WP custom content database.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        return 'manage_options';
    }

    public function operations()
    {
        return [
            'status' => Operation::read('status')
                ->label(__('Get configuration sync status', 'extend-wp'))
                ->description(__('Report how the automatic configuration sync (content exported to files on save, imported from files on admin load) is set up: the layout, the folder, how many per-object files exist, whether the legacy ewp_configuration.json is still there and the last import signature.', 'extend-wp'))
                ->output($this->status_schema())
                ->rest('GET', 'status')
                ->cli('sync status', ['default_format' => 'json'])
                ->ability('sync-status'),

            'export' => Operation::write('export')
                ->annotations(['idempotent' => true])
                ->label(__('Export configuration files', 'extend-wp'))
                ->description(__('Write every configured content type to the configuration folder now, in the configured layout (or the one given). The per object layout writes one deterministic JSON file per row and removes files of rows that no longer exist; the single layout rewrites ewp_configuration.json.', 'extend-wp'))
                ->input([
                    Field::enum('layout', [Config_Sync::LAYOUT_SINGLE, Config_Sync::LAYOUT_PER_OBJECT])->describe(__('Optional. single (one ewp_configuration.json) or per_object (one file per row under ewp-config/). Defaults to the configured layout.', 'extend-wp')),
                ])
                ->args([$this, 'export_args'])
                ->output($this->summary_schema(__('What was written.', 'extend-wp')))
                ->rest('POST', 'export')
                ->cli('sync export', ['default_format' => 'json'])
                ->ability('sync-export'),

            'migrate' => Operation::write('migrate')
                ->annotations(['destructive' => true])
                ->confirm([Context::ABILITY, Context::CLI])
                ->label(__('Migrate configuration to one file per object', 'extend-wp'))
                ->description(__('Write the ewp-config/ folder (one JSON file per row) from the database, rename ewp_configuration.json to ewp_configuration.legacy.json and store layout = per_object in the export settings. Importing sites must run a plugin version that reads ewp-config/ before this runs, otherwise they keep reading the stale single file. confirm must be true.', 'extend-wp'))
                ->output($this->summary_schema(__('Outcome of the migration.', 'extend-wp')))
                ->rest('POST', 'migrate')
                ->cli('sync migrate', ['default_format' => 'json'])
                ->ability('sync-migrate'),

            'import' => Operation::write('import')
                ->annotations(['destructive' => true])
                ->confirm([Context::ABILITY, Context::CLI])
                ->label(__('Import configuration files now', 'extend-wp'))
                ->description(__('Import the configuration folder (ewp-config/ or the legacy ewp_configuration.json) now instead of waiting for the next admin load. Rows are upserted by hash and rows this site imported before that the files no longer list are deleted, so confirm must be true.', 'extend-wp'))
                ->input([
                    Field::string('path')->describe(__('Optional. Folder relative to wp-content. Defaults to the configured import path.', 'extend-wp')),
                    Field::bool('force')->default_value(true)->describe(__('Optional, default true. Import even if the files did not change since the last import.', 'extend-wp')),
                ])
                ->args([$this, 'import_args'])
                ->output($this->summary_schema(__('What was imported.', 'extend-wp')))
                ->rest('POST', 'import')
                ->cli('sync import', ['default_format' => 'json'])
                ->ability('sync-import'),
        ];
    }

    /**
     * export(?array $options): the configured settings, with the requested layout when one is given.
     *
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array Arguments of Config_Sync::export().
     */
    public function export_args(array $input, Context $ctx)
    {
        $options = $this->service->export_settings(true);
        if (!empty($input['layout'])) {
            $options['layout'] = (string) $input['layout'];
        }

        return [$options];
    }

    /**
     * import(array $options): the given or configured import path.
     *
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array Arguments of Config_Sync::import().
     */
    public function import_args(array $input, Context $ctx)
    {
        $settings = $this->service->import_settings();
        $path     = !empty($input['path']) ? (string) $input['path'] : (isset($settings['path']) ? (string) $settings['path'] : '');

        return [['path' => $path, 'force' => !isset($input['force']) || !empty($input['force'])]];
    }

    /**
     * @return array JSON Schema of the status output.
     */
    private function status_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'layout'                => ['type' => 'string', 'description' => __('Configured layout: single or per_object.', 'extend-wp')],
                'auto_export'           => ['type' => 'boolean', 'description' => __('Whether auto export is active (stored setting honoured, or enabled by the filter).', 'extend-wp')],
                'dormant'               => ['type' => 'boolean', 'description' => __('True when a stored Auto export setting is ignored until the settings page is saved again.', 'extend-wp')],
                'types'                 => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => __('Content types covered by the export.', 'extend-wp')],
                'export_path'           => ['type' => 'string', 'description' => __('Absolute export folder, empty when none is configured.', 'extend-wp')],
                'import_path'           => ['type' => 'string', 'description' => __('Configured import folder, relative to wp-content.', 'extend-wp')],
                'import_enabled'        => ['type' => 'boolean', 'description' => __('Whether auto import is enabled.', 'extend-wp')],
                'per_object_files'      => ['type' => 'integer', 'description' => __('Row files under ewp-config/ in the export folder.', 'extend-wp')],
                'legacy_file'           => ['type' => 'boolean', 'description' => __('Whether ewp_configuration.json exists in the export folder.', 'extend-wp')],
                'legacy_backup'         => ['type' => 'boolean', 'description' => __('Whether ewp_configuration.legacy.json exists in the export folder.', 'extend-wp')],
                'last_import_signature' => ['type' => 'string', 'description' => __('Signature of the source imported last.', 'extend-wp')],
                'imported_hashes'       => ['type' => 'integer', 'description' => __('Rows this site imported last time (the only ones an import may delete).', 'extend-wp')],
            ],
            'additionalProperties' => true,
        ];
    }

    /**
     * @param string $description What the object describes.
     *
     * @return array Loose JSON Schema of a result summary.
     */
    private function summary_schema($description)
    {
        return ['type' => 'object', 'description' => $description, 'additionalProperties' => true];
    }
}
