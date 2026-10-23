<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Listeners\AbstractListener;
use Netivo\Module\ActionLogger\Tests\Support\RecordingSink;
use Netivo\Module\ActionLogger\Tests\TestCase;
use WP_User;

/**
 * Base for listener tests: captures hook registrations so tests can fire them
 * like WordPress would, and records dispatched events.
 */
abstract class ListenerTestCase extends TestCase {
	protected RecordingSink $sink;

	/** @var array<string, array<int, callable>> */
	protected array $hooks = [];

	/** @var array<string, mixed> */
	private array $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
		$_SERVER['REMOTE_ADDR']     = '83.24.110.15';
		$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

		$this->sink = new RecordingSink();
		$capture    = function ( string $hook, callable $callback ): bool {
			$this->hooks[ $hook ][] = $callback;

			return true;
		};
		Functions\when( 'add_action' )->alias( $capture );
		Functions\when( 'add_filter' )->alias( $capture );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( self::admin() );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		parent::tearDown();
	}

	/**
	 * Creates and registers the listener under test.
	 *
	 * @param class-string<AbstractListener> $class Listener class.
	 */
	protected function listen( string $class ): AbstractListener {
		$listener = new $class( $this->sink, Config::from_array( [] ) );
		$listener->register();

		return $listener;
	}

	/**
	 * Fires a captured hook; for filters returns the (unchanged) value.
	 */
	protected function fire( string $hook, mixed ...$args ): mixed {
		self::assertArrayHasKey( $hook, $this->hooks, "Hook {$hook} was not registered." );
		$result = $args[0] ?? null;
		foreach ( $this->hooks[ $hook ] as $callback ) {
			$result = $callback( ...$args );
		}

		return $result;
	}

	protected static function admin(): WP_User {
		return new WP_User(
			[
				'ID'           => 1,
				'user_login'   => 'admin',
				'user_email'   => 'admin@example.com',
				'display_name' => 'Site Admin',
				'roles'        => [ 'administrator' ],
			]
		);
	}

	/**
	 * Serialized last event.
	 *
	 * @return array<string, mixed>
	 */
	protected function last(): array {
		return $this->sink->last()->to_array();
	}
}
