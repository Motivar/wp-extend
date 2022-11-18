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
