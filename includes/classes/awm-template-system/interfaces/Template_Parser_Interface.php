<?php

namespace EWP\TemplateSystem\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Parser Interface
 * 
 * Defines the contract for template parsing services.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem\Interfaces
 */
interface Template_Parser_Interface
{
    /**
     * Parse template file with variables
     * 
     * @param string $template_path Template file path
     * @param array $variables Template variables
     * @return string Rendered template content
     */
    public function parse_template($template_path, $variables);

    /**
     * Validate template file
     * 
     * @param string $template_path Template file path
     * @return bool True if valid
     */
    public function validate_template($template_path);
}