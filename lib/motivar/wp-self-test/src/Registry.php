<?php
/**
 * The plugins that registered a self-test manifest.
 *
 * A plugin registers once, from its own folder:
 *
 *     add_action('mwp_self_test_register', function ($registry) {
 *         $registry->register('my-plugin', __DIR__ . '/self-test/manifest.json', [
 *             'label'        => 'My Plugin',
 *             'requirements' => ['abilities', 'logger'],   // optional: valid `requires` keys
 *             'cli_loaders'  => [[My_Plugin::class, 'load_cli']], // optional: load CLI classes under the shim
 *         ]);
 *     });
 *
 * Case ids must be unique across every registered manifest.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */

namespace Motivar\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Registry
{
    /** @var array<string,array> slug => {manifest, label, requirements, cli_loaders} */
    private $plugins = [];

    /** @var Manifest[]|null */
    private $manifests = null;

    /** @var bool */
    private $cli_loaded = false;

    /**
     * Register a plugin's manifest.
     *
     * @param string $slug          Plugin slug, used to group and filter cases.
     * @param string $manifest_path Absolute path to the manifest JSON file.
     * @param array  $opts          label (string), requirements (string[]|null), cli_loaders (callable[]).
     *
     * @return Registry
     *
     * @since 0.1.0
     */
    public function register($slug, $manifest_path, array $opts = [])
    {
        $slug = sanitize_key((string) $slug);
        if ($slug === '') {
            return $this;
        }

        $this->plugins[$slug] = [
            'manifest'     => (string) $manifest_path,
            'label'        => isset($opts['label']) ? (string) $opts['label'] : $slug,
            'requirements' => isset($opts['requirements']) && is_array($opts['requirements']) ? array_values(array_map('strval', $opts['requirements'])) : null,
            'cli_loaders'  => isset($opts['cli_loaders']) ? array_values(array_filter((array) $opts['cli_loaders'], 'is_callable')) : [],
        ];
        $this->manifests = null;

        return $this;
    }

    /**
     * @return array<string,array> Registered plugins keyed by slug.
     *
     * @since 0.1.0
     */
    public function plugins()
    {
        return $this->plugins;
    }

    /**
     * @return Manifest[] Keyed by plugin slug.
     *
     * @since 0.1.0
     */
    public function manifests()
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        foreach ($this->plugins as $slug => $plugin) {
            $this->manifests[$slug] = new Manifest($plugin['manifest'], $slug, $plugin['requirements']);
        }

        return $this->manifests;
    }

    /**
     * Every case definition across every plugin, keyed by id.
     *
     * @param string $plugin Restrict to one plugin slug; empty for all.
     *
     * @return array|\WP_Error
     *
     * @since 0.1.0
     */
    public function definitions($plugin = '')
    {
        if ($this->plugins === []) {
            return new \WP_Error('mwp_self_test_no_manifests', __('No plugin has registered a self-test manifest. Hook mwp_self_test_register and call $registry->register().', Config::TEXT_DOMAIN));
        }

        $all = [];
        foreach ($this->manifests() as $slug => $manifest) {
            if ($plugin !== '' && $slug !== $plugin) {
                continue;
            }

            $definitions = $manifest->definitions();
            if (is_wp_error($definitions)) {
                return $definitions;
            }

            foreach ($definitions as $id => $entry) {
                if (isset($all[$id])) {
                    return new \WP_Error('mwp_self_test_manifest_invalid', sprintf(__('Self-test case id "%1$s" is declared by both "%2$s" and "%3$s".', Config::TEXT_DOMAIN), $id, $all[$id]['plugin'], $slug));
                }
                $all[$id] = $entry;
            }
        }

        if ($plugin !== '' && $all === [] && !isset($this->plugins[$plugin])) {
            return new \WP_Error('mwp_self_test_unknown_plugin', sprintf(__('No self-test manifest is registered for "%s".', Config::TEXT_DOMAIN), $plugin));
        }

        return $all;
    }

    /**
     * Instantiate cases, configured from their entries.
     *
     * @param string[] $ids    Empty for all.
     * @param string   $plugin Restrict to one plugin slug; empty for all.
     *
     * @return Case_Base[]|\WP_Error Keyed by id.
     *
     * @since 0.1.0
     */
    public function cases(array $ids = [], $plugin = '')
    {
        $definitions = $this->definitions($plugin);
        if (is_wp_error($definitions)) {
            return $definitions;
        }

        $cases = [];
        foreach ($definitions as $id => $entry) {
            if ($ids !== [] && !in_array($id, $ids, true)) {
                continue;
            }
            $class = $entry['class'];
            $case  = new $class();
            $case->configure($entry);
            $cases[$id] = $case;
        }

        $unknown = array_diff($ids, array_keys($cases));
        if ($unknown !== []) {
            return new \WP_Error('mwp_self_test_unknown_case', sprintf(__('Unknown self-test case(s): %s.', Config::TEXT_DOMAIN), implode(', ', $unknown)));
        }

        return $cases;
    }

    /**
     * Run every registered CLI loader once, so command classes that bail
     * without WP_CLI are declared under the shim before cases call them.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function load_cli()
    {
        if ($this->cli_loaded || !class_exists('WP_CLI')) {
            return;
        }
        $this->cli_loaded = true;

        foreach ($this->plugins as $plugin) {
            foreach ($plugin['cli_loaders'] as $loader) {
                call_user_func($loader);
            }
        }
    }
}
