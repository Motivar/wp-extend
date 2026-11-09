<?php

/**
 * Critical CSS file reader
 *
 * Reads the critical CSS file of a dynamic style asset (`critical_path` /
 * `critical_src`) so the Dynamic Asset Loader can inline it in the <head>.
 *
 * A file on this site is always read from disk and never requested over HTTP:
 * a loopback request on every page render makes each page view spawn another
 * one. Only a file on another host is fetched, and that result is cached.
 *
 * @package ExtendWP
 */

namespace EWP\DynamicAssets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Critical_CSS_File
 *
 * Resolves and reads critical CSS files. Docs: includes/classes/CRITICAL-CSS-GUIDE.md.
 */
class Critical_CSS_File
{
    /**
     * Transient prefix for CSS fetched from another host
     *
     * @var string
     */
    const CACHE_PREFIX = 'ewp_critical_css_';

    /**
     * Seconds a fetched remote file stays cached
     *
     * @var int
     */
    const REMOTE_TTL = 86400;

    /**
     * Seconds a failed remote fetch stays cached, so a dead host is not retried on every page view
     *
     * @var int
     */
    const REMOTE_FAILURE_TTL = 300;

    /**
     * Seconds to wait for a remote file
     *
     * @var int
     */
    const REMOTE_TIMEOUT = 3;

    /**
     * Load the critical CSS file of an asset
     *
     * `critical_path` wins over `critical_src`. A `critical_src` on this site is
     * mapped to a file path and read from disk; one on another host is fetched.
     *
     * @param array $asset Sanitized asset configuration (`critical_path` and/or `critical_src`)
     * @return string|false CSS content, false when there is no readable file
     */
    public function load($asset)
    {
        if (!empty($asset['critical_path'])) {
            return $this->read_local($asset['critical_path']);
        }

        if (empty($asset['critical_src'])) {
            return false;
        }

        if ($this->is_local_url($asset['critical_src'])) {
            return $this->read_local_url($asset['critical_src']);
        }

        return $this->fetch_remote($asset['critical_src']);
    }

    /**
     * Check whether a URL points at this site
     *
     * @param string $url URL to check (absolute, protocol-relative or root-relative)
     * @return bool True when the URL has no host or the host of the home, site, content or plugins URL
     */
    public function is_local_url($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (empty($host)) {
            return true;
        }

        foreach (array(home_url(), site_url(), content_url(), plugins_url()) as $local_url) {
            if (strcasecmp($host, (string) wp_parse_url($local_url, PHP_URL_HOST)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a URL on this site to a file path
     *
     * Only the URL path is compared, so the scheme, a query string (`?ver=`)
     * and a fragment do not matter.
     *
     * @param string $url URL of a file in a theme, the plugins directory or the content directory
     * @return string|false Absolute file path, false when the URL is outside those directories
     */
    public function url_to_path($url)
    {
        $url_path = wp_parse_url($url, PHP_URL_PATH);

        if (!is_string($url_path) || $url_path === '') {
            return false;
        }

        $url_path = rawurldecode($url_path);

        foreach ($this->local_roots() as $root) {
            $base_path = untrailingslashit((string) wp_parse_url($root[0], PHP_URL_PATH));

            if ($base_path !== '' && strpos($url_path, $base_path . '/') === 0) {
                return untrailingslashit(wp_normalize_path($root[1])) . substr($url_path, strlen($base_path));
            }
        }

        return false;
    }

    /**
     * Read a local CSS file
     *
     * @param string $path Absolute path to a `.css` file
     * @return string|false CSS content, false when the path is not a readable, non-empty `.css` file
     */
    public function read_local($path)
    {
        $path = wp_normalize_path((string) $path);

        if (!$this->is_css_path($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $css = file_get_contents($path);

        return is_string($css) && trim($css) !== '' ? $css : false;
    }

    /**
     * Fetch a CSS file from another host, cached in a transient
     *
     * A failure is cached too (for REMOTE_FAILURE_TTL), so an unreachable host
     * costs one timeout and not one per page view.
     *
     * @param string $url Absolute URL on another host
     * @return string|false CSS content, false when the file could not be fetched
     */
    public function fetch_remote($url)
    {
        $key = self::CACHE_PREFIX . md5($url);
        $cached = get_transient($key);

        if (is_array($cached) && isset($cached['css'])) {
            return $cached['css'] !== '' ? $cached['css'] : false;
        }

        $css = $this->request_remote($url);

        set_transient(
            $key,
            array('css' => $css === false ? '' : $css),
            $css === false ? self::REMOTE_FAILURE_TTL : self::REMOTE_TTL
        );

        return $css;
    }

    /**
     * Read the file a local URL points at
     *
     * @param string $url URL on this site
     * @return string|false CSS content, false when the URL maps to no readable file (never requested over HTTP)
     */
    private function read_local_url($url)
    {
        $path = $this->url_to_path($url);

        return $path === false ? false : $this->read_local($path);
    }

    /**
     * URL / directory pairs a local URL can map to, most specific first
     *
     * @return array[] List of array(base URL, base directory)
     */
    private function local_roots()
    {
        $roots = array(
            array(get_stylesheet_directory_uri(), get_stylesheet_directory()),
            array(get_template_directory_uri(), get_template_directory()),
            array(plugins_url(), WP_PLUGIN_DIR),
        );

        if (defined('WPMU_PLUGIN_URL') && defined('WPMU_PLUGIN_DIR')) {
            $roots[] = array(WPMU_PLUGIN_URL, WPMU_PLUGIN_DIR);
        }

        $roots[] = array(content_url(), WP_CONTENT_DIR);

        return $roots;
    }

    /**
     * Check that a path is a plain `.css` file path
     *
     * @param string $path Normalized file path
     * @return bool False for another extension, a stream wrapper (`http://`, `phar://`) or a path with `..`
     */
    private function is_css_path($path)
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css') {
            return false;
        }

        if (wp_is_stream($path)) {
            return false;
        }

        return !in_array('..', explode('/', $path), true);
    }

    /**
     * Request a CSS file from another host
     *
     * @param string $url Absolute URL on another host
     * @return string|false Response body, false on an error, a non-200 response, an empty body or a body closing the style tag
     */
    private function request_remote($url)
    {
        $response = wp_safe_remote_get($url, array('timeout' => self::REMOTE_TIMEOUT));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $css = wp_remote_retrieve_body($response);

        if (trim($css) === '' || stripos($css, '</style') !== false) {
            return false;
        }

        return $css;
    }
}
