<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Payload;

use InvalidArgumentException;
use Netivo\Module\ActionLogger\Payload\ErrorPayload;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class ErrorPayloadTest extends TestCase {
	public function test_serializes_in_ingestion_api_shape(): void {
		$error = ErrorPayload::create(
			'wp_debug_log',
			'fatal',
			'Uncaught Error: Call to undefined function get_header()',
			rtrim( ABSPATH, '/' ) . '/index.php',
			12,
			"Stack trace:\n#0 {main}",
			[ 'php_version' => '8.3.0' ],
			'2026-11-02T14:15:02.000Z',
			3,
			rtrim( ABSPATH, '/' ) . '/wp-content/debug.log'
		);

		$data = $error->to_array();

		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['error_id'] );
		self::assertSame( 'wp_debug_log', $data['source'] );
		self::assertSame( 'wp-content/debug.log', $data['source_file'] );
		self::assertSame( 'fatal', $data['level'] );
		self::assertSame( 'Uncaught Error: Call to undefined function get_header()', $data['message'] );
		self::assertSame( 'index.php', $data['file'] );
		self::assertSame( 12, $data['line'] );
		self::assertSame( "Stack trace:\n#0 {main}", $data['stack_trace'] );
		self::assertSame( '2026-11-02T14:15:02.000Z', $data['occurred_at'] );
		self::assertSame( 3, $data['occurrences'] );
		self::assertSame( [ 'php_version' => '8.3.0' ], $data['metadata'] );
	}

	public function test_omits_optional_fields_when_absent(): void {
		$data = ErrorPayload::create( 'runtime_fatal', 'fatal', 'Out of memory' )->to_array();

		self::assertArrayNotHasKey( 'source_file', $data );
		self::assertArrayNotHasKey( 'file', $data );
		self::assertArrayNotHasKey( 'line', $data );
		self::assertArrayNotHasKey( 'stack_trace', $data );
		self::assertArrayNotHasKey( 'metadata', $data );
		self::assertSame( 1, $data['occurrences'] );
	}

	public function test_rejects_an_unknown_level(): void {
		$this->expectException( InvalidArgumentException::class );

		ErrorPayload::create( 'wp_debug_log', 'bogus', 'message' );
	}

	public function test_redacts_credential_shaped_secrets_from_message_and_stack_trace(): void {
		// Redactor's string-level masking recognizes credential shapes (site
		// tokens, Bearer headers, JWTs, PEM keys), not arbitrary "password=..."
		// text; key-based masking only applies to array values (see RedactorTest).
		$data = ErrorPayload::create(
			'wp_debug_log',
			'error',
			'API call failed with token nt_live_0123456789abcdef0123456789abcdef',
			null,
			null,
			'Authorization: Bearer abcdefghijklmnopqrstuvwx1234567890'
		)->to_array();

		self::assertStringNotContainsString( 'nt_live_0123456789abcdef0123456789abcdef', $data['message'] );
		self::assertStringNotContainsString( 'abcdefghijklmnopqrstuvwx1234567890', $data['stack_trace'] );
	}

	public function test_strips_absolute_paths_from_file_fields(): void {
		$data = ErrorPayload::create(
			'wp_debug_log',
			'warning',
			'message',
			rtrim( ABSPATH, '/' ) . '/wp-content/plugins/x/x.php',
			10
		)->to_array();

		self::assertSame( 'wp-content/plugins/x/x.php', $data['file'] );
	}

	public function test_falls_back_to_a_default_message_when_blank(): void {
		$data = ErrorPayload::create( 'wp_debug_log', 'notice', '   ' )->to_array();

		self::assertSame( 'Unknown error', $data['message'] );
	}
}
