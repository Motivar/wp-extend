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
    /**
     * Export every row of the given content types with their meta.
     *
     * Rows written before hashes existed get one on the way out so the
     * payload can be re-imported as an upsert.
     *
     * @param string[] $content_types Content type ids.
     *
     * @return array|\WP_Error `{type: {id: row + meta}, modified}`; error when nothing was found.
     *
     * @since 1.5.0
     */
    public function export(array $content_types)
    {
        $data = [];

        foreach ($content_types as $content_type) {
            $rows = awm_get_db_content($content_type);
            if (empty($rows) || !is_array($rows)) {
                continue;
            }

            $data[$content_type] = [];
            foreach ($rows as $row) {
                if (empty($row['hash'])) {
                    $row['hash'] = md5(serialize($row));
                    awm_insert_db_content($content_type, $row);
                }
                $row['meta']                                 = awm_get_db_content_meta($content_type, $row['content_id']);
                $data[$content_type][$row['content_id']] = $row;
            }
        }

        if ($data === []) {
            return new \WP_Error('no_data', __('No data to export', 'extend-wp'), ['status' => 400]);
        }

        $data['modified'] = date('Y-m-d H:i:s');

        return $data;
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
