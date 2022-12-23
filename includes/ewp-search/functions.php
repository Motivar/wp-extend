<?php
if (!defined('ABSPATH')) {
 exit;
}

if (!function_exists('ewp_query_fields')) {
 function ewp_query_fields()
 {
  /**
   * function to show the fields available for user to choose and create a form
   */

  return apply_filters(
   'ewp_query_fields_filter',
   array(
    'post' => array('label' => __('WP Post object', 'extend-wp')),
    'meta' =>  array(
     'label' => __('WP Meta', 'extend-wp'),
     'field-choices' => array(
      'meta_key' => array(
       'label' => __('Meta key', 'extend-wp'),
       'case' => 'input',
       'type' => 'text',
       'label_class' => array('awm-needed'),
      ),
      'meta_compare' => array(
       'label' => __('Compare function', 'extend-wp'),
       'case' => 'input',
       'type' => 'text',
       'label_class' => array('awm-needed'),
      )
     ),
    ),
    'taxonomy' =>  array(
     'label' => __('WP taxonomy', 'extend-wp'),
     'field-choices' => array(
      'compare_type' => array(
       'label' => __('Compare operator', 'extend-wp'),
       'case' => 'select',
       'options' => array(
        'in' => array('label' => 'IN'),
        'not_in' => array('label' => 'NOT IN'),
       ),
       'label_class' => array('awm-needed'),
      ),
     ),
    )
   )
  );
 }
}
