<?php
/**
 * One-time move of data stored under the pre-1.0 `mwp_` prefix.
 *
 * Idempotent: a second run finds nothing to do. Never overwrites a value
 * that already exists under the new key.
 *
 * @package MammothWP\SelfTest
 * @since   1.0.0
 */

namespace MammothWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Migration
{
    /** Option flagging that the migration has run on this site. */
    const DONE_OPTION = 'mmwp_self_test_migrated';

    /**
     * Old option name => new option name.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function options()
    {
        return [Config::LEGACY_STATE_OPTION => Config::STATE_OPTION];
    }

    /**
     * Run the migration once per site (no-op afterwards).
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function maybe_run()
    {
        if (get_option(self::DONE_OPTION, null) !== null) {
            return;
        }

        self::run(false);
        update_option(self::DONE_OPTION, gmdate('c'), false);
    }

    /**
     * Move every legacy option to its new name.
     *
     * @param bool $dry_run Report what would happen without writing.
     *
     * @return array<int,array{from:string,to:string,action:string}> One row per legacy option found.
     *
     * @since 1.0.0
     */
    public static function run($dry_run = false)
    {
        $rows = [];

        foreach (self::options() as $from => $to) {
            $row = self::migrate_option($from, $to, (bool) $dry_run);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Migrate a single option.
     *
     * @param string $from    Old option name.
     * @param string $to      New option name.
     * @param bool   $dry_run Do not write.
     *
     * @return array{from:string,to:string,action:string}|null Null when the old option does not exist.
     *
     * @since 1.0.0
     */
    private static function migrate_option($from, $to, $dry_run)
    {
        $value = get_option($from, null);
        if ($value === null) {
            return null;
        }

        $keep   = get_option($to, null) !== null;
        $action = $keep ? 'drop-legacy (new key already set)' : 'copy-and-drop-legacy';

        if (!$dry_run) {
            $keep || update_option($to, $value, false);
            delete_option($from);
        }

        return ['from' => $from, 'to' => $to, 'action' => $action];
    }
}
