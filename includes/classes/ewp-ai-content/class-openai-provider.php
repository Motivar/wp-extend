<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI Provider for EWP AI Content Generator.
 *
 * Implements EWP_AI_Provider_Interface using the OpenAI Chat Completions API.
 * Supports GPT-4o and GPT-4.1 family models with optional vision (image) input.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_OpenAI_Provider implements EWP_AI_Provider_Interface {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.openai.com/v1';

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
		return 'openai';
	}

	/** @inheritDoc */
	public function get_label(): string {
		return 'OpenAI';
	}

	/** @inheritDoc */
	public function get_models(): array {
		return [
			'gpt-4o'       => 'GPT-4o',
			'gpt-4o-mini'  => 'GPT-4o Mini',
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-4.1-mini' => 'GPT-4.1 Mini',
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
		$response = wp_remote_get(
			self::API_BASE . '/models',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$error_msg = sprintf(
				__('Could not connect to OpenAI: %s', 'extend-wp'),
				$response->get_error_message()
			);
			return new \WP_Error(
				'openai_connection_failed',
				$error_msg
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return true;
		}

		$message = isset( $body['error']['message'] )
			? $body['error']['message']
			: __( 'Unknown error from OpenAI.', 'extend-wp' );

		$error_code = 401 === $code ? 'openai_invalid_key' : 'openai_api_error';

		$detailed_message = sprintf(
			'HTTP %d: %s',
			$code,
			$message
		);

		return new \WP_Error($error_code, $detailed_message);
	}

	// -------------------------------------------------------------------------
	// Interface: generation
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function generate( string $prompt, string $model, array $options = [] ): array|\WP_Error {
		/**
		 * Action fired before OpenAI API call.
		 *
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_openai_before_generate', $prompt, $model, $options);

		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return new \WP_Error( 'openai_not_configured', __( 'OpenAI API key is not configured.', 'extend-wp' ) );
		}

		$messages = $this->build_messages( $prompt, $options );

		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 2048,
			'temperature' => isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7,
		];

		/**
		 * Filter OpenAI API request body before sending.
		 *
		 * Allows developers to modify request parameters or add OpenAI-specific options.
		 *
		 * @param array  $body Request body array.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$body = apply_filters('ewp_ai_openai_request_body', $body, $prompt, $model, $options);

		$response = wp_remote_post(
			self::API_BASE . '/chat/completions',
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'openai_connection_failed',
				__( 'Could not connect to OpenAI. Check your internet connection.', 'extend-wp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown error from OpenAI.', 'extend-wp' );
			return new \WP_Error( 'openai_api_error', $message );
		}

		$content = $data['choices'][0]['message']['content'] ?? '';

		$result = [
			'content' => trim( $content ),
			'model'   => $data['model'] ?? $model,
			'usage'   => $data['usage'] ?? [],
		];

		/**
		 * Filter OpenAI API response before returning.
		 *
		 * @param array  $result Processed result array.
		 * @param array  $data Raw API response data.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 *
		 * @since 1.0.0
		 */
		$result = apply_filters('ewp_ai_openai_response', $result, $data, $prompt, $model);

		/**
		 * Action fired after successful OpenAI API call.
		 *
		 * @param array  $result Result array.
		 * @param string $prompt User prompt.
		 * @param string $model Model ID.
		 *
		 * @since 1.0.0
		 */
		do_action('ewp_ai_openai_after_generate', $result, $prompt, $model);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the API key.
	 *
	 * Priority: PHP constant EWP_OPENAI_API_KEY → decrypted value from settings.
	 * Decrypts the API key if it's stored encrypted in the database.
	 *
	 * @return string Plain-text API key, or '' if not set.
	 *
	 * @since 1.0.0
	 */
	private function get_api_key(): string {
		if ( defined( 'EWP_OPENAI_API_KEY' ) && '' !== EWP_OPENAI_API_KEY ) {
			return EWP_OPENAI_API_KEY;
		}

		$settings = EWP_AI_Content::get_settings();
		$encrypted_key = $settings['openai_api_key'] ?? '';

		// Decrypt if encrypted
		if (EWP_Encryption::is_encrypted($encrypted_key)) {
			return EWP_Encryption::decrypt($encrypted_key);
		}

		return $encrypted_key;
	}

	/**
	 * Build the messages array for the Chat Completions API.
	 *
	 * Supports an optional system message and an optional vision image
	 * (base64-encoded JPEG) passed via $options.
	 *
	 * @param string $prompt  User prompt text.
	 * @param array  $options Generation options.
	 * @return array Messages array.
	 *
	 * @since 1.0.0
	 */
	private function build_messages( string $prompt, array $options ): array {
		$messages = [];

		/**
		 * Filter the prompt before building messages.
		 *
		 * @param string $prompt User prompt.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		$prompt = apply_filters('ewp_ai_openai_prompt', $prompt, $options);

		// System message (optional).
		if ( ! empty( $options['system'] ) ) {
			$messages[] = [
				'role'    => 'system',
				'content' => $options['system'],
			];
		}

		// User message — plain text or multimodal (text + image).
		if ( ! empty( $options['image_base64'] ) ) {
			$mime       = $options['image_mime'] ?? 'image/jpeg';
			$messages[] = [
				'role'    => 'user',
				'content' => [
					[
						'type' => 'text',
						'text' => $prompt,
					],
					[
						'type'      => 'image_url',
						'image_url' => [
							'url'    => 'data:' . $mime . ';base64,' . $options['image_base64'],
							'detail' => 'low',
						],
					],
				],
			];
		} else {
			$messages[] = [
				'role'    => 'user',
				'content' => $prompt,
			];
		}

		/**
		 * Filter OpenAI messages array before sending.
		 *
		 * @param array  $messages Messages array.
		 * @param string $prompt User prompt.
		 * @param array  $options Generation options.
		 *
		 * @since 1.0.0
		 */
		return apply_filters('ewp_ai_openai_messages', $messages, $prompt, $options);
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
		$options['openai'] = ['label' => 'OpenAI'];
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

		$fields['openai_api_key'] = [
			'label'       => __('OpenAI API Key', 'extend-wp'),
			'case'        => 'input',
			'type'        => 'text',
			'encrypt'     => true,
			'show_masked' => true,
			'explanation' => __('Your OpenAI API key from platform.openai.com. Stored encrypted.', 'extend-wp'),
			'show-when'   => ['default_provider' => ['values' => ['openai' => true]]],
		];

		$fields['openai_model'] = [
			'label'       => __('OpenAI Model', 'extend-wp'),
			'case'        => 'select',
			'options'     => array_map(fn($label) => ['label' => $label], $models),
			'explanation' => __('Default model for OpenAI requests.', 'extend-wp'),
			'show-when'   => ['default_provider' => ['values' => ['openai' => true]]],
		];

		return $fields;
	}
}

// Register provider option and settings fields filters.
add_filter('ewp_ai_provider_options', ['EWP_AI_OpenAI_Provider', 'register_provider_option']);
add_filter('ewp_ai_provider_settings_fields', ['EWP_AI_OpenAI_Provider', 'register_settings_fields']);
