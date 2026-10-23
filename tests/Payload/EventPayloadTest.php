<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Payload;

use InvalidArgumentException;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Payload\Redactor;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class EventPayloadTest extends TestCase {
	public function test_serializes_in_ingestion_api_shape(): void {
		$event = EventPayload::create(
			'content',
			'post_updated',
			new Actor( 14, 'mswiatek' ),
			[ 'type' => 'post', 'id' => 1024, 'title' => 'Valve' ],
			[ 'post_status' => [ 'old' => 'draft', 'new' => 'publish' ] ],
			[ 'context' => 'admin' ]
		);

		$data = $event->to_array();

		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['event_id'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $data['occurred_at'] );
		self::assertSame( 'content', $data['category'] );
		self::assertSame( 'post_updated', $data['action'] );
		self::assertSame( 'info', $data['severity'] );
		self::assertSame( [ 'id' => 14, 'login' => 'mswiatek' ], $data['actor'] );
		self::assertSame( [ 'post_status' => [ 'old' => 'draft', 'new' => 'publish' ] ], $data['changes'] );
		self::assertSame( [ 'context' => 'admin' ], $data['metadata'] );
	}

	public function test_encodes_empty_maps_as_objects_and_omits_empty_sections(): void {
		$json = (string) json_encode( EventPayload::create( 'auth', 'wp_logout', new Actor() )->to_array() );

		self::assertStringContainsString( '"actor":{}', $json );
		self::assertStringContainsString( '"entity":{}', $json );
		self::assertStringNotContainsString( '"changes"', $json );
		self::assertStringNotContainsString( '"metadata"', $json );
	}

	public function test_redacts_secrets_and_keeps_change_shape(): void {
		$data = EventPayload::create(
			'user',
			'profile_updated',
			new Actor( 1 ),
			[ 'type' => 'user', 'id' => 3 ],
			[
				'user_pass'  => [ 'old' => 'a', 'new' => 'b' ],
				'user_email' => [ 'old' => 'a@x.pl', 'new' => 'b@x.pl' ],
			],
			[ 'request' => [ 'pwd' => 'hunter2', 'card' => '4111111111111111' ] ]
		)->to_array();

		self::assertSame( [ 'old' => Redactor::REDACTED, 'new' => Redactor::REDACTED ], $data['changes']['user_pass'] );
		self::assertSame( [ 'old' => 'a@x.pl', 'new' => 'b@x.pl' ], $data['changes']['user_email'] );
		self::assertSame( [ 'pwd' => Redactor::REDACTED, 'card' => Redactor::REDACTED ], $data['metadata']['request'] );
	}

	public function test_truncates_entity_fields_and_sanitizes_action(): void {
		$data = EventPayload::create(
			'content',
			'custom action/with spaces',
			new Actor(),
			[ 'title' => str_repeat( 'ą', 1500 ), 'slug' => str_repeat( 's', 600 ) ]
		)->to_array();

		self::assertSame( 'custom_action_with_spaces', $data['action'] );
		self::assertSame( 1000, mb_strlen( $data['entity']['title'] ) );
		self::assertSame( 500, strlen( $data['entity']['slug'] ) );
	}

	public function test_rejects_unknown_category_and_severity(): void {
		$this->expectException( InvalidArgumentException::class );
		EventPayload::create( 'billing', 'paid', new Actor() );
	}

	public function test_rejects_unknown_severity(): void {
		$this->expectException( InvalidArgumentException::class );
		EventPayload::create( 'auth', 'wp_login', new Actor(), [], null, [], 'fatal' );
	}

	public function test_generates_unique_event_ids(): void {
		$ids = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = EventPayload::create( 'auth', 'wp_login', new Actor() )->event_id();
		}

		self::assertCount( 50, array_unique( $ids ) );
	}

	public function test_diff_reports_only_changed_fields(): void {
		$changes = EventPayload::diff(
			[ 'post_title' => 'Old', 'menu_order' => 5, 'post_status' => 'draft', 'tags' => [ 1, 2 ] ],
			[ 'post_title' => 'New', 'menu_order' => '5', 'post_status' => 'draft', 'tags' => [ 1, 3 ] ]
		);

		self::assertSame(
			[
				'post_title' => [ 'old' => 'Old', 'new' => 'New' ],
				'tags'       => [ 'old' => [ 1, 2 ], 'new' => [ 1, 3 ] ],
			],
			$changes
		);
		self::assertSame( [], EventPayload::diff( [ 'a' => 1, 'b' => 2 ], [ 'a' => 1, 'b' => 3 ], [ 'a' ] ) );
	}
}
