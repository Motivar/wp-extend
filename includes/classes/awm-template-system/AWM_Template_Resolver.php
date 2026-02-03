<?php

namespace EWP\TemplateSystem;

use EWP\TemplateSystem\Interfaces\Template_Renderer_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Resolver - Main orchestrator for template-based rendering
 * 
 * This class serves as the main entry point for the template system,
 * coordinating between template location, variable preparation, and rendering.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Resolver implements Template_Renderer_Interface
{
    /**
     * Template locator instance
     * 
     * @var AWM_Template_Locator
     */
    private $locator;

    /**
     * Variable sanitizer instance
     * 
     * @var AWM_Variable_Sanitizer
     */
    private $sanitizer;

    /**
     * Template parser instance
     * 
     * @var AWM_Template_Parser
     */
    private $parser;

    /**
     * Template cache instance
     * 
     * @var AWM_Template_Cache
     */
    private $cache;

    /**
     * Error handler instance
     * 
     * @var AWM_Template_Error_Handler
     */
    private $error_handler;

    /**
     * Whether fallback mode is enabled
     * 
     * @var bool
     */
    private $fallback_mode = false;

    /**
     * Constructor
     * 
     * @param AWM_Template_Locator|null $locator Template locator instance
     * @param AWM_Variable_Sanitizer|null $sanitizer Variable sanitizer instance
     * @param AWM_Template_Parser|null $parser Template parser instance
     * @param AWM_Template_Cache|null $cache Template cache instance
     * @param AWM_Template_Error_Handler|null $error_handler Error handler instance
     */
    public function __construct(
        ?AWM_Template_Locator $locator = null,
        ?AWM_Variable_Sanitizer $sanitizer = null,
        ?AWM_Template_Parser $parser = null,
        ?AWM_Template_Cache $cache = null,
        ?AWM_Template_Error_Handler $error_handler = null
    ) {
        $this->locator = $locator ?: new AWM_Template_Locator();
        $this->sanitizer = $sanitizer ?: new AWM_Variable_Sanitizer();
        $this->parser = $parser ?: new AWM_Template_Parser();
        $this->cache = $cache ?: new AWM_Template_Cache();
        $this->error_handler = $error_handler ?: new AWM_Template_Error_Handler();
    }

    /**
     * Main entry point for template rendering
     * 
     * @param array $field_config Field configuration array
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return string Rendered HTML output
     */
    public function render_field($field_config, $value, $context)
    {
        try {
            // Get template path for this input type
            $template_path = $this->get_template_path($field_config['case'], $field_config['type'] ?? null);
            
            if (!$template_path) {
                // Template not found, use fallback if enabled
                if ($this->fallback_mode) {
                    return $this->render_fallback($field_config, $value, $context);
                }
                
                $this->error_handler->handle_template_not_found(
                    $this->get_template_name($field_config['case'], $field_config['type'] ?? null),
                    $this->locator->get_template_hierarchy($this->get_template_name($field_config['case'], $field_config['type'] ?? null))
                );
                
                return '';
            }

            // Prepare and sanitize variables
            $variables = $this->prepare_template_variables($field_config, $value, $context);
            $sanitized_variables = $this->sanitizer->sanitize_variables($variables, $context);

            // Parse template with variables
            return $this->parser->parse_template($template_path, $sanitized_variables);

        } catch (\Exception $e) {
            $this->error_handler->handle_template_parse_error($template_path ?? '', $e);
            
            if ($this->fallback_mode) {
                return $this->render_fallback($field_config, $value, $context);
            }
            
            return '';
        }
    }

    /**
     * Get template path for input type
     * 
     * @param string $input_type Main input type (input, select, textarea, etc.)
     * @param string|null $subtype Input subtype (text, number, checkbox, etc.)
     * @return string|false Template file path or false if not found
     */
    public function get_template_path($input_type, $subtype = null)
    {
        $template_name = $this->get_template_name($input_type, $subtype);
        
        // Check cache first
        $cached_path = $this->cache->get_cached_path($template_name);
        if ($cached_path !== false) {
            return $cached_path;
        }

        // Locate template file
        $template_path = $this->locator->locate_template($template_name);
        
        if ($template_path) {
            // Cache the found path
            $this->cache->cache_path($template_name, $template_path);
        }

        return $template_path;
    }

    /**
     * Enable or disable fallback mode
     * 
     * @param bool $enabled Whether to enable fallback mode
     */
    public function set_fallback_mode($enabled)
    {
        $this->fallback_mode = (bool) $enabled;
    }

    /**
     * Get fallback mode status
     * 
     * @return bool Whether fallback mode is enabled
     */
    public function get_fallback_mode()
    {
        return $this->fallback_mode;
    }

    /**
     * Clear template cache
     */
    public function clear_cache()
    {
        $this->cache->invalidate_cache();
    }

    /**
     * Get template name for input type and subtype
     * 
     * @param string $input_type Main input type
     * @param string|null $subtype Input subtype
     * @return string Template name
     */
    private function get_template_name($input_type, $subtype = null)
    {
        if ($input_type === 'input' && $subtype) {
            return "input/{$subtype}.php";
        }
        
        return "{$input_type}.php";
    }

    /**
     * Prepare template variables from field configuration
     * 
     * @param array $field_config Field configuration
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return array Prepared variables
     */
    private function prepare_template_variables($field_config, $value, $context)
    {
        $preparer = new AWM_Template_Variable_Preparer();
        return $preparer->prepare_variables($field_config, $value, $context);
    }

    /**
     * Render using fallback (legacy) method
     * 
     * @param array $field_config Field configuration
     * @param mixed $value Field value
     * @param array $context Rendering context
     * @return string Rendered HTML
     */
    private function render_fallback($field_config, $value, $context)
    {
        // This would call the original awm_show_content logic
        // For now, return empty string as placeholder
        return '';
    }
}