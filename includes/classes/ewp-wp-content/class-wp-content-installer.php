<?php
if (!defined('ABSPATH')) {
  exit;
}
/**
 * this is the class which installs all the post types
 */
global $ewp_post_types;
require_once 'ewp_wp_functions.php';

class EWP_WP_Content_Installer
{

  /**
   * @var post_types all the post types.
   */
  protected $post_types;
  /**
   * @var taxonomies all the post types.
   */
  protected $taxonomies;

  public function init()
  {
    register_activation_hook(awm_path . 'extend-wp.php', array($this, 'activate'));
    register_deactivation_hook(awm_path . 'extend-wp.php', array($this, 'deactivate'));
    add_action('init', [$this, 'register_post_types'], 0);
    add_action('init', [$this, 'register_taxonomies'], 0);
    add_filter('init', [$this, 'connect_post_types_taxonomies'], 0);
    add_action('init', [$this, 'register_sidebars'], 100);
    add_action('admin_init', [$this, 'add_caps'], 1);
    add_action('load-options-permalink.php', [$this, 'permalink_settings']);
    add_filter('gallery_meta_box_post_types', array($this, 'gallery'));
    add_filter('template_include', array($this, 'taxonomy_page_redirect'), 90);
    add_filter('single_template', [$this, 'set_single'], 10);
    add_filter('use_block_editor_for_post_type', [$this, 'disable_gutenburg'], 10, 2);
    add_action('plugins_loaded', function () {
      if (isset($_REQUEST['ewp_delete_trans'])) {
        ewp_flush_cache();
        die();
      }
    });
  }


  function disable_gutenburg($current_status, $post_type)
  {
    // Use your post type key instead of 'product'
    $types = $this->post_types;
    if (empty($types)) {
      return $current_status;
    }
    foreach ($types as $n) {
      if ($post_type == $n['post'] && isset($n['disable_gutenburg']) && $n['disable_gutenburg']) {
        return false;
      }
    }
    return $current_status;
  }


  public function set_single($single)
  {
    global $post;
    $types = $this->post_types;
    if (empty($types)) {
      return $single;
    }
    foreach ($types as $n) {
      if ($post->post_type == $n['post']) {
        $default = locate_template(array($n['post'] . '.php'));
        if ($default != '') {
          return $default;
        }
      }
    }


    return $single;
  }


  /**
   * enable the gallery for the custom types of filox
   */
  public function gallery($post_types)
  {
    $types = $this->post_types;
    if (empty($types)) {
      return $post_types;
    }
    foreach ($types as $n) {
      if (isset($n['custom_gallery']) && $n['custom_gallery']) {
        $post_types[] = $n['post'];
      }
    }
    return apply_filters('gallery_meta_box_post_types_filter', $post_types);
  }

  function taxonomy_page_redirect($template)
  {

    if (empty($this->taxonomies)) {
      return $template;
    }

    foreach ($this->taxonomies as $taxonomy_name => $taxonomy) {

      if (is_tax($taxonomy_name)) {

        $default = locate_template(array('taxonomy-' . $taxonomy_name . '.php'));

        if ($default != '') {
          return $default;
        }

        $file_path = (!empty($taxonomy['template']) && file_exists(WP_CONTENT_DIR . '/' . $taxonomy['template'])) ? WP_CONTENT_DIR . '/' . $taxonomy['template'] :  '';
        if ($file_path != '') {
          return $file_path;
        }
      }
    }
    return $template;
  }



  /**
   * with this function we enter the permalink settings
   */
  public function permalink_settings()
  {

    $slugs = array();
    if (!empty($this->post_types)) {
      foreach ($this->post_types as $n) {
        if (isset($n['flx_custom_template']) && $n['flx_custom_template']) {
          $slugs[$n['post']] = $n['sn'];
        }
      }
    }
    if (!empty($this->taxonomies)) {
      foreach ($this->taxonomies as $tax => $tax_data) {
        $slugs[$tax] = isset($tax_data['label']) ? __($tax_data['label'], 'extend-wp') : $tax;
      }
    }

    if (empty($slugs)) {
      return;
    }
    foreach ($slugs as $slug => $title) {
      $updated = false;
      /*check if updated*/
      if (isset($_POST[$slug . '_slug'])) {
        update_option($slug . '_slug', sanitize_title_with_dashes($_POST[$slug . '_slug']), false);
        $updated = true;
      }
      /*register the setting*/
      add_settings_field(
        $slug . '_slug',
        __($title . ' Slug', 'extend-wp'),
        function ($views) use ($slug) {
          $value = get_option($slug . '_slug');
          echo '<input type="text" value="' . esc_attr($value) . '" name="' . $slug . '_slug' . '" id="' . $slug . '_slug' . '" class="regular-text" placeholder="' . $slug . '"/>';
        },
        'permalink',
        'optional'
      );
    }
    if ($updated) {
      ewp_flush_cache();
    }
  }






  /**
   * dynamic register all the widgets to the post types which have archives
   */
  public function register_sidebars()
  {
    if (!empty($this->post_types)) {
      foreach ($this->post_types as $post_type) {
        if (isset($post_type['flx_custom_template']) && $post_type['flx_custom_template']) {
          register_sidebars(
            1,
            array(
              'id' => $post_type['post'] . '-archive-widget',
              'name' => sprintf(__('Sidebar for %s', 'extend-wp'), $post_type['pl']),
              'before_widget' => '<div id="%1$s" class="widget %2$s ' . $post_type['post'] . '-widget">',
              'after_widget' => '</div>',
              'before_title' => '<h2 class="widgettitle ' . $post_type['post'] . '-widget-title">',
              'after_title' => '</h2>',
            )
          );
        }
      }
    }
  }



  /**
   * working when activate the plugin
   */
  public function activate()
  {
    ewp_flush_cache()
  }
  /**
   * working when de-activate the plugin
   */

  public function deactivate()
  {
    ewp_flush_cache();
  }


  /**
   * add caps for new post types and users
   */
  public function add_caps()
  {
    /*init users first*/
    $users_version_old = get_option('ewp_user_caps_version_old');
    $users_version = get_option('ewp_user_caps_version') ?: EWP_USERS_VERSION;
    if ($users_version_old != $users_version) {
      $types = $this->post_types;
      $capabilitiesInfo = ewp_roles_access();
      if (!empty($types)) {
        foreach ($types as $type) {
          foreach ($capabilitiesInfo as $access_type => $access_data) {
            if (isset($type['admin_access'][$access_type])) {
              $access_data['users'] = array_merge($access_data['users'], $type['admin_access'][$access_type]);
            }
            foreach ($access_data['users'] as $user) {
              $admin = get_role($user);
              foreach ($access_data['capabilities'] as $cap) {
                $admin->add_cap(ewp_create_caps($type['post'], $cap));
              }
            }
          }
        }
        update_option('ewp_user_caps_version_old', $users_version, false);
      }
    }
  }


  /**
   * check for connections and attach taxonomies
   */
  public function connect_post_types_taxonomies()
  {
    $post_types = $this->post_types;
    foreach ($post_types as $post_type) {
      $post_taxes = isset($post_type['taxonomies_connected']) ? $post_type['taxonomies_connected'] : array();

      if (!empty($post_taxes)) {
        foreach ($post_taxes as $taxonomy) {
          register_taxonomy_for_object_type($taxonomy, $post_type['post']);
        }
      }
    }
  }

  /**
   * Get post types from everywhere.
   *
   * @return array
   */
  protected function post_types()
  {
    global $ewp_post_types;
    $types = apply_filters('epw_get_post_types', array());
    /**
     * sort settings by menu_position
     */
    if (!empty($types)) {
      uasort($types, function ($a, $b) {
        $first = isset($a['menu_position']) ? $a['menu_position'] : 100;
        $second = isset($b['menu_position']) ? $b['menu_position'] : 100;
        return $first - $second;
      });
    }
    $ewp_post_types = $types;
    return $types;
  }

  /**
   * Get taxonomies from everywhere.
   *
   * @return array
   */
  protected function taxonomies()
  {
    $taxes = apply_filters('epw_get_taxonomies', array());
    /**
     * sort settings by menu_position
     */
    uasort($taxes, function ($a, $b) {
      $first = isset($a['label']) ? $a['label'] : '';
      $second = isset($b['label']) ? $b['label'] : '';
      return strcmp($first, $second);
    });
    return $taxes;
  }

  public function register_taxonomies()
  {
    $this->taxonomies = $this->taxonomies();
    $taxonomies = $this->taxonomies;
    foreach ($taxonomies as $term => $term_data) {
      /**this is in case the term has not been registered */
      if (isset($term_data['name'])) {
        $labels = $args = array();
        $name = __($term_data['name'], 'extend-wp');
        $label = __($term_data['label'], 'extend-wp');
        $labels = array(
          'name' => $name,
          'label' => $label,
          'menu_name' => $label,
          'all_items' => sprintf(__('All %s', 'extend-wp'), $name),
          'edit_item' => sprintf(__('Edit %s', 'extend-wp'), $label),
          'update_item' => sprintf(__('Update %s', 'extend-wp'), $label),
          'add_new_item' => sprintf(__('New %s', 'extend-wp'), $label),
          'new_item_name' => sprintf(__('New %s', 'extend-wp'), $label),
          'parent_item' =>   sprintf(__('Parent %s', 'extend-wp'), $label),
          'parent_item_colon' => sprintf(__('Parent: %s', 'extend-wp'), $label),
          'search_items' => sprintf(__('Search %s', 'extend-wp'), $name),
          'popular_items' => sprintf(__('Popular %s', 'extend-wp'), $name),
          'add_or_remove_items' => sprintf(__('Update/remove %s', 'extend-wp'), $label),
          'choose_from_most_used' => sprintf(__('Select %s', 'extend-wp'), $name),
        );
        $args = array(
          'labels' => $labels,
          'hierarchical' => isset($term_data['hierarchical']) ? $term_data['hierarchical'] :  true,
          'label' => $term,
          'show_ui' => isset($term_data['show_ui']) ? $term_data['show_ui'] : true,
          'query_var' => isset($term_data['query_var']) ? $term_data['query_var'] : true,
          'rewrite' => array(
            'slug' => get_option($term . '_slug') ?: $term,
            'with_front' => false,
          ),
          'show_admin_column' => isset($term_data['show_admin_column']) ? $term_data['show_admin_column'] : false,
          'show_in_rest' =>   true,
        );
        register_taxonomy($term, $term_data['post_types'], $args);
        do_action('ewp_register_taxonomy_action', $term, $term_data);
      }
    }
  }

  /**
   * register the post types
   */
  public function register_post_types()
  {
    $this->post_types = $this->post_types();
    $types = $this->post_types;
    
    if (!empty($types)) {
      foreach ($types as $type) {
        
        $extra_sl = isset($type['extra_slug']) ? '/%' . $type['extra_slug'] . '%' : '';
        $extra_sl = apply_filters('flx_extra_slug_filter', $extra_sl);
        $general_slug = isset($type['slug']) ? $type['slug'] : (get_option($type['post'] . '_slug') ?: $type['post']);
        $chk = (isset($type['flx_custom_template']) && $type['flx_custom_template']) ? true : false;
        $labels = $args = array();
        $type['sn'] = __(ucwords(($type['sn'])), 'extend-wp');
        $type['pl'] = __(ucwords(($type['pl'])), 'extend-wp');
        $labels = array(
          'name' => $type['pl'],
          'singular_name' => $type['sn'],
          'menu_name' => $type['pl'],
          'add_new' => sprintf(__('New %s', 'extend-wp'), $type['sn']),
          'add_new_item' => sprintf(__('New %s', 'extend-wp'), $type['sn']),
          'edit' => __('Edit', 'extend-wp'),
          'edit_item' => sprintf(__('Edit %s', 'extend-wp'), $type['sn']),
          'new_item' => sprintf(__('New %s', 'extend-wp'), $type['sn']),
          'view' => sprintf(__('View %s', 'extend-wp'), $type['sn']),
          'view_item' => sprintf(__('View %s', 'extend-wp'), $type['sn']),
          'search_items' => sprintf(__('Search %s', 'extend-wp'), $type['pl']),
          'not_found' => sprintf(__('No %s found', 'extend-wp'), $type['pl']),
          'not_found_in_trash' => sprintf(__('No %s in trash', 'extend-wp'), $type['pl']),
        );
        $args = array(
          'labels' => $labels,
          'description' => isset($type['description']) ? $type['description'] : '',
          'public' => isset($type['public']) ? $type['public'] : $chk,
          'can_export' => $chk,
          'show_in_nav_menus' => $chk,
          'show_ui' => true,
          'has_archive' => isset($type['public']) ? $type['public'] : $chk,
          'show_in_menu' => isset($type['flx_show_in_menu']) ? 'edit.php?post_type=' . $type['flx_show_in_menu'] : true,
          'exclude_from_search' => !$chk,
          'capability_type' => $type['post'],
          'capabilities' => ewp_create_caps($type['post']),
          'map_meta_cap' => true,
          'hierarchical' => isset($type['hierarchical']) ? $type['hierarchical'] : false,
          'rewrite' => array(
            'slug' => $general_slug . $extra_sl,
            'with_front' => false,
            'pages' => true,
            'feeds' => $chk,
          ),
          'query_var' => true,
          'supports' => $type['args'],
          'show_in_rest' => isset($type['flx_rest']) ? $type['flx_rest'] : $chk,
        );
        if ($extra_sl != '') {
          $args['has_archive'] = $general_slug;
        }

        if (!empty($type['menu_position'])) {
          $args['menu_position'] = 5 + $type['menu_position'];
        }

        if (!empty($type['icn'])) {
          $args['menu_icon'] = $type['icn'];
        }
        register_post_type($type['post'], $args);
        if (isset($type['custom_status']) && !empty($type['custom_status'])) {

          foreach ($type['custom_status'] as $k => $v) {
            register_post_status($k, array(
              'label' => __($k, $type['post']),
              'public' => true,
              'exclude_from_search' => false,
              'show_in_admin_all_list' => true,
              'show_in_admin_status_list' => true,
              'label_count' => _n_noop($v['label'] . '  <span class="count">(%s)</span>', $v['label'] . ' <span class="count">(%s)</span>', 'extend-wp'),
            ));
          }
        }
        do_action('ewp_register_post_type_action', $type, $args);
      }
    }
  }
}


$ewp_setup_content = new EWP_WP_Content_Installer();
$ewp_setup_content->init();