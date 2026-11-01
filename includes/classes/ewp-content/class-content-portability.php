<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-content-php-export.php';

/**
 * Export and import of custom content rows (definitions and data) across
 * sites, keyed by row hash so re-importing is an upsert.
 *
 * The one implementation behind `GET/POST ewp/v1/export|import`,
 * `wp ewp content export|import`, `ewp-content/export|import` and the
 * import/export admin screen's automatic file sync.
 *
 * @package    EWP\Content
 * @author     Motivar
 *
 * @since 1.5.0
 */
final class Content_Portability
{
    /** Rows read per query while exporting (since 1.11.0). */
    const BATCH = 500;

    /** Largest `per_page` of a paged export (since 1.11.0). */
    const MAX_PER_PAGE = 1000;

    /** Logger owner of the export/import entries (since 1.15.0). */
    const LOG_OWNER = 'extend-wp';

    /** Logger action type of an export (since 1.15.0). */
    const LOG_EXPORT = 'content_export';

    /** Logger action type of an import (since 1.15.0). */
    const LOG_IMPORT = 'content_import';

    /**
     * Register the export/import action types with the EWP Logger.
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
        ewp_register_log_type(self::LOG_OWNER, self::LOG_EXPORT, 'Content Export', 'Custom content rows were exported (Import/Export screen, REST, WP-CLI or ability).');
        ewp_register_log_type(self::LOG_OWNER, self::LOG_IMPORT, 'Content Import', 'Custom content rows were imported (Import/Export screen, REST, WP-CLI or ability).');
    }

    /**
     * Export the rows of the given content types with their meta.
     *
     * Rows are read in batches of BATCH ordered by id (since 1.11.0), with the
     * read cache off for the exported tables and the primed meta forgotten after
     * every batch, so memory stays flat while reading. Three modes:
     *
     * - no options: every row, returned as one payload (as before 1.11.0);
     * - `page` / `per_page`: one page of every type, plus `pagination`
     *   (type => { page, per_page, total, total_pages }) so a caller can loop;
     * - `file`: every row streamed to that file as JSON, batch by batch (only
     *   one batch is in memory); returns a summary instead of the payload.
     *
     * Rows written before hashes existed get one on the way out so the
     * payload can be re-imported as an upsert.
     *
     * @param string[] $content_types Content type ids.
     * @param array    $options       {
     *     Optional.
     *
     *     @type int    $page     Page to export, from 1. With per_page.
     *     @type int    $per_page Rows per page and type, 1–MAX_PER_PAGE (default BATCH when page is given).
     *     @type string $file     Write the whole export to this path instead of returning it (the CLI's --file).
     *     @type string $format   `php` returns the definitions as PHP code (see export_php()) instead of the rows; page, per_page and file are ignored (since 1.15.0).
     *     @type string $layout   `per_object` returns one file per row in the config sync layout (see export_files()); page, per_page and file are ignored (since 1.15.0).
     *     @type bool   $log      False to skip the `content_export` log entry (the config sync logs its own); default true (since 1.15.0).
     * }
     *
     * @return array|\WP_Error `{type: {id: row + meta}, modified[, pagination]}`, or with `file`
     *                         `{file, count, types: {type: rows}, modified}`, or with `format` php
     *                         `{code, skipped}`; error when nothing was found or the file cannot be written.
     *
     * @since 1.5.0
     * @since 1.11.0 Batched reads; `page`, `per_page` and `file` options.
     * @since 1.15.0 `format` option.
     */
    public function export(array $content_types, array $options = [])
    {
        $result = $this->run_export($content_types, $options);
        if (!isset($options['log']) || $options['log'] !== false) {
            $this->log_export($content_types, $options, $result);
        }

        return $result;
    }

    /**
     * The export itself (see export()), without logging.
     *
     * @param string[] $content_types Content type ids.
     * @param array    $options       As export().
     *
     * @return array|\WP_Error As export().
     */
    private function run_export(array $content_types, array $options)
    {
        if (isset($options['format']) && $options['format'] === 'php') {
            return $this->export_php($content_types);
        }
        if (isset($options['layout']) && $options['layout'] === 'per_object') {
            return $this->export_files($content_types);
        }
        $content_types = array_values(array_filter(array_map('strval', $content_types)));
        if (!empty($options['file'])) {
            return $this->export_file($content_types, (string) $options['file']);
        }

        $paged    = !empty($options['page']) || !empty($options['per_page']);
        $per_page = $paged ? self::per_page(isset($options['per_page']) ? $options['per_page'] : 0) : 0;
        $page     = $paged ? max(1, isset($options['page']) ? (int) $options['page'] : 1) : 1;

        $data       = [];
        $pagination = [];
        foreach ($content_types as $content_type) {
            $rows = [];
            $this->each_row($content_type, $paged ? ($page - 1) * $per_page : 0, $per_page, function (array $row) use (&$rows) {
                $rows[$row['content_id']] = $row;
            });
            if ($paged) {
                $total                     = (int) \AWM_DB_Creator::count_db_data($content_type . '_main');
                $pagination[$content_type] = ['page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil($total / $per_page)];
            }
            if ($rows !== []) {
                $data[$content_type] = $rows;
            }
        }

        if ($data === [] && !$paged) {
            return new \WP_Error('no_data', __('No data to export', 'extend-wp'), ['status' => 400]);
        }

        $data['modified'] = date('Y-m-d H:i:s');
        if ($paged) {
            $data['pagination'] = $pagination;
        }

        return $data;
    }

    /**
     * Export field groups, post types and taxonomies as PHP code that registers them.
     *
     * @param string[] $content_types Content type ids; types without a generator are reported as skipped.
     *
     * @return array|\WP_Error `{code: string, skipped: string[]}`; an error when no type was requested.
     *
     * @since 1.15.0
     */
    public function export_php(array $content_types)
    {
        $content_types = array_values(array_filter(array_map('strval', $content_types)));
        if ($content_types === []) {
            return new \WP_Error('no_data', __('No data to export', 'extend-wp'), ['status' => 400]);
        }
        $export = new Content_Php_Export();

        return $export->export($content_types);
    }

    /**
     * Export the rows as the per object files of the config sync (`ewp-config/{type}/{hash}.json`),
     * byte-identical to what the auto export writes, so the Import/Export screen can zip them.
     *
     * @param string[] $content_types Content type ids.
     *
     * @return array|\WP_Error `{files: path => contents, types: type => rows}`; an error when nothing was found.
     *
     * @since 1.15.0
     */
    public function export_files(array $content_types)
    {
        require_once __DIR__ . '/class-config-sync.php';

        return (new Config_Sync($this))->build_files($content_types);
    }

    /**
     * Stream an export of every row of the given types to a JSON file, one batch in memory at a time.
     * The file has the same shape as export()'s payload.
     *
     * @param string[] $content_types Content type ids.
     * @param string   $file          Path to write (overwritten).
     *
     * @return array|\WP_Error { file, count, types: {type: rows written}, modified }.
     *
     * @since 1.11.0
     */
    public function export_file(array $content_types, $file)
    {
        $handle = @fopen($file, 'wb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
        if ($handle === false) {
            /* translators: %s: file path. */
            return new \WP_Error('ewp_content_export_file', sprintf(__('Failed to write to %s.', 'extend-wp'), $file), ['status' => 500]);
        }

        $flags  = JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $counts = [];
        fwrite($handle, '{');
        foreach ($content_types as $content_type) {
            $count = 0;
            $this->each_row($content_type, 0, 0, function (array $row) use ($handle, $flags, &$count, $content_type) {
                $prefix = $count === 0 ? "\n" . wp_json_encode((string) $content_type) . ': {' : ',';
                fwrite($handle, $prefix . "\n" . wp_json_encode((string) $row['content_id']) . ': ' . wp_json_encode($row, $flags));
                $count++;
            });
            if ($count > 0) {
                fwrite($handle, "\n},");
                $counts[$content_type] = $count;
            }
        }
        $modified = date('Y-m-d H:i:s');
        fwrite($handle, "\n\"modified\": " . wp_json_encode($modified) . "\n}\n");
        fclose($handle);

        if ($counts === []) {
            @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
            return new \WP_Error('no_data', __('No data to export', 'extend-wp'), ['status' => 400]);
        }

        return ['file' => (string) $file, 'count' => array_sum($counts), 'types' => $counts, 'modified' => $modified];
    }

    /**
     * Call $callback for each row of a type (with `meta`, and a backfilled `hash`), reading BATCH rows per query
     * by ascending id. The read cache is off for the type's tables meanwhile and primed meta is dropped after
     * every batch, so nothing accumulates in memory.
     *
     * @param string   $content_type Content type id.
     * @param int      $offset       Rows to skip.
     * @param int      $limit        Rows to visit; 0 for all.
     * @param callable $callback     fn(array $row): void.
     *
     * @return int Rows visited.
     *
     * @since 1.11.0
     */
    private function each_row($content_type, $offset, $limit, callable $callback)
    {
        $no_cache = function ($enabled, $table) use ($content_type) {
            return in_array($table, [$content_type . '_main', $content_type . '_data'], true) ? false : $enabled;
        };
        add_filter('ewp_db_cache_table', $no_cache, PHP_INT_MAX, 2);

        $visited = 0;
        do {
            $batch = $limit > 0 ? min(self::BATCH, $limit - $visited) : self::BATCH;
            $rows  = awm_get_db_content($content_type, [
                'limit'    => $batch,
                'offset'   => $offset + $visited,
                'order_by' => ['column' => 'content_id', 'type' => 'asc'],
            ]);
            $rows = is_array($rows) ? $rows : [];
            if ($rows === []) {
                break;
            }
            awm_get_db_content_metas($content_type, array_column($rows, 'content_id'));
            foreach ($rows as $row) {
                $callback($this->with_hash_and_meta($content_type, $row));
            }
            $visited += count($rows);
            \EWP\Db\Content_Meta::forget();
        } while (count($rows) === $batch && ($limit === 0 || $visited < $limit));

        remove_filter('ewp_db_cache_table', $no_cache, PHP_INT_MAX);

        return $visited;
    }

    /**
     * Attach the row's meta, backfilling a missing hash (rows written before hashes existed).
     *
     * @param string $content_type Content type id.
     * @param array  $row          A `{type}_main` row.
     *
     * @return array The row with `hash` and `meta`.
     *
     * @since 1.15.0
     */
    private function with_hash_and_meta($content_type, array $row)
    {
        if (empty($row['hash'])) {
            $row['hash'] = md5(serialize($row));
            awm_insert_db_content($content_type, $row);
        }
        $row['meta'] = awm_get_db_content_meta($content_type, $row['content_id']);

        return $row;
    }

    /**
     * One row of a content type with its meta (and a backfilled hash), as export() would list it.
     *
     * @param string $content_type Content type id.
     * @param int    $content_id   Row id.
     *
     * @return array|null The row, or null when it does not exist.
     *
     * @since 1.15.0
     */
    public function find_row($content_type, $content_id)
    {
        $rows = awm_get_db_content((string) $content_type, ['include' => [(int) $content_id], 'limit' => 1]);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        return $this->with_hash_and_meta((string) $content_type, reset($rows));
    }

    /**
     * Call $callback for every row of a content type (batched, flat memory), as export() reads them.
     *
     * @param string   $content_type Content type id.
     * @param callable $callback     fn(array $row): void, the row carrying `meta` and `hash`.
     *
     * @return int Rows visited.
     *
     * @since 1.15.0
     */
    public function walk_rows($content_type, callable $callback)
    {
        return $this->each_row((string) $content_type, 0, 0, $callback);
    }

    /**
     * Clamp a requested page size.
     *
     * @param mixed $per_page Requested rows per page; empty for BATCH.
     *
     * @return int 1–MAX_PER_PAGE.
     *
     * @since 1.11.0
     */
    private static function per_page($per_page)
    {
        $per_page = (int) $per_page;

        return $per_page > 0 ? min($per_page, self::MAX_PER_PAGE) : self::BATCH;
    }

    /**
     * Import rows into one content type, upserting by hash, then flush caches.
     *
     * @param string $content_type Content type id.
     * @param array  $rows         Rows as exported (each with optional `meta`).
     * @param array  $options      {
     *     Optional.
     *
     *     @type bool $log False to skip the `content_import` log entry (the config sync logs its own); default true.
     * }
     *
     * @return array|\WP_Error content_type, count, hashes
     *
     * @since 1.5.0
     * @since 1.15.0 `$options` (`log`).
     */
    public function import($content_type, array $rows, array $options = [])
    {
        $result = $this->run_import((string) $content_type, $rows);
        if (!isset($options['log']) || $options['log'] !== false) {
            $this->log_import((string) $content_type, count($rows), $result);
        }

        return $result;
    }

    /**
     * The import itself (see import()), without logging.
     *
     * @param string $content_type Content type id.
     * @param array  $rows         Rows as exported.
     *
     * @return array|\WP_Error As import().
     */
    private function run_import($content_type, array $rows)
    {
        if ($content_type === '' || $rows === []) {
            return new \WP_Error('no_content', __('No content provided', 'extend-wp'), ['status' => 400]);
        }

        $hashes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $meta = isset($row['meta']) ? $row['meta'] : [];
            unset($row['meta']);
            $row['user_id'] = get_current_user_id();

            $id = awm_insert_db_content($content_type, $row, ['hash']);
            if (!$id) {
                return new \WP_Error('not_imported_id', 'Id:' . (isset($row['content_id']) ? $row['content_id'] : '?'), ['status' => 400]);
            }

            if (!empty($meta) && is_array($meta)) {
                awm_insert_db_content_meta($content_type, $id, $meta);
            }

            $hashes[] = isset($row['hash']) ? (string) $row['hash'] : '';
        }

        if (function_exists('ewp_flush_cache')) {
            ewp_flush_cache();
        }

        return ['content_type' => $content_type, 'count' => count($hashes), 'hashes' => $hashes];
    }

    /**
     * Log an export: what was asked, in which form, and how many rows or files came out.
     *
     * @param string[]        $content_types Requested content type ids.
     * @param array           $options       export() options.
     * @param array|\WP_Error $result        export() result.
     *
     * @return void
     */
    private function log_export(array $content_types, array $options, $result)
    {
        $form = isset($options['format']) && $options['format'] === 'php' ? 'php' : (isset($options['layout']) && $options['layout'] === 'per_object' ? 'per_object' : (!empty($options['file']) ? 'file' : 'json'));
        $data = ['types' => array_values(array_map('strval', $content_types)), 'form' => $form];
        foreach (['page', 'per_page', 'file'] as $key) {
            if (!empty($options[$key])) {
                $data[$key] = $options[$key];
            }
        }
        if (is_wp_error($result)) {
            $data['error'] = $result->get_error_message();
            self::log(self::LOG_EXPORT, sprintf('Content export failed (%s): %s', implode(', ', $data['types']), $result->get_error_message()), $data, 0);
            return;
        }
        $data['counts'] = self::export_counts($result);
        if (isset($result['skipped'])) {
            $data['skipped'] = (array) $result['skipped'];
        }
        if (isset($result['files'])) {
            $data['files'] = count((array) $result['files']);
        }
        self::log(self::LOG_EXPORT, sprintf('Exported %d row(s) of %s as %s', array_sum($data['counts']), implode(', ', $data['types']), $form), $data, 1);
    }

    /**
     * Rows per content type in an export result.
     *
     * @param array $result export() result (payload, file summary, files or php).
     *
     * @return array<string, int> Type => rows (empty for php).
     */
    private static function export_counts(array $result)
    {
        if (isset($result['types']) && is_array($result['types'])) {
            return array_map('intval', $result['types']);
        }
        $counts = [];
        foreach ($result as $key => $rows) {
            if (!in_array($key, ['modified', 'pagination', 'code', 'skipped'], true) && is_array($rows)) {
                $counts[(string) $key] = count($rows);
            }
        }

        return $counts;
    }

    /**
     * Log an import of one content type.
     *
     * @param string          $content_type Content type id.
     * @param int             $rows         Rows received.
     * @param array|\WP_Error $result       import() result.
     *
     * @return void
     */
    private function log_import($content_type, $rows, $result)
    {
        $data = ['content_type' => $content_type, 'rows' => (int) $rows];
        if (is_wp_error($result)) {
            $data['error'] = $result->get_error_message();
            self::log(self::LOG_IMPORT, sprintf('Content import into %s failed: %s', $content_type, $result->get_error_message()), $data, 0);
            return;
        }
        $data['imported'] = (int) $result['count'];
        self::log(self::LOG_IMPORT, sprintf('Imported %d row(s) into %s', (int) $result['count'], $content_type), $data, 1);
    }

    /**
     * Write an entry to the EWP Logger when it is available.
     *
     * @param string $type      Action type (LOG_EXPORT or LOG_IMPORT).
     * @param string $message   Summary.
     * @param array  $data      Details.
     * @param int    $behaviour 1 success, 0 error.
     *
     * @return void
     */
    private static function log($type, $message, array $data, $behaviour)
    {
        if (function_exists('ewp_log')) {
            ewp_log(self::LOG_OWNER, $type, $message, $data, 'developer', 'custom_content', $behaviour);
        }
    }
}
