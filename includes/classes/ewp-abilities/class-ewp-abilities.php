<?php

namespace EWP\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/ewp-content/class-content-service.php';
require_once __DIR__ . '/class-ewp-abilities-audit.php';

/**
 * Bootstraps the Extend WP integration with the WordPress Abilities API.
 *
 * Registers the plugin's own functionality — custom fields, custom content
 * rows, UI-registered post types and taxonomies, search filters, options
 * portability, cache maintenance and log writing — as schema-described
 * abilities so any consumer of the core Abilities API (the core AI client,
 * the MCP adapter, the `ai` plugin) can use them without new transport code.
 *
 * Nothing is loaded or hooked unless the running WordPress version actually
 * supports the Abilities API; see self::is_supported().
 *
 * @package    EWP\Abilities
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Abilities
{
    /**
     * Minimum WordPress version that ships the Abilities API.
     *
     * @var string
     */
    const MIN_WP_VERSION = '6.9';

    /**
     * Log action type recorded for every write ability execution.
     *
     * @var string
     */
    const LOG_ACTION_TYPE = 'ability_write';

    /**
     * Log owner used for ability auditing.
     *
     * @var string
     */
    const LOG_OWNER = 'extend-wp';

    /**
     * Singleton instance.
     *
     * @var EWP_Abilities|null
     */
    private static $instance = null;

    /**
     * Memoised support check result.
     *
     * @var bool|null
     */
    private static $supported = null;

    /**
     * Reason the Abilities API is unavailable, when it is.
     *
     * @var string
     */
    private static $unsupported_reason = '';

    /**
     * Shared content service.
     *
     * @var EWP_Abilities_Content_Service|null
     */
    private $service = null;

    /**
     * Return the singleton instance.
     *
     * @return EWP_Abilities
     *
     * @since 1.4.0
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Whether the running WordPress supports the Abilities API.
     *
     * Checks the core version first so the reason we report to the admin is
     * accurate, then the actual API surface, so a site on 6.9+ where the API
     * has been removed or disabled is still handled gracefully. The version
     * and API checks are memoised; the filter is evaluated on every call so a
     * plugin loading after Extend WP can still opt out.
     *
     * @return bool True when abilities can be registered.
     *
     * @since 1.4.0
     */
    public static function is_supported()
    {
        $supported = self::has_api_support();

        /**
         * Filter whether Extend WP registers abilities with the core API.
         *
         * Returning false disables every Extend WP ability.
         *
         * @param bool   $supported  Whether the Abilities API is usable.
         * @param string $wp_version The running WordPress version.
         *
         * @since 1.4.0
         */
        return (bool) apply_filters('ewp_abilities_supported', $supported, get_bloginfo('version'));
    }

    /**
     * Whether core itself provides a usable Abilities API.
     *
     * Unfiltered and memoised: this is the check that decides whether the
     * module wires itself up at all.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    public static function has_api_support()
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        $wp_version = get_bloginfo('version');

        if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            self::$supported          = false;
            self::$unsupported_reason = sprintf(
                /* translators: 1: required WordPress version, 2: running WordPress version. */
                __('Extend WP abilities require WordPress %1$s or newer (this site runs %2$s).', 'extend-wp'),
                self::MIN_WP_VERSION,
                $wp_version
            );

            return self::$supported;
        }

        if (!self::api_functions_exist()) {
            self::$supported          = false;
            self::$unsupported_reason = __('The WordPress Abilities API is not available on this site, so Extend WP abilities were not registered.', 'extend-wp');

            return self::$supported;
        }

        self::$supported          = true;
        self::$unsupported_reason = '';

        return self::$supported;
    }

    /**
     * Whether every Abilities API function this module needs exists.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    private static function api_functions_exist()
    {
        return function_exists('wp_register_ability')
            && function_exists('wp_register_ability_category')
            && function_exists('wp_has_ability_category')
            && class_exists('WP_Abilities_Registry');
    }

    /**
     * Human readable reason abilities are unavailable.
     *
     * @return string Empty string when abilities are supported.
     *
     * @since 1.4.0
     */
    public static function get_unsupported_reason()
    {
        self::has_api_support();

        return self::$unsupported_reason;
    }

    /**
     * Whether ability registration is switched on.
     *
     * Separate from the support check so a site can keep the abilities off
     * without pretending the API is missing.
     *
     * @return bool
     *
     * @since 1.4.0
     */
    public static function is_enabled()
    {
        if (!self::is_supported()) {
            return false;
        }

        /**
         * Filter whether the Extend WP abilities are registered.
         *
         * @param bool $enabled Default true.
         *
         * @since 1.4.0
         */
        return (bool) apply_filters('ewp_abilities_enabled', true);
    }

    /**
     * Register hooks.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function init()
    {
        if (!self::has_api_support()) {
            add_action('admin_notices', [$this, 'render_unsupported_notice'], 20);
            return;
        }

        $this->service = new EWP_Abilities_Content_Service();

        $audit = new EWP_Abilities_Audit();
        $audit->init();

        add_action('ewp_logger_initialized', [$this, 'register_log_type']);
    }

    /**
     * Return the shared content service.
     *
     * @return EWP_Abilities_Content_Service|null
     *
     * @since 1.4.0
     */
    public function get_service()
    {
        return $this->service;
    }

    /**
     * Register the audit log action type with the logger.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function register_log_type()
    {
        if (!function_exists('ewp_register_log_type')) {
            return;
        }

        ewp_register_log_type(
            self::LOG_OWNER,
            self::LOG_ACTION_TYPE,
            __('Ability write', 'extend-wp'),
            __('A write ability was executed through the WordPress Abilities API.', 'extend-wp')
        );
    }

    /**
     * Tell administrators why no Extend WP abilities are registered.
     *
     * @return void
     *
     * @since 1.4.0
     */
    public function render_unsupported_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reason = self::get_unsupported_reason();
        if ($reason === '') {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html($reason)
        );
    }
}

EWP_Abilities::instance()->init();
