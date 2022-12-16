<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * with this class we set custom db structure to save the custom taxonomies and post types
 */

class Extend_WP_WP_Content
{
 public function __construct()
 {
  add_filter('awm_register_content_db', [$this, 'register_defaults']);
  add_filter('epw_get_post_types', [$this, 'get_post_types']);
  add_filter('epw_get_taxonomies', [$this, 'get_taxonomies']);
  add_action('ewp_post_types_save_action', [$this, 'clear_transients']);
  add_action('ewp_post_types_delete_action', [$this, 'clear_transients']);
  add_action('ewp_taxonomies_save_action', [$this, 'clear_transients']);
  add_action('ewp_taxonomies_delete_action', [$this, 'clear_transients']);
 }


 public function get_taxonomies($taxes)
 {
  $transient_key = 'ewp_taxonomies';
  $cached = awm_get_transient($transient_key);
  if ($cached !== false) {
   return $cached;
  }
  $taxonomies = awm_get_db_content('ewp_taxonomies');
  if (!empty($taxonomies)) {
   foreach ($taxonomies as $taxonomy) {
    $metas = awm_get_db_content_meta('ewp_taxonomies', $taxonomy['content_id']);
    $prefix = !empty($metas['prefix']) ? $metas['prefix'] : 'ewp';
    $taxonomy_name = $prefix . '_' . awm_clean_string(strtolower($metas['taxonomy_name']));
    $taxes[$taxonomy_name] = array(
     'name' => __($metas['name'], 'extend-wp'),
     'label' => __($metas['label'], 'extend-wp'),
     'custom-slug' => true,
     'post_types' => $metas['post_types'],
     'template' => $metas['template']
    );
   }
  }
  $taxes = apply_filters('ewp_get_taxonomies_filter', $taxes, $taxonomies);
  awm_set_transient($transient_key, $taxes, 0, 36, 'awm_post_fields_transients');
  return $taxes;
 }


 public function clear_transients()
 {
  update_option('ewp_user_caps_version', strtotime('now'), false);
  awm_delete_transient_group('awm_post_fields_transients');
  delete_option('ewp_user_caps_version');
 }



 public function get_post_types()
 {

  $transient_key = 'ewp_post_types';
  $cached = awm_get_transient($transient_key);
  if ($cached !== false) {
   return $cached;
  }
  $post_types = awm_get_db_content('ewp_post_types');
  $types = array();
  if (!empty($post_types)) {
   foreach ($post_types as $post) {
    $metas = awm_get_db_content_meta('ewp_post_types', $post['content_id']);
    if (!empty($post['content_title'])) {
     $prefix = !empty($metas['prefix']) ? $metas['prefix'] : 'ewp';
     $post_name = $prefix . '_' . awm_clean_string(strtolower($metas['post_name']));
     $types[$post_name] = array(
      'post' => $post_name,
      'sn' => __($metas['singular'], 'filox'),
      'pl' => __($metas['plural'], 'filox'),
      'custom_gallery' => isset($metas['gallery']) ? $metas['gallery'] : false,
      'args' => isset($metas['args']) ? $metas['args'] : false,
      'flx_enable' => true,
      'slug' => get_option($post_name . '_slug') ?: $prefix . '_' . awm_clean_string(strtolower($metas['singular'])),
      'taxonomies_connected' => isset($metas['taxonomies']) ? $metas['taxonomies'] : array(),
      'description' => isset($metas['description']) ? $metas['description'] : '',
      'flx_custom_template' => isset($metas['custom_template']) ? $metas['custom_template'] : false,
      'admin_access' => array(
       'fullAccess' => array('administrator'),
       'semiAccess' => array(),
      )
     );
     if (isset($metas['fullAccess']) && !empty($metas['fullAccess'])) {
      $types[$post_name]['admin_access']['fullAccess'] += $metas['fullAccess'];
     }
     if (isset($metas['semiAccess']) && !empty($metas['semiAccess'])) {
      $types[$post_name]['admin_access']['semiAccess'] = $metas['semiAccess'];
     }
    }
   }
  }
  $types = apply_filters('ewp_get_post_types_filter', $types, $post_types);
  awm_set_transient($transient_key, $types, 0, 36, 'awm_post_fields_transients');
  return $types;
 }



 public function register_defaults($data)
 {
  /*register fields*/
  $data['post_types'] = array(
   'parent' => 'extend-wp',
   'statuses' => array(
    'enabled' => array('label' => __('Enabled', 'extend-wp')),
    'disabled' => array('label' => __('Disabled', 'extend-wp')),
   ),
   'show_new' => false,
   'list_name' => __('Post types', 'extend-wp'),
   'list_name_singular' => __('Post type', 'extend-wp'),
   'order' => 1,
   'capability' => 'edit_posts',
   'version' => 0.01,
   'metaboxes' => array(
    'post_type_configuration' => array(
     'title' => __('Post type configuration', 'extend-wp'),
     'context' => 'normal',
     'priority' => 'high',
     'library' => $this->ewp_post_type_fields_creation(),
     'auto_translate' => true,
     'order' => 1,
    ),
    'post_type_users' => array(
     'title' => __('User access configuration', 'extend-wp'),
     'context' => 'side',
     'priority' => 'low',
     'library' => $this->ewp_post_type_users(),
     'auto_translate' => true,
     'order' => 1,
    )
   )
  );
  /*register fields*/
  $data['taxonomies'] = array(
   'parent' => 'extend-wp',
   'statuses' => array(
    'enabled' => array('label' => __('Enabled', 'extend-wp')),
    'disabled' => array('label' => __('Disabled', 'extend-wp')),
   ),
   'show_new' => false,
   'list_name' => __('Taxonomies', 'extend-wp'),
   'list_name_singular' => __('Taxonomy', 'extend-wp'),
   'order' => 1,
   'capability' => 'edit_posts',
   'version' => 0.02,
   'metaboxes' => array(
    'taxonomy_configuration' => array(
     'title' => __('Taxonomy configuration', 'extend-wp'),
     'context' => 'normal',
     'priority' => 'high',
     'library' => $this->ewp_taxonomy_fields_creation(),
     'auto_translate' => true,
     'order' => 1,
    )
   )
  );
  return $data;
 }


 /**
  * with this function we set the taxonomy dynamic form
  */
 public function ewp_post_type_users()
 {
  return apply_filters(
   'ewp_post_type_users_filter',
   array(
    'fullAccess' => array(
     'case' => 'user_roles',
     'exclude' => array('administrator'),
     'attributes' => array('multiple' => true),
     'label' => __('Select user roles with full edit access', 'extend-wp'),
    ),
    'semiAccess' => array(
     'case' => 'user_roles',
     'exclude' => array('administrator'),
     'attributes' => array('multiple' => true),
     'label' => __('Select user roles with restricted edit access ', 'extend-wp'),
    )
   )
  );
 }


 /**
  * with this function we set the taxonomy dynamic form
  */
 public function ewp_post_type_fields_creation()
 {
  return apply_filters(
   'ewp_post_type_fields_creation_filter',
   array(
    'post_name' => array(
     'label' => __('Post label', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'class' => array('awm-lowercase'),
     'attributes' => array('title' => 'English only!', 'pattern' => '[\x00-\x7F]+'),
     'label_class' => array('awm-needed'),
    ),
    'plural' => array(
     'label' => __('Name plural', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'label_class' => array('awm-needed'),
    ),
    'singular' => array(
     'label' => __('Name singular', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'label_class' => array('awm-needed'),
    ),
    'prefix' => array(
     'label' => __('Registration prefix', 'extend-wp'),
     'case' => 'input',
     'type' => 'text'
    ),
    'gallery' => array(
     'label' => __('Has gallery', 'extend-wp'),
     'case' => 'input',
     'type' => 'checkbox'
    ),

    'custom_template' => array(
     'label' => __('Is public', 'extend-wp'),
     'case' => 'input',
     'type' => 'checkbox',
     'admin_list' => true
    ),
    'args' => array(
     'label' => __('Args', 'extend-wp'),
     'case' => 'select',
     'options' => array(
      'title' => array('label' => __('title', 'extend-wp')),
      'editor' => array('label' => __('editor', 'extend-wp')),
      'author' => array('label' => __('author', 'extend-wp')),
      'thumbnail' => array('label' => __('thumbnail', 'extend-wp')),
      'excerpt' => array('label' => __('excerpt', 'extend-wp')),
      'trackbacks' => array('label' => __('trackbacks', 'extend-wp')),
      'custom-fields' => array('label' => __('custom-fields', 'extend-wp')),
      'comments' => array('label' => __('comments', 'extend-wp')),
      'revisions' => array('label' => __('revisions', 'extend-wp')),
      'page-attributes' => array('label' => __('page-attributes', 'extend-wp')),
      'post-formats' => array('label' => __('post-formats', 'extend-wp')),
     ),
     'attributes' => array('multiple' => true),
    ),
    'taxonomies' => array(
     'case' => 'taxonomies',
     'label' => __('Attach taxonomies', 'extend-wp'),
     'attributes' => array('multiple' => 1)
    ),
    'extra_slug' => array(
     'label' => __('With front (taxonomy_label)', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
    ),
    'description' => array(
     'label' => __('Desription', 'extend-wp'),
     'case' => 'textarea',
    ),
   )
  );
 }


 /**
  * with this function we set the taxonomy dynamic form
  */
 public function ewp_taxonomy_fields_creation()
 {
  return apply_filters(
   'ewp_taxonomy_fields_creation_filter',
   array(
    'taxonomy_name' => array(
     'label' => __('Taxonomy name', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'class' => array('awm-lowercase'),
     'attributes' => array('title' => 'English only!', 'pattern' => '[\x00-\x7F]+'),
     'label_class' => array('awm-needed'),
    ),
    'name' => array(
     'label' => __('Name plural', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'label_class' => array('awm-needed'),
    ),
    'label' => array(
     'label' => __('Name singular', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'label_class' => array('awm-needed'),
    ),

    'prefix' => array(
     'label' => __('Registration prefix', 'extend-wp'),
     'case' => 'input',
     'type' => 'text'
    ),
    'post_types' => array(
     'label' => __('Post types', 'extend-wp'),
     'case' => 'post_types',
     'attributes' => array('multiple' => true),
     'label_class' => array('awm-needed'),
     'admin_list' => true
    ),
    'template' => array(
     'label' => __('Template path', 'extend-wp'),
     'case' => 'input',
     'type' => 'text',
     'explanation' => __('if you create ewp_{taxonomy_name}.php it will be used. Otherwise use path (from plugins/ or themes/). If none archive.php will be used.', 'extend-wp'),
    )
   )

  );
 }
}


new Extend_WP_WP_Content();
