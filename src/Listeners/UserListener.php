<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use Netivo\Module\ActionLogger\Payload\EventPayload;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class UserListener
 *
 * User accounts: creation, profile edits (including password and email changes),
 * role changes and deletion.
 */
class UserListener extends AbstractListener {

	/**
	 * Profile fields compared on update.
	 */
	private const PROFILE_FIELDS = [ 'user_login', 'user_email', 'user_nicename', 'user_url', 'display_name' ];

	/**
	 * Roles whose gain or loss is reported as critical.
	 */
	private const PRIVILEGED_ROLES = [ 'administrator', 'shop_manager' ];

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->on( 'user_register', 'user_registered' );
		$this->on( 'profile_update', 'profile_updated', 10, 2 );
		$this->on( 'set_user_role', 'role_changed', 10, 3 );
		$this->on( 'delete_user', 'user_deleted', 10, 3 );
	}

	/**
	 * Account created (by an administrator or through self-registration).
	 *
	 * @param int $user_id New user ID.
	 *
	 * @return void
	 */
	public function user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->log( 'user', 'user_created', $this->user_entity( $user ), null, [ 'roles' => array_values( $user->roles ) ] );
	}

	/**
	 * Profile saved. Logged only when a tracked field or the password changed.
	 *
	 * @param int     $user_id  User ID.
	 * @param WP_User $old_user User before the update.
	 *
	 * @return void
	 */
	public function profile_updated( int $user_id, WP_User $old_user ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$changes = EventPayload::diff( $this->profile( $old_user ), $this->profile( $user ), self::PROFILE_FIELDS );

		$password_changed = $old_user->user_pass !== $user->user_pass;
		if ( $password_changed ) {
			// Masked by the redactor; only the fact that it changed is sent.
			$changes['user_pass'] = [
				'old' => '',
				'new' => '',
			];
		}

		if ( [] === $changes ) {
			return;
		}

		$severity = ( $password_changed || isset( $changes['user_email'] ) ) ? 'warning' : 'info';
		$this->log( 'user', 'profile_updated', $this->user_entity( $user ), $changes, [], $severity );
	}

	/**
	 * Role changed. Calls without previous roles belong to account creation and
	 * are covered by user_created.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 *
	 * @return void
	 */
	public function role_changed( int $user_id, string $role, array $old_roles = [] ): void {
		if ( [] === $old_roles || [ $role ] === array_values( $old_roles ) ) {
			return;
		}

		$user   = get_userdata( $user_id );
		$entity = $user instanceof WP_User ? $this->user_entity( $user ) : [
			'type' => 'user',
			'id'   => $user_id,
		];

		$privileged = [] !== array_intersect( array_merge( [ $role ], $old_roles ), self::PRIVILEGED_ROLES );
		$changes    = [
			'roles' => [
				'old' => array_values( $old_roles ),
				'new' => '' === $role ? [] : [ $role ],
			],
		];

		$this->log( 'user', 'user_role_changed', $entity, $changes, [], $privileged ? 'critical' : 'warning' );
	}

	/**
	 * Account about to be deleted.
	 *
	 * @param int          $user_id  User ID.
	 * @param int|null     $reassign User receiving the deleted user's content, if any.
	 * @param WP_User|null $user     User being deleted (WordPress 5.5+).
	 *
	 * @return void
	 */
	public function user_deleted( int $user_id, ?int $reassign = null, ?WP_User $user = null ): void {
		$user   = $user ?? get_userdata( $user_id );
		$entity = $user instanceof WP_User ? $this->user_entity( $user ) : [
			'type' => 'user',
			'id'   => $user_id,
		];

		$metadata = [ 'reassign' => $reassign ];
		if ( $user instanceof WP_User ) {
			$metadata['roles'] = array_values( $user->roles );
		}

		$this->log( 'user', 'user_deleted', $entity, null, $metadata, 'warning' );
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
	 * Tracked profile fields of a user.
	 *
	 * @param WP_User $user User.
	 *
	 * @return array<string, mixed>
	 */
	private function profile( WP_User $user ): array {
		$fields = [];
		foreach ( self::PROFILE_FIELDS as $field ) {
			$fields[ $field ] = $user->$field ?? null;
		}

		return $fields;
	}
}
