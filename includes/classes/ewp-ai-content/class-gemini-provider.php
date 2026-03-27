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

		return [
			'content' => trim( $content ),
			'model'   => $model,
			'usage'   => $usage,
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the decrypted API key.
	 *
	 * Priority: PHP constant EWP_GEMINI_API_KEY → encrypted DB value.
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
		return $settings['gemini_api_key'] ?? '';
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

		return [
			[
				'role'  => 'user',
				'parts' => $parts,
			],
		];
	}
}
