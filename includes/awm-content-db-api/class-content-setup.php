<?php
if (!defined('ABSPATH')) {
  exit;
}


/**
 * setup the custom content id db
 */

class AWM_Add_Content_DB_Setup
{
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
    add_filter('awm_show_content_value_filter', array($this, 'awm_content_metas_values'), 10, 4);
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
        $where_clause = array(
          "clause" => array(
            array(
              "operator" => "AND",
              "clause" => array(
                array('column' => 'content_id', 'value' => absint($id), 'compare' => '='),
                array('column' => 'meta_key', 'value' => $original_meta, 'compare' => '='),
              ),
            ),
          )
        );
        $results = AWM_DB_Creator::get_db_data($this->table_name_meta, array('meta_value'), $where_clause, '', 1);

        if (isset($results[0]['meta_value']) && !empty($results[0]['meta_value'])) {
          $data = maybe_unserialize(maybe_unserialize($results[0]['meta_value']));
          return $data;
        }
        break;
      case $this->table_name_main:
        if ($original_meta == 'title')
          $original_meta = 'content_title';
        $where_clause = array(
          "clause" => array(
            array(
              "clause" => array(
                array('column' => 'content_id', 'value' => $id, 'compare' => '=')
              ),
            ),
          )
        );
        $results = AWM_DB_Creator::get_db_data($view, '*', $where_clause);

        if (isset($results[0][$original_meta]) && !empty($results[0][$original_meta])) {
          $data = maybe_unserialize($results[0][$original_meta]);
          return $data;
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
              'user_id' => 'bigint unsigned NOT NULL'
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
    $metaboxes = $this->get_extra_metaboxes();
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

    $metaboxes = array(
      'basics' => array(
        'title' => __('Basics', 'filox'),
        'context' => 'normal',
        'priority' => 'high',
        'library' => array(
          'title' => array(
            'case' => 'input',
            'type' => 'text',
            'label' => __('Title', 'awm'),
            'label_class' => array('awm-needed'),
            'attributes' => array('placeholder' => __('add your title', 'extend-wp'))
          ),
          'status' => array(
            'case' => 'select',
            'label' => __('Status', 'awm'),
            'removeEmpty' => true,
            'options' => $this->get_statuses(),
            'label_class' => array('awm-needed'),
            'admin_list' => 1,
            'restrict_content' => 1
          )
        ),
        'order' => 1,
        'view' => $this->table_name_main
      )
    );

    return $metaboxes + $this->get_extra_metaboxes();
  }

  public function get_extra_metaboxes()
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
   * 
   */
  function awm_custom_content_save($data, $args)
  {
    $id = (!empty($data['table_id']) && $data['table_id'] != 'new') ? $data['table_id'] : false;
    $update = true;
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
      "user_id" => get_current_user_id()
    );

    if (!$id) {
      $result = AWM_DB_Creator::insert_db_data(
        $args['main'],
        $update_data,
        'content_id'
      );
      $id = $result['id'];
      $update = false;
    }
    if ($update) {
      unset($update_data['created']);
      unset($update_data['user_id']);
      $where_clause = array(
        "clause" => array(
          array(
            "clause" => array(
              array('column' => 'content_id', 'value' => $id, 'compare' => '=')
            ),
          ),
        )
      );
      AWM_DB_Creator::update_db_data($args['main'], $update_data, $where_clause);
    }

    foreach ($configuration as $key => $value) {
      $where_clause = array(
        "clause" => array(
          array(
            "operator" => "AND",
            "clause" => array(
              array("column" => "content_id", "value" => $id, "compare" => "="),
              array("column" => "meta_key", "value" => $key, "compare" => "=")
            )
          )
        )
      );
      // Sanitize all required data given in by the user

      $result = AWM_DB_Creator::insert_update_db_data(
        $args['meta'],
        array(
          "content_id" => $id,
          "meta_key" => $key,
          "meta_value" => maybe_serialize($value),
        ),
        $where_clause,
        'meta_id'
      );
    }

    wp_cache_flush();
    awm_delete_transient_all();
    return $id;
  }
}
