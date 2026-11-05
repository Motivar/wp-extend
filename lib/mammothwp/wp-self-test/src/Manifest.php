<?php
/**
 * One plugin's manifest.json: the list of its self-test cases.
 *
 * Shape: `{"version": 1, "cases": [{id, label, category, layers, class,
 * requires, args, covers: {rest, cli, ability}, browser: {crawl, specs,
 * allow, timeout}}]}`.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */

namespace MammothWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Manifest
{
    /** @var string */
    private $path;
    /** @var string */
    private $plugin;
    /** @var string[]|null */
    private $requirements;
    /** @var array|null */
    private $data = null;

    /**
     * @param string        $path         Manifest file path.
     * @param string        $plugin       Owning plugin slug.
     * @param string[]|null $requirements Valid `requires` keys; null accepts any.
     */
    public function __construct($path, $plugin = '', ?array $requirements = null)
    {
        $this->path         = (string) $path;
        $this->plugin       = (string) $plugin;
        $this->requirements = $requirements;
    }

    /** @return string */
    public function path()
    {
        return $this->path;
    }

    /** @return string */
    public function plugin()
    {
        return $this->plugin;
    }

    /**
     * Parsed and validated manifest.
     *
     * @return array|\WP_Error
     *
     * @since 0.1.0
     */
    public function load()
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_readable($this->path)) {
            return new \WP_Error('mmwp_self_test_manifest_missing', sprintf(__('Self-test manifest not found at %s.', Config::TEXT_DOMAIN), $this->path));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local manifest file.
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded['cases']) || !is_array($decoded['cases'])) {
            return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Self-test manifest %s is not valid JSON with a "cases" array.', Config::TEXT_DOMAIN), $this->path));
        }

        $seen = [];
        foreach ($decoded['cases'] as $index => $entry) {
            $problem = $this->validate_entry($entry, $index, $seen);
            if (is_wp_error($problem)) {
                return $problem;
            }
            $seen[] = $entry['id'];
            $decoded['cases'][$index] = $this->normalise_browser($entry);
        }

        /**
         * Filter one parsed manifest before its cases are built.
         *
         * @param array  $manifest Parsed manifest (`version`, `cases`).
         * @param string $path     Manifest file path.
         * @param string $plugin   Owning plugin slug.
         *
         * @since 0.1.0
         */
        $this->data = apply_filters('mmwp_self_test_manifest', $decoded, $this->path, $this->plugin);

        return $this->data;
    }

    /**
     * Definitions keyed by case id, each carrying its `plugin` slug.
     *
     * @return array|\WP_Error
     *
     * @since 0.1.0
     */
    public function definitions()
    {
        $data = $this->load();
        if (is_wp_error($data)) {
            return $data;
        }

        $definitions = [];
        foreach ($data['cases'] as $entry) {
            $entry['plugin']         = $this->plugin;
            $entry['manifest_dir']   = dirname($this->path);
            $definitions[$entry['id']] = $entry;
        }

        return $definitions;
    }

    /**
     * @param mixed    $entry Raw entry.
     * @param int      $index Position, for messages.
     * @param string[] $seen  Ids already used in this manifest.
     *
     * @return true|\WP_Error
     */
    private function validate_entry($entry, $index, array $seen)
    {
        if (!is_array($entry) || empty($entry['id']) || empty($entry['class']) || empty($entry['label'])) {
            return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Manifest case #%d needs "id", "label" and "class".', Config::TEXT_DOMAIN), $index));
        }

        if (in_array($entry['id'], $seen, true)) {
            return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Manifest case id "%s" is used more than once.', Config::TEXT_DOMAIN), $entry['id']));
        }

        if (!class_exists($entry['class']) || !is_subclass_of($entry['class'], Case_Base::class)) {
            return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Manifest case "%1$s": class %2$s does not exist or does not extend %3$s.', Config::TEXT_DOMAIN), $entry['id'], $entry['class'], Case_Base::class));
        }

        if ($this->requirements !== null) {
            $unknown = array_diff(isset($entry['requires']) ? (array) $entry['requires'] : [], $this->requirements);
            if ($unknown !== []) {
                return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Manifest case "%1$s" declares unknown requirement(s): %2$s.', Config::TEXT_DOMAIN), $entry['id'], implode(', ', $unknown)));
            }
        }

        return $this->validate_browser($entry);
    }

    /**
     * Validate an optional `browser` entry:
     * `{crawl?: bool, specs?: string[], allow?: {console|requests|pages: string[]}, timeout?: int}`
     * with at least one of `crawl` / `specs`.
     *
     * @param array $entry Raw entry.
     *
     * @return true|\WP_Error
     *
     * @since 2.1.0
     */
    private function validate_browser(array $entry)
    {
        if (!array_key_exists('browser', $entry)) {
            return true;
        }

        $browser = $entry['browser'];
        $id      = $entry['id'];
        $invalid = function ($message) use ($id) {
            return new \WP_Error('mmwp_self_test_manifest_invalid', sprintf(__('Manifest case "%1$s": %2$s', Config::TEXT_DOMAIN), $id, $message));
        };

        if (!is_array($browser) || ($browser !== [] && array_keys($browser) === range(0, count($browser) - 1))) {
            return $invalid(__('"browser" must be an object.', Config::TEXT_DOMAIN));
        }

        $unknown = array_diff(array_keys($browser), ['crawl', 'specs', 'allow', 'timeout']);
        if ($unknown !== []) {
            return $invalid(sprintf(__('unknown "browser" key(s): %s.', Config::TEXT_DOMAIN), implode(', ', $unknown)));
        }

        if (empty($browser['crawl']) && empty($browser['specs'])) {
            return $invalid(__('"browser" needs "crawl": true and/or a non-empty "specs" list.', Config::TEXT_DOMAIN));
        }

        if (isset($browser['crawl']) && !is_bool($browser['crawl'])) {
            return $invalid(__('"browser.crawl" must be true or false.', Config::TEXT_DOMAIN));
        }

        foreach (isset($browser['specs']) ? (is_array($browser['specs']) ? $browser['specs'] : [null]) : [] as $spec) {
            if (!is_string($spec) || $spec === '' || $spec[0] === '/' || strpos($spec, '..') !== false) {
                return $invalid(__('"browser.specs" must be relative paths (no "..") inside the plugin.', Config::TEXT_DOMAIN));
            }
        }

        if (isset($browser['timeout']) && (!is_int($browser['timeout']) || $browser['timeout'] < 1)) {
            return $invalid(__('"browser.timeout" must be a positive number of seconds.', Config::TEXT_DOMAIN));
        }

        return $this->validate_allow(isset($browser['allow']) ? $browser['allow'] : [], $invalid);
    }

    /**
     * Validate `browser.allow`: lists of regular-expression strings.
     *
     * @param mixed    $allow   Raw value.
     * @param callable $invalid Builds the WP_Error for a message.
     *
     * @return true|\WP_Error
     *
     * @since 2.1.0
     */
    private function validate_allow($allow, callable $invalid)
    {
        if (!is_array($allow)) {
            return $invalid(__('"browser.allow" must be an object.', Config::TEXT_DOMAIN));
        }

        foreach ($allow as $kind => $patterns) {
            if (!in_array($kind, ['console', 'requests', 'pages'], true) || !is_array($patterns) || $patterns !== array_filter($patterns, 'is_string')) {
                return $invalid(__('"browser.allow" accepts "console", "requests" and "pages", each a list of regular-expression strings.', Config::TEXT_DOMAIN));
            }
        }

        return true;
    }

    /**
     * A case with `browser` checks always lists the `browser` layer, and
     * the optional keys get defaults.
     *
     * @param array $entry Validated entry.
     *
     * @return array
     *
     * @since 2.1.0
     */
    private function normalise_browser(array $entry)
    {
        if (!isset($entry['browser'])) {
            return $entry;
        }

        $entry['browser'] = [
            'crawl'   => !empty($entry['browser']['crawl']),
            'specs'   => isset($entry['browser']['specs']) ? array_values($entry['browser']['specs']) : [],
            'allow'   => array_merge(['console' => [], 'requests' => [], 'pages' => []], isset($entry['browser']['allow']) ? $entry['browser']['allow'] : []),
            'timeout' => isset($entry['browser']['timeout']) ? $entry['browser']['timeout'] : 300,
        ];

        $layers          = isset($entry['layers']) ? (array) $entry['layers'] : [];
        $entry['layers'] = array_values(array_unique(array_merge($layers, ['browser'])));

        return $entry;
    }
}
