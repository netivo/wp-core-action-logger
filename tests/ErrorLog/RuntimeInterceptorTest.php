<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\ErrorLog;

use Brain\Monkey\Functions;
use Mockery;
use Netivo\Module\ActionLogger\ErrorLog\RuntimeInterceptor;
use Netivo\Module\ActionLogger\Payload\ErrorPayload;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\ErrorSink;
use RuntimeException;
use Throwable;

final class RuntimeInterceptorTest extends TestCase {
	public function test_register_hooks_shutdown_and_chains_the_previous_exception_handler(): void {
		$sink = Mockery::mock( ErrorSink::class );
		$sink->shouldReceive( 'dispatch' )->once();
		$interceptor = new RuntimeInterceptor( $sink );

		$previous_called_with = null;
		$previous              = function ( Throwable $e ) use ( &$previous_called_with ): void {
			$previous_called_with = $e;
		};

		Functions\expect( 'add_action' )->once()->with( 'shutdown', [ $interceptor, 'handle_fatal' ], 1 );
		Functions\expect( 'set_exception_handler' )->once()->with( [ $interceptor, 'handle_exception' ] )->andReturn( $previous );

		$interceptor->register();

		$exception = new RuntimeException( 'boom' );
		$interceptor->handle_exception( $exception );

		self::assertSame( $exception, $previous_called_with );
	}

	public function test_handle_fatal_reports_a_fatal_error(): void {
		$captured = null;
		$sink     = Mockery::mock( ErrorSink::class );
		$sink->shouldReceive( 'dispatch' )->once()->with(
			Mockery::on(
				function ( ErrorPayload $payload ) use ( &$captured ): bool {
					$captured = $payload;

					return true;
				}
			)
		);

		Functions\when( 'error_get_last' )->justReturn( [ 'type' => E_ERROR, 'message' => 'Out of memory', 'file' => '/x.php', 'line' => 10 ] );

		( new RuntimeInterceptor( $sink ) )->handle_fatal();

		self::assertSame( 'fatal', $captured->level() );
	}

	public function test_handle_fatal_maps_a_parse_error_to_the_parse_level(): void {
		$captured = null;
		$sink     = Mockery::mock( ErrorSink::class );
		$sink->shouldReceive( 'dispatch' )->once()->with(
			Mockery::on(
				function ( ErrorPayload $payload ) use ( &$captured ): bool {
					$captured = $payload;

					return true;
				}
			)
		);

		Functions\when( 'error_get_last' )->justReturn( [ 'type' => E_PARSE, 'message' => 'syntax error', 'file' => '/x.php', 'line' => 1 ] );

		( new RuntimeInterceptor( $sink ) )->handle_fatal();

		self::assertSame( 'parse', $captured->level() );
	}

	public function test_handle_fatal_ignores_a_non_fatal_last_error(): void {
		$sink = Mockery::mock( ErrorSink::class );
		$sink->shouldNotReceive( 'dispatch' );

		Functions\when( 'error_get_last' )->justReturn( [ 'type' => E_WARNING, 'message' => 'x', 'file' => '/x.php', 'line' => 1 ] );

		( new RuntimeInterceptor( $sink ) )->handle_fatal();
	}

	public function test_handle_fatal_does_nothing_when_there_is_no_error(): void {
		$sink = Mockery::mock( ErrorSink::class );
		$sink->shouldNotReceive( 'dispatch' );

		Functions\when( 'error_get_last' )->justReturn( null );

		( new RuntimeInterceptor( $sink ) )->handle_fatal();
	}

	public function test_handle_exception_reports_with_the_class_and_message(): void {
		$captured = null;
		$sink     = Mockery::mock( ErrorSink::class );
		$sink->shouldReceive( 'dispatch' )->once()->with(
			Mockery::on(
				function ( ErrorPayload $payload ) use ( &$captured ): bool {
					$captured = $payload;

					return true;
				}
			)
		);

		( new RuntimeInterceptor( $sink ) )->handle_exception( new RuntimeException( 'boom' ) );

		self::assertSame( 'fatal', $captured->level() );
	}
}
