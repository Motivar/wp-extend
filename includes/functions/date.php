<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Kept out of library.php on purpose: on sites where a standalone Extend WP
 * is installed via Composer, the root autoloader loads THAT copy's
 * includes/functions/init.php and Composer then skips the bundled copy's
 * (same file identifier). extend-wp.php requires this file by path, so
 * awm_date() exists even when the other copy predates it.
 */
if (!function_exists('awm_date')) {
    /**
     * Format a timestamp like PHP's date(): same format characters, same
     * timezone (PHP's default, which WordPress sets to UTC).
     *
     * Callers pair it with strtotime(), which reads the same default
     * timezone, so the pairs stay in step on every site; gmdate() would not
     * when another plugin changes the default timezone.
     *
     * @param string          $format    date() format string.
     * @param int|string|null $timestamp Unix timestamp; null = now.
     * @return string The formatted date.
     */
    function awm_date($format, $timestamp = null)
    {
        static $zones = array();
        $zone_name = date_default_timezone_get();
        if (!isset($zones[$zone_name])) {
            $zones[$zone_name] = new DateTimeZone($zone_name);
        }
        $date = new DateTime('@' . ($timestamp === null ? time() : (int) $timestamp));
        return $date->setTimezone($zones[$zone_name])->format($format);
    }
}
