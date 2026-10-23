<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Listeners\ContentListener;
use WP_Post;
use WP_Term;

final class ContentListenerTest extends ListenerTestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		$this->listen( ContentListener::class );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function post( array $data = [] ): WP_Post {
		return new WP_Post(
			$data + [
				'ID'           => 1024,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'About us',
				'post_name'    => 'about-us',
				'post_content' => 'Hello',
			]
		);
	}

	public function test_logs_changed_fields_and_content_length_only(): void {
		$before = self::post();
		$after  = self::post( [ 'post_title' => 'About Netivo', 'post_content' => 'Hello world' ] );

		$this->fire( 'post_updated', 1024, $after, $before );

		$event = $this->last();
		self::assertSame( 'post_updated', $event['action'] );
		self::assertSame( [ 'type' => 'post', 'subtype' => 'page', 'id' => 1024, 'title' => 'About Netivo', 'slug' => 'about-us' ], $event['entity'] );
		self::assertSame(
			[
				'post_title'          => [ 'old' => 'About us', 'new' => 'About Netivo' ],
				'post_content_length' => [ 'old' => 5, 'new' => 11 ],
			],
			$event['changes']
		);
		self::assertStringNotContainsString( 'Hello world', (string) json_encode( $event ) );
	}

	public function test_skips_saves_without_changes(): void {
		$this->fire( 'post_updated', 1024, self::post(), self::post() );

		self::assertSame( [], $this->sink->events );
	}

	public function test_names_publish_transitions(): void {
		$this->fire( 'post_updated', 1, self::post(), self::post( [ 'post_status' => 'draft' ] ) );
		$this->fire( 'post_updated', 1, self::post( [ 'post_status' => 'draft' ] ), self::post() );

		self::assertSame( [ 'post_published', 'post_unpublished' ], $this->sink->actions() );
		self::assertSame( [ 'old' => 'publish', 'new' => 'draft' ], $this->last()['changes']['post_status'] );
	}

	public function test_first_save_of_auto_draft_is_creation(): void {
		$this->fire( 'wp_insert_post', 1, self::post( [ 'post_status' => 'auto-draft' ] ), false );
		$this->fire( 'post_updated', 1, self::post( [ 'post_status' => 'draft' ] ), self::post( [ 'post_status' => 'auto-draft' ] ) );

		self::assertSame( [ 'post_created' ], $this->sink->actions() );
		self::assertSame( 'draft', $this->last()['metadata']['status'] );
	}

	public function test_logs_programmatic_inserts_but_not_updates(): void {
		$this->fire( 'wp_insert_post', 5, self::post(), false );
		$this->fire( 'wp_insert_post', 5, self::post(), true );

		self::assertSame( [ 'post_created' ], $this->sink->actions() );
	}

	public function test_masks_password_changes(): void {
		$this->fire( 'post_updated', 1, self::post( [ 'post_password' => 'secret' ] ), self::post() );

		self::assertSame( [ 'old' => '[REDACTED]', 'new' => '[REDACTED]' ], $this->last()['changes']['post_password'] );
	}

	public function test_ignores_revisions_internal_and_woocommerce_types(): void {
		Functions\when( 'wp_is_post_revision' )->alias( static fn ( WP_Post $post ) => 'revision' === $post->post_status );

		$this->fire( 'post_updated', 1, self::post( [ 'post_status' => 'revision', 'post_title' => 'x' ] ), self::post() );
		$this->fire( 'post_updated', 1, self::post( [ 'post_type' => 'customize_changeset', 'post_title' => 'x' ] ), self::post( [ 'post_type' => 'customize_changeset' ] ) );
		$this->fire( 'post_updated', 1, self::post( [ 'post_type' => 'product', 'post_title' => 'x' ] ), self::post( [ 'post_type' => 'product' ] ) );

		self::assertSame( [], $this->sink->events );
	}

	public function test_logs_trash_restore_and_delete_once_each(): void {
		Functions\when( 'get_post' )->justReturn( self::post( [ 'post_status' => 'trash' ] ) );

		$this->fire( 'post_updated', 1, self::post( [ 'post_status' => 'trash' ] ), self::post() );
		$this->fire( 'trashed_post', 1 );
		$this->fire( 'untrashed_post', 1, 'publish' );
		$this->fire( 'before_delete_post', 1, self::post( [ 'post_status' => 'trash' ] ) );

		self::assertSame( [ 'post_trashed', 'post_restored', 'post_deleted' ], $this->sink->actions() );
		self::assertSame( 'warning', $this->last()['severity'] );
		self::assertSame( 'publish', $this->sink->events[1]->metadata()['previous_status'] );
	}

	public function test_logs_media_uploads_and_deletions(): void {
		$attachment = self::post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'logo', 'post_mime_type' => 'image/png' ] );
		Functions\when( 'get_post' )->justReturn( $attachment );

		$this->fire( 'add_attachment', 9 );
		$this->fire( 'before_delete_post', 9, $attachment );
		$this->fire( 'delete_attachment', 9, $attachment );

		self::assertSame( [ 'attachment_uploaded', 'attachment_deleted' ], $this->sink->actions() );
		self::assertSame( 'attachment', $this->last()['entity']['type'] );
		self::assertSame( 'image/png', $this->last()['metadata']['mime_type'] );
	}

	public function test_logs_term_lifecycle_with_changes(): void {
		$old = new WP_Term( [ 'term_id' => 3, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category' ] );
		$new = new WP_Term( [ 'term_id' => 3, 'name' => 'Company news', 'slug' => 'news', 'taxonomy' => 'category' ] );
		Functions\expect( 'get_term' )->times( 3 )->andReturn( $old, $old, $new );

		$this->fire( 'created_term', 3, 30, 'category' );
		$this->fire( 'edit_terms', 3, 'category' );
		$this->fire( 'edited_term', 3, 30, 'category' );
		$this->fire( 'delete_term', 3, 30, 'category', $new );

		self::assertSame( [ 'term_created', 'term_updated', 'term_deleted' ], $this->sink->actions() );
		self::assertSame( [ 'name' => [ 'old' => 'News', 'new' => 'Company news' ] ], $this->sink->events[1]->changes() );
		self::assertSame( 'category', $this->last()['entity']['subtype'] );
	}

	public function test_ignores_post_formats(): void {
		$this->fire( 'delete_term', 1, 1, 'post_format', null );

		self::assertSame( [], $this->sink->events );
	}
}
