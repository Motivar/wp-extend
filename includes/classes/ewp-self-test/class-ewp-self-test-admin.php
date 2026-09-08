<?php

namespace EWP\SelfTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The wp-admin dashboard (Extend WP > Self test).
 *
 * Registered through the plugin's own options-page abstraction
 * (`awm_add_options_boxes_filter`, like the log viewer) so it inherits the
 * capability check and admin chrome. The page body comes from
 * templates/admin-view/self-test.php; behaviour from
 * assets/js/modules/ewp-self-test.js, loaded lazily by the Dynamic Asset
 * Loader when `.ewp-self-test` is in the DOM. Only instantiated when
 * EWP_Self_Test::ui_enabled() (WP_DEBUG) is true.
 *
 * @package EWP\SelfTest
 * @since   1.5.0
 */
class EWP_Self_Test_Admin
{
    const PAGE_KEY = 'ewp-self-test';

    /** @var EWP_Self_Test_Runner */
    private $runner;

    public function __construct(EWP_Self_Test_Runner $runner)
    {
        $this->runner = $runner;
    }

    /** @return void */
    public function init()
    {
        add_filter('awm_add_options_boxes_filter', [$this, 'register_page'], 102);
        add_filter('ewp_register_dynamic_assets', [$this, 'register_assets']);
    }

    /**
     * Add the page to the plugin's options pages.
     *
     * @param array $options Options pages keyed by slug.
     *
     * @return array
     */
    public function register_page($options)
    {
        $options[self::PAGE_KEY] = [
            'title'       => __('Self test', 'extend-wp'),
            'callback'    => [$this, 'fields'],
            'order'       => 1000000000001,
            'cap'         => EWP_Self_Test::capability(),
            'hide_submit' => true,
            'parent'      => 'extend-wp',
        ];

        return $options;
    }

    /**
     * The page is one HTML field carrying the template.
     *
     * @return array awm_show_content field definitions.
     */
    public function fields()
    {
        return [
            'ewp_self_test_dashboard' => [
                'case'         => 'html',
                'value'        => $this->render(),
                'exclude_meta' => true,
            ],
        ];
    }

    /**
     * Render templates/admin-view/self-test.php.
     *
     * @return string
     */
    private function render()
    {
        $cases    = $this->runner->cases();
        $rest_url = esc_url(rest_url('extend-wp/v1/self-test'));
        $nonce    = wp_create_nonce('wp_rest');
        $manifest = $this->runner->manifest()->path();

        if (is_wp_error($cases)) {
            return '<div class="notice notice-error"><p>' . esc_html($cases->get_error_message()) . '</p></div>';
        }

        ob_start();
        include awm_path . 'templates/admin-view/self-test.php';

        return (string) ob_get_clean();
    }

    /**
     * Lazy-load the dashboard script and styles.
     *
     * @param array $assets Registered dynamic assets.
     *
     * @return array
     */
    public function register_assets($assets)
    {
        $assets[] = [
            'handle'   => 'ewp-self-test-style',
            'selector' => '.ewp-self-test',
            'type'     => 'style',
            'src'      => awm_url . 'assets/css/admin/ewp-self-test.min.css',
            'version'  => AWM_ASSET_VERSION,
            'context'  => 'admin',
        ];

        $assets[] = [
            'handle'       => 'ewp-self-test-script',
            'selector'     => '.ewp-self-test',
            'type'         => 'script',
            'src'          => awm_url . 'build/modules/ewp-self-test.js',
            'version'      => AWM_ASSET_VERSION,
            'context'      => 'admin',
            'dependencies' => [],
            'in_footer'    => true,
            'defer'        => true,
            'localize'     => [
                'objectName' => 'ewpSelfTest',
                'data'       => [
                    'strings' => [
                        'running'      => __('Running…', 'extend-wp'),
                        'previewing'   => __('Loading preview…', 'extend-wp'),
                        'noSelection'  => __('Select at least one case.', 'extend-wp'),
                        'error'        => __('Request failed.', 'extend-wp'),
                        'pass'         => __('pass', 'extend-wp'),
                        'fail'         => __('fail', 'extend-wp'),
                        'skip'         => __('skipped', 'extend-wp'),
                        'error_status' => __('error', 'extend-wp'),
                        'unavailable'  => __('unavailable', 'extend-wp'),
                    ],
                ],
            ],
        ];

        return $assets;
    }
}
