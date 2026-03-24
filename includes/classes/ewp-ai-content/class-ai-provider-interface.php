<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider Interface for EWP AI Content Generator.
 *
 * Every AI provider (OpenAI, Claude, Gemini, …) must implement this
 * interface so the Content Generator can work with any of them
 * interchangeably.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
interface EWP_AI_Provider_Interface {

	/**
	 * Return the provider's unique identifier.
	 *
	 * Used as the key in settings storage and REST payloads.
	 * Must be lowercase, no spaces (e.g. 'openai', 'claude', 'gemini').
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_id(): string;

	/**
	 * Return the human-readable provider label.
	 *
	 * Shown in the settings page and meta box dropdowns.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function get_label(): string;

	/**
	 * Return available models for this provider.
	 *
	 * Keyed by model ID (sent to the API), valued by display label.
	 * Example: [ 'gpt-4o' => 'GPT-4o', 'gpt-4o-mini' => 'GPT-4o Mini' ]
	 *
	 * @return array<string, string>
	 *
	 * @since 1.0.0
	 */
	public function get_models(): array;

	/**
	 * Check whether the provider has an API key configured.
	 *
	 * Does NOT make a network request — only checks that a non-empty
	 * (decrypted) key exists in settings.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function is_configured(): bool;

	/**
	 * Validate an API key by making a lightweight network request.
	 *
	 * Called from the health-check REST endpoint when the user saves
	 * settings. Implementations should use the cheapest possible call
	 * (e.g. list models, or a minimal prompt) to confirm the key works.
	 *
	 * @param string $key Plain-text API key to test.
	 * @return true|\WP_Error  True on success, WP_Error with a
	 *                         human-readable message on failure.
	 *
	 * @since 1.0.0
	 */
	public function validate_key( string $key ): bool|\WP_Error;

	/**
	 * Generate content from a prompt.
	 *
	 * @param string  $prompt  The full prompt (system + user combined, or
	 *                         just the user message — each provider handles
	 *                         the split internally).
	 * @param string  $model   Model ID from get_models() keys.
	 * @param array   $options {
	 *     Optional generation parameters.
	 *
	 *     @type int        $max_tokens   Maximum tokens in the response.
	 *     @type float      $temperature  Sampling temperature (0–2).
	 *     @type string     $system       Separate system message (if the
	 *                                    provider supports it).
	 *     @type string     $image_base64 Base64-encoded JPEG screenshot to
	 *                                    include as a vision input.
	 *     @type string     $image_mime   MIME type of the image, default
	 *                                    'image/jpeg'.
	 * }
	 * @return array|\WP_Error {
	 *     On success, an associative array:
	 *
	 *     @type string $content  Generated text content.
	 *     @type string $model    Actual model used (may differ from request).
	 *     @type array  $usage    Token usage data from the provider.
	 * }
	 *
	 * @since 1.0.0
	 */
	public function generate( string $prompt, string $model, array $options = [] ): array|\WP_Error;
}
