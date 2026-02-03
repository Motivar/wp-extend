<?php

namespace EWP\TemplateSystem\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Cache Interface
 * 
 * Defines the contract for template caching services.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem\Interfaces
 */
interface Template_Cache_Interface
{
    /**
     * Get cached template path
     * 
     * @param string $template_name Template name
     * @return string|false Cached path or false if not cached
     */
    public function get_cached_path($template_name);

    /**
     * Cache template path
     * 
     * @param string $template_name Template name
     * @param string $path Template file path
     */
    public function cache_path($template_name, $path);

    /**
     * Invalidate cache for template or all templates
     * 
     * @param string|null $template_name Template name or null for all
     */
    public function invalidate_cache($template_name = null);

    /**
     * Check if cache is valid
     * 
     * @param string $template_name Template name
     * @return bool True if cache is valid
     */
    public function is_cache_valid($template_name);
}