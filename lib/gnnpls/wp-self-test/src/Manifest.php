<?php
/**
 * One plugin's manifest.json: the list of its self-test cases.
 *
 * Shape: `{"version": 1, "cases": [{id, label, category, layers, class,
 * requires, args, covers: {rest, cli, ability}}]}`.
 *
 * @package Gnnpls\SelfTest
 * @since   0.1.0
 */

namespace Gnnpls\SelfTest;

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
            return new \WP_Error('mwp_self_test_manifest_missing', sprintf(__('Self-test manifest not found at %s.', Config::TEXT_DOMAIN), $this->path));
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded['cases']) || !is_array($decoded['cases'])) {
            return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Self-test manifest %s is not valid JSON with a "cases" array.', Config::TEXT_DOMAIN), $this->path));
        }

        $seen = [];
        foreach ($decoded['cases'] as $index => $entry) {
            $problem = $this->validate_entry($entry, $index, $seen);
            if (is_wp_error($problem)) {
                return $problem;
            }
            $seen[] = $entry['id'];
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
        $this->data = apply_filters('mwp_self_test_manifest', $decoded, $this->path, $this->plugin);

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
            return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Manifest case #%d needs "id", "label" and "class".', Config::TEXT_DOMAIN), $index));
        }

        if (in_array($entry['id'], $seen, true)) {
            return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Manifest case id "%s" is used more than once.', Config::TEXT_DOMAIN), $entry['id']));
        }

        if (!class_exists($entry['class']) || !is_subclass_of($entry['class'], Case_Base::class)) {
            return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Manifest case "%1$s": class %2$s does not exist or does not extend %3$s.', Config::TEXT_DOMAIN), $entry['id'], $entry['class'], Case_Base::class));
        }

        if ($this->requirements !== null) {
            $unknown = array_diff(isset($entry['requires']) ? (array) $entry['requires'] : [], $this->requirements);
            if ($unknown !== []) {
                return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Manifest case "%1$s" declares unknown requirement(s): %2$s.', Config::TEXT_DOMAIN), $entry['id'], implode(', ', $unknown)));
            }
        }

        return true;
    }
}
