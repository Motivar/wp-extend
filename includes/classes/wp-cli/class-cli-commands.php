<?php
if (!defined('ABSPATH')) {
 exit;
}
if (!class_exists('WP_CLI')) {
 return;
}

/**
 * `wp ewp delete-cache` entry point.
 *
 * The command is declared once in EWP\Surfaces\Resources\System_Resource
 * (next to `wp ewp system info`, `POST extend-wp/v1/system/flush-cache`
 * and the `ewp-system/flush-cache` ability) and registered by the kit.
 * This class keeps the callable the self-test suite and PHPUnit invoke.
 */
class WP_CLI_Integration
{
 /**
  * Flush all Extend WP caches through the shared operation.
  *
  * @return mixed
  */
 public function awm_delete_transient_all()
 {
  $registry = class_exists('EWP\\Surfaces\\EWP_Surfaces') ? \EWP\Surfaces\EWP_Surfaces::instance()->registry() : null;
  $found    = $registry ? $registry->find('system', 'flush') : null;

  if ($found === null) {
   WP_CLI::error('The cache command is unavailable: the Extend WP surfaces did not boot.');
   return null;
  }

  return \Motivar\WP\Adapters\Cli_Adapter::invoke($found, [], []);
 }
}
