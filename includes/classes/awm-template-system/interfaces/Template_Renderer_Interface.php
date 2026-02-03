<?php

namespace EWP\TemplateSystem\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Renderer Interface
 * 
 * Defines the contract for template rendering components.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem\Interfaces
 */
interface Template_Renderer_Interface
{
    /**
     * Render field using template system
     * 
     * @param array $field_config Field configuration array
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return string Rendered HTML output
     */
    public function render_field($field_config, $value, $context);
}