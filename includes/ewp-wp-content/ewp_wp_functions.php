<?php
if (!defined('ABSPATH')) {
 exit;
}


/**
 * create capabilities for post type and for capability
 * @param string $post the post type name
 * @param string $cap the name of the capability we would like to create
 *
 */
if (!function_exists('ewp_create_caps')) {
 function ewp_create_caps($post, $cap = '')
 {
  $capabilities = array(
   'edit_published_posts' => 'edit_published_' . $post . 's',
   'delete_published_posts' => 'delete_published_' . $post . 's',
   'publish_posts' => 'publish_' . $post . 's',
   'edit_posts' => 'edit_' . $post . 's',
   'edit_others_posts' => 'edit_others_' . $post . 's',
   'delete_posts' => 'delete_' . $post . 's',
   'delete_others_posts' => 'delete_others_' . $post . 's',
   'read_private_posts' => 'read_private_' . $post . 's',
   'delete_private_posts' => 'delete_private_' . $post . 's',
   'edit_post' => 'edit_' . $post,
   'delete_post' => 'delete_' . $post,
   'read_post' => 'read_' . $post,
   'publish_post' => 'publish_' . $post,
   'read' => 'read'
  );
  if (!empty($cap)) {
   return $capabilities[$cap];
  }
  return $capabilities;
 }
}


if (!function_exists('ewp_roles_access')) {
 /**
  * register users and connections roles
  */
 function ewp_roles_access()
 {
  $connections = array(
   'fullAccess' => array(
    'users' => array('administrator'),
    'capabilities' => array('publish_posts', 'edit_posts', 'edit_others_posts', 'delete_posts', 'delete_others_posts', 'read_private_posts', 'edit_post', 'delete_post', 'read_post', 'edit_published_posts', 'delete_published_posts', 'delete_private_posts'),
   ),
   'semiAccess' => array(
    'users' => array(),
    'capabilities' => array('publish_posts', 'edit_posts', 'edit_published_posts', 'edit_others_posts', 'delete_posts', 'edit_post', 'delete_post', 'read_post'),
   )
  );
  return apply_filters('ewp_roles_access_filter', $connections);
 }
}
