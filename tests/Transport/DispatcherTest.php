<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Transport;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use Netivo\Module\ActionLogger\Transport\Dispatcher;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\Status;
use RuntimeException;

final class DispatcherTest extends TestCase {
	/** @var Queue&MockInterface */
	private Queue $queue;

	/** @var Client&MockInterface */
	private Client $client;

	/** @var Status&MockInterface */
	private Status $status;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->queue  = Mockery::mock( Queue::class );
		$this->client = Mockery::mock( Client::class );
		$this->status = Mockery::mock( Status::class );
		$this->status->allows( 'is_auth_blocked' )->andReturn( false )->byDefault();
	}

	private function dispatcher( string $delivery = Config::DELIVERY_SHUTDOWN ): Dispatcher {
		return new Dispatcher( Config::from_array( [ 'delivery' => $delivery ] ), $this->queue, $this->client, $this->status );
	}

	private static function event( string $action = 'wp_login' ): EventPayload {
		return EventPayload::create( 'auth', $action, new Actor() );
	}

	/**
	 * @param array<int, int>   $delivered
	 * @param array<int, int>   $failed
	 * @param array<int, int>   $rejected
	 * @return array{delivered: array<int, int>, failed: array<int, int>, rejected: array<int, int>, result: DeliveryResult}
	 */
	private static function report( array $delivered, array $failed = [], array $rejected = [], ?DeliveryResult $result = null ): array {
		return [
			'delivered' => $delivered,
			'failed'    => $failed,
			'rejected'  => $rejected,
			'result'    => $result ?? new DeliveryResult( DeliveryResult::DELIVERED ),
		];
	}

	public function test_buffers_events_and_registers_shutdown_once(): void {
		Actions\expectAdded( 'shutdown' )->once()->with( Mockery::type( 'array' ), 1000 );
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( self::event() );
		$dispatcher->dispatch( self::event( 'wp_logout' ) );

		self::assertSame( 2, $dispatcher->pending() );
	}

	public function test_flush_delivers_buffer_in_one_request(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->dispatch( self::event() );
		$dispatcher->dispatch( self::event( 'wp_logout' ) );

		$this->client->expects( 'deliver' )
			->once()
			->with( Mockery::on( static fn ( array $events ): bool => [ 'wp_login', 'wp_logout' ] === array_column( $events, 'action' ) ) )
			->andReturn( self::report( [ 0, 1 ] ) );
		$this->status->expects( 'record_success' )->once();
		$this->queue->expects( 'push' )->never();

		$dispatcher->flush();
		$dispatcher->flush();

		self::assertSame( 0, $dispatcher->pending() );
	}

	public function test_queues_failed_events_with_the_error(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->dispatch( self::event( 'a' ) );
		$dispatcher->dispatch( self::event( 'b' ) );
		$failure = new DeliveryResult( DeliveryResult::RETRY, null, 'cURL error 7: Failed to connect' );

		$this->client->expects( 'deliver' )->andReturn( self::report( [], [ 0, 1 ], [], $failure ) );
		$this->queue->expects( 'push' )
			->once()
			->with( Mockery::on( static fn ( array $events ): bool => [ 'a', 'b' ] === array_column( $events, 'action' ) ), 'cURL error 7: Failed to connect' );
		$this->status->expects( 'record_failure' )->once()->with( $failure );

		$dispatcher->flush();
	}

	public function test_records_rejected_events_as_dropped(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->dispatch( self::event( 'a' ) );
		$dispatcher->dispatch( self::event( 'b' ) );
		$rejected = new DeliveryResult( DeliveryResult::REJECTED, 422, 'HTTP 422: Invalid event schema.' );

		$this->client->expects( 'deliver' )->andReturn( self::report( [ 0 ], [], [ 1 ], $rejected ) );
		$this->status->expects( 'record_success' )->once();
		$this->status->expects( 'record_dropped' )->once()->with( 1, 'HTTP 422: Invalid event schema.' );
		$this->queue->expects( 'push' )->never();

		$dispatcher->flush();
	}

	public function test_queue_mode_skips_the_api(): void {
		$dispatcher = $this->dispatcher( Config::DELIVERY_QUEUE );
		$dispatcher->dispatch( self::event() );

		$this->client->expects( 'deliver' )->never();
		$this->queue->expects( 'push' )->once()->with( Mockery::type( 'array' ) );

		$dispatcher->flush();
	}

	public function test_queues_while_the_token_is_refused(): void {
		$this->status->allows( 'is_auth_blocked' )->andReturn( true );
		$dispatcher = $this->dispatcher();
		$dispatcher->dispatch( self::event() );

		$this->client->expects( 'deliver' )->never();
		$this->queue->expects( 'push' )->once();

		$dispatcher->flush();
	}

	public function test_splits_large_buffers_into_batches(): void {
		$dispatcher = $this->dispatcher();
		for ( $i = 0; $i < Dispatcher::BATCH_SIZE + 5; $i++ ) {
			$dispatcher->dispatch( self::event() );
		}

		$this->client->expects( 'deliver' )->twice()->andReturn( self::report( [ 0 ] ) );
		$this->status->allows( 'record_success' );

		$dispatcher->flush();
	}

	public function test_never_throws(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->dispatch( self::event() );
		$this->client->expects( 'deliver' )->andThrow( new RuntimeException( 'boom' ) );

		$dispatcher->flush();

		self::assertSame( 0, $dispatcher->pending() );
	}
}
