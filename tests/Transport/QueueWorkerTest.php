<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Transport;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use Netivo\Module\ActionLogger\Transport\Status;
use RuntimeException;

final class QueueWorkerTest extends TestCase {
	/** @var Queue&MockInterface */
	private Queue $queue;

	/** @var Client&MockInterface */
	private Client $client;

	/** @var Status&MockInterface */
	private Status $status;

	private QueueWorker $worker;

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->queue  = Mockery::mock( Queue::class );
		$this->client = Mockery::mock( Client::class );
		$this->status = Mockery::mock( Status::class );
		$this->queue->allows( 'drop_exhausted' )->with( QueueWorker::MAX_ATTEMPTS )->andReturn( 0 )->byDefault();
		$this->status->allows( 'record_dropped' )->with( 0, Mockery::any() )->byDefault();

		$this->worker = new QueueWorker( $this->queue, $this->client, $this->status );
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, array{id: int, attempts: int, payload: object}>
	 */
	private static function rows( array $ids ): array {
		return array_map( static fn ( int $id ): array => [ 'id' => $id, 'attempts' => 0, 'payload' => (object) [ 'n' => $id ] ], $ids );
	}

	/**
	 * @param array<int, int> $delivered
	 * @param array<int, int> $failed
	 * @param array<int, int> $rejected
	 * @return array<string, mixed>
	 */
	private static function report( array $delivered, array $failed = [], array $rejected = [], ?DeliveryResult $result = null ): array {
		return [
			'delivered' => $delivered,
			'failed'    => $failed,
			'rejected'  => $rejected,
			'result'    => $result ?? new DeliveryResult( DeliveryResult::DELIVERED ),
		];
	}

	public function test_registers_schedule_and_hook(): void {
		Filters\expectAdded( 'cron_schedules' )->once();
		Actions\expectAdded( QueueWorker::HOOK )->once();

		$this->worker->register();
		$schedules = $this->worker->add_schedule( [] );

		self::assertSame( 300, $schedules[ QueueWorker::SCHEDULE ]['interval'] );
	}

	public function test_schedules_only_when_missing(): void {
		Functions\expect( 'wp_next_scheduled' )->twice()->with( QueueWorker::HOOK )->andReturn( false, 1700000000 );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), QueueWorker::SCHEDULE, QueueWorker::HOOK );

		$this->worker->schedule();
		$this->worker->schedule();
	}

	public function test_drains_batches_until_queue_is_empty(): void {
		$this->queue->expects( 'due' )->times( 3 )->with( QueueWorker::BATCH_SIZE )->andReturn( self::rows( [ 1, 2 ] ), self::rows( [ 3 ] ), [] );
		$this->client->expects( 'deliver' )
			->twice()
			->with( Mockery::on( static fn ( array $events ): bool => isset( $events[1] ) || isset( $events[3] ) ) )
			->andReturn( self::report( [ 1, 2 ] ), self::report( [ 3 ] ) );
		$this->queue->expects( 'delete' )->with( [ 1, 2 ] )->once();
		$this->queue->expects( 'delete' )->with( [ 3 ] )->once();
		$this->status->expects( 'record_success' )->twice();

		self::assertSame( 3, $this->worker->drain() );
	}

	public function test_backs_off_and_stops_when_the_api_is_unavailable(): void {
		$failure = new DeliveryResult( DeliveryResult::RETRY, 503, 'HTTP 503' );
		$this->queue->expects( 'due' )->once()->andReturn( self::rows( [ 4, 5 ] ) );
		$this->client->expects( 'deliver' )->andReturn( self::report( [], [ 4, 5 ], [], $failure ) );
		$this->queue->expects( 'delete' )->with( [] );
		$this->queue->expects( 'release' )->once()->with( [ 4, 5 ], 'HTTP 503' );
		$this->status->expects( 'record_failure' )->once()->with( $failure );

		self::assertSame( 0, $this->worker->drain() );
	}

	public function test_keeps_attempts_when_the_token_is_refused(): void {
		$failure = new DeliveryResult( DeliveryResult::UNAUTHORIZED, 401, 'HTTP 401' );
		$this->queue->expects( 'due' )->once()->andReturn( self::rows( [ 4 ] ) );
		$this->client->expects( 'deliver' )->andReturn( self::report( [], [ 4 ], [], $failure ) );
		$this->queue->expects( 'delete' )->with( [] );
		$this->queue->expects( 'release' )->never();
		$this->status->expects( 'record_failure' )->once();

		$this->worker->drain();
	}

	public function test_deletes_and_records_rejected_events(): void {
		$rejected = new DeliveryResult( DeliveryResult::REJECTED, 422, 'HTTP 422' );
		$this->queue->expects( 'due' )->twice()->andReturn( self::rows( [ 6, 7 ] ), [] );
		$this->client->expects( 'deliver' )->andReturn( self::report( [ 6 ], [], [ 7 ], $rejected ) );
		$this->queue->expects( 'delete' )->once()->with( [ 6, 7 ] );
		$this->status->expects( 'record_success' )->once();
		$this->status->expects( 'record_dropped' )->once()->with( 1, 'HTTP 422' );

		$this->worker->drain();
	}

	public function test_drops_exhausted_events_first(): void {
		$this->queue->expects( 'drop_exhausted' )->with( QueueWorker::MAX_ATTEMPTS )->andReturn( 3 );
		$this->status->expects( 'record_dropped' )->once()->with( 3, 'too many failed delivery attempts' );
		$this->queue->expects( 'due' )->andReturn( [] );

		$this->worker->drain();
	}

	public function test_skips_when_another_run_holds_the_lock(): void {
		Functions\when( 'get_transient' )->justReturn( 1700000000 );
		$this->queue->expects( 'due' )->never();

		self::assertSame( 0, $this->worker->drain() );
	}

	public function test_releases_lock_and_swallows_errors(): void {
		$released = false;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$released ): bool {
				$released = true;

				return true;
			}
		);
		$this->queue->expects( 'due' )->andThrow( new RuntimeException( 'db gone' ) );

		self::assertSame( 0, $this->worker->drain() );
		self::assertTrue( $released );
	}
}
