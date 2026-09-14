<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the Extend WP self-test suite with the motivar/wp-self-test
 * package (lib/motivar/wp-self-test, loaded by Composer).
 *
 * The runner, the Tools › Self-test dashboard, the `mwp-self-test/v1`
 * REST routes, the `wp mwp self-test` commands and the `mwp-self-test/*`
 * abilities all live in the package; this plugin only contributes its
 * manifest (manifest.json) and cases (cases/), the CLI classes that must
 * be re-declared under the package's WP_CLI shim, and its own gates.
 * Docs: docs/self-test.md.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
final class EWP_Self_Test
{
    const SLUG = 'extend-wp';

    /** @var EWP_Self_Test|null */
    private static $instance = null;

    /** @return EWP_Self_Test */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Whether the dashboard and its REST routes are available.
     *
     * Kept as a plugin-level entry point; the package decides through
     * `mwp_self_test_ui_enabled`, onto which the legacy
     * `ewp_self_test_ui_enabled` filter is mapped.
     *
     * @return bool
     */
    public static function ui_enabled()
    {
        return class_exists('Motivar\\SelfTest\\Config') && \Motivar\SelfTest\Config::ui_enabled();
    }

    /**
     * Capability required to run self-tests on every surface.
     *
     * @return string
     */
    public static function capability()
    {
        return class_exists('Motivar\\SelfTest\\Config') ? \Motivar\SelfTest\Config::capability() : 'manage_options';
    }

    /**
     * Hook the registration and map the plugin's legacy filters.
     *
     * @return void
     */
    public function init()
    {
        if (!class_exists('Motivar\\SelfTest\\Loader')) {
            return;
        }

        add_action('mwp_self_test_register', [$this, 'register']);
        add_filter('mwp_self_test_ui_enabled', [$this, 'legacy_ui_enabled']);
        add_filter('mwp_self_test_capability', [$this, 'legacy_capability']);
        add_filter('mwp_self_test_abilities_available', [$this, 'abilities_available']);
        add_filter('mwp_self_test_manifest', [$this, 'legacy_manifest'], 10, 3);
        add_action('mwp_self_test_completed', [$this, 'legacy_completed']);
    }

    /**
     * Register manifest.json with the package.
     *
     * @param \Motivar\SelfTest\Registry $registry The package registry.
     *
     * @return void
     */
    public function register($registry)
    {
        self::load_cases();

        $registry->register(self::SLUG, __DIR__ . '/manifest.json', [
            'label'        => 'Extend WP',
            'requirements' => ['abilities', 'logger', 'ai'],
            'cli_loaders'  => [[__CLASS__, 'load_cli']],
        ]);
    }

    /**
     * Load the case classes.
     *
     * They extend Motivar\SelfTest\Case_Base, which the package's loader
     * only autoloads after it booted on `plugins_loaded`, so they cannot be
     * required when Setup.php loads this file.
     *
     * @return void
     */
    public static function load_cases()
    {
        if (class_exists(__NAMESPACE__ . '\\Cases\\Content_Crud_Case', false)) {
            return;
        }

        if (!class_exists(__NAMESPACE__ . '\\EWP_Self_Test_Case', false)) {
            // Cases written against the pre-1.5 base class keep working.
            class_alias('Motivar\\SelfTest\\Case_Base', __NAMESPACE__ . '\\EWP_Self_Test_Case');
        }

        foreach ([
            'trait-fixture-content-type',
            'class-content-crud-case',
            'class-search-filter-case',
            'class-cache-flush-case',
            'class-logger-case',
            'class-options-portability-case',
            'class-ai-case',
            'class-content-portability-case',
            'class-object-search-case',
            'class-rest-health-case',
            'class-typed-content-case',
        ] as $file) {
            require_once __DIR__ . '/cases/' . $file . '.php';
        }
    }

    /**
     * Declare the CLI classes that bail without WP_CLI, so cases can call
     * their static handlers under the package's shim.
     *
     * Files that `return` early when WP_CLI is missing never declared their
     * class on plugin load, so they are included again here (plain
     * `include`, PHP already marked them as included once).
     *
     * @return void
     */
    public static function load_cli()
    {
        $base = dirname(__DIR__);

        if (!class_exists('EWP_Content_CLI', false)) {
            include $base . '/awm-content-db-api/custom-content/class-content-cli.php';
        }

        if (!class_exists('WP_CLI_Integration', false)) {
            include $base . '/wp-cli/class-cli-commands.php';
        }

        if (!class_exists('EWP_Options_Portability_CLI', false)) {
            include $base . '/ewp-options-portability/class-options-portability-cli.php';
        }
    }

    /**
     * @param bool $enabled Package default.
     *
     * @return bool
     */
    public function legacy_ui_enabled($enabled)
    {
        /**
         * Filter whether the self-test dashboard (and its REST routes) are registered.
         *
         * @param bool $enabled Default: the package's `mwp_self_test_ui_enabled` value (WP_DEBUG).
         *
         * @since 1.5.0
         */
        return (bool) apply_filters('ewp_self_test_ui_enabled', $enabled);
    }

    /**
     * @param string $capability Package default.
     *
     * @return string
     */
    public function legacy_capability($capability)
    {
        /**
         * Filter the capability required to run the self-tests.
         *
         * @param string $capability Default 'manage_options'.
         *
         * @since 1.5.0
         */
        return (string) apply_filters('ewp_self_test_capability', $capability);
    }

    /**
     * Abilities are only usable for cases when the plugin's own gate is open.
     *
     * @param bool $available Package default.
     *
     * @return bool
     */
    public function abilities_available($available)
    {
        return $available && class_exists('EWP\\Abilities\\EWP_Abilities') && \EWP\Abilities\EWP_Abilities::is_enabled();
    }

    /**
     * @param array  $manifest Parsed manifest.
     * @param string $path     Manifest path.
     * @param string $plugin   Owning plugin slug.
     *
     * @return array
     */
    public function legacy_manifest($manifest, $path, $plugin)
    {
        if ($plugin !== self::SLUG) {
            return $manifest;
        }

        /**
         * Filter the parsed Extend WP self-test manifest before cases are built.
         *
         * @param array  $manifest Parsed manifest (`version`, `cases`).
         * @param string $path     Manifest file path.
         *
         * @since 1.5.0
         */
        return apply_filters('ewp_self_test_manifest', $manifest, $path);
    }

    /**
     * @param array $report Stored report.
     *
     * @return void
     */
    public function legacy_completed($report)
    {
        /**
         * Fires after a self-test run completed.
         *
         * @param array $report The report that was stored.
         *
         * @since 1.5.0
         */
        do_action('ewp_self_test_completed', $report);
    }
}

EWP_Self_Test::instance()->init();
