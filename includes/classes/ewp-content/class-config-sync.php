<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatic configuration sync: write custom content to files on save (auto export)
 * and read them back on another site (auto import).
 *
 * Two on-disk layouts share one set of row hashes, so switching never duplicates rows:
 *
 * - `single` (legacy): `{path}/ewp_configuration.json`, every exported type in one file.
 * - `per_object` (format 2): `{path}/ewp-config/format.json` plus
 *   `{path}/ewp-config/{content_type}/{hash}.json`, one row with its meta per file, written
 *   deterministically (sorted keys, no volatile top-level data) so two developers touching
 *   different objects never conflict in git.
 *
 * The one implementation behind the thin hook wrappers of `Extend_WP_Import_Export` and the
 * `ewp/v1/config-sync/*` routes, `wp ewp content sync *` and the `ewp-content/sync-*` abilities.
 *
 * @package    EWP\Content
 * @author     Motivar
 *
 * @since 1.15.0
 */
final class Config_Sync
{
    /** Legacy single file name. */
    const LEGACY_FILE = 'ewp_configuration.json';

    /** Name the legacy file gets once migrated. */
    const LEGACY_BACKUP = 'ewp_configuration.legacy.json';

    /** Folder of the per-object layout, inside the configured path. */
    const DIR = 'ewp-config';

    /** Marker file of the per-object layout. */
    const FORMAT_FILE = 'format.json';

    /** Version written to the marker file. */
    const FORMAT_VERSION = 2;

    /** Layout: everything in `ewp_configuration.json`. */
    const LAYOUT_SINGLE = 'single';

    /** Layout: one file per object. */
    const LAYOUT_PER_OBJECT = 'per_object';

    /** Option holding the UI export settings (store, types, path, layout). */
    const OPTION_EXPORT = 'ewp_auto_export_settings';

    /** Option holding the UI import settings (import, import_type, path). */
    const OPTION_IMPORT = 'ewp_auto_import_settings';

    /** Option holding the signature of the last imported source. */
    const OPTION_SIGNATURE = 'ewp_file_import_signature';

    /** Option holding type => hashes imported last time (the only rows an import may delete). */
    const OPTION_HASHES = 'ewp_auto_imported_hashes';

    /** Transient holding notices raised outside an admin page (a save, a REST call). */
    const TRANSIENT_NOTICES = 'ewp_config_sync_notices';

    /** Logger action type of a configuration export (auto on save/delete, sync export, migrate). */
    const LOG_EXPORT = 'config_auto_export';

    /** Logger action type of a configuration import (auto on admin_init, sync import). */
    const LOG_IMPORT = 'config_auto_import';

    /**
     * Register the configuration sync action types with the EWP Logger.
     *
     * @return void
     *
     * @hook ewp_logger_initialized
     * @since 1.15.0
     */
    public static function register_log_types()
    {
        if (!function_exists('ewp_register_log_type')) {
            return;
        }
        ewp_register_log_type('extend-wp', self::LOG_EXPORT, 'Config Auto Export', 'The EWP configuration files were written (auto export on save or delete, sync export, migration).');
        ewp_register_log_type('extend-wp', self::LOG_IMPORT, 'Config Auto Import', 'The EWP configuration files were imported (auto import on an admin page load, or sync import).');
    }

    /**
     * Write a configuration sync entry to the EWP Logger when it is available.
     *
     * @param string $type      LOG_EXPORT or LOG_IMPORT.
     * @param string $message   Summary.
     * @param array  $data      Details (trigger, types, path, counts or error).
     * @param int    $behaviour 1 success, 0 error, 2 warning.
     *
     * @return void
     */
    private static function log($type, $message, array $data, $behaviour = 1)
    {
        if (function_exists('ewp_log')) {
            ewp_log('extend-wp', $type, $message, $data, 'developer', 'custom_content', $behaviour);
        }
    }

    /** @var Content_Portability */
    private $portability;

    /** @var array<int,array{level:string,message:string}> Notices raised during this request. */
    private static $notices = [];

    /**
     * @param Content_Portability|null $portability Row export/import service (default: a new one).
     */
    public function __construct(?Content_Portability $portability = null)
    {
        $this->portability = $portability ?: new Content_Portability();
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    /**
     * Whether a stored export setting asks for auto export without the `layout` key.
     *
     * Before 1.15.0 the stored option was never read (its name had a trailing space), so a
     * site may hold `store = on` for years without ever having exported. Such a setting stays
     * dormant until the admin saves the settings page again, which writes `layout`.
     *
     * @return bool
     *
     * @since 1.15.0
     */
    public function has_dormant_export_settings()
    {
        return self::is_dormant(self::stored_export_settings());
    }

    /**
     * The effective export settings: stored option, dormant guard, then the
     * `ewp_auto_export_settings_filter` filter. `layout` is always present.
     *
     * @param bool $ignore_dormant True for explicit commands (export, migrate) that act on a stored configuration regardless of `store`.
     *
     * @return array {store: bool, types: string[], path: string, layout: 'single'|'per_object'}
     *
     * @since 1.15.0
     */
    public function export_settings($ignore_dormant = false)
    {
        $stored = self::stored_export_settings();
        if (!$ignore_dormant && self::is_dormant($stored)) {
            $stored['store'] = false;
        }

        /**
         * Filter the auto export settings.
         *
         * @since 1.0.0
         *
         * @param array $settings {store: bool, types: string[], path: string, layout?: 'single'|'per_object'}.
         *                        A missing `layout` means the legacy single file.
         */
        $settings = apply_filters('ewp_auto_export_settings_filter', $stored);
        $settings = is_array($settings) ? $settings : [];

        $settings['layout'] = self::normalize_layout(isset($settings['layout']) ? $settings['layout'] : '');

        return $settings;
    }

    /**
     * The effective import settings (stored option through `ewp_auto_import_settings_filter`).
     *
     * @return array {import: bool, import_type: string, path: string}
     *
     * @since 1.15.0
     */
    public function import_settings()
    {
        /**
         * Filter the auto import settings.
         *
         * @since 1.0.0
         *
         * @param array $settings {import: bool, import_type: string, path: string}.
         */
        $settings = apply_filters('ewp_auto_import_settings_filter', get_option(self::OPTION_IMPORT, []));

        return is_array($settings) ? $settings : [];
    }

    /**
     * Layout the settings page proposes: the legacy single file where one exists at the path, else per object.
     *
     * @param string $path Configured path, relative to wp-content.
     *
     * @return string `single` or `per_object`.
     *
     * @since 1.15.0
     */
    public function default_layout($path)
    {
        $dir = $this->resolve_dir($path);
        if (is_wp_error($dir)) {
            return self::LAYOUT_PER_OBJECT;
        }

        return is_file($dir . self::LEGACY_FILE) ? self::LAYOUT_SINGLE : self::LAYOUT_PER_OBJECT;
    }

    /**
     * @param mixed $layout Requested layout.
     *
     * @return string `per_object` when asked for, `single` for anything else.
     *
     * @since 1.15.0
     */
    public static function normalize_layout($layout)
    {
        return $layout === self::LAYOUT_PER_OBJECT ? self::LAYOUT_PER_OBJECT : self::LAYOUT_SINGLE;
    }

    /**
     * Absolute, slash-terminated directory of a configured path.
     *
     * @param string $path Folder relative to wp-content (a leading slash is optional).
     *
     * @return string|\WP_Error Error for an empty path or a path that climbs out with `..`.
     *
     * @since 1.15.0
     */
    public function resolve_dir($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '') {
            return new \WP_Error('ewp_config_sync_path', __('No path is configured.', 'extend-wp'), ['status' => 400]);
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return new \WP_Error('ewp_config_sync_path', __('The path must not contain "..".', 'extend-wp'), ['status' => 400]);
        }

        return untrailingslashit(WP_CONTENT_DIR) . '/' . $path . '/';
    }

    /**
     * @return array The stored export option as an array.
     */
    private static function stored_export_settings()
    {
        $stored = get_option(self::OPTION_EXPORT, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array $stored Stored export option.
     *
     * @return bool Auto export is on but `layout` was never written.
     */
    private static function is_dormant(array $stored)
    {
        return !empty($stored['store']) && !array_key_exists('layout', $stored);
    }

    /* ---------------------------------------------------------------------
     * Auto export (hooks)
     * ------------------------------------------------------------------ */

    /**
     * A content row was saved: refresh its file (per object) or the single file.
     *
     * @param string $content_type Content type id.
     * @param int    $object_id    Saved content id.
     *
     * @return true|null|\WP_Error Null when auto export is off or does not cover the type; errors are logged and raised as an admin notice, never thrown.
     *
     * @since 1.15.0
     */
    public function handle_saved($content_type, $object_id)
    {
        return $this->run_export_hook($content_type, ['trigger' => 'save', 'object_id' => (int) $object_id], function (array $options, $dir) use ($content_type, $object_id) {
            if ($options['layout'] === self::LAYOUT_PER_OBJECT) {
                return $this->write_object($dir, (string) $content_type, (int) $object_id);
            }

            return $this->write_single($dir, $options['types']);
        });
    }

    /**
     * Rows of a content type were deleted: drop the files of rows that no longer exist (per object) or rewrite the single file.
     *
     * @param string $content_type Content type id.
     *
     * @return true|null|\WP_Error As handle_saved().
     *
     * @since 1.15.0
     */
    public function handle_deleted($content_type)
    {
        return $this->run_export_hook($content_type, ['trigger' => 'delete'], function (array $options, $dir) use ($content_type) {
            if ($options['layout'] === self::LAYOUT_PER_OBJECT) {
                $removed = $this->reconcile_type($dir, (string) $content_type);

                return is_wp_error($removed) ? $removed : true;
            }

            return $this->write_single($dir, $options['types']);
        });
    }

    /**
     * Shared guard of the export hooks: settings, type coverage, path; then run $action.
     *
     * Every run that writes (or fails) is logged as `config_auto_export`.
     *
     * @param string   $content_type Content type id.
     * @param array    $context      Log details: `trigger` (save|delete), optional `object_id`.
     * @param callable $action       fn(array $options, string $dir): true|\WP_Error.
     *
     * @return true|null|\WP_Error
     */
    private function run_export_hook($content_type, array $context, callable $action)
    {
        $options = $this->export_settings();
        if (empty($options['store'])) {
            return null;
        }

        $result = $this->guard_export($options, (string) $content_type);
        if ($result === null) {
            return null;
        }
        if (!is_wp_error($result)) {
            $result = $action($options, $result);
        }

        $data = array_merge($context, ['content_type' => (string) $content_type, 'layout' => $options['layout'], 'path' => (string) $options['path']]);
        if (is_wp_error($result)) {
            self::log(self::LOG_EXPORT, sprintf('Config auto export (%s of %s) failed: %s', $context['trigger'], $content_type, $result->get_error_message()), $data + ['error' => $result->get_error_message()], 0);

            return $this->report_error($result);
        }
        self::log(self::LOG_EXPORT, sprintf('Config auto export (%s of %s) wrote the %s layout', $context['trigger'], $content_type, $options['layout']), $data);

        return $result;
    }

    /**
     * @param array  $options      Effective export settings (store on).
     * @param string $content_type Content type id.
     *
     * @return string|null|\WP_Error The writable directory, null when the type is not covered, or an error.
     */
    private function guard_export(array $options, $content_type)
    {
        if (empty($options['path']) || empty($options['types'])) {
            return new \WP_Error('ewp_config_sync_incomplete', __('Export settings are incomplete or disabled.', 'extend-wp'));
        }
        if (!in_array($content_type, array_map('strval', (array) $options['types']), true)) {
            return null;
        }

        return $this->writable_dir($options['path']);
    }

    /**
     * @param string $path Configured path.
     *
     * @return string|\WP_Error An existing, writable directory.
     */
    private function writable_dir($path)
    {
        $dir = $this->resolve_dir($path);
        if (is_wp_error($dir)) {
            return $dir;
        }
        if (!is_dir($dir)) {
            /* translators: %s: directory. */
            return new \WP_Error('ewp_config_sync_path', sprintf(__('The path %s does not exist.', 'extend-wp'), $dir));
        }
        if (!is_writable($dir)) {
            /* translators: %s: directory. */
            return new \WP_Error('ewp_config_sync_path', sprintf(__('The path %s is not writable.', 'extend-wp'), $dir));
        }

        return $dir;
    }

    /**
     * Log an error and queue it as an admin notice, so a failing sync never breaks a save.
     *
     * @param \WP_Error $error Error.
     *
     * @return \WP_Error The same error.
     */
    private function report_error(\WP_Error $error)
    {
        /* translators: %s: error message. */
        $message = sprintf(__('Configuration export failed: %s', 'extend-wp'), $error->get_error_message());
        error_log('[extend-wp] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer log.
        self::notify('error', $message, true);

        return $error;
    }

    /* ---------------------------------------------------------------------
     * Export: writers
     * ------------------------------------------------------------------ */

    /**
     * Write the legacy single file (every configured type).
     *
     * @param string   $dir   Slash-terminated target directory.
     * @param string[] $types Content type ids.
     *
     * @return true|\WP_Error
     */
    private function write_single($dir, array $types)
    {
        $data = $this->portability->export(array_values($types), ['log' => false]);
        if (is_wp_error($data) && $data->get_error_code() === 'no_data') {
            /* Every row was deleted: keep the file, empty, so importing sites drop what they imported. */
            $data = ['modified' => date('Y-m-d H:i:s')];
        }
        if (is_wp_error($data)) {
            /* translators: %s: error message. */
            return new \WP_Error('ewp_config_sync_export', sprintf(__('Failed to export content: %s', 'extend-wp'), $data->get_error_message()));
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new \WP_Error('ewp_config_sync_json', __('Failed to encode export data to JSON.', 'extend-wp'));
        }

        $written = $this->write_atomic($dir . self::LEGACY_FILE, $json);

        return is_wp_error($written) ? $written : true;
    }

    /**
     * Write the file of one row (per object layout).
     *
     * @param string $dir          Slash-terminated target directory.
     * @param string $content_type Content type id.
     * @param int    $content_id   Row id.
     *
     * @return true|\WP_Error
     */
    private function write_object($dir, $content_type, $content_id)
    {
        $row = $this->portability->find_row($content_type, $content_id);
        if ($row === null) {
            return true;
        }

        $ready = $this->ensure_layout($dir);
        if (is_wp_error($ready)) {
            return $ready;
        }

        $written = $this->write_row_file($dir, $content_type, $row);

        return is_wp_error($written) ? $written : true;
    }

    /**
     * Create `ewp-config/` and its marker file when missing.
     *
     * @param string $dir Slash-terminated target directory.
     *
     * @return true|\WP_Error
     */
    private function ensure_layout($dir)
    {
        $root = $dir . self::DIR;
        if (!wp_mkdir_p($root)) {
            /* translators: %s: directory. */
            return new \WP_Error('ewp_config_sync_path', sprintf(__('Could not create %s.', 'extend-wp'), $root));
        }
        $marker = $root . '/' . self::FORMAT_FILE;
        if (is_file($marker)) {
            return true;
        }
        $written = $this->write_atomic($marker, $this->encode_json(['format' => self::FORMAT_VERSION]));

        return is_wp_error($written) ? $written : true;
    }

    /**
     * Write one row to `ewp-config/{type}/{filename}.json`.
     *
     * @param string $dir          Slash-terminated target directory.
     * @param string $content_type Content type id.
     * @param array  $row          Row with meta and hash.
     *
     * @return bool|\WP_Error True when the file changed, false when it already had these bytes.
     */
    private function write_row_file($dir, $content_type, array $row)
    {
        $file = $this->object_file($content_type, $row);
        if ($file === null || is_wp_error($file)) {
            return $file === null ? false : $file;
        }
        $type_dir = dirname($dir . $file['path']);
        if (!wp_mkdir_p($type_dir)) {
            /* translators: %s: directory. */
            return new \WP_Error('ewp_config_sync_path', sprintf(__('Could not create %s.', 'extend-wp'), $type_dir));
        }

        return $this->write_atomic($dir . $file['path'], $file['contents']);
    }

    /**
     * The per object file of one row: its path inside the export root and its bytes. The single
     * source of the per object layout, used by the auto export, `sync export|migrate` and the
     * per object (zip) export of the Import/Export screen, so all of them produce identical files.
     *
     * @param string $content_type Content type id.
     * @param array  $row          Row with meta and hash.
     *
     * @return array{path: string, contents: string}|null|\WP_Error `ewp-config/{type}/{name}.json` and its JSON;
     *                                                              null for a row without a usable hash; error for an unsafe type id.
     *
     * @since 1.15.0
     */
    public function object_file($content_type, array $row)
    {
        $type_dir = $this->type_dir('', $content_type);
        if (is_wp_error($type_dir)) {
            return $type_dir;
        }
        $name = $this->filename_for($content_type, $row);
        if ($name === null) {
            return null;
        }

        return ['path' => $type_dir . '/' . $name, 'contents' => $this->encode_json($this->portable_row($row))];
    }

    /**
     * Every file of the per object layout for the given types, in memory: the marker file plus one
     * file per row, exactly as the auto export writes them. Used for the zip download.
     *
     * @param string[] $content_types Content type ids.
     *
     * @return array|\WP_Error {files: path => contents (paths start with `ewp-config/`), types: type => rows};
     *                         an error when no row was found or a type id is unsafe.
     *
     * @since 1.15.0
     */
    public function build_files(array $content_types)
    {
        $files = [self::DIR . '/' . self::FORMAT_FILE => $this->encode_json(['format' => self::FORMAT_VERSION])];
        $types = [];
        foreach (array_values(array_filter(array_map('strval', $content_types))) as $type) {
            $error = null;
            $count = $this->portability->walk_rows($type, function (array $row) use ($type, &$files, &$error) {
                $file = $error === null ? $this->object_file($type, $row) : null;
                if (is_wp_error($file)) {
                    $error = $file;
                    return;
                }
                if ($file !== null) {
                    $files[$file['path']] = $file['contents'];
                }
            });
            if ($error !== null) {
                return $error;
            }
            if ($count > 0) {
                $types[$type] = $count;
            }
        }
        if ($types === []) {
            return new \WP_Error('no_data', __('No data to export', 'extend-wp'), ['status' => 400]);
        }

        return ['files' => $files, 'types' => $types];
    }

    /**
     * Re-export every configured row in the given layout.
     *
     * Logged as `config_auto_export` with the `trigger` (default `manual`).
     *
     * @param array|null $options {types: string[], path: string, layout?: string, trigger?: string}; null for the effective settings.
     *
     * @return array|\WP_Error {layout, path, types: type => rows, written: files changed, removed: files deleted}.
     *
     * @since 1.15.0
     */
    public function export(?array $options = null)
    {
        $options = $options ?: $this->export_settings(true);
        $result  = $this->run_full_export($options);
        $data    = [
            'trigger' => isset($options['trigger']) ? (string) $options['trigger'] : 'manual',
            'types'   => array_values(array_map('strval', (array) (isset($options['types']) ? $options['types'] : []))),
            'layout'  => self::normalize_layout(isset($options['layout']) ? $options['layout'] : ''),
            'path'    => isset($options['path']) ? (string) $options['path'] : '',
        ];
        if (is_wp_error($result)) {
            self::log(self::LOG_EXPORT, sprintf('Config export (%s) failed: %s', $data['trigger'], $result->get_error_message()), $data + ['error' => $result->get_error_message()], 0);

            return $result;
        }
        self::log(self::LOG_EXPORT, sprintf('Config export (%s): %d file(s) written, %d removed', $data['trigger'], (int) $result['written'], (int) $result['removed']), $data + ['rows' => $result['types'], 'written' => $result['written'], 'removed' => $result['removed']]);

        return $result;
    }

    /**
     * The export itself (see export()), without logging.
     *
     * @param array $options Export settings.
     *
     * @return array|\WP_Error As export().
     */
    private function run_full_export(array $options)
    {
        $dir     = $this->prepared_dir($options);
        if (is_wp_error($dir)) {
            return $dir;
        }

        $layout = self::normalize_layout(isset($options['layout']) ? $options['layout'] : '');
        $types  = array_values(array_map('strval', (array) $options['types']));
        if ($layout === self::LAYOUT_SINGLE) {
            $written = $this->write_single($dir, $types);

            return is_wp_error($written) ? $written : ['layout' => $layout, 'path' => $dir, 'types' => [], 'written' => 1, 'removed' => 0];
        }

        return $this->export_per_object($dir, $types);
    }

    /**
     * @param array $options Settings with `path` and `types`.
     *
     * @return string|\WP_Error Writable directory, or an error when path/types are missing.
     */
    private function prepared_dir(array $options)
    {
        if (empty($options['path']) || empty($options['types'])) {
            return new \WP_Error('ewp_config_sync_incomplete', __('Export settings are incomplete: set the content types and the path.', 'extend-wp'), ['status' => 400]);
        }

        return $this->writable_dir($options['path']);
    }

    /**
     * Write every row of the given types to its own file and drop files of rows that are gone.
     *
     * @param string   $dir   Slash-terminated target directory.
     * @param string[] $types Content type ids.
     *
     * @return array|\WP_Error As export().
     */
    private function export_per_object($dir, array $types)
    {
        $ready = $this->ensure_layout($dir);
        if (is_wp_error($ready)) {
            return $ready;
        }

        $summary = ['layout' => self::LAYOUT_PER_OBJECT, 'path' => $dir, 'types' => [], 'written' => 0, 'removed' => 0];
        foreach ($types as $type) {
            $error = null;
            $count = $this->portability->walk_rows($type, function (array $row) use ($dir, $type, &$summary, &$error) {
                $result = $error === null ? $this->write_row_file($dir, $type, $row) : false;
                if (is_wp_error($result)) {
                    $error = $result;
                    return;
                }
                $summary['written'] += $result ? 1 : 0;
            });
            if ($error !== null) {
                return $error;
            }
            $removed = $this->reconcile_type($dir, $type);
            if (is_wp_error($removed)) {
                return $removed;
            }
            $summary['types'][$type] = $count;
            $summary['removed']     += $removed;
        }

        return $summary;
    }

    /**
     * Delete the files of a type whose row no longer exists in the database.
     *
     * @param string $dir          Slash-terminated target directory.
     * @param string $content_type Content type id.
     *
     * @return int|\WP_Error Files removed.
     */
    private function reconcile_type($dir, $content_type)
    {
        $type_dir = $this->type_dir($dir, $content_type);
        if (is_wp_error($type_dir)) {
            return $type_dir;
        }
        /* Never prune from a type whose tables are missing: an empty read there is not "no rows". */
        if (!is_dir($type_dir) || !\AWM_DB_Creator::check_table_exists($content_type . '_main')) {
            return 0;
        }

        $expected = [];
        foreach ((array) awm_get_db_content($content_type, ['limit' => -1]) as $row) {
            $name = is_array($row) && !empty($row['hash']) ? $this->filename_for($content_type, $row) : null;
            if ($name !== null) {
                $expected[$name] = true;
            }
        }

        $removed = 0;
        foreach ((array) glob($type_dir . '/*.json') as $file) {
            if (!isset($expected[basename($file)]) && @unlink($file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- counted below.
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param string $dir          Slash-terminated target directory.
     * @param string $content_type Content type id.
     *
     * @return string|\WP_Error `{dir}ewp-config/{type}`; error for a type id that is not a safe folder name.
     */
    private function type_dir($dir, $content_type)
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $content_type)) {
            /* translators: %s: content type id. */
            return new \WP_Error('ewp_config_sync_type', sprintf(__('The content type %s cannot be used as a folder name.', 'extend-wp'), $content_type));
        }

        return $dir . self::DIR . '/' . $content_type;
    }

    /**
     * File name of a row.
     *
     * @param string $content_type Content type id.
     * @param array  $row          A `{type}_main` row (meta is not guaranteed).
     *
     * @return string|null Null for a row without a usable hash.
     */
    private function filename_for($content_type, array $row)
    {
        $hash = isset($row['hash']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $row['hash']) : '';
        if ($hash === '') {
            return null;
        }

        /**
         * Filter the file name of a row in the per object configuration layout.
         *
         * The name must be a plain `*.json` file name (letters, digits, `.`, `_`, `-`); anything
         * else falls back to `{hash}.json`. Keep it unique per row and stable across saves:
         * renaming makes the old file look orphaned and the next delete or export removes it.
         *
         * @since 1.15.0
         *
         * @param string $filename     File name, `{hash}.json` by default.
         * @param string $content_type Content type id.
         * @param array  $row          The row's main-table columns (without `meta`).
         */
        $name = apply_filters('ewp_config_sync_filename', $hash . '.json', $content_type, $row);

        return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*\.json$/', $name) ? $name : $hash . '.json';
    }

    /**
     * A row without the values that differ per environment and carry no configuration.
     *
     * @param array $row Row with meta.
     *
     * @return array
     */
    private function portable_row(array $row)
    {
        unset($row['content_id'], $row['user_id']);

        return $row;
    }

    /**
     * Deterministic JSON: sorted keys, pretty printed, unescaped slashes and unicode, trailing newline.
     *
     * @param mixed $data Data to encode.
     *
     * @return string
     *
     * @since 1.15.0
     */
    public function encode_json($data)
    {
        $json = json_encode(self::sort_keys($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return (string) $json . "\n";
    }

    /**
     * Sort the keys of every associative array, recursively. Lists keep their order.
     *
     * @param mixed $value Any value.
     *
     * @return mixed
     */
    private static function sort_keys($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sort_keys($item);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Write a file atomically (temp file in the same folder, then rename), unless it already holds these bytes.
     *
     * @param string $file     Target path.
     * @param string $contents Bytes.
     *
     * @return bool|\WP_Error True when written, false when unchanged.
     */
    private function write_atomic($file, $contents)
    {
        if (is_file($file) && file_get_contents($file) === $contents) {
            return false;
        }

        $tmp = dirname($file) . '/.' . basename($file) . '.' . wp_generate_password(8, false) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            /* translators: %s: file path. */
            return new \WP_Error('ewp_config_sync_write', sprintf(__('Failed to save exported content to %s.', 'extend-wp'), $file));
        }
        if (!@rename($tmp, $file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
            /* translators: %s: file path. */
            return new \WP_Error('ewp_config_sync_write', sprintf(__('Failed to save exported content to %s.', 'extend-wp'), $file));
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Import
     * ------------------------------------------------------------------ */

    /**
     * Import on `admin_init`: administrators only, never on admin-ajax; raises notices instead of throwing.
     *
     * @return true|null True after an import; null when nothing was due or the import failed (the error is logged and shown as a notice).
     *
     * @since 1.15.0
     */
    public function auto_import()
    {
        if (wp_doing_ajax() || !current_user_can('manage_options')) {
            return null;
        }

        $options = $this->import_settings();
        if (empty($options['import']) || empty($options['path']) || !isset($options['import_type']) || $options['import_type'] !== 'auto') {
            return null;
        }

        $result = $this->import($options + ['trigger' => 'admin_init']);
        if (is_wp_error($result)) {
            /* translators: %s: error message. */
            $message = sprintf(__('Import failed: %s. Please check your php error_logs.', 'extend-wp'), $result->get_error_message());
            error_log('[extend-wp] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer log.
            self::notify('error', $message);

            return null;
        }
        if ($result['status'] !== 'imported') {
            return null;
        }

        self::notify('success', __('EWP Content has been successfully imported.', 'extend-wp'));

        return true;
    }

    /**
     * Import the configuration found at a path, detecting the layout.
     *
     * `{path}/ewp-config/format.json` means the per object layout (it wins over a leftover
     * legacy file, with a notice); otherwise `ewp_configuration.json` is read as before.
     * The import is an upsert by row hash. Afterwards, rows whose hash this site imported
     * last time but that the source no longer lists are deleted; rows created here are never touched.
     *
     * @param array $options {
     *     @type string $path  Folder relative to wp-content (required).
     *     @type bool   $force   Import even if the source did not change since the last import.
     *     @type string $trigger Log detail: `admin_init` (auto import) or `manual` (default).
     * }
     *
     * Every import that ran (or failed) is logged as `config_auto_import`; an unchanged source is not logged.
     *
     * @return array|\WP_Error {status: 'imported'|'unchanged', format: 'v2'|'legacy', types: type => rows, deleted: int}
     *
     * @since 1.15.0
     */
    public function import(array $options)
    {
        $result = $this->run_import($options);
        $data   = ['trigger' => isset($options['trigger']) ? (string) $options['trigger'] : 'manual', 'path' => isset($options['path']) ? (string) $options['path'] : ''];
        if (is_wp_error($result)) {
            self::log(self::LOG_IMPORT, sprintf('Config import (%s) failed: %s', $data['trigger'], $result->get_error_message()), $data + ['error' => $result->get_error_message()], 0);

            return $result;
        }
        if ($result['status'] === 'imported') {
            self::log(self::LOG_IMPORT, sprintf('Config import (%s, %s layout): %d row(s) imported, %d deleted', $data['trigger'], $result['format'], array_sum((array) $result['types']), (int) $result['deleted']), $data + ['format' => $result['format'], 'rows' => $result['types'], 'deleted' => $result['deleted']], $result['deleted'] > 0 ? 2 : 1);
        }

        return $result;
    }

    /**
     * The import itself (see import()), without logging.
     *
     * @param array $options As import().
     *
     * @return array|\WP_Error As import().
     */
    private function run_import(array $options)
    {
        $dir = $this->resolve_dir(isset($options['path']) ? $options['path'] : '');
        if (is_wp_error($dir)) {
            return $dir;
        }
        if (!is_dir($dir)) {
            /* translators: %s: directory. */
            return new \WP_Error('ewp_config_sync_path', sprintf(__('The path %s does not exist.', 'extend-wp'), $dir), ['status' => 400]);
        }

        $format = $this->detect_format($dir);
        if ($format === 'none') {
            /* translators: %s: file path. */
            return new \WP_Error('ewp_config_sync_missing', sprintf(__('The file %s does not exist.', 'extend-wp'), $dir . self::LEGACY_FILE), ['status' => 404]);
        }
        if ($format === 'both') {
            self::notify('warning', __('Both the per object configuration folder (ewp-config) and ewp_configuration.json exist. The folder is used; the single file is ignored and can be deleted.', 'extend-wp'));
        }

        $use       = $format === 'legacy' ? 'legacy' : 'v2';
        $signature = $this->source_signature($dir, $use);
        if (empty($options['force']) && get_option(self::OPTION_SIGNATURE) === $signature) {
            return ['status' => 'unchanged', 'format' => $use, 'types' => [], 'deleted' => 0];
        }

        $sources = $use === 'v2' ? $this->read_per_object($dir) : $this->read_single($dir . self::LEGACY_FILE);
        if (is_wp_error($sources)) {
            return $sources;
        }

        $hashes = $this->import_sources($sources);
        if (is_wp_error($hashes)) {
            return $hashes;
        }

        $old     = get_option(self::OPTION_HASHES);
        $deleted = $this->delete_stale(is_array($old) ? $old : [], $hashes);
        update_option(self::OPTION_SIGNATURE, $signature, false);
        update_option(self::OPTION_HASHES, $hashes, false);

        if (function_exists('ewp_flush_cache')) {
            ewp_flush_cache();
        }

        return ['status' => 'imported', 'format' => $use, 'types' => array_map('count', $hashes), 'deleted' => $deleted];
    }

    /**
     * Which layout a folder holds.
     *
     * @param string $dir Slash-terminated directory.
     *
     * @return string `v2`, `legacy`, `both` or `none`.
     *
     * @since 1.15.0
     */
    public function detect_format($dir)
    {
        $v2     = is_file($dir . self::DIR . '/' . self::FORMAT_FILE);
        $legacy = is_file($dir . self::LEGACY_FILE);
        if ($v2 && $legacy) {
            return 'both';
        }

        return $v2 ? 'v2' : ($legacy ? 'legacy' : 'none');
    }

    /**
     * Change signature of a source: the legacy file's md5, or for the per object layout a hash of
     * every file's relative path, size and mtime (cheap enough for every admin request; a checkout
     * that only touches mtimes costs one harmless re-upsert).
     *
     * @param string $dir    Slash-terminated directory.
     * @param string $format `v2` or `legacy`.
     *
     * @return string
     */
    private function source_signature($dir, $format)
    {
        if ($format === 'legacy') {
            return (string) md5_file($dir . self::LEGACY_FILE);
        }

        clearstatcache();
        $entries = [];
        foreach ($this->per_object_files($dir) as $relative => $file) {
            $entries[] = $relative . '|' . filesize($file) . '|' . filemtime($file);
        }
        sort($entries, SORT_STRING);

        return 'v2:' . md5(implode("\n", $entries));
    }

    /**
     * @param string $dir Slash-terminated directory.
     *
     * @return array<string,string> Relative path => absolute path of `format.json` and every `{type}/*.json`.
     */
    private function per_object_files($dir)
    {
        $root  = $dir . self::DIR;
        $files = [self::FORMAT_FILE => $root . '/' . self::FORMAT_FILE];
        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $type_dir) {
            $type = basename($type_dir);
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $type)) {
                continue;
            }
            foreach ((array) glob($type_dir . '/*.json') as $file) {
                $files[$type . '/' . basename($file)] = $file;
            }
        }

        return $files;
    }

    /**
     * @param string $dir Slash-terminated directory.
     *
     * @return array|\WP_Error type => rows.
     */
    private function read_per_object($dir)
    {
        $sources = [];
        foreach ($this->per_object_files($dir) as $relative => $file) {
            if ($relative === self::FORMAT_FILE) {
                continue;
            }
            $row = $this->read_json_file($file);
            if (is_wp_error($row)) {
                return $row;
            }
            $sources[dirname($relative)][] = $row;
        }

        if ($sources === []) {
            return new \WP_Error('ewp_config_sync_empty', __('The configuration folder contains no objects.', 'extend-wp'), ['status' => 400]);
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param string $file Path of the legacy single file.
     *
     * @return array|\WP_Error type => rows.
     */
    private function read_single($file)
    {
        $data = $this->read_json_file($file);
        if (is_wp_error($data)) {
            return $data;
        }

        $sources = [];
        foreach ($data as $content_type => $items) {
            if ($content_type === 'modified' || $content_type === 'pagination') {
                continue;
            }
            $sources[(string) $content_type] = is_array($items) ? $items : [];
        }

        return $sources;
    }

    /**
     * @param string $file JSON file.
     *
     * @return array|\WP_Error
     */
    private function read_json_file($file)
    {
        $contents = @file_get_contents($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
        $data     = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data)) {
            /* translators: %s: file path. */
            return new \WP_Error('ewp_config_sync_json', sprintf(__('Invalid JSON in the configuration file %s.', 'extend-wp'), $file), ['status' => 400]);
        }

        return $data;
    }

    /**
     * Upsert every type's rows.
     *
     * @param array $sources type => rows.
     *
     * @return array|\WP_Error type => imported hashes.
     */
    private function import_sources(array $sources)
    {
        $hashes = [];
        foreach ($sources as $content_type => $rows) {
            $result = $this->portability->import((string) $content_type, array_values($rows), ['log' => false]);
            if (is_wp_error($result) || empty($result['hashes'])) {
                /* translators: %s: content type id. */
                return new \WP_Error('ewp_config_sync_import', sprintf(__('Failed to import content of type %s.', 'extend-wp'), $content_type), ['status' => 400]);
            }
            $hashes[(string) $content_type] = $result['hashes'];
        }

        return $hashes;
    }

    /**
     * Delete the rows this site auto-imported before and the source no longer lists.
     *
     * Rows are resolved from their hash and deleted by content id (the data table has no hash column).
     *
     * @param array $old type => hashes of the previous import.
     * @param array $new type => hashes of this import.
     *
     * @return int Rows deleted.
     */
    private function delete_stale(array $old, array $new)
    {
        $deleted = 0;
        foreach ($old as $content_type => $hashes) {
            if (!is_array($hashes) || $hashes === [] || !\AWM_DB_Creator::check_table_exists($content_type . '_main')) {
                continue;
            }
            $unused = array_values(array_filter(array_diff($hashes, isset($new[$content_type]) ? $new[$content_type] : []), 'strlen'));
            if ($unused === []) {
                continue;
            }
            $rows = awm_get_db_content((string) $content_type, ['fields' => ['content_id', 'hash'], 'include_hashes' => $unused]);
            $ids  = array_values(array_filter(array_map('intval', array_column((array) $rows, 'content_id'))));
            if ($ids !== [] && awm_custom_content_delete((string) $content_type, $ids)) {
                $deleted += count($ids);
            }
        }

        return $deleted;
    }

    /* ---------------------------------------------------------------------
     * Status and migration
     * ------------------------------------------------------------------ */

    /**
     * Where the configuration sync stands.
     *
     * @return array {
     *     layout: string, auto_export: bool, dormant: bool, types: string[], export_path: string, import_path: string,
     *     import_enabled: bool, per_object_files: int, legacy_file: bool, legacy_backup: bool,
     *     last_import_signature: string, imported_hashes: int
     * }
     *
     * @since 1.15.0
     */
    public function status()
    {
        $export = $this->export_settings(true);
        $import = $this->import_settings();
        $dir    = $this->resolve_dir(isset($export['path']) ? $export['path'] : '');
        $hashes = get_option(self::OPTION_HASHES);

        $files = is_wp_error($dir) ? [] : array_diff_key($this->per_object_files($dir), [self::FORMAT_FILE => true]);

        return [
            'layout'                => $export['layout'],
            'auto_export'           => !empty($this->export_settings()['store']),
            'dormant'               => $this->has_dormant_export_settings(),
            'types'                 => array_values(array_map('strval', (array) (isset($export['types']) ? $export['types'] : []))),
            'export_path'           => is_wp_error($dir) ? '' : $dir,
            'import_path'           => isset($import['path']) ? (string) $import['path'] : '',
            'import_enabled'        => !empty($import['import']),
            'per_object_files'      => count(array_filter($files, 'is_file')),
            'legacy_file'           => !is_wp_error($dir) && is_file($dir . self::LEGACY_FILE),
            'legacy_backup'         => !is_wp_error($dir) && is_file($dir . self::LEGACY_BACKUP),
            'last_import_signature' => (string) get_option(self::OPTION_SIGNATURE, ''),
            'imported_hashes'       => is_array($hashes) ? array_sum(array_map('count', array_filter($hashes, 'is_array'))) : 0,
        ];
    }

    /**
     * Move a site from the single file to one file per object.
     *
     * Writes `ewp-config/` from the database, renames `ewp_configuration.json` to
     * `ewp_configuration.legacy.json` (importing sites then stop seeing the old file) and stores
     * `layout = per_object` in the export option.
     *
     * @return array|\WP_Error {export: array (see export()), legacy_renamed: bool, layout_saved: bool, warnings: string[]}
     *
     * @since 1.15.0
     */
    public function migrate()
    {
        $options = $this->export_settings(true);
        $options['layout']  = self::LAYOUT_PER_OBJECT;
        $options['trigger'] = 'migrate';

        $summary = $this->export($options);
        if (is_wp_error($summary)) {
            return $summary;
        }

        $dir      = $summary['path'];
        $warnings = [];
        $renamed  = $this->retire_legacy_file($dir);
        if (is_wp_error($renamed)) {
            return $renamed;
        }

        $stored           = self::stored_export_settings();
        $stored['layout'] = self::LAYOUT_PER_OBJECT;
        update_option(self::OPTION_EXPORT, $stored);
        $saved = $this->export_settings(true)['layout'] === self::LAYOUT_PER_OBJECT;
        if (!$saved) {
            $warnings[] = __('The export settings come from the ewp_auto_export_settings_filter filter; add \'layout\' => \'per_object\' there too.', 'extend-wp');
        }

        return ['export' => $summary, 'legacy_renamed' => $renamed, 'layout_saved' => $saved, 'warnings' => $warnings];
    }

    /**
     * Rename the legacy single file out of the way.
     *
     * @param string $dir Slash-terminated directory.
     *
     * @return bool|\WP_Error True when renamed, false when there was no legacy file.
     */
    private function retire_legacy_file($dir)
    {
        $legacy = $dir . self::LEGACY_FILE;
        if (!is_file($legacy)) {
            return false;
        }
        $backup = $dir . self::LEGACY_BACKUP;
        if (is_file($backup)) {
            @unlink($backup); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- replaced by the rename.
        }
        if (!@rename($legacy, $backup)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
            /* translators: %s: file path. */
            return new \WP_Error('ewp_config_sync_write', sprintf(__('Could not rename %s.', 'extend-wp'), $legacy));
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Admin notices
     * ------------------------------------------------------------------ */

    /**
     * Queue an admin notice.
     *
     * @param string $level   `error`, `warning` or `success`.
     * @param string $message Message.
     * @param bool   $persist Keep it for the next admin page load (for requests that never render a page, like a save over REST).
     *
     * @return void
     */
    public static function notify($level, $message, $persist = false)
    {
        $notice = ['level' => (string) $level, 'message' => (string) $message];
        if (!$persist) {
            self::$notices[] = $notice;
            return;
        }

        $stored   = get_transient(self::TRANSIENT_NOTICES);
        $stored   = is_array($stored) ? $stored : [];
        $stored[md5($message)] = $notice;
        set_transient(self::TRANSIENT_NOTICES, array_slice($stored, -5, null, true), HOUR_IN_SECONDS);
    }

    /**
     * Print (and clear) the queued notices. Hooked on `admin_notices`; administrators only.
     *
     * @return void
     *
     * @since 1.15.0
     */
    public static function render_notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stored  = get_transient(self::TRANSIENT_NOTICES);
        $notices = array_merge(is_array($stored) ? array_values($stored) : [], self::$notices);
        if (is_array($stored)) {
            delete_transient(self::TRANSIENT_NOTICES);
        }
        self::$notices = [];

        foreach ($notices as $notice) {
            echo awm_parse_template(EWP_PLUGIN_DIR . 'templates/admin-view/config-sync-notice.php', $notice); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template.
        }
    }

    /**
     * Notices queued during this request and not yet printed (for tests).
     *
     * @return array
     */
    public static function pending_notices()
    {
        return self::$notices;
    }

    /**
     * Forget the queued notices of this request (for tests).
     *
     * @return void
     */
    public static function reset_notices()
    {
        self::$notices = [];
        delete_transient(self::TRANSIENT_NOTICES);
    }
}
