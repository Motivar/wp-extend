<?php

namespace EWP\TemplateSystem;

use EWP\TemplateSystem\Interfaces\Template_Locator_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Locator - Locates template files following WordPress hierarchy
 * 
 * This class handles the template hierarchy resolution, checking child theme,
 * parent theme, and core plugin locations in that order.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Locator implements Template_Locator_Interface
{
    /**
     * Template directory path within themes and plugin
     */
    const TEMPLATE_DIR = 'extend-wp/templates/frontend/inputs/';

    /**
     * Core plugin template directory
     */
    const CORE_TEMPLATE_DIR = 'templates/frontend/inputs/';

    /**
     * Locate template file following WordPress hierarchy
     * 
     * @param string $template_name Template name (e.g., 'input/text.php', 'select.php')
     * @return string|false Template file path or false if not found
     */
    public function locate_template($template_name)
    {
        $hierarchy = $this->get_template_hierarchy($template_name);
        
        foreach ($hierarchy as $template_path) {
            if (file_exists($template_path)) {
                return $template_path;
            }
        }
        
        return false;
    }

    /**
     * Get template hierarchy for a template name
     * 
     * @param string $template_name Template name
     * @return array Array of template paths in order of priority
     */
    public function get_template_hierarchy($template_name)
    {
        $hierarchy = array();
        
        // 1. Child theme override
        if (is_child_theme()) {
            $hierarchy[] = get_stylesheet_directory() . '/' . self::TEMPLATE_DIR . $template_name;
        }
        
        // 2. Parent theme override
        $hierarchy[] = get_template_directory() . '/' . self::TEMPLATE_DIR . $template_name;
        
        // 3. Core plugin template
        $hierarchy[] = $this->get_plugin_path() . self::CORE_TEMPLATE_DIR . $template_name;
        
        /**
         * Filter template hierarchy
         * 
         * @param array $hierarchy Template file paths in order of priority
         * @param string $template_name Template name
         */
        return apply_filters('awm_template_hierarchy', $hierarchy, $template_name);
    }

    /**
     * Check if template is overridden by theme
     * 
     * @param string $template_name Template name
     * @return bool True if template is overridden
     */
    public function is_template_overridden($template_name)
    {
        $hierarchy = $this->get_template_hierarchy($template_name);
        $core_template = end($hierarchy); // Last item is core template
        
        foreach (array_slice($hierarchy, 0, -1) as $template_path) {
            if (file_exists($template_path)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get template version from template file header
     * 
     * @param string $template_path Template file path
     * @return string|null Template version or null if not found
     */
    public function get_template_version($template_path)
    {
        if (!file_exists($template_path)) {
            return null;
        }
        
        $file_data = get_file_data($template_path, array('version' => 'Version'));
        
        return !empty($file_data['version']) ? $file_data['version'] : null;
    }

    /**
     * Get plugin directory path
     * 
     * @return string Plugin directory path
     */
    private function get_plugin_path()
    {
        return defined('awm_path') ? awm_path : plugin_dir_path(__FILE__) . '../../../';
    }
}