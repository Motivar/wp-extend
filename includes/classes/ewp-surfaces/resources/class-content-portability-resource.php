<?php

namespace EWP\Surfaces\Resources;

use EWP\Content\Content_Portability;
use MammothWP\WpKit\Context;
use MammothWP\WpKit\Field;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Resource;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export and import of custom content rows on every surface.
 *
 * REST keeps its historical contract for the admin screen: `GET ewp/v1/export`
 * answers with the payload encoded as a JSON (or PHP-serialized) string and
 * `POST ewp/v1/import` answers `true`. The CLI reads and writes files;
 * abilities exchange the payload as an object.
 *
 * @package    EWP\Surfaces
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Content_Portability_Resource extends Resource
{
    /** @var Content_Portability */
    private $service;

    /**
     * @param Content_Portability $service Portability service.
     */
    public function __construct(Content_Portability $service)
    {
        $this->service = $service;
    }

    public function name()
    {
        return 'content-portability';
    }

    public function label()
    {
        return __('EWP Content Portability', 'extend-wp');
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
        return '/';
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
            'export' => Operation::read('export')
                ->label(__('Export custom content', 'extend-wp'))
                ->description(__('Export every row of one or more content types with their meta, keyed by row hash so the payload can be imported elsewhere as an upsert. Definitions (field groups, post types) and data rows alike.', 'extend-wp'))
                ->input([
                    Field::array('content_types')->items('string')->required()->cli_name('types')->describe(__('Content type ids to export, comma separated on REST and the CLI.', 'extend-wp')),
                    Field::enum('method', ['json', 'php'])->describe(__('REST only: encode the payload as json (default) or a PHP serialized string.', 'extend-wp')),
                    Field::string('file')->describe(__('Command line only: write the payload to this path (default ./ewp-content-export.json). Rows are streamed to the file in batches, so large tables do not have to fit in memory.', 'extend-wp')),
                    Field::int('page')->min(1)->describe(__('Optional. Export only this page (from 1) of every type; the payload then carries pagination (type => page, per_page, total, total_pages). Omit for every row. Ignored with the command line file output unless per_page is set too.', 'extend-wp')),
                    Field::int('per_page')->min(1)->max(Content_Portability::MAX_PER_PAGE)->describe(__('Optional. Rows per page and type for a paged export, 1-1000 (default 500 when page is given).', 'extend-wp')),
                ])
                ->args([$this, 'export_args'])
                ->transform([$this, 'export_transform'])
                ->output(['type' => 'object', 'additionalProperties' => true])
                ->rest('GET', 'export')
                ->cli('export', ['presenter' => [$this, 'present_export']])
                ->ability('export'),

            'import' => Operation::write('import')
                ->annotations(['destructive' => true])
                ->confirm([Context::ABILITY, Context::CLI])
                ->label(__('Import custom content', 'extend-wp'))
                ->description(__('Import rows into one content type, upserting by hash. Existing rows with the same hash are overwritten, so confirm must be true. On the command line pass the export file as the first argument.', 'extend-wp'))
                ->input([
                    Field::string('content_type')->required()->cli_name('type')->describe(__('The content type to import into.', 'extend-wp')),
                    Field::custom('content', ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => __('Rows as produced by export (the value under the content type key).', 'extend-wp')]),
                    Field::string('file')->positional()->describe(__('Command line only: path to a JSON export file.', 'extend-wp')),
                ])
                ->args([$this, 'import_args'])
                ->transform([$this, 'import_transform'])
                ->output($this->import_output_schema())
                ->rest('POST', 'import')
                ->cli('import', ['success' => 'Imported %count% row(s) into %content_type%.'])
                ->ability('import'),
        ];
    }

    /**
     * export($content_types, $options): the CLI without a page streams to its file; every other call
     * passes the page options (never `file`, which only the CLI may set).
     *
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array Arguments of Content_Portability::export().
     *
     * @since 1.11.0
     */
    public function export_args(array $input, Context $ctx)
    {
        $options = [];
        foreach (['page', 'per_page'] as $key) {
            if (!empty($input[$key])) {
                $options[$key] = (int) $input[$key];
            }
        }
        if ($ctx->surface() === Context::CLI && $options === []) {
            $options['file'] = !empty($input['file']) ? (string) $input['file'] : './ewp-content-export.json';
        }

        return [(array) $input['content_types'], $options];
    }

    /**
     * REST answers with an encoded string (the admin screen downloads it).
     *
     * @param array   $data  Export payload.
     * @param array   $input Input.
     * @param Context $ctx   Invocation context.
     *
     * @return mixed
     */
    public function export_transform($data, array $input, Context $ctx)
    {
        if ($ctx->surface() !== Context::REST || !is_array($data)) {
            return $data;
        }

        if (isset($input['method']) && $input['method'] === 'php') {
            return serialize($data);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array   $input Normalised input.
     * @param Context $ctx   Invocation context.
     *
     * @return array|\WP_Error import($content_type, array $rows)
     */
    public function import_args(array $input, Context $ctx)
    {
        $type = (string) $input['content_type'];
        $rows = isset($input['content']) && is_array($input['content']) ? $input['content'] : [];

        if ($ctx->surface() === Context::CLI && !empty($input['file'])) {
            $file = (string) $input['file'];
            if (!file_exists($file)) {
                return new \WP_Error('ewp_content_file_not_found', sprintf(/* translators: %s: file path */ __('File not found: %s', 'extend-wp'), $file), ['status' => 400]);
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return new \WP_Error('invalid_json', __('Invalid JSON in the import file.', 'extend-wp'), ['status' => 400]);
            }
            $rows = isset($decoded[$type]) && is_array($decoded[$type]) ? $decoded[$type] : $decoded;
        }

        return [$type, array_values($rows)];
    }

    /**
     * REST keeps answering `true`; other surfaces get the summary.
     *
     * @param array   $result Import summary.
     * @param array   $input  Input.
     * @param Context $ctx    Invocation context.
     *
     * @return mixed
     */
    public function import_transform($result, array $input, Context $ctx)
    {
        return $ctx->surface() === Context::REST ? true : $result;
    }

    /**
     * @param array       $data   Export payload.
     * @param array       $input  Input.
     * @param string|null $format Requested --format.
     *
     * @return void
     */
    public function present_export($data, array $input, $format)
    {
        if (isset($data['file'], $data['types'], $data['count'])) {
            \WP_CLI::success(sprintf('Exported %d row(s) of %d content type(s) to %s.', (int) $data['count'], count((array) $data['types']), $data['file']));
            return;
        }

        $file = !empty($input['file']) ? (string) $input['file'] : './ewp-content-export.json';
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json) === false) {
            \WP_CLI::error(sprintf(/* translators: %s: error message */ __('Failed to write to %s.', 'extend-wp'), $file));
            return;
        }

        $types = array_diff(array_keys($data), ['modified', 'pagination']);
        \WP_CLI::success(sprintf('Exported %d content type(s) to %s.', count($types), $file));
    }

    /**
     * @return array
     */
    private function import_output_schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'content_type' => ['type' => 'string'],
                'count'        => ['type' => 'integer'],
                'hashes'       => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'additionalProperties' => true,
        ];
    }
}
