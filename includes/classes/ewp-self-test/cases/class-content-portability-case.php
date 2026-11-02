<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;
use EWP\Surfaces\EWP_Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Round-trips a row through export and import on every surface.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Content_Portability_Case extends Case_Base
{
    use Fixture_Content_Type;

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register a temporary content type "ewp_selftest_<random>" and create one row in it.', 'extend-wp'),
            __('REST: GET /ewp/v1/export?content_types[]=<type> — expect 200 and a JSON string containing the row hash.', 'extend-wp'),
            __('Ability: ewp-content/export — expect the payload object keyed by content type.', 'extend-wp'),
            __('CLI: wp ewp content export --types=<type> --file=<temp> — expect the file to be written (streamed in batches).', 'extend-wp'),
            __('Paged export: REST GET /ewp/v1/export?page=1&per_page=1 and ewp-content/export with page 1 / per_page 1 — expect the row plus pagination (total 1, total_pages 1).', 'extend-wp'),
            __('PHP export (method=php) of ewp_fields plus the fixture type: REST GET /ewp/v1/export, ability ewp-content/export and wp ewp content export --method=php --file=<temp>.php — expect {code, skipped} with PHP code and the fixture type listed as skipped.', 'extend-wp'),
            __('Per object export (layout=per_object): REST GET /ewp/v1/export, ability ewp-content/export and wp ewp content export --layout=per_object --file=<temp folder> — expect {files, types} with ewp-config/format.json and one ewp-config/<type>/<hash>.json, and the folder written by the CLI.', 'extend-wp'),
            __('Delete the row, then import it back on each surface: REST POST /ewp/v1/import, wp ewp content import <file> --yes, ewp-content/import (confirm: true) — expect the row to exist again.', 'extend-wp'),
            __('Cleanup: drop the fixture tables and the temporary file.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $key     = 'selftest_' . substr(md5(uniqid('', true)), 0, 6);
        $type    = 'ewp_' . $key;
        $file    = trailingslashit(sys_get_temp_dir()) . 'ewp-self-test-' . $key . '.json';
        $php_file = trailingslashit(sys_get_temp_dir()) . 'ewp-self-test-' . $key . '.php';
        $files_dir = trailingslashit(sys_get_temp_dir()) . 'ewp-self-test-' . $key . '-files';
        $context  = ['content_type' => $type, 'file' => $file, 'php_file' => $php_file, 'files_dir' => $files_dir, 'observed' => []];
        $o       = &$context['observed'];

        $this->register_fixture_type($key, 'ewp');

        $service = new \EWP\Content\Content_Service();
        $row     = $service->create_item($type, 'Portable row', 'enabled', ['required_field' => 'x']);
        $id      = is_array($row) ? (int) $row['id'] : 0;

        $o['rest_export']    = $this->rest('GET', '/ewp/v1/export', ['content_types' => [$type], 'method' => 'json']);
        $o['ability_export'] = $this->ability('ewp-content/export', ['content_types' => [$type]]);
        $o['cli_export']     = $this->cli(EWP_Surfaces::cli('content-portability', 'export'), [], ['types' => $type, 'file' => $file]);
        $o['rest_export_paged']    = $this->rest('GET', '/ewp/v1/export', ['content_types' => [$type], 'page' => 1, 'per_page' => 1]);
        $o['ability_export_paged'] = $this->ability('ewp-content/export', ['content_types' => [$type], 'page' => 1, 'per_page' => 1]);

        $php_types = ['ewp_fields', $type];
        $o['rest_export_php']    = $this->rest('GET', '/ewp/v1/export', ['content_types' => $php_types, 'method' => 'php']);
        $o['ability_export_php'] = $this->ability('ewp-content/export', ['content_types' => $php_types, 'method' => 'php']);
        $o['cli_export_php']     = $this->cli(EWP_Surfaces::cli('content-portability', 'export'), [], ['types' => implode(',', $php_types), 'method' => 'php', 'file' => $php_file]);
        $o['php_file_code']      = file_exists($php_file) ? (string) file_get_contents($php_file) : '';

        $o['rest_export_files']    = $this->rest('GET', '/ewp/v1/export', ['content_types' => [$type], 'layout' => 'per_object']);
        $o['ability_export_files'] = $this->ability('ewp-content/export', ['content_types' => [$type], 'layout' => 'per_object']);
        $o['cli_export_files']     = $this->cli(EWP_Surfaces::cli('content-portability', 'export'), [], ['types' => $type, 'layout' => 'per_object', 'file' => $files_dir]);
        $o['cli_files_written']    = count((array) glob($files_dir . '/ewp-config/' . $type . '/*.json'));

        $payload = !empty($o['ability_export']['ok']) ? $o['ability_export']['data'] : [];
        $rows    = isset($payload[$type]) ? array_values((array) $payload[$type]) : [];

        if ($id > 0) {
            $service->delete_items($type, [$id]);
        }

        $o['rest_import']    = $this->rest('POST', '/ewp/v1/import', ['content_type' => $type, 'content' => $rows]);
        $o['after_rest']     = count($service->list_items($type, ['limit' => 10])['items']);
        $o['ability_import'] = $this->ability('ewp-content/import', ['content_type' => $type, 'content' => $rows, 'confirm' => true]);
        $o['cli_import']     = file_exists($file) ? $this->cli(EWP_Surfaces::cli('content-portability', 'import'), [$file], ['type' => $type, 'yes' => true]) : null;
        $o['after_all']      = count($service->list_items($type, ['limit' => 10])['items']);

        return $context;
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        $checks[] = $this->check('rest', 'GET /ewp/v1/export returns 200 with a JSON string', $this->status($o, 'rest_export') === 200 && is_string($o['rest_export']['data']) && strpos($o['rest_export']['data'], 'Portable row') !== false, $this->detail($o, 'rest_export'));
        $checks[] = $this->ability_check($o, 'ability_export', 'ewp-content/export returns the payload keyed by type', function ($data) use ($context) {
            return isset($data[$context['content_type']]);
        });
        $checks[] = $this->cli_check($o, 'cli_export', 'wp ewp content export writes the file', 'Exported');
        $paged    = $this->status($o, 'rest_export_paged') === 200 && is_string($o['rest_export_paged']['data']) ? json_decode($o['rest_export_paged']['data'], true) : null;
        $checks[] = $this->check('rest', 'GET /ewp/v1/export?page=1&per_page=1 returns the page with pagination', isset($paged['pagination'][$context['content_type']]['total'], $paged[$context['content_type']]) && (int) $paged['pagination'][$context['content_type']]['total'] === 1, $this->detail($o, 'rest_export_paged'));
        $checks[] = $this->ability_check($o, 'ability_export_paged', 'ewp-content/export (page 1, per_page 1) returns pagination', function ($data) use ($context) {
            $type = $context['content_type'];
            return isset($data[$type], $data['pagination'][$type]['total_pages']) && (int) $data['pagination'][$type]['total_pages'] === 1 && count((array) $data[$type]) === 1;
        });

        $php_check = function ($data) use ($context) {
            return is_array($data) && isset($data['code'], $data['skipped']) && strpos($data['code'], '<?php') === 0 && in_array($context['content_type'], (array) $data['skipped'], true);
        };
        $rest_php = $this->status($o, 'rest_export_php') === 200 ? $o['rest_export_php']['data'] : null;
        $checks[] = $this->check('rest', 'GET /ewp/v1/export?method=php returns {code, skipped} with the fixture type skipped', $php_check($rest_php), $this->detail($o, 'rest_export_php'));
        $checks[] = $this->ability_check($o, 'ability_export_php', 'ewp-content/export (method php) returns {code, skipped}', $php_check);
        $checks[] = $this->cli_check($o, 'cli_export_php', 'wp ewp content export --method=php writes the PHP file', 'Exported PHP code');
        $checks[] = $this->cli_available()
            ? $this->check('cli', 'The CLI PHP export file starts with a PHP tag', strpos($o['php_file_code'], '<?php') === 0, 'Wrote ' . strlen($o['php_file_code']) . ' bytes.')
            : $this->skip('cli', 'The CLI PHP export file starts with a PHP tag', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));

        $files_check = function ($data) use ($context) {
            $type = $context['content_type'];
            if (!is_array($data) || !isset($data['files']['ewp-config/format.json'], $data['types'][$type])) {
                return false;
            }
            $rows = array_filter(array_keys((array) $data['files']), function ($path) use ($type) {
                return strpos($path, 'ewp-config/' . $type . '/') === 0;
            });
            return count($rows) === 1 && (int) $data['types'][$type] === 1;
        };
        $rest_files = $this->status($o, 'rest_export_files') === 200 ? $o['rest_export_files']['data'] : null;
        $checks[]   = $this->check('rest', 'GET /ewp/v1/export?layout=per_object returns {files, types} with the marker and one row file', $files_check($rest_files), $this->detail($o, 'rest_export_files'));
        $checks[]   = $this->ability_check($o, 'ability_export_files', 'ewp-content/export (layout per_object) returns {files, types}', $files_check);
        $checks[]   = $this->cli_check($o, 'cli_export_files', 'wp ewp content export --layout=per_object writes the folder', 'as 2 file(s)');
        $checks[]   = $this->cli_available()
            ? $this->check('cli', 'The CLI per object export wrote one row file', $o['cli_files_written'] === 1, 'Found ' . $o['cli_files_written'] . ' row file(s).')
            : $this->skip('cli', 'The CLI per object export wrote one row file', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));

        $checks[] = $this->check('rest', 'POST /ewp/v1/import restores the row (upsert by hash)', $this->status($o, 'rest_import') === 200 && $o['after_rest'] === 1, $this->detail($o, 'rest_import'));
        $checks[] = $this->ability_check($o, 'ability_import', 'ewp-content/import (confirm: true) upserts without duplicating', function ($data) use ($o) {
            return isset($data['count']) && $data['count'] === 1 && $o['after_all'] === 1;
        });
        $checks[] = $this->cli_check($o, 'cli_import', 'wp ewp content import <file> --yes reports the row', 'Imported');

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        if (!empty($context['file']) && file_exists($context['file'])) {
            wp_delete_file($context['file']);
        }

        if (!empty($context['php_file']) && file_exists($context['php_file'])) {
            wp_delete_file($context['php_file']);
        }

        if (!empty($context['files_dir']) && is_dir($context['files_dir'])) {
            $this->remove_files_dir((string) $context['files_dir']);
        }

        return $this->cleanup_fixture_type(isset($context['content_type']) ? (string) $context['content_type'] : '');
    }

    /**
     * Remove the folder written by the per object CLI export.
     *
     * @param string $dir Folder (inside the system temp dir).
     *
     * @return void
     */
    private function remove_files_dir($dir)
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : wp_delete_file($item->getPathname()); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the test's own folder; WP_Filesystem may ask for FTP credentials.
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the test's own folder; WP_Filesystem may ask for FTP credentials.
    }
}
