<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Transport;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use Netivo\Module\ActionLogger\Transport\Status;

final class StatusTest extends TestCase {
	/** @var array<string, mixed> */
	private array $stored = [];

	private int $writes = 0;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( fn () => $this->stored );
		Functions\when( 'update_option' )->alias(
			function ( string $name, array $value, bool $autoload ): bool {
				self::assertSame( Status::OPTION, $name );
				self::assertFalse( $autoload );
				$this->stored = $value;
				++$this->writes;

				return true;
			}
		);
	}

	public function test_unauthorized_failure_pauses_sending_until_success(): void {
		$status = new Status();
		$status->record_failure( new DeliveryResult( DeliveryResult::UNAUTHORIZED, 401, 'HTTP 401' ) );

		self::assertTrue( $status->is_auth_blocked() );
		self::assertSame( 'HTTP 401', $status->all()['last_error'] );
		self::assertSame( 401, $status->all()['last_error_code'] );

		$status->record_success();

		self::assertFalse( $status->is_auth_blocked() );
		self::assertIsInt( $status->all()['last_success_at'] );
	}

	public function test_throttles_success_writes(): void {
		$status = new Status();
		$status->record_success();
		$status->record_success();
		$status->record_success();

		self::assertSame( 1, $this->writes );
	}

	public function test_retry_failure_does_not_pause_sending(): void {
		$status = new Status();
		$status->record_failure( new DeliveryResult( DeliveryResult::RETRY, 503, 'HTTP 503' ) );

		self::assertFalse( $status->is_auth_blocked() );
	}

	public function test_counts_dropped_events(): void {
		$status = new Status();
		$status->record_dropped( 2, 'HTTP 422: Invalid event schema.' );
		$status->record_dropped( 1, 'too many attempts' );
		$status->record_dropped( 0, 'ignored' );

		self::assertSame( 3, $status->all()['dropped_total'] );
		self::assertSame( '1 event(s) dropped: too many attempts', $status->all()['last_error'] );
	}
}
