<?php
if (!defined('ABSPATH')) {
  exit;
}


if (!function_exists('awm_insert_db_content')) {
  /**
   * with this function we insert data to our custom content objects
   * similar to get posts
   * @param string $field the db object to search
   * @param array $args similar structure to get_posts
   *@return int/boolean the id if completed successfully otherwise false
   */

  function awm_insert_db_content($field, $args)
  {
    if (empty($field) || empty($args)) {
      return false;
    }
    $table = $field . '_main';
    $where_clause = array();
    if (isset($args['content_id']) && !empty($args['content_id']) && $args['content_id']) {
      unset($args['created']);
      unset($args['user_id']);
      $where_clause = array(
        "clause" => array(
          array(
            "clause" => array(
              array('column' => 'content_id', 'value' => $args['content_id'], 'compare' => '=')
            ),
          ),
        )
      );
    }
    $result = AWM_DB_Creator::insert_update_db_data($table, $args, $where_clause, 'content_id');
    if (!empty($where_clause) && $result) {
      return $args['content_id'];
    }
    if (isset($result['id'])) {
      return $result['id'];
    }
    return false;
  }
}



if (!function_exists('awm_insert_db_content_meta')) {
  /**
   * with this function we insert data to our custom content objects data table
   * similar to get posts
   * @param string $field the db object to search
   * @param int the id of the content to update
   * @param array $metas the array of the metas to update like (array('key'=>'value))
   * @return boolean true/false if completed successfully or not
   */

  function awm_insert_db_content_meta($field, $id, $metas)
  {
    if (empty($field) || empty($id) || empty($metas)) {
      return false;
    }
    $table = $field . '_data';
    $where_clause = array();
    foreach ($metas as $key => $value) {
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
      AWM_DB_Creator::insert_update_db_data(
        $table,
        array(
          "content_id" => $id,
          "meta_key" => $key,
          "meta_value" => maybe_serialize($value),
        ),
        $where_clause
      );
    }
    return true;
  }
}

if (!function_exists('awm_get_db_content')) {
  /**
   * similar to get posts
   * @param string $field the db object to search
   * @param array $args similar structure to get_posts
   *@return array $results all the results
   **/
  function awm_get_db_content($field, $args = array())
  {
    $defaults = array(
      'fields' => '*', /*accept array with certain fields*/
      'limit' => -1, /*accept -1*/
      'order_by' => array('column' => 'created', 'type' => 'desc'),
      'include' => array(),
      'meta_query' => array()
    );

    $query_args = array_merge($defaults, $args);
    $wheres = array();
    $where_clause = '';
    if (!empty($query_args['status'])) {
      $query_args['status'] = !is_array($query_args['status']) ? array($query_args['status']) : $query_args['status'];
      $wheres[]
        = array('column' => 'status', 'value' =>  "('" . implode("','", $query_args['status']) . "')", 'compare' => 'IN');
    }
    if (!empty($query_args['include'])) {
      $query_args['include'] = !is_array($query_args['include']) ? array($query_args['include']) : $query_args['include'];
      $wheres[]
        = array('column' => 'content_id', 'value' =>  "('" . implode("','", $query_args['include']) . "')", 'compare' => 'IN');

      $query_args['limit'] = count($query_args['include']);
    }

    if (isset($query_args['meta_query']) && !empty($query_args['meta_query'])) {
      $operator = isset($query_args['meta_query']['relation']) ? $query_args['meta_query']['relation'] : 'AND';
      if (isset($query_args['meta_query']['relation'])) {
        unset($query_args['meta_query']['relation']);
      }
      $meta_wheres = array();

      foreach ($query_args['meta_query'] as $query) {
        $meta_wheres[] = array(
          'operator' => 'AND',
          'clause' => array(
            array('column' => 'meta_key', 'value' => $query['key'], 'compare' => '='),
            array('column' => 'meta_value', 'value' => $query['value'], 'compare' => $query['compare'])
          )
        );
      }
      if (!empty($meta_wheres)) {
        $meta_where_clause = array(
          "clause" => array(
            array(
              "operator" => $operator,
              "clause" => $meta_wheres
            )
          )
        );
      }
      $inner_query = AWM_DB_Creator::get_db_data($field . '_data', array('content_id'), $meta_where_clause, '', '', 0, true);
      $wheres[] = array('column' => 'content_id', 'value' =>  '(' . $inner_query . ')', 'compare' => 'IN');
    }
    $results = array();

    if (empty($field)) {
      return array();
    }
    if (!empty($wheres)) {
      $where_clause = array(
        "clause" => array(
          array(
            "operator" => "AND",
            "clause" => $wheres
          ),
        )
      );
    }
    $results = AWM_DB_Creator::get_db_data($field . '_main', $query_args['fields'], $where_clause, $query_args['order_by'], $query_args['limit'], 0);


    return $results;
  }
}



if (!function_exists('awm_get_db_content_meta')) {
  function awm_get_db_content_meta($table = '', $content_id = '', $meta_key = '')
  {
    if (empty($table) || empty($content_id)) {
      return false;
    }
    $single = false;
    $where_clause = array(
      "clause" => array(
        array(
          "operator" => "AND",
          "clause" => array(
            array('column' => 'content_id', 'value' => absint($content_id), 'compare' => '=')
          ),
        ),
      )
    );
    $retrieve = array('meta_key', 'meta_value');
    if (!empty($meta_key)) {
      $where_clause['clause'][0]['clause'][] = array('column' => 'meta_key', 'value' => $meta_key, 'compare' => '=');
      $retrieve = array('meta_value');
      $single = true;
    }
    $return = array();
    $results = AWM_DB_Creator::get_db_data($table . '_data', $retrieve, $where_clause);
    if (empty($results)) {
      return false;
    }
    foreach ($results as $result) {
      if ($single) {
        return maybe_unserialize(maybe_unserialize($result['meta_value']));
      }
      $return[$result['meta_key']] = maybe_unserialize(maybe_unserialize($result['meta_value']));
    }
    return $return;
  }
}






if (!function_exists('awm_custom_content_delete')) {
  /**
   * with this function we delte the data and the relations
   * @param string $field the object to type to delete the data
   * @param array $ids
   * 
   */
  function awm_custom_content_delete($field, $ids = array())
  {
    if (!empty($ids)) {
      $where_clause = array(
        "clause" => array(
          array(
            "operator" => "AND",
            "clause" => array(
              array('column' => 'content_id', 'value' => '(' . implode(',', $ids) . ')', 'compare' => 'IN')
            ),
          ),
        )
      );
      /*action to run before delete*/
      do_action($field . '_pre_delete_action', $ids, $where_clause);
      /*make the deletes*/
      AWM_DB_Creator::delete_db_data($field . '_data', $where_clause);
      AWM_DB_Creator::delete_db_data($field . '_main', $where_clause);
      /*action to run after delete*/
      do_action($field . '_after_delete_action', $ids, $where_clause);
      /*cache flushes and transients*/
      wp_cache_flush();
      awm_delete_transient_all();
      return true;
    }
    return false;
  }
}

if (!function_exists('awm_main_table_data')) {
  /**
   * with this function we set the main table to update
   * @param string $id the object id
   * @param array $data the data posted to be saved
   * 
   */
  function awm_main_table_data($id, $data)
  {
    $setup = AWM_Add_Content_DB_Setup::$ewp_data_configuration[$id];
    $main_data_structure = $setup['save_columns'];
    $main_data = array(
      "modified" => current_time('mysql'),
      "user_id" => get_current_user_id(),
    );
    $exclude = array();
    foreach ($main_data_structure as $key => $conf) {
      if (isset($conf['sql'])) {
        if (isset($conf['required']) && !isset($data[$key])) {
          return false;
        }
        if (isset($data[$key])) {
          $sql_key = isset($conf['sql_key']) ? $conf['sql_key'] : $key;
          $main_data[$sql_key] = $data[$key];
          $exclude[] = $key;
        }
      }
    }
    if ($main_data['content_id'] === 'new' || !$main_data['content_id']) {
      $main_data['created'] = current_time('mysql');
      unset($main_data['content_id']);
    }
    return apply_filters('awm_main_table_data_filter', array('table_data' => $main_data, 'exclude' => $exclude), $id, $data);
  }
}



if (!function_exists('awm_meta_table_data')) {
  /**
   * with this function we set the main table to update
   * @param string $id the object id
   * @param array $data the data posted to be saved
   * @param array $exlude the data to excluded from meta array
   * 
   */
  function awm_meta_table_data($id, $data, $exclude)
  {
    $metas = array();
    if (!isset($data['awm_custom_meta'])) {
      return array();
    }
    foreach ($data['awm_custom_meta'] as $key) {
      if (!in_array($key, $exclude)) {
        $metas[$key] = isset($data[$key]) ? $data[$key] : false;
      }
    }
    return apply_filters('awm_meta_table_data_filter', $metas, $id, $data, $exclude);
  }
}





if (!function_exists('awm_custom_content_save')) {
  /**
   * with this function we set the save action
   * @param string $id the content object to user
   * @param array $data the data from the content object
   * 
   */
  function awm_custom_content_save($id, $data)
  {
    $main_table_data = awm_main_table_data($id, $data);
    if (is_wp_error($main_table_data)) {
      return new WP_Error('missing_fields', __('Missing fields ', 'extend-wp'));
    }
    $metas = awm_meta_table_data($id, $data, $main_table_data['exclude']);
    $object_id = awm_insert_db_content($id, $main_table_data['table_data']);
    awm_insert_db_content_meta($id, $object_id, $metas);
    do_action($id . '_save_action', $object_id, $data);
    wp_cache_flush();
    awm_delete_transient_all();
    return $object_id;
  }
}