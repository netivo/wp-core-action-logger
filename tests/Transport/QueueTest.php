<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Transport;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Queue;
use wpdb;

final class QueueTest extends TestCase {
	private wpdb $db;

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->db    = new wpdb();
		$this->queue = new Queue( $this->db );
	}

	public function test_installs_table_once_per_schema_version(): void {
		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! file_exists( $upgrade ) ) {
			mkdir( dirname( $upgrade ), 0777, true );
			file_put_contents( $upgrade, '<?php' );
		}

		Functions\expect( 'get_option' )->twice()->with( Queue::VERSION_OPTION )->andReturn( false, Queue::DB_VERSION );
		Functions\expect( 'dbDelta' )
			->once()
			->with( \Mockery::on( static fn ( string $sql ): bool => str_contains( $sql, 'CREATE TABLE wp_nt_action_log_queue' ) && str_contains( $sql, 'PRIMARY KEY  (id)' ) ) );
		Functions\expect( 'update_option' )->once()->with( Queue::VERSION_OPTION, Queue::DB_VERSION, true );

		$this->queue->install();
		$this->queue->install();
	}

	public function test_pushes_events_in_one_insert_and_trims_overflow(): void {
		$this->db->results['get_var'] = [ null ];

		$stored = $this->queue->push( [ [ 'action' => 'a' ], [ 'action' => "it's" ] ], 'cURL error 7' );

		self::assertSame( 2, $stored );
		self::assertStringStartsWith( 'INSERT INTO `wp_nt_action_log_queue` (event_payload, attempts, created_at, last_error) VALUES (', $this->db->queries[0] );
		self::assertSame( 2, substr_count( $this->db->queries[0], "'cURL error 7'" ) );
		self::assertStringContainsString( "it\\'s", $this->db->queries[0] );
		self::assertStringContainsString( 'OFFSET ' . Queue::MAX_ROWS, $this->db->queries[1] );
		self::assertCount( 2, $this->db->queries );
	}

	public function test_push_without_error_stores_zero_attempts(): void {
		$this->db->results['get_var'] = [ '42' ];

		$this->queue->push( [ [ 'action' => 'a' ] ] );

		self::assertStringContainsString( ', 0, ', $this->db->queries[0] );
		self::assertStringEndsWith( ', NULL)', $this->db->queries[0] );
		self::assertSame( 'DELETE FROM `wp_nt_action_log_queue` WHERE id <= 42', $this->db->queries[2] );
	}

	public function test_returns_due_events_decoded_as_objects(): void {
		$this->db->results['get_results'] = [
			[
				[ 'id' => '5', 'attempts' => '2', 'event_payload' => '{"action":"wp_login","entity":{}}' ],
				[ 'id' => '6', 'attempts' => '0', 'event_payload' => 'not json' ],
			],
		];

		$due = $this->queue->due( 50 );

		self::assertCount( 1, $due );
		self::assertSame( 5, $due[0]['id'] );
		self::assertSame( 2, $due[0]['attempts'] );
		self::assertSame( '{"action":"wp_login","entity":{}}', json_encode( $due[0]['payload'] ) );
		self::assertStringContainsString( 'next_attempt_at IS NULL OR next_attempt_at <=', $this->db->queries[0] );
		self::assertStringEndsWith( 'LIMIT 50', $this->db->queries[0] );
		self::assertSame( 'DELETE FROM `wp_nt_action_log_queue` WHERE id IN (6)', $this->db->queries[1] );
	}

	public function test_release_schedules_backoff(): void {
		$this->queue->release( [ 3, 4 ], 'HTTP 503' );

		self::assertStringContainsString( 'SET attempts = attempts + 1', $this->db->queries[0] );
		self::assertStringContainsString( "last_error = 'HTTP 503'", $this->db->queries[0] );
		self::assertStringContainsString( 'LEAST(3600, 60 * POW(2, LEAST(attempts, 6)))', $this->db->queries[0] );
		self::assertStringEndsWith( 'WHERE id IN (3, 4)', $this->db->queries[0] );
	}

	public function test_ignores_empty_id_lists(): void {
		$this->queue->delete( [] );
		$this->queue->release( [], 'x' );

		self::assertSame( [], $this->db->queries );
	}

	public function test_counts_and_drops_exhausted_rows(): void {
		$this->db->results['get_var'] = [ '17', '2026-10-23 10:00:00' ];
		$this->db->results['query']   = [ 3 ];

		self::assertSame( 17, $this->queue->count() );
		self::assertSame( '2026-10-23 10:00:00', $this->queue->oldest() );
		self::assertSame( 3, $this->queue->drop_exhausted( 20 ) );
		self::assertSame( 'DELETE FROM `wp_nt_action_log_queue` WHERE attempts >= 20', $this->db->queries[2] );
	}
}
