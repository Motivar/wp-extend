<?php
/**
 * Orchestrates the package: the registry, the runner and every surface.
 *
 * Booted once by Loader. Plugins register their manifests on the
 * `mmwp_self_test_register` action (fired at `init` priority 0) or by
 * calling `Self_Test::instance()->registry()->register()` directly.
 *
 * @package MammothWP\SelfTest
 * @since   0.1.0
 */

namespace MammothWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Self_Test
{
    /** @var Self_Test|null */
    private static $instance = null;
    /** @var Registry */
    private $registry;
    /** @var Runner */
    private $runner;
    /** @var bool */
    private $booted = false;
    /** @var bool */
    private $registered = false;

    /**
     * @return Self_Test
     *
     * @since 0.1.0
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->registry = new Registry();
        $this->runner   = new Runner($this->registry);
    }

    /** @return Registry */
    public function registry()
    {
        return $this->registry;
    }

    /** @return Runner */
    public function runner()
    {
        return $this->runner;
    }

    /**
     * Load the shim where the dashboard needs it and attach every surface.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        if (!defined(Loader::class . '::GATES_PRODUCTION') && !Config::enabled()) {
            return;
        }

        if (Config::shim_enabled() && !class_exists('WP_CLI')) {
            require_once __DIR__ . '/Wp_Cli_Shim.php';
        }

        if (did_action('init')) {
            $this->fire_register();
        } else {
            add_action('init', [$this, 'fire_register'], 0);
        }

        Surfaces\Cli::init($this->runner);
        Surfaces\Abilities::init($this->runner);

        if (!Config::ui_enabled()) {
            return;
        }

        Browser\Menu_Inventory::init();
        (new Surfaces\Rest($this->runner))->init();
        (new Admin($this->runner))->init();
    }

    /**
     * Let plugins register their manifests.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function fire_register()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        /**
         * Register self-test manifests.
         *
         * @param Registry $registry Call `$registry->register($slug, $manifest_path, $opts)`.
         *
         * @since 0.1.0
         */
        do_action_ref_array('mmwp_self_test_register', [$this->registry]);
    }
}
