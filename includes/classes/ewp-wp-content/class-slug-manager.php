<?php

namespace EWP\WP_Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EWP Slug Manager
 * 
 * Caches all *_slug options in a transient to reduce database queries
 * during post type and taxonomy registration.
 * 
 * @package    EWP\WP_Content
 * @author     Motivar
 * @version    1.0.0
 * @since      1.0.0
 */
class Slug_Manager
{
    /**
     * Singleton instance
     * 
     * @var Slug_Manager|null
     */
    private static $instance = null;

    /**
     * Transient key for cached slugs
     * 
     * @var string
     */
    private const TRANSIENT_KEY = 'ewp_cached_slugs';

    /**
     * Cache expiration (30 days)
     * 
     * @var int
     */
    private const CACHE_EXPIRATION = MONTH_IN_SECONDS;

    /**
     * Cached slugs array
     * 
     * @var array|null
     */
    private static $cached_slugs = null;

    /**
     * Get singleton instance
     * 
     * @return Slug_Manager
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton pattern)
     */
    private function __construct()
    {
        add_action('update_option', [$this, 'invalidate_cache_on_slug_update'], 10, 3);
        add_action('add_option', [$this, 'invalidate_cache_on_slug_add'], 10, 2);
        add_action('ewp_flush_cache_action', [$this, 'flush_cache']);
    }

    /**
     * Get slug for a given key (post type or taxonomy)
     * 
     * @param string $key The option name without '_slug' suffix
     * @param string $default Default value if slug not found
     * @return string
     */
    public static function get_slug($key, $default = '')
    {
        $slugs = self::get_all_slugs();
        $option_name = $key . '_slug';
        
        return isset($slugs[$option_name]) ? $slugs[$option_name] : ($default ?: $key);
    }

    /**
     * Get all cached slugs
     * 
     * @return array
     */
    private static function get_all_slugs()
    {
        if (self::$cached_slugs !== null) {
            return self::$cached_slugs;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        
        if ($cached !== false) {
            self::$cached_slugs = $cached;
            return self::$cached_slugs;
        }

        self::$cached_slugs = self::load_slugs_from_db();
        set_transient(self::TRANSIENT_KEY, self::$cached_slugs, self::CACHE_EXPIRATION);
        
        return self::$cached_slugs;
    }

    /**
     * Load all *_slug options from database
     * 
     * @return array
     */
    private static function load_slugs_from_db()
    {
        global $wpdb;
        
        $pattern = $wpdb->esc_like('_slug');
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 AND option_name NOT LIKE %s",
                '%' . $pattern,
                '\\_' . $pattern
            ),
            ARRAY_A
        );
        
        $slugs = [];
        
        if (!empty($results)) {
            foreach ($results as $row) {
                $slugs[$row['option_name']] = $row['option_value'];
            }
        }
        
        return apply_filters('ewp_slug_manager_slugs', $slugs);
    }

    /**
     * Invalidate cache when a slug option is updated
     * 
     * @param string $option Option name
     * @param mixed $old_value Old value
     * @param mixed $new_value New value
     */
    public function invalidate_cache_on_slug_update($option, $old_value, $new_value)
    {
        if (strpos($option, '_slug') !== false) {
            $this->flush_cache();
        }
    }

    /**
     * Invalidate cache when a slug option is added
     * 
     * @param string $option Option name
     * @param mixed $value Option value
     */
    public function invalidate_cache_on_slug_add($option, $value)
    {
        if (strpos($option, '_slug') !== false) {
            $this->flush_cache();
        }
    }

    /**
     * Flush the slug cache
     * 
     * @return bool
     */
    public static function flush_cache()
    {
        self::$cached_slugs = null;
        return delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Manually refresh cache
     * 
     * @return array
     */
    public static function refresh_cache()
    {
        self::flush_cache();
        return self::get_all_slugs();
    }
}