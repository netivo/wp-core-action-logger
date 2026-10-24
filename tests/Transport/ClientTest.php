<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Transport;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use WP_Error;

final class ClientTest extends TestCase {
	private const KEY = 'nt_live_0123456789abcdef0123456789abcdef';

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	private Client $client;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.3' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $r ) => $r['body'] );

		$this->client = new Client( Config::from_array( [ 'api_url' => 'https://logger.example', 'api_key' => self::KEY, 'timeout' => 4 ] ) );
	}

	/**
	 * Makes wp_remote_post answer with the given responses in order.
	 *
	 * @param array<int, array<string, mixed>|WP_Error|callable> $responses Responses or callables receiving the decoded events.
	 */
	private function respond( array $responses ): void {
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ) use ( &$responses ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				$next             = array_shift( $responses );
				if ( is_callable( $next ) ) {
					$next = $next( json_decode( $args['body'], true )['events'] );
				}

				return $next;
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function http( int $code, string $message = '' ): array {
		$body = '' === $message ? '{"success":true}' : (string) json_encode( [ 'error' => [ 'message' => $message ] ] );

		return [ 'response' => [ 'code' => $code ], 'body' => $body ];
	}

	public function test_posts_a_blocking_authenticated_batch(): void {
		$this->respond( [ self::http( 202 ) ] );

		$result = $this->client->send( [ [ 'action' => 'wp_login', 'entity' => new \stdClass() ] ] );

		self::assertTrue( $result->is_delivered() );
		self::assertSame( 'https://logger.example/api/v1/ingest', $this->requests[0]['url'] );
		$args = $this->requests[0]['args'];
		self::assertTrue( $args['blocking'] );
		self::assertSame( 4, $args['timeout'] );
		self::assertSame( 'Bearer ' . self::KEY, $args['headers']['Authorization'] );
		self::assertSame( '{"system_type":"wordpress","events":[{"action":"wp_login","entity":{}}]}', $args['body'] );
		self::assertStringStartsWith( 'netivo-action-logger/', $args['user-agent'] );
	}

	public function test_classifies_failures(): void {
		$this->respond(
			[
				new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ),
				self::http( 401, 'The provided site token is invalid or inactive.' ),
				self::http( 422, 'Invalid event schema.' ),
				self::http( 503 ),
				self::http( 429 ),
			]
		);

		$timeout = $this->client->send( [ [] ] );
		self::assertSame( DeliveryResult::RETRY, $timeout->status() );
		self::assertNull( $timeout->code() );
		self::assertSame( 'cURL error 28: Connection timed out', $timeout->error() );

		$auth = $this->client->send( [ [] ] );
		self::assertSame( DeliveryResult::UNAUTHORIZED, $auth->status() );
		self::assertSame( 'HTTP 401: The provided site token is invalid or inactive.', $auth->error() );

		self::assertSame( DeliveryResult::REJECTED, $this->client->send( [ [] ] )->status() );
		self::assertSame( DeliveryResult::RETRY, $this->client->send( [ [] ] )->status() );
		self::assertSame( DeliveryResult::RETRY, $this->client->send( [ [] ] )->status() );
	}

	public function test_deliver_bisects_rejected_batches_to_isolate_bad_events(): void {
		// The API rejects any batch containing the event with action "bad".
		$judge = static fn ( array $events ) => in_array( 'bad', array_column( $events, 'action' ), true ) ? self::http( 422, 'Invalid event schema.' ) : self::http( 202 );
		$this->respond( array_fill( 0, 20, $judge ) );

		$events = [
			10 => [ 'action' => 'a' ],
			11 => [ 'action' => 'b' ],
			12 => [ 'action' => 'bad' ],
			13 => [ 'action' => 'c' ],
		];

		$report = $this->client->deliver( $events );

		self::assertSame( [ 10, 11, 13 ], array_values( array_merge( $report['delivered'] ) ) );
		self::assertSame( [ 12 ], $report['rejected'] );
		self::assertSame( [], $report['failed'] );
		self::assertSame( DeliveryResult::REJECTED, $report['result']->status() );
		self::assertCount( 5, $this->requests );
	}

	public function test_deliver_keeps_unsent_events_when_api_fails_midway(): void {
		$this->respond( [ self::http( 422 ), self::http( 202 ), self::http( 503 ) ] );

		$report = $this->client->deliver( [ 1 => [ 'n' => 1 ], 2 => [ 'n' => 2 ], 3 => [ 'n' => 3 ], 4 => [ 'n' => 4 ] ] );

		self::assertSame( [ 1, 2 ], $report['delivered'] );
		self::assertSame( [ 3, 4 ], $report['failed'] );
		self::assertSame( [], $report['rejected'] );
		self::assertSame( DeliveryResult::RETRY, $report['result']->status() );
	}

	public function test_deliver_marks_everything_failed_on_auth_error(): void {
		$this->respond( [ self::http( 401 ) ] );

		$report = $this->client->deliver( [ 7 => [], 8 => [] ] );

		self::assertSame( [ 7, 8 ], $report['failed'] );
		self::assertSame( DeliveryResult::UNAUTHORIZED, $report['result']->status() );
	}
}
