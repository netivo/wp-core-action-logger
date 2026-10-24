<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Payload;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Tests\TestCase;
use WP_User;

final class ActorTest extends TestCase {
	/** @var array<string, mixed> */
	private array $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
		$_SERVER['REMOTE_ADDR']     = '83.24.110.15';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test)';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		parent::tearDown();
	}

	public function test_builds_actor_from_current_user(): void {
		Functions\when( 'wp_get_current_user' )->justReturn(
			new WP_User(
				[
					'ID'           => 14,
					'user_login'   => 'mswiatek',
					'user_email'   => 'michal@example.com',
					'display_name' => 'Michał',
				]
			)
		);

		self::assertSame(
			[
				'id'           => 14,
				'login'        => 'mswiatek',
				'email'        => 'michal@example.com',
				'display_name' => 'Michał',
				'ip'           => '83.24.110.15',
				'user_agent'   => 'Mozilla/5.0 (Test)',
			],
			Actor::current()->to_array()
		);
	}

	public function test_falls_back_to_anonymous_actor(): void {
		Functions\when( 'wp_get_current_user' )->justReturn( new WP_User() );

		$actor = Actor::current();

		self::assertNull( $actor->id() );
		self::assertSame( [ 'ip' => '83.24.110.15', 'user_agent' => 'Mozilla/5.0 (Test)' ], $actor->to_array() );
		self::assertSame( 'attacker', Actor::anonymous( 'attacker' )->to_array()['login'] );
	}

	public function test_ignores_forwarded_for_from_untrusted_peers(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		self::assertSame( '83.24.110.15', Actor::client_ip() );
		self::assertSame( '83.24.110.15', Actor::client_ip( [ '10.0.0.1' ] ) );
	}

	public function test_uses_rightmost_untrusted_forwarded_address_behind_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 91.200.1.2, 10.0.0.2';

		self::assertSame( '91.200.1.2', Actor::client_ip( [ '10.0.0.1', '10.0.0.2' ] ) );
	}

	public function test_returns_null_for_invalid_remote_address(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		self::assertNull( Actor::client_ip() );
	}

	public function test_truncates_user_agent(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 2000 );

		self::assertSame( 1024, strlen( Actor::anonymous()->to_array()['user_agent'] ) );
	}
}
