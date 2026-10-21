<?php
/**
 * Locates the DB-layer entry point in a backtrace.
 *
 * @package extend-wp
 */

namespace EWP\Tools\Db_Baseline;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds which public DB-layer API a query came from and who called it.
 */
class Call_Site
{
    /** Class whose static methods are the raw-table API. */
    const ENTRY_CLASS = 'AWM_DB_Creator';

    /** Procedural content-DB API functions. */
    const ENTRY_FUNCTIONS = array(
        'awm_get_db_content',
        'awm_get_db_content_meta',
        'awm_insert_db_content',
        'awm_insert_db_content_meta',
        'awm_custom_content_delete',
        'awm_custom_content_save',
        'awm_custom_content_duplicate',
    );

    /**
     * Resolve the call site from a debug_backtrace() result.
     *
     * Frames are innermost first. The outermost matching frame is the API the
     * consumer called (its file/line is the consumer's call site); the
     * innermost is the AWM_DB_Creator method that actually ran the query.
     *
     * @param array $frames Output of debug_backtrace(0).
     * @return array|null Null when no DB-layer frame is present, otherwise
     *                    { entry, inner, args, file, line }.
     */
    public static function resolve(array $frames)
    {
        $outer = null;
        $inner = null;
        foreach ($frames as $frame) {
            $name = self::name($frame);
            if ($name === null) {
                continue;
            }
            $inner = $inner === null ? $name : $inner;
            $outer = array('frame' => $frame, 'name' => $name);
        }
        if ($outer === null) {
            return null;
        }
        return array(
            'entry' => $outer['name'],
            'inner' => $inner,
            'args'  => isset($outer['frame']['args']) ? $outer['frame']['args'] : array(),
            'file'  => isset($outer['frame']['file']) ? $outer['frame']['file'] : '',
            'line'  => isset($outer['frame']['line']) ? (int) $outer['frame']['line'] : 0,
        );
    }

    /**
     * API name of a frame, or null when it is not a DB-layer entry point.
     *
     * @param array $frame One backtrace frame.
     * @return string|null "AWM_DB_Creator::method", a function name, or null.
     */
    private static function name(array $frame)
    {
        $function = isset($frame['function']) ? $frame['function'] : '';
        if (isset($frame['class']) && $frame['class'] === self::ENTRY_CLASS) {
            return self::ENTRY_CLASS . '::' . $function;
        }
        if (!isset($frame['class']) && in_array($function, self::ENTRY_FUNCTIONS, true)) {
            return $function;
        }
        return null;
    }

    /**
     * Owner of a file path: plugin/theme slug, bundled extend-wp marked.
     *
     * @param string $file Absolute file path.
     * @return string Owner label, e.g. "filox", "extend-wp (bundled in filox)".
     */
    public static function owner($file)
    {
        if (preg_match('#/(plugins|themes|mu-plugins)/([^/]+)/(?:.*?/3rd-party/(extend-wp)/)?#', $file, $m)) {
            return !empty($m[3]) ? 'extend-wp (bundled in ' . $m[2] . ')' : $m[2];
        }
        return 'core';
    }

    /**
     * Path relative to wp-content, for stable output across environments.
     *
     * @param string $file Absolute file path.
     * @return string Relative path when under wp-content, otherwise unchanged.
     */
    public static function relative($file)
    {
        $pos = strpos($file, '/wp-content/');
        return $pos === false ? $file : substr($file, $pos + strlen('/wp-content/'));
    }
}
