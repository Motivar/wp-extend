<?php

namespace EWP\TemplateSystem;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Error Handler - Handles template system errors and logging
 * 
 * This class provides comprehensive error handling for the template system,
 * including logging, debugging, and graceful degradation.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Error_Handler
{
    /**
     * Handle template not found error
     * 
     * @param string $template_name Template name that was not found
     * @param array $search_paths Paths that were searched
     */
    public function handle_template_not_found($template_name, $search_paths)
    {
        $message = sprintf(
            'AWM Template not found: %s. Searched in: %s',
            $template_name,
            implode(', ', $search_paths)
        );
        
        // Log warning
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($message);
        }
        
        // Fire action hook for custom handling
        do_action('awm_template_not_found', $template_name, $search_paths);
        
        // Show debug information in development mode
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $this->show_debug_notice($message);
        }
    }

    /**
     * Handle template parse error
     * 
     * @param string $template_path Template file path
     * @param \Exception $error Exception that occurred
     */
    public function handle_template_parse_error($template_path, $error)
    {
        $message = sprintf(
            'AWM Template parse error in %s: %s',
            $template_path,
            $error->getMessage()
        );
        
        // Log error
        error_log($message);
        
        // Fire action hook for custom handling
        do_action('awm_template_parse_error', $template_path, $error);
        
        // Show detailed error in development mode
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $debug_message = $message . "\n" . $error->getTraceAsString();
            $this->show_debug_notice($debug_message);
        }
    }

    /**
     * Handle security violation
     * 
     * @param string $attempted_path Path that was attempted to be loaded
     */
    public function handle_security_violation($attempted_path)
    {
        $message = sprintf(
            'AWM Template security violation: attempted to load %s',
            $attempted_path
        );
        
        // Log security warning
        error_log($message);
        
        // Fire action hook for custom handling
        do_action('awm_template_security_violation', $attempted_path);
        
        // Show warning in development mode
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $this->show_debug_notice($message, 'error');
        }
    }

    /**
     * Handle variable validation error
     * 
     * @param array $invalid_variables Invalid variables
     * @param array $schema Expected schema
     */
    public function handle_variable_validation_error($invalid_variables, $schema)
    {
        $message = sprintf(
            'AWM Template variable validation failed. Invalid variables: %s',
            implode(', ', array_keys($invalid_variables))
        );
        
        // Log warning
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($message);
        }
        
        // Fire action hook for custom handling
        do_action('awm_template_variable_validation_error', $invalid_variables, $schema);
        
        // Show debug information in development mode
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $this->show_debug_notice($message, 'warning');
        }
    }

    /**
     * Show debug notice to administrators
     * 
     * @param string $message Debug message
     * @param string $type Notice type (notice, warning, error)
     */
    private function show_debug_notice($message, $type = 'notice')
    {
        $class = 'notice notice-' . $type;
        
        add_action('admin_notices', function() use ($message, $class) {
            printf(
                '<div class="%s"><p><strong>AWM Template Debug:</strong> %s</p></div>',
                esc_attr($class),
                esc_html($message)
            );
        });
    }
}