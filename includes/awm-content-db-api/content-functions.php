<?php
if (!defined('ABSPATH')) {
  exit;
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

      $query_args['limit'] = count($query_args['content_ids']);
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
      $inner_query = AWM_DB_Creator::get_filox_db_data($field . '_data', array('content_id'), $meta_where_clause, '', '', 0, true);

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
    $results = AWM_DB_Creator::get_filox_db_data($field . '_main', $query_args['fields'], $where_clause, $query_args['order_by'], $query_args['limit'], 0);


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
    $results = AWM_DB_Creator::get_filox_db_data($table . '_data', $retrieve, $where_clause);
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
