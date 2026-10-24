<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Listeners\AbstractListener;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Tests\Support\RecordingSink;
use Netivo\Module\ActionLogger\Tests\TestCase;
use RuntimeException;

final class AbstractListenerTest extends TestCase {
	private RecordingSink $sink;

	/** @var array<string, callable> */
	private array $callbacks = [];

	protected function setUp(): void {
		parent::setUp();
		$this->sink = new RecordingSink();
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'add_action' )->alias( fn ( string $hook, callable $cb ) => $this->callbacks[ $hook ] = $cb );
		Functions\when( 'add_filter' )->alias( fn ( string $hook, callable $cb ) => $this->callbacks[ $hook ] = $cb );
	}

	private function listener(): AbstractListener {
		return new class( $this->sink, Config::from_array( [] ) ) extends AbstractListener {
			public function register(): void {
				$this->on( 'boom', 'explode' );
				$this->on( 'record', 'record', 10, 2 );
				$this->observe( 'peek', 'explode' );
			}

			public function explode(): void {
				throw new RuntimeException( 'listener bug' );
			}

			public function record( string $action, string $severity ): void {
				$this->log( 'system', $action, [ 'type' => 'plugin' ], null, [ 'extra' => 1 ], $severity, new Actor( 1 ) );
			}
		};
	}

	public function test_hook_callbacks_swallow_listener_errors(): void {
		$this->listener()->register();

		( $this->callbacks['boom'] )();
		self::assertSame( 'original', ( $this->callbacks['peek'] )( 'original' ) );
	}

	public function test_log_dispatches_event_with_request_context(): void {
		$this->listener()->register();

		( $this->callbacks['record'] )( 'plugin_activated', 'warning' );

		$event = $this->sink->last();
		self::assertSame( 'plugin_activated', $event->action() );
		self::assertSame( 'warning', $event->severity() );
		self::assertSame( [ 'extra' => 1, 'context' => 'admin' ], $event->metadata() );
	}

	public function test_log_drops_invalid_events_silently(): void {
		$this->listener()->register();

		( $this->callbacks['record'] )( 'x', 'not-a-severity' );

		self::assertSame( [], $this->sink->events );
	}

	public function test_detects_request_context(): void {
		self::assertSame( 'admin', AbstractListener::context() );

		Functions\when( 'is_admin' )->justReturn( false );
		self::assertSame( 'front', AbstractListener::context() );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		self::assertSame( 'ajax', AbstractListener::context() );

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		self::assertSame( 'cron', AbstractListener::context() );
	}
}
