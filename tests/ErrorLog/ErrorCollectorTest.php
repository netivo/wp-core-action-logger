<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\ErrorLog;

use Brain\Monkey\Functions;
use Mockery;
use Netivo\Module\ActionLogger\ErrorLog\ErrorCollector;
use Netivo\Module\ActionLogger\ErrorLog\FileScanner;
use Netivo\Module\ActionLogger\ErrorLog\FileTailer;
use Netivo\Module\ActionLogger\ErrorLog\LogParser;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use Netivo\Module\ActionLogger\Transport\ErrorQueue;

final class ErrorCollectorTest extends TestCase {
	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		mkdir( WP_CONTENT_DIR, 0777, true );

		$this->options = [];
		Functions\when( 'get_option' )->alias( fn ( string $name, mixed $default = false ): mixed => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( glob( WP_CONTENT_DIR . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( WP_CONTENT_DIR );
		parent::tearDown();
	}

	private function delivered( array $events ): array {
		return [
			'delivered' => array_keys( $events ),
			'failed'    => [],
			'rejected'  => [],
			'result'    => new DeliveryResult( DeliveryResult::DELIVERED ),
		];
	}

	public function test_collects_and_delivers_entries_found_in_a_discovered_log(): void {
		file_put_contents( WP_CONTENT_DIR . '/debug.log', "[02-Nov-2026 09:00:00 UTC] PHP Warning:  Oops in /a.php on line 1\n" );

		$sent   = null;
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'deliver_errors' )->once()->andReturnUsing(
			function ( array $events ) use ( &$sent ): array {
				$sent = $events;

				return $this->delivered( $events );
			}
		);
		$queue = Mockery::mock( ErrorQueue::class );
		$queue->shouldNotReceive( 'push' );

		$collector = new ErrorCollector( new FileScanner(), new FileTailer(), new LogParser(), $client, $queue );

		self::assertSame( 1, $collector->collect() );
		self::assertSame( 'wp_debug_log', $sent[0]['source'] );
		self::assertSame( 'warning', $sent[0]['level'] );
		self::assertSame( 'Oops', $sent[0]['message'] );
	}

	public function test_queues_events_the_api_could_not_accept(): void {
		file_put_contents( WP_CONTENT_DIR . '/debug.log', "[02-Nov-2026 09:00:00 UTC] PHP Fatal error:  Boom in /a.php on line 1\n" );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'deliver_errors' )->once()->andReturnUsing(
			fn ( array $events ): array => [
				'delivered' => [],
				'failed'    => array_keys( $events ),
				'rejected'  => [],
				'result'    => new DeliveryResult( DeliveryResult::RETRY, null, 'network error' ),
			]
		);
		$queue = Mockery::mock( ErrorQueue::class );
		$queue->shouldReceive( 'push' )->once()->with( Mockery::on( fn ( array $events ): bool => 1 === count( $events ) ), 'network error' );

		$collector = new ErrorCollector( new FileScanner(), new FileTailer(), new LogParser(), $client, $queue );

		$collector->collect();
	}

	public function test_returns_zero_and_calls_nothing_when_no_log_files_exist(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldNotReceive( 'deliver_errors' );
		$queue = Mockery::mock( ErrorQueue::class );

		$collector = new ErrorCollector( new FileScanner(), new FileTailer(), new LogParser(), $client, $queue );

		self::assertSame( 0, $collector->collect() );
	}
}
