<?php
if (!defined('ABSPATH')) {
  exit;
}


/**
 * setup the custom content id db
 */

class AWM_Add_Content_DB_Setup
{
  private static $item_meta = false;
  private $data_id;
  private $data_content;
  private $table_name_main;
  private $table_name_meta;
  private $table_version;
  private $content_id;

  public function __construct($args)
  {
    $this->data_id = $args['key'];
    $this->data_content = $args['structure'];
    $this->set_table_names();
    add_action('admin_init', array($this, 'on_load'));
    add_filter('awm_custom_lists_view_filter', array($this, 'set_custom_list'), PHP_INT_MAX);
    add_filter('awm_column_' . $this->content_id . '_column_content_filter', array($this, 'awm_content_metas_columns'), 10, 3);
  }


  public function set_table_names()
  {
    $prefix = isset($this->data_content['custom_prefix']) ? $this->data_content['custom_prefix'] : 'ewp';
    $this->content_id = $prefix . '_' . strtolower(awm_clean_string($this->data_id));
    $this->table_name_main = $this->content_id . '_main';
    $this->table_name_meta = $this->content_id . '_data';
    $this->table_version = isset($this->data_content['version']) ? $this->data_content['version'] : 1;
  }


  public function awm_content_metas_columns($content, $item, $column)
  {

    switch ($column) {
      case 'status':
        return $this->get_statuses()[$item['status']]['label'];
        break;
      default:
        $content = $this->awm_content_metas_values('', $item['content_id'], $column, $this->table_name_meta);
        $content = awm_display_meta_value($column, $this->get_metabox_conf($column),  0, $content);

        break;
    }
    return $content;
  }



  /**
   * this is the function to return inside the postbox the data from the db
   * @param varchar $val the value expected 0
   * @param int $id the post id
   * @param string $original_meta the meta to recover
   * @param string $view which view we use to return the data
   */
  public function awm_content_metas_values($val, $id, $original_meta, $view)
  {
    switch ($view) {
      case $this->table_name_meta:
        if (isset(self::$item_meta[$id])) {
          return isset(self::$item_meta[$id][$original_meta]) ? self::$item_meta[$id][$original_meta] : false;
        }
        $where_clause = array(
          "clause" => array(
            array(
              "operator" => "AND",
              "clause" => array(
                array('column' => 'content_id', 'value' => absint($id), 'compare' => '='),
              ),
            ),
          )
        );
        $results = AWM_DB_Creator::get_db_data($this->table_name_meta, array('meta_key', 'meta_value'), $where_clause);
        if (!empty($results)) {
          $final_results = array();
          foreach ($results as $result) {
            $final_results[$result['meta_key']] = maybe_unserialize(maybe_unserialize($result['meta_value']));
          }
          self::$item_meta[$id] = $final_results;
          return isset(self::$item_meta[$id][$original_meta]) ? self::$item_meta[$id][$original_meta] : false;
        }
        break;
    }

    return $val;
  }



  public function on_load()
  {
    new AWM_DB_Creator(
      apply_filters(
        'awm_content_db_setup_data_filter',
        array(
          $this->table_name_main => array(
            'data' => array(
              'content_id' => 'bigint(20) NOT NULL AUTO_INCREMENT',
              'content_title' => 'longtext NOT NULL',
              'created' => 'bigint(20) NOT NULL',
              'modified' => 'bigint(20) NOT NULL',
              'status' => 'LONGTEXT NOT NULL',
              'user_id' => 'bigint unsigned'
            ),
            'primaryKey' => 'content_id',
            'foreignKey' => array(array(
              "key" => "user_id",
              "ref" => "users(ID)"
            )),
            'version' => $this->table_version,
          ),
          $this->table_name_meta => array(
            'data' => array(
              'content_id' => 'bigint(20) NOT NULL',
              'meta_key' => 'varchar(255) NOT NULL',
              'meta_value' => 'LONGTEXT',
            ),
            'primaryKey' => 'content_id,meta_key',
            'index' => 'meta_key',
            'foreignKey' => array(array(
              "key" => "content_id",
              "ref" => "$this->table_name_main(content_id)"
            )),
            'version' => $this->table_version,
          ),
        )
      ),
      $this->data_id,
      $this->data_content
    );
  }

  public function get_statuses()
  {
    $statuses = isset($this->data_content['statuses']) ? $this->data_content['statuses'] : array('public' => array('label' => __('Public', 'awm')), 'private' => array('label' => __('Private', 'awm')));
    return $statuses;
  }


  public function get_metabox_conf($meta_key)
  {
    $metaboxes = $this->get_metaboxes();
    foreach ($metaboxes as $metaBoxKey => $metaBoxData) {
      $metaBoxData['library'] = awm_callback_library(awm_callback_library_options($metaBoxData), $metaBoxKey);
      if (!empty($metaBoxData['library'])) {

        if (array_key_exists($meta_key, $metaBoxData['library'])) {
          return $metaBoxData['library'][$meta_key];
        }
      }
    }
    return array();
  }


  public function get_metaboxes()
  {
    $metaboxes = isset($this->data_content['metaboxes']) ? $this->data_content['metaboxes'] : array();
    foreach ($metaboxes as &$metabox_data) {
      if (!isset($metabox_data['view'])) {
        $metabox_data['view'] = $this->table_name_meta;
      }
    }
    return $metaboxes;
  }

  /**
   * add the custom list
   */
  public function set_custom_list($lists)
  {


    $lists[$this->content_id] = array(
      'parent' => isset($this->data_content['parent']) ? $this->data_content['parent'] : '',
      'show_new' => isset($this->data_content['show_new']) ? $this->data_content['show_new'] : true,
      'disable_new' => isset($this->data_content['disable_new']) ? $this->data_content['disable_new'] : false,
      'list_name' => $this->data_content['list_name'],
      'status' => $this->get_statuses(),
      'db_search_key' => 'content_id',
      'title_key' => 'content_title',
      'list_name_singular' => $this->data_content['list_name_singular'],
      'columns' => array(
        'title' => __('Title', 'filox'),
      ),
      'sortable' => array('date' => array('date', true)),
      'table_name' => $this->table_name_main,
      'results_per_page' => 50,
      'is_data_encrypted' => false,
      'capability' => isset($this->data_content['capability']) ? $this->data_content['capability'] : 'edit_posts',
      'save_callback' => 'awm_custom_content_save',
      'save_callback_args' => array('main' => $this->table_name_main, 'meta' => $this->table_name_meta, 'content_id' => $this->content_id),
      'delete_callback' => 'awm_custom_content_delete',
      'delete_callback_args' => array('main' => $this->table_name_main, 'meta' => $this->table_name_meta, 'content_id' => $this->content_id),
      'metaboxes' => $this->get_metaboxes()
    );
    return $lists;
  }
}

if (!function_exists('awm_custom_content_delete')) {
  /**
   * with this function we delte the data and the relations
   * @param array $ids
   * 
   */
  function awm_custom_content_delete($ids, $callback_args)
  {
    if (!empty($ids)) {
      global $wpdb;
      $wpdb->query("DELETE FROM {$wpdb->prefix}" . $callback_args['meta'] . " WHERE content_id IN($ids)");
      $wpdb->query("DELETE FROM {$wpdb->prefix}" . $callback_args['main'] . " WHERE content_id IN($ids)");
    }
  }
}




if (!function_exists('awm_custom_content_save')) {
  /**
   * with this function we set the save action
   * @param array $data the post_data
   * @param array $args the data from the content object
   * @return int $id the id of the object created/updated
   * 
   */
  function awm_custom_content_save($data, $args)
  {
    $id = (!empty($data['table_id']) && $data['table_id'] != 'new') ? $data['table_id'] : false;
    $configuration = array();
    $exclude = array('title',  'status', 'table_id');
    foreach ($data['awm_custom_meta'] as $key) {
      if (!in_array($key, $exclude)) {
        $configuration[$key] = $data[$key];
      }
    }
    $update_data = array(
      "content_title" => $data['title'],
      "status" => $data['status'],
      "created" => current_time('timestamp'),
      "modified" => current_time('timestamp'),
      "user_id" => get_current_user_id(),
      'content_id' => $id,
    );
    $id = awm_insert_db_content($args['content_id'], $update_data);
    awm_insert_db_content_meta($args['content_id'], $id, $configuration);
    wp_cache_flush();
    awm_delete_transient_all();
    return $id;
  }
}
