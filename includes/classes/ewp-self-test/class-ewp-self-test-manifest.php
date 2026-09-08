<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads manifest.json — the single list of self-test cases shared by the
 * dashboard, the CLI, the abilities and the pre-push / CI runners.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test_Manifest
{
    /** Site requirement keys a case may declare in `requires`. */
    const REQUIREMENTS = ['abilities', 'logger', 'ai'];

    /** @var array|null Parsed manifest. */
    private $data = null;

    /** @var string */
    private $path;

    /**
     * @param string $path Manifest file path. Defaults to the bundled manifest.json.
     */
    public function __construct($path = '')
    {
        $this->path = $path ?: __DIR__ . '/manifest.json';
    }

    /** @return string */
    public function path()
    {
        return $this->path;
    }

    /**
     * Parsed manifest, validated.
     *
     * @return array|\WP_Error
     */
    public function load()
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_readable($this->path)) {
            return new \WP_Error('ewp_self_test_manifest_missing', sprintf(__('Self-test manifest not found at %s.', 'extend-wp'), $this->path));
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded['cases']) || !is_array($decoded['cases'])) {
            return new \WP_Error('ewp_self_test_manifest_invalid', __('Self-test manifest is not valid JSON with a "cases" array.', 'extend-wp'));
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
         * Filter the parsed self-test manifest before cases are built.
         *
         * Lets another plugin append its own cases (a class extending
         * \EWP\SelfTest\EWP_Self_Test_Case) so they appear in the same
         * dashboard/CLI/ability run.
         *
         * @param array  $manifest Parsed manifest (`version`, `cases`).
         * @param string $path     Manifest file path.
         *
         * @since 1.5.0
         */
        $this->data = apply_filters('ewp_self_test_manifest', $decoded, $this->path);

        return $this->data;
    }

    /**
     * Definitions keyed by case id.
     *
     * @return array|\WP_Error
     */
    public function definitions()
    {
        $data = $this->load();

        if (is_wp_error($data)) {
            return $data;
        }

        $definitions = [];

        foreach ($data['cases'] as $entry) {
            $definitions[$entry['id']] = $entry;
        }

        return $definitions;
    }

    /**
     * Instantiate every case (or the given ids), configured from its entry.
     *
     * @param string[] $ids Empty for all.
     *
     * @return EWP_Self_Test_Case[]|\WP_Error Keyed by id.
     */
    public function cases(array $ids = [])
    {
        $definitions = $this->definitions();

        if (is_wp_error($definitions)) {
            return $definitions;
        }

        $cases = [];

        foreach ($definitions as $id => $entry) {
            if (!empty($ids) && !in_array($id, $ids, true)) {
                continue;
            }

            $class = $entry['class'];
            $case  = new $class();
            $case->configure($entry);
            $cases[$id] = $case;
        }

        if (!empty($ids)) {
            $unknown = array_diff($ids, array_keys($cases));
            if (!empty($unknown)) {
                return new \WP_Error('ewp_self_test_unknown_case', sprintf(__('Unknown self-test case(s): %s.', 'extend-wp'), implode(', ', $unknown)));
            }
        }

        return $cases;
    }

    /**
     * Check one manifest entry.
     *
     * @param mixed    $entry Raw entry.
     * @param int      $index Position, for messages.
     * @param string[] $seen  Ids already used.
     *
     * @return true|\WP_Error
     */
    private function validate_entry($entry, $index, array $seen)
    {
        if (!is_array($entry) || empty($entry['id']) || empty($entry['class']) || empty($entry['label'])) {
            return new \WP_Error('ewp_self_test_manifest_invalid', sprintf(__('Manifest case #%d needs "id", "label" and "class".', 'extend-wp'), $index));
        }

        if (in_array($entry['id'], $seen, true)) {
            return new \WP_Error('ewp_self_test_manifest_invalid', sprintf(__('Manifest case id "%s" is used more than once.', 'extend-wp'), $entry['id']));
        }

        if (!class_exists($entry['class']) || !is_subclass_of($entry['class'], __NAMESPACE__ . '\\EWP_Self_Test_Case')) {
            return new \WP_Error('ewp_self_test_manifest_invalid', sprintf(__('Manifest case "%s": class %s does not exist or does not extend EWP_Self_Test_Case.', 'extend-wp'), $entry['id'], $entry['class']));
        }

        $unknown = array_diff(isset($entry['requires']) ? (array) $entry['requires'] : [], self::REQUIREMENTS);
        if (!empty($unknown)) {
            return new \WP_Error('ewp_self_test_manifest_invalid', sprintf(__('Manifest case "%s" declares unknown requirement(s): %s.', 'extend-wp'), $entry['id'], implode(', ', $unknown)));
        }

        return true;
    }
}
