<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Payload;

use Netivo\Module\ActionLogger\Payload\Redactor;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class RedactorTest extends TestCase {
	public function test_masks_sensitive_keys_at_any_depth(): void {
		$result = Redactor::redact(
			[
				'user_pass' => 'hunter2',
				'nested'    => [
					'api_key'        => 'abc',
					'session_tokens' => [ 'x' ],
					'title'          => 'Hello',
				],
				'list'      => [ [ 'cookie' => 'wordpress_logged_in=...' ] ],
			]
		);

		self::assertSame(
			[
				'user_pass' => Redactor::REDACTED,
				'nested'    => [
					'api_key'        => Redactor::REDACTED,
					'session_tokens' => Redactor::REDACTED,
					'title'          => 'Hello',
				],
				'list'      => [ [ 'cookie' => Redactor::REDACTED ] ],
			],
			$result
		);
	}

	public function test_masks_card_numbers_but_not_other_digits(): void {
		self::assertSame( Redactor::REDACTED, Redactor::redact( '4111 1111 1111 1111' ) );
		self::assertSame( Redactor::REDACTED, Redactor::redact( '5500-0000-0000-0004' ) );
		self::assertSame( '4111111111111112', Redactor::redact( '4111111111111112' ) );
		self::assertSame( '48600123456', Redactor::redact( '48600123456' ) );
		self::assertSame( 1024, Redactor::redact( 1024 ) );
	}

	public function test_converts_objects_and_limits_depth(): void {
		$object           = new \stdClass();
		$object->password = 'secret';
		$object->name     = 'value';

		self::assertSame( [ 'password' => Redactor::REDACTED, 'name' => 'value' ], Redactor::redact( $object ) );

		$deep = 'leaf';
		for ( $i = 0; $i < 20; $i++ ) {
			$deep = [ 'level' => $deep ];
		}
		self::assertStringNotContainsString( 'leaf', (string) json_encode( Redactor::redact( $deep ) ) );
	}

	public function test_detects_sensitive_key_names(): void {
		foreach ( [ 'password', 'pwd', 'user_pass', 'auth_key', 'nonce', 'card_number', 'cvv', 'Authorization' ] as $key ) {
			self::assertTrue( Redactor::is_sensitive_key( $key ), $key );
		}
		foreach ( [ 'post_title', 'passenger', 'compass', 'email' ] as $key ) {
			self::assertFalse( Redactor::is_sensitive_key( $key ), $key );
		}
	}
}
