<?php

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/../class-encryption.php';
require_once __DIR__ . '/class-ai-provider-interface.php';
require_once __DIR__ . '/class-openai-provider.php';
require_once __DIR__ . '/class-claude-provider.php';
require_once __DIR__ . '/class-gemini-provider.php';
require_once __DIR__ . '/class-context-builder.php';
require_once __DIR__ . '/class-screenshot-generator.php';
require_once __DIR__ . '/class-content-generator.php';

/**
 * EWP AI Content Generator — Main Orchestrator.
 *
 * Admin-only module. Registers:
 *  - Standalone options page under Extend WP menu.
 *  - Post meta box on all public post types.
 *  - REST API endpoints (extend-wp/v1/ai-content/*).
 *  - Dynamic assets (JS + CSS) via EWP Dynamic Asset Loader.
 *  - Log types via EWP Logger.
 *
 * Self-instantiates at the bottom of this file. Nothing runs on
 * the frontend — the is_admin() guard at the top of __construct()
 * ensures complete isolation.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Content
{

	/**
	 * Module version.
	 *
	 * @var string
	 */
	private static string $version = '1.0.2';

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private static string $rest_namespace = 'extend-wp/v1';

	/**
	 * Settings option key — used as the options page ID (awm_add_options_boxes_filter).
	 * EWP saves settings by SECTION key, not this key.
	 *
	 * @var string
	 */
	private static string $option_key = 'ewp_ai_content_settings';



	/**
	 * Transient key for cached health-check status.
	 *
	 * @var string
	 */
	private static string $health_transient = 'ewp_ai_content_health';

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private static ?array $settings_cache = null;

	/**
	 * Content generator instance.
	 *
	 * @var EWP_AI_Content_Generator
	 */
	private EWP_AI_Content_Generator $generator;

	/**
	 * Screenshot generator instance.
	 *
	 * @var EWP_AI_Screenshot_Generator
	 */
	private EWP_AI_Screenshot_Generator $screenshot_generator;

	/**
	 * Constructor — registers all hooks. Admin-only.
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		$this->generator            = new EWP_AI_Content_Generator();
		$this->screenshot_generator = new EWP_AI_Screenshot_Generator();

		// Must run on every request type:
		// — REST API calls are not is_admin(), so routes must be registered before the admin-only guard.
		// — Logger log-type registration must also fire on REST requests so
		//   ewp_log() calls inside REST handlers are recorded correctly.
		add_action('rest_api_init', [$this, 'register_rest_routes']);
		add_action('ewp_logger_initialized', [$this, 'register_log_types']);

		// Register options page for both admin and REST contexts (needed for modal field lookup)
		add_filter('awm_add_options_boxes_filter', [$this, 'register_options_page']);

		// Modal field definition lookup (needed for REST API modal-fields endpoint)
		add_filter('awm_modal_field_definition_lookup', [$this, 'filter_modal_field_definition_lookup'], 10, 4);

		// Modal footer buttons filter (needed for REST API modal rendering)
		add_filter('awm_modal_footer_buttons_html', [$this, 'filter_modal_footer_buttons_html'], 10, 7);

		// Everything below is admin-only — never runs on frontend or REST requests.
		if (! is_admin()) {
			return;
		}
		add_filter('awm_add_meta_boxes_filter', [$this, 'register_ai_meta_box']);
		add_filter('awm_modal_wrapper_classes', [$this, 'filter_modal_wrapper_classes'], 10, 3);
		add_filter('awm_modal_body_content', [$this, 'filter_modal_body_content'], 10, 3);
		add_filter('awm_modal_after_body', [$this, 'filter_modal_after_body'], 10, 3);
		add_filter('ewp_register_dynamic_assets', [$this, 'register_dynamic_assets']);
		add_action('admin_bar_menu', [$this, 'render_admin_bar_node'], 100);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_assets']);

		// Invalidate health check transient when provider config is updated.
		add_action('updated_option', [$this, 'invalidate_health_check_on_provider_update'], 10, 3);

		// Bust settings cache when business_data or provider_config is updated.
		add_action('updated_option', [$this, 'on_option_updated'], 10, 3);
	}

	/* =========================================================
	 * Section: Admin Bar
	 * ========================================================= */

	/**
	 * Render the AI health indicator node in the WP admin bar.
	 *
	 * Reads the cached transient — never makes a live API call.
	 * Status dot colours: green = ok, red = error, yellow = unknown, grey = unconfigured.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 *
	 * @hook admin_bar_menu (priority 100)
	 * @since 1.0.0
	 */
	public function render_admin_bar_node(\WP_Admin_Bar $wp_admin_bar): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings    = self::get_settings();
		$provider_id = $settings['default_provider'] ?? 'openai';
		$health      = get_transient(self::$health_transient);

		if (false === $health) {
			$configured = $this->generator->get_configured_providers();
			$status     = empty($configured) ? 'unconfigured' : 'unknown';
			$provider_label = ucfirst($provider_id);
			$checked_at     = null;
			$detail_msg     = empty($configured)
				? __('No API key configured. Click to add one.', 'extend-wp')
				: __('Health not verified yet. Save settings to trigger a check.', 'extend-wp');
		} else {
			$status         = $health['status'] ?? 'unknown';
			$provider_label = $health['label'] ?? ucfirst($provider_id);
			$checked_at     = $health['checked_at'] ?? null;
			$detail_msg     = $health['message'] ?? '';
		}

		$status_text = [
			'ok'           => __('Connected', 'extend-wp'),
			'error'        => __('Connection error', 'extend-wp'),
			'unknown'      => __('Not verified', 'extend-wp'),
			'unconfigured' => __('Not configured', 'extend-wp'),
		];

		$node_title = sprintf(
			'<span class="ewp-ai-health-dot ewp-ai-health--%s" aria-hidden="true"></span><span class="ab-label">AI %s</span>',
			esc_attr($status),
			esc_html($provider_label)
		);

		$settings_url = admin_url('admin.php?page=ewp_ai_content_settings');

		$wp_admin_bar->add_node([
			'id'    => 'ewp-ai-health',
			'title' => $node_title,
			'href'  => $settings_url,
			'meta'  => [
				'title' => sprintf(
					'%s — %s',
					$status_text[$status] ?? $status,
					$detail_msg ?: __('Click to open AI settings.', 'extend-wp')
				),
				'class' => 'ewp-ai-health-node ewp-ai-health-node--' . esc_attr($status),
			],
		]);

		// Sub-node: last checked.
		if (null !== $checked_at) {
			$wp_admin_bar->add_node([
				'parent' => 'ewp-ai-health',
				'id'     => 'ewp-ai-health-time',
				'title'  => sprintf(
					/* translators: %s: human time diff e.g. "5 minutes" */
					__('Last checked: %s ago', 'extend-wp'),
					human_time_diff($checked_at)
				),
				'href'   => false,
			]);
		}

		// Sub-node: settings link.
		$wp_admin_bar->add_node([
			'parent' => 'ewp-ai-health',
			'id'     => 'ewp-ai-health-settings',
			'title'  => __('AI Content Settings', 'extend-wp'),
			'href'   => $settings_url,
		]);
	}

	/**
	 * Enqueue admin-bar dot CSS + async polling JS on every admin page.
	 *
	 * CSS is inlined on the 'admin-bar' style handle (always present).
	 * JS is inlined on the 'admin-bar' script handle and polls the
	 * health-status REST endpoint every 5 minutes via awm_ajax_call.
	 *
	 * @hook admin_enqueue_scripts
	 * @since 1.0.0
	 */
	public function enqueue_admin_bar_assets(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		// ── CSS ──────────────────────────────────────────────────────────
		$css = '
			#wp-admin-bar-ewp-ai-health > .ab-item {
				display: flex;
				align-items: center;
				gap: 4px;
			}
			#wp-admin-bar-ewp-ai-health .ewp-ai-health-dot {
				display: inline-block;
				width: 8px;
				height: 8px;
				border-radius: 50%;
				flex-shrink: 0;
				transition: background .3s, box-shadow .3s;
			}
			#wp-admin-bar-ewp-ai-health .ewp-ai-health--ok           { background:#46b450; box-shadow:0 0 0 2px rgba(70,180,80,.25); }
			#wp-admin-bar-ewp-ai-health .ewp-ai-health--error         { background:#dc3232; box-shadow:0 0 0 2px rgba(220,50,50,.25); }
			#wp-admin-bar-ewp-ai-health .ewp-ai-health--unknown       { background:#ffb900; box-shadow:0 0 0 2px rgba(255,185,0,.25); }
			#wp-admin-bar-ewp-ai-health .ewp-ai-health--unconfigured  { background:#999;    box-shadow:0 0 0 2px rgba(153,153,153,.25); }
		';
		wp_add_inline_style('admin-bar', $css);

		// ── JS config (localized data) ───────────────────────────────────
		$config = wp_json_encode([
			'statusUrl' => rest_url(self::$rest_namespace . '/ai-content/health-status'),
			'nonce'     => wp_create_nonce('wp_rest'),
			'pollMs'    => 5 * MINUTE_IN_SECONDS * 1000,
		]);
		wp_add_inline_script('admin-bar', 'window.ewpAiHealthBar=' . $config . ';');

		// ── Polling script ───────────────────────────────────────────────
		wp_add_inline_script('admin-bar', $this->get_admin_bar_polling_js());

		// Also load the main AI JS + CSS on the settings page (for onboarding modal).
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && false !== strpos($screen->id ?? '', self::$option_key)) {
			wp_enqueue_script(
				'ewp-ai-content',
				awm_url . 'assets/js/admin/class-ewp-ai-content.js',
				['admin-bar'],
				self::$version,
				true
			);
			wp_localize_script('ewp-ai-content', 'ewpAiContent', [
				'restUrl'           => rest_url(self::$rest_namespace . '/ai-content/'),
				'nonce'             => wp_create_nonce('wp_rest'),
				'includeScreenshot' => false,
				'wpmlActive'        => defined('ICL_LANGUAGE_CODE'),
				'isSettingsPage'    => true,
				'settingsUrl'       => admin_url('admin.php?page=' . self::$option_key),
				'strings'           => [
					'generating'       => __('Generating…', 'extend-wp'),
					'capturing'        => __('Capturing screenshot…', 'extend-wp'),
					'error_generic'    => __('Generation failed. Please try again.', 'extend-wp'),
					'accept_all'       => __('Accept All', 'extend-wp'),
					'retry'            => __('Retry', 'extend-wp'),
					'cancel'           => __('Cancel', 'extend-wp'),
					'generate'         => __('✦ Generate', 'extend-wp'),
					'preview_prompt'   => __('▶ Preview Prompt', 'extend-wp'),
					'loading_preview'  => __('Loading preview…', 'extend-wp'),
					'setup_business'   => __('🏢 Setup Business Context', 'extend-wp'),
					'gen_summary'      => __('✦ Generate Summary', 'extend-wp'),
					'save_context'     => __('Save to Settings', 'extend-wp'),
					'saving'           => __('Saving…', 'extend-wp'),
					'saved'            => __('Saved!', 'extend-wp'),
					'task_title'       => __('Title', 'extend-wp'),
					'task_excerpt'     => __('Excerpt', 'extend-wp'),
					'task_content'     => __('Full Content', 'extend-wp'),
					'translate_mode'   => __('Translation Mode', 'extend-wp'),
					'translate_label'  => __('Translate', 'extend-wp'),
					'recreate_label'   => __('Recreate', 'extend-wp'),
					'instructions_ph'  => __('Add specific instructions for this post…', 'extend-wp'),
				],
			]);
			wp_enqueue_style(
				'ewp-ai-content-style',
				awm_url . 'assets/css/admin/ewp-ai-content.css',
				[],
				self::$version
			);
		}
	}

	/**
	 * Return the inline JS that polls the health-status endpoint and
	 * updates the admin bar dot/label without a full page reload.
	 *
	 * Uses awm_ajax_call (always available in wp-admin via awm-global-script).
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	private function get_admin_bar_polling_js(): string
	{
		return <<<'JS'
(function () {
	'use strict';

	var cfg = window.ewpAiHealthBar || {};
	if ( ! cfg.statusUrl ) { return; }

	var STATUSES = ['ok', 'error', 'unknown', 'unconfigured'];

	function updateBar( data ) {
		var dot   = document.querySelector( '#wp-admin-bar-ewp-ai-health .ewp-ai-health-dot' );
		var label = document.querySelector( '#wp-admin-bar-ewp-ai-health .ab-label' );
		var item  = document.querySelector( '#wp-admin-bar-ewp-ai-health > .ab-item' );
		var time  = document.querySelector( '#wp-admin-bar-ewp-ai-health-time > .ab-item' );

		if ( dot ) {
			STATUSES.forEach( function ( s ) { dot.classList.remove( 'ewp-ai-health--' + s ); } );
			dot.classList.add( 'ewp-ai-health--' + ( data.status || 'unknown' ) );
		}
		if ( label ) {
			label.textContent = 'AI ' + ( data.label || '' );
		}
		if ( item ) {
			item.title = ( data.status_text || data.status ) + ' \u2014 ' + ( data.message || '' );
		}
		if ( time && data.checked_ago ) {
			time.textContent = 'Last checked: ' + data.checked_ago + ' ago';
		}
	}

	function poll() {
		if ( typeof awm_ajax_call === 'undefined' ) { return; }
		awm_ajax_call( {
			url:      cfg.statusUrl,
			method:   'GET',
			log:      false,
			callback: function ( response ) {
				if ( response && response.status ) {
					updateBar( response );
				}
			},
		} );
	}

	// First poll shortly after load; then on interval.
	setTimeout( poll, 2000 );
	setInterval( poll, cfg.pollMs || 300000 );
}());
JS;
	}

	/* =========================================================
	 * Section: Settings
	 * ========================================================= */

	/**
	 * Get current settings with defaults applied.
	 *
	 * Reads a single serialised array option and merges with defaults.
	 * Results are cached for the lifetime of the request.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public static function get_settings(): array
	{
		if (self::$settings_cache !== null) {
			return self::$settings_cache;
		}

		// EWP saves settings by section key — read each section option and merge.
		$provider_raw     = get_option('provider_config', []);
		$content_raw      = get_option('content_settings', []);
		$instructions_raw = get_option('general_instructions', []);
		$business_raw     = get_option('business_data', []);

		$stored = array_merge(
			is_array($provider_raw)     ? $provider_raw     : [],
			is_array($content_raw)      ? $content_raw      : [],
			is_array($instructions_raw) ? $instructions_raw : [],
			is_array($business_raw)     ? $business_raw     : []
		);

		// Scalar defaults for provider/content settings (section-specific).
		$provider_defaults = [
			'default_provider'   => 'openai',
			'openai_api_key'     => '',
			'openai_model'       => 'gpt-4o-mini',
			'claude_api_key'     => '',
			'claude_model'       => 'claude-sonnet-4-20250514',
			'gemini_api_key'     => '',
			'gemini_model'       => 'gemini-2.5-flash',
			'max_tokens'         => 2048,
			'temperature'        => 0.7,
			'include_screenshot' => '',
			'business_context'   => '',
		];

		// Business data defaults derived from field definitions — single source of truth.
		$business_defaults = self::extract_scalar_defaults(self::get_business_data_fields());

		$defaults = array_merge($provider_defaults, $business_defaults);

		$settings = [];
		foreach ($defaults as $key => $default) {
			$settings[$key] = $stored[$key] ?? $default;
		}

		/**
		 * Filter the AI Content settings before caching.
		 *
		 * Allows developers to modify or add settings dynamically.
		 *
		 * @param array $settings Complete settings array with defaults applied.
		 *
		 * @since 1.0.0
		 */
		$settings = apply_filters('ewp_ai_content_settings', $settings);

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Bust the settings cache (e.g. after saving business context via REST).
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function bust_cache(): void
	{
		self::$settings_cache = null;
	}

	/**
	 * Read the stored business_data option and merge with field-definition defaults.
	 *
	 * Single accessor — never call get_option('business_data') directly.
	 * Defaults are derived from get_business_data_fields() so there is no duplication.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public static function get_business_data(): array
	{
		$saved    = (array) get_option('business_data', []);
		$defaults = self::extract_scalar_defaults(self::get_business_data_fields());
		$data     = wp_parse_args($saved, $defaults);

		/**
		 * Filter the business data before returning.
		 *
		 * Allows developers to modify or add business data fields dynamically.
		 *
		 * @param array $data Business data array with defaults applied.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_business_data', $data);
	}

	/**
	 * Calculate SHA256 hash of current business_data.
	 * Excludes 'business_data_hash' field from calculation to avoid circular dependency.
	 *
	 * @return string SHA256 hash hex string.
	 *
	 * @since 1.0.0
	 */
	private static function get_business_data_hash(): string
	{
		$biz = self::get_business_data();
		unset($biz['business_data_hash']); // Exclude hash from hash calculation
		return hash('sha256', wp_json_encode($biz));
	}

	/**
	 * Walk a flat field-definition array and collect 'default' values for scalar fields.
	 *
	 * Repeater fields (whose default would be an array) are intentionally skipped —
	 * wp_parse_args handles missing array keys naturally.
	 *
	 * @param array $fields Field library (as returned by get_business_data_fields()).
	 * @return array Key → scalar default value.
	 *
	 * @since 1.0.0
	 */
	private static function extract_scalar_defaults(array $fields): array
	{
		$defaults = [];
		foreach ($fields as $key => $cfg) {
			if (isset($cfg['default']) && ! is_array($cfg['default'])) {
				$defaults[$key] = $cfg['default'];
			}
		}
		return $defaults;
	}

	/**
	 * Register the standalone options page under the Extend WP menu.
	 *
	 * @param array $pages Existing options pages.
	 * @return array
	 *
	 * @hook awm_add_options_boxes_filter
	 * @since 1.0.0
	 */
	public function register_options_page(array $pages): array
	{
		$pages[self::$option_key] = [
			'title'   => __('AI Content Generator', 'extend-wp'),
			'parent'  => 'extend-wp',
			'cap'     => 'manage_options',
			'order'   => 50,
			'library' => $this->get_settings_fields(),
		];

		return $pages;
	}

	/**
	 * Return the settings field definitions for the options page.
	 *
	 * Only call after the 'init' action (contains translated strings).
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	private function get_settings_fields(): array
	{
		return [

			// ── Provider Configuration ────────────────────────────────────
			'provider_config' => [
				'case'    => 'section',
				'label'   => __('Provider Configuration', 'extend-wp'),
				'include' => array_merge(
					[
						'default_provider' => [
							'label'       => __('Default Provider', 'extend-wp'),
							'case'        => 'select',
							'options'     => apply_filters('ewp_ai_provider_options', []),
							'explanation' => __('Provider used when none is specified per request.', 'extend-wp'),
						],
					],
					apply_filters('ewp_ai_provider_settings_fields', [])
				),
			],

			// ── Content Settings ──────────────────────────────────────────
			'content_settings' => [
				'case'    => 'section',
				'label'   => __('Content Settings', 'extend-wp'),
				'include' => [
					'brand_voice' => [
						'label'       => __('Brand Voice', 'extend-wp'),
						'case'        => 'select',
						'default'     => 'professional',
						'options'     => [
							'professional' => ['label' => __('Professional', 'extend-wp')],
							'casual'       => ['label' => __('Casual', 'extend-wp')],
							'friendly'     => ['label' => __('Friendly', 'extend-wp')],
							'luxurious'    => ['label' => __('Luxurious', 'extend-wp')],
						],
						'explanation' => __('Tone used by the AI in all generated content.', 'extend-wp'),
					],
					'max_tokens' => [
						'label'       => __('Max Tokens', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'number',
						'default'     => 2048,
						'attributes'  => ['min' => 100, 'max' => 8192],
						'explanation' => __('Maximum tokens in the AI response. Higher = longer content, more cost.', 'extend-wp'),
					],

					'temperature' => [
						'label'       => __('Temperature', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'number',
						'default'     => '0.7',
						'attributes'  => ['min' => 0, 'max' => 2, 'step' => '0.1'],
						'explanation' => __('Creativity level. 0 = deterministic, 2 = very creative.', 'extend-wp'),
					],

					'include_screenshot' => [
						'label'       => __('Include Screenshot', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'checkbox',
						'explanation' => __('Capture a screenshot of the post frontend and send it to the AI as visual context. Requires a vision-capable model.', 'extend-wp'),
					],
				],
			],

			// ── General Instructions ──────────────────────────────────────
			// brand_voice, target_audience, custom_instructions have moved into
			// the business_data modal (get_business_data_fields). Only
			// business_context remains here — it is auto-populated by JS after
			// the modal saves (awm_modal_fields_saved → generate-business-context).
			'general_instructions' => [
				'case'    => 'section',
				'label'   => __('General Instructions', 'extend-wp'),
				'include' => [

					'business_context' => [
						'label'       => __('Business Context', 'extend-wp'),
						'case'        => 'textarea',
						'attributes' => array('readonly' => true),
						'default'     => '',
						'explanation' => __('Auto-generated from your Business Data when you save the modal. You can also edit manually.', 'extend-wp'),
					],
				],
			],

			// ── Business Data ───────────────────────────────────────────────
			// awm_modal renders a trigger button; clicking opens the modal via
			// EWP's REST-powered modal system. modal_view='option' ensures
			// data is saved/read with update_option/get_option('business_data').
			'business_data' => [
				'case'         => 'awm_modal',
				'modal_view'   => 'option',
				'label'        => __('🏢 Setup Business Context', 'extend-wp'),
				'button_label' => __('🏢 Setup Business Context', 'extend-wp'),
				'modal_title'  => __('Business Data', 'extend-wp'),
				'button_class' => 'button button-primary',
				'include'      => self::get_business_data_fields(),
			],
		];
	}

	/**
	 * Return the field library for the business_data modal (single source of truth).
	 *
	 * Public + static so it can be called without an instance (e.g. from get_business_data()).
	 * All field defaults here are the authoritative source — never hardcode them elsewhere.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public static function get_business_data_fields(): array
	{
		$fields = [
			// ── Identity ──────────────────────────────────────────────────
			'business_name' => [
				'label'      => __('Business Name', 'extend-wp'),
				'case'       => 'input',
				'type'       => 'text',
				'default'    => '',
				'attributes' => ['placeholder' => __('Your company or brand name', 'extend-wp')],
			],
			'business_website' => [
				'label'      => __('Website URL', 'extend-wp'),
				'case'       => 'input',
				'type'       => 'url',
				'default'    => home_url(),
				'attributes' => ['placeholder' => home_url()],
			],
			'business_location' => [
				'label'       => __('Location / Service Area', 'extend-wp'),
				'case'        => 'input',
				'type'        => 'text',
				'default'     => '',
				'explanation' => __('e.g. Corfu, Greece — or "Worldwide / Online"', 'extend-wp'),
				'attributes'  => ['placeholder' => __('City, Country or service area', 'extend-wp')],
			],
			'business_description' => [
				'label'       => __('What does your business do?', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('Briefly describe what your business offers and who it serves.', 'extend-wp'),
			],
			'key_services' => [
				'label'       => __('Key Products / Services', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('List your main offerings, one per line or comma-separated.', 'extend-wp'),
				'attributes'  => ['placeholder' => __('e.g. Consulting, SaaS platform, Custom development…', 'extend-wp')],
			],
			'unique_selling_points' => [
				'label'       => __('What makes you different?', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('Your competitive advantages, certifications, or unique qualities.', 'extend-wp'),
			],

			// ── Content & Voice ──────────────────────────────────────────

			'target_audience' => [
				'label'       => __('Target Audience', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('Describe your target audience. Example: "First-time homebuyers aged 25–40 in urban areas."', 'extend-wp'),
			],
			'custom_instructions' => [
				'label'       => __('Custom Instructions', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('Additional instructions added to every AI prompt. Example: "Always mention our 5-year warranty."', 'extend-wp'),
			],

		];

		/**
		 * Filter the business data field definitions.
		 *
		 * Allows developers to add, remove, or modify business data fields
		 * displayed in the modal and used for AI context generation.
		 *
		 * @param array $fields Field definitions array.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_content_business_data_fields', $fields);
	}

	/**
	 * Bust settings cache when business_data or provider_config is updated.
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 *
	 * @hook updated_option
	 * @since 1.0.0
	 */
	public function on_option_updated(string $option, $old_value, $value): void
	{
		if ('business_data' === $option || 'provider_config' === $option) {
			self::bust_cache();
		}
	}

	/**
	 * Invalidate health check transient when provider config is updated.
	 *
	 * Deletes the cached health status so the next health-status request
	 * will trigger a fresh check when API keys or provider settings change.
	 *
	 * @param string $option    The option name.
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 *
	 * @hook updated_option
	 * @since 1.0.2
	 */
	public function invalidate_health_check_on_provider_update(string $option, $old_value, $value): void
	{
		// Only invalidate when provider_config is updated (contains API keys)
		if ('provider_config' === $option) {
			delete_transient(self::$health_transient);
		}
	}

	/**
	 * Encrypt API keys before saving to the database via Settings API.
	 *
	 * Hooked into pre_update_option_{option_key} so encryption is transparent
	 * to the standard WP Settings API flow.
	 *
	 * @param mixed $new_value Incoming settings array.
	 * @param mixed $old_value Previously stored settings array.
	 * @return mixed Encrypted settings array.
	 *
	 * @since 1.0.0
	 */
	public function encrypt_api_keys_on_save($new_value, $old_value): mixed
	{
		if (!is_array($new_value)) {
			return $new_value;
		}

		$key_fields = ['openai_api_key', 'claude_api_key', 'gemini_api_key'];

		foreach ($key_fields as $field) {
			if (!isset($new_value[$field])) {
				continue;
			}

			$raw = $new_value[$field];

			// If the field is empty, preserve the existing encrypted value.
			if ('' === $raw) {
				$new_value[$field] = is_array($old_value) ? ($old_value[$field] ?? '') : '';
				continue;
			}

			// If the value looks like the masked placeholder, don't re-encrypt.
			if (EWP_Encryption::is_masked($raw)) {
				$new_value[$field] = is_array($old_value) ? ($old_value[$field] ?? '') : '';
				continue;
			}

			// Encrypt the API key
			$new_value[$field] = EWP_Encryption::encrypt($raw);
		}

		// Bust the settings cache so subsequent calls get fresh values.
		self::$settings_cache = null;

		return $new_value;
	}

	/* =========================================================
	 * Section: Meta Box
	 * ========================================================= */

	/**
	 * Register the AI Content meta box via awm_add_meta_boxes_filter.
	 *
	 * Only registered when at least one provider has an API key configured.
	 * Uses awm_modal field type for the generator interface.
	 *
	 * @param array $meta_boxes Existing meta boxes.
	 * @return array Updated meta boxes.
	 *
	 * @hook awm_add_meta_boxes_filter
	 * @since 1.0.3
	 */
	public function register_ai_meta_box(array $meta_boxes): array
	{
		if (empty($this->generator->get_configured_providers())) {
			return $meta_boxes;
		}

		$post_types = get_post_types(['public' => true], 'names');

		$meta_boxes['ewp_ai_content'] = [
			'id'        => 'ewp_ai_content',
			'title'     => __('AI Content Generator', 'extend-wp'),
			'postTypes' => $post_types,
			'context'   => 'side',
			'priority'  => 'default',
			'library'   => [
				'ai_generator' => [
					'case'         => 'awm_modal',
					'modal_view'   => 'post',
					'label'        => __('✦ Generate with AI', 'extend-wp'),
					'button_label' => __('✦ Generate with AI', 'extend-wp'),
					'modal_title'  => __('AI Content Generator', 'extend-wp'),
					'button_class' => 'button button-primary widefat',
					'include'      => $this->get_ai_generator_fields(),
				],
			],
		];

		return $meta_boxes;
	}

	/**
	 * Get AI generator field definitions for the modal.
	 *
	 * Returns field library with tasks, provider, model, instructions, and custom HTML sections.
	 * Provider and model options are populated from configured providers.
	 *
	 * @return array Field definitions.
	 * @since 1.0.3
	 */
	private function get_ai_generator_fields(): array
	{
		$settings  = self::get_settings();
		$providers = $this->generator->get_configured_providers();

		// Build provider and model options from configured providers
		$provider_options = [];
		$model_options    = [];

		foreach ($providers as $provider) {
			$provider_id                    = $provider->get_id();
			$provider_options[$provider_id] = ['label' => $provider->get_label()];

			foreach ($provider->get_models() as $model_id => $model_label) {
				$model_options[$model_id] = [
					'label'           => $model_label,
					'data-provider'   => $provider_id,
				];
			}
		}

		return [
			'tasks' => [
				'label'   => __('✦ Generate', 'extend-wp'),
				'case'    => 'select',
				'options' => [
					'title'        => ['label' => __('Title', 'extend-wp')],
					'excerpt'      => ['label' => __('Excerpt', 'extend-wp')],
					'full_content' => ['label' => __('Full Content', 'extend-wp')],
				],
				'attributes' => array('multiple' => true),
			],
			'provider' => [
				'label'   => __('Provider', 'extend-wp'),
				'case'    => 'select',
				'options' => $provider_options,
				'removeEmpty' => true,
			],
			'model' => [
				'label'   => __('Model', 'extend-wp'),
				'case'    => 'select',
				'options' => $model_options,
				'removeEmpty' => true,
			],
			'translation_mode' => [
				'label'   => __('Translation Mode', 'extend-wp'),
				'case'    => 'radio',
				'options' => [
					'translate' => ['label' => __('Translate', 'extend-wp')],
					'recreate'  => ['label' => __('Recreate', 'extend-wp')],
				],
				'default' => 'translate',
			],
			'instructions' => [
				'label'      => __('Instructions', 'extend-wp'),
				'case'       => 'textarea',
				'attributes' => [
					'rows'        => 2,
					'placeholder' => __('Add specific instructions for this post…', 'extend-wp'),
				],
			],
			'prompt_preview' => [
				'case'  => 'html',
				'value' => $this->get_prompt_preview_html(),
			],
			'progress' => [
				'case'  => 'html',
				'value' => '<div class="ewp-ai-modal-progress" style="display:none;">
					<span class="spinner is-active"></span>
					<span class="ewp-ai-progress-label">' . esc_html__('Generating…', 'extend-wp') . '</span>
				</div>',
			],
			'error' => [
				'case'  => 'html',
				'value' => '<div class="ewp-ai-modal-error notice notice-error" style="display:none;"><p></p></div>',
			],
		];
	}

	/**
	 * Get HTML for the prompt preview section.
	 *
	 * Returns a collapsible preview section with toggle button and placeholder content.
	 *
	 * @return string HTML for prompt preview.
	 * @since 1.0.3
	 */
	private function get_prompt_preview_html(): string
	{
		return '<div class="ewp-ai-prompt-row">
			<button type="button" class="ewp-ai-prompt-toggle">▶ ' . esc_html__('Preview Prompt', 'extend-wp') . '</button>
			<div class="ewp-ai-prompt-preview" style="display:none;">
				<div class="ewp-ai-prompt-loading">' . esc_html__('Loading preview…', 'extend-wp') . '</div>
				<div class="ewp-ai-prompt-content" style="display:none;">
					<div class="ewp-ai-prompt-section">
						<strong>' . esc_html__('System', 'extend-wp') . '</strong>
						<pre class="ewp-ai-pre" id="ewp-prompt-system"></pre>
					</div>
					<div class="ewp-ai-prompt-section">
						<strong>' . esc_html__('User', 'extend-wp') . '</strong>
						<pre class="ewp-ai-pre" id="ewp-prompt-user"></pre>
					</div>
				</div>
			</div>
		</div>';
	}

	/**
	 * Filter modal wrapper classes to add AI-specific styling.
	 *
	 * @param array  $classes Default wrapper classes.
	 * @param string $modal_id Modal identifier.
	 * @param array  $args     Field arguments.
	 * @return array Updated classes.
	 *
	 * @hook awm_modal_wrapper_classes
	 * @since 1.0.3
	 */
	public function filter_modal_wrapper_classes(array $classes, string $modal_id, array $args): array
	{
		if (strpos($modal_id, 'ai_generator') !== false) {
			$classes[] = 'ewp-ai-generator-modal';
		}
		return $classes;
	}

	/**
	 * Filter modal body content to customize field rendering for AI modal.
	 *
	 * @param string $fields_html Rendered fields HTML.
	 * @param string $modal_id    Modal identifier.
	 * @param array  $args        Field arguments.
	 * @return string Updated HTML.
	 *
	 * @hook awm_modal_body_content
	 * @since 1.0.3
	 */
	public function filter_modal_body_content(string $fields_html, string $modal_id, array $args): string
	{
		if (strpos($modal_id, 'ai_generator') === false) {
			return $fields_html;
		}

		// Wrap fields in AI-specific container
		return '<div class="ewp-ai-modal-body">' . $fields_html . '</div>';
	}

	/**
	 * Filter modal footer buttons HTML to replace with custom AI buttons.
	 *
	 * @param string $default_buttons        Default Save/Cancel button HTML.
	 * @param array  $save_button_classes    Save button CSS classes.
	 * @param string $save_text              Save button text.
	 * @param array  $cancel_button_classes  Cancel button CSS classes.
	 * @param string $cancel_text            Cancel button text.
	 * @param string $modal_id               Modal identifier.
	 * @param array  $args                   Field arguments.
	 * @return string Custom button HTML for AI modal, default buttons for others.
	 *
	 * @hook awm_modal_footer_buttons_html
	 * @since 1.0.3
	 */
	public function filter_modal_footer_buttons_html(
		string $default_buttons,
		array $save_button_classes,
		string $save_text,
		array $cancel_button_classes,
		string $cancel_text,
		string $modal_id,
		array $args
	): string {
		// Only customize AI generator modal
		if (empty($modal_id) || false === strpos($modal_id, 'ai_generator')) {
			return $default_buttons;
		}

		// Return custom AI buttons instead of default Save/Cancel
		return '<button type="button" class="button button-primary ewp-ai-generate-btn">✦ ' . esc_html__('Generate', 'extend-wp') . '</button>
			<button type="button" class="button ewp-ai-accept-all" style="display:none;">' . esc_html__('Accept All', 'extend-wp') . '</button>
			<button type="button" class="button ewp-ai-retry" style="display:none;">' . esc_html__('Retry', 'extend-wp') . '</button>';
	}

	/**
	 * Filter save button classes to hide default Save button for AI modal.
	 *
	 * @param array  $classes  Default button classes.
	 * @param string $modal_id Modal identifier.
	 * @param array  $args     Field arguments.
	 * @return array Updated classes.
	 *
	 * @hook awm_modal_save_button_classes
	 * @since 1.0.3
	 */
	public function filter_save_button_classes(array $classes, string $modal_id, array $args): array
	{
		if (strpos($modal_id, 'ai_generator') !== false) {
			$classes[] = 'awm-hidden';
		}
		return $classes;
	}

	/**
	 * Filter cancel button classes for AI modal.
	 *
	 * @param array  $classes  Default button classes.
	 * @param string $modal_id Modal identifier.
	 * @param array  $args     Field arguments.
	 * @return array Updated classes.
	 *
	 * @hook awm_modal_cancel_button_classes
	 * @since 1.0.3
	 */
	public function filter_cancel_button_classes(array $classes, string $modal_id, array $args): array
	{
		if (strpos($modal_id, 'ai_generator') !== false) {
			$classes[] = 'awm-hidden';
		}
		return $classes;
	}

	/**
	 * Filter modal body to inject results section after main content.
	 *
	 * @param string $content  Body content.
	 * @param string $modal_id Modal identifier.
	 * @param array  $args     Field arguments.
	 * @return string Updated content.
	 *
	 * @hook awm_modal_after_body
	 * @since 1.0.3
	 */
	public function filter_modal_after_body(string $content, string $modal_id, array $args): string
	{
		if (strpos($modal_id, 'ai_generator') === false) {
			return $content;
		}

		// Inject results section
		return '<div class="ewp-ai-results-section" style="display:none;">
			<div class="ewp-ai-result-item" data-task="title" style="display:none;">
				<strong>' . esc_html__('Title', 'extend-wp') . '</strong>
				<div class="ewp-ai-result-text"></div>
			</div>
			<div class="ewp-ai-result-item" data-task="excerpt" style="display:none;">
				<strong>' . esc_html__('Excerpt', 'extend-wp') . '</strong>
				<div class="ewp-ai-result-text"></div>
			</div>
			<div class="ewp-ai-result-item" data-task="full_content" style="display:none;">
				<strong>' . esc_html__('Full Content', 'extend-wp') . '</strong>
				<div class="ewp-ai-result-text ewp-ai-result-text--content"></div>
			</div>
		</div>';
	}

	/**
	 * Filter modal field definition lookup to provide AI generator field definitions.
	 *
	 * Called by REST API when looking up field definitions for modal rendering.
	 * Provides the field definitions for the ai_generator modal field.
	 *
	 * @param array|false $field_def    Current field definition (false if not found).
	 * @param string      $meta_key     Meta key being looked up.
	 * @param string      $view         View type (post/term/user/option).
	 * @param int         $object_id    Object ID.
	 * @return array|false Field definitions or false.
	 *
	 * @hook awm_modal_field_definition_lookup
	 * @since 1.0.3
	 */
	public function filter_modal_field_definition_lookup($field_def, string $meta_key, string $view, int $object_id)
	{
		// Only handle ai_generator field in post view
		if ($meta_key !== 'ai_generator' || $view !== 'post') {
			return $field_def;
		}

		// If already found, don't override
		if ($field_def !== false) {
			return $field_def;
		}

		// Return AI generator field definitions
		return $this->get_ai_generator_fields();
	}

	/* =========================================================
	 * Section: REST Endpoints
	 * ========================================================= */

	/**
	 * Register REST API endpoints.
	 *
	 * @hook rest_api_init
	 * @since 1.0.0
	 */
	public function register_rest_routes(): void
	{
		// POST /extend-wp/v1/ai-content/generate
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'rest_generate'],
				'permission_callback' => [$this, 'rest_can_edit_posts'],
				'args'                => [
					'post_id'          => ['required' => true,  'type' => 'integer', 'minimum' => 1],
					'task'             => ['required' => true,  'type' => 'string',  'enum' => ['title', 'excerpt', 'full_content']],
					'provider'         => ['required' => false, 'type' => 'string'],
					'model'            => ['required' => false, 'type' => 'string'],
					'instructions'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
					'translation_mode' => ['required' => false, 'type' => 'string', 'enum' => ['translate', 'recreate', '']],
					'image_base64'     => ['required' => false, 'type' => 'string'],
					'image_mime'       => ['required' => false, 'type' => 'string', 'default' => 'image/jpeg'],
				],
			]
		);

		// GET /extend-wp/v1/ai-content/providers
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/providers',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [$this, 'rest_get_providers'],
				'permission_callback' => [$this, 'rest_can_edit_posts'],
			]
		);

		// POST /extend-wp/v1/ai-content/health-check
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/health-check',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'rest_health_check'],
				'permission_callback' => [$this, 'rest_can_manage_options'],
				'args'                => [
					'provider' => ['required' => true, 'type' => 'string'],
					'api_key'  => ['required' => true, 'type' => 'string'],
				],
			]
		);

		// GET /extend-wp/v1/ai-content/health-status
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/health-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [$this, 'rest_health_status'],
				'permission_callback' => [$this, 'rest_can_manage_options'],
			]
		);

		// GET /extend-wp/v1/ai-content/prompt-preview
		register_rest_route(self::$rest_namespace, '/ai-content/prompt-preview', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [$this, 'rest_prompt_preview'],
			'permission_callback' => [$this, 'rest_can_edit_posts'],
			'args'                => [
				'post_id'          => ['required' => true, 'type' => 'integer', 'minimum' => 1],
				'task'             => ['required' => true, 'type' => 'string', 'enum' => ['title', 'excerpt', 'full_content']],
				'instructions'     => ['required' => false, 'type' => 'string', 'default' => ''],
				'translation_mode' => ['required' => false, 'type' => 'string', 'default' => ''],
			],
		]);

		// POST /extend-wp/v1/ai-content/generate-business-context
		// Called by JS after awm_modal_fields_saved; reads business_data from DB,
		// fetches review pages to extract sentiment + competitor context, generates
		// and returns a concise business_context text for the settings page.
		register_rest_route(self::$rest_namespace, '/ai-content/generate-business-context', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [$this, 'rest_generate_business_context'],
			'permission_callback' => [$this, 'rest_can_manage_options'],
		]);
	}

	/**
	 * REST handler: generate content for a post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		try {
			// Clear settings cache to ensure fresh decrypted API keys
			self::$settings_cache = null;

			$post_id = (int) $request->get_param('post_id');

			// Verify the current user can edit this specific post.
			if (! current_user_can('edit_post', $post_id)) {
				return new \WP_Error('rest_forbidden', __('You do not have permission to edit this post.', 'extend-wp'), ['status' => 403]);
			}

			// Sanitise and validate screenshot base64 if provided.
			$image_base64 = '';
			$raw_image    = $request->get_param('image_base64');

			if (! empty($raw_image)) {
				$sanitised = $this->screenshot_generator->sanitise_base64($raw_image);

				if (is_wp_error($sanitised)) {
					// Screenshot failed validation — proceed without it (graceful fallback).
					$image_base64 = '';
				} else {
					$image_base64 = $sanitised;
				}
			}

			$options = [
				'provider'         => $request->get_param('provider') ?: '',
				'model'            => $request->get_param('model') ?: '',
				'instructions'     => $request->get_param('instructions') ?: '',
				'translation_mode' => $request->get_param('translation_mode') ?: '',
				'image_base64'     => $image_base64,
				'image_mime'       => $request->get_param('image_mime') ?: 'image/jpeg',
			];

			/**
			 * Filter generation options before processing.
			 *
			 * Allows developers to modify or add generation options,
			 * auto-populate instructions, or override provider settings.
			 *
			 * @param array            $options Generation options array.
			 * @param int              $post_id Post ID being generated for.
			 * @param string           $task    Task type (title, excerpt, full_content).
			 * @param \WP_REST_Request $request REST request object.
			 *
			 * @since 1.0.0
			 */
			$options = apply_filters('ewp_ai_content_generation_options', $options, $post_id, $request->get_param('task'), $request);

			// Strip empty string values so defaults apply in the generator.
			$options = array_filter($options, fn($v) => '' !== $v);

			$result = $this->generator->generate_content($post_id, $request->get_param('task'), $options);

			if (is_wp_error($result)) {
				ewp_log(
					'extend-wp',
					'ai_content_error',
					sprintf('Failed to generate %s for post #%d: %s', $request->get_param('task'), $post_id, $result->get_error_message()),
					[
						'post_id'  => $post_id,
						'task'     => $request->get_param('task'),
						'provider' => $options['provider'] ?? '',
						'error'    => $result->get_error_message(),
					],
					'developer',
					'post_type',
					0
				);

				return $result;
			}

			ewp_log(
				'extend-wp',
				'ai_content_generate',
				sprintf('Generated %s for post #%d via %s (%s)', $result['task'], $post_id, $result['provider'], $result['model']),
				[
					'post_id'  => $post_id,
					'task'     => $result['task'],
					'provider' => $result['provider'],
					'model'    => $result['model'],
					'usage'    => $result['usage'],
				],
				'editor',
				'post_type',
				$post_id
			);

			return rest_ensure_response($result);
		} catch (\Throwable $e) {
			// Log the exception
			if (function_exists('ewp_log')) {
				ewp_log(
					'extend-wp',
					'ai_content_error',
					'Exception in rest_generate',
					[
						'error' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
					],
					'developer',
					'',
					0
				);
			}

			return new \WP_Error(
				'generation_failed',
				sprintf(__('Content generation failed: %s', 'extend-wp'), $e->getMessage()),
				['status' => 500]
			);
		}
	}

	/**
	 * REST handler: return configured providers and their models.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 *
	 * @since 1.0.0
	 */
	public function rest_get_providers(\WP_REST_Request $request): \WP_REST_Response
	{
		// Clear settings cache to ensure fresh decrypted API keys
		self::$settings_cache = null;

		$configured = $this->generator->get_configured_providers();
		$data       = [];

		foreach ($configured as $provider) {
			$data[] = [
				'id'     => $provider->get_id(),
				'label'  => $provider->get_label(),
				'models' => $provider->get_models(),
			];
		}

		return rest_ensure_response($data);
	}

	/**
	 * REST handler: validate an API key (health check).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_health_check(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$provider_id = sanitize_key($request->get_param('provider'));
		$api_key = trim($request->get_param('api_key'));

		if ('' === $api_key) {
			return new \WP_Error('invalid_api_key', __('API key is empty.', 'extend-wp'), ['status' => 400]);
		}

		$provider = $this->generator->get_provider($provider_id);

		if (! $provider) {
			return new \WP_Error('unknown_provider', __('Unknown provider.', 'extend-wp'), ['status' => 400]);
		}

		$result = $provider->validate_key($api_key);

		$success = ! is_wp_error($result);

		$log_data = [
			'provider' => $provider_id,
			'success'  => $success,
		];

		if (! $success) {
			$log_data['error'] = $result->get_error_message();
			$log_data['error_code'] = $result->get_error_code();
		}

		ewp_log(
			'extend-wp',
			'ai_content_health_check',
			sprintf('Health check for provider "%s": %s', $provider_id, $success ? 'ok' : 'failed'),
			$log_data,
			'editor',
			'',
			$success ? 1 : 0
		);

		// Cache the result so the admin bar indicator can read it without making a live API call.
		set_transient(
			self::$health_transient,
			[
				'status'     => $success ? 'ok' : 'error',
				'provider'   => $provider_id,
				'label'      => $provider->get_label(),
				'message'    => $success
					? __('Connection verified successfully.', 'extend-wp')
					: $result->get_error_message(),
				'checked_at' => time(),
			],
			HOUR_IN_SECONDS
		);

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(['success' => true, 'message' => __('Connection successful.', 'extend-wp')]);
	}

	/**
	 * REST handler: return the cached health status (used by the admin bar polling JS).
	 *
	 * Never triggers a live API call — only reads the transient.
	 *
	 * @param \WP_REST_Request $request REST request (unused).
	 * @return \WP_REST_Response
	 *
	 * @since 1.0.0
	 */
	public function rest_health_status(\WP_REST_Request $request): \WP_REST_Response
	{
		$status_labels = [
			'ok'           => __('Connected', 'extend-wp'),
			'error'        => __('Connection error', 'extend-wp'),
			'unknown'      => __('Not verified', 'extend-wp'),
			'unconfigured' => __('Not configured', 'extend-wp'),
		];

		$health = get_transient(self::$health_transient);

		if (false === $health) {
			$configured = $this->generator->get_configured_providers();
			$status     = empty($configured) ? 'unconfigured' : 'unknown';

			return rest_ensure_response([
				'status'      => $status,
				'provider'    => '',
				'label'       => '',
				'message'     => empty($configured)
					? __('No API key configured.', 'extend-wp')
					: __('Health not verified yet.', 'extend-wp'),
				'checked_at'  => null,
				'checked_ago' => null,
				'status_text' => $status_labels[$status],
			]);
		}

		return rest_ensure_response([
			'status'      => $health['status'],
			'provider'    => $health['provider'],
			'label'       => $health['label'],
			'message'     => $health['message'],
			'checked_at'  => $health['checked_at'],
			'checked_ago' => $health['checked_at'] ? human_time_diff($health['checked_at']) : null,
			'status_text' => $status_labels[$health['status']] ?? $health['status'],
		]);
	}

	/**
	 * REST handler: return the system + user prompts for a task without calling the AI.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_prompt_preview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$post_id = (int) $request->get_param('post_id');
		if (! current_user_can('edit_post', $post_id)) {
			return new \WP_Error('rest_forbidden', __('You do not have permission to edit this post.', 'extend-wp'), ['status' => 403]);
		}
		$options = [
			'instructions'     => $request->get_param('instructions') ?: '',
			'translation_mode' => $request->get_param('translation_mode') ?: '',
		];
		$prompts = $this->generator->get_prompts($post_id, $request->get_param('task'), $options);
		if (is_wp_error($prompts)) {
			return $prompts;
		}
		return rest_ensure_response($prompts);
	}

	/**
	 * REST handler: generate a business context summary from stored business_data.
	 *
	 * Called by JS after awm_modal_fields_saved fires for the business_data modal.
	 * Reads business_data from DB (no request payload needed), fetches accessible
	 * review pages to extract customer sentiment snippets, includes competitor
	 * context, and asks the AI to produce a concise business_context paragraph.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_generate_business_context(): \WP_REST_Response|\WP_Error
	{
		try {
			// CRITICAL: Clear settings cache BEFORE getting providers
			// to ensure get_api_key() reads fresh decrypted values
			self::$settings_cache = null;

			// Force settings reload
			$settings = self::get_settings();

			// Log business context generation start
			ewp_log(
				'extend-wp',
				'ai_content_business_context',
				'Business context generation started',
				[
					'default_provider' => $settings['default_provider'] ?? 'openai',
				],
				'developer',
				'',
				1
			);

			// Debug: Log the API key from settings to verify decryption
			if (function_exists('ewp_log')) {
				$openai_key = $settings['openai_api_key'] ?? '';
				ewp_log(
					'extend-wp',
					'ai_content_api_key_check',
					'API key from settings in rest_generate_business_context',
					[
						'key_length' => strlen($openai_key),
						'key_starts_with' => substr($openai_key, 0, 10),
						'is_encrypted' => (0 === strpos($openai_key, 'ewp_enc:')),
					],
					'developer',
					'',
					1
				);
			}

			$configured = $this->generator->get_configured_providers();
			if (empty($configured)) {
				return new \WP_Error('no_provider', __('No AI provider configured.', 'extend-wp'), ['status' => 400]);
			}
			$provider_id = $settings['default_provider'] ?? 'openai';
			$provider    = $this->generator->get_provider($provider_id);
			if (! $provider || ! $provider->is_configured()) {
				$provider    = reset($configured);
				$provider_id = $provider->get_id();
			}

			$biz = self::get_business_data();

			// ── Smart caching: skip regeneration if data hasn't changed ──────────
			$current_hash = self::get_business_data_hash();
			if ($current_hash === ($biz['business_data_hash'] ?? '') && ! empty($biz['business_context'])) {
				return rest_ensure_response(['business_context' => $biz['business_context']]);
			}

			// ── Build prompt parts ──────────────────────────────────────────────
			$context_parts = [];

			/**
			 * Filter business data before building context prompt.
			 *
			 * Allows developers to modify business data used for context generation,
			 * add computed fields, or inject external data sources.
			 *
			 * @param array $biz Business data array.
			 *
			 * @since 1.0.0
			 */
			$biz = apply_filters('ewp_ai_content_business_context_data', $biz);

			if (! empty($biz['business_name'])) {
				$context_parts[] = 'Business: ' . $biz['business_name'];
			}

			if (! empty($biz['business_location'])) {
				$context_parts[] = 'Location: ' . $biz['business_location'];
			}

			if (! empty($biz['business_description'])) {
				$context_parts[] = 'Description: ' . $biz['business_description'];
			}

			if (! empty($biz['key_services'])) {
				$context_parts[] = 'Services: ' . $biz['key_services'];
			}

			if (! empty($biz['unique_selling_points'])) {
				$context_parts[] = 'Differentiation: ' . $biz['unique_selling_points'];
			}

			if (! empty($biz['target_audience'])) {
				$context_parts[] = 'Audience: ' . $biz['target_audience'];
			}


			/**
			 * Filter context parts before building the business context prompt.
			 *
			 * Allows developers to add, remove, or modify context parts
			 * that will be sent to the AI for business context generation.
			 *
			 * @param array $context_parts Array of context strings.
			 * @param array $biz           Business data array.
			 *
			 * @since 1.0.0
			 */
			$context_parts = apply_filters('ewp_ai_content_business_context_parts', $context_parts, $biz);

			// ── AI call ─────────────────────────────────────────────────────────
			$prompt_parts = [

				'Create a business context for an AI content generation system.',

				'The output must define:',
				'- what the business does',
				'- who it serves',
				'- what makes it different',
				'- the appropriate tone of voice',
				'- how future content should be written',

				'Guidelines:',
				'- Be specific and concrete',
				'- Avoid generic marketing phrases (e.g. "high-quality service")',
				'- Avoid fluff and repetition',
				'- Focus on clarity and real value',
				'- Use natural language (not robotic)',
				'- Keep it under 120 words',

				'Output rules:',
				'- Return a single paragraph',
				'- No headings, no bullet points, no markdown',

				'Business data:',
				implode("\n", $context_parts),
			];

			/**
			 * Filter the business context generation prompt parts.
			 *
			 * Allows developers to modify the prompt structure sent to AI
			 * for generating business context.
			 *
			 * @param array $prompt_parts Array of prompt strings.
			 * @param array $context_parts Array of context data strings.
			 * @param array $biz Business data array.
			 *
			 * @since 1.0.0
			 */
			$prompt_parts = apply_filters('ewp_ai_content_business_context_prompt_parts', $prompt_parts, $context_parts, $biz);

			$prompt = implode("\n", $prompt_parts);
			$model  = $settings[$provider_id . '_model'] ?? array_key_first($provider->get_models());
			$system_prompt = 'You are a senior conversion copywriter creating foundational business context for an AI system. Your goal is to produce clear, specific, and non-generic descriptions that will guide all future AI-generated content. Avoid vague claims and generic marketing language.';

			/**
			 * Filter the system prompt for business context generation.
			 *
			 * @param string $system_prompt System prompt text.
			 * @param array  $biz Business data array.
			 *
			 * @since 1.0.0
			 */
			$system_prompt = apply_filters('ewp_ai_content_business_context_system_prompt', $system_prompt, $biz);

			/**
			 * Filter the user prompt for business context generation.
			 *
			 * @param string $prompt User prompt text.
			 * @param array  $biz Business data array.
			 *
			 * @since 1.0.0
			 */
			$prompt = apply_filters('ewp_ai_content_business_context_user_prompt', $prompt, $biz);

			// Log AI call details
			ewp_log(
				'extend-wp',
				'ai_content_business_context',
				'Calling AI provider for business context generation',
				[
					'provider' => $provider_id,
					'model' => $model,
					'system_prompt' => $system_prompt,
					'user_prompt' => $prompt,
					'max_tokens' => 300,
					'temperature' => 0.5,
					'business_data_fields' => array_keys($context_parts),
				],
				'developer',
				'',
				1
			);

			$result = $provider->generate($prompt, $model, [
				'system' => $system_prompt,
				'max_tokens'  => 300,
				'temperature' => 0.5,
			]);

			if (is_wp_error($result)) {
				ewp_log(
					'extend-wp',
					'ai_content_business_context',
					'Business context generation failed: ' . $result->get_error_message(),
					[
						'provider' => $provider_id,
						'model' => $model,
						'error_code' => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					],
					'developer',
					'',
					0
				);
				return $result;
			}

			// Update hash and context in business_data option
			$biz['business_data_hash'] = $current_hash;
			$biz['business_context'] = trim($result['content']);

			/**
			 * Filter the generated business context before saving.
			 *
			 * Allows developers to post-process the AI-generated business context.
			 *
			 * @param string $business_context Generated business context text.
			 * @param array  $result AI generation result.
			 * @param array  $biz Business data array.
			 *
			 * @since 1.0.0
			 */
			$biz['business_context'] = apply_filters('ewp_ai_content_business_context_generated', $biz['business_context'], $result, $biz);

			/**
			 * Action fired before saving generated business context.
			 *
			 * @param array $biz Business data array with new context.
			 *
			 * @since 1.0.0
			 */
			do_action('ewp_ai_content_before_save_business_context', $biz);

			update_option('business_data', $biz, false);

			/**
			 * Action fired after saving generated business context.
			 *
			 * @param array $biz Business data array with new context.
			 *
			 * @since 1.0.0
			 */
			do_action('ewp_ai_content_after_save_business_context', $biz);

			// Log successful generation
			ewp_log(
				'extend-wp',
				'ai_content_business_context',
				'Business context generated successfully',
				[
					'provider' => $provider_id,
					'model' => $result['model'] ?? $model,
					'usage' => $result['usage'] ?? [],
					'content_length' => strlen($biz['business_context']),
					'business_data_hash' => $current_hash,
				],
				'developer',
				'',
				1
			);

			return rest_ensure_response(['business_context' => $biz['business_context']]);
		} catch (\Throwable $e) {
			// Log the exception
			if (function_exists('ewp_log')) {
				ewp_log(
					'extend-wp',
					'ai_content_error',
					'Exception in rest_generate_business_context',
					[
						'error' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					],
					'developer',
					'',
					0
				);
			}

			return new \WP_Error(
				'generation_failed',
				sprintf(__('Failed to generate business context: %s', 'extend-wp'), $e->getMessage()),
				['status' => 500]
			);
		}
	}

	/**
	 * Check if a URL returns a successful HTTP response (2xx or 3xx).
	 *
	 * Used to filter out dead links before including them in AI prompts.
	 *
	 * @param string $url The URL to test.
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	private function is_url_accessible(string $url): bool
	{
		if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}
		$response = wp_remote_head($url, [
			'timeout'     => 5,
			'sslverify'   => false,
			'user-agent'  => 'Mozilla/5.0 (compatible; WP/' . get_bloginfo('version') . ')',
			'redirection' => 3,
		]);
		if (is_wp_error($response)) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		return $code >= 200 && $code < 400;
	}

	/**
	 * Permission callback: requires edit_posts capability.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function rest_can_edit_posts(): bool
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Permission callback: requires manage_options capability.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function rest_can_manage_options(): bool
	{
		return current_user_can('manage_options');
	}

	/* =========================================================
	 * Section: Dynamic Assets
	 * ========================================================= */

	/**
	 * Register JS and CSS assets via EWP Dynamic Asset Loader.
	 *
	 * @param array $assets Existing registered assets.
	 * @return array
	 *
	 * @hook ewp_register_dynamic_assets
	 * @since 1.0.0
	 */
	public function register_dynamic_assets(array $assets): array
	{
		$settings           = self::get_settings();
		$screenshot_enabled = ! empty($settings['include_screenshot']);

		// JavaScript — loads when AI generator modal trigger exists.
		$assets[] = [
			'handle'       => 'ewp-ai-content',
			'selector'     => '.awm-modal-trigger[data-modal-id*="ai_generator"]',
			'type'         => 'script',
			'src'          => awm_url . 'assets/js/admin/class-ewp-ai-content.js',
			'version'      => self::$version,
			'context'      => 'admin',
			'dependencies' => ['awm-modal-field', $screenshot_enabled ? 'html2canvas' : null],
			'in_footer'    => true,
			'defer'        => true,
			'localize'     => [
				'objectName' => 'ewpAiContent',
				'data'       => [
					'restUrl'           => rest_url(self::$rest_namespace . '/ai-content/'),
					'nonce'             => wp_create_nonce('wp_rest'),
					'includeScreenshot' => $screenshot_enabled,
					'wpmlActive'        => defined('ICL_LANGUAGE_CODE'),
					'isSettingsPage'    => false,
					'settingsUrl'       => admin_url('admin.php?page=' . self::$option_key),
					'strings'           => [
						'generating'       => __('Generating…', 'extend-wp'),
						'capturing'        => __('Capturing screenshot…', 'extend-wp'),
						'error_generic'    => __('Generation failed. Please try again.', 'extend-wp'),
						'accept_all'       => __('Accept All', 'extend-wp'),
						'retry'            => __('Retry', 'extend-wp'),
						'cancel'           => __('Cancel', 'extend-wp'),
						'generate'         => __('✦ Generate', 'extend-wp'),
						'preview_prompt'   => __('▶ Preview Prompt', 'extend-wp'),
						'loading_preview'  => __('Loading preview…', 'extend-wp'),
						'setup_business'   => __('🏢 Setup Business Context', 'extend-wp'),
						'generating_ctx'   => __('Generating business context…', 'extend-wp'),
						'saving'           => __('Saving…', 'extend-wp'),
						'saved'            => __('Saved!', 'extend-wp'),
						'task_title'       => __('Title', 'extend-wp'),
						'task_excerpt'     => __('Excerpt', 'extend-wp'),
						'task_content'     => __('Full Content', 'extend-wp'),
						'translate_mode'   => __('Translation Mode', 'extend-wp'),
						'translate_label'  => __('Translate', 'extend-wp'),
						'recreate_label'   => __('Recreate', 'extend-wp'),
						'instructions_ph'  => __('Add specific instructions for this post…', 'extend-wp'),
					],
				],
			],
		];

		// html2canvas CDN — only registered when screenshot is enabled.
		if ($screenshot_enabled) {
			$assets[] = [
				'handle'  => 'html2canvas',
				'type'    => 'script',
				'src'     => 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
				'version' => '1.4.1',
				'context' => 'admin',
			];
		}

		// CSS — loads when AI generator modal trigger exists.
		$assets[] = [
			'handle'   => 'ewp-ai-content-style',
			'selector' => '.awm-modal-trigger[data-modal-id*="ai_generator"]',
			'type'     => 'style',
			'src'      => awm_url . 'assets/css/admin/ewp-ai-content.css',
			'version'  => self::$version,
			'context'  => 'admin',
		];

		return $assets;
	}

	/* =========================================================
	 * Section: Logger
	 * ========================================================= */

	/**
	 * Register EWP Logger action types for this module.
	 *
	 * @param mixed $logger Logger instance (not used directly — we call the
	 *                      global ewp_register_log_type() helper).
	 *
	 * @hook ewp_logger_initialized
	 * @since 1.0.0
	 */
	public function register_log_types($logger): void
	{
		ewp_register_log_type(
			'extend-wp',
			'ai_content_generate',
			'AI Content Generate',
			'AI content was generated successfully for a post.'
		);

		ewp_register_log_type(
			'extend-wp',
			'ai_content_error',
			'AI Content Error',
			'AI content generation failed.'
		);

		ewp_register_log_type(
			'extend-wp',
			'ai_content_health_check',
			'AI Content Health Check',
			'An AI provider API key was validated.'
		);

		ewp_register_log_type(
			'extend-wp',
			'ai_content_business_context',
			'Ai Content Business Context',
			'Business context generation for AI content system.'
		);

		ewp_register_log_type(
			'extend-wp',
			'ai_content_api_call',
			'AI Content Api Call',
			'API call to AI provider for content generation.'
		);
	}
}

// Only instantiate if AI integration is enabled in general settings
if (Extend_WP_Default_Content::get_general_settings('ewp_enable_ai_integration')) {
	new EWP_AI_Content();
}