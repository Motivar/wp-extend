<?php
if (!defined('ABSPATH')) {
  exit;
}
/**
 * this class has hooks and function in order to work well with WMPL library
 * 
 */

class EWP_Wmpl
{

  public function __construct()
  {
    add_filter('awm_auto_translate_fields_filter', array($this, 'add_gallery_to_translation'), 10, 2);
    if (function_exists('icl_object_id')) {
      add_action('do_meta_boxes', array($this, 'remove_boxes'), PHP_INT_MAX);
    }
  }

  /**
   * add gallery meta key to translatable meta keys
   * @param array $data all the tranlatable meta keys
   * @param string $case
   *
   * @return array $data all the meta keys for translation.
   */
  public function add_gallery_to_translation($data, $case)
  {
    switch ($case) {
      case 'post':
        $gallery_box = new Truongwp_Gallery_Meta_Box();
        $data[] = $gallery_box->meta_key();
        break;
    }
    return $data;
  }


  /**
   * remote the metaboxes in not primiary language
   */
  public function remove_boxes()
  {
    global $sitepress;
    if ($sitepress->get_default_language() != ICL_LANGUAGE_CODE) {
      /*the gallery*/
      $post_types = apply_filters('gallery_meta_box_post_types', array());
      foreach ($post_types as $post_type) {
        remove_meta_box('truongwp-gallery', $post_type, 'side');
      }
      /*check the auto translate*/
      $metaBoxes = apply_filters('awm_add_meta_boxes_filter', array());
      foreach ($metaBoxes as $metaBoxKey => $metaBoxData) {
        if (isset($metaBoxData['auto-translate']) &&  $metaBoxData['auto-translate']) {
          foreach ($metaBoxData['postTypes'] as $post_type) {
            remove_meta_box($metaBoxKey, $post_type, $metaBoxData['context']);
          }
        }
      }
    }
  }
}


new EWP_Wmpl();
