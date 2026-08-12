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


if (!function_exists('ewp_template_source_options')) {
 /**
  * Build the option list for the "template from" selects of the post type and
  * taxonomy configuration screens.
  *
  * Values are namespaced tokens (`post_type:{slug}` / `taxonomy:{slug}`) so a
  * post type and a taxonomy sharing a slug stay distinguishable. The returned
  * array carries the `optgroups` entry the standard `select` renderer expects
  * (see awm_show_content() in includes/functions/library.php).
  *
  * Used as a field `callback`, resolved at render time by awm_prepare_field().
  *
  * @return array Options array including an `optgroups` entry.
  */
 function ewp_template_source_options()
 {
  $options = $optgroups = array();
  $groups = array(
   EWP_Template_Resolver::SOURCE_POST_TYPE => array(
    'label' => __('Post types', 'extend-wp'),
    'objects' => get_post_types(array('public' => true), 'objects'),
   ),
   EWP_Template_Resolver::SOURCE_TAXONOMY => array(
    'label' => __('Taxonomies', 'extend-wp'),
    'objects' => get_taxonomies(array('public' => true), 'objects'),
   ),
  );

  foreach ($groups as $group_id => $group) {
   if (empty($group['objects'])) {
    /*an empty optgroup would still print a closing tag, so skip it*/
    continue;
   }
   $optgroups[$group_id] = array('label' => $group['label']);
   foreach ($group['objects'] as $slug => $object) {
    $options[$group_id . ':' . $slug] = array(
     'label' => sprintf('%s (%s)', $object->label, $slug),
     'optgroup' => $group_id,
    );
   }
  }

  $options['optgroups'] = $optgroups;

  /**
   * Filter the available template sources.
   *
   * @param array $options Options array including the `optgroups` entry.
   */
  return apply_filters('ewp_template_source_options_filter', $options);
 }
}
