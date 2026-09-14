<?php
if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('WP_CLI')) {
  return;
}

/**
 * `wp ewp content *` entry points.
 *
 * The commands themselves are declared once in
 * EWP\Surfaces\Resources\Content_Resource and registered with WP-CLI by
 * the kit's Cli_Adapter, together with the matching `ewp-content/*`
 * abilities. This class only keeps the static callables (types, list,
 * get, create, update, delete) that the self-test suite and PHPUnit
 * invoke in-process, and forwards each to the same operation.
 *
 * Commands:
 *   wp ewp content types            — List registered content types.
 *   wp ewp content list             — List items of a content type.
 *   wp ewp content get              — Get one item with its meta.
 *   wp ewp content create           — Create an item.
 *   wp ewp content update           — Update an item.
 *   wp ewp content delete           — Delete one or more items.
 *
 * @package    EWP\ContentDB
 * @author     Motivar
 *
 * @since 1.4.0
 */
class EWP_Content_CLI
{
  /**
   * Kept for callers that used to register the commands here; the kit's
   * Cli_Adapter registers them on boot.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function init()
  {
  }

  /** @see Content_Resource `types` */
  public static function types($args, $assoc_args)
  {
    return self::run('types', $args, $assoc_args);
  }

  /** @see Content_Resource `list` */
  public static function list_items($args, $assoc_args)
  {
    return self::run('list', $args, $assoc_args);
  }

  /** @see Content_Resource `get` */
  public static function get_item($args, $assoc_args)
  {
    return self::run('get', $args, $assoc_args);
  }

  /** @see Content_Resource `create` */
  public static function create($args, $assoc_args)
  {
    return self::run('create', $args, $assoc_args);
  }

  /** @see Content_Resource `update` */
  public static function update($args, $assoc_args)
  {
    return self::run('update', $args, $assoc_args);
  }

  /** @see Content_Resource `delete` */
  public static function delete($args, $assoc_args)
  {
    return self::run('delete', $args, $assoc_args);
  }

  /**
   * Run one operation of the `content` resource with CLI arguments.
   *
   * @param string $operation  Operation key.
   * @param array  $args       Positional arguments.
   * @param array  $assoc_args Named arguments.
   *
   * @return mixed The operation result, after printing.
   *
   * @since 1.5.0
   */
  private static function run($operation, $args, $assoc_args)
  {
    $registry = \EWP\Surfaces\EWP_Surfaces::instance()->registry();
    $found    = $registry ? $registry->find('content', $operation) : null;

    if ($found === null) {
      \WP_CLI::error('The content commands are unavailable: the Extend WP surfaces did not boot.');
      return null;
    }

    return \Gnnpls\WP\Adapters\Cli_Adapter::invoke($found, (array) $args, (array) $assoc_args);
  }
}
