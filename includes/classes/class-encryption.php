<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global Encryption Utility for Sensitive Meta Fields.
 *
 * Two-way AES-256-CBC encryption using WordPress salts so that sensitive
 * data (API keys, secrets, etc.) are never stored in plain text in the database.
 * The encryption key is derived from AUTH_KEY and the IV from SECURE_AUTH_SALT.
 *
 * NOTE: If WordPress salts are rotated (e.g. after a security incident)
 * all stored encrypted values will become undecryptable and must be re-entered.
 *
 * @package EWP
 * @since   1.3.0
 */
class EWP_Encryption {

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
	 * @param string $algorithm Optional cipher algorithm. Defaults to aes-256-cbc.
	 * @return string Encrypted + base64-encoded string, or '' on empty input.
	 *
	 * @since 1.3.0
	 */
	public static function encrypt( string $value, string $algorithm = '' ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( '' === $algorithm ) {
			$algorithm = apply_filters( 'ewp_encryption_algorithm', self::CIPHER );
		}

		$key = self::derive_key();
		$iv  = self::derive_iv();

		$encrypted = openssl_encrypt( $value, $algorithm, $key, OPENSSL_RAW_DATA, $iv );

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
	 * @param string $algorithm Optional cipher algorithm. Defaults to aes-256-cbc.
	 * @return string Decrypted plain-text value, or '' on failure.
	 *
	 * @since 1.3.0
	 */
	public static function decrypt( string $encrypted, string $algorithm = '' ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		// Not an encrypted value — return as-is (migration safety).
		if ( 0 !== strpos( $encrypted, self::PREFIX ) ) {
			return $encrypted;
		}

		if ( '' === $algorithm ) {
			$algorithm = apply_filters( 'ewp_encryption_algorithm', self::CIPHER );
		}

		$payload = base64_decode( substr( $encrypted, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $payload ) {
			return '';
		}

		$key   = self::derive_key();
		$iv    = self::derive_iv();
		$plain = openssl_decrypt( $payload, $algorithm, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $plain ? $plain : '';
	}

	/**
	 * Return a masked representation for display in the admin UI.
	 *
	 * Shows four asterisks to indicate the field is encrypted without
	 * revealing any information about the stored value.
	 *
	 * @param string $encrypted Encrypted string from the database.
	 * @param string $algorithm Optional cipher algorithm. Defaults to aes-256-cbc.
	 * @return string Masked string, e.g. "****", or '' if empty.
	 *
	 * @since 1.3.0
	 */
	public static function mask( string $encrypted, string $algorithm = '' ): string {
		if ('' === $encrypted) {
			return '';
		}

		return '****';
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
	 * @since 1.3.0
	 */
	public static function is_masked( string $value ): bool {
		return '' !== $value && 0 === strpos( $value, self::MASK_CHAR );
	}

	/**
	 * Check whether a value is already encrypted.
	 *
	 * Used to avoid re-encrypting already encrypted values.
	 *
	 * @param string $value Value to check.
	 * @return bool True if the value is already encrypted.
	 *
	 * @since 1.3.0
	 */
	public static function is_encrypted(string $value): bool
	{
		return '' !== $value && 0 === strpos($value, self::PREFIX);
	}

	/**
	 * Derive a 32-byte encryption key from AUTH_KEY.
	 *
	 * @return string 32-byte binary key.
	 *
	 * @since 1.3.0
	 */
	private static function derive_key(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'ewp-encryption-fallback-key';
		return hash( 'sha256', $salt, true );
	}

	/**
	 * Derive a 16-byte IV from SECURE_AUTH_SALT.
	 *
	 * @return string 16-byte binary IV.
	 *
	 * @since 1.3.0
	 */
	private static function derive_iv(): string {
		$salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'ewp-encryption-fallback-iv';
		return substr( hash( 'sha256', $salt, true ), 0, 16 );
	}
}
