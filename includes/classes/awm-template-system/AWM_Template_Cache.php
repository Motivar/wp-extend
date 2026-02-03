<?php

namespace EWP\TemplateSystem;

use EWP\TemplateSystem\Interfaces\Template_Cache_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Cache - Caches template file paths and metadata
 * 
 * This class handles caching of template file paths to avoid
 * repeated filesystem operations and improve performance.
 * 
 * @since 1.1.3
 * @package EWP\TemplateSystem
 */
class AWM_Template_Cache implements Template_Cache_Interface
{
    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'awm_template_';

    /**
     * Cache expiration time (1 hour)
     */
    const CACHE_EXPIRATION = 3600;

    /**
     * Memory cache for current request
     * 
     * @var array
     */
    private $memory_cache = array();

    /**
     * Get cached template path
     * 
     * @param string $template_name Template name
     * @return string|false Cached path or false if not cached
     */
    public function get_cached_path($template_name)
    {
        // Check memory cache first
        if (isset($this->memory_cache[$template_name])) {
            return $this->memory_cache[$template_name];
        }
        
        // Check persistent cache
        $cache_key = $this->get_cache_key($template_name);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false && $this->is_cache_valid($template_name, $cached_data)) {
            // Store in memory cache for this request
            $this->memory_cache[$template_name] = $cached_data['path'];
            return $cached_data['path'];
        }
        
        return false;
    }

    /**
     * Cache template path
     * 
     * @param string $template_name Template name
     * @param string $path Template file path
     */
    public function cache_path($template_name, $path)
    {
        // Store in memory cache
        $this->memory_cache[$template_name] = $path;
        
        // Skip persistent caching in development mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return;
        }
        
        // Store in persistent cache
        $cache_key = $this->get_cache_key($template_name);
        $cache_data = array(
            'path' => $path,
            'timestamp' => time(),
            'theme' => get_stylesheet(),
            'file_mtime' => file_exists($path) ? filemtime($path) : 0,
        );
        
        set_transient($cache_key, $cache_data, self::CACHE_EXPIRATION);
    }

    /**
     * Invalidate cache for template or all templates
     * 
     * @param string|null $template_name Template name or null for all
     */
    public function invalidate_cache($template_name = null)
    {
        if ($template_name) {
            // Invalidate specific template
            unset($this->memory_cache[$template_name]);
            delete_transient($this->get_cache_key($template_name));
        } else {
            // Invalidate all template caches
            $this->memory_cache = array();
            $this->clear_all_transients();
        }
    }

    /**
     * Check if cache is valid
     * 
     * @param string $template_name Template name
     * @param array|null $cached_data Cached data or null to fetch
     * @return bool True if cache is valid
     */
    public function is_cache_valid($template_name, $cached_data = null)
    {
        if ($cached_data === null) {
            $cache_key = $this->get_cache_key($template_name);
            $cached_data = get_transient($cache_key);
        }
        
        if ($cached_data === false) {
            return false;
        }
        
        // Check if theme changed
        if ($cached_data['theme'] !== get_stylesheet()) {
            return false;
        }
        
        // Check if file was modified
        if (file_exists($cached_data['path'])) {
            $current_mtime = filemtime($cached_data['path']);
            if ($current_mtime !== $cached_data['file_mtime']) {
                return false;
            }
        } else {
            // File no longer exists
            return false;
        }
        
        return true;
    }

    /**
     * Get cache key for template
     * 
     * @param string $template_name Template name
     * @return string Cache key
     */
    private function get_cache_key($template_name)
    {
        return self::CACHE_PREFIX . md5($template_name . get_stylesheet());
    }

    /**
     * Clear all template transients
     */
    private function clear_all_transients()
    {
        global $wpdb;
        
        // Only clear transients if we're in a WordPress environment
        if (!$wpdb || !($wpdb instanceof wpdb)) {
            return;
        }
        
        // Delete all transients with our prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%',
                '_transient_timeout_' . self::CACHE_PREFIX . '%'
            )
        );
    }
}