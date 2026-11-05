<?php
/**
 * Read-only inventory of the admin pages the current user can open.
 *
 * The crawler of the browser layer GETs every URL listed here, so the list
 * never contains URLs that perform an action (nonces, `action=`, update and
 * install screens, the customizer).
 *
 * wp-admin builds `$menu` / `$submenu` only on admin requests, and many
 * plugins register their pages only when `is_admin()` is true. So a snapshot
 * is taken on `admin_menu` in real admin requests and the REST route serves
 * it; the Node side loads one admin page first to prime it.
 *
 * @package MammothWP\SelfTest
 * @since   2.1.0
 */

namespace MammothWP\SelfTest\Browser;

use MammothWP\SelfTest\Config;

if (!defined('ABSPATH')) {
    exit;
}

final class Menu_Inventory
{
    /** Per-user transient prefix of the admin-request snapshot. */
    const SNAPSHOT_PREFIX = 'mmwp_self_test_menu_';

    /** Seconds a snapshot is served. */
    const SNAPSHOT_TTL = 3600;

    /** @var string[] Case-insensitive substrings that mark a URL as unsafe to GET. */
    const DEFAULT_EXCLUSIONS = [
        'action=',
        'nonce',
        'customize.php',
        'update.php',
        'update-core.php',
        'plugin-install.php',
        'theme-install.php',
        'post-new.php',
        'logout',
    ];

    /**
     * Hook the snapshot into real admin requests.
     *
     * @return void
     *
     * @since 2.1.0
     */
    public static function init()
    {
        add_action('admin_menu', [self::class, 'snapshot'], PHP_INT_MAX);
    }

    /**
     * Store the current user's menu so the REST route can serve it.
     *
     * @return void
     *
     * @since 2.1.0
     */
    public static function snapshot()
    {
        if (!Config::ui_enabled() || !current_user_can(Config::capability())) {
            return;
        }

        global $menu, $submenu;

        $pages = self::pages((array) $menu, (array) $submenu, admin_url(), self::exclusions());

        set_transient(self::SNAPSHOT_PREFIX . get_current_user_id(), $pages, self::SNAPSHOT_TTL);
    }

    /**
     * The inventory for the current user: the admin-request snapshot, or a
     * live build from the globals when none exists yet.
     *
     * @return array{admin_url: string, pages: array[], excluded: string[], source: string}
     *
     * @since 2.1.0
     */
    public static function current()
    {
        $snapshot = get_transient(self::SNAPSHOT_PREFIX . get_current_user_id());
        $source   = 'snapshot';

        if (!is_array($snapshot)) {
            global $menu, $submenu;

            $snapshot = self::pages((array) $menu, (array) $submenu, admin_url(), self::exclusions());
            $source   = 'live';
        }

        return [
            'admin_url' => admin_url(),
            'pages'     => $snapshot['pages'],
            'excluded'  => $snapshot['excluded'],
            'source'    => $source,
        ];
    }

    /**
     * The URL exclusion needles after the filter.
     *
     * @return string[]
     *
     * @since 2.1.0
     */
    public static function exclusions()
    {
        /**
         * Filter the substrings that keep an admin URL out of the browser
         * crawl (matched case-insensitively). Return the defaults plus your
         * own, or remove defaults you accept to crawl.
         *
         * @param string[] $needles Default: DEFAULT_EXCLUSIONS.
         *
         * @since 2.1.0
         */
        return array_values(array_filter(array_map('strval', (array) apply_filters('mmwp_self_test_admin_menu_exclusions', self::DEFAULT_EXCLUSIONS))));
    }

    /**
     * Turn WordPress' menu arrays into a flat, de-duplicated page list.
     * Pure function: no WordPress state is read.
     *
     * @param array    $menu       `$menu`: [title, capability, slug, ...] per top-level item.
     * @param array    $submenu    `$submenu`: parent slug => [[title, capability, slug], ...].
     * @param string   $admin_url  Absolute admin URL with trailing slash.
     * @param string[] $exclusions Substrings that exclude a URL.
     *
     * @return array{pages: array[], excluded: string[]} Pages as {title, url, path, parent}.
     *
     * @since 2.1.0
     */
    public static function pages(array $menu, array $submenu, $admin_url, array $exclusions)
    {
        $admin_url = trailingslashit((string) $admin_url);
        $pages     = [];
        $excluded  = [];

        foreach ($menu as $item) {
            $parent = isset($item[2]) ? (string) $item[2] : '';
            if ($parent === '' || self::is_separator($item)) {
                continue;
            }

            $entries = [[wp_strip_all_tags((string) $item[0]), $parent]];
            foreach (isset($submenu[$parent]) ? (array) $submenu[$parent] : [] as $sub) {
                $entries[] = [wp_strip_all_tags((string) $sub[0]), isset($sub[2]) ? (string) $sub[2] : ''];
            }

            foreach ($entries as $entry) {
                self::add_entry($pages, $excluded, $entry, $parent, $admin_url, $exclusions);
            }
        }

        return ['pages' => array_values($pages), 'excluded' => array_values(array_unique($excluded))];
    }

    /**
     * Add one menu entry to the page list or the excluded list.
     *
     * @param array    $pages      Pages by path (by reference).
     * @param string[] $excluded   Excluded paths (by reference).
     * @param array    $entry      [title, slug].
     * @param string   $parent     Parent menu slug.
     * @param string   $admin_url  Admin URL with trailing slash.
     * @param string[] $exclusions Exclusion needles.
     *
     * @return void
     *
     * @since 2.1.0
     */
    private static function add_entry(array &$pages, array &$excluded, array $entry, $parent, $admin_url, array $exclusions)
    {
        $path = self::path($entry[1]);
        if ($path === '' || isset($pages[$path])) {
            return;
        }

        if (self::is_excluded($path, $exclusions)) {
            $excluded[] = $path;
            return;
        }

        $pages[$path] = ['title' => trim($entry[0]), 'url' => $admin_url . $path, 'path' => $path, 'parent' => $parent];
    }

    /**
     * Path (relative to wp-admin/) a menu slug opens.
     *
     * @param string $slug Menu slug.
     *
     * @return string Empty for external URLs and unusable slugs.
     *
     * @since 2.1.0
     */
    public static function path($slug)
    {
        $slug = trim((string) $slug);

        if ($slug === '' || preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $slug)) {
            return '';
        }

        if (preg_match('/\.php(?:$|\?)/', $slug)) {
            return ltrim($slug, '/');
        }

        return 'admin.php?page=' . rawurlencode($slug);
    }

    /**
     * Whether a path contains an exclusion needle.
     *
     * @param string   $path       Admin path.
     * @param string[] $exclusions Needles.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    public static function is_excluded($path, array $exclusions)
    {
        foreach ($exclusions as $needle) {
            if ($needle !== '' && stripos($path, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a top-level item is a separator.
     *
     * @param array $item Top-level `$menu` item.
     *
     * @return bool
     *
     * @since 2.1.0
     */
    private static function is_separator(array $item)
    {
        return (isset($item[4]) && strpos((string) $item[4], 'wp-menu-separator') !== false) || (isset($item[2]) && strpos((string) $item[2], 'separator') === 0);
    }
}
