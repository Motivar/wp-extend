<?php

namespace EWP\Surfaces\Resources;

use Motivar\WP\Context;
use Motivar\WP\Field;
use Motivar\WP\Operation;
use Motivar\WP\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options portability on every surface, over EWP_Options_Portability.
 *
 * `extend-wp/v1/options-portability/*`, `wp ewp options *` and
 * `ewp-options/*` all call the same export_options() / import_options().
 * Surface differences (the admin form's `option_pages[]` field name, the
 * CLI's `<file>` argument and `--file` output, a dry run by default for
 * abilities) live in the argument mappers and presenters here.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Options_Resource extends Resource
{
    public function name()
    {
        return 'options';
    }

    public function label()
    {
        return __('EWP Options Portability', 'extend-wp');
    }

    public function service()
    {
        return function () {
            return class_exists('EWP_Options_Portability') ? \EWP_Options_Portability::instance() : null;
        };
    }

    public function rest_namespace()
    {
        return 'extend-wp/v1';
    }

    public function rest_base()
    {
        return '/options-portability';
    }

    public function cli_base()
    {
        return 'ewp options';
    }

    public function ability_category()
    {
        return 'ewp-options';
    }

    public function ability_category_args()
    {
        return [
            'label'       => __('EWP Options Portability', 'extend-wp'),
            'description' => __('Export and import the values stored on Extend WP options pages.', 'extend-wp'),
        ];
    }

    public function capability()
    {
        return 'manage_options';
    }

    public function operations()
    {
        return [
            'pages'  => Operation::read('get_exportable_pages')
                ->label(__('List exportable options pages', 'extend-wp'))
                ->description(__('List every Extend WP options page that can be exported, with its title and how many fields it holds. Call this first to learn the page keys the export ability expects.', 'extend-wp'))
                ->transform([$this, 'pages_transform'])
                ->output($this->pages_output_schema())
                ->rest('GET', 'pages')
                ->cli('list', ['presenter' => [$this, 'present_pages']])
                ->ability('list-pages'),

            'export' => Operation::read('export_options')
                ->label(__('Export options pages', 'extend-wp'))
                ->description(__('Export the stored values of one or more options pages as a portable payload, including the site URLs so an import elsewhere can rewrite them. The payload contains raw option values, so treat it as sensitive. Omit page_keys on the command line to export every page.', 'extend-wp'))
                ->input([
                    Field::array('page_keys')->items('string')->cli_name('pages')->describe(__('Page keys to export, as reported by list-pages.', 'extend-wp')),
                    Field::string('file')->describe(__('Command line only: write the payload to this path (default ./ewp-options-export.json).', 'extend-wp')),
                ])
                ->allow_extra()
                ->args([$this, 'export_args'])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('GET', 'export')
                ->cli('export', ['presenter' => [$this, 'present_export']])
                ->ability('export'),

            'import' => Operation::write('import_options')
                ->annotations(['destructive' => true])
                ->label(__('Import options pages', 'extend-wp'))
                ->description(__('Import an export payload back into this site, overwriting the stored option values. For abilities this runs as a dry run by default, which reports exactly what would change without writing anything; to write, set dry_run to false and confirm to true. On the command line pass the export file as the first argument.', 'extend-wp'))
                ->input([
                    Field::object('data')->describe(__('An export payload produced by the export operation (a JSON string is accepted).', 'extend-wp')),
                    Field::string('file')->positional()->describe(__('Command line only: path to the JSON export file.', 'extend-wp')),
                    Field::bool('dry_run')->describe(__('Report the changes without applying them. Abilities default to true; REST and the CLI default to false.', 'extend-wp')),
                    Field::bool('skip_url_replace')->describe(__('Keep the URLs from the source site instead of rewriting them to this one.', 'extend-wp')),
                    Field::string('backup_file')->describe(__('Server path to write a JSON backup of the affected options before importing.', 'extend-wp')),
                    Field::bool('confirm')->describe(__('Must be true when dry_run is false on the ability surface.', 'extend-wp')),
                ])
                ->args([$this, 'import_args'])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('POST', 'import')
                ->cli('import', ['presenter' => [$this, 'present_import']])
                ->ability('import'),
        ];
    }

    /* ---------------------------------------------------------------------
     * Argument mappers and transforms
     * ------------------------------------------------------------------ */

    /**
     * REST keeps its `{key: {title, field_count}}` map; abilities get
     * `{count, pages}`; the CLI presenter reads the raw map.
     *
     * @param array   $pages Raw exportable pages.
     * @param array   $input Input.
     * @param Context $ctx   Invocation context.
     *
     * @return array
     */
    public function pages_transform($pages, array $input, Context $ctx)
    {
        if (!is_array($pages)) {
            return $pages;
        }

        if ($ctx->surface() === Context::REST) {
            $result = [];
            foreach ($pages as $key => $page) {
                $result[$key] = ['title' => $page['title'], 'field_count' => count($page['fields'])];
            }
            return $result;
        }

        if ($ctx->surface() === Context::CLI) {
            return $pages;
        }

        $list = [];
        foreach ($pages as $key => $page) {
            $list[] = [
                'key'         => (string) $key,
                'title'       => isset($page['title']) ? (string) $page['title'] : (string) $key,
                'field_count' => isset($page['field_count']) ? (int) $page['field_count'] : count($page['fields']),
            ];
        }

        return ['count' => count($list), 'pages' => $list];
    }

    /**
     * Page keys from `page_keys`, or the admin form's `pages` /
     * `option_pages[]` / `option_pages`; the CLI defaults to every page.
     *
     * @param array   $input Normalised input (extra keys kept).
     * @param Context $ctx   Invocation context.
     *
     * @return array|\WP_Error export_options($page_keys, $actor)
     */
    public function export_args(array $input, Context $ctx)
    {
        $keys = [];
        foreach (['page_keys', 'pages', 'option_pages[]', 'option_pages'] as $candidate) {
            if (!empty($input[$candidate])) {
                $keys = is_array($input[$candidate]) ? $input[$candidate] : explode(',', (string) $input[$candidate]);
                break;
            }
        }
        $keys = array_values(array_filter(array_map('trim', array_map('strval', $keys))));

        if ($keys === [] && $ctx->surface() === Context::CLI) {
            $keys = array_keys($this->service_instance()->get_exportable_pages());
        }

        if ($keys === []) {
            return new \WP_Error('missing_pages', __('Please select at least one option page.', 'extend-wp'), ['status' => 400]);
        }

        return [$keys, $ctx->surface()];
    }

    /**
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array|\WP_Error import_options($data, $opts)
     */
    public function import_args(array $input, Context $ctx)
    {
        $data = isset($input['data']) ? $input['data'] : null;

        if ($ctx->surface() === Context::CLI && !empty($input['file'])) {
            $data = $this->read_export_file((string) $input['file']);
            if ($data instanceof \WP_Error) {
                return $data;
            }
        }

        if (!is_array($data) || $data === []) {
            return new \WP_Error('ewp_abilities_invalid_import_data', __('A valid export payload is required in data.', 'extend-wp'), ['status' => 400]);
        }

        $dry_run = array_key_exists('dry_run', $input) ? (bool) $input['dry_run'] : $ctx->surface() === Context::ABILITY;

        if ($ctx->surface() === Context::ABILITY && !$dry_run && empty($input['confirm'])) {
            return new \WP_Error('mwp_confirm_required', __('This import overwrites stored options. Pass confirm: true, or keep dry_run true to preview.', 'extend-wp'), ['status' => 400]);
        }

        return [$data, [
            'dry_run'          => $dry_run,
            'skip_url_replace' => !empty($input['skip_url_replace']),
            'actor'            => $ctx->surface(),
            'backup_file'      => !empty($input['backup_file']) ? (string) $input['backup_file'] : null,
        ]];
    }

    /**
     * @param string $path File path from the command line.
     *
     * @return array|\WP_Error
     */
    private function read_export_file($path)
    {
        if (!file_exists($path)) {
            return new \WP_Error('ewp_options_file_not_found', sprintf(__('File not found: %s', 'extend-wp'), $path), ['status' => 400]);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new \WP_Error('invalid_json', __('Invalid JSON in the import file.', 'extend-wp'), ['status' => 400]);
        }

        return $data;
    }

    /* ---------------------------------------------------------------------
     * CLI presenters
     * ------------------------------------------------------------------ */

    /**
     * @param array       $pages  Raw exportable pages.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_pages($pages, array $input, $format)
    {
        if (!is_array($pages) || $pages === []) {
            \WP_CLI::success(__('No exportable option pages found.', 'extend-wp'));
            return;
        }

        $rows = [];
        foreach ($pages as $key => $page) {
            $rows[] = ['Page Key' => $key, 'Title' => $page['title'], 'Field Count' => count($page['fields'])];
        }

        \WP_CLI\Utils\format_items($format ?: 'table', $rows, ['Page Key', 'Title', 'Field Count']);
    }

    /**
     * `--format=table` prints a summary; otherwise the payload is written to `--file`.
     *
     * @param array       $data   Export payload.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_export($data, array $input, $format)
    {
        if ($format === 'table') {
            $rows = [];
            foreach ($data['pages'] as $key => $page) {
                $rows[] = ['Page' => $key, 'Title' => $page['title'], 'Fields' => $page['field_count']];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['Page', 'Title', 'Fields']);
            return;
        }

        $file = !empty($input['file']) ? (string) $input['file'] : './ewp-options-export.json';
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            \WP_CLI::error(__('Failed to encode export data to JSON.', 'extend-wp'));
            return;
        }

        $written = file_put_contents($file, $json);
        if ($written === false) {
            \WP_CLI::error(sprintf(__('Failed to write to %s.', 'extend-wp'), $file));
            return;
        }

        \WP_CLI::success(sprintf('Exported %d page(s) to %s (%s bytes)', count($data['pages']), $file, number_format($written)));
    }

    /**
     * @param array       $result Import summary.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_import($result, array $input, $format)
    {
        if ($format === 'json') {
            \WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        $prefix = !empty($result['dry_run']) ? '[DRY-RUN] ' : '';
        $stats  = [
            ['Metric' => 'Pages Imported', 'Value' => count($result['pages_imported'])],
            ['Metric' => 'Pages Skipped', 'Value' => count($result['pages_skipped'])],
            ['Metric' => 'Fields Imported', 'Value' => $result['fields_imported']],
            ['Metric' => 'Fields Skipped', 'Value' => $result['fields_skipped']],
            ['Metric' => 'URL Replace Applied', 'Value' => !empty($result['url_replace_applied']) ? 'Yes' : 'No'],
            ['Metric' => 'URL Replacement Pairs', 'Value' => $result['url_replacements_count']],
            ['Metric' => 'Backup Created', 'Value' => !empty($result['backup_created']) ? 'Yes' : 'No'],
        ];
        \WP_CLI\Utils\format_items('table', $stats, ['Metric', 'Value']);

        foreach (isset($result['warnings']) ? $result['warnings'] : [] as $warning) {
            \WP_CLI::warning($prefix . $warning);
        }

        if (!empty($result['dry_run'])) {
            \WP_CLI::success('Dry-run completed. No changes were made.');
            return;
        }

        if (empty($result['pages_skipped'])) {
            \WP_CLI::success(sprintf('Import completed: %d pages, %d fields imported.', count($result['pages_imported']), $result['fields_imported']));
            return;
        }

        \WP_CLI::warning(sprintf('Import completed with warnings: %d pages imported, %d skipped.', count($result['pages_imported']), count($result['pages_skipped'])));
    }

    /**
     * @return array
     */
    private function pages_output_schema()
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
}
