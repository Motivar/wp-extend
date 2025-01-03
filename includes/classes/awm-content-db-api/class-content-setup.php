<?php
if (!defined('ABSPATH')) {
  exit;
}


/**
 * setupσ the custom content id db
 */

require_once 'class-content-rest-api.php';
class AWM_Add_Content_DB_Setup
{
  /*
  var ewp_data_configuration all the data related to lists table to use in save function
  */
  public static $ewp_data_configuration = array();

  private $data_id;
  private $prefix;
  private $data_content;
  private $table_name_main;
  private $table_name_meta;
  private $table_version;
  private $content_id;
  private $meta_table_data;
  private $main_table_data;
  private $item_meta;
  private $main_columns;
  private $list;
  private $main_table_data_version = 0.1;
  private $meta_table_data_version = 0.1;
  public function init($args)
  {
    $this->get_table_conf($args);
    add_action('admin_init', [$this, 'on_load']);
    add_filter('awm_custom_lists_view_filter', [$this, 'set_custom_list'], PHP_INT_MAX);
    add_filter('ewp_column_' . $this->content_id . '_column_content_filter', [$this, 'awm_content_metas_columns'], 10, 3);
    add_action('rest_api_init', [$this, 'rest_endpoints'], 10);
  }

  /**
   * check if we are going to disable rest otherwise contsruct the endpoints
   */
  public function rest_endpoints()
  {
    $class = new AWM_Add_Content_DB_API($this->content_id, $this->data_content + $this->list);
    /*register the default crud method*/
    $default_rest = array(
      'view' => array(
        'endpoint' => $this->data_id,
        'namespace' =>  $this->prefix,
        'method' => 'get',
        'php_callback' => [$class, 'get_results'],
      ),
      'view_single' => array(
        'endpoint' => $this->data_id . '/(?P<id>\d+)',
        'namespace' =>  $this->prefix,
        'method' => 'get',
        'php_callback' => [$class, 'get_results'],
        'args' => array(
          'id' => array(
            'description'       => sprintf(__('The id of the %s content object', 'ewp'), $this->data_content['list_name_singular']),
            'type'              => 'int',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'required' => true
          )
        )
      ),
      'create' => array(
        'endpoint' => $this->data_id . '/create/',
        'namespace' =>  $this->prefix,
        'method' => 'post',
        'php_callback' => [$class, 'insert'],
        'permission_callback' => 'ewp_rest_check_user_is_admin'
      ),
      'update' => array(
        'endpoint' => $this->data_id . '/update/(?P<id>\d+)',
        'namespace' =>  $this->prefix,
        'method' => 'post',
        'args' => array(
          'id' => array(
            'description'       => sprintf(__('The id of the %s content object', 'ewp'), $this->data_content['list_name_singular']),
            'type'              => 'int',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'required' => true
          )
        ),
        'php_callback' => [$class, 'update'],
        'permission_callback' => 'ewp_rest_check_user_is_admin'
      ),
      'delete_single' => array(
        'endpoint' => $this->data_id . '/delete/',
        'namespace' =>  $this->prefix,
        'method' => 'delete',
        'args' => array(
          'ids' => array(
            'description'       => sprintf(__('The ids of the %s content object. You can combine multiple seperated', 'ewp'), $this->data_content['list_name_singular']),
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 0,
            'required' => true
          )
        ),
        'php_callback' => [$class, 'delete'],
        'permission_callback' => 'ewp_rest_check_user_is_admin'
        
      ),
    );
    $d_api = new AWM_Dynamic_API($default_rest);
    $d_api->register_routes();
  }



  /**
   * set the basics for tables indide the class
   */
  public function get_table_conf($args)
  {
    $this->data_content = $args['structure'];
    $this->data_id = strtolower(awm_clean_string($args['key']));
    $this->prefix = isset($this->data_content['custom_prefix']) ? $this->data_content['custom_prefix'] : 'ewp';
    $this->content_id = $this->prefix . '_' . $this->data_id;
    $this->table_name_main = $this->content_id . '_main';
    $this->table_name_meta = $this->content_id . '_data';
    $this->table_version = isset($this->data_content['version']) ? $this->data_content['version'] : 1;
    $this->table_version = $this->table_version . '.' . $this->main_table_data_version . '.' . $this->meta_table_data_version;
    $this->main_table_data = isset($this->data_content['main_table_data']) ? $this->data_content['main_table_data'] : array();
    $this->meta_table_data = isset($this->data_content['meta_table_data']) ? $this->data_content['meta_table_data'] : array();



    $this->list = array(
      'parent' => isset($this->data_content['parent']) ? $this->data_content['parent'] : '',
      'show_new' => isset($this->data_content['show_new']) ? $this->data_content['show_new'] : true,
      'disable_new' => isset($this->data_content['disable_new']) ? $this->data_content['disable_new'] : false,
      'list_name' => $this->data_content['list_name'],
      'status' => $this->get_statuses(),
      'db_search_key' => 'content_id',
      'title_key' => 'content_title',
      'list_name_singular' => $this->data_content['list_name_singular'],
      'columns' => $this->get_columns(),
      'table_name' => $this->table_name_main,
      'table_name_meta' => $this->table_name_meta,
      'results_per_page' => 50,
      'is_data_encrypted' => false,
      'capability' => isset($this->data_content['capability']) ? $this->data_content['capability'] : 'edit_posts',
      'metaboxes' => $this->get_metaboxes(),
      'save_columns' => $this->main_table_columns()
    );
    self::$ewp_data_configuration[$this->content_id] = $this->list;
  }


  public function awm_content_metas_columns($content, $item, $column)
  {
    switch ($column) {
      default:
        if (isset($this->main_columns[$column])) {
          $key = isset($this->main_columns[$column]['sql_key']) ? $this->main_columns[$column]['sql_key'] : $column;
          return  awm_display_meta_value($column, $this->main_columns[$column],  0, $item[$key]);
        }
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
        if (isset($this->item_meta[$id])) {
          return isset($this->item_meta[$id][$original_meta]) ? $this->item_meta[$id][$original_meta] : false;
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
          $this->item_meta[$id] = $final_results;
          return isset($this->item_meta[$id][$original_meta]) ? $this->item_meta[$id][$original_meta] : false;
        }
        break;
    }

    return $val;
  }


  private function main_table_columns()
  {
    if ($this->main_columns !== null) {
      return $this->main_columns;
    }
    $default_columns =
      array(
        'content_id' => array(
          'sql' => 'bigint(20) NOT NULL AUTO_INCREMENT'
        ),
        'title' => array(
          'sql' => 'longtext NOT NULL',
          'label' => __('Title', 'extend-wp'),
          'admin_list' => true,
          'sortable' => 'content_title',
          'sql_key' => 'content_title',
          'searchable' => true,
        ),
        'ewp_date' => array(
          'label' => __('Created', 'extend-wp'),
          'sql' => 'datetime NOT NULL',
          'admin_list' => true,
          'sortable' => 'created',
          'sql_key' => 'created'
        ),
        'modified' => array(
          'sql' => 'datetime NOT NULL',
        ),
        'status' => array(
          'sql' => 'LONGTEXT NOT NULL',
          'case' => 'select',
          'options' => $this->get_statuses(),
          'required' => true,
        ),
        'user_id' => array(
          'sql' => 'bigint unsigned'
      ),
      'hash' => array(
        'sql' => 'varchar(100) NOT NULL',
      ),
      );

    $extend = isset($this->main_table_data['data']) ? $this->main_table_data['data'] : array();
    $columns = array_replace_recursive($default_columns, $extend);
    $this->main_columns = $columns;
    return $columns;
  }

  private function prepare_sql_columns($columns)
  {

    $sql = array();
    foreach ($columns as $key => $value) {
      if (isset($value['sql']) && !empty($value['sql'])) {
        $sql_key = isset($value['sql_key']) ? $value['sql_key'] : $key;
        $sql[$sql_key] = $value['sql'];
      }
    }
    return $sql;
  }

  private function main_table()
  {
    $defaults = array(
      'data' => $this->prepare_sql_columns($this->main_table_columns()),
      'primaryKey' => 'content_id',
      'index' => 'content_id',
      'foreignKey' => array(array(
        "key" => "user_id",
        "ref" => "users(ID)"
      )),
      'version' => $this->table_version,
    );

    if (empty($this->main_table_data)) {
      return $defaults;
    }

    $extend = $this->main_table_data;
    $extend['data'] = array();

    return array_replace_recursive($defaults, $extend);
  }

  private function meta_table()
  {
    $defaults = array(
      'data' => array(
        'content_id' => 'bigint(20) NOT NULL',
        'meta_key' => 'varchar(100) NOT NULL',
        'meta_value' => 'LONGTEXT',
      ),
      'primaryKey' => 'content_id,meta_key',
      'index' => 'meta_key',
      'foreignKey' => array(array(
        "key" => "content_id",
        "ref" => "$this->table_name_main(content_id)"
      )),
      'version' => $this->table_version,
    );
    if (empty($this->meta_table_data)) {
      return $defaults;
    }
    $extend = $this->meta_table_data;
    $extend['data'] = array();
    if (isset($this->meta_table_data['data'])) {
      foreach ($this->meta_table_data['data'] as $key => $value) {
        $extend['data'][$key] = $value['sql'];
      }
    }
    return array_replace_recursive($defaults, $extend);
  }

  public function on_load()
  {
    new AWM_DB_Creator(
      apply_filters(
        'awm_content_db_setup_data_filter',
        array(
          $this->table_name_main => $this->main_table(),
          $this->table_name_meta => $this->meta_table(),
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

  public function get_columns()
  {
    $defaults = array();
    $all_columns = $this->main_table_columns();
    foreach ($all_columns as $key => $data) {
      if (isset($data['admin_list'])) {
        //$key = isset($data['sql_key']) ? $data['sql_key'] : $key;
        $defaults[$key] = $data;
      }
    }
    if (!isset($this->data_content['columns'])) {
      return $defaults;
    }

    return array_replace_recursive($defaults, $this->data_content['columns']);
  }

  /**
   * add the custom list
   */
  public function set_custom_list($lists)
  {
    $lists[$this->content_id] = $this->list;
    return $lists;
  }
}