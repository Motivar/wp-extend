<?php

/**
 * Gallery meta box class.
 */

/**
 * Class Truongwp_Gallery_Meta_Box.
 */

class Truongwp_Gallery_Meta_Box
{
    public function init()
    {
        add_action('add_meta_boxes', array($this, 'add'));

        foreach ($this->post_types() as $post_type) {
            add_action('save_post_' . $post_type, array($this, 'save'), 10, 3);
        }
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('admin_footer', array($this, 'js_template'));
        add_action('admin_init', array($this, 'awm_flx_gallery_post_columns'), 1);
    }

    public function awm_flx_gallery_post_columns()
    {
        global $pagenow;

        switch ($pagenow) {
            case 'edit.php';
                $post_types =  $this->post_types();
                if (!empty($post_types)) {
                    if (isset($_GET['post_type']) && in_array($_GET['post_type'], $post_types)) {
                        $post_type = $_GET['post_type'];
                        /*add post columns*/
                        add_filter('manage_' . $post_type . '_posts_columns', function ($columns) {
                            $columns['flx_gallery'] = __('Thumb', 'extend-wp');
                            return $columns;
                        }, 10, 1);
                        /*add the value of the post columns*/
                        add_action('manage_' . $post_type . '_posts_custom_column', function ($column) {
                            global $post;
                            switch ($column) {
                                case 'flx_gallery':
                                    echo awm_show_featured_image($post->ID);
                                    break;
                            }
                        }, 10, 1);
                    }
                    break;
                }
        }
    }



    /**
     * Enqueue necessary js and css.
     */
    public function enqueue()
    {
        if (!$this->is_editing_screen()) {
            return;
        }
        if (!did_action('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        wp_enqueue_style('truongwp-gallery-meta-box', TRUONGWP_GALLERY_META_BOX_URL . 'css/gallery-meta-box.css', array(), false);
        wp_enqueue_script('truongwp-gallery-meta-box', TRUONGWP_GALLERY_META_BOX_URL . 'js/gallery-meta-box.js', array('backbone', 'jquery'), false, true);
    }

    /**
     * Add meta box.
     *
     * @param string $post_type post type name
     */
    public function add($post_type)
    {
        if (!in_array($post_type, $this->post_types())) {
            return;
        }

        add_meta_box(
            'truongwp-gallery',
            __('Gallery', 'gallery-meta-box'),
            array($this, 'render'),
            $post_type,
            'side',
            'default'
        );
    }

    /**
     * Save meta data.
     *
     * @param int     $post_id post id
     * @param WP_Post $post    post object
     * @param bool    $update  is updating or not
     *
     * @return mixed
     */
    public function save($post_id, $post, $update)
    {
        /*
         * We need to verify this came from the our screen and with proper authorization,
         * because save_post can be triggered at other times.
         */

        // Check if our nonce is set.
        if (!isset($_POST['gallery_meta_box_nonce'])) {
            return $post_id;
        }

        $nonce = $_POST['gallery_meta_box_nonce'];

        // Verify that the nonce is valid.
        if (!wp_verify_nonce($nonce, 'gallery_meta_box')) {
            return $post_id;
        }

        /*
         * If this is an autosave, our form has not been submitted,
         * so we don't want to do anything.
         */
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        // Check the user's permissions.
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        // Save data.
        if (isset($_POST['gallery_meta_box'])) {
            $value = array_map('absint', $_POST['gallery_meta_box']);
            update_post_meta($post_id, $this->meta_key(), $value);
            $feat_img_id = $value[0];
            set_post_thumbnail($post_id, $feat_img_id);
        } else {
            delete_post_meta($post_id, $this->meta_key());
        }

        /*
         * Fires after saving gallery data.
         *
         * @var int     $post_id Post ID.
         * @var WP_Post $post    Post object.
         * @var bool    $update  Whether this is an existing post being updated or not.
         */
        do_action('gallery_meta_box_save', $post_id, $post, $update);

        return $post_id;
    }

    /**
     * Render meta box output.
     *
     * @param WP_Post $post post object
     */
    public function render($post)
    {

        wp_nonce_field('gallery_meta_box', 'gallery_meta_box_nonce');
        $ids = get_post_meta($post->ID, $this->meta_key(), true);
        if (!$ids || !is_array($ids)) {
            $ids = array();
        } ?>
        <div id="truongwp-gallery-container" class="gallery">
            <?php if ($ids || !empty($ids)) {

                foreach ($ids as $thesis => $id) { ?>
                    <?php if (is_int($id)) { ?>
                        <div id="gallery-image-<?php echo absint($id); ?>" class="gallery-item">
                            <?php echo wp_get_attachment_image($id, 'thumbnail'); ?>

                            <a href="#" class="gallery-remove">&times;</a>

                            <input type="hidden" name="gallery_meta_box[]" value="<?php echo absint($id); ?>">
                        </div>
                    <?php } else {
                        unset($ids[$thesis]);
                    } ?>
            <?php }
            } ?>
        </div>

        <a href="#" id="truongwp-add-gallery"><?php esc_html_e('Set gallery images', 'gallery-meta-box'); ?></a>

        <input type="hidden" id="truongwp-gallery-ids" value="<?php echo esc_attr(implode(',', $ids)); ?>">
    <?php
    }

    public function js_template()
    {
        if (!$this->is_editing_screen()) {
            return;
        }
    ?>

        <script type="text/html" id="tmpl-gallery-meta-box-image">
            <div id="gallery-image-{{{ data.id }}}" class="gallery-item">
                <img src="{{{ data.url }}}">
                <a href="#" class="gallery-remove">&times;</a>
                <input type="hidden" name="gallery_meta_box[]" value="{{{ data.id }}}">
            </div>
        </script>
<?php
    }

    /**
     * Get post types for this meta box.
     *
     * @return array
     */
    protected function post_types()
    {
        $post_types = array();

        /*
         * Filters supported post types.
         *
         * @var array $post_types List supported post types.
         */
        return apply_filters('gallery_meta_box_post_types', $post_types);
    }

    /**
     * Returns gallery meta key.
     *
     * @return string
     */
    protected function meta_key()
    {
        /*
         * Filters meta key to store the gallery.
         *
         * @var string $meta_key Meta key.
         */
        return apply_filters('gallery_meta_box_meta_key', 'ewp_gallery');
    }

    /**
     * Check if is in editing screen.
     *
     * @return bool
     */
    protected function is_editing_screen()
    {
        $screen = get_current_screen();

        return in_array($screen->id, $this->post_types());
    }
}
