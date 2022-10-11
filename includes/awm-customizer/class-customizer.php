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
  $settings = apply_filters('awm_add_customizer_settings_filter', array(), 1);
  /**
   * sort settings by order
   */
  $settings = array(
   'test' => array(
    'title' => __('nikos', 'filox'),
    'order' => 40,
    'capability' => 'edit_theme_options',
    'description' => __('Add custom CSS here'),
    'callback' => 'awm_fields_positions',
   )
  );

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
   require('class-output.php');

   foreach ($customizers as $customizer_id => $customizer_data) {
    $customizer_id = awm_clean_string($customizer_id);
    $fields = awm_callback_library(awm_callback_library_options($customizer_data), $customizer_id);
    if (!empty($fields)) {
     $cap = isset($customizer_data['capability']) ? $customizer_data['capability'] : 'edit_theme_options';
     $wp_customize->add_section(
      $customizer_id,
      array(
       'title'      => $customizer_data['title'],
       //'priority'   => $customizer_data['order'],
       'capability' => $cap,
       'description' => $customizer_data['description'],
       //'panel' => '', // Not typically needed.
       //'theme_supports' => '', // Rarely needed.
      )
     );
     foreach (array_keys($fields) as $field) {
      /**  Logo Image ----------------------------------*/
      $wp_customize->add_setting(
       $field,
       array(
        'capability'        => $cap,
       )
      );
      $control = new Toms_Control_Builder(
       $wp_customize,
       $field,
       array(
        'label' => $field,
        'section'  => $customizer_id,
        'fields' => array($field => $fields[$field])
       )
      );
      $wp_customize->add_control($control);

      /**  Logo Image ----------------------------------*/
      $wp_customize->add_setting(
       'site_logo',
       array(
        'capability'        => 'edit_theme_options',
       )
      );
      $wp_customize->add_control(
       new WP_Customize_Media_Control(
        $wp_customize,
        'site_logo',
        array(
         'label' => __('Website Logo', 'filox'),
         'section'  => $customizer_id,
         'mime_type' => 'image',
        )
       )
      );
     }
    }
   }
  }
 }
}

// Setup the Theme Customizer settings and contro

//new AWM_Customize();
