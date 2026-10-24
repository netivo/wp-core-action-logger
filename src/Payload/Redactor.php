<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Payload;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Redactor
 *
 * Masks sensitive values before an event leaves the site. Mirrors the API's
 * server-side rules (apps/api/src/lib/redact.ts) so secrets never travel at all.
 */
final class Redactor {

	/**
	 * Replacement written in place of a sensitive value.
	 */
	public const REDACTED = '[REDACTED]';

	/**
	 * Keys whose values must never be sent (passwords, secrets, cookies, card data).
	 */
	private const SENSITIVE_KEY = '/(passw(or)?d|passphrase|^pass$|^pwd$|^user_pass$|secret|token|api[_-]?key|cookie|session|authorization|auth[_-]?(key|salt)|nonce|^salt$|private[_-]?key|card[_-]?(number|num)|^cc[_-]?(num|number)$|cvv|cvc|security[_-]?code)/i';

	/**
	 * Deepest nesting level that is copied; anything deeper is masked.
	 */
	private const MAX_DEPTH = 12;

	/**
	 * Credentials recognizable by shape inside free-form strings (whose key gave no hint):
	 * site tokens, JWTs, bearer credentials and PEM private keys. Only the match is replaced.
	 */
	private const CREDENTIAL_PATTERNS = [
		'/nt_live_[0-9a-f]{32}/i',
		'/eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
		'/\bBearer\s+[A-Za-z0-9._~+\/=-]{16,}/i',
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
	];

	/**
	 * Returns a copy of the value with sensitive keys and card-like numbers masked.
	 *
	 * @param mixed $value Value to redact.
	 * @param int   $depth Current nesting level.
	 *
	 * @return mixed
	 */
	public static function redact( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > self::MAX_DEPTH ) {
			return self::REDACTED;
		}
		if ( is_string( $value ) ) {
			return self::looks_like_card_number( $value ) ? self::REDACTED : self::mask_credentials( $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $key => $inner ) {
				$result[ $key ] = ( is_string( $key ) && self::is_sensitive_key( $key ) )
					? self::REDACTED
					: self::redact( $inner, $depth + 1 );
			}

			return $result;
		}

		return $value;
	}

	/**
	 * Whether an array key names a sensitive value.
	 *
	 * @param string $key Array key.
	 *
	 * @return bool
	 */
	public static function is_sensitive_key( string $key ): bool {
		return 1 === preg_match( self::SENSITIVE_KEY, $key );
	}

	/**
	 * Replaces credential-shaped substrings with the redaction marker.
	 *
	 * @param string $value Free-form string.
	 *
	 * @return string
	 */
	public static function mask_credentials( string $value ): string {
		$masked = preg_replace( self::CREDENTIAL_PATTERNS, self::REDACTED, $value );

		// preg_replace() returns null on errors (e.g. invalid UTF-8); fail closed.
		return null === $masked ? self::REDACTED : $masked;
	}

	/**
	 * Luhn checksum over 13-19 digits, used to catch raw payment card numbers.
	 *
	 * @param string $value Value to check.
	 *
	 * @return bool
	 */
	public static function looks_like_card_number( string $value ): bool {
		$digits = (string) preg_replace( '/[\s-]/', '', $value );
		if ( 1 !== preg_match( '/^\d{13,19}$/', $digits ) ) {
			return false;
		}

		$sum    = 0;
		$double = false;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];
			if ( $double ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}
}
