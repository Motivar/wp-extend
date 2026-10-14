<?php
if (!defined('ABSPATH')) {
 exit;
}

if (!function_exists('ewp_search_sorting_filter')) {
 /**
  * function to show the sorting options available for user to choose and create a form
  * @param array $configuration
  * @param array $params
  */
 function ewp_search_sorting_filter($configuration, $params = array())
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
  if ($configuration['show'] == 'results') {
   $box['ewp_sorting']['attributes']['onchange'] = 'ewp_sorting(this);';
  }
  if (isset($params['ewp_sorting'])) {
   $box['ewp_sorting']['attributes']['value'] = $params['ewp_sorting'];
  }
  return apply_filters('ewp_search_sorting_filter', $box);
 }
}


if (!function_exists('ewp_search_apply_all_choices')) {
 /**
  * settings of the apply-all option, offered for the term and checkbox_multiple
  * filter inputs of a search filter (they apply when the input renders as
  * checkbox_multiple). Stored on the query_fields row and passed to the field
  * as disable_apply_all / apply_all_mode / apply_all_label.
  *
  * @return array field library
  */
 function ewp_search_apply_all_choices()
 {
  return array(
   'apply_all_mode' => array(
    'label' => __('All option behaviour', 'extend-wp'),
    'case' => 'select',
    'removeEmpty' => true,
    'options' => array(
     'toggle' => array('label' => __('Toggle all', 'extend-wp')),
     'exclusive' => array('label' => __('Exclusive "All" tab', 'extend-wp')),
    ),
    'explanation' => __('Multiple checkbox only. Toggle all checks/unchecks every option. Exclusive "All" tab: "All" is checked when nothing else is, unchecks the other options and searches with no filter for this field.', 'extend-wp'),
   ),
   'apply_all_label' => array(
    'label' => __('All option label', 'extend-wp'),
    'case' => 'input',
    'type' => 'text',
    'attributes' => array('placeholder' => __('Select All', 'extend-wp')),
    'explanation' => __('Multiple checkbox only. Translatable through the extend-wp text domain (WPML String Translation).', 'extend-wp'),
   ),
   'disable_apply_all' => array(
    'label' => __('Hide the All option', 'extend-wp'),
    'case' => 'input',
    'type' => 'checkbox',
    'explanation' => __('Multiple checkbox only. Do not show the apply-all option.', 'extend-wp'),
   ),
  );
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
        'and' => array('label' => 'AND'),
       ),
       'explanation' => __('How multiple selected values of this field combine: IN matches any of them, NOT IN excludes them, AND requires the post to have all of them.', 'extend-wp'),
       'label_class' => array('awm-needed'),
      ),
     ),
    )
   )
  );
 }
}