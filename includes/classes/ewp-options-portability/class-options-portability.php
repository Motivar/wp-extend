<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * EWP Options Portability — Export/Import for EWP option pages.
 *
 * Provides admin UI (integrated into existing Import/Export page),
 * REST API endpoints, and WP-CLI commands for exporting and importing
 * option page values registered via awm_add_options_boxes_filter.
 *
 * Features:
 * - Transaction-safe imports with rollback on failure
 * - URL replacement (home_url, site_url, content_url, upload_url, scheme changes)
 * - Serialized data safety (never raw str_replace in serialized blobs)
 * - Enriched logging via ewp_log()
 * - Dry-run mode for previewing imports
 * - Versioning metadata in export payload
 *
 * @package    EWP\OptionsPortability
 * @author     Motivar
 * @version    1.0.0
 *
 * @since 1.0.0
 */
class EWP_Options_Portability
{
	/**
	 * Module version.
	 *
	 * @var string
	 */
	private static $version = '1.0.0';

	/**
	 * Export format version.
	 *
	 * @var string
	 */
	private static $format_version = '1.0';

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private static $rest_namespace = 'extend-wp/v1';

	/**
	 * Cached exportable pages array.
	 *
	 * @var array|null
	 */
	private static $exportable_pages_cache = null;


	/**
	 * Constructor — register all hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		add_action('rest_api_init', [$this, 'register_rest_routes']);
		add_filter('ewp_register_dynamic_assets', [$this, 'register_dynamic_assets']);
		add_action('ewp_logger_initialized', [$this, 'register_log_types']);

		/* Bootstrap CLI commands */
		if (class_exists('WP_CLI')) {
			require_once __DIR__ . '/class-options-portability-cli.php';
			EWP_Options_Portability_CLI::init($this);
		}
	}

	/* =========================================================
	 * Section: Exportable Pages Discovery
	 * ========================================================= */

	/**
	 * Get all exportable option pages with their field keys.
	 *
	 * Retrieves option pages from AWM_Meta::options_boxes(), resolves
	 * field libraries, and returns a structured array suitable for
	 * export operations.
	 *
	 * @return array [ page_key => [ 'title' => string, 'fields' => [ field_key, ... ] ] ]
	 *
	 * @since 1.0.0
	 */
	public function get_exportable_pages()
	{
		if (self::$exportable_pages_cache !== null) {
			return self::$exportable_pages_cache;
		}

		$pages  = array();
		$meta   = new \AWM_Meta();
		$boxes  = $meta->options_boxes();

		/* Internal pages to exclude by default */
		$exclude = array('ewp-import-export', 'ewp-log-viewer');

		foreach ($boxes as $key => $data) {
			if (in_array($key, $exclude, true)) {
				continue;
			}

			$library = awm_callback_library(awm_callback_library_options($data), $key);
			$result  = $this->extract_field_keys($library, $key);

			if (empty($result['keys'])) {
				continue;
			}

			$title = isset($data['title']) ? $data['title'] : $key;

			$pages[$key] = array(
				'title'       => $title,
				'fields'      => $result['keys'],
				'field_count' => $result['field_count'],
			);
		}

		/**
		 * Filter the list of exportable option pages.
		 *
		 * @param array $pages Associative array of exportable pages.
		 * @return array Modified pages array.
		 *
		 * @since 1.0.0
		 */
		$pages = apply_filters('ewp_options_portability_exportable_pages', $pages);

		self::$exportable_pages_cache = $pages;

		return $pages;
	}

	/**
	 * Extract wp_option keys from a library definition.
	 *
	 * Sections store their sub-field values as a serialized array
	 * under the section key itself (e.g. get_option('ewp_general_settings')
	 * returns ['ewp_user_access' => ..., ...]). Flat fields store
	 * their value directly under the field key.
	 *
	 * Returns the actual wp_option names and a count of logical fields
	 * (sub-fields for sections, 1 for flat fields).
	 *
	 * @param array  $library   Resolved field library array.
	 * @param string $page_key  Option page key for context.
	 * @return array [ 'keys' => [ wp_option_name, ... ], 'field_count' => int ]
	 *
	 * @since 1.0.0
	 */
	private function extract_field_keys($library, $page_key)
	{
		$keys        = array();
		$field_count = 0;

		foreach ($library as $field_key => $field_def) {
			/* Section: the section key is the wp_option name, sub-fields are stored inside it */
			if (isset($field_def['case']) && $field_def['case'] === 'section' && isset($field_def['include'])) {
				$keys[] = $field_key;
				$field_count += count($field_def['include']);
				continue;
			}

			/* Skip non-saveable fields */
			if (isset($field_def['exclude_meta']) && $field_def['exclude_meta']) {
				continue;
			}
			if (isset($field_def['case']) && $field_def['case'] === 'html') {
				continue;
			}

			/* Flat field: field key IS the wp_option name */
			$keys[] = $field_key;
			$field_count++;
		}

		return array(
			'keys'        => $keys,
			'field_count' => $field_count,
		);
	}

	/* =========================================================
	 * Section: Export
	 * ========================================================= */

	/**
	 * Export option page values to a structured array.
	 *
	 * Reads field values from wp_options for the requested pages,
	 * builds the export payload with versioning and URL metadata.
	 *
	 * @param array  $page_keys Array of option page keys to export.
	 * @param string $actor     Context identifier: 'admin', 'rest', or 'cli'.
	 * @return array|WP_Error Export payload array or WP_Error on failure.
	 *
	 * @since 1.0.0
	 */
	public function export_options($page_keys, $actor = 'admin')
	{
		$all_pages = $this->get_exportable_pages();

		if (empty($page_keys)) {
			return new \WP_Error('no_pages', __('No option pages specified for export.', 'extend-wp'), array('status' => 400));
		}

		$export_pages    = array();
		$total_fields    = 0;
		$pages_requested = $page_keys;

		foreach ($page_keys as $page_key) {
			if (!isset($all_pages[$page_key])) {
				continue;
			}

			$page_data = $all_pages[$page_key];
			$fields    = array();

			/* 'fields' now contains wp_option keys (section keys or flat field keys) */
			foreach ($page_data['fields'] as $option_key) {
				$fields[$option_key] = get_option($option_key);
			}

			$export_pages[$page_key] = array(
				'title'       => $page_data['title'],
				'field_count' => isset($page_data['field_count']) ? $page_data['field_count'] : count($fields),
				'fields'      => $fields,
			);

			$total_fields += $export_pages[$page_key]['field_count'];
		}

		if (empty($export_pages)) {
			return new \WP_Error('no_data', __('No exportable data found for the selected pages.', 'extend-wp'), array('status' => 400));
		}

		$upload_dir = wp_upload_dir();

		$data = array(
			'ewp_options_export' => true,
			'format_version'     => self::$format_version,
			'plugin'             => 'extend-wp',
			'plugin_version'     => $this->get_plugin_version(),
			'wp_version'         => get_bloginfo('version'),
			'php_version'        => phpversion(),
			'home_url'           => home_url(),
			'site_url'           => site_url(),
			'content_url'        => content_url(),
			'upload_url'         => $upload_dir['baseurl'],
			'exported_at'        => current_time('mysql'),
			'pages'              => $export_pages,
		);

		/**
		 * Filter the export data before returning.
		 *
		 * @param array $data       Complete export payload.
		 * @param array $page_keys  Requested page keys.
		 * @param string $actor     Context: 'admin', 'rest', or 'cli'.
		 * @return array Modified export payload.
		 *
		 * @since 1.0.0
		 */
		$data = apply_filters('ewp_options_portability_export_data', $data, $page_keys, $actor);

		/* Log the export */
		ewp_log(
			'extend-wp',
			'options_export',
			sprintf('Exported %d pages (%d fields)', count($export_pages), $total_fields),
			array(
				'actor'           => $actor,
				'pages_requested' => $pages_requested,
				'pages_exported'  => array_keys($export_pages),
				'fields_exported' => $total_fields,
				'home_url'        => home_url(),
				'site_url'        => site_url(),
			),
			'developer',
			'option',
			1
		);

		return $data;
	}

	/* =========================================================
	 * Section: Import (Transaction-Safe)
	 * ========================================================= */

	/**
	 * Import option page values from a parsed export payload.
	 *
	 * Validates structure, creates backup, applies URL replacements,
	 * writes values, and rolls back on failure. Supports dry-run mode.
	 *
	 * @param array $import_data Parsed JSON export data.
	 * @param array $opts        Import options:
	 *   - 'dry_run'          => bool  (default false)
	 *   - 'skip_url_replace' => bool  (default false)
	 *   - 'actor'            => string ('admin', 'rest', 'cli')
	 *   - 'backup_file'      => string|null (path to write backup JSON)
	 * @return array|WP_Error Summary array or WP_Error on validation failure.
	 *
	 * @since 1.0.0
	 */
	public function import_options($import_data, $opts = array())
	{
		$defaults = array(
			'dry_run'          => false,
			'skip_url_replace' => false,
			'actor'            => 'admin',
			'backup_file'      => null,
		);
		$opts = wp_parse_args($opts, $defaults);

		/* Step 1: Validate structure */
		$validation = $this->validate_import_data($import_data);
		if (is_wp_error($validation)) {
			return $validation;
		}

		$warnings  = array();
		$all_pages = $this->get_exportable_pages();
		$pages     = $import_data['pages'];

		/* Step 2: Version warnings (non-blocking) */
		$warnings = $this->check_version_warnings($import_data, $warnings);

		/* Step 3: Compute field list and categorize pages */
		$imported_pages  = array();
		$skipped_pages   = array();
		$field_keys      = array();
		$field_values    = array();

		foreach ($pages as $page_key => $page_data) {
			if (!isset($all_pages[$page_key])) {
				$skipped_pages[$page_key] = __('Option page not registered', 'extend-wp');
				$warnings[] = sprintf(__('Page "%s" skipped: not registered on this site.', 'extend-wp'), $page_key);
				continue;
			}

			$registered_fields = $all_pages[$page_key]['fields'];
			$import_fields     = isset($page_data['fields']) ? $page_data['fields'] : array();

			foreach ($import_fields as $fk => $fv) {
				if (!in_array($fk, $registered_fields, true)) {
					continue;
				}
				$field_keys[]      = $fk;
				$field_values[$fk] = $fv;
			}

			$imported_pages[] = $page_key;
		}

		if (empty($field_keys)) {
			return new \WP_Error(
				'no_matching_fields',
				__('No matching fields found for import.', 'extend-wp'),
				array('status' => 400)
			);
		}

		/* Step 4: Backup existing values */
		$backup = $this->create_backup($field_keys);

		/* Optionally write backup to file (CLI) */
		if (!empty($opts['backup_file'])) {
			$backup_json = wp_json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			file_put_contents($opts['backup_file'], $backup_json);
		}

		/* Step 5: URL replacement */
		$url_replace_applied = false;
		$url_replacements    = array();

		if (!$opts['skip_url_replace']) {
			$url_replacements = $this->build_url_replacements($import_data);

			if (!empty($url_replacements)) {
				$url_replace_applied = true;

				foreach ($field_values as $fk => &$fv) {
					$fv = $this->recursive_url_replace($fv, $url_replacements);
				}
				unset($fv);
			}
		}

		/* Step 6: Write (skip on dry-run) */
		$fields_imported = 0;
		$fields_skipped  = 0;

		if (!$opts['dry_run']) {
			try {
				foreach ($field_values as $fk => $fv) {
					$result = update_option($fk, $fv);
					$fields_imported++;
				}
			} catch (\Throwable $e) {
				/* Rollback on failure */
				/**
				 * Action fired before rollback executes.
				 *
				 * @param array      $backup  Backup data.
				 * @param \Throwable $e       The exception that triggered rollback.
				 * @param array      $opts    Import options.
				 *
				 * @since 1.0.0
				 */
				do_action('ewp_options_portability_before_rollback', $backup, $e, $opts);

				$this->rollback($backup);

				ewp_log(
					'extend-wp',
					'options_import',
					sprintf('Import failed and rolled back: %s', $e->getMessage()),
					array(
						'actor'   => $opts['actor'],
						'error'   => $e->getMessage(),
						'backup'  => true,
					),
					'developer',
					'option',
					0
				);

				return new \WP_Error(
					'import_failed',
					sprintf(__('Import failed and was rolled back: %s', 'extend-wp'), $e->getMessage()),
					array('status' => 500)
				);
			}
		} else {
			$fields_imported = count($field_values);
		}

		$fields_skipped = count($field_keys) - $fields_imported + (count($field_keys) - count($field_values));

		/* Step 7: Build summary */
		$summary = array(
			'dry_run'              => $opts['dry_run'],
			'actor'                => $opts['actor'],
			'pages_requested'      => array_keys($pages),
			'pages_imported'       => $imported_pages,
			'pages_skipped'        => $skipped_pages,
			'fields_imported'      => $fields_imported,
			'fields_skipped'       => $fields_skipped,
			'old_home_url'         => isset($import_data['home_url']) ? $import_data['home_url'] : '',
			'new_home_url'         => home_url(),
			'old_site_url'         => isset($import_data['site_url']) ? $import_data['site_url'] : '',
			'new_site_url'         => site_url(),
			'url_replace_applied'  => $url_replace_applied,
			'url_replacements_count' => count($url_replacements),
			'plugin_version_match' => $this->check_major_version_match($import_data),
			'warnings'             => $warnings,
			'backup_created'       => true,
		);

		/* Step 8: Log */
		$log_behaviour = empty($skipped_pages) ? 1 : 2;
		$log_message   = $opts['dry_run']
			? sprintf('Dry-run: %d pages, %d fields would be imported', count($imported_pages), $fields_imported)
			: sprintf('Imported %d pages (%d fields)', count($imported_pages), $fields_imported);

		ewp_log(
			'extend-wp',
			'options_import',
			$log_message,
			$summary,
			'developer',
			'option',
			$log_behaviour
		);

		/**
		 * Action fired after import completes successfully.
		 *
		 * @param array $summary  Import summary.
		 * @param array $opts     Import options.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_options_portability_after_import', $summary, $opts);

		return $summary;
	}

	/* =========================================================
	 * Section: Import Helpers
	 * ========================================================= */

	/**
	 * Validate the structure of import data.
	 *
	 * @param array $data Parsed import payload.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 *
	 * @since 1.0.0
	 */
	private function validate_import_data($data)
	{
		if (empty($data) || !is_array($data)) {
			return new \WP_Error('invalid_data', __('Import data is empty or invalid.', 'extend-wp'), array('status' => 400));
		}

		if (empty($data['ewp_options_export'])) {
			return new \WP_Error('invalid_format', __('This file is not an EWP Options export.', 'extend-wp'), array('status' => 400));
		}

		if (empty($data['pages']) || !is_array($data['pages'])) {
			return new \WP_Error('no_pages', __('No pages found in import data.', 'extend-wp'), array('status' => 400));
		}

		return true;
	}

	/**
	 * Check for version-related warnings (non-blocking).
	 *
	 * @param array $data     Import payload.
	 * @param array $warnings Existing warnings array.
	 * @return array Updated warnings array.
	 *
	 * @since 1.0.0
	 */
	private function check_version_warnings($data, $warnings)
	{
		if (!$this->check_major_version_match($data)) {
			$warnings[] = sprintf(
				__('Plugin version mismatch: export was created with v%s, current is v%s.', 'extend-wp'),
				isset($data['plugin_version']) ? $data['plugin_version'] : 'unknown',
				$this->get_plugin_version()
			);
		}

		return $warnings;
	}

	/**
	 * Check if the major plugin version matches.
	 *
	 * @param array $data Import payload.
	 * @return bool True if major versions match.
	 *
	 * @since 1.0.0
	 */
	private function check_major_version_match($data)
	{
		if (empty($data['plugin_version'])) {
			return false;
		}

		$import_major  = intval(explode('.', $data['plugin_version'])[0]);
		$current_major = intval(explode('.', $this->get_plugin_version())[0]);

		return $import_major === $current_major;
	}

	/**
	 * Create a backup of existing option values for the given keys.
	 *
	 * @param array $field_keys Array of wp_option keys to back up.
	 * @return array [ field_key => current_value|false ]
	 *               false means the option did not previously exist.
	 *
	 * @since 1.0.0
	 */
	public function create_backup($field_keys)
	{
		$backup = array();

		foreach ($field_keys as $key) {
			$value = get_option($key, '___ewp_option_not_exists___');

			$backup[$key] = ($value === '___ewp_option_not_exists___') ? false : $value;
		}

		return $backup;
	}

	/**
	 * Rollback touched options to their previous values.
	 *
	 * @param array $backup Backup array from create_backup().
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function rollback($backup)
	{
		foreach ($backup as $key => $value) {
			if ($value === false) {
				delete_option($key);
				continue;
			}
			update_option($key, $value);
		}
	}

	/* =========================================================
	 * Section: URL Replacement
	 * ========================================================= */

	/**
	 * Build URL replacement pairs from export metadata vs current site.
	 *
	 * Generates an ordered array (longest-first) of old → new URL pairs,
	 * including scheme-swapped variants for http/https changes.
	 *
	 * @param array $data Export payload with URL metadata.
	 * @return array [ old_url => new_url, ... ] ordered longest-first.
	 *
	 * @since 1.0.0
	 */
	public function build_url_replacements($data)
	{
		$pairs      = array();
		$upload_dir = wp_upload_dir();

		$url_map = array(
			'home_url'    => array(
				'old' => isset($data['home_url']) ? $data['home_url'] : '',
				'new' => home_url(),
			),
			'site_url'    => array(
				'old' => isset($data['site_url']) ? $data['site_url'] : '',
				'new' => site_url(),
			),
			'content_url' => array(
				'old' => isset($data['content_url']) ? $data['content_url'] : '',
				'new' => content_url(),
			),
			'upload_url'  => array(
				'old' => isset($data['upload_url']) ? $data['upload_url'] : '',
				'new' => $upload_dir['baseurl'],
			),
		);

		foreach ($url_map as $type => $urls) {
			if (empty($urls['old']) || empty($urls['new'])) {
				continue;
			}
			if ($urls['old'] === $urls['new']) {
				continue;
			}

			/* Primary replacement */
			$pairs[$urls['old']] = $urls['new'];

			/* Scheme-swapped variant (http <-> https) */
			$scheme_swapped = $this->swap_scheme($urls['old']);
			if ($scheme_swapped !== $urls['old'] && $scheme_swapped !== $urls['new']) {
				$pairs[$scheme_swapped] = $urls['new'];
			}
		}

		/**
		 * Filter URL replacement pairs before they are applied.
		 *
		 * @param array $pairs Replacement pairs [ old_url => new_url ].
		 * @param array $data  Export payload.
		 * @return array Modified replacement pairs.
		 *
		 * @since 1.0.0
		 */
		$pairs = apply_filters('ewp_options_portability_url_replacements', $pairs, $data);

		/* Sort longest-first to avoid partial replacements */
		uksort($pairs, function ($a, $b) {
			return strlen($b) - strlen($a);
		});

		return $pairs;
	}

	/**
	 * Swap URL scheme between http and https.
	 *
	 * @param string $url URL to swap scheme for.
	 * @return string URL with swapped scheme.
	 *
	 * @since 1.0.0
	 */
	private function swap_scheme($url)
	{
		if (strpos($url, 'https://') === 0) {
			return 'http://' . substr($url, 8);
		}

		if (strpos($url, 'http://') === 0) {
			return 'https://' . substr($url, 7);
		}

		return $url;
	}

	/**
	 * Recursively replace URLs in a value.
	 *
	 * Handles strings, arrays, and serialized data safely.
	 * Serialized strings are unserialized first, replaced recursively,
	 * then re-serialized — never raw str_replace in serialized blobs.
	 *
	 * @param mixed $data         Value to process.
	 * @param array $replacements [ old_url => new_url ] pairs.
	 * @return mixed Processed value with URLs replaced.
	 *
	 * @since 1.0.0
	 */
	public function recursive_url_replace($data, $replacements)
	{
		if (empty($replacements)) {
			return $data;
		}

		/* Handle serialized strings safely */
		if (is_string($data) && is_serialized($data)) {
			$unserialized = maybe_unserialize($data);
			$replaced     = $this->recursive_url_replace($unserialized, $replacements);
			return maybe_serialize($replaced);
		}

		/* Plain strings */
		if (is_string($data)) {
			return str_replace(
				array_keys($replacements),
				array_values($replacements),
				$data
			);
		}

		/* Arrays — recurse by reference for performance */
		if (is_array($data)) {
			foreach ($data as $key => &$value) {
				$value = $this->recursive_url_replace($value, $replacements);
			}
			unset($value);
			return $data;
		}

		/* Objects */
		if (is_object($data)) {
			$vars = get_object_vars($data);
			foreach ($vars as $prop => $value) {
				$data->$prop = $this->recursive_url_replace($value, $replacements);
			}
			return $data;
		}

		/* Scalars (int, float, bool, null) — return unchanged */
		return $data;
	}

	/* =========================================================
	 * Section: REST API
	 * ========================================================= */

	/**
	 * Register REST API routes for options portability.
	 *
	 * @return void
	 *
	 * @hook rest_api_init
	 * @since 1.0.0
	 */
	public function register_rest_routes()
	{
		/* GET /options-portability/pages */
		register_rest_route(self::$rest_namespace, '/options-portability/pages', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'rest_get_pages'),
				'permission_callback' => array($this, 'rest_check_permission'),
			),
		));

		/* GET /options-portability/export — accepts pages via 'pages' or form field name */
		register_rest_route(self::$rest_namespace, '/options-portability/export', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'rest_export'),
				'permission_callback' => array($this, 'rest_check_permission'),
			),
		));

		/* POST /options-portability/import */
		register_rest_route(self::$rest_namespace, '/options-portability/import', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'rest_import'),
				'permission_callback' => array($this, 'rest_check_permission'),
				'args'                => array(
					'data' => array(
						'required' => true,
						'type'     => 'string',
					),
					'dry_run' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'skip_url_replace' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			),
		));
	}

	/**
	 * REST permission callback — require manage_options capability.
	 *
	 * @return bool True if user can manage options.
	 *
	 * @since 1.0.0
	 */
	public function rest_check_permission()
	{
		return current_user_can('manage_options');
	}

	/**
	 * REST callback: list available pages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Available pages with field counts.
	 *
	 * @since 1.0.0
	 */
	public function rest_get_pages($request)
	{
		$pages  = $this->get_exportable_pages();
		$result = array();

		foreach ($pages as $key => $data) {
			$result[$key] = array(
				'title'       => $data['title'],
				'field_count' => count($data['fields']),
			);
		}

		return rest_ensure_response($result);
	}

	/**
	 * REST callback: export option pages.
	 *
	 * Extracts the pages array from the request. Accepts the form
	 * field name (option_pages / option_pages[]) or the canonical
	 * 'pages' parameter — making the endpoint resilient to field
	 * name changes on the admin form.
	 *
	 * @param WP_REST_Request $request Request with pages parameter.
	 * @return WP_REST_Response|WP_Error Export JSON or error.
	 *
	 * @since 1.0.0
	 */
	public function rest_export($request)
	{
		$pages = $this->extract_pages_param($request);

		if (empty($pages)) {
			return new \WP_Error(
				'missing_pages',
				__('Please select at least one option page.', 'extend-wp'),
				array('status' => 400)
			);
		}

		$data = $this->export_options($pages, 'rest');

		if (is_wp_error($data)) {
			return $data;
		}

		return rest_ensure_response($data);
	}

	/**
	 * Extract the pages array from a REST request.
	 *
	 * Checks multiple possible parameter names so the endpoint works
	 * both with the admin form serializer and direct API calls.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array Flat array of page keys (may be empty).
	 *
	 * @since 1.1.0
	 */
	private function extract_pages_param($request)
	{
		/* Direct API calls use 'pages' */
		$pages = $request->get_param('pages');

		if (!empty($pages)) {
			return is_array($pages) ? $pages : array($pages);
		}

		/* Form serializer sends 'option_pages[]' or 'option_pages' */
		$pages = $request->get_param('option_pages[]');

		if (!empty($pages)) {
			return is_array($pages) ? $pages : array($pages);
		}

		$pages = $request->get_param('option_pages');

		if (!empty($pages)) {
			return is_array($pages) ? $pages : array($pages);
		}

		return array();
	}

	/**
	 * REST callback: import option pages.
	 *
	 * @param WP_REST_Request $request Request with 'data', 'dry_run', 'skip_url_replace'.
	 * @return WP_REST_Response|WP_Error Import summary or error.
	 *
	 * @since 1.0.0
	 */
	public function rest_import($request)
	{
		$raw_data = $request->get_param('data');
		$parsed   = json_decode($raw_data, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new \WP_Error('invalid_json', __('Invalid JSON in import data.', 'extend-wp'), array('status' => 400));
		}

		$opts = array(
			'dry_run'          => (bool) $request->get_param('dry_run'),
			'skip_url_replace' => (bool) $request->get_param('skip_url_replace'),
			'actor'            => 'rest',
		);

		/**
		 * Filter import data before processing via REST.
		 *
		 * Return false to cancel the import.
		 *
		 * @param array  $parsed Parsed import payload.
		 * @param array  $opts   Import options.
		 * @return array|false Modified import data or false to cancel.
		 *
		 * @since 1.0.0
		 */
		$parsed = apply_filters('ewp_options_portability_before_import', $parsed, $opts);

		if ($parsed === false) {
			return new \WP_Error('import_cancelled', __('Import was cancelled by a filter.', 'extend-wp'), array('status' => 400));
		}

		$result = $this->import_options($parsed, $opts);

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response($result);
	}

	/* =========================================================
	 * Section: Dynamic Assets
	 * ========================================================= */

	/**
	 * Register JS and CSS via Dynamic Asset Loader.
	 *
	 * @param array $assets Existing registered assets.
	 * @return array Modified assets array.
	 *
	 * @hook ewp_register_dynamic_assets
	 * @since 1.0.0
	 */
	public function register_dynamic_assets($assets)
	{
		/* JavaScript */
		$assets[] = array(
			'handle'       => 'ewp-options-portability',
			'selector'     => '.ewp-options-portability-wrap',
			'type'         => 'script',
			'src'          => awm_url . 'assets/js/admin/class-ewp-options-portability.js',
			'version'      => self::$version,
			'context'      => 'admin',
			'dependencies' => array(),
			'in_footer'    => true,
			'defer'        => true,
			'localize'     => array(
				'objectName' => 'ewpOptionsPortability',
				'data'       => array(
					'restUrl'        => rest_url(self::$rest_namespace . '/options-portability/'),
					'nonce'          => wp_create_nonce('wp_rest'),
					'homeUrl'        => home_url(),
					'siteUrl'        => site_url(),
					'pluginVersion'  => $this->get_plugin_version(),
					'strings'  => array(
						'exporting'         => __('Exporting...', 'extend-wp'),
						'importing'         => __('Importing...', 'extend-wp'),
						'exportSuccess'     => __('Export completed successfully.', 'extend-wp'),
						'importSuccess'     => __('Import completed successfully.', 'extend-wp'),
						'importDryRun'      => __('Dry-run completed. No changes were made.', 'extend-wp'),
						'error'             => __('An error occurred.', 'extend-wp'),
						'noPages'           => __('Please select at least one option page.', 'extend-wp'),
						'noFile'            => __('Please upload a JSON file.', 'extend-wp'),
						'invalidFile'       => __('Invalid JSON file.', 'extend-wp'),
						'invalidFormat'     => __('This file is not an EWP Options export.', 'extend-wp'),
						'urlDiffConfirm'    => __('The export was created on %s. URLs will be replaced with %s. Continue?', 'extend-wp'),
						'versionMismatch'   => __('Warning: Plugin version mismatch (export: %s, current: %s).', 'extend-wp'),
						'rollbackApplied'   => __('Import failed — all changes have been rolled back.', 'extend-wp'),
						'pagesImported'     => __('Pages imported: %d', 'extend-wp'),
						'pagesSkipped'      => __('Pages skipped: %d', 'extend-wp'),
						'fieldsImported'    => __('Fields imported: %d', 'extend-wp'),
						'urlsReplaced'      => __('URL replacements applied: %d pairs', 'extend-wp'),
					),
				),
			),
		);

		/* CSS */
		$assets[] = array(
			'handle'   => 'ewp-options-portability-css',
			'selector' => '.ewp-options-portability-wrap',
			'type'     => 'style',
			'src'      => awm_url . 'assets/css/admin/ewp-options-portability.css',
			'version'  => self::$version,
			'context'  => 'admin',
		);

		return $assets;
	}

	/* =========================================================
	 * Section: Logger Registration
	 * ========================================================= */

	/**
	 * Register logger action types for options portability.
	 *
	 * @param object $logger Logger instance.
	 * @return void
	 *
	 * @hook ewp_logger_initialized
	 * @since 1.0.0
	 */
	public function register_log_types($logger)
	{
		ewp_register_log_type(
			'extend-wp',
			'options_export',
			'Options Export',
			'EWP option page values were exported.'
		);

		ewp_register_log_type(
			'extend-wp',
			'options_import',
			'Options Import',
			'EWP option page values were imported.'
		);
	}

	/* =========================================================
	 * Section: Utility
	 * ========================================================= */

	/**
	 * Get the current Extend WP plugin version from file header.
	 *
	 * @return string Plugin version string.
	 *
	 * @since 1.0.0
	 */
	private function get_plugin_version()
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

	/**
	 * Get the singleton-like portability instance.
	 *
	 * Useful for CLI and external access.
	 *
	 * @return EWP_Options_Portability
	 *
	 * @since 1.0.0
	 */
	public static function instance()
	{
		static $instance = null;

		if ($instance === null) {
			$instance = new self();
		}

		return $instance;
	}
}

/* Initialize */
EWP_Options_Portability::instance();
