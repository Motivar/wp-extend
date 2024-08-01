<?php
if (!defined('ABSPATH')) {
 exit;
}
if (!class_exists('WP_CLI')) {
 return;
}

class WP_CLI_Integration
{
 public function __construct()
 {
  WP_CLI::add_command('ewp delete-cache', [$this, 'awm_delete_transient_all']);
 }

 public function awm_delete_transient_all()
 {
  $action = awm_delete_transient_all();
  if ($action) {
   WP_CLI::success("All ewp cache deleted.");
  }
 }
}

new WP_CLI_Integration();