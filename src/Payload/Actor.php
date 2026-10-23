<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Payload;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Actor
 *
 * Identity of whoever performed an action: the WordPress user (when known),
 * the client IP and the user agent.
 */
final class Actor {

	/**
	 * Actor constructor.
	 *
	 * @param int|null    $id           WordPress user ID.
	 * @param string|null $login        User login (or the attempted login for failures).
	 * @param string|null $email        User email.
	 * @param string|null $display_name Display name.
	 * @param string|null $ip           Client IP address.
	 * @param string|null $user_agent   Client user agent.
	 */
	public function __construct(
		private readonly ?int $id = null,
		private readonly ?string $login = null,
		private readonly ?string $email = null,
		private readonly ?string $display_name = null,
		private readonly ?string $ip = null,
		private readonly ?string $user_agent = null
	) {
	}

	/**
	 * Actor for the currently signed-in user, or an anonymous actor.
	 *
	 * @param string[] $trusted_proxies Proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return self
	 */
	public static function current( array $trusted_proxies = [] ): self {
		$user = wp_get_current_user();
		if ( $user->exists() ) {
			return self::from_user( $user, $trusted_proxies );
		}

		return self::anonymous( null, $trusted_proxies );
	}

	/**
	 * Actor for a specific user, with the current request's IP and user agent.
	 *
	 * @param WP_User  $user            WordPress user.
	 * @param string[] $trusted_proxies Proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return self
	 */
	public static function from_user( WP_User $user, array $trusted_proxies = [] ): self {
		return new self(
			(int) $user->ID,
			self::limit( $user->user_login, 255 ),
			self::limit( $user->user_email, 255 ),
			self::limit( $user->display_name, 255 ),
			self::client_ip( $trusted_proxies ),
			self::user_agent()
		);
	}

	/**
	 * Actor without a WordPress account (e.g. a failed login attempt).
	 *
	 * @param string|null $login           Attempted login, if any.
	 * @param string[]    $trusted_proxies Proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return self
	 */
	public static function anonymous( ?string $login = null, array $trusted_proxies = [] ): self {
		return new self( null, self::limit( $login, 255 ), null, null, self::client_ip( $trusted_proxies ), self::user_agent() );
	}

	/**
	 * Resolves the client IP. X-Forwarded-For is only honoured when the direct peer
	 * is a trusted proxy; the right-most untrusted address is used, since entries
	 * to its left can be forged by the client.
	 *
	 * @param string[] $trusted_proxies Proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return string|null
	 */
	public static function client_ip( array $trusted_proxies = [] ): ?string {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- every address is validated with FILTER_VALIDATE_IP, and forwarded headers are only read from trusted proxies; this is an audit trail, not cached output.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( ! in_array( $remote, $trusted_proxies, true ) || empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote;
		}

		$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		foreach ( array_reverse( array_map( 'trim', $forwarded ) ) as $candidate ) {
			if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				break;
			}
			if ( ! in_array( $candidate, $trusted_proxies, true ) ) {
				return $candidate;
			}
		}
		// phpcs:enable

		return $remote;
	}

	/**
	 * Current request's user agent, truncated to the API limit.
	 *
	 * @return string|null
	 */
	private static function user_agent(): ?string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}

		return self::limit( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 1024 ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- audit trail, not output caching.
	}

	/**
	 * Truncates a string to the given length, mapping empty values to null.
	 *
	 * @param string|null $value  Value to truncate.
	 * @param int         $length Maximum length in characters.
	 *
	 * @return string|null
	 */
	private static function limit( ?string $value, int $length ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return mb_substr( $value, 0, $length );
	}

	/**
	 * User ID, if the actor is a WordPress user.
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Serializes the actor in the ingestion API shape, omitting unknown fields.
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return array_filter(
			[
				'id'           => $this->id,
				'login'        => $this->login,
				'email'        => $this->email,
				'display_name' => $this->display_name,
				'ip'           => $this->ip,
				'user_agent'   => $this->user_agent,
			],
			static fn ( $value ): bool => null !== $value
		);
	}
}
