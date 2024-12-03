<?php
if (!defined('ABSPATH')) {
 exit;
}
class AWM_Content_DB
{
 private static $registered_ids = [];

 public function __construct()
 {
  // Use plugins_loaded for early setup.
  add_action('plugins_loaded', array($this, 'register_content'), 100);

  // Add another action to trigger functionality after themes are loaded.
  add_action('after_setup_theme', array($this, 'register_content_after_theme'));
 }

 /**
  * Check if we have custom lists to add and meet the expectations.
  */
 public function register_content()
 {
  $this->initialize_content();
 }

 /**
  * Trigger the same functionality after the theme is fully loaded.
  */
 public function register_content_after_theme()
 {
  $this->initialize_content();
 }

 /**
  * Common function to handle the initialization logic.
  */
 private function initialize_content()
 {
  $content = $this->awm_register_content_db();

  if (!empty($content)) {
   foreach ($content as $id => $content_structure) {
    // Skip if this $id has already been registered.
    if (in_array($id, self::$registered_ids, true)) {
     continue;
    }

    // Register the content and track the ID.
    $setup = new AWM_Add_Content_DB_Setup();
    $setup->init(array('key' => $id, 'structure' => $content_structure));
    self::$registered_ids[] = $id;
   }
  }
 }

 /**
  * Get all the registered options pages.
  *
  * @return array
  */
 public function awm_register_content_db()
 {
  $content = apply_filters('awm_register_content_db', array());

  // Sort settings by order if applicable.
  uasort($content, function ($a, $b) {
   $first = isset($a['order']) ? $a['order'] : 100;
   $second = isset($b['order']) ? $b['order'] : 100;
   return $first - $second;
  });

  return $content;
 }
}

new AWM_Content_DB();