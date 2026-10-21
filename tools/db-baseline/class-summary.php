<?php
/**
 * Groups recorded DB-layer queries by fingerprint.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads records-*.jsonl and produces a per-call-site baseline: how often each
 * (entry, call site, argument shape) ran, from which requests, the distinct
 * SQL it produced, row counts and result hashes.
 */
class Summary
{
    /** Distinct SQL / arg samples kept per fingerprint. */
    const MAX_SAMPLES = 5;

    /**
     * Summarise every records file in a directory.
     *
     * @param string $dir Directory holding records-*.jsonl.
     * @return array Groups keyed by fingerprint, sorted by owner then call site.
     */
    public static function from_dir($dir)
    {
        $groups = array();
        foreach ((array) glob(rtrim($dir, '/') . '/records-*.jsonl') as $file) {
            $groups = self::add_file($groups, $file);
        }
        uasort($groups, array(__CLASS__, 'compare'));
        return $groups;
    }

    /**
     * Fold one JSON-lines file into the groups.
     *
     * @param array  $groups Groups so far.
     * @param string $file   Records file.
     * @return array Updated groups.
     */
    private static function add_file(array $groups, $file)
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            return $groups;
        }
        while (($line = fgets($handle)) !== false) {
            $record = json_decode($line, true);
            if (is_array($record) && !empty($record['fingerprint'])) {
                $groups = self::add($groups, $record);
            }
        }
        fclose($handle);
        return $groups;
    }

    /**
     * Fold one record into its group.
     *
     * @param array $groups Groups so far.
     * @param array $record One recorded query.
     * @return array Updated groups.
     */
    public static function add(array $groups, array $record)
    {
        $key   = $record['fingerprint'];
        $group = isset($groups[$key]) ? $groups[$key] : self::empty_group($record);
        $group['count']++;
        $group['contexts'] = self::push_unique($group['contexts'], $record['context']['kind'] . ' ' . $record['context']['target']);
        $group['sql']      = self::push_unique($group['sql'], $record['sql']);
        $group['args']     = self::push_unique($group['args'], $record['args']);
        $group['rows']     = self::push_unique($group['rows'], $record['rows'], PHP_INT_MAX);
        $group['hashes']   = self::push_unique($group['hashes'], $record['result_hash'], PHP_INT_MAX);
        if ($record['error'] !== '') {
            $group['errors'] = self::push_unique($group['errors'], $record['error']);
        }
        $groups[$key] = $group;
        return $groups;
    }

    /**
     * New group seeded from its first record.
     *
     * @param array $record First record of the group.
     * @return array Group with zero counts.
     */
    private static function empty_group(array $record)
    {
        return array(
            'fingerprint' => $record['fingerprint'],
            'owner'       => $record['owner'],
            'call'        => $record['call'],
            'entry'       => $record['entry'],
            'inner'       => $record['inner'],
            'shape'       => $record['shape'],
            'count'       => 0,
            'contexts'    => array(),
            'sql'         => array(),
            'args'        => array(),
            'rows'        => array(),
            'hashes'      => array(),
            'errors'      => array(),
        );
    }

    /**
     * Append a value when not already present and under the limit.
     *
     * @param array $list  Current values.
     * @param mixed $value Candidate value.
     * @param int   $limit Maximum list size.
     * @return array Updated list.
     */
    private static function push_unique(array $list, $value, $limit = self::MAX_SAMPLES)
    {
        if (count($list) >= $limit || in_array($value, $list, true)) {
            return $list;
        }
        $list[] = $value;
        return $list;
    }

    /**
     * Sort groups by owner, then call site, then entry.
     *
     * @param array $a Group.
     * @param array $b Group.
     * @return int Comparison result.
     */
    private static function compare(array $a, array $b)
    {
        return strcmp($a['owner'] . $a['call'] . $a['entry'], $b['owner'] . $b['call'] . $b['entry']);
    }

    /**
     * Golden fixtures for the rewrite's compat tests: one entry per group,
     * request URLs stripped of nonces, sorted for stable diffs.
     *
     * @param array $groups Output of from_dir().
     * @return array List of fixtures { id, owner, call, entry, inner, shape, args, sql, rows, errors, requests }.
     */
    public static function fixtures(array $groups)
    {
        $fixtures = array();
        foreach ($groups as $group) {
            $fixtures[] = array(
                'id'       => substr($group['fingerprint'], 0, 12),
                'owner'    => $group['owner'],
                'call'     => $group['call'],
                'entry'    => $group['entry'],
                'inner'    => $group['inner'],
                'shape'    => $group['shape'],
                'args'     => $group['args'],
                'sql'      => $group['sql'],
                'rows'     => $group['rows'],
                'errors'   => count($group['errors']),
                'requests' => array_values(array_unique(array_map(array(__CLASS__, 'scrub_request'), $group['contexts']))),
            );
        }
        return $fixtures;
    }

    /**
     * Remove nonce values from a recorded request description.
     *
     * @param string $context "kind target" string.
     * @return string Same string with nonce parameters blanked.
     */
    public static function scrub_request($context)
    {
        return (string) preg_replace('/((?:_wp)?nonce=)[^&\s]+/i', '$1x', (string) $context);
    }

    /**
     * Markdown overview table of the groups.
     *
     * @param array $groups Output of from_dir().
     * @return string Markdown.
     */
    public static function markdown(array $groups)
    {
        $lines = array(
            '# DB layer baseline',
            '',
            sprintf('%d distinct call shapes, %d queries.', count($groups), array_sum(array_column($groups, 'count'))),
            '',
            '| Owner | Call site | Entry → inner | Runs | Distinct SQL | Rows seen | Errors | Requests |',
            '|---|---|---|---|---|---|---|---|',
        );
        foreach ($groups as $group) {
            $lines[] = sprintf(
                '| %s | `%s` | `%s` → `%s` | %d | %d | %s | %d | %s |',
                $group['owner'],
                $group['call'],
                $group['entry'],
                $group['inner'],
                $group['count'],
                count($group['sql']),
                implode(', ', $group['rows']),
                count($group['errors']),
                str_replace('|', '\\|', implode('<br>', array_slice($group['contexts'], 0, 3)))
            );
        }
        return implode("\n", $lines) . "\n";
    }
}
