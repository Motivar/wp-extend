<?php
// Block direct access to the file for security.
if (!defined('ABSPATH')) {
 exit;
}

/**
 * EWP_Recently_Seen_UTIL class manages the Recently Seen functionality
 * Tracks and stores recently viewed posts/pages in user sessions
 * Provides REST API endpoints for updating recently seen items
 * Handles script registration and enqueuing for the feature
 */
class EWP_Recently_Seen_UTIL
{
  /**
   * Static property to store development settings
   * Contains configuration for the Recently Seen functionality
   * Retrieved from 'ewp_dev_settings' option in WordPress
   * 
   * @var array
   */
  private static $post_types = array();
  

 /**
  * Constructor - initializes the Recently Seen functionality
  * Sets up WordPress hooks for scripts, REST API, and session management
  */
 public function __construct()
 {
        // Initialize dev settings from WordPress options
        self::$post_types= $this->post_types();
        
        // Register scripts early in the WordPress init process
        add_action('init', array($this, 'registerScripts'), 0);
        // Add frontend scripts when appropriate
        add_action('wp_enqueue_scripts', array($this, 'addScripts'), 10);
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'rest_endpoints'], 10);
        // Initialize session handling early in the WordPress init process
        add_action('init', [$this, 'init_session'],1);
 }
 public function post_types()
 {
    
    $options= get_option('ewp_dev_settings') ?: array();
    $post_types=$options['recently_seen'] ?? array();
    return apply_filters('ewp_recently_seen_post_types_filter', $post_types);
 }
    /**
     * Initializes PHP session if not already started
     * Only runs if recently_seen settings are configured
     * Sessions are used to store user's recently viewed items
     */
    public function init_session()
    { // Check if recently_seen has values in dev settings
        if (empty(self::$post_types)) {
            return;
        }
        try {
            if (!session_id()) {
                session_start();
            }
        } catch (Exception $e) {
            error_log('Session initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Registers REST API endpoints for the Recently Seen functionality
     * Creates a POST endpoint at /wp-json/ewp/v1/recently-seen/{id}
     * Only registers if recently_seen settings are configured
     */
    public function rest_endpoints()
    { 
        try {
            // Check if recently_seen has values in dev settings
            if (empty(self::$post_types)) {
                return;
            }
            
            // Register the REST API endpoint for updating recently seen items
            register_rest_route('ewp/v1', '/recently-seen/(?P<id>\d+)', array(
                'methods' => 'POST',
                'callback' => [$this, 'recently_seen'],
                'args' => array(
                    'id' => array(
                        'description'       => __('The id of the post', 'extend-wp'),
                        'validate_callback' => function($param) { return is_numeric($param); },
                        'sanitize_callback' => 'absint',
                        'required' => true
                    )
                ),
                'permission_callback' => '__return_true'
            ));
        } catch (Exception $e) {
            error_log('REST endpoint registration error: ' . $e->getMessage());
        }
    }

    /**
     * REST API callback for the recently-seen endpoint
     * Processes the POST request and updates the recently seen items
     * 
     * @param WP_REST_Request $request The REST request object containing the post ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function recently_seen($request)
    {
        try {
            $id = absint($request['id']);
            $post_type = get_post_type($id);
            $this->update_recently_seen($id, $post_type);
            return true;
        } catch (Exception $e) {
            error_log('Recently seen processing error: ' . $e->getMessage());
            return new WP_Error('recently_seen_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * Updates the recently seen items in the user's session
     * Organizes items by post type and prevents duplicates
     * 
     * @param int $id The post ID to add to recently seen
     * @param string $post_type The post type of the content
     */
    public function update_recently_seen($id, $post_type)
    {
        try {
          
            // Get existing recently seen data or initialize empty array
            $recently_seen = isset($_SESSION['ewp_recently_seen']) ? $_SESSION['ewp_recently_seen'] : array();
            
            // Initialize array for this post type if it doesn't exist
            if (!isset($recently_seen[$post_type])) {
                $recently_seen[$post_type] = array();
            }
            
            // Skip if this ID is already in the recently seen list for this post type
            if (in_array($id, $recently_seen[$post_type])) {
                return;
            }
            
            // Add the ID to the recently seen list for this post type
            $recently_seen[$post_type][] = $id;
            
            // Update the session variable
            $_SESSION['ewp_recently_seen'] = $recently_seen;
        
        } catch (Exception $e) {
            error_log('Update recently seen error: ' . $e->getMessage());
        }
    }

    /**
     * Registers JavaScript files needed for the Recently Seen functionality
     * Registers scripts but does not enqueue them (enqueuing happens in addScripts)
     * 
     * @see addScripts() For script enqueuing logic
     */
    public function registerScripts()
    {
        $version = 0.02;
        wp_register_script('ewp-recently-seen', awm_url . 'assets/js/public/ewp-recently.js', array(), $version, true);
    }
    /**
     * Conditionally enqueues scripts for the Recently Seen functionality
     * Only enqueues the script if:
     * 1. recently_seen settings exist in dev_settings
     * 2. We're on a single view of a post type
     * 3. The current post type is configured in recently_seen settings
     * 
     * Also passes necessary data to JavaScript via wp_localize_script
     */
    public function addScripts()
    {
        // Check if recently_seen has values in dev settings
        if (empty(self::$post_types)) {
            return;
        }
        
        // Check if we are on a single post view
        if (!is_singular()) {
            return;
        }

        // Get current post type
        $current_post_type = get_post_type();

        // Check if current post type is in the recently_seen configuration
        if (in_array($current_post_type, self::$post_types)) {
            // Enqueue the previously registered script
            wp_enqueue_script('ewp-recently-seen');
            
            // Pass data to JavaScript for use in tracking recently seen items
          wp_localize_script('ewp-recently-seen', 'ewpRecentlySeen', array(
                'id' => get_the_ID(),
                'post_type' => $current_post_type
            ));
        }
    }

}

new EWP_Recently_Seen_UTIL();