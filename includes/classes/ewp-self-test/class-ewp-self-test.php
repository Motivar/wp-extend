<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-ewp-self-test-wp-cli-shim.php';
require_once __DIR__ . '/class-ewp-self-test-case.php';
require_once __DIR__ . '/class-ewp-self-test-manifest.php';
require_once __DIR__ . '/class-ewp-self-test-runner.php';
require_once __DIR__ . '/class-ewp-self-test-rest.php';
require_once __DIR__ . '/class-ewp-self-test-cli.php';
require_once __DIR__ . '/class-ewp-self-test-admin.php';
require_once __DIR__ . '/cases/class-content-crud-case.php';
require_once __DIR__ . '/cases/class-search-filter-case.php';
require_once __DIR__ . '/cases/class-cache-flush-case.php';
require_once __DIR__ . '/cases/class-logger-case.php';
require_once __DIR__ . '/cases/class-options-portability-case.php';
require_once __DIR__ . '/cases/class-ai-case.php';

/**
 * Bootstraps the self-test module.
 *
 * One runner (EWP_Self_Test_Runner) driven by manifest.json is exposed
 * through four surfaces: the wp-admin dashboard + its REST routes (both
 * only when the dashboard is enabled — WP_DEBUG by default), the
 * `wp ewp self-test` commands (always), and the ewp-self-test/* abilities
 * (when the Abilities API is available). Docs: docs/self-test.md.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test
{
    /** @var EWP_Self_Test|null */
    private static $instance = null;

    /** @var EWP_Self_Test_Runner */
    private $runner;

    /** @return EWP_Self_Test */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->runner = new EWP_Self_Test_Runner();
    }

    /** @return EWP_Self_Test_Runner The shared runner every surface uses. */
    public function runner()
    {
        return $this->runner;
    }

    /**
     * Whether the dashboard and its REST routes are available.
     *
     * Off unless WP_DEBUG is on, because a run creates data on the site.
     *
     * @return bool
     */
    public static function ui_enabled()
    {
        /**
         * Filter whether the self-test dashboard (and its REST routes) are registered.
         *
         * @param bool $enabled Default: `defined('WP_DEBUG') && WP_DEBUG`.
         *
         * @since 1.5.0
         */
        return (bool) apply_filters('ewp_self_test_ui_enabled', defined('WP_DEBUG') && WP_DEBUG);
    }

    /**
     * Capability required to run self-tests on every surface.
     *
     * @return string
     */
    public static function capability()
    {
        /**
         * Filter the capability required to run the self-tests.
         *
         * @param string $capability Default 'manage_options'.
         *
         * @since 1.5.0
         */
        return (string) apply_filters('ewp_self_test_capability', 'manage_options');
    }

    /** @return void */
    public function init()
    {
        EWP_Self_Test_CLI::init($this->runner);

        add_filter('ewp_abilities_providers', [$this, 'register_abilities_provider']);

        if (!self::ui_enabled()) {
            return;
        }

        $rest = new EWP_Self_Test_REST($this->runner);
        $rest->init();

        $admin = new EWP_Self_Test_Admin($this->runner);
        $admin->init();
    }

    /**
     * Add the ewp-self-test provider to the plugin's abilities.
     *
     * @param array $providers Providers keyed by slug.
     *
     * @return array
     */
    public function register_abilities_provider($providers)
    {
        if (class_exists('EWP\\Abilities\\EWP_Abilities_Provider')) {
            require_once __DIR__ . '/class-ewp-self-test-abilities-provider.php';
            $providers['self-test'] = new EWP_Self_Test_Abilities_Provider($this->runner);
        }

        return $providers;
    }
}

EWP_Self_Test::instance()->init();
