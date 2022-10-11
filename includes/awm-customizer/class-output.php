<?php
if (!defined('ABSPATH')) {
 exit;
}

class Toms_Control_Builder extends WP_Customize_Control
{
 protected $fields = array();
 public function __construct($manager, $id, $args = array())
 {
  $this->fields = $args['fields'];
  //parent::construct($manager, $id, $args);

 }

 public function render_content()
 {
  print_r($this->fields);
  echo awm_show_content($this->fields);
  echo 'aaaasdfasfsafsafsaf';
 }
}
