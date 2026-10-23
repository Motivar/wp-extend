<?php
/**
 * Batched meta loading for content types.
 *
 * @package extend-wp
 */

namespace EWP\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads the `{type}_data` meta of many content items in one query and keeps
 * it for the rest of the request, so lists render without one query per row
 * (the N+1 the baseline found in every content list).
 *
 * awm_get_db_content_meta() reads from here first. Entries are tied to the
 * `{type}_data` table's Read_Cache stamp, so any write through
 * AWM_DB_Creator (or Read_Cache::invalidate()) makes them stale.
 */
final class Content_Meta
{
    /** Ids per query, to keep the IN list reasonable. */
    const CHUNK = 500;

    /** @var array Content type => { stamp: string, items: array<int, array> }. */
    private static $store = array();

    /**
     * Load the meta of several items with one query per CHUNK ids.
     *
     * @param string $type Content type id (tables `{type}_main` / `{type}_data`).
     * @param array  $ids  Content ids.
     * @return array<int, array> Content id => meta key => value (empty array for items without meta).
     */
    public static function prime($type, array $ids)
    {
        $type = (string) $type;
        $ids  = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if ($type === '' || !Table_Columns::is_identifier($type) || empty($ids)) {
            return array();
        }

        $items   = self::fresh_items($type);
        $missing = array_values(array_diff($ids, array_keys($items)));
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            $items = self::load($type, $chunk) + $items;
        }
        self::$store[$type]['items'] = $items;

        return array_intersect_key($items, array_flip($ids));
    }

    /**
     * Meta of one item when it was primed in this request and is still current.
     *
     * @param string     $type Content type id.
     * @param int|string $id   Content id.
     * @return array|null Meta key => value, null when not primed.
     */
    public static function primed($type, $id)
    {
        $items = self::fresh_items((string) $type);
        $id    = absint($id);
        return isset($items[$id]) ? $items[$id] : null;
    }

    /**
     * Forget everything primed (tests, long-running CLI loops).
     *
     * @return void
     */
    public static function forget()
    {
        self::$store = array();
    }

    /**
     * Primed items of a type, dropped when `{type}_data` changed since.
     *
     * @param string $type Content type id.
     * @return array<int, array> Content id => meta.
     */
    private static function fresh_items($type)
    {
        $stamp = Read_Cache::stamp($type . '_data');
        if (!isset(self::$store[$type]) || self::$store[$type]['stamp'] !== $stamp) {
            self::$store[$type] = array('stamp' => $stamp, 'items' => array());
        }
        return self::$store[$type]['items'];
    }

    /**
     * One query for a chunk of ids.
     *
     * @param string $type Content type id.
     * @param int[]  $ids  Content ids.
     * @return array<int, array> Content id => meta (every requested id present).
     */
    private static function load($type, array $ids)
    {
        $items = array_fill_keys($ids, array());
        $rows  = \AWM_DB_Creator::get_db_data(
            $type . '_data',
            array('content_id', 'meta_key', 'meta_value'),
            array('column' => 'content_id', 'compare' => 'IN', 'value' => $ids)
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            $items[(int) $row['content_id']][$row['meta_key']] = \EWP\Serialization::unserialize_twice($row['meta_value']);
        }
        return $items;
    }
}
