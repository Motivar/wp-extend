<?php

namespace EWP\TemplateSystem\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Locator Interface
 * 
 * Defines the contract for template file location services.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem\Interfaces
 */
interface Template_Locator_Interface
{
    /**
     * Locate template file following hierarchy
     * 
     * @param string $template_name Template name
     * @return string|false Template file path or false if not found
     */
    public function locate_template($template_name);

    /**
     * Get template hierarchy for a template name
     * 
     * @param string $template_name Template name
     * @return array Array of template paths in order of priority
     */
    public function get_template_hierarchy($template_name);

    /**
     * Check if template is overridden by theme
     * 
     * @param string $template_name Template name
     * @return bool True if template is overridden
     */
    public function is_template_overridden($template_name);
}