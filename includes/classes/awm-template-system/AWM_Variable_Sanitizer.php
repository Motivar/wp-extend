<?php

namespace EWP\TemplateSystem;

use EWP\TemplateSystem\Interfaces\Variable_Sanitizer_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Variable Sanitizer - Sanitizes and escapes template variables for security
 * 
 * This class handles the sanitization and escaping of template variables
 * to prevent XSS attacks and ensure secure output.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Variable_Sanitizer implements Variable_Sanitizer_Interface
{
    /**
     * Default escaping contexts for different variable types
     */
    const ESCAPE_CONTEXTS = array(
        'html' => 'esc_html',
        'attr' => 'esc_attr',
        'url' => 'esc_url',
        'js' => 'esc_js',
        'textarea' => 'esc_textarea',
        'raw' => null, // No escaping
    );

    /**
     * Sanitize variables for template rendering
     * 
     * @param array $variables Template variables
     * @param array $context Rendering context
     * @return array Sanitized variables
     */
    public function sanitize_variables($variables, $context)
    {
        $sanitized = array();
        
        foreach ($variables as $key => $value) {
            $sanitized[$key] = $this->sanitize_variable($key, $value, $context);
        }
        
        /**
         * Filter sanitized template variables
         * 
         * @param array $sanitized Sanitized variables
         * @param array $variables Original variables
         * @param array $context Rendering context
         */
        return apply_filters('awm_template_sanitized_variables', $sanitized, $variables, $context);
    }

    /**
     * Escape value for HTML output
     * 
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    public function escape_for_html($value)
    {
        if (is_array($value)) {
            return array_map(array($this, 'escape_for_html'), $value);
        }
        
        return esc_html($value);
    }

    /**
     * Escape value for HTML attribute
     * 
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    public function escape_for_attribute($value)
    {
        if (is_array($value)) {
            return array_map(array($this, 'escape_for_attribute'), $value);
        }
        
        return esc_attr($value);
    }

    /**
     * Validate variable types against schema
     * 
     * @param array $variables Variables to validate
     * @param array $schema Variable schema
     * @return bool True if valid
     */
    public function validate_variable_types($variables, $schema)
    {
        foreach ($schema as $key => $expected_type) {
            if (!isset($variables[$key])) {
                continue;
            }
            
            $actual_type = gettype($variables[$key]);
            
            if ($actual_type !== $expected_type && !$this->is_compatible_type($actual_type, $expected_type)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Sanitize individual variable
     * 
     * @param string $key Variable key
     * @param mixed $value Variable value
     * @param array $context Rendering context
     * @return mixed Sanitized value
     */
    private function sanitize_variable($key, $value, $context)
    {
        // Get escaping context for this variable
        $escape_context = $this->get_escape_context($key, $context);
        
        // Apply escaping based on context
        switch ($escape_context) {
            case 'html':
                return $this->escape_for_html($value);
                
            case 'attr':
                return $this->escape_for_attribute($value);
                
            case 'url':
                return is_array($value) ? array_map('esc_url', $value) : esc_url($value);
                
            case 'js':
                return is_array($value) ? array_map('esc_js', $value) : esc_js($value);
                
            case 'textarea':
                return is_array($value) ? array_map('esc_textarea', $value) : esc_textarea($value);
                
            case 'raw':
            default:
                // No escaping for raw content or unknown contexts
                return $value;
        }
    }

    /**
     * Get escaping context for variable
     * 
     * @param string $key Variable key
     * @param array $context Rendering context
     * @return string Escaping context
     */
    private function get_escape_context($key, $context)
    {
        // Define default contexts for common variables
        $default_contexts = array(
            'input_name' => 'attr',
            'input_id' => 'attr',
            'attributes_string' => 'raw', // Already escaped
            'label_html' => 'raw', // Already escaped
            'explanation_html' => 'raw', // Already escaped
            'value' => 'attr', // Default for input values
            'css_classes' => 'attr',
        );
        
        // Check for custom context in rendering context
        if (isset($context['escape_contexts'][$key])) {
            return $context['escape_contexts'][$key];
        }
        
        // Use default context if available
        if (isset($default_contexts[$key])) {
            return $default_contexts[$key];
        }
        
        // Default to HTML escaping
        return 'html';
    }

    /**
     * Check if types are compatible
     * 
     * @param string $actual_type Actual variable type
     * @param string $expected_type Expected variable type
     * @return bool True if compatible
     */
    private function is_compatible_type($actual_type, $expected_type)
    {
        $compatible_types = array(
            'string' => array('integer', 'double', 'boolean'),
            'array' => array('object'),
            'object' => array('array'),
        );
        
        if (isset($compatible_types[$expected_type])) {
            return in_array($actual_type, $compatible_types[$expected_type]);
        }
        
        return false;
    }
}