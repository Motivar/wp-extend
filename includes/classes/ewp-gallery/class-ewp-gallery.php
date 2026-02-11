<?php
/**
 * EWP Gallery Meta Box Class
 *
 * Lightweight replacement for the legacy Truongwp_Gallery_Meta_Box.
 * Registers a gallery meta box on post types that opt-in via the
 * 'gallery_meta_box_post_types' filter, handles saving, sets the
 * featured image, and registers assets via the Dynamic Asset Loader.
 *
 * @package ExtendWP
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EWP_Gallery_Meta_Box
 *
 * Manages gallery meta boxes for custom post types.
 */
class EWP_Gallery_Meta_Box
{
    /**
     * Singleton instance
     *
     * @var EWP_Gallery_Meta_Box|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return EWP_Gallery_Meta_Box
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — registers all WordPress hooks.
     */
    private function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add'));
        add_action('admin_enqueue_scripts', array($this, 'maybe_enqueue_media'));
        add_action('admin_init', array($this, 'gallery_post_columns'), 1);
        add_filter('ewp_register_dynamic_assets', array($this, 'register_dynamic_assets'));

        /*
         * Use the generic save_post hook because post_types() depends on
         * 'gallery_meta_box_post_types' which is populated at 'init' —
         * after this constructor runs at 'plugins_loaded'.
         * The post type guard is inside save().
         */
        add_action('save_post', array($this, 'save'), 10, 3);
    }

    /* ----------------------------------------------------------
     * Filters (backward-compatible)
     * ---------------------------------------------------------- */

    /**
     * Returns the list of post types that should have a gallery meta box.
     *
     * Developers add post types via the 'gallery_meta_box_post_types' filter.
     * EWP_WP_Content_Installer::gallery() hooks here to add types with
     * the custom_gallery flag.
     *
     * @return array
     */
    public function post_types()
    {
        /**
         * Filters supported post types for the gallery meta box.
         *
         * @param array $post_types List of post type slugs.
         */
        return apply_filters('gallery_meta_box_post_types', array());
    }

    /**
     * Returns the meta key used to store gallery image IDs.
     *
     * @return string
     */
    public function meta_key()
    {
        /**
         * Filters the meta key to store gallery data.
         *
         * @param string $meta_key Default meta key.
         */
        return apply_filters('gallery_meta_box_meta_key', 'ewp_gallery');
    }

    /* ----------------------------------------------------------
     * Meta box registration
     * ---------------------------------------------------------- */

    /**
     * Register the gallery meta box on supported post types.
     *
     * @param string $post_type Current post type.
     */
    public function add($post_type)
    {
        if (!in_array($post_type, $this->post_types(), true)) {
            return;
        }

        add_meta_box(
            'ewp-gallery',
            __('Gallery', 'extend-wp'),
            array($this, 'render'),
            $post_type,
            'side',
            'default'
        );
    }

    /**
     * Render the gallery meta box content.
     *
     * @param WP_Post $post Current post object.
     */
    public function render($post)
    {
        wp_nonce_field('ewp_gallery_meta_box', 'ewp_gallery_meta_box_nonce');

        $ids = get_post_meta($post->ID, $this->meta_key(), true);
        if (!$ids || !is_array($ids)) {
            $ids = array();
        }

        /* Use the unified media field function */
        echo awm_media_field_html($this->meta_key(), $ids, 0);
    }

    /* ----------------------------------------------------------
     * Save
     * ---------------------------------------------------------- */

    /**
     * Save gallery meta data when a post is saved.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an update.
     * @return int Post ID (for chaining / early returns).
     */
    public function save($post_id, $post, $update)
    {
        /* Nonce check */
        if (!isset($_POST['ewp_gallery_meta_box_nonce'])) {
            return $post_id;
        }

        if (!wp_verify_nonce($_POST['ewp_gallery_meta_box_nonce'], 'ewp_gallery_meta_box')) {
            return $post_id;
        }

        /* Skip autosave */
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        /* Capability check */
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        /* Only process for gallery-enabled post types */
        if (!in_array($post->post_type, $this->post_types(), true)) {
            return $post_id;
        }

        $meta_key = $this->meta_key();

        /* Save or delete */
        if (isset($_POST[$meta_key]) && is_array($_POST[$meta_key])) {
            $value = array_map('absint', $_POST[$meta_key]);
            update_post_meta($post_id, $meta_key, $value);

            /* Set featured image from first gallery image */
            if (!empty($value[0])) {
                set_post_thumbnail($post_id, $value[0]);
            }
        } else {
            delete_post_meta($post_id, $meta_key);
        }

        /**
         * Fires after gallery data is saved.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         * @param bool    $update  Whether this is an update.
         */
        do_action('gallery_meta_box_save', $post_id, $post, $update);

        return $post_id;
    }

    /* ----------------------------------------------------------
     * Admin columns (Thumb)
     * ---------------------------------------------------------- */

    /**
     * Add a thumbnail column to post lists of supported post types.
     */
    public function gallery_post_columns()
    {
        global $pagenow;

        if ($pagenow !== 'edit.php') {
            return;
        }

        $post_types = $this->post_types();

        if (empty($post_types)) {
            return;
        }

        if (!isset($_GET['post_type']) || !in_array($_GET['post_type'], $post_types, true)) {
            return;
        }

        $post_type = sanitize_text_field($_GET['post_type']);

        /* Add "Thumb" column */
        add_filter('manage_' . $post_type . '_posts_columns', function ($columns) {
            $columns['flx_gallery'] = __('Thumb', 'extend-wp');
            return $columns;
        }, 10, 1);

        /* Render column value */
        add_action('manage_' . $post_type . '_posts_custom_column', function ($column) {
            if ($column !== 'flx_gallery') {
                return;
            }
            global $post;
            if (function_exists('awm_show_featured_image')) {
                echo awm_show_featured_image($post->ID);
            }
        }, 10, 1);
    }

    /* ----------------------------------------------------------
     * Enqueue wp.media on editing screens
     * ---------------------------------------------------------- */

    /**
     * Ensure wp.media and jQuery UI Sortable are loaded on editing screens.
     */
    public function maybe_enqueue_media()
    {
        if (!$this->is_editing_screen()) {
            return;
        }

        if (!did_action('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        wp_enqueue_script('jquery-ui-sortable');
    }

    /* ----------------------------------------------------------
     * Dynamic Asset Loader registration
     * ---------------------------------------------------------- */

    /**
     * Register the media-field JS and CSS via the Dynamic Asset Loader.
     *
     * Assets are loaded only when a .awm-media-field element exists on the page.
     *
     * @param array $assets Existing registered assets.
     * @return array Modified assets array.
     *
     * @hook ewp_register_dynamic_assets
     */
    public function register_dynamic_assets($assets)
    {
        static $version = '1.0.0';

        /* Script */
        $assets[] = array(
            'handle'       => 'awm-media-field-script',
            'selector'     => '.awm-media-field',
            'type'         => 'script',
            'src'          => awm_url . 'assets/js/admin/class-awm-media-field.js',
            'version'      => $version,
            'context'      => 'admin',
            'dependencies' => array(),
            'in_footer'    => true,
            'localize'     => array(
                'objectName' => 'awmMediaFieldData',
                'data'       => array(
                    'selectImages'  => __('Select Images', 'extend-wp'),
                    'selectImage'   => __('Select Image', 'extend-wp'),
                    'useImages'     => __('Use selected images', 'extend-wp'),
                    'useImage'      => __('Use selected image', 'extend-wp'),
                    'addImages'     => __('Add Images', 'extend-wp'),
                    'editGallery'   => __('Edit Gallery', 'extend-wp'),
                    'remove'        => __('Remove', 'extend-wp'),
                    'insertMedia'   => __('Insert media', 'extend-wp'),
                    'removeMedia'   => __('Remove media', 'extend-wp'),
                ),
            ),
        );

        /* Style */
        $assets[] = array(
            'handle'   => 'awm-media-field-style',
            'selector' => '.awm-media-field',
            'type'     => 'style',
            'src'      => awm_url . 'assets/css/admin/awm-media-field.css',
            'version'  => $version,
            'context'  => 'admin',
        );

        return $assets;
    }

    /* ----------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------- */

    /**
     * Check if current screen is an editing screen for a supported post type.
     *
     * @return bool
     */
    private function is_editing_screen()
    {
        $screen = get_current_screen();

        if (!$screen) {
            return false;
        }

        return in_array($screen->id, $this->post_types(), true);
    }
}

/* Initialize on plugins_loaded so all filters are available */
add_action('plugins_loaded', function () {
    EWP_Gallery_Meta_Box::get_instance();
}, 20);