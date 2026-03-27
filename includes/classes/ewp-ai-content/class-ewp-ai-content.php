<?php

if (! defined('ABSPATH')) {
	exit;
}

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
	 * EWP section option keys — the actual wp_options rows where settings are stored.
	 * Keys map to which section each field belongs to.
	 *
	 * @var array<string, string>
	 */
	private static array $section_keys = [
		'default_provider'    => 'provider_config',
		'openai_api_key'      => 'provider_config',
		'openai_model'        => 'provider_config',
		'claude_api_key'      => 'provider_config',
		'claude_model'        => 'provider_config',
		'gemini_api_key'      => 'provider_config',
		'gemini_model'        => 'provider_config',
		'max_tokens'          => 'content_settings',
		'temperature'         => 'content_settings',
		'include_screenshot'  => 'content_settings',
		'brand_voice'         => 'general_instructions',
		'target_audience'     => 'general_instructions',
		'business_context'    => 'general_instructions',
		'custom_instructions' => 'general_instructions',
	];

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

		// Everything below is admin-only — never runs on frontend or REST requests.
		if (! is_admin()) {
			return;
		}
		add_action('add_meta_boxes', [$this, 'register_meta_box']);
		add_filter('ewp_register_dynamic_assets', [$this, 'register_dynamic_assets']);
		add_action('admin_bar_menu', [$this, 'render_admin_bar_node'], 100);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_assets']);

		// Bust settings cache whenever EWP's modal save persists business_data.
		add_action('awm_modal_after_save', [$this, 'on_business_data_saved'], 10, 4);
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
		return wp_parse_args($saved, $defaults);
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
		$openai_models = (new EWP_AI_OpenAI_Provider())->get_models();
		$claude_models = (new EWP_AI_Claude_Provider())->get_models();
		$gemini_models = (new EWP_AI_Gemini_Provider())->get_models();

		return [

			// ── Provider Configuration ────────────────────────────────────
			'provider_config' => [
				'case'    => 'section',
				'label'   => __('Provider Configuration', 'extend-wp'),
				'include' => [

					'default_provider' => [
						'label'       => __('Default Provider', 'extend-wp'),
						'case'        => 'select',
						'options'     => [
							'openai' => ['label' => 'OpenAI'],
							'claude' => ['label' => 'Claude (Anthropic)'],
							'gemini' => ['label' => 'Gemini (Google)'],
						],
						'explanation' => __('Provider used when none is specified per request.', 'extend-wp'),
					],

					'openai_api_key' => [
						'label'       => __('OpenAI API Key', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'password',
						'encrypt'     => true,
						'show_masked' => true,
						'explanation' => __('Your OpenAI API key from platform.openai.com. Stored encrypted.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['openai' => true]]],
					],

					'openai_model' => [
						'label'       => __('OpenAI Model', 'extend-wp'),
						'case'        => 'select',
						'options'     => array_map(fn($label) => ['label' => $label], $openai_models),
						'explanation' => __('Default model for OpenAI requests.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['openai' => true]]],
					],

					'claude_api_key' => [
						'label'       => __('Claude API Key', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'password',
						'encrypt'     => true,
						'show_masked' => true,
						'explanation' => __('Your Anthropic API key from console.anthropic.com. Stored encrypted.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['claude' => true]]],
					],

					'claude_model' => [
						'label'       => __('Claude Model', 'extend-wp'),
						'case'        => 'select',
						'options'     => array_map(fn($label) => ['label' => $label], $claude_models),
						'explanation' => __('Default model for Claude requests.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['claude' => true]]],
					],

					'gemini_api_key' => [
						'label'       => __('Gemini API Key', 'extend-wp'),
						'case'        => 'input',
						'type'        => 'password',
						'encrypt'     => true,
						'show_masked' => true,
						'explanation' => __('Your Google Gemini API key from aistudio.google.com. Stored encrypted.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['gemini' => true]]],
					],

					'gemini_model' => [
						'label'       => __('Gemini Model', 'extend-wp'),
						'case'        => 'select',
						'options'     => array_map(fn($label) => ['label' => $label], $gemini_models),
						'explanation' => __('Default model for Gemini requests.', 'extend-wp'),
						'show-when'   => ['default_provider' => ['values' => ['gemini' => true]]],
					],
				],
			],

			// ── Content Settings ──────────────────────────────────────────
			'content_settings' => [
				'case'    => 'section',
				'label'   => __('Content Settings', 'extend-wp'),
				'include' => [

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
		return [
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

			// ── Review links — URL only (platform inferred from domain) ──
			'review_links' => [
				'label'       => __('Review Platform Links', 'extend-wp'),
				'case'        => 'repeater',
				'item_name'   => __('Link', 'extend-wp'),
				'minrows'     => 0,
				'explanation' => __('Google Maps, TripAdvisor, Yelp, Booking.com… The AI will extract customer sentiment from these when you save.', 'extend-wp'),
				'include'     => [
					'url' => [
						'label'      => __('URL', 'extend-wp'),
						'case'       => 'input',
						'type'       => 'url',
						'attributes' => ['placeholder' => 'https://maps.google.com/…'],
					],
				],
			],

			// Auto-populated via generate-business-context endpoint, editable.
			'customer_sentiment' => [
				'label'       => __('Customer Review Summary', 'extend-wp'),
				'case'        => 'textarea',
				'default'     => '',
				'explanation' => __('Summarises what customers say. Auto-generated when you save — or write your own.', 'extend-wp'),
			],

			// ── Social links — URL only ───────────────────────────────────
			'social_links' => [
				'label'       => __('Social Media Profiles', 'extend-wp'),
				'case'        => 'repeater',
				'item_name'   => __('Profile', 'extend-wp'),
				'minrows'     => 0,
				'explanation' => __('Facebook, Instagram, TikTok, LinkedIn… URLs validated before being sent to the AI.', 'extend-wp'),
				'include'     => [
					'url' => [
						'label'      => __('URL', 'extend-wp'),
						'case'       => 'input',
						'type'       => 'url',
						'attributes' => ['placeholder' => 'https://facebook.com/…'],
					],
				],
			],

			// ── Competitors ───────────────────────────────────────────────
			'competitors' => [
				'label'       => __('Competitors', 'extend-wp'),
				'case'        => 'repeater',
				'item_name'   => __('Competitor', 'extend-wp'),
				'minrows'     => 0,
				'explanation' => __('Add main competitors to help the AI differentiate your content.', 'extend-wp'),
				'include'     => [
					'name' => [
						'label'      => __('Name', 'extend-wp'),
						'case'       => 'input',
						'type'       => 'text',
						'attributes' => ['placeholder' => 'Competitor name'],
					],
					'url' => [
						'label'      => __('Website', 'extend-wp'),
						'case'       => 'input',
						'type'       => 'url',
						'attributes' => ['placeholder' => 'https://… (optional)'],
					],
				],
			],
		];
	}

	/**
	 * Bust the settings cache after EWP's modal system saves the business_data option.
	 *
	 * @param string $meta_key  Saved option key.
	 * @param string $view      View type ('option').
	 * @param int    $object_id Not used for options.
	 * @param array  $values    Saved values.
	 *
	 * @hook awm_modal_after_save
	 * @since 1.0.0
	 */
	public function on_business_data_saved(string $meta_key, string $view, int $object_id, array $values): void
	{
		if ('business_data' === $meta_key) {
			self::bust_cache();
		}
	}

	/* =========================================================
	 * Section: Meta Box
	 * ========================================================= */

	/**
	 * Register the AI Content meta box on all public post types.
	 *
	 * Only registered when at least one provider has an API key configured.
	 *
	 * @hook add_meta_boxes
	 * @since 1.0.0
	 */
	public function register_meta_box(): void
	{
		if (empty($this->generator->get_configured_providers())) {
			return;
		}

		$post_types = get_post_types(['public' => true], 'names');

		foreach ($post_types as $post_type) {
			add_meta_box(
				'ewp-ai-content',
				__('AI Content Generator', 'extend-wp'),
				[$this, 'render_meta_box'],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the AI Content meta box HTML.
	 *
	 * The .ewp-ai-content-metabox wrapper class is used by the Dynamic
	 * Asset Loader as a selector to conditionally load JS + CSS.
	 *
	 * @param \WP_Post $post Current post object.
	 * @since 1.0.0
	 */
	public function render_meta_box(\WP_Post $post): void
	{
		$configured         = $this->generator->get_configured_providers();
		$settings           = self::get_settings();
		$screenshot_enabled = ! empty($settings['include_screenshot']);
		$frontend_url       = $this->screenshot_generator->get_frontend_url($post->ID);

		wp_nonce_field('ewp_ai_content_metabox', 'ewp_ai_content_nonce');
?>
		<div class="ewp-ai-content-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>"
			data-frontend-url="<?php echo esc_attr($frontend_url ?: ''); ?>"
			data-screenshot-enabled="<?php echo esc_attr($screenshot_enabled ? '1' : '0'); ?>"
			data-wpml-active="<?php echo esc_attr(defined('ICL_LANGUAGE_CODE') ? '1' : '0'); ?>">
			<?php if (empty($configured)) : ?>
				<p class="ewp-ai-notice ewp-ai-notice--warn">
					<?php printf(
						wp_kses(__('No AI provider configured. <a href="%s">Go to settings</a>.', 'extend-wp'), ['a' => ['href' => []]]),
						esc_url(admin_url('admin.php?page=ewp_ai_content_settings'))
					); ?>
				</p>
			<?php else : ?>
				<button type="button" class="button button-primary widefat ewp-ai-open-modal">
					✦ <?php esc_html_e('Generate with AI', 'extend-wp'); ?>
				</button>
			<?php endif; ?>
		</div>
<?php
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

			// ── Build prompt parts ──────────────────────────────────────────────
			$parts = [
				'Generate a concise business context summary (max 150 words) for use in AI content generation prompts. '
					. 'Write only the summary paragraph — no headers, no labels, no markdown.',
			];

			if (! empty($biz['business_name'])) {
				$parts[] = 'Business name: ' . $biz['business_name'];
			}
			if (! empty($biz['business_website'])) {
				$url = esc_url_raw($biz['business_website']);
				if ($this->is_url_accessible($url)) {
					$parts[] = 'Website: ' . $url;
				}
			}
			if (! empty($biz['business_location'])) {
				$parts[] = 'Location/service area: ' . $biz['business_location'];
			}
			if (! empty($biz['business_description'])) {
				$parts[] = 'Description: ' . $biz['business_description'];
			}
			if (! empty($biz['key_services'])) {
				$parts[] = 'Key services/products: ' . $biz['key_services'];
			}
			if (! empty($biz['unique_selling_points'])) {
				$parts[] = 'What makes them different: ' . $biz['unique_selling_points'];
			}
			if (! empty($biz['brand_voice'])) {
				$parts[] = 'Brand voice/tone: ' . $biz['brand_voice'];
			}
			if (! empty($biz['target_audience'])) {
				$parts[] = 'Target audience: ' . $biz['target_audience'];
			}

			// ── Fetch review page snippets for sentiment ────────────────────────
			$review_links = is_array($biz['review_links'] ?? null) ? $biz['review_links'] : [];
			$snippets     = [];
			foreach ($review_links as $link) {
				$url = esc_url_raw(is_array($link) ? ($link['url'] ?? '') : '');
				if (! $url || ! $this->is_url_accessible($url)) {
					continue;
				}
				$response = wp_remote_get($url, [
					'timeout'    => 8,
					'sslverify'  => false,
					'user-agent' => 'Mozilla/5.0 (compatible; WP/' . get_bloginfo('version') . ')',
				]);
				if (is_wp_error($response)) {
					continue;
				}
				$html  = wp_remote_retrieve_body($response);
				$title = '';
				$desc  = '';
				if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
					$title = wp_strip_all_tags($m[1]);
				}
				if (
					preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)
					|| preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/i', $html, $m)
				) {
					$desc = $m[1];
				}
				$snippet = trim($title . ($desc ? ' — ' . $desc : ''));
				if ($snippet) {
					$snippets[] = $snippet;
				} else {
					$parsed = wp_parse_url($url);
					if (! empty($parsed['host'])) {
						$snippets[] = $parsed['host'];
					}
				}
			}
			if (! empty($snippets)) {
				$biz_label = ! empty($biz['business_name']) ? " for \"{$biz['business_name']}\"" : '';
				$parts[]   = "Customer review snippets{$biz_label}: " . implode(' | ', $snippets);
			} elseif (! empty($biz['customer_sentiment'])) {
				// Fall back to manually written sentiment if reviews are inaccessible.
				$parts[] = 'Customer sentiment: ' . $biz['customer_sentiment'];
			}

			// ── Social links ────────────────────────────────────────────────────
			$social_links = is_array($biz['social_links'] ?? null) ? $biz['social_links'] : [];
			$valid_social = [];
			foreach ($social_links as $link) {
				$url = esc_url_raw(is_array($link) ? ($link['url'] ?? '') : '');
				if ($url && $this->is_url_accessible($url)) {
					$valid_social[] = $url;
				}
			}
			if (! empty($valid_social)) {
				$parts[] = 'Social media: ' . implode(', ', $valid_social);
			}

			// ── Competitors ─────────────────────────────────────────────────────
			$competitors       = is_array($biz['competitors'] ?? null) ? $biz['competitors'] : [];
			$competitor_labels = [];
			foreach ($competitors as $comp) {
				if (! is_array($comp)) {
					continue;
				}
				$name = sanitize_text_field($comp['name'] ?? '');
				$url  = esc_url_raw($comp['url'] ?? '');
				if (! $name) {
					continue;
				}
				if ($url && ! $this->is_url_accessible($url)) {
					$competitor_labels[] = $name;
				} elseif ($url) {
					$competitor_labels[] = "{$name} ({$url})";
				} else {
					$competitor_labels[] = $name;
				}
			}
			if (! empty($competitor_labels)) {
				$parts[] = 'Main competitors: ' . implode(', ', $competitor_labels);
			}

			// ── AI call ─────────────────────────────────────────────────────────
			$prompt = implode("\n", $parts);
			$model  = $settings[$provider_id . '_model'] ?? array_key_first($provider->get_models());
			$result = $provider->generate($prompt, $model, [
				'system'      => 'You are a professional copywriter. Write clear, accurate, concise business context paragraphs for AI content generation.',
				'max_tokens'  => 300,
				'temperature' => 0.5,
			]);

			if (is_wp_error($result)) {
				return $result;
			}

			return rest_ensure_response(['business_context' => trim($result['content'])]);
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

		// JavaScript.
		$assets[] = [
			'handle'       => 'ewp-ai-content',
			'selector'     => '.ewp-ai-content-metabox',
			'type'         => 'script',
			'src'          => awm_url . 'assets/js/admin/class-ewp-ai-content.js',
			'version'      => self::$version,
			'context'      => 'admin',
			'dependencies' => $screenshot_enabled ? ['html2canvas'] : [],
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

		// CSS.
		$assets[] = [
			'handle'  => 'ewp-ai-content-style',
			'selector' => '.ewp-ai-content-metabox',
			'type'    => 'style',
			'src'     => awm_url . 'assets/css/admin/ewp-ai-content.css',
			'version' => self::$version,
			'context' => 'admin',
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
	}
}

new EWP_AI_Content();
