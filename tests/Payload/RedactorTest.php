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

	public function test_masks_credential_shaped_substrings_in_free_text(): void {
		$token = 'nt_live_0123456789abcdef0123456789abcdef';
		$jwt   = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

		self::assertSame( [ 'note' => 'Configured key [REDACTED] on staging' ], Redactor::redact( [ 'note' => "Configured key {$token} on staging" ] ) );
		self::assertSame( 'session=[REDACTED]', Redactor::redact( "session={$jwt}" ) );
		self::assertSame( 'header was [REDACTED]', Redactor::redact( 'header was Bearer abcdefghijklmnop1234' ) );
		self::assertSame(
			'key: [REDACTED] end',
			Redactor::redact( "key: -----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY----- end" )
		);
	}

	public function test_leaves_ordinary_strings_alone(): void {
		$value = [ 'title' => 'Bearer of good news', 'slug' => 'nt_live_short', 'jwtish' => 'eyJ.eyJ.x' ];

		self::assertSame( $value, Redactor::redact( $value ) );
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
