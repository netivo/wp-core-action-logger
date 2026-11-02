<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Listeners\AbstractListener;
use Netivo\Module\ActionLogger\Module;
use Netivo\Module\ActionLogger\Transport\ErrorQueue;
use Netivo\Module\ActionLogger\Transport\EventSink;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use wpdb;

final class ModuleTest extends TestCase {
	private const KEY = 'nt_live_0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		$GLOBALS['wpdb'] = new wpdb();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->alias(
			static fn ( string $name ) => in_array( $name, [ Queue::VERSION_OPTION, ErrorQueue::VERSION_OPTION ], true ) ? Queue::DB_VERSION : false
		);
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private static function active_config(): Config {
		return Config::from_array( [ 'api_url' => 'https://logger.example', 'api_key' => self::KEY ] );
	}

	public function test_defers_boot_until_after_setup_theme(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Actions\expectAdded( 'after_setup_theme' )->once()->with( \Mockery::type( 'array' ), 20 );

		$module = new Module( self::active_config() );

		self::assertNull( $module->dispatcher() );
	}

	public function test_active_module_schedules_worker_and_registers_listeners(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		// The audit, error-queue and error-collector workers each schedule their own
		// cron hook; only the audit worker's exact scheduling is asserted here.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		// RuntimeInterceptor::register() installs a real exception handler for the
		// request's lifetime; stub it so this test doesn't leak that global state.
		Functions\when( 'set_exception_handler' )->justReturn( null );
		Actions\expectAdded( QueueWorker::HOOK )->once();

		$module = new class( self::active_config() ) extends Module {
			public int $registered = 0;

			protected function listeners( EventSink $sink, Config $config ): array {
				$listener = new class( $sink, $config, $this ) extends AbstractListener {
					public function __construct( EventSink $sink, Config $config, private readonly object $owner ) {
						parent::__construct( $sink, $config );
					}

					public function register(): void {
						++$this->owner->registered;
					}
				};

				return [ $listener ];
			}
		};

		self::assertNotNull( $module->dispatcher() );
		self::assertNotNull( $module->error_dispatcher() );
		self::assertSame( 1, $module->registered );
	}

	public function test_inactive_module_captures_nothing_and_unschedules_worker(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		// The audit, error-queue and error-collector workers are all unscheduled.
		$cleared = [];
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( string $hook ) use ( &$cleared ): bool {
				$cleared[] = $hook;

				return true;
			}
		);
		Functions\expect( 'wp_schedule_event' )->never();

		$module = new Module( Config::from_array( [ 'enabled' => false ] ) );

		self::assertNull( $module->dispatcher() );
		self::assertFalse( $module->config()?->is_active() );
		self::assertContains( QueueWorker::HOOK, $cleared );
	}

	public function test_boot_runs_once_and_never_throws(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		unset( $GLOBALS['wpdb'] );

		$module = new Module( self::active_config() );
		$module->boot();

		self::assertNull( $module->dispatcher() );
	}

	public function test_resolves_module_path(): void {
		self::assertSame( realpath( __DIR__ . '/..' ), Module::get_module_path() );
	}
}
