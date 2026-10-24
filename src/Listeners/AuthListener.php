<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class AuthListener
 *
 * Logins, logouts, failed login attempts, password resets and application passwords.
 */
class AuthListener extends AbstractListener {

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->on( 'wp_login', 'logged_in', 10, 2 );
		$this->on( 'wp_logout', 'logged_out' );
		$this->on( 'wp_login_failed', 'login_failed', 10, 2 );
		$this->on( 'retrieve_password', 'password_reset_requested' );
		$this->on( 'after_password_reset', 'password_reset' );
		$this->on( 'wp_create_application_password', 'application_password_created', 10, 3 );
		$this->on( 'wp_delete_application_password', 'application_password_deleted', 10, 2 );
	}

	/**
	 * Successful sign-in.
	 *
	 * @param string  $user_login Login used.
	 * @param WP_User $user       Signed-in user.
	 *
	 * @return void
	 */
	public function logged_in( string $user_login, WP_User $user ): void {
		$this->log( 'auth', 'wp_login', $this->user_entity( $user ), null, [ 'roles' => array_values( $user->roles ) ], 'info', $this->actor_for( $user ) );
	}

	/**
	 * Sign-out. The current user is already cleared, so the ID passed by WordPress is used.
	 *
	 * @param int $user_id User who signed out.
	 *
	 * @return void
	 */
	public function logged_out( int $user_id = 0 ): void {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) {
			$this->log( 'auth', 'wp_logout', [ 'type' => 'user', 'id' => $user_id ] );

			return;
		}

		$this->log( 'auth', 'wp_logout', $this->user_entity( $user ), null, [], 'info', $this->actor_for( $user ) );
	}

	/**
	 * Failed sign-in attempt.
	 *
	 * @param string        $username Attempted username or email.
	 * @param WP_Error|null $error    Authentication error (WordPress 5.4+).
	 *
	 * @return void
	 */
	public function login_failed( string $username, ?WP_Error $error = null ): void {
		$metadata = [];
		if ( $error instanceof WP_Error ) {
			$metadata['reason'] = $error->get_error_code();
		}

		$this->log( 'auth', 'wp_login_failed', [ 'type' => 'user', 'title' => $username ], null, $metadata, 'warning', $this->anonymous( $username ) );
	}

	/**
	 * Password reset link requested.
	 *
	 * @param string $user_login Login the reset was requested for.
	 *
	 * @return void
	 */
	public function password_reset_requested( string $user_login ): void {
		$this->log( 'auth', 'password_reset_requested', [ 'type' => 'user', 'title' => $user_login ], null, [], 'info', $this->anonymous( $user_login ) );
	}

	/**
	 * Password changed through the reset form. The new password is never captured.
	 *
	 * @param WP_User $user User whose password was reset.
	 *
	 * @return void
	 */
	public function password_reset( WP_User $user ): void {
		$this->log( 'auth', 'password_reset', $this->user_entity( $user ), null, [], 'warning', $this->actor_for( $user ) );
	}

	/**
	 * Application password created.
	 *
	 * @param int                  $user_id  Owner of the password.
	 * @param array<string, mixed> $item     Password details (name, uuid, ...).
	 * @param string               $password Generated password (never captured).
	 *
	 * @return void
	 */
	public function application_password_created( int $user_id, array $item, string $password = '' ): void {
		unset( $password );
		$this->log( 'auth', 'application_password_created', $this->user_entity_by_id( $user_id ), null, $this->application_password( $item ), 'warning' );
	}

	/**
	 * Application password revoked.
	 *
	 * @param int                  $user_id Owner of the password.
	 * @param array<string, mixed> $item    Password details.
	 *
	 * @return void
	 */
	public function application_password_deleted( int $user_id, array $item ): void {
		$this->log( 'auth', 'application_password_deleted', $this->user_entity_by_id( $user_id ), null, $this->application_password( $item ) );
	}

	/**
	 * Entity describing a user.
	 *
	 * @param WP_User $user User.
	 *
	 * @return array<string, mixed>
	 */
	private function user_entity( WP_User $user ): array {
		return [
			'type'  => 'user',
			'id'    => (int) $user->ID,
			'title' => $user->user_login,
		];
	}

	/**
	 * Entity describing a user known only by ID.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array<string, mixed>
	 */
	private function user_entity_by_id( int $user_id ): array {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $this->user_entity( $user ) : [
			'type' => 'user',
			'id'   => $user_id,
		];
	}

	/**
	 * Safe subset of application password details.
	 *
	 * @param array<string, mixed> $item Password details.
	 *
	 * @return array<string, mixed>
	 */
	private function application_password( array $item ): array {
		return array_intersect_key( $item, array_flip( [ 'uuid', 'name', 'app_id' ] ) );
	}
}
