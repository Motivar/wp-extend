<?php
if (!defined('ABSPATH')) {
 exit;
}

/**
 * class to extend customizer through fields
 */



class AWM_Customize
{

 public function __construct()
 {
  add_action('customize_register', array($this, 'register'), 100);
 }

 public function awm_get_customizer_settings()
 {
  /**
   * get all the awm boxes for customizer
   * @param array all the boxes
   * @return array return all the boxes
   */
  $settings = apply_filters('awm_add_customizer_settings_filter', array());
  /**
   * sort settings by order
   */

  uasort($settings, function ($a, $b) {
   $first = isset($a['order']) ? $a['order'] : 100;
   $second = isset($b['order']) ? $b['order'] : 100;
   return $first - $second;
  });
  return $settings;
 }




 /**
  * Register customizer options.
  *
  * @param WP_Customize_Manager $wp_customize Theme Customizer object.
  */
 public function register($wp_customize)
 {

  if (!isset($wp_customize)) {
   return;
  }

  $customizers = $this->awm_get_customizer_settings();
  if (!empty($customizers)) {
   foreach ($customizers as $customizer_id => $customizer_data) {
    if (isset($customizer_data['sections'])) {
     $customizer_id = awm_clean_string($customizer_id);
     $wp_customize->add_panel($customizer_id, array(
      'title' => $customizer_data['title'],
      'description' => isset($customizer_data['description']) ? $customizer_data['description'] : '',
      'priority' => isset($customizer_data['priority']) ? $customizer_data['priority'] : 100,
     ));
     foreach ($customizer_data['sections'] as $section_id => $section_data) {
      $fields = awm_callback_library(awm_callback_library_options($section_data), $section_id);
      if (!empty($fields)) {
       $cap = isset($section_data['capability']) ? $section_data['capability'] : 'edit_theme_options';
       $wp_customize->add_section(
        $section_id,
        array(
         'panel' => $customizer_id,
         'title'       => $section_data['title'],
         'priority'    => $section_data['order'],
         'capability'  => $cap,
         'description' => $section_data['description'],
        )
       );

       foreach ($fields as $field_id => $field_data) {
        /*register the setting*/
        $wp_customize->add_setting(
         $field_id,
         array(
          'capability'        => $cap,
          'type' => isset($field_data['attributes']['customizer_type']) ? $field_data['attributes']['customizer_type'] : 'theme_mod',
          'default' => isset($field_data['attirbutes']['customizer_default']) ? $field_data['attributes']['customizer_default'] : '',
          'sanitize_callback' => isset($field_data['attributes']['customizer_sanitize_callback']) ? $field_data['attributes']['customizer_sanitize_callback'] : ''
         )
        );
        $args = array(
         'label' => $field_data['label'],
         'settings' => $field_id,
         'priority' => $field_data['order'] ?: 100,
         'section' => $section_id,
        );
        switch ($field_data['case']) {

         case 'image':
          $args['mime_type'] = isset($field_data['mime_type']) ? $field_data['mime_type'] : 'image';
          $wp_customize->add_control(
           new WP_Customize_Media_Control(
            $wp_customize,
            $field_id,
            $args
           )
          );
          break;
         default:
          if ($field_data['type'] == 'color') {
           $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $field_id, $args));
           continue;
          }
          $field_data = awm_prepare_field($field_data);
          $args['type'] = $field_data['case'] == 'input' ? $field_data['type'] : $field_data['case'];
          if (isset($field_data['options'])) {
           foreach ($field_data['options'] as $option_id => $option_data) {
            $args['choices'][$option_id] = $option_data['label'];
           }
          }
          $wp_customize->add_control($field_id, $args);
          break;
        }
       }
      }
     }
    }
   }
  }
 }
}

// Setup the Theme Customizer settings and contro

new AWM_Customize();
