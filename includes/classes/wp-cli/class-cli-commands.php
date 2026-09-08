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

 /**
  * Flush all Extend WP caches.
  *
  * Calls the same ewp_flush_cache() used by the `ewp-system/flush-cache`
  * ability, so both surfaces do the same work: transient groups, the
  * user-capabilities version bump, rewrite rules and the object cache.
  */
 public function awm_delete_transient_all()
 {
  $action = ewp_flush_cache();
  if ($action) {
   WP_CLI::success("All ewp cache deleted.");
  }
 }
}

new WP_CLI_Integration();