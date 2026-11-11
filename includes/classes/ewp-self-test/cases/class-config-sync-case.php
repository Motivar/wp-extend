<?php

namespace EWP\SelfTest\Cases;

use MammothWP\SelfTest\Case_Base;
use EWP\Surfaces\EWP_Surfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exercises the automatic configuration sync (status, export, migrate, import) on every surface,
 * against a throwaway content type and a throwaway folder under wp-content.
 *
 * The export, import-signature and imported-hashes options are saved before the run and restored
 * after it, so the site's own sync setup (and the rows it may delete on import) is never touched.
 *
 * @package EWP\SelfTest
 * @since   1.15.0
 */
class Config_Sync_Case extends Case_Base
{
    use Fixture_Content_Type;

    /** Marker for "option did not exist". */
    const ABSENT = '__ewp_absent__';

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('Register a temporary content type "ewp_selftest_<random>", create one row and a temporary folder wp-content/ewp-selftest-sync-<random>.', 'extend-wp'),
            __('Point the export option at that folder (the sync options are saved first and restored afterwards).', 'extend-wp'),
            __('Status: REST GET /ewp/v1/config-sync/status, ability ewp-content/sync-status, wp ewp content sync status.', 'extend-wp'),
            __('Export: REST POST /ewp/v1/config-sync/export (per_object), ability ewp-content/sync-export, wp ewp content sync export — expect ewp-config/<type>/<hash>.json.', 'extend-wp'),
            __('Migrate: write the legacy single file, then REST POST /ewp/v1/config-sync/migrate, ability ewp-content/sync-migrate (confirm: true), wp ewp content sync migrate --yes — expect ewp_configuration.legacy.json and layout = per_object.', 'extend-wp'),
            __('Import: delete the row, then import it back on each surface (REST, ability with confirm: true, wp ewp content sync import --yes) — expect the row to exist again.', 'extend-wp'),
            __('Cleanup: restore the options, remove the folder, drop the fixture tables.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $key     = 'selftest_' . substr(md5(uniqid('', true)), 0, 6);
        $type    = 'ewp_' . $key;
        $rel     = 'ewp-selftest-sync-' . $key;
        $dir     = untrailingslashit(WP_CONTENT_DIR) . '/' . $rel;
        $context = ['content_type' => $type, 'dir' => $dir, 'saved' => $this->save_options(), 'observed' => []];
        $o       = &$context['observed'];

        $this->register_fixture_type($key, 'ewp');
        wp_mkdir_p($dir);
        $service = new \EWP\Content\Content_Service();
        $row     = $service->create_item($type, 'Synced row', 'enabled', ['required_field' => 'x']);
        $id      = is_array($row) ? (int) $row['id'] : 0;

        update_option(\EWP\Content\Config_Sync::OPTION_EXPORT, ['store' => false, 'types' => [$type], 'path' => $rel, 'layout' => 'single']);
        update_option(\EWP\Content\Config_Sync::OPTION_HASHES, [], false);

        $o['rest_status']    = $this->rest('GET', '/ewp/v1/config-sync/status');
        $o['ability_status'] = $this->ability('ewp-content/sync-status');
        $o['cli_status']     = $this->cli(EWP_Surfaces::cli('config-sync', 'status'));

        $o['rest_export']    = $this->rest('POST', '/ewp/v1/config-sync/export', ['layout' => 'per_object']);
        $o['files_after_rest'] = count((array) glob($dir . '/ewp-config/' . $type . '/*.json'));
        $o['ability_export'] = $this->ability('ewp-content/sync-export', ['layout' => 'per_object']);
        $o['cli_export']     = $this->cli(EWP_Surfaces::cli('config-sync', 'export'), [], ['layout' => 'per_object']);

        $this->migrate_and_import($context, $service, $id);

        $this->restore_options($context['saved']);
        $context['saved'] = [];

        return $context;
    }

    /**
     * The migrate and import steps of run().
     *
     * @param array                         $context Run context (observations are added to it).
     * @param \EWP\Content\Content_Service $service Content service.
     * @param int                           $id      Fixture row id.
     *
     * @return void
     */
    private function migrate_and_import(array &$context, $service, $id)
    {
        $type = $context['content_type'];
        $dir  = $context['dir'];
        $o    = &$context['observed'];
        $path = basename($dir);

        $sync = new \EWP\Content\Config_Sync();
        $sync->export(['types' => [$type], 'path' => $path, 'layout' => 'single']);
        $o['legacy_before'] = is_file($dir . '/ewp_configuration.json');
        $o['rest_migrate']  = $this->rest('POST', '/ewp/v1/config-sync/migrate');
        $o['legacy_after']  = is_file($dir . '/ewp_configuration.legacy.json') && !is_file($dir . '/ewp_configuration.json');
        $o['layout_saved']  = $sync->export_settings(true)['layout'];

        $o['ability_migrate'] = $this->ability('ewp-content/sync-migrate', ['confirm' => true]);
        $o['cli_migrate']     = $this->cli(EWP_Surfaces::cli('config-sync', 'migrate'), [], ['yes' => true]);

        $o['rest_import'] = $this->import_round($service, $id, $type, function () use ($path) {
            return $this->rest('POST', '/ewp/v1/config-sync/import', ['path' => $path]);
        });
        $o['ability_import'] = $this->import_round($service, $id, $type, function () use ($path) {
            return $this->ability('ewp-content/sync-import', ['path' => $path, 'confirm' => true]);
        });
        $o['cli_import'] = $this->import_round($service, $id, $type, function () use ($path) {
            return $this->cli(EWP_Surfaces::cli('config-sync', 'import'), [], ['path' => $path, 'yes' => true]);
        });
    }

    /**
     * Delete every row of the type, run an import, and report what the import did.
     *
     * @param \EWP\Content\Content_Service $service Content service.
     * @param int                           $id      Row id to delete first.
     * @param string                        $type    Content type id.
     * @param callable                      $import  Runs the import, returns the surface's observation.
     *
     * @return array {result: mixed, rows: int} rows = rows of the type after the import.
     */
    private function import_round($service, $id, $type, callable $import)
    {
        $items = $service->list_items($type, ['limit' => 10])['items'];
        $ids   = array_map(function ($item) {
            return (int) $item['id'];
        }, $items);
        if ($ids) {
            $service->delete_items($type, $ids);
        }
        update_option(\EWP\Content\Config_Sync::OPTION_HASHES, [], false);
        $result = $import();

        return ['result' => $result, 'rows' => count($service->list_items($type, ['limit' => 10])['items'])];
    }

    /**
     * @return array option => value (or ABSENT) of every option the run changes.
     */
    private function save_options()
    {
        $saved = [];
        foreach ([\EWP\Content\Config_Sync::OPTION_EXPORT, \EWP\Content\Config_Sync::OPTION_HASHES, \EWP\Content\Config_Sync::OPTION_SIGNATURE] as $option) {
            $saved[$option] = get_option($option, self::ABSENT);
        }

        return $saved;
    }

    /**
     * @param array $saved As returned by save_options().
     *
     * @return void
     */
    private function restore_options(array $saved)
    {
        foreach ($saved as $option => $value) {
            if ($value === self::ABSENT) {
                delete_option($option);
                continue;
            }
            update_option($option, $value, false);
        }
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        $checks[] = $this->check('rest', 'GET /ewp/v1/config-sync/status reports the single layout and the type', $this->status($o, 'rest_status') === 200 && $o['rest_status']['data']['layout'] === 'single' && in_array($context['content_type'], (array) $o['rest_status']['data']['types'], true), $this->detail($o, 'rest_status'));
        $checks[] = $this->ability_check($o, 'ability_status', 'ewp-content/sync-status returns the layout', function ($data) {
            return isset($data['layout']);
        });
        $checks[] = $this->cli_check_printed($o, 'cli_status', 'wp ewp content sync status prints the layout', function ($printed) {
            return strpos(wp_json_encode($printed), 'layout') !== false;
        });

        $checks[] = $this->check('rest', 'POST /ewp/v1/config-sync/export writes one file per row', $this->status($o, 'rest_export') === 200 && $o['files_after_rest'] === 1, $this->detail($o, 'rest_export'));
        $checks[] = $this->ability_check($o, 'ability_export', 'ewp-content/sync-export reports the per object layout', function ($data) {
            return isset($data['layout']) && $data['layout'] === 'per_object';
        });
        $checks[] = $this->cli_check_printed($o, 'cli_export', 'wp ewp content sync export prints the summary', function ($printed) {
            return strpos(wp_json_encode($printed), 'per_object') !== false;
        });

        $checks[] = $this->check('rest', 'POST /ewp/v1/config-sync/migrate retires the legacy file and saves the layout', $o['legacy_before'] && $this->status($o, 'rest_migrate') === 200 && $o['legacy_after'] && $o['layout_saved'] === 'per_object', $this->detail($o, 'rest_migrate'));
        $checks[] = $this->ability_check($o, 'ability_migrate', 'ewp-content/sync-migrate (confirm: true) reports the layout as saved', function ($data) {
            return !empty($data['layout_saved']);
        });
        $checks[] = $this->cli_check_printed($o, 'cli_migrate', 'wp ewp content sync migrate --yes prints the summary', function ($printed) {
            return strpos(wp_json_encode($printed), 'layout_saved') !== false;
        });

        $checks[] = $this->check('rest', 'POST /ewp/v1/config-sync/import restores the row', $this->status($o['rest_import'], 'result') === 200 && $o['rest_import']['rows'] === 1, $this->detail($o['rest_import'], 'result'));
        $checks[] = $this->ability_check($o['ability_import'], 'result', 'ewp-content/sync-import (confirm: true) restores the row', function ($data) use ($o) {
            return $o['ability_import']['rows'] === 1 && isset($data['status']) && $data['status'] === 'imported';
        });
        $checks[] = $this->cli_check_printed($o['cli_import'], 'result', 'wp ewp content sync import --yes restores the row', function ($printed) use ($o) {
            return $o['cli_import']['rows'] === 1 && strpos(wp_json_encode($printed), 'imported') !== false;
        });

        return $checks;
    }

    /** {@inheritDoc} */
    public function cleanup(array $context)
    {
        $messages = [];
        if (!empty($context['saved'])) {
            $this->restore_options($context['saved']);
            $messages[] = __('Restored the configuration sync options.', 'extend-wp');
        }
        if (!empty($context['dir']) && strpos(basename($context['dir']), 'ewp-selftest-sync-') === 0 && is_dir($context['dir'])) {
            $this->remove_dir($context['dir']);
            $messages[] = __('Removed the temporary configuration folder.', 'extend-wp');
        }

        return array_merge($messages, $this->cleanup_fixture_type(isset($context['content_type']) ? (string) $context['content_type'] : ''));
    }

    /**
     * Delete a folder tree.
     *
     * @param string $dir Folder.
     *
     * @return void
     */
    private function remove_dir($dir)
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->remove_dir($path) : @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort cleanup.
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the test's own folder; WP_Filesystem may ask for FTP credentials.
    }
}
