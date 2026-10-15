<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * The Extend WP admin look for options pages.
 *
 * Adds `ewp-admin-ui` to the body of every options page rendered by
 * templates/settings.php (registered through `awm_add_options_boxes_filter`
 * without an `ext_callback`) and loads assets/css/admin/ewp-admin-ui.min.css
 * there. Every rule in that stylesheet hangs off the body class, so meta
 * boxes, term and user screens, pages with their own markup (`ext_callback`)
 * and every other admin page keep their current look.
 *
 * A plugin can turn the look on for its own screens, or off for an options
 * page, with the `ewp_admin_ui_enabled` filter, and can restyle it by setting
 * the `--ewp-ui-*` custom properties on its screens (see the top of
 * ewp-admin-ui.scss).
 *
 * @since 1.5.4
 */
class EWP_Admin_UI
{
  const BODY_CLASS = 'ewp-admin-ui';
  const HANDLE = 'ewp-admin-ui';

  /** @var bool|null Cached result of is_enabled() for this request. */
  private static $enabled = null;

  public function __construct()
  {
    add_filter('admin_body_class', [$this, 'body_class']);
    // Registered early on every admin screen, so a plugin can list it as a
    // dependency of its own stylesheet from any priority.
    add_action('admin_enqueue_scripts', [$this, 'register'], 1);
    // After awm-admin-style (priority 10), so equal selectors resolve here.
    add_action('admin_enqueue_scripts', [$this, 'enqueue'], 20);
  }

  /**
   * Register the stylesheet (handle `ewp-admin-ui`).
   */
  public function register()
  {
    wp_register_style(self::HANDLE, awm_url . 'assets/css/admin/ewp-admin-ui.min.css', array('awm-admin-style'), AWM_ASSET_VERSION);
  }

  /**
   * Whether the current screen is an options page rendered by
   * templates/settings.php.
   *
   * @return bool
   */
  public static function is_options_page()
  {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only screen check.
    if ($page === '') {
      return false;
    }
    $options = is_array(AWM_Meta::$ewp_options) ? AWM_Meta::$ewp_options : array();
    if (!$options) {
      global $metas;
      if ($metas instanceof AWM_Meta) {
        $options = (array) $metas->options_boxes();
      }
    }
    if (!isset($options[$page]) || !is_array($options[$page])) {
      return false;
    }

    // A page with its own callback renders its own markup.
    return empty($options[$page]['ext_callback']);
  }

  /**
   * Whether the current screen gets the Extend WP admin look.
   *
   * @return bool
   */
  public static function is_enabled()
  {
    if (self::$enabled !== null) {
      return self::$enabled;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
      return false;
    }

    /**
     * Filter whether an admin screen gets the Extend WP admin look
     * (`body.ewp-admin-ui` and ewp-admin-ui.min.css).
     *
     * @param bool      $enabled True on options pages rendered by
     *                           templates/settings.php, false elsewhere.
     * @param WP_Screen $screen  The current admin screen.
     * @return bool
     *
     * @since 1.5.4
     */
    self::$enabled = (bool) apply_filters('ewp_admin_ui_enabled', self::is_options_page(), $screen);

    return self::$enabled;
  }

  /**
   * @param string $classes Space-separated admin body classes.
   * @return string
   */
  public function body_class($classes)
  {
    return self::is_enabled() ? $classes . ' ' . self::BODY_CLASS . ' ' : $classes;
  }

  /**
   * Load the stylesheet on enabled screens.
   */
  public function enqueue()
  {
    if (!self::is_enabled()) {
      return;
    }
    wp_enqueue_style(self::HANDLE);
  }
}

new EWP_Admin_UI();
