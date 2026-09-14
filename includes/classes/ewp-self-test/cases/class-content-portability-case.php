<?php

namespace EWP\SelfTest\Cases;

use Gnnpls\SelfTest\Case_Base;
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
            __('CLI: wp ewp content export --types=<type> --file=<temp> — expect the file to be written.', 'extend-wp'),
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
        $context = ['content_type' => $type, 'file' => $file, 'observed' => []];
        $o       = &$context['observed'];

        $this->register_fixture_type($key, 'ewp');

        $service = new \EWP\Content\Content_Service();
        $row     = $service->create_item($type, 'Portable row', 'enabled', ['required_field' => 'x']);
        $id      = is_array($row) ? (int) $row['id'] : 0;

        $o['rest_export']    = $this->rest('GET', '/ewp/v1/export', ['content_types' => [$type], 'method' => 'json']);
        $o['ability_export'] = $this->ability('ewp-content/export', ['content_types' => [$type]]);
        $o['cli_export']     = $this->cli(EWP_Surfaces::cli('content-portability', 'export'), [], ['types' => $type, 'file' => $file]);

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
            @unlink($context['file']);
        }

        return $this->cleanup_fixture_type(isset($context['content_type']) ? (string) $context['content_type'] : '');
    }
}
