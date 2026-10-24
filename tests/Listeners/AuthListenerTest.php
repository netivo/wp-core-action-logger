<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Listeners\AuthListener;
use WP_Error;
use WP_User;

final class AuthListenerTest extends ListenerTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->listen( AuthListener::class );
	}

	private static function editor(): WP_User {
		return new WP_User(
			[
				'ID'           => 7,
				'user_login'   => 'editor',
				'user_email'   => 'editor@example.com',
				'display_name' => 'Ed',
				'roles'        => [ 'editor' ],
			]
		);
	}

	public function test_logs_successful_login_as_the_signed_in_user(): void {
		$this->fire( 'wp_login', 'editor', self::editor() );

		$event = $this->last();
		self::assertSame( 'auth', $event['category'] );
		self::assertSame( 'wp_login', $event['action'] );
		self::assertSame( 'info', $event['severity'] );
		self::assertSame( 7, $event['actor']['id'] );
		self::assertSame( '83.24.110.15', $event['actor']['ip'] );
		self::assertSame( [ 'type' => 'user', 'id' => 7, 'title' => 'editor' ], $event['entity'] );
		self::assertSame( [ 'editor' ], $event['metadata']['roles'] );
	}

	public function test_logs_logout_for_the_user_id_passed_by_wordpress(): void {
		Functions\expect( 'get_userdata' )->once()->with( 7 )->andReturn( self::editor() );

		$this->fire( 'wp_logout', 7 );

		self::assertSame( 'wp_logout', $this->last()['action'] );
		self::assertSame( 'editor', $this->last()['actor']['login'] );
	}

	public function test_logs_failed_login_as_anonymous_warning(): void {
		$this->fire( 'wp_login_failed', 'root', new WP_Error( 'incorrect_password', 'The password is incorrect.' ) );

		$event = $this->last();
		self::assertSame( 'wp_login_failed', $event['action'] );
		self::assertSame( 'warning', $event['severity'] );
		self::assertSame( [ 'login' => 'root', 'ip' => '83.24.110.15', 'user_agent' => 'PHPUnit' ], $event['actor'] );
		self::assertSame( 'incorrect_password', $event['metadata']['reason'] );
	}

	public function test_logs_password_reset_flow_without_the_password(): void {
		$this->fire( 'retrieve_password', 'editor' );
		$this->fire( 'after_password_reset', self::editor(), 'n3w-Secret!' );

		self::assertSame( [ 'password_reset_requested', 'password_reset' ], $this->sink->actions() );
		self::assertSame( 'warning', $this->last()['severity'] );
		self::assertStringNotContainsString( 'n3w-Secret!', (string) json_encode( $this->sink->payloads() ) );
	}

	public function test_logs_application_passwords_without_the_secret(): void {
		Functions\when( 'get_userdata' )->justReturn( self::editor() );

		$this->fire( 'wp_create_application_password', 7, [ 'uuid' => 'u-1', 'name' => 'CI', 'password' => 'hash' ], 'abcd efgh' );
		$this->fire( 'wp_delete_application_password', 7, [ 'uuid' => 'u-1', 'name' => 'CI' ] );

		self::assertSame( [ 'application_password_created', 'application_password_deleted' ], $this->sink->actions() );
		self::assertSame( [ 'uuid' => 'u-1', 'name' => 'CI', 'context' => 'admin' ], $this->sink->events[0]->metadata() );
		self::assertStringNotContainsString( 'abcd efgh', (string) json_encode( $this->sink->payloads() ) );
	}
}
