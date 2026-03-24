<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude (Anthropic) Provider for EWP AI Content Generator.
 *
 * Implements EWP_AI_Provider_Interface using the Anthropic Messages API.
 * Supports Claude 4 Sonnet / Haiku with optional vision (image) input.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Claude_Provider implements EWP_AI_Provider_Interface {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.anthropic.com/v1';

	/**
	 * Anthropic API version header value.
	 *
	 * @var string
	 */
	private const API_VERSION = '2023-06-01';

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
		return 'claude';
	}

	/** @inheritDoc */
	public function get_label(): string {
		return 'Claude (Anthropic)';
	}

	/** @inheritDoc */
	public function get_models(): array {
		return [
			'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
			'claude-haiku-4-20250514'  => 'Claude Haiku 4',
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
		// Anthropic has no cheap "list models" endpoint, so we send a
		// minimal single-token prompt to confirm the key is valid.
		$body = [
			'model'      => 'claude-haiku-4-20250514',
			'max_tokens' => 1,
			'messages'   => [
				[ 'role' => 'user', 'content' => 'Hi' ],
			],
		];

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			[
				'timeout' => 15,
				'headers' => $this->build_headers( $key ),
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'claude_connection_failed',
				__( 'Could not connect to Anthropic. Check your internet connection.', 'extend-wp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 200 (success) or 529 (overloaded but key is valid).
		if ( in_array( $code, [ 200, 529 ], true ) ) {
			return true;
		}

		$message = isset( $data['error']['message'] )
			? $data['error']['message']
			: __( 'Unknown error from Anthropic.', 'extend-wp' );

		$error_code = 401 === $code ? 'claude_invalid_key' : 'claude_api_error';

		return new \WP_Error( $error_code, $message );
	}

	// -------------------------------------------------------------------------
	// Interface: generation
	// -------------------------------------------------------------------------

	/** @inheritDoc */
	public function generate( string $prompt, string $model, array $options = [] ): array|\WP_Error {
		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return new \WP_Error( 'claude_not_configured', __( 'Claude API key is not configured.', 'extend-wp' ) );
		}

		$body = [
			'model'      => $model,
			'max_tokens' => isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 2048,
			'messages'   => $this->build_messages( $prompt, $options ),
		];

		// System message is a top-level field in the Anthropic API.
		if ( ! empty( $options['system'] ) ) {
			$body['system'] = $options['system'];
		}

		// Temperature (default 1.0 for Claude; keep user value if provided).
		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = (float) $options['temperature'];
		}

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			[
				'timeout' => self::TIMEOUT,
				'headers' => $this->build_headers( $api_key ),
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'claude_connection_failed',
				__( 'Could not connect to Anthropic. Check your internet connection.', 'extend-wp' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown error from Anthropic.', 'extend-wp' );
			return new \WP_Error( 'claude_api_error', $message );
		}

		$content = $data['content'][0]['text'] ?? '';

		return [
			'content' => trim( $content ),
			'model'   => $data['model'] ?? $model,
			'usage'   => $data['usage'] ?? [],
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the decrypted API key.
	 *
	 * Priority: PHP constant EWP_CLAUDE_API_KEY → encrypted DB value.
	 *
	 * @return string Plain-text API key, or '' if not set.
	 *
	 * @since 1.0.0
	 */
	private function get_api_key(): string {
		if ( defined( 'EWP_CLAUDE_API_KEY' ) && '' !== EWP_CLAUDE_API_KEY ) {
			return EWP_CLAUDE_API_KEY;
		}

		$settings = EWP_AI_Content::get_settings();
		return EWP_AI_Encryption::decrypt( $settings['claude_api_key'] ?? '' );
	}

	/**
	 * Build Anthropic request headers.
	 *
	 * @param string $api_key Plain-text API key.
	 * @return array Headers array.
	 *
	 * @since 1.0.0
	 */
	private function build_headers( string $api_key ): array {
		return [
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		];
	}

	/**
	 * Build the messages array for the Anthropic Messages API.
	 *
	 * Supports optional vision image via $options['image_base64'].
	 *
	 * @param string $prompt  User prompt text.
	 * @param array  $options Generation options.
	 * @return array Messages array.
	 *
	 * @since 1.0.0
	 */
	private function build_messages( string $prompt, array $options ): array {
		if ( ! empty( $options['image_base64'] ) ) {
			$mime = $options['image_mime'] ?? 'image/jpeg';

			return [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'   => 'text',
							'text'   => $prompt,
						],
						[
							'type'   => 'image',
							'source' => [
								'type'       => 'base64',
								'media_type' => $mime,
								'data'       => $options['image_base64'],
							],
						],
					],
				],
			];
		}

		return [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];
	}
}
