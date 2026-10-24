<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Listeners\UserListener;
use WP_User;

final class UserListenerTest extends ListenerTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->listen( UserListener::class );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function user( array $data = [] ): WP_User {
		return new WP_User(
			$data + [
				'ID'           => 12,
				'user_login'   => 'anna',
				'user_email'   => 'anna@example.com',
				'display_name' => 'Anna',
				'user_pass'    => '$wp$hash-1',
				'roles'        => [ 'editor' ],
			]
		);
	}

	public function test_logs_account_creation_with_roles(): void {
		Functions\when( 'get_userdata' )->justReturn( self::user( [ 'roles' => [ 'customer' ] ] ) );

		$this->fire( 'user_register', 12 );

		$event = $this->last();
		self::assertSame( 'user_created', $event['action'] );
		self::assertSame( [ 'type' => 'user', 'id' => 12, 'title' => 'anna' ], $event['entity'] );
		self::assertSame( [ 'customer' ], $event['metadata']['roles'] );
		self::assertSame( 1, $event['actor']['id'] );
	}

	public function test_logs_profile_changes_and_masks_password(): void {
		Functions\when( 'get_userdata' )->justReturn( self::user( [ 'user_email' => 'anna@netivo.pl', 'user_pass' => '$wp$hash-2' ] ) );

		$this->fire( 'profile_update', 12, self::user() );

		$event = $this->last();
		self::assertSame( 'profile_updated', $event['action'] );
		self::assertSame( 'warning', $event['severity'] );
		self::assertSame( [ 'old' => 'anna@example.com', 'new' => 'anna@netivo.pl' ], $event['changes']['user_email'] );
		self::assertSame( [ 'old' => '[REDACTED]', 'new' => '[REDACTED]' ], $event['changes']['user_pass'] );
		self::assertStringNotContainsString( 'hash-2', (string) json_encode( $event ) );
	}

	public function test_minor_profile_change_is_info_and_no_change_is_skipped(): void {
		Functions\when( 'get_userdata' )->justReturn( self::user( [ 'display_name' => 'Anna N.' ] ) );
		$this->fire( 'profile_update', 12, self::user() );

		Functions\when( 'get_userdata' )->justReturn( self::user() );
		$this->fire( 'profile_update', 12, self::user() );

		self::assertCount( 1, $this->sink->events );
		self::assertSame( 'info', $this->last()['severity'] );
	}

	public function test_privilege_escalation_is_critical(): void {
		Functions\when( 'get_userdata' )->justReturn( self::user() );

		$this->fire( 'set_user_role', 12, 'administrator', [ 'subscriber' ] );

		$event = $this->last();
		self::assertSame( 'user_role_changed', $event['action'] );
		self::assertSame( 'critical', $event['severity'] );
		self::assertSame( [ 'roles' => [ 'old' => [ 'subscriber' ], 'new' => [ 'administrator' ] ] ], $event['changes'] );
	}

	public function test_unprivileged_role_change_is_warning_and_creation_is_skipped(): void {
		Functions\when( 'get_userdata' )->justReturn( self::user() );

		$this->fire( 'set_user_role', 12, 'subscriber', [] );
		$this->fire( 'set_user_role', 12, 'editor', [ 'editor' ] );
		$this->fire( 'set_user_role', 12, 'author', [ 'editor' ] );

		self::assertCount( 1, $this->sink->events );
		self::assertSame( 'warning', $this->last()['severity'] );
	}

	public function test_logs_deletion_with_reassignment(): void {
		$this->fire( 'delete_user', 12, 1, self::user() );

		$event = $this->last();
		self::assertSame( 'user_deleted', $event['action'] );
		self::assertSame( 'warning', $event['severity'] );
		self::assertSame( 1, $event['metadata']['reassign'] );
		self::assertSame( [ 'editor' ], $event['metadata']['roles'] );
	}
}
