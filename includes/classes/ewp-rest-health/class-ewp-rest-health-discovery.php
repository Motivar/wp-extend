<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EWP REST Health — Endpoint Discovery
 *
 * Scans the WordPress REST server for registered routes and maps them
 * to active plugins using a token-intersection heuristic.
 *
 * Override the namespace→plugin map via the filter:
 *   add_filter('ewp_rest_health_namespace_plugin_map', function($map) {
 *       $map['ewp/v1'] = 'wp-extend/extend-wp.php';
 *       return $map;
 *   });
 *
 * @package EWP\RestHealth
 * @since   1.0.0
 */
class EWP_REST_Health_Discovery
{
    /**
     * WordPress core and well-known third-party namespace prefixes that should
     * never be attributed to an installed plugin via the heuristic matcher.
     * Mapped to the special '__wp_core__' pseudo-plugin key.
     */
    private const CORE_NS_PREFIXES = [
        'wp/',
        'wp-abilities/',   // WP core Abilities API (wp-includes)
        'oembed/',
        'wp-site-health/',
        'wp-block-editor/',
        'wp-block-renderer/',
        'wp-block-directory/',
        'wp-block-types/',
        'wc/',
        'wc-telemetry/',
    ];

    /** Pseudo-plugin key for WordPress core + WooCommerce endpoints. */
    const CORE_PLUGIN_KEY = '__wp_core__';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return all active plugins with basic metadata.
     * Prepends a "WordPress Core" pseudo-entry so it can be selected in the UI.
     *
     * @return array<string, array{name: string, dir: string}>  Keyed by plugin path.
     */
    public function get_active_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option('active_plugins', []);
        $result         = [];

        foreach ($active_plugins as $plugin_path) {
            if (!isset($all_plugins[$plugin_path])) {
                continue;
            }
            $result[$plugin_path] = [
                'name' => $all_plugins[$plugin_path]['Name'] ?? $plugin_path,
                'dir'  => dirname($plugin_path),
            ];
        }

        uasort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Prepend WordPress Core as the first selectable pseudo-plugin
        return array_merge(
            [self::CORE_PLUGIN_KEY => ['name' => 'WordPress Core', 'dir' => self::CORE_PLUGIN_KEY]],
            $result
        );
    }

    /**
     * Return all unique namespaces from the registered REST server.
     *
     * @return string[]
     */
    public function get_all_namespaces(): array
    {
        $namespaces = [];
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            if ($route === '/') {
                continue;
            }
            $ns = $this->extract_namespace($route);
            if ($ns) {
                $namespaces[$ns] = true;
            }
        }
        return array_keys($namespaces);
    }

    /**
     * Build a map of namespace → plugin_path using token-intersection scoring.
     *
     * @return array<string, string>  namespace => plugin path
     */
    public function build_namespace_plugin_map(): array
    {
        $namespaces = $this->get_all_namespaces();
        // Exclude the pseudo-plugin key so core namespaces don't compete with real plugins
        $plugins = array_filter(
            $this->get_active_plugins(),
            fn($path) => $path !== self::CORE_PLUGIN_KEY,
            ARRAY_FILTER_USE_KEY
        );
        $map = [];

        foreach ($namespaces as $namespace) {
            // Core namespaces (wp/v2, oembed/…, etc.) always map to the pseudo key
            if ($this->is_core_namespace($namespace)) {
                $map[$namespace] = self::CORE_PLUGIN_KEY;
                continue;
            }

            $ns_slug     = $this->strip_version($namespace);
            $best_plugin = null;
            $best_score  = 0;

            foreach ($plugins as $path => $meta) {
                $score = $this->match_score($ns_slug, $meta['dir'], $meta['name']);
                if ($score > $best_score) {
                    $best_score  = $score;
                    $best_plugin = $path;
                }
            }

            if ($best_plugin && $best_score > 0) {
                $map[$namespace] = $best_plugin;
            }
        }

        return apply_filters('ewp_rest_health_namespace_plugin_map', $map);
    }

    /**
     * Return endpoint metadata for a given list of plugin paths.
     *
     * @param  string[] $plugin_paths
     * @return array[]  Keyed by route.
     */
    public function get_routes_for_plugins(array $plugin_paths): array
    {
        $ns_map  = $this->build_namespace_plugin_map();
        $all     = rest_get_server()->get_routes();
        $target  = array_keys(array_filter($ns_map, fn($p) => in_array($p, $plugin_paths, true)));
        $result  = [];

        foreach ($all as $route => $handlers) {
            if ($route === '/') {
                continue;
            }
            $ns = $this->extract_namespace($route);
            if (!$ns || !in_array($ns, $target, true)) {
                continue;
            }
            $result[$route] = $this->get_route_metadata($route, $handlers);
        }

        return $result;
    }

    /**
     * Extract structured metadata for a single route.
     *
     * @param  string $route
     * @param  array  $handlers  Raw handler array from WP REST server.
     * @return array
     */
    public function get_route_metadata(string $route, array $handlers): array
    {
        $methods    = [];
        $args       = [];
        $perm_cb    = ['name' => '', 'valid' => false];
        $controller = null;

        foreach ($handlers as $handler) {
            if (!is_array($handler) || empty($handler['methods'])) {
                continue;
            }
            foreach (array_keys($handler['methods']) as $method) {
                if (!in_array($method, $methods, true)) {
                    $methods[] = $method;
                }
            }
            if (empty($perm_cb['name']) && isset($handler['permission_callback'])) {
                $perm_cb = $this->resolve_permission_callback($handler['permission_callback']);
            }
            if (!empty($handler['args'])) {
                $args = array_merge($args, (array) $handler['args']);
            }
            if (!$controller && isset($handler['callback'])) {
                $controller = $this->detect_controller_class($handler['callback']);
            }
        }

        return [
            'route'               => $route,
            'namespace'           => $this->extract_namespace($route),
            'methods'             => $methods,
            'permission_callback' => $perm_cb,
            'controller_class'    => $controller,
            'args'                => $args,
            'url_params'          => $this->extract_url_params($route),
        ];
    }

    /**
     * Extract named capture groups from a WP REST route pattern.
     *
     * /filox/v1/booking/(?P<id>\d+) → ['id']
     *
     * @param  string   $route
     * @return string[]
     */
    public function extract_url_params(string $route): array
    {
        preg_match_all('/\(\?P<([^>]+)>/', $route, $matches);
        return $matches[1] ?? [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if a namespace belongs to WordPress core or a well-known
     * platform (WooCommerce, etc.) rather than to an installed plugin.
     */
    private function is_core_namespace(string $namespace): bool
    {
        foreach (self::CORE_NS_PREFIXES as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the REST namespace from a route path.
     *
     * /filox/v1/rates → filox/v1
     * /filox-elorus/create → filox-elorus
     */
    private function extract_namespace(string $route): string
    {
        $parts = explode('/', ltrim($route, '/'), 3);
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }
        if (preg_match('/^v\d+$/', $parts[1])) {
            return $parts[0] . '/' . $parts[1];
        }
        return $parts[0];
    }

    /**
     * Strip the version segment from a namespace string.
     *
     * filox/v1 → filox | extend-wp/v1 → extend-wp | filox-elorus → filox-elorus
     */
    private function strip_version(string $namespace): string
    {
        return preg_replace('/\/v\d+.*$/', '', $namespace);
    }

    /**
     * Compute a match score between a namespace slug and a plugin.
     *
     * Priority (highest first):
     *  10 — exact string match (ns_slug === plugin_dir)
     *   5 — one is a prefix of the other
     *   3 — one is a substring of the other
     *   N — token intersection ≥ 2  (N = number of shared tokens)
     *   0 — single-token match only → rejected (avoids "wp" false positives)
     */
    private function match_score(string $ns_slug, string $plugin_dir, string $plugin_name): int
    {
        $ns  = strtolower($ns_slug);
        $dir = strtolower($plugin_dir);

        if ($ns === $dir) return 10;
        if (str_starts_with($ns, $dir) || str_starts_with($dir, $ns)) return 5;
        if (str_contains($dir, $ns)    || str_contains($ns, $dir))    return 3;

        $ns_tokens     = $this->tokenize($ns_slug);
        $plugin_tokens = array_unique(array_merge(
            $this->tokenize($plugin_dir),
            $this->tokenize($plugin_name)
        ));
        $shared = count(array_intersect($ns_tokens, $plugin_tokens));

        // Single shared token (e.g. 'wp') is too ambiguous — require at least 2
        return $shared >= 2 ? $shared : 0;
    }

    /**
     * Split a string into lowercase tokens on dashes, underscores, and spaces.
     *
     * @return string[]
     */
    private function tokenize(string $s): array
    {
        return array_values(array_filter(preg_split('/[\s_\-]+/', strtolower($s))));
    }

    /**
     * Resolve a permission callback to a readable name and validity flag.
     *
     * @return array{name: string, valid: bool}
     */
    private function resolve_permission_callback($cb): array
    {
        $valid = is_callable($cb);

        if (is_array($cb)) {
            $class  = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
            $method = (string) ($cb[1] ?? '');
            return ['name' => $class . '::' . $method, 'valid' => $valid];
        }
        if ($cb instanceof Closure) {
            return ['name' => 'Closure', 'valid' => $valid];
        }
        $name = is_string($cb) ? $cb : '';
        if ($name === '__return_true') {
            $name = '__return_true (public)';
        }
        return ['name' => $name, 'valid' => $valid];
    }

    /**
     * Attempt to detect the controller class from a route callback.
     */
    private function detect_controller_class($callback): ?string
    {
        if (is_array($callback) && isset($callback[0])) {
            if (is_object($callback[0])) {
                return get_class($callback[0]);
            }
            if (is_string($callback[0]) && class_exists($callback[0])) {
                return $callback[0];
            }
        }
        return null;
    }
}
