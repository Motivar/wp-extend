<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the old "REST API Health" admin URL after the module moved to the
 * standalone "MammothWP REST Health" plugin.
 *
 * Requests for `admin.php?page=ewp-rest-health` are redirected to the new
 * screen when that plugin is active, otherwise to the plugins list with a
 * one-request notice. This class never touches the data the new plugin
 * imports (user meta `ewp_rh_preferences`, `uploads/ewp-rest-health/`).
 *
 * @since 1.14.0
 */
class EWP_REST_Health_Moved
{
    /** Old admin page slug. */
    const OLD_PAGE = 'ewp-rest-health';

    /** Query arg that triggers the notice on the plugins screen. */
    const NOTICE_ARG = 'ewp_rest_health_moved';

    /** Main file of the standalone plugin, relative to the plugins directory. */
    const PLUGIN_FILE = 'mammoth-rest-health/mammoth-rest-health.php';

    /** Screen of the standalone plugin, relative to wp-admin. */
    const NEW_SCREEN = 'tools.php?page=mmwp-rh';

    /**
     * Callable returning whether the standalone plugin is active.
     *
     * @var callable
     */
    private $detector;

    /**
     * Create the handler.
     *
     * @param callable|null $detector Optional `function (): bool` replacing the default detection (used by tests).
     *
     * @since 1.14.0
     */
    public function __construct($detector = null)
    {
        $this->detector = $detector ?: [__CLASS__, 'is_standalone_active'];
    }

    /**
     * Attach the admin hooks.
     *
     * @return void
     *
     * @since 1.14.0
     */
    public function init()
    {
        /*wp-admin/menu.php wp_die()s for an unknown page before admin_init runs; this action fires just before that*/
        add_action('admin_page_access_denied', [$this, 'maybe_redirect']);
        add_action('admin_notices', [$this, 'render_notice']);
    }

    /**
     * Whether the MammothWP REST Health plugin is active.
     *
     * @return bool
     *
     * @since 1.14.0
     */
    public static function is_standalone_active()
    {
        if (class_exists('MammothWP\RestHealth\Plugin', false)) {
            return true;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active(self::PLUGIN_FILE);
    }

    /**
     * Where a request for the old page should be sent.
     *
     * @param bool $standalone_active Whether the standalone plugin is active.
     *
     * @return string Absolute admin URL.
     *
     * @since 1.14.0
     */
    public static function redirect_url($standalone_active)
    {
        if ($standalone_active) {
            return admin_url(self::NEW_SCREEN);
        }
        return add_query_arg(self::NOTICE_ARG, '1', admin_url('plugins.php'));
    }

    /**
     * Redirect the old page request, then exit.
     *
     * @return void
     *
     * @since 1.14.0
     */
    public function maybe_redirect()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on a page slug.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::OLD_PAGE) {
            return;
        }
        wp_safe_redirect(self::redirect_url((bool) call_user_func($this->detector)));
        exit;
    }

    /**
     * Notice markup, escaped.
     *
     * @return string
     *
     * @since 1.14.0
     */
    public static function notice_html()
    {
        return sprintf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html__('REST API Health moved to the "MammothWP REST Health" plugin. Install and activate it to keep using the REST API Health screen.', 'extend-wp')
        );
    }

    /**
     * Print the notice after the redirect.
     *
     * @return void
     *
     * @since 1.14.0
     */
    public function render_notice()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
        if (empty($_GET[self::NOTICE_ARG]) || !current_user_can('activate_plugins')) {
            return;
        }
        echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
    }
}
