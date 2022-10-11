<?php

/**
 * Gallery meta box.
 *
 * @author Truong Giang <truongwp@gmail.com>
 *
 * @version 0.1.2
 */
if (defined('TRUONGWP_GALLERY_META_BOX_PATH')) {
    return;
}

define('TRUONGWP_GALLERY_META_BOX_PATH', plugin_dir_path(__FILE__));
define('TRUONGWP_GALLERY_META_BOX_URL', plugin_dir_url(__FILE__));

require_once TRUONGWP_GALLERY_META_BOX_PATH . 'class-truongwp-gallery-meta-box.php';

/**
 * Initialize.
 */
function truongwp_gallery_meta_box_init()
{
    $metaBox = new Truongwp_Gallery_Meta_Box();
    $metaBox->init();
}
add_action('init', 'truongwp_gallery_meta_box_init');
