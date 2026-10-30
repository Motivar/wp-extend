<?php

namespace EWP\Content;

if (!defined('ABSPATH')) {
    exit;
}

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
     * }
     *
     * @return array|\WP_Error `{type: {id: row + meta}, modified[, pagination]}`, or with `file`
     *                         `{file, count, types: {type: rows}, modified}`; error when nothing was found
     *                         or the file cannot be written.
     *
     * @since 1.5.0
     * @since 1.11.0 Batched reads; `page`, `per_page` and `file` options.
     */
    public function export(array $content_types, array $options = [])
    {
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

        $data['modified'] = awm_date('Y-m-d H:i:s');
        if ($paged) {
            $data['pagination'] = $pagination;
        }

        return $data;
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
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streams one batch at a time; WP_Filesystem can only write a whole string.
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
        $modified = awm_date('Y-m-d H:i:s');
        fwrite($handle, "\n\"modified\": " . wp_json_encode($modified) . "\n}\n");
        fclose($handle);
        // phpcs:enable

        if ($counts === []) {
            wp_delete_file($file);
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
                if (empty($row['hash'])) {
                    $row['hash'] = md5(serialize($row));
                    awm_insert_db_content($content_type, $row);
                }
                $row['meta'] = awm_get_db_content_meta($content_type, $row['content_id']);
                $callback($row);
            }
            $visited += count($rows);
            \EWP\Db\Content_Meta::forget();
        } while (count($rows) === $batch && ($limit === 0 || $visited < $limit));

        remove_filter('ewp_db_cache_table', $no_cache, PHP_INT_MAX);

        return $visited;
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
     *
     * @return array|\WP_Error content_type, count, hashes
     *
     * @since 1.5.0
     */
    public function import($content_type, array $rows)
    {
        $content_type = (string) $content_type;
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
}
