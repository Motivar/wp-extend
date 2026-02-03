<?php

namespace EWP\TemplateSystem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template System - Main initialization and coordination class
 * 
 * This class initializes the template system and provides a unified
 * interface for template-based rendering.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_System
{
    /**
     * Template resolver instance
     * 
     * @var AWM_Template_Resolver
     */
    private static $resolver;

    /**
     * Whether the template system is initialized
     * 
     * @var bool
     */
    private static $initialized = false;

    /**
     * Initialize the template system
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        // Initialize components
        self::$resolver = new AWM_Template_Resolver();

        // Set up hooks
        self::setup_hooks();

        self::$initialized = true;
    }

    /**
     * Get template resolver instance
     * 
     * @return AWM_Template_Resolver
     */
    public static function get_resolver()
    {
        if (!self::$initialized) {
            self::init();
        }

        return self::$resolver;
    }

    /**
     * Render field using template system
     * 
     * @param array $field_config Field configuration
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return string Rendered HTML
     */
    public static function render_field($field_config, $value, $context)
    {
        return self::get_resolver()->render_field($field_config, $value, $context);
    }

    /**
     * Check if template system is available for field type
     * 
     * @param string $input_type Input type
     * @param string|null $subtype Input subtype
     * @return bool True if template is available
     */
    public static function has_template($input_type, $subtype = null)
    {
        $resolver = self::get_resolver();
        return $resolver->get_template_path($input_type, $subtype) !== false;
    }

    /**
     * Enable fallback mode for gradual rollout
     * 
     * @param bool $enabled Whether to enable fallback mode
     */
    public static function set_fallback_mode($enabled)
    {
        self::get_resolver()->set_fallback_mode($enabled);
    }

    /**
     * Clear template cache
     */
    public static function clear_cache()
    {
        self::get_resolver()->clear_cache();
    }

    /**
     * Set up WordPress hooks
     */
    private static function setup_hooks()
    {
        // Clear cache when theme is switched
        add_action('switch_theme', array(__CLASS__, 'clear_cache'));

        // Clear cache when plugin is updated
        add_action('upgrader_process_complete', array(__CLASS__, 'on_plugin_update'), 10, 2);

        // Add template system status to admin
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'admin_init'));
        }
    }

    /**
     * Handle plugin update
     * 
     * @param \WP_Upgrader $upgrader Upgrader instance
     * @param array $hook_extra Extra data
     */
    public static function on_plugin_update($upgrader, $hook_extra)
    {
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === plugin_basename(awm_path . 'extend-wp.php')) {
            self::clear_cache();
        }
    }

    /**
     * Admin initialization
     */
    public static function admin_init()
    {
        // Add template system information to admin
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            add_action('admin_notices', array(__CLASS__, 'show_debug_info'));
        }
    }

    /**
     * Show debug information in admin
     */
    public static function show_debug_info()
    {
        if (!isset($_GET['awm_template_debug'])) {
            return;
        }

        $resolver = self::get_resolver();
        $locator = new AWM_Template_Locator();
        
        echo '<div class="notice notice-info">';
        echo '<h3>AWM Template System Debug</h3>';
        echo '<p><strong>Status:</strong> ' . (self::$initialized ? 'Initialized' : 'Not Initialized') . '</p>';
        echo '<p><strong>Fallback Mode:</strong> ' . ($resolver->get_fallback_mode() ? 'Enabled' : 'Disabled') . '</p>';
        echo '</div>';
    }
}