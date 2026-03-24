<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screenshot Generator (PHP side) for EWP AI Content Generator.
 *
 * The actual screenshot capture is performed client-side via html2canvas in
 * class-ewp-ai-content.js. This PHP class handles the server-side concerns:
 *
 * - Providing the frontend URL for the iframe to load.
 * - Formatting a raw base64 image string into the correct structure
 *   expected by each provider's vision API.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Screenshot_Generator {

	/**
	 * Maximum allowed base64 payload size (bytes, ~1 MB decoded).
	 *
	 * Providers reject very large images; this guards against oversized
	 * screenshots sent from the browser.
	 *
	 * @var int
	 */
	private const MAX_BASE64_BYTES = 1_400_000; // ~1 MB decoded

	/**
	 * Return the URL that the JS iframe should load for screenshot capture.
	 *
	 * For published posts: returns the public permalink.
	 * For drafts / pending: returns a preview URL (uses WP preview nonce).
	 * Returns false when no frontend URL can be determined.
	 *
	 * @param int $post_id Post ID.
	 * @return string|false Frontend URL, or false if unavailable.
	 *
	 * @since 1.0.0
	 */
	public function get_frontend_url( int $post_id ): string|false {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// Published post — use the public permalink.
		if ( 'publish' === $post->post_status ) {
			$url = get_permalink( $post_id );
			return $url ?: false;
		}

		// Draft / pending — use WP's built-in preview URL.
		$url = get_preview_post_link( $post );
		return $url ?: false;
	}

	/**
	 * Validate and sanitise a raw base64 screenshot from the browser.
	 *
	 * Strips the data URI prefix if present (e.g. "data:image/jpeg;base64,")
	 * and enforces the maximum payload size.
	 *
	 * @param string $raw Raw base64 string from the JS client.
	 * @return string|\WP_Error Clean base64 string, or WP_Error on failure.
	 *
	 * @since 1.0.0
	 */
	public function sanitise_base64( string $raw ): string|\WP_Error {
		if ( '' === $raw ) {
			return new \WP_Error( 'screenshot_empty', __( 'Screenshot data is empty.', 'extend-wp' ) );
		}

		// Strip data URI prefix if present.
		if ( str_contains( $raw, ';base64,' ) ) {
			$raw = substr( $raw, strpos( $raw, ';base64,' ) + 8 );
		}

		// Validate that the remaining string is valid base64.
		if ( base64_decode( $raw, true ) === false ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return new \WP_Error( 'screenshot_invalid', __( 'Screenshot data is not valid base64.', 'extend-wp' ) );
		}

		// Enforce size limit.
		if ( strlen( $raw ) > self::MAX_BASE64_BYTES ) {
			return new \WP_Error(
				'screenshot_too_large',
				__( 'Screenshot exceeds the maximum allowed size (1 MB). Try disabling screenshots or reducing the browser window size.', 'extend-wp' )
			);
		}

		return $raw;
	}

	/**
	 * Format a clean base64 string for a specific provider's vision API.
	 *
	 * Returns an array that can be merged directly into the $options passed
	 * to EWP_AI_Provider_Interface::generate(). Returns an empty array when
	 * the provider ID is unrecognised (graceful fallback).
	 *
	 * @param string $base64      Clean base64 image string (no data URI prefix).
	 * @param string $provider_id Provider identifier ('openai', 'claude', 'gemini').
	 * @param string $mime        MIME type, default 'image/jpeg'.
	 * @return array Options fragment, e.g. ['image_base64' => '…', 'image_mime' => '…'].
	 *
	 * @since 1.0.0
	 */
	public function prepare_for_provider( string $base64, string $provider_id, string $mime = 'image/jpeg' ): array {
		if ( '' === $base64 ) {
			return [];
		}

		// All three providers use the same $options keys; each provider class
		// translates them into its own API format internally.
		return [
			'image_base64' => $base64,
			'image_mime'   => $mime,
		];
	}
}
