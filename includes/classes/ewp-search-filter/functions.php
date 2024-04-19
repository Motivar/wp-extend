<?php
if (!defined('ABSPATH')) {
 exit;
}

if (!function_exists('ewp_search_sorting_filter')) {
 function ewp_search_sorting_filter($configuration)
 {
  if (empty($configuration) || !isset($configuration['options']) || empty($configuration['options'])) {
   return array();
  }
  $options = $configuration['options'];
  $box_options = array();
  foreach ($options as $option) {
   $box_options[$option['awm_key']] = array('label' => $option['label']);
  }
  $box = array(
   'ewp_sorting' => array(
    'label' => __('Sorting', 'extend-wp'),
    'case' => 'select',
    'options' => $box_options
   )
  );
  return apply_filters('ewp_search_sorting_filter', $box);
 }
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
    'post' => array(
     'label' => __('WP Post object', 'extend-wp'),
     'field-choices' => array(
      'search_type' => array(
       'label' => __('Search type', 'extend-wp'),
       'case' => 'select',
       'options' => array(
        'search' => array('label' => __('Search text', 'extend-wp')),
        'date_from' => array('label' => __('Publish date from', 'extend-wp')),
        'date_to' => array('label' => __('Publish date to', 'extend-wp')),
        'orderby' => array('label' => __('Order by', 'extend-wp')),
        'order' => array('label' => __('Order', 'extend-wp')),
       ),
       'label_class' => array('awm-needed'),
      )
     )
    ),
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