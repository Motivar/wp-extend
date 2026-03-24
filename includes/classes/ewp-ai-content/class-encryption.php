<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Key Encryption for EWP AI Content Generator.
 *
 * Two-way AES-256-CBC encryption using WordPress salts so that API keys
 * are never stored in plain text in wp_options. The encryption key is
 * derived from AUTH_KEY and the IV from SECURE_AUTH_SALT.
 *
 * NOTE: If WordPress salts are rotated (e.g. after a security incident)
 * all stored keys will become undecryptable and must be re-entered.
 *
 * @package EWP\AIContent
 * @since   1.0.0
 */
class EWP_AI_Encryption {

	/**
	 * Cipher algorithm.
	 *
	 * @var string
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Prefix stored in the DB to distinguish encrypted values.
	 *
	 * @var string
	 */
	private const PREFIX = 'ewp_enc:';

	/**
	 * Mask placeholder character shown in the admin UI.
	 *
	 * @var string
	 */
	private const MASK_CHAR = '•';

	/**
	 * Encrypt a plain-text value using WP salts.
	 *
	 * Returns the encrypted payload as a base64 string prefixed with
	 * self::PREFIX so it can be identified later. Returns an empty
	 * string when $value is empty.
	 *
	 * @param string $value Plain-text value to encrypt.
	 * @return string Encrypted + base64-encoded string, or '' on empty input.
	 *
	 * @since 1.0.0
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = self::derive_key();
		$iv  = self::derive_iv();

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		return self::PREFIX . base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * Accepts either an encrypted string (with self::PREFIX) or a plain
	 * string (legacy / never-encrypted). Returns '' on failure.
	 *
	 * @param string $encrypted Encrypted string from the database.
	 * @return string Decrypted plain-text value, or '' on failure.
	 *
	 * @since 1.0.0
	 */
	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		// Not an encrypted value — return as-is (migration safety).
		if ( 0 !== strpos( $encrypted, self::PREFIX ) ) {
			return $encrypted;
		}

		$payload = base64_decode( substr( $encrypted, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $payload ) {
			return '';
		}

		$key   = self::derive_key();
		$iv    = self::derive_iv();
		$plain = openssl_decrypt( $payload, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $plain ? $plain : '';
	}

	/**
	 * Return a masked representation for display in the admin UI.
	 *
	 * Shows eight bullet characters followed by the last four characters
	 * of the decrypted value so the user can confirm which key is stored
	 * without revealing the full secret.
	 *
	 * @param string $encrypted Encrypted string from the database.
	 * @return string Masked string, e.g. "••••••••abcd", or '' if empty.
	 *
	 * @since 1.0.0
	 */
	public static function mask( string $encrypted ): string {
		$plain = self::decrypt( $encrypted );

		if ( '' === $plain ) {
			return '';
		}

		$suffix = mb_substr( $plain, -4 );

		return str_repeat( self::MASK_CHAR, 8 ) . $suffix;
	}

	/**
	 * Check whether a raw input value is the mask placeholder.
	 *
	 * Used during settings save: if the user did not change the key
	 * the input will contain the masked value — skip re-encryption.
	 *
	 * @param string $value Raw input value from $_POST.
	 * @return bool True when the value looks like an unmodified mask.
	 *
	 * @since 1.0.0
	 */
	public static function is_masked( string $value ): bool {
		return '' !== $value && 0 === strpos( $value, self::MASK_CHAR );
	}

	/**
	 * Derive a 32-byte encryption key from AUTH_KEY.
	 *
	 * @return string 32-byte binary key.
	 *
	 * @since 1.0.0
	 */
	private static function derive_key(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'ewp-ai-content-fallback-key';
		return hash( 'sha256', $salt, true );
	}

	/**
	 * Derive a 16-byte IV from SECURE_AUTH_SALT.
	 *
	 * @return string 16-byte binary IV.
	 *
	 * @since 1.0.0
	 */
	private static function derive_iv(): string {
		$salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'ewp-ai-content-fallback-iv';
		return substr( hash( 'sha256', $salt, true ), 0, 16 );
	}
}
