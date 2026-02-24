<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('WP_CLI')) {
	return;
}

/**
 * WP-CLI commands for EWP Options Portability.
 *
 * Provides CLI access for exporting, importing, and listing EWP option pages.
 * Commands are registered under the 'ewp options' namespace.
 *
 * Commands:
 *   wp ewp options export — Export option pages to a JSON file.
 *   wp ewp options import — Import option pages from a JSON file.
 *   wp ewp options list   — List all exportable option pages.
 *
 * @package    EWP\OptionsPortability
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Options_Portability_CLI
{
	/**
	 * Reference to the portability instance.
	 *
	 * @var EWP_Options_Portability
	 */
	private static $portability;

	/**
	 * Initialize CLI commands.
	 *
	 * @param EWP_Options_Portability $portability Portability instance.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function init($portability)
	{
		self::$portability = $portability;

		\WP_CLI::add_command('ewp options export', array(__CLASS__, 'export'));
		\WP_CLI::add_command('ewp options import', array(__CLASS__, 'import_options'));
		\WP_CLI::add_command('ewp options list', array(__CLASS__, 'list_pages'));
	}

	/**
	 * Export option pages to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--pages=<pages>]
	 * : Comma-separated page keys to export. Default: all exportable pages.
	 *
	 * [--file=<file>]
	 * : Output file path. Default: ./ewp-options-export.json
	 *
	 * [--format=<format>]
	 * : Output format: json (file download), table (summary only). Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ewp options export
	 *     wp ewp options export --pages=my-plugin-settings,another-page
	 *     wp ewp options export --file=/tmp/backup.json
	 *     wp ewp options export --format=table
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function export($args, $assoc_args)
	{
		$format = isset($assoc_args['format']) ? $assoc_args['format'] : 'json';
		$file   = isset($assoc_args['file']) ? $assoc_args['file'] : './ewp-options-export.json';

		/* Determine pages to export */
		$page_keys = self::resolve_page_keys($assoc_args);

		if (empty($page_keys)) {
			\WP_CLI::error(__('No exportable option pages found.', 'extend-wp'));
			return;
		}

		\WP_CLI::log(sprintf('Exporting %d option page(s)...', count($page_keys)));

		$data = self::$portability->export_options($page_keys, 'cli');

		if (is_wp_error($data)) {
			\WP_CLI::error($data->get_error_message());
			return;
		}

		/* Summary format — show table only */
		if ($format === 'table') {
			$display = self::build_export_summary_table($data);
			\WP_CLI\Utils\format_items('table', $display, array('Page', 'Title', 'Fields'));
			return;
		}

		/* JSON format — write to file */
		$json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			\WP_CLI::error(__('Failed to encode export data to JSON.', 'extend-wp'));
			return;
		}

		$result = file_put_contents($file, $json);

		if ($result === false) {
			\WP_CLI::error(sprintf(__('Failed to write to %s.', 'extend-wp'), $file));
			return;
		}

		\WP_CLI::success(sprintf(
			'Exported %d page(s) to %s (%s bytes)',
			count($data['pages']),
			$file,
			number_format($result)
		));
	}

	/**
	 * Import option pages from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON export file.
	 *
	 * [--skip-url-replace]
	 * : Skip URL replacement even if home_url differs.
	 *
	 * [--dry-run]
	 * : Preview what would be imported without making changes.
	 *
	 * [--backup-file=<file>]
	 * : Save a pre-import backup of existing values to this file.
	 *
	 * [--format=<format>]
	 * : Output format: table (default), json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ewp options import /tmp/backup.json
	 *     wp ewp options import backup.json --dry-run
	 *     wp ewp options import backup.json --skip-url-replace
	 *     wp ewp options import backup.json --backup-file=/tmp/pre-import-backup.json
	 *
	 * @param array $args       Positional arguments (file path).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function import_options($args, $assoc_args)
	{
		if (empty($args[0])) {
			\WP_CLI::error(__('Please provide a file path.', 'extend-wp'));
			return;
		}

		$file_path = $args[0];

		if (!file_exists($file_path)) {
			\WP_CLI::error(sprintf(__('File not found: %s', 'extend-wp'), $file_path));
			return;
		}

		$content = file_get_contents($file_path);
		$data    = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			\WP_CLI::error(__('Invalid JSON in the import file.', 'extend-wp'));
			return;
		}

		$dry_run = isset($assoc_args['dry-run']);
		$format  = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

		$opts = array(
			'dry_run'          => $dry_run,
			'skip_url_replace' => isset($assoc_args['skip-url-replace']),
			'actor'            => 'cli',
			'backup_file'      => isset($assoc_args['backup-file']) ? $assoc_args['backup-file'] : null,
		);

		if ($dry_run) {
			\WP_CLI::log('Running in dry-run mode — no changes will be made.');
		}

		/* Show URL diff warning */
		if (!$opts['skip_url_replace'] && isset($data['home_url']) && $data['home_url'] !== home_url()) {
			\WP_CLI::warning(sprintf(
				'URL difference detected: export=%s, current=%s. URLs will be replaced.',
				$data['home_url'],
				home_url()
			));
		}

		/* Show version mismatch warning */
		if (isset($data['plugin_version'])) {
			$import_major  = intval(explode('.', $data['plugin_version'])[0]);
			$current_major = intval(explode('.', self::get_plugin_version())[0]);

			if ($import_major !== $current_major) {
				\WP_CLI::warning(sprintf(
					'Plugin version mismatch: export=%s, current=%s',
					$data['plugin_version'],
					self::get_plugin_version()
				));
			}
		}

		$result = self::$portability->import_options($data, $opts);

		if (is_wp_error($result)) {
			\WP_CLI::error($result->get_error_message());
			return;
		}

		/* Display results */
		if ($format === 'json') {
			\WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT));
			return;
		}

		self::display_import_summary($result);
	}

	/**
	 * List all exportable option pages.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table (default), json, csv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ewp options list
	 *     wp ewp options list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function list_pages($args, $assoc_args)
	{
		$format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
		$pages  = self::$portability->get_exportable_pages();

		if (empty($pages)) {
			\WP_CLI::success(__('No exportable option pages found.', 'extend-wp'));
			return;
		}

		$display = array();

		foreach ($pages as $key => $data) {
			$display[] = array(
				'Page Key'    => $key,
				'Title'       => $data['title'],
				'Field Count' => count($data['fields']),
			);
		}

		\WP_CLI\Utils\format_items($format, $display, array('Page Key', 'Title', 'Field Count'));
	}

	/* =========================================================
	 * Section: CLI Helpers
	 * ========================================================= */

	/**
	 * Resolve page keys from CLI arguments.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return array Array of page key strings.
	 *
	 * @since 1.0.0
	 */
	private static function resolve_page_keys($assoc_args)
	{
		$all_pages = self::$portability->get_exportable_pages();

		if (isset($assoc_args['pages']) && !empty($assoc_args['pages'])) {
			return array_map('trim', explode(',', $assoc_args['pages']));
		}

		return array_keys($all_pages);
	}

	/**
	 * Build export summary table data.
	 *
	 * @param array $data Export payload.
	 * @return array Array of table rows.
	 *
	 * @since 1.0.0
	 */
	private static function build_export_summary_table($data)
	{
		$display = array();

		foreach ($data['pages'] as $key => $page) {
			$display[] = array(
				'Page'   => $key,
				'Title'  => $page['title'],
				'Fields' => $page['field_count'],
			);
		}

		return $display;
	}

	/**
	 * Display import summary as CLI table with status messages.
	 *
	 * @param array $result Import summary from import_options().
	 * @return void
	 *
	 * @since 1.0.0
	 */
	private static function display_import_summary($result)
	{
		$prefix = $result['dry_run'] ? '[DRY-RUN] ' : '';

		/* Summary stats */
		$stats = array(
			array('Metric' => 'Pages Imported', 'Value' => count($result['pages_imported'])),
			array('Metric' => 'Pages Skipped', 'Value' => count($result['pages_skipped'])),
			array('Metric' => 'Fields Imported', 'Value' => $result['fields_imported']),
			array('Metric' => 'Fields Skipped', 'Value' => $result['fields_skipped']),
			array('Metric' => 'URL Replace Applied', 'Value' => $result['url_replace_applied'] ? 'Yes' : 'No'),
			array('Metric' => 'URL Replacement Pairs', 'Value' => $result['url_replacements_count']),
			array('Metric' => 'Backup Created', 'Value' => $result['backup_created'] ? 'Yes' : 'No'),
		);

		\WP_CLI\Utils\format_items('table', $stats, array('Metric', 'Value'));

		/* Warnings */
		if (!empty($result['warnings'])) {
			foreach ($result['warnings'] as $warning) {
				\WP_CLI::warning($prefix . $warning);
			}
		}

		/* Final status */
		if ($result['dry_run']) {
			\WP_CLI::success('Dry-run completed. No changes were made.');
			return;
		}

		if (empty($result['pages_skipped'])) {
			\WP_CLI::success(sprintf(
				'Import completed: %d pages, %d fields imported.',
				count($result['pages_imported']),
				$result['fields_imported']
			));
			return;
		}

		\WP_CLI::warning(sprintf(
			'Import completed with warnings: %d pages imported, %d skipped.',
			count($result['pages_imported']),
			count($result['pages_skipped'])
		));
	}

	/**
	 * Get the current plugin version.
	 *
	 * @return string Plugin version string.
	 *
	 * @since 1.0.0
	 */
	private static function get_plugin_version()
	{
		static $version = null;

		if ($version !== null) {
			return $version;
		}

		$plugin_file = awm_path . 'extend-wp.php';

		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data($plugin_file, false, false);
		$version     = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

		return $version;
	}
}
