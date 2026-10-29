<?php

namespace EWP\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves and renders field templates.
 *
 * Lookup order for `fields/input`: the active (child) theme's
 * `extend-wp/fields/input.php`, then the plugin's `templates/fields/input.php`;
 * the result passes through the `ewp_field_template_path` filter and is
 * rendered by awm_parse_template(), so templates read their data from
 * `$variables`.
 *
 * @package EWP\Fields
 * @since 1.11.0
 */
final class Template_Loader
{
    /** @var string Plugin templates directory, with trailing slash. */
    private $base_dir;

    /** @var array<string, string> Resolved paths per template name, per request. */
    private $paths = array();

    /**
     * @param string $base_dir Plugin templates directory (default: awm_path . 'templates/').
     */
    public function __construct($base_dir = '')
    {
        $this->base_dir = $base_dir ? trailingslashit($base_dir) : awm_path . 'templates/';
    }

    /**
     * Render a template to a string.
     *
     * @param string $name      Template name relative to templates/, without `.php` (e.g. `fields/input`).
     * @param array  $variables Data exposed to the template as `$variables`.
     * @return string HTML, '' when the template does not exist or the name is invalid.
     */
    public function render($name, array $variables = array())
    {
        $path = $this->locate($name);
        return $path ? awm_parse_template($path, $variables) : '';
    }

    /**
     * Resolve a template name to a file path.
     *
     * @param string $name Template name (lowercase letters, digits, `-`, `_`, `/`).
     * @return string Absolute path, '' when invalid or missing.
     */
    public function locate($name)
    {
        if (isset($this->paths[$name])) {
            return $this->paths[$name];
        }
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $name)) {
            return '';
        }
        $theme = locate_template(array('extend-wp/' . $name . '.php'));
        $path  = $theme ?: $this->base_dir . $name . '.php';

        /**
         * Filter the file a field template renders from.
         *
         * @since 1.11.0
         *
         * @param string $path Resolved path (theme override or plugin default).
         * @param string $name Template name, e.g. `fields/input`.
         */
        $path = (string) apply_filters('ewp_field_template_path', $path, $name);

        $this->paths[$name] = is_readable($path) ? $path : '';
        return $this->paths[$name];
    }
}
