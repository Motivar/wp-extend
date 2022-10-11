<?php
if (!defined('ABSPATH')) {
 exit;
}


class AWM_Content_DB
{
 public function __construct()
 {
  add_action('plugins_loaded', array($this, 'register_content'), 100);
 }
 /**
  * check if we have ustom lists to add and meet the expectations
  */
 public function register_content()
 {
  $content = $this->awm_register_content_db();
  if (!empty($content)) {
   foreach ($content as $id => $content_structure) {
    new AWM_Add_Content_DB_Setup(array('key' => $id, 'structure' => $content_structure));
   }
  }
 }

 /**
  * get all the registered options pages
  *
  * @return array
  */
 public function awm_register_content_db()
 {
  $content = apply_filters('awm_register_content_db', array());
  /**
   * sort settings by order
   */
  if (!empty($custom_lists)) {
   uasort($content, function ($a, $b) {
    $first = isset($a['order']) ? $a['order'] : 100;
    $second = isset($b['order']) ? $b['order'] : 100;
    return $first - $second;
   });
  }

  return $content;
 }
}
new AWM_Content_DB();
