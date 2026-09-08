<?php

namespace EWP\SelfTest\Cases;

use EWP\SelfTest\EWP_Self_Test_Case;
use EWP\SelfTest\WP_CLI_Shim;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options portability: list the exportable pages, export the first one and
 * feed that export back through a dry-run import on every surface. A dry
 * run validates and reports but never writes, so no option changes and
 * nothing needs cleaning up. Sites with no exportable page report the
 * export/import checks as skipped.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class Options_Portability_Case extends EWP_Self_Test_Case
{
    /** {@inheritDoc} */
    public function availability()
    {
        if (!class_exists('EWP_Options_Portability')) {
            return ['available' => false, 'reason' => __('The options portability module is not loaded.', 'extend-wp')];
        }

        return ['available' => true, 'reason' => ''];
    }

    /** {@inheritDoc} */
    public function preview()
    {
        return [
            __('List the exportable option pages (EWP_Options_Portability::get_exportable_pages()).', 'extend-wp'),
            __('REST: GET /extend-wp/v1/options-portability/pages — expect 200 and the same list.', 'extend-wp'),
            __('CLI: wp ewp options list — expect the same pages.', 'extend-wp'),
            __('Ability: ewp-options/list-pages — expect the same pages.', 'extend-wp'),
            __('Export the first page via REST (GET .../export?pages[]=<page>) and via ewp-options/export.', 'extend-wp'),
            __('Dry-run import of that export via REST (POST .../import, dry_run=true) and via ewp-options/import (dry_run: true) — validates without writing any option.', 'extend-wp'),
            __('No option is changed; nothing to clean up.', 'extend-wp'),
        ];
    }

    /** {@inheritDoc} */
    public function run()
    {
        $portability = \EWP_Options_Portability::instance();
        $pages       = $portability->get_exportable_pages();
        $page        = !empty($pages) && is_array($pages) ? (string) array_key_first($pages) : '';
        $o           = ['page' => $page, 'page_count' => is_array($pages) ? count($pages) : 0];

        if ($this->cli_available()) {
            WP_CLI_Shim::load_plugin_commands();
        }

        $o['rest_pages']    = $this->rest('GET', '/extend-wp/v1/options-portability/pages');
        $o['cli_list']      = $this->cli(['EWP_Options_Portability_CLI', 'list_pages'], [], ['format' => 'json']);
        $o['ability_pages'] = $this->ability('ewp-options/list-pages');

        if ($page === '') {
            return ['observed' => $o];
        }

        $o['rest_export'] = $this->rest('GET', '/extend-wp/v1/options-portability/export', ['pages' => [$page]]);
        $export           = $portability->export_options([$page], 'self-test');
        $o['export_ok']   = !is_wp_error($export);

        if (!is_wp_error($export)) {
            $o['rest_import']    = $this->rest('POST', '/extend-wp/v1/options-portability/import', ['data' => wp_json_encode($export), 'dry_run' => true]);
            $o['ability_export'] = $this->ability('ewp-options/export', ['page_keys' => [$page]]);
            $o['ability_import'] = $this->ability('ewp-options/import', ['data' => $this->serialisable($export), 'dry_run' => true]);
        }

        return ['observed' => $o];
    }

    /** {@inheritDoc} */
    public function validate(array $context)
    {
        $o      = $context['observed'];
        $checks = [];

        $checks[] = $this->check('rest', 'GET /options-portability/pages returns 200', $o['rest_pages']['status'] === 200, 'HTTP ' . $o['rest_pages']['status'] . sprintf(', %d page(s)', (int) $o['page_count']));

        if ($this->cli_available()) {
            $checks[] = $this->check('cli', 'wp ewp options list runs', !empty($o['cli_list']['ok']), $o['cli_list']['error'] ?: __('ok', 'extend-wp'));
        } else {
            $checks[] = $this->skip('cli', 'wp ewp options list runs', __('WP-CLI wrappers are not loaded in this process.', 'extend-wp'));
        }

        if ($this->abilities_available()) {
            $checks[] = $this->check('ability', 'ewp-options/list-pages executes', !empty($o['ability_pages']['ok']), $o['ability_pages']['error'] ?: __('ok', 'extend-wp'));
        } else {
            $checks[] = $this->skip('ability', 'ewp-options/list-pages executes', __('Abilities API not available on this site.', 'extend-wp'));
        }

        if ($o['page'] === '') {
            $reason = __('No exportable option page is registered on this site.', 'extend-wp');
            $checks[] = $this->skip('rest', 'GET /options-portability/export returns the page', $reason);
            $checks[] = $this->skip('rest', 'POST /options-portability/import (dry run) validates the export', $reason);
            $checks[] = $this->skip('ability', 'ewp-options/export + ewp-options/import (dry run)', $reason);
            return $checks;
        }

        $checks[] = $this->check('rest', sprintf('GET /options-portability/export exports "%s"', $o['page']), $o['rest_export']['status'] === 200 && isset($o['rest_export']['data']['pages']), 'HTTP ' . $o['rest_export']['status']);
        $checks[] = $this->check('core', 'EWP_Options_Portability::export_options() succeeds', !empty($o['export_ok']));

        if (empty($o['export_ok'])) {
            return $checks;
        }

        $checks[] = $this->check('rest', 'POST /options-portability/import with dry_run=true validates without writing', $o['rest_import']['status'] === 200, 'HTTP ' . $o['rest_import']['status'] . ' ' . wp_json_encode($o['rest_import']['data']));

        if ($this->abilities_available()) {
            $checks[] = $this->check('ability', 'ewp-options/export returns the export', !empty($o['ability_export']['ok']) && isset($o['ability_export']['data']['pages']), $o['ability_export']['error'] ?: __('ok', 'extend-wp'));
            $checks[] = $this->check('ability', 'ewp-options/import (dry_run: true) validates without writing', !empty($o['ability_import']['ok']), $o['ability_import']['error'] ?: __('ok', 'extend-wp'));
        } else {
            $checks[] = $this->skip('ability', 'ewp-options/export + ewp-options/import (dry run)', __('Abilities API not available on this site.', 'extend-wp'));
        }

        return $checks;
    }
}
