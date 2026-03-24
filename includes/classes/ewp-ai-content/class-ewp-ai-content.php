<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-encryption.php';
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
class EWP_AI_Content {

	/**
	 * Module version.
	 *
	 * @var string
	 */
	private static string $version = '1.0.0';

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
	public function __construct() {
		$this->generator            = new EWP_AI_Content_Generator();
		$this->screenshot_generator = new EWP_AI_Screenshot_Generator();

		// Must run on every request type:
		// — REST API calls are not is_admin(), so routes and the encryption
		//   filter must be registered before the admin-only guard.
		// — Logger log-type registration must also fire on REST requests so
		//   ewp_log() calls inside REST handlers are recorded correctly.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'ewp_logger_initialized', [ $this, 'register_log_types' ] );

		// Encrypt API keys whenever the provider_config section is saved.
		// EWP stores settings by section key, so we hook that option directly.
		add_filter( 'pre_update_option_provider_config', [ $this, 'maybe_encrypt_api_keys' ], 10, 2 );

		// Everything below is admin-only — never runs on frontend or REST requests.
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'awm_add_options_boxes_filter', [ $this, 'register_options_page' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_filter( 'ewp_register_dynamic_assets', [ $this, 'register_dynamic_assets' ] );
		add_action( 'admin_bar_menu', [ $this, 'render_admin_bar_node' ], 100 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_bar_assets' ] );
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
	public function render_admin_bar_node( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = self::get_settings();
		$provider_id = $settings['default_provider'] ?? 'openai';
		$health      = get_transient( self::$health_transient );

		if ( false === $health ) {
			$configured = $this->generator->get_configured_providers();
			$status     = empty( $configured ) ? 'unconfigured' : 'unknown';
			$provider_label = ucfirst( $provider_id );
			$checked_at     = null;
			$detail_msg     = empty( $configured )
				? __( 'No API key configured. Click to add one.', 'extend-wp' )
				: __( 'Health not verified yet. Save settings to trigger a check.', 'extend-wp' );
		} else {
			$status         = $health['status'] ?? 'unknown';
			$provider_label = $health['label'] ?? ucfirst( $provider_id );
			$checked_at     = $health['checked_at'] ?? null;
			$detail_msg     = $health['message'] ?? '';
		}

		$status_text = [
			'ok'           => __( 'Connected', 'extend-wp' ),
			'error'        => __( 'Connection error', 'extend-wp' ),
			'unknown'      => __( 'Not verified', 'extend-wp' ),
			'unconfigured' => __( 'Not configured', 'extend-wp' ),
		];

		$node_title = sprintf(
			'<span class="ewp-ai-health-dot ewp-ai-health--%s" aria-hidden="true"></span><span class="ab-label">AI %s</span>',
			esc_attr( $status ),
			esc_html( $provider_label )
		);

		$settings_url = admin_url( 'admin.php?page=ewp_ai_content_settings' );

		$wp_admin_bar->add_node( [
			'id'    => 'ewp-ai-health',
			'title' => $node_title,
			'href'  => $settings_url,
			'meta'  => [
				'title' => sprintf(
					'%s — %s',
					$status_text[ $status ] ?? $status,
					$detail_msg ?: __( 'Click to open AI settings.', 'extend-wp' )
				),
				'class' => 'ewp-ai-health-node ewp-ai-health-node--' . esc_attr( $status ),
			],
		] );

		// Sub-node: last checked.
		if ( null !== $checked_at ) {
			$wp_admin_bar->add_node( [
				'parent' => 'ewp-ai-health',
				'id'     => 'ewp-ai-health-time',
				'title'  => sprintf(
					/* translators: %s: human time diff e.g. "5 minutes" */
					__( 'Last checked: %s ago', 'extend-wp' ),
					human_time_diff( $checked_at )
				),
				'href'   => false,
			] );
		}

		// Sub-node: settings link.
		$wp_admin_bar->add_node( [
			'parent' => 'ewp-ai-health',
			'id'     => 'ewp-ai-health-settings',
			'title'  => __( 'AI Content Settings', 'extend-wp' ),
			'href'   => $settings_url,
		] );
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
	public function enqueue_admin_bar_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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
		wp_add_inline_style( 'admin-bar', $css );

		// ── JS config (localized data) ───────────────────────────────────
		$config = wp_json_encode( [
			'statusUrl' => rest_url( self::$rest_namespace . '/ai-content/health-status' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'pollMs'    => 5 * MINUTE_IN_SECONDS * 1000,
		] );
		wp_add_inline_script( 'admin-bar', 'window.ewpAiHealthBar=' . $config . ';' );

		// ── Polling script ───────────────────────────────────────────────
		wp_add_inline_script( 'admin-bar', $this->get_admin_bar_polling_js() );
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
	private function get_admin_bar_polling_js(): string {
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
	public static function get_settings(): array {
		if ( self::$settings_cache !== null ) {
			return self::$settings_cache;
		}

		// EWP saves settings by section key — read each section option and merge.
		$provider_raw     = get_option( 'provider_config', [] );
		$content_raw      = get_option( 'content_settings', [] );
		$instructions_raw = get_option( 'general_instructions', [] );

		$stored = array_merge(
			is_array( $provider_raw )     ? $provider_raw     : [],
			is_array( $content_raw )      ? $content_raw      : [],
			is_array( $instructions_raw ) ? $instructions_raw : []
		);

		$defaults = [
			'default_provider'    => 'openai',
			'openai_api_key'      => '',
			'openai_model'        => 'gpt-4o-mini',
			'claude_api_key'      => '',
			'claude_model'        => 'claude-sonnet-4-20250514',
			'gemini_api_key'      => '',
			'gemini_model'        => 'gemini-2.5-flash',
			'max_tokens'          => 2048,
			'temperature'         => 0.7,
			'include_screenshot'  => '',
			'brand_voice'         => 'professional',
			'target_audience'     => '',
			'business_context'    => '',
			'custom_instructions' => '',
		];

		$settings = [];
		foreach ( $defaults as $key => $default ) {
			$settings[ $key ] = isset( $stored[ $key ] ) ? $stored[ $key ] : $default;
		}

		self::$settings_cache = $settings;
		return $settings;
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
	public function register_options_page( array $pages ): array {
		$pages[ self::$option_key ] = [
			'title'   => __( 'AI Content Generator', 'extend-wp' ),
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
	private function get_settings_fields(): array {
		$openai_models = ( new EWP_AI_OpenAI_Provider() )->get_models();
		$claude_models = ( new EWP_AI_Claude_Provider() )->get_models();
		$gemini_models = ( new EWP_AI_Gemini_Provider() )->get_models();

		return [

			// ── Provider Configuration ────────────────────────────────────
			'provider_config' => [
				'case'    => 'section',
				'label'   => __( 'Provider Configuration', 'extend-wp' ),
				'include' => [

					'default_provider' => [
						'label'       => __( 'Default Provider', 'extend-wp' ),
						'case'        => 'select',
						'options'     => [
							'openai' => [ 'label' => 'OpenAI' ],
							'claude' => [ 'label' => 'Claude (Anthropic)' ],
							'gemini' => [ 'label' => 'Gemini (Google)' ],
						],
						'explanation' => __( 'Provider used when none is specified per request.', 'extend-wp' ),
					],

					'openai_api_key' => [
						'label'       => __( 'OpenAI API Key', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'password',
						'explanation' => __( 'Your OpenAI API key from platform.openai.com. Stored encrypted.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'openai' => true ] ] ],
					],

					'openai_model' => [
						'label'       => __( 'OpenAI Model', 'extend-wp' ),
						'case'        => 'select',
						'options'     => array_map( fn( $label ) => [ 'label' => $label ], $openai_models ),
						'explanation' => __( 'Default model for OpenAI requests.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'openai' => true ] ] ],
					],

					'claude_api_key' => [
						'label'       => __( 'Claude API Key', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'password',
						'explanation' => __( 'Your Anthropic API key from console.anthropic.com. Stored encrypted.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'claude' => true ] ] ],
					],

					'claude_model' => [
						'label'       => __( 'Claude Model', 'extend-wp' ),
						'case'        => 'select',
						'options'     => array_map( fn( $label ) => [ 'label' => $label ], $claude_models ),
						'explanation' => __( 'Default model for Claude requests.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'claude' => true ] ] ],
					],

					'gemini_api_key' => [
						'label'       => __( 'Gemini API Key', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'password',
						'explanation' => __( 'Your Google Gemini API key from aistudio.google.com. Stored encrypted.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'gemini' => true ] ] ],
					],

					'gemini_model' => [
						'label'       => __( 'Gemini Model', 'extend-wp' ),
						'case'        => 'select',
						'options'     => array_map( fn( $label ) => [ 'label' => $label ], $gemini_models ),
						'explanation' => __( 'Default model for Gemini requests.', 'extend-wp' ),
						'show-when'   => [ 'default_provider' => [ 'values' => [ 'gemini' => true ] ] ],
					],
				],
			],

			// ── Content Settings ──────────────────────────────────────────
			'content_settings' => [
				'case'    => 'section',
				'label'   => __( 'Content Settings', 'extend-wp' ),
				'include' => [

					'max_tokens' => [
						'label'       => __( 'Max Tokens', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'number',
						'default'     => 2048,
						'attributes'  => [ 'min' => 100, 'max' => 8192 ],
						'explanation' => __( 'Maximum tokens in the AI response. Higher = longer content, more cost.', 'extend-wp' ),
					],

					'temperature' => [
						'label'       => __( 'Temperature', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'number',
						'default'     => '0.7',
						'attributes'  => [ 'min' => 0, 'max' => 2, 'step' => '0.1' ],
						'explanation' => __( 'Creativity level. 0 = deterministic, 2 = very creative.', 'extend-wp' ),
					],

					'include_screenshot' => [
						'label'       => __( 'Include Screenshot', 'extend-wp' ),
						'case'        => 'input',
						'type'        => 'checkbox',
						'explanation' => __( 'Capture a screenshot of the post frontend and send it to the AI as visual context. Requires a vision-capable model.', 'extend-wp' ),
					],
				],
			],

			// ── General Instructions ──────────────────────────────────────
			'general_instructions' => [
				'case'    => 'section',
				'label'   => __( 'General Instructions', 'extend-wp' ),
				'include' => [

					'brand_voice' => [
						'label'   => __( 'Brand Voice', 'extend-wp' ),
						'case'    => 'select',
						'options' => [
							'professional' => [ 'label' => __( 'Professional', 'extend-wp' ) ],
							'casual'       => [ 'label' => __( 'Casual', 'extend-wp' ) ],
							'friendly'     => [ 'label' => __( 'Friendly', 'extend-wp' ) ],
							'luxurious'    => [ 'label' => __( 'Luxurious', 'extend-wp' ) ],
						],
						'explanation' => __( 'Tone used by the AI in all generated content.', 'extend-wp' ),
					],

					'target_audience' => [
						'label'       => __( 'Target Audience', 'extend-wp' ),
						'case'        => 'textarea',
						'explanation' => __( 'Describe your target audience. Example: "First-time homebuyers aged 25–40 in urban areas."', 'extend-wp' ),
					],

					'business_context' => [
						'label'       => __( 'Business Context', 'extend-wp' ),
						'case'        => 'textarea',
						'explanation' => __( 'Your business, location, industry, and key selling points. The AI will use this to write relevant content.', 'extend-wp' ),
					],

					'custom_instructions' => [
						'label'       => __( 'Custom Instructions', 'extend-wp' ),
						'case'        => 'textarea',
						'explanation' => __( 'Additional instructions added to every AI prompt. Example: "Always mention our 5-year warranty."', 'extend-wp' ),
					],
				],
			],
		];
	}

	/**
	 * Intercept settings save to encrypt API keys before storing.
	 *
	 * Hooked into 'pre_update_option_{option_key}' so encryption
	 * is transparent to the standard WP Settings API flow.
	 *
	 * @param mixed $new_value  Incoming settings array.
	 * @param mixed $old_value  Previously stored settings array.
	 * @return mixed Sanitised + encrypted settings array.
	 *
	 * @since 1.0.0
	 */
	public function maybe_encrypt_api_keys( $new_value, $old_value ): mixed {
		if ( ! is_array( $new_value ) ) {
			return $new_value;
		}

		$key_fields = [ 'openai_api_key', 'claude_api_key', 'gemini_api_key' ];

		foreach ( $key_fields as $field ) {
			if ( ! isset( $new_value[ $field ] ) ) {
				continue;
			}

			$raw = $new_value[ $field ];

			// If the field is empty, preserve the existing encrypted value.
			if ( '' === $raw ) {
				$new_value[ $field ] = is_array( $old_value ) ? ( $old_value[ $field ] ?? '' ) : '';
				continue;
			}

			// If the value looks like the masked placeholder, don't re-encrypt.
			if ( EWP_AI_Encryption::is_masked( $raw ) ) {
				$new_value[ $field ] = is_array( $old_value ) ? ( $old_value[ $field ] ?? '' ) : '';
				continue;
			}

			$new_value[ $field ] = EWP_AI_Encryption::encrypt( $raw );
		}

		// Bust the settings cache so subsequent calls get fresh values.
		self::$settings_cache = null;

		// Invalidate the health transient — run a fresh check with the new key.
		delete_transient( self::$health_transient );
		$this->run_health_check_after_save( $new_value );

		return $new_value;
	}

	/**
	 * Run a health check for the default provider immediately after settings are saved.
	 *
	 * Called from maybe_encrypt_api_keys so the admin bar dot updates on the
	 * very next page load without the user having to wait for the JS polling cycle.
	 *
	 * The check is intentionally lightweight (< 2 s timeout) so it doesn't block
	 * the settings save response.
	 *
	 * @param array $saved The just-saved provider_config array (keys already encrypted).
	 *
	 * @since 1.0.0
	 */
	private function run_health_check_after_save( array $saved ): void {
		// Map field → provider id.
		$provider_key_map = [
			'openai_api_key' => 'openai',
			'claude_api_key' => 'claude',
			'gemini_api_key' => 'gemini',
		];

		$default_provider = $saved['default_provider'] ?? 'openai';
		$field            = $default_provider . '_api_key';
		$encrypted        = $saved[ $field ] ?? '';
		$plain_key        = EWP_AI_Encryption::decrypt( $encrypted );

		if ( '' === $plain_key ) {
			return; // Nothing to check.
		}

		$provider = $this->generator->get_provider( $default_provider );

		if ( ! $provider ) {
			return;
		}

		$result  = $provider->validate_key( $plain_key );
		$success = ! is_wp_error( $result );

		set_transient(
			self::$health_transient,
			[
				'status'     => $success ? 'ok' : 'error',
				'provider'   => $default_provider,
				'label'      => $provider->get_label(),
				'message'    => $success
					? __( 'Connection verified successfully.', 'extend-wp' )
					: $result->get_error_message(),
				'checked_at' => time(),
			],
			HOUR_IN_SECONDS
		);

		ewp_log(
			'extend-wp',
			'ai_content_health_check',
			sprintf( 'Auto health check on save — provider "%s": %s', $default_provider, $success ? 'ok' : 'failed' ),
			[ 'provider' => $default_provider, 'success' => $success ],
			'editor',
			'',
			$success ? 1 : 0
		);
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
	public function register_meta_box(): void {
		if ( empty( $this->generator->get_configured_providers() ) ) {
			return;
		}

		$post_types = get_post_types( [ 'public' => true ], 'names' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ewp-ai-content',
				__( 'AI Content Generator', 'extend-wp' ),
				[ $this, 'render_meta_box' ],
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
	public function render_meta_box( \WP_Post $post ): void {
		$settings            = self::get_settings();
		$configured          = $this->generator->get_configured_providers();
		$screenshot_enabled  = ! empty( $settings['include_screenshot'] );
		$wpml_active         = defined( 'ICL_LANGUAGE_CODE' );
		$frontend_url        = $this->screenshot_generator->get_frontend_url( $post->ID );

		wp_nonce_field( 'ewp_ai_content_metabox', 'ewp_ai_content_nonce' );
		?>
		<div class="ewp-ai-content-metabox"
			data-post-id="<?php echo esc_attr( $post->ID ); ?>"
			data-frontend-url="<?php echo esc_attr( $frontend_url ?: '' ); ?>"
			data-screenshot-enabled="<?php echo esc_attr( $screenshot_enabled ? '1' : '0' ); ?>">

			<?php if ( empty( $configured ) ) : ?>
				<p class="ewp-ai-notice ewp-ai-notice--warn">
					<?php
					printf(
						wp_kses(
							/* translators: %s: settings page URL */
							__( 'No AI provider configured. <a href="%s">Go to settings</a>.', 'extend-wp' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						esc_url( admin_url( 'admin.php?page=ewp_ai_content_settings' ) )
					);
					?>
				</p>
			<?php else : ?>

				<?php /* Task selector */ ?>
				<p>
					<label for="ewp-ai-task"><strong><?php esc_html_e( 'Generate', 'extend-wp' ); ?></strong></label>
					<select id="ewp-ai-task" class="ewp-ai-select widefat">
						<option value="title"><?php esc_html_e( 'Title', 'extend-wp' ); ?></option>
						<option value="excerpt"><?php esc_html_e( 'Excerpt', 'extend-wp' ); ?></option>
						<option value="full_content"><?php esc_html_e( 'Full Content', 'extend-wp' ); ?></option>
					</select>
				</p>

				<?php /* Provider selector */ ?>
				<p>
					<label for="ewp-ai-provider"><strong><?php esc_html_e( 'Provider', 'extend-wp' ); ?></strong></label>
					<select id="ewp-ai-provider" class="ewp-ai-select widefat">
						<?php foreach ( $configured as $provider ) : ?>
							<option value="<?php echo esc_attr( $provider->get_id() ); ?>"
								<?php selected( $provider->get_id(), $settings['default_provider'] ); ?>>
								<?php echo esc_html( $provider->get_label() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php /* Model selector — provider-grouped data attributes */ ?>
				<p>
					<label for="ewp-ai-model"><strong><?php esc_html_e( 'Model', 'extend-wp' ); ?></strong></label>
					<select id="ewp-ai-model" class="ewp-ai-select widefat">
						<?php foreach ( $configured as $provider ) : ?>
							<?php foreach ( $provider->get_models() as $model_id => $model_label ) : ?>
								<option value="<?php echo esc_attr( $model_id ); ?>"
									data-provider="<?php echo esc_attr( $provider->get_id() ); ?>"
									<?php selected( $model_id, $settings[ $provider->get_id() . '_model' ] ?? '' ); ?>>
									<?php echo esc_html( $model_label ); ?>
								</option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</p>

				<?php /* WPML translation mode (only shown when WPML is active) */ ?>
				<?php if ( $wpml_active ) : ?>
					<p class="ewp-ai-translation-mode">
						<strong><?php esc_html_e( 'Translation Mode', 'extend-wp' ); ?></strong><br>
						<label>
							<input type="radio" name="ewp_ai_translation_mode" value="translate" checked>
							<?php esc_html_e( 'Translate', 'extend-wp' ); ?>
						</label>
						&nbsp;
						<label>
							<input type="radio" name="ewp_ai_translation_mode" value="recreate">
							<?php esc_html_e( 'Recreate', 'extend-wp' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<?php /* Per-post custom instructions */ ?>
				<p>
					<label for="ewp-ai-instructions"><strong><?php esc_html_e( 'Instructions (optional)', 'extend-wp' ); ?></strong></label>
					<textarea id="ewp-ai-instructions" class="widefat" rows="3"
						placeholder="<?php esc_attr_e( 'Add specific instructions for this post…', 'extend-wp' ); ?>"></textarea>
				</p>

				<?php /* Generate button + progress */ ?>
				<p>
					<button type="button" id="ewp-ai-generate-btn" class="button button-primary widefat">
						<?php esc_html_e( 'Generate', 'extend-wp' ); ?>
					</button>
				</p>

				<div id="ewp-ai-progress" class="ewp-ai-progress" style="display:none;">
					<span class="spinner is-active"></span>
					<span id="ewp-ai-progress-label"><?php esc_html_e( 'Generating…', 'extend-wp' ); ?></span>
				</div>

				<?php /* Result preview */ ?>
				<div id="ewp-ai-result" class="ewp-ai-result" style="display:none;">
					<p><strong><?php esc_html_e( 'Preview', 'extend-wp' ); ?></strong></p>
					<div id="ewp-ai-result-content" class="ewp-ai-result-content"></div>

					<div class="ewp-ai-actions">
						<button type="button" id="ewp-ai-accept-btn" class="button button-primary">
							<?php esc_html_e( 'Accept', 'extend-wp' ); ?>
						</button>
						<button type="button" id="ewp-ai-regenerate-btn" class="button">
							<?php esc_html_e( 'Regenerate', 'extend-wp' ); ?>
						</button>
						<button type="button" id="ewp-ai-discard-btn" class="button">
							<?php esc_html_e( 'Discard', 'extend-wp' ); ?>
						</button>
					</div>
				</div>

				<?php /* Error area */ ?>
				<div id="ewp-ai-error" class="ewp-ai-error notice notice-error" style="display:none;">
					<p id="ewp-ai-error-message"></p>
				</div>

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
	public function register_rest_routes(): void {
		// POST /extend-wp/v1/ai-content/generate
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_generate' ],
				'permission_callback' => [ $this, 'rest_can_edit_posts' ],
				'args'                => [
					'post_id'          => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
					'task'             => [ 'required' => true,  'type' => 'string',  'enum' => [ 'title', 'excerpt', 'full_content' ] ],
					'provider'         => [ 'required' => false, 'type' => 'string' ],
					'model'            => [ 'required' => false, 'type' => 'string' ],
					'instructions'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
					'translation_mode' => [ 'required' => false, 'type' => 'string', 'enum' => [ 'translate', 'recreate', '' ] ],
					'image_base64'     => [ 'required' => false, 'type' => 'string' ],
					'image_mime'       => [ 'required' => false, 'type' => 'string', 'default' => 'image/jpeg' ],
				],
			]
		);

		// GET /extend-wp/v1/ai-content/providers
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/providers',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_providers' ],
				'permission_callback' => [ $this, 'rest_can_edit_posts' ],
			]
		);

		// POST /extend-wp/v1/ai-content/health-check
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/health-check',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_health_check' ],
				'permission_callback' => [ $this, 'rest_can_manage_options' ],
				'args'                => [
					'provider' => [ 'required' => true, 'type' => 'string' ],
					'api_key'  => [ 'required' => true, 'type' => 'string' ],
				],
			]
		);

		// GET /extend-wp/v1/ai-content/health-status
		register_rest_route(
			self::$rest_namespace,
			'/ai-content/health-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_health_status' ],
				'permission_callback' => [ $this, 'rest_can_manage_options' ],
			]
		);

	}

	/**
	 * REST handler: generate content for a post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_generate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		// Verify the current user can edit this specific post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'extend-wp' ), [ 'status' => 403 ] );
		}

		// Sanitise and validate screenshot base64 if provided.
		$image_base64 = '';
		$raw_image    = $request->get_param( 'image_base64' );

		if ( ! empty( $raw_image ) ) {
			$sanitised = $this->screenshot_generator->sanitise_base64( $raw_image );

			if ( is_wp_error( $sanitised ) ) {
				// Screenshot failed validation — proceed without it (graceful fallback).
				$image_base64 = '';
			} else {
				$image_base64 = $sanitised;
			}
		}

		$options = [
			'provider'         => $request->get_param( 'provider' ) ?: '',
			'model'            => $request->get_param( 'model' ) ?: '',
			'instructions'     => $request->get_param( 'instructions' ) ?: '',
			'translation_mode' => $request->get_param( 'translation_mode' ) ?: '',
			'image_base64'     => $image_base64,
			'image_mime'       => $request->get_param( 'image_mime' ) ?: 'image/jpeg',
		];

		// Strip empty string values so defaults apply in the generator.
		$options = array_filter( $options, fn( $v ) => '' !== $v );

		$result = $this->generator->generate_content( $post_id, $request->get_param( 'task' ), $options );

		if ( is_wp_error( $result ) ) {
			ewp_log(
				'extend-wp',
				'ai_content_error',
				sprintf( 'Failed to generate %s for post #%d: %s', $request->get_param( 'task' ), $post_id, $result->get_error_message() ),
				[
					'post_id'  => $post_id,
					'task'     => $request->get_param( 'task' ),
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
			sprintf( 'Generated %s for post #%d via %s (%s)', $result['task'], $post_id, $result['provider'], $result['model'] ),
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

		return rest_ensure_response( $result );
	}

	/**
	 * REST handler: return configured providers and their models.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 *
	 * @since 1.0.0
	 */
	public function rest_get_providers( \WP_REST_Request $request ): \WP_REST_Response {
		$configured = $this->generator->get_configured_providers();
		$data       = [];

		foreach ( $configured as $provider ) {
			$data[] = [
				'id'     => $provider->get_id(),
				'label'  => $provider->get_label(),
				'models' => $provider->get_models(),
			];
		}

		return rest_ensure_response( $data );
	}

	/**
	 * REST handler: validate an API key (health check).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @since 1.0.0
	 */
	public function rest_health_check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider_id = sanitize_key( $request->get_param( 'provider' ) );
		$api_key     = trim( $request->get_param( 'api_key' ) );

		$provider = $this->generator->get_provider( $provider_id );

		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', __( 'Unknown provider.', 'extend-wp' ), [ 'status' => 400 ] );
		}

		$result = $provider->validate_key( $api_key );

		$success = ! is_wp_error( $result );

		ewp_log(
			'extend-wp',
			'ai_content_health_check',
			sprintf( 'Health check for provider "%s": %s', $provider_id, $success ? 'ok' : 'failed' ),
			[ 'provider' => $provider_id, 'success' => $success ],
			'editor',
			'',
			0
		);

		// Cache the result so the admin bar indicator can read it without making a live API call.
		set_transient(
			self::$health_transient,
			[
				'status'     => $success ? 'ok' : 'error',
				'provider'   => $provider_id,
				'label'      => $provider->get_label(),
				'message'    => $success
					? __( 'Connection verified successfully.', 'extend-wp' )
					: $result->get_error_message(),
				'checked_at' => time(),
			],
			HOUR_IN_SECONDS
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'success' => true, 'message' => __( 'Connection successful.', 'extend-wp' ) ] );
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
	public function rest_health_status( \WP_REST_Request $request ): \WP_REST_Response {
		$status_labels = [
			'ok'           => __( 'Connected', 'extend-wp' ),
			'error'        => __( 'Connection error', 'extend-wp' ),
			'unknown'      => __( 'Not verified', 'extend-wp' ),
			'unconfigured' => __( 'Not configured', 'extend-wp' ),
		];

		$health = get_transient( self::$health_transient );

		if ( false === $health ) {
			$configured = $this->generator->get_configured_providers();
			$status     = empty( $configured ) ? 'unconfigured' : 'unknown';

			return rest_ensure_response( [
				'status'      => $status,
				'provider'    => '',
				'label'       => '',
				'message'     => empty( $configured )
					? __( 'No API key configured.', 'extend-wp' )
					: __( 'Health not verified yet.', 'extend-wp' ),
				'checked_at'  => null,
				'checked_ago' => null,
				'status_text' => $status_labels[ $status ],
			] );
		}

		return rest_ensure_response( [
			'status'      => $health['status'],
			'provider'    => $health['provider'],
			'label'       => $health['label'],
			'message'     => $health['message'],
			'checked_at'  => $health['checked_at'],
			'checked_ago' => $health['checked_at'] ? human_time_diff( $health['checked_at'] ) : null,
			'status_text' => $status_labels[ $health['status'] ] ?? $health['status'],
		] );
	}

	/**
	 * Permission callback: requires edit_posts capability.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function rest_can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback: requires manage_options capability.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function rest_can_manage_options(): bool {
		return current_user_can( 'manage_options' );
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
	public function register_dynamic_assets( array $assets ): array {
		$settings           = self::get_settings();
		$screenshot_enabled = ! empty( $settings['include_screenshot'] );

		// JavaScript.
		$assets[] = [
			'handle'       => 'ewp-ai-content',
			'selector'     => '.ewp-ai-content-metabox',
			'type'         => 'script',
			'src'          => awm_url . 'assets/js/admin/class-ewp-ai-content.js',
			'version'      => self::$version,
			'context'      => 'admin',
			'dependencies' => $screenshot_enabled ? [ 'html2canvas' ] : [],
			'in_footer'    => true,
			'defer'        => true,
			'localize'     => [
				'objectName' => 'ewpAiContent',
				'data'       => [
					'restUrl'           => rest_url( self::$rest_namespace . '/ai-content/' ),
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'includeScreenshot' => $screenshot_enabled,
					'wpmlActive'        => defined( 'ICL_LANGUAGE_CODE' ),
					'strings'           => [
						'generating'       => __( 'Generating…', 'extend-wp' ),
						'capturing'        => __( 'Capturing preview…', 'extend-wp' ),
						'error_generic'    => __( 'Generation failed. Please try again.', 'extend-wp' ),
						'error_screenshot' => __( 'Could not capture screenshot, proceeding without it.', 'extend-wp' ),
					],
				],
			],
		];

		// html2canvas CDN — only registered when screenshot is enabled.
		if ( $screenshot_enabled ) {
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
	public function register_log_types( $logger ): void {
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
