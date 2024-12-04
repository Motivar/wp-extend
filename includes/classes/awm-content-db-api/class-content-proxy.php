<?php
if (!defined('ABSPATH')) {
 exit;
}

class AWM_Content_DB
{
 private static $instance = null;
 private static $registered_content = [];

 /**
  * Singleton pattern to ensure single instance.
  */
 public static function get_instance()
 {
  if (self::$instance === null) {
   self::$instance = new self();
  }
  return self::$instance;
 }

 /**
  * Constructor to set up hooks.
  */
 private function __construct()
 {
  // Hook to initialize content early.
  add_action('plugins_loaded', [$this, 'register_content'], 100);

  // Hook to reinitialize after theme setup.
  add_action('after_setup_theme', [$this, 'register_content_after_theme']);
 }

 /**
  * Initialize content during plugins_loaded.
  */
 public function register_content()
 {
  $this->initialize_content();
 }

 /**
  * Initialize content after theme setup.
  */
 public function register_content_after_theme()
 {
  $this->initialize_content();
 }

 /**
  * Centralized logic to initialize content.
  */
 private function initialize_content()
 {
  $content = $this->awm_register_content_db();
  if (!empty($content)) {
   foreach ($content as $id => $content_structure) {
    // Skip already registered content.
    if (array_key_exists($id, self::$registered_content)) {
     continue;
    }

    // Initialize and track content.
    $setup = new AWM_Add_Content_DB_Setup();
    $setup->init(['key' => $id, 'structure' => $content_structure]);
    self::$registered_content[$id] = $content_structure;
   }
  }
 }

 /**
  * Retrieve all registered content, sorted by order.
  *
  * @return array
  */
 public function awm_register_content_db()
 {
  $content = apply_filters('awm_register_content_db', []);

  // Sort content by the 'order' key.
  uasort($content, function ($a, $b) {
   $first = $a['order'] ?? 100;
   $second = $b['order'] ?? 100;
   return $first - $second;
  });

  return $content;
 }

 /**
  * Get content types formatted for select options.
  *
  * @return array
  */
 public function get_content_types()
 {
  $content = self::$registered_content;
  $options = [];
  foreach ($content as $key => $value) {
   if (isset($value['list_name_singular'])) {
    $id = strtolower(awm_clean_string($key));
    $prefix = isset($value['custom_prefix']) ? $value['custom_prefix'] : 'ewp';
    $contet_id = awm_clean_string($prefix) . '_' . $id;
    $options[$contet_id] = array('label' => $value['list_name_singular']);
   }
  }

  return $options;
 }
}

// Initialize the singleton instance.
AWM_Content_DB::get_instance();