<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use Netivo\Module\ActionLogger\Payload\EventPayload;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ContentListener
 *
 * Posts, pages, custom post types, media and taxonomy terms. Autosaves, revisions
 * and internal post types are ignored; updates are logged only when a tracked field
 * actually changed.
 */
class ContentListener extends AbstractListener {

	/**
	 * Post types never logged here: internal types, and WooCommerce types that the
	 * WooCommerce listener covers with richer data.
	 */
	private const IGNORED_POST_TYPES = [
		'revision',
		'customize_changeset',
		'oembed_cache',
		'custom_css',
		'user_request',
		'wp_global_styles',
		'nav_menu_item',
		'product',
		'product_variation',
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_coupon',
		'scheduled-action',
	];

	/**
	 * Taxonomies never logged.
	 */
	private const IGNORED_TAXONOMIES = [ 'post_format', 'wp_theme', 'wp_template_part_area', 'action-group' ];

	/**
	 * Post fields compared on update.
	 */
	private const POST_FIELDS = [
		'post_title',
		'post_status',
		'post_name',
		'post_author',
		'post_parent',
		'post_excerpt',
		'post_date',
		'menu_order',
		'comment_status',
		'post_password',
	];

	/**
	 * Term fields compared on update.
	 */
	private const TERM_FIELDS = [ 'name', 'slug', 'parent', 'description' ];

	/**
	 * Term snapshots taken before an edit, keyed by term ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $term_snapshots = [];

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->on( 'wp_insert_post', 'post_inserted', 10, 3 );
		$this->on( 'post_updated', 'post_updated', 10, 3 );
		$this->on( 'trashed_post', 'post_trashed' );
		$this->on( 'untrashed_post', 'post_untrashed', 10, 2 );
		$this->on( 'before_delete_post', 'post_deleted', 10, 2 );
		$this->on( 'add_attachment', 'attachment_added' );
		$this->on( 'delete_attachment', 'attachment_deleted', 10, 2 );
		$this->on( 'created_term', 'term_created', 10, 3 );
		$this->on( 'edit_terms', 'term_before_edit', 10, 2 );
		$this->on( 'edited_term', 'term_edited', 10, 3 );
		$this->on( 'delete_term', 'term_deleted', 10, 4 );
	}

	/**
	 * New post inserted directly (not via an auto-draft, which post_updated covers).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Inserted post.
	 * @param bool    $update  Whether this is an update.
	 *
	 * @return void
	 */
	public function post_inserted( int $post_id, WP_Post $post, bool $update ): void {
		if ( $update || 'auto-draft' === $post->post_status || 'attachment' === $post->post_type || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		$this->log( 'content', 'post_created', $this->post_entity( $post ), null, [ 'status' => $post->post_status ] );
	}

	/**
	 * Existing post saved. The first save of an auto-draft is reported as creation.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 *
	 * @return void
	 */
	public function post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( ! $this->is_tracked_post( $after ) || 'auto-draft' === $after->post_status ) {
			return;
		}
		// Trashing and restoring are logged by their own hooks.
		if ( 'trash' === $after->post_status || 'trash' === $before->post_status ) {
			return;
		}

		if ( 'auto-draft' === $before->post_status ) {
			$this->log( 'content', 'post_created', $this->post_entity( $after ), null, [ 'status' => $after->post_status ] );

			return;
		}

		$changes = EventPayload::diff( $this->post_fields( $before ), $this->post_fields( $after ), self::POST_FIELDS );

		$before_length = mb_strlen( $before->post_content );
		$after_length  = mb_strlen( $after->post_content );
		if ( $before->post_content !== $after->post_content ) {
			// Content itself is not sent: it can be large and personal.
			$changes['post_content_length'] = [
				'old' => $before_length,
				'new' => $after_length,
			];
		}

		if ( [] === $changes ) {
			return;
		}

		$action = 'post_updated';
		if ( isset( $changes['post_status'] ) && 'publish' === $after->post_status ) {
			$action = 'post_published';
		} elseif ( isset( $changes['post_status'] ) && 'publish' === $before->post_status ) {
			$action = 'post_unpublished';
		}

		$this->log( 'content', $action, $this->post_entity( $after ), $changes );
	}

	/**
	 * Post moved to the trash.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function post_trashed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && $this->is_tracked_post( $post ) ) {
			$this->log( 'content', 'post_trashed', $this->post_entity( $post ) );
		}
	}

	/**
	 * Post restored from the trash.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status Status before trashing.
	 *
	 * @return void
	 */
	public function post_untrashed( int $post_id, string $previous_status = '' ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && $this->is_tracked_post( $post ) ) {
			$this->log( 'content', 'post_restored', $this->post_entity( $post ), null, '' === $previous_status ? [] : [ 'previous_status' => $previous_status ] );
		}
	}

	/**
	 * Post about to be permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post being deleted (WordPress 5.5+).
	 *
	 * @return void
	 */
	public function post_deleted( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		$this->log( 'content', 'post_deleted', $this->post_entity( $post ), null, [ 'status' => $post->post_status ], 'warning' );
	}

	/**
	 * Media uploaded.
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return void
	 */
	public function attachment_added( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->log( 'content', 'attachment_uploaded', $this->post_entity( $post ), null, [ 'mime_type' => $post->post_mime_type ] );
		}
	}

	/**
	 * Media deleted.
	 *
	 * @param int          $post_id Attachment ID.
	 * @param WP_Post|null $post    Attachment (WordPress 5.5+).
	 *
	 * @return void
	 */
	public function attachment_deleted( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->log( 'content', 'attachment_deleted', $this->post_entity( $post ), null, [ 'mime_type' => $post->post_mime_type ], 'warning' );
		}
	}

	/**
	 * Term created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof WP_Term && $this->is_tracked_taxonomy( $taxonomy ) ) {
			$this->log( 'content', 'term_created', $this->term_entity( $term ) );
		}
	}

	/**
	 * Snapshot of a term right before it is updated.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function term_before_edit( int $term_id, string $taxonomy ): void {
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof WP_Term ) {
			$this->term_snapshots[ $term_id ] = $this->term_fields( $term );
		}
	}

	/**
	 * Term updated.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		$before = $this->term_snapshots[ $term_id ] ?? null;
		unset( $this->term_snapshots[ $term_id ] );

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term || ! $this->is_tracked_taxonomy( $taxonomy ) ) {
			return;
		}

		$changes = null === $before ? null : EventPayload::diff( $before, $this->term_fields( $term ), self::TERM_FIELDS );
		if ( [] === $changes ) {
			return;
		}

		$this->log( 'content', 'term_updated', $this->term_entity( $term ), $changes );
	}

	/**
	 * Term deleted.
	 *
	 * @param int          $term_id      Term ID.
	 * @param int          $tt_id        Term taxonomy ID.
	 * @param string       $taxonomy     Taxonomy.
	 * @param WP_Term|null $deleted_term Copy of the deleted term.
	 *
	 * @return void
	 */
	public function term_deleted( int $term_id, int $tt_id, string $taxonomy, mixed $deleted_term = null ): void {
		if ( ! $this->is_tracked_taxonomy( $taxonomy ) ) {
			return;
		}

		$entity = $deleted_term instanceof WP_Term ? $this->term_entity( $deleted_term ) : [
			'type'    => 'term',
			'subtype' => $taxonomy,
			'id'      => $term_id,
		];
		$this->log( 'content', 'term_deleted', $entity, null, [], 'warning' );
	}

	/**
	 * Whether a post should be logged by this listener.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return bool
	 */
	private function is_tracked_post( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		/**
		 * Filters the post types ignored by the Action Logger content listener.
		 *
		 * @param string[] $post_types Ignored post types.
		 */
		$ignored = apply_filters( 'netivo_action_logger_ignored_post_types', self::IGNORED_POST_TYPES );

		return ! in_array( $post->post_type, $ignored, true );
	}

	/**
	 * Whether a taxonomy should be logged.
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return bool
	 */
	private function is_tracked_taxonomy( string $taxonomy ): bool {
		return ! in_array( $taxonomy, self::IGNORED_TAXONOMIES, true );
	}

	/**
	 * Entity describing a post.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return array<string, mixed>
	 */
	private function post_entity( WP_Post $post ): array {
		return [
			'type'    => 'attachment' === $post->post_type ? 'attachment' : 'post',
			'subtype' => $post->post_type,
			'id'      => (int) $post->ID,
			'title'   => $post->post_title,
			'slug'    => $post->post_name,
		];
	}

	/**
	 * Tracked fields of a post.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return array<string, mixed>
	 */
	private function post_fields( WP_Post $post ): array {
		$fields = [];
		foreach ( self::POST_FIELDS as $field ) {
			$fields[ $field ] = $post->$field ?? null;
		}

		return $fields;
	}

	/**
	 * Entity describing a term.
	 *
	 * @param WP_Term $term Term.
	 *
	 * @return array<string, mixed>
	 */
	private function term_entity( WP_Term $term ): array {
		return [
			'type'    => 'term',
			'subtype' => $term->taxonomy,
			'id'      => (int) $term->term_id,
			'title'   => $term->name,
			'slug'    => $term->slug,
		];
	}

	/**
	 * Tracked fields of a term.
	 *
	 * @param WP_Term $term Term.
	 *
	 * @return array<string, mixed>
	 */
	private function term_fields( WP_Term $term ): array {
		return [
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'description' => $term->description,
		];
	}
}
