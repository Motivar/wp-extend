<?php
if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('WP_CLI')) {
  return;
}

/**
 * WP-CLI commands for the generic custom-content DB (`awm_register_content_db`).
 *
 * Every registered content type — `ewp_fields`, `ewp_post_types`,
 * `ewp_taxonomies`, `ewp_search`, and any type a sibling plugin registers —
 * is reachable here. All read/write operations delegate to
 * \EWP\Content\Content_Service, the same implementation
 * used by the `ewp-content`/`ewp-fields`/`ewp-wp-content`/`ewp-search`
 * abilities and by the generic REST create/update routes
 * (AWM_Add_Content_DB_API::insert()/update()), so validation and
 * persistence behave identically across all three surfaces.
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
 * @version    1.0.0
 *
 * @since 1.4.0
 */
class EWP_Content_CLI
{
  /**
   * Shared content service instance.
   *
   * @var \EWP\Content\Content_Service
   */
  private static $service;

  /**
   * Register CLI commands.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function init()
  {
    self::$service = new \EWP\Content\Content_Service();

    \WP_CLI::add_command('ewp content types', [__CLASS__, 'types']);
    \WP_CLI::add_command('ewp content list', [__CLASS__, 'list_items']);
    \WP_CLI::add_command('ewp content get', [__CLASS__, 'get_item']);
    \WP_CLI::add_command('ewp content create', [__CLASS__, 'create']);
    \WP_CLI::add_command('ewp content update', [__CLASS__, 'update']);
    \WP_CLI::add_command('ewp content delete', [__CLASS__, 'delete']);
  }

  /**
   * Resolve and validate the `--type` argument shared by every subcommand.
   *
   * @param array $assoc_args Named arguments, expected to contain `type`.
   *
   * @return string The validated content type id. Exits via WP_CLI::error() when missing/unknown.
   *
   * @since 1.4.0
   */
  private static function require_type($assoc_args)
  {
    $type = isset($assoc_args['type']) ? sanitize_key($assoc_args['type']) : '';

    if (empty($type)) {
      \WP_CLI::error('Missing required --type=<content_type>. Run "wp ewp content types" to list the registered content types.');
    }

    if (null === self::$service->get_config($type)) {
      \WP_CLI::error(sprintf('Unknown content type "%s". Run "wp ewp content types" to list the registered content types.', $type));
    }

    return $type;
  }

  /**
   * Decode the `--meta=<json>` argument into an associative array.
   *
   * @param array $assoc_args Named arguments, optionally containing `meta` as a JSON object string.
   *
   * @return array Decoded meta values, keyed by meta key. Exits via WP_CLI::error() on invalid JSON.
   *
   * @since 1.4.0
   */
  private static function decode_meta($assoc_args)
  {
    if (!isset($assoc_args['meta']) || $assoc_args['meta'] === '') {
      return [];
    }

    $meta = json_decode($assoc_args['meta'], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($meta)) {
      \WP_CLI::error('--meta must be a JSON object, for example --meta=\'{"label":"Example"}\'.');
    }

    return $meta;
  }

  /**
   * Print a WP_Error and exit, or return the value unchanged.
   *
   * @param mixed $result Any value; WP_Error is treated as fatal.
   *
   * @return mixed $result, when it is not a WP_Error.
   *
   * @since 1.4.0
   */
  private static function unwrap($result)
  {
    if (is_wp_error($result)) {
      \WP_CLI::error($result->get_error_message());
    }

    return $result;
  }

  /**
   * List every registered content type.
   *
   * ## OPTIONS
   *
   * [--format=<format>]
   * : Output format (table, json, csv). Default table.
   *
   * ## EXAMPLES
   *
   *     wp ewp content types
   *     wp ewp content types --format=json
   *
   * @param array $args       Positional arguments.
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function types($args, $assoc_args)
  {
    $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
    $types  = self::$service->list_types();

    if (empty($types)) {
      \WP_CLI::success('No content types registered.');
      return;
    }

    $display = [];
    foreach ($types as $type_id => $config) {
      $display[] = [
        'Type'       => $type_id,
        'Label'      => isset($config['list_name']) ? $config['list_name'] : $type_id,
        'Capability' => isset($config['capability']) ? $config['capability'] : '',
        'Writable'   => !empty($config['writable']) ? 'yes' : 'no',
      ];
    }

    \WP_CLI\Utils\format_items($format, $display, ['Type', 'Label', 'Capability', 'Writable']);
  }

  /**
   * List items of a content type.
   *
   * ## OPTIONS
   *
   * --type=<content_type>
   * : The content type id, for example ewp_fields. See "wp ewp content types".
   *
   * [--status=<status>]
   * : Filter by one status key.
   *
   * [--search=<search>]
   * : Filter by title, matched with LIKE.
   *
   * [--limit=<limit>]
   * : Maximum rows to return. Default 20.
   *
   * [--with-meta]
   * : Include each item's meta fields in the output.
   *
   * [--format=<format>]
   * : Output format (table, json, csv). Default table.
   *
   * ## EXAMPLES
   *
   *     wp ewp content list --type=ewp_fields
   *     wp ewp content list --type=ewp_post_types --status=public --format=json
   *
   * @param array $args       Positional arguments.
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function list_items($args, $assoc_args)
  {
    $type = self::require_type($assoc_args);

    $query_args = [
      'limit'     => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 20,
      'with_meta' => isset($assoc_args['with-meta']),
    ];

    if (!empty($assoc_args['status'])) {
      $query_args['status'] = [sanitize_text_field($assoc_args['status'])];
    }
    if (!empty($assoc_args['search'])) {
      $query_args['search'] = sanitize_text_field($assoc_args['search']);
    }

    $result = self::unwrap(self::$service->list_items($type, $query_args));
    $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

    if (empty($result['items'])) {
      \WP_CLI::success('No items found matching the criteria.');
      return;
    }

    $display = array_map(function ($item) {
      return [
        'ID'       => $item['id'],
        'Title'    => $item['title'],
        'Status'   => $item['status'],
        'Modified' => $item['modified'],
      ];
    }, $result['items']);

    \WP_CLI\Utils\format_items($format, $display, ['ID', 'Title', 'Status', 'Modified']);
  }

  /**
   * Get one item with its meta.
   *
   * ## OPTIONS
   *
   * <id>
   * : The item id.
   *
   * --type=<content_type>
   * : The content type id, for example ewp_fields. See "wp ewp content types".
   *
   * [--format=<format>]
   * : Output format (table, json, csv, yaml). Default json.
   *
   * ## EXAMPLES
   *
   *     wp ewp content get 42 --type=ewp_fields
   *
   * @param array $args       Positional arguments: [id].
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function get_item($args, $assoc_args)
  {
    $type   = self::require_type($assoc_args);
    $id     = absint($args[0]);
    $result = self::unwrap(self::$service->get_item($type, $id));
    $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'json';

    \WP_CLI::print_value($result, ['format' => $format]);
  }

  /**
   * Create an item.
   *
   * ## OPTIONS
   *
   * --type=<content_type>
   * : The content type id, for example ewp_fields. See "wp ewp content types".
   *
   * --title=<title>
   * : The item title.
   *
   * [--status=<status>]
   * : The status to assign. Defaults to the content type's first registered status.
   *
   * [--meta=<json>]
   * : Meta field values as a JSON object, for example '{"label":"Example"}'.
   *
   * ## EXAMPLES
   *
   *     wp ewp content create --type=ewp_fields --title="Homepage fields" --meta='{"case":"input"}'
   *
   * @param array $args       Positional arguments.
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function create($args, $assoc_args)
  {
    $type   = self::require_type($assoc_args);
    $title  = isset($assoc_args['title']) ? sanitize_text_field($assoc_args['title']) : '';
    $status = isset($assoc_args['status']) ? sanitize_text_field($assoc_args['status']) : '';
    $meta   = self::decode_meta($assoc_args);

    $result = self::unwrap(self::$service->create_item($type, $title, $status, $meta));

    \WP_CLI::success(sprintf('Created %s item #%d.', $type, $result['id']));
  }

  /**
   * Update an item. Only the options passed are changed (patch semantics).
   *
   * ## OPTIONS
   *
   * <id>
   * : The item id.
   *
   * --type=<content_type>
   * : The content type id, for example ewp_fields. See "wp ewp content types".
   *
   * [--title=<title>]
   * : New title.
   *
   * [--status=<status>]
   * : New status.
   *
   * [--meta=<json>]
   * : Meta field values to merge, as a JSON object.
   *
   * ## EXAMPLES
   *
   *     wp ewp content update 42 --type=ewp_fields --status=disabled
   *
   * @param array $args       Positional arguments: [id].
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function update($args, $assoc_args)
  {
    $type = self::require_type($assoc_args);
    $id   = absint($args[0]);

    $patch = [];
    if (array_key_exists('title', $assoc_args)) {
      $patch['title'] = sanitize_text_field($assoc_args['title']);
    }
    if (array_key_exists('status', $assoc_args)) {
      $patch['status'] = sanitize_text_field($assoc_args['status']);
    }
    if (isset($assoc_args['meta'])) {
      $patch['meta'] = self::decode_meta($assoc_args);
    }

    self::unwrap(self::$service->update_item($type, $id, $patch));

    \WP_CLI::success(sprintf('Updated %s item #%d.', $type, $id));
  }

  /**
   * Delete one or more items.
   *
   * ## OPTIONS
   *
   * <id>...
   * : One or more item ids.
   *
   * --type=<content_type>
   * : The content type id, for example ewp_fields. See "wp ewp content types".
   *
   * ## EXAMPLES
   *
   *     wp ewp content delete 42 --type=ewp_fields
   *     wp ewp content delete 12 13 14 --type=ewp_post_types
   *
   * @param array $args       Positional arguments: [id, ...].
   * @param array $assoc_args Named arguments.
   *
   * @return void
   *
   * @since 1.4.0
   */
  public static function delete($args, $assoc_args)
  {
    $type = self::require_type($assoc_args);
    $ids  = array_map('absint', $args);

    $result = self::unwrap(self::$service->delete_items($type, $ids));

    \WP_CLI::success(sprintf('Deleted %d %s item(s).', $result['count'], $type));
  }
}

EWP_Content_CLI::init();
