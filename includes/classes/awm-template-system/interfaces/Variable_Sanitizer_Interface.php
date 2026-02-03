<?php

namespace EWP\TemplateSystem\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Variable Sanitizer Interface
 * 
 * Defines the contract for template variable sanitization services.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem\Interfaces
 */
interface Variable_Sanitizer_Interface
{
    /**
     * Sanitize variables for template rendering
     * 
     * @param array $variables Template variables
     * @param array $context Rendering context
     * @return array Sanitized variables
     */
    public function sanitize_variables($variables, $context);

    /**
     * Escape value for HTML output
     * 
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    public function escape_for_html($value);

    /**
     * Escape value for HTML attribute
     * 
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    public function escape_for_attribute($value);

    /**
     * Validate variable types against schema
     * 
     * @param array $variables Variables to validate
     * @param array $schema Variable schema
     * @return bool True if valid
     */
    public function validate_variable_types($variables, $schema);
}