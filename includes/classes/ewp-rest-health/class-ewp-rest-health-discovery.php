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
     * Build a map of namespace → plugin_path.
     *
     * Resolution order:
     *  1. Core namespace blocklist → __wp_core__
     *  2. Static file scan: grep each plugin's PHP files for register_rest_route()
     *     literal namespace strings → most accurate, catches any naming convention
     *  3. Token-intersection heuristic → fallback when scan finds nothing
     *  4. ewp_rest_health_namespace_plugin_map filter → manual overrides
     *
     * Results are cached in a transient keyed by active-plugin list hash.
     * Call clear_map_cache() (e.g., on Refresh) to force a rescan.
     *
     * @return array<string, string>  namespace => plugin path
     */
    public function build_namespace_plugin_map(): array
    {
        $plugins = array_filter(
            $this->get_active_plugins(),
            fn($path) => $path !== self::CORE_PLUGIN_KEY,
            ARRAY_FILTER_USE_KEY
        );

        // Cache key changes whenever the active-plugin list changes
        $cache_key = 'ewp_rh_ns_map_' . substr(md5(implode(',', array_keys($plugins))), 0, 12);
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return apply_filters('ewp_rest_health_namespace_plugin_map', $cached);
        }

        $all_ns = $this->get_all_namespaces();
        $map    = [];

        // Step 1 — core namespaces
        foreach ($all_ns as $ns) {
            if ($this->is_core_namespace($ns)) {
                $map[$ns] = self::CORE_PLUGIN_KEY;
            }
        }

        // Step 2 — scan every plugin's PHP source for register_rest_route() literals
        $scan_results = []; // namespace_slug => plugin_path  (from file scan)
        foreach ($plugins as $path => $meta) {
            foreach ($this->scan_plugin_namespaces($path) as $found_slug) {
                if (!isset($scan_results[$found_slug])) {
                    $scan_results[$found_slug] = $path;
                }
            }
        }

        // Step 3 — resolve each registered namespace
        foreach ($all_ns as $ns) {
            if (isset($map[$ns])) {
                continue; // already mapped (core)
            }

            $ns_slug = $this->strip_version($ns);

            // File-scan hit (exact slug match or namespace === scanned slug)
            if (isset($scan_results[$ns_slug]) || isset($scan_results[$ns])) {
                $map[$ns] = $scan_results[$ns_slug] ?? $scan_results[$ns];
                continue;
            }

            // Heuristic fallback
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
                $map[$ns] = $best_plugin;
            }
        }

        set_transient($cache_key, $map, HOUR_IN_SECONDS);

        return apply_filters('ewp_rest_health_namespace_plugin_map', $map);
    }

    /**
     * Invalidate the namespace map cache (called by the Refresh button).
     * Deletes all ewp_rh_ns_map_* transients.
     */
    public function clear_map_cache(): void
    {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ewp_rh_ns_map_%'"
        );
    }

    /**
     * Scan a plugin's PHP files for register_rest_route() calls and return
     * every namespace string literal found.
     *
     * Handles the two most common patterns:
     *   register_rest_route( 'namespace/v1', '/path', ... )
     *   protected $namespace = 'namespace/v1';
     *
     * Does NOT trace variables or constants — a best-effort static analysis.
     * Results are usable for the vast majority of plugins.
     *
     * @param  string   $plugin_path  e.g. 'sync/sync.php'
     * @return string[] Unique namespace slugs found (version stripped)
     */
    public function scan_plugin_namespaces(string $plugin_path): array
    {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
        if (!is_dir($plugin_dir)) {
            return [];
        }

        $found   = [];
        $counter = 0;
        $files   = $this->collect_php_files($plugin_dir, 0, $counter);

        foreach ($files as $file) {
            $src = @file_get_contents($file);
            if (!$src) {
                continue;
            }

            // Pattern 1: register_rest_route( 'namespace/v1', ...
            preg_match_all(
                '/register_rest_route\s*\(\s*[\'"]([a-zA-Z0-9][a-zA-Z0-9_\-\/\.]*)[\'"]/',
                $src,
                $m1
            );

            // Pattern 2: $namespace = 'value';  or  $rest_namespace = 'value';
            preg_match_all(
                '/\$(?:namespace|rest_namespace|rest_base)\s*=\s*[\'"]([a-zA-Z0-9][a-zA-Z0-9_\-\/\.]*)[\'"]/',
                $src,
                $m2
            );

            foreach (array_merge($m1[1] ?? [], $m2[1] ?? []) as $raw) {
                $slug = $this->strip_version($raw);
                if ($slug && strlen($slug) > 1) {
                    $found[$slug] = true;
                    $found[$raw]  = true; // also store with version suffix
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Recursively collect PHP files in a directory.
     * Skips common non-source directories and caps at 300 files to stay fast.
     *
     * @param string $dir
     * @param int    $depth   Current recursion depth (max 6)
     * @param int    &$count  Running file count (stops at 300)
     * @return string[]
     */
    private function collect_php_files(string $dir, int $depth, int &$count): array
    {
        static $skip_dirs = ['node_modules', 'vendor', 'build', 'dist', 'assets', 'tests', 'test', '.git'];

        if ($depth > 6 || $count >= 300) {
            return [];
        }

        $files = [];
        $items = @scandir($dir);
        if (!$items) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;

            if (is_file($path) && str_ends_with($item, '.php') && $count < 300) {
                $files[] = $path;
                $count++;
            } elseif (is_dir($path) && !in_array($item, $skip_dirs, true)) {
                $files = array_merge($files, $this->collect_php_files($path, $depth + 1, $count));
            }
        }

        return $files;
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
            'callback_name'       => $this->resolve_callback_name($handlers),
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
     * Two rules only — no prefix/substring checks (they cause false positives
     * like 'filox-sync' being attributed to 'filox' because it starts with 'filox'):
     *
     *  10 — exact string match  (ns_slug === plugin_dir)
     *   N — token intersection ≥ 2  (N = shared token count; uses dir + plugin name)
     *   0 — anything else (single shared token is too ambiguous)
     *
     * Examples:
     *   filox/v1      vs filox         → exact 10  ✓
     *   filox-sync/v1 vs sync (Filox Rates Sync) → tokens ['filox','sync'] ∩ ['sync','filox','rates'] = 2 ✓
     *   filox-sync/v1 vs filox         → tokens ['filox','sync'] ∩ ['filox'] = 1 → 0 ✓
     *   extend-wp/v1  vs wp-extend     → tokens ['extend','wp'] ∩ ['wp','extend'] = 2 ✓
     */
    private function match_score(string $ns_slug, string $plugin_dir, string $plugin_name): int
    {
        if (strtolower($ns_slug) === strtolower($plugin_dir)) {
            return 10;
        }

        $ns_tokens     = $this->tokenize($ns_slug);
        $plugin_tokens = array_unique(array_merge(
            $this->tokenize($plugin_dir),
            $this->tokenize($plugin_name)
        ));
        $shared = count(array_intersect($ns_tokens, $plugin_tokens));

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

    /**
     * Build a human-readable callback string for the first handler that has one.
     *
     * Examples:
     *   [AWM_API, 'get_case_fields']  → 'AWM_API::get_case_fields'
     *   'my_function'                 → 'my_function'
     *   Closure                       → 'Closure'
     *   __return_true                 → '__return_true'
     *
     * @param  array $handlers Raw handler array from WP REST server.
     * @return string|null
     */
    private function resolve_callback_name(array $handlers): ?string
    {
        foreach ($handlers as $handler) {
            if (empty($handler['callback'])) {
                continue;
            }
            $cb = $handler['callback'];

            if ($cb instanceof Closure) {
                return 'Closure';
            }
            if (is_array($cb) && isset($cb[0], $cb[1])) {
                $class  = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
                $method = (string) $cb[1];
                return $class . '::' . $method;
            }
            if (is_string($cb) && $cb !== '') {
                return $cb;
            }
        }
        return null;
    }
}
