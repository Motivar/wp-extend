<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Gemini Provider for EWP AI Content Generator.
 *
 * Implements EWP_AI_Provider_Interface using the Google Generative Language
 * API (v1beta). Supports Gemini 2.5 Flash / Pro with optional vision input.
 *
 * Authentication uses an API key passed as a query parameter (?key=…).
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Gemini_Provider implements EWP_AI_Provider_Interface {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 60;

	// -------------------------------------------------------------------------
	// Interface: identity
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function get_id(): string {
		return 'gemini';
	}

	/** @inheritDoc */
	public function get_label(): string {
		return 'Gemini (Google)';
	}

	/** @inheritDoc */
	public function get_models(): array {
		return [
			'gemini-2.5-flash' => 'Gemini 2.5 Flash',
			'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
		];
	}

	// -------------------------------------------------------------------------
	// Interface: configuration
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function is_configured(): bool {
		return '' !== $this->get_api_key();
	}

	// -------------------------------------------------------------------------
	// Interface: health check
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function validate_key( string $key ): bool|\WP_Error {
		// GET /v1beta/models lists available models — cheap, no token cost.
		$url = add_query_arg( 'key', $key, self::API_BASE . '/models' );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'gemini_connection_failed',
				__( 'Could not connect to Google Gemini. Check your internet connection.', 'extend-wp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return true;
		}

		$message = isset( $data['error']['message'] )
			? $data['error']['message']
			: __( 'Unknown error from Google Gemini.', 'extend-wp' );

		$error_code = 400 === $code ? 'gemini_invalid_key' : 'gemini_api_error';

		return new \WP_Error( $error_code, $message );
	}

	// -------------------------------------------------------------------------
	// Interface: generation
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function generate( string $prompt, string $model, array $options = [] ): array|\WP_Error {
		/**
		 * Action fired before Gemini API call.
		 *
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_gemini_before_generate', $prompt, $model, $options);

		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return new \WP_Error( 'gemini_not_configured', __( 'Gemini API key is not configured.', 'extend-wp' ) );
		}

		$url = add_query_arg(
			'key',
			$api_key,
			self::API_BASE . '/models/' . $model . ':generateContent'
		);

		$contents = $this->build_contents( $prompt, $options );
		$body     = [ 'contents' => $contents ];

		// System instruction (optional top-level field).
		if ( ! empty( $options['system'] ) ) {
			$body['systemInstruction'] = [
				'parts' => [ [ 'text' => $options['system'] ] ],
			];
		}

		// Generation config.
		$gen_config = [];
		if ( isset( $options['max_tokens'] ) ) {
			$gen_config['maxOutputTokens'] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) ) {
			$gen_config['temperature'] = (float) $options['temperature'];
		}
		if ( ! empty( $gen_config ) ) {
			$body['generationConfig'] = $gen_config;
		}

		/**
		 * Filter Gemini API request body before sending.
		 *
		 * Allows developers to modify request parameters or add Gemini-specific options.
		 *
		 * @param array  $body Request body array.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$body = apply_filters('ewp_ai_gemini_request_body', $body, $prompt, $model, $options);

		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'gemini_connection_failed',
				__( 'Could not connect to Google Gemini. Check your internet connection.', 'extend-wp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown error from Google Gemini.', 'extend-wp' );
			return new \WP_Error( 'gemini_api_error', $message );
		}

		$content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

		$usage = [];
		if ( isset( $data['usageMetadata'] ) ) {
			$usage = [
				'prompt_tokens'     => $data['usageMetadata']['promptTokenCount'] ?? 0,
				'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
				'total_tokens'      => $data['usageMetadata']['totalTokenCount'] ?? 0,
			];
		}

		$result = [
			'content' => trim( $content ),
			'model'   => $model,
			'usage'   => $usage,
		];

		/**
		 * Filter Gemini API response before returning.
		 *
		 * @param array  $result Processed result array.
		 * @param array  $data Raw API response data.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 *
		 * @since 1.0.0
		 */
		$result = apply_filters('ewp_ai_gemini_response', $result, $data, $prompt, $model);

		/**
		 * Action fired after successful Gemini API call.
		 *
		 * @param array  $result Result array.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_gemini_after_generate', $result, $prompt, $model);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the API key.
	 *
	 * Priority: PHP constant EWP_GEMINI_API_KEY → decrypted value from settings.
	 * Decrypts the API key if it's stored encrypted in the database.
	 *
	 * @return string Plain-text API key, or '' if not set.
	 *
	 * @since 1.0.0
	 */
	private function get_api_key(): string {
		if ( defined( 'EWP_GEMINI_API_KEY' ) && '' !== EWP_GEMINI_API_KEY ) {
			return EWP_GEMINI_API_KEY;
		}

		$settings = EWP_AI_Content::get_settings();
		$encrypted_key = $settings['gemini_api_key'] ?? '';

		// Decrypt if encrypted
		if (EWP_Encryption::is_encrypted($encrypted_key)) {
			return EWP_Encryption::decrypt($encrypted_key);
		}

		return $encrypted_key;
	}

	/**
	 * Build the contents array for the Gemini generateContent API.
	 *
	 * Supports optional vision image via $options['image_base64'].
	 *
	 * @param string $prompt  User prompt text.
	 * @param array  $options Generation options.
	 * @return array Contents array.
	 *
	 * @since 1.0.0
	 */
	private function build_contents( string $prompt, array $options ): array {
		/**
		 * Filter the prompt before building contents.
		 *
		 * @param string $prompt User prompt.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$prompt = apply_filters('ewp_ai_gemini_prompt', $prompt, $options);

		$parts = [ [ 'text' => $prompt ] ];

		if ( ! empty( $options['image_base64'] ) ) {
			$mime    = $options['image_mime'] ?? 'image/jpeg';
			$parts[] = [
				'inlineData' => [
					'mimeType' => $mime,
					'data'     => $options['image_base64'],
				],
			];
		}

		$contents = [
			[
				'role'  => 'user',
				'parts' => $parts,
			],
		];

		/**
		 * Filter Gemini contents array before sending.
		 *
		 * @param array  $contents Contents array.
		 * @param string $prompt User prompt.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_gemini_contents', $contents, $prompt, $options);
	}

	/**
	 * Register provider option in the default_provider dropdown.
	 *
	 * @param array $options Existing provider options.
	 * @return array Updated provider options.
	 *
	 * @since 1.0.0
	 */
	public static function register_provider_option(array $options): array
	{
		$options['gemini'] = ['label' => 'Gemini (Google)'];
		return $options;
	}

	/**
	 * Register provider settings fields (API key + model).
	 *
	 * @param array $fields Existing settings fields.
	 * @return array Updated settings fields.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings_fields(array $fields): array
	{
		$models = (new self())->get_models();

		$fields['gemini_api_key'] = [
			'label'       => __('Gemini API Key', 'extend-wp'),
			'case'        => 'input',
			'type'        => 'password',
			'encrypt'     => true,
			'show_masked' => true,
			'explanation' => __('Your Google Gemini API key from aistudio.google.com. Stored encrypted.', 'extend-wp'),
			'show-when'   => ['default_provider' => ['values' => ['gemini' => true]]],
		];

		$fields['gemini_model'] = [
			'label'       => __('Gemini Model', 'extend-wp'),
			'case'        => 'select',
			'options'     => array_map(fn($label) => ['label' => $label], $models),
			'explanation' => __('Default model for Gemini requests.', 'extend-wp'),
			'show-when'   => ['default_provider' => ['values' => ['gemini' => true]]],
		];

		return $fields;
	}
}

// Register provider option and settings fields filters.
add_filter('ewp_ai_provider_options', ['EWP_AI_Gemini_Provider', 'register_provider_option']);
add_filter('ewp_ai_provider_settings_fields', ['EWP_AI_Gemini_Provider', 'register_settings_fields']);
