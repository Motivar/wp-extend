<?php
/**
 * The wp-admin dashboard: Tools › Self-test.
 *
 * Lists the cases of every registered plugin and drives them through the
 * REST routes. Only instantiated when Config::ui_enabled() (WP_DEBUG) is
 * true. Markup comes from templates/dashboard.php, behaviour from
 * assets/self-test.js, both enqueued on this screen only.
 *
 * @package Motivar\SelfTest
 * @since   0.1.0
 */

namespace Motivar\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    const PAGE_SLUG = 'mwp-self-test';

    /** @var Runner */
    private $runner;

    /** @var string Screen hook suffix of the registered page. */
    private $hook = '';

    /**
     * @param Runner $runner Shared runner.
     */
    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /** @return void */
    public function init()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /** @return void */
    public function register_page()
    {
        $this->hook = (string) add_management_page(
            __('Self-test', Config::TEXT_DOMAIN),
            __('Self-test', Config::TEXT_DOMAIN),
            Config::capability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Enqueue the dashboard script and styles on this screen only.
     *
     * @param string $hook Current screen hook suffix.
     *
     * @return void
     */
    public function enqueue($hook)
    {
        if ($hook !== $this->hook) {
            return;
        }

        $version = Loader::version() ?: '0.0.0';

        wp_enqueue_style('mwp-self-test', Config::asset_url('assets/self-test.css'), [], $version);
        wp_enqueue_script('mwp-self-test', Config::asset_url('assets/self-test.js'), [], $version, true);
        wp_localize_script('mwp-self-test', 'mwpSelfTest', [
            'strings' => [
                'running'      => __('Running…', Config::TEXT_DOMAIN),
                'previewing'   => __('Loading preview…', Config::TEXT_DOMAIN),
                'noSelection'  => __('Select at least one case.', Config::TEXT_DOMAIN),
                'error'        => __('Request failed.', Config::TEXT_DOMAIN),
                'pass'         => __('pass', Config::TEXT_DOMAIN),
                'fail'         => __('fail', Config::TEXT_DOMAIN),
                'skip'         => __('skipped', Config::TEXT_DOMAIN),
                'error_status' => __('error', Config::TEXT_DOMAIN),
                'unavailable'  => __('unavailable', Config::TEXT_DOMAIN),
                'hiddenChecks' => __('check(s) of other surfaces hidden by the filter', Config::TEXT_DOMAIN),
            ],
        ]);
    }

    /**
     * Render templates/dashboard.php.
     *
     * @return void
     */
    public function render()
    {
        $cases    = $this->runner->cases();
        $plugins  = $this->runner->registry()->plugins();
        $rest_url = esc_url_raw(rest_url('mwp-self-test/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        $error    = is_wp_error($cases) ? $cases->get_error_message() : '';
        $cases    = is_wp_error($cases) ? [] : $cases;
        $version  = Loader::version();

        include Loader::path() . '/templates/dashboard.php';
    }
}
