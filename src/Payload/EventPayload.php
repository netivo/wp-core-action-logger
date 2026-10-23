<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Payload;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class EventPayload
 *
 * One audit event in the ingestion API shape (see docs/architecture/api-specification.md).
 * Values are redacted and truncated to the API's limits when serialized.
 */
final class EventPayload {

	/**
	 * Categories accepted by the ingestion API.
	 */
	public const CATEGORIES = [ 'auth', 'content', 'system', 'user', 'settings', 'woocommerce' ];

	/**
	 * Severities accepted by the ingestion API.
	 */
	public const SEVERITIES = [ 'info', 'warning', 'critical' ];

	/**
	 * Maximum lengths of known entity fields, matching the API schema.
	 */
	private const ENTITY_LIMITS = [
		'type'    => 100,
		'subtype' => 100,
		'title'   => 1000,
		'slug'    => 500,
	];

	/**
	 * EventPayload constructor.
	 *
	 * @param string                    $event_id    UUID used by the API to drop duplicate deliveries.
	 * @param string                    $category    One of CATEGORIES.
	 * @param string                    $action      Action slug, e.g. `post_updated`.
	 * @param string                    $severity    One of SEVERITIES.
	 * @param string                    $occurred_at ISO 8601 UTC timestamp.
	 * @param Actor                     $actor       Who performed the action.
	 * @param array<string, mixed>      $entity      Affected object (type, subtype, id, title, slug, ...).
	 * @param array<string, mixed>|null $changes     Changed fields as `field => [old, new]`.
	 * @param array<string, mixed>      $metadata    Additional context.
	 */
	private function __construct(
		private readonly string $event_id,
		private readonly string $category,
		private readonly string $action,
		private readonly string $severity,
		private readonly string $occurred_at,
		private readonly Actor $actor,
		private readonly array $entity,
		private readonly ?array $changes,
		private readonly array $metadata
	) {
	}

	/**
	 * Creates an event that occurred now.
	 *
	 * @param string                    $category One of CATEGORIES.
	 * @param string                    $action   Action slug.
	 * @param Actor                     $actor    Who performed the action.
	 * @param array<string, mixed>      $entity   Affected object.
	 * @param array<string, mixed>|null $changes  Changed fields.
	 * @param array<string, mixed>      $metadata Additional context.
	 * @param string                    $severity One of SEVERITIES.
	 *
	 * @return self
	 * @throws InvalidArgumentException When the category or severity is unknown.
	 */
	public static function create(
		string $category,
		string $action,
		Actor $actor,
		array $entity = [],
		?array $changes = null,
		array $metadata = [],
		string $severity = 'info'
	): self {
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown event category "%s".', $category ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered.
		}
		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown event severity "%s".', $severity ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered.
		}

		$action = substr( (string) preg_replace( '/[^A-Za-z0-9_.:-]+/', '_', $action ), 0, 100 );

		return new self(
			self::uuid4(),
			$category,
			'' === $action ? 'unknown' : $action,
			$severity,
			( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s.v\Z' ),
			$actor,
			$entity,
			( null === $changes || [] === $changes ) ? null : $changes,
			$metadata
		);
	}

	/**
	 * Computes changed fields between two snapshots.
	 *
	 * Scalars are compared by their string form, so `5` and `"5"` are equal; arrays
	 * are compared structurally.
	 *
	 * @param array<string, mixed> $before Values before the change.
	 * @param array<string, mixed> $after  Values after the change.
	 * @param string[]|null        $fields Fields to compare; null compares the union of both keys.
	 *
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	public static function diff( array $before, array $after, ?array $fields = null ): array {
		$fields  = $fields ?? array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		$changes = [];

		foreach ( $fields as $field ) {
			$old = $before[ $field ] ?? null;
			$new = $after[ $field ] ?? null;
			if ( self::normalize( $old ) !== self::normalize( $new ) ) {
				$changes[ $field ] = [
					'old' => $old,
					'new' => $new,
				];
			}
		}

		return $changes;
	}

	/**
	 * Normalizes a value for comparison in diff().
	 *
	 * @param mixed $value Value to normalize.
	 *
	 * @return mixed
	 */
	private static function normalize( mixed $value ): mixed {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'normalize' ], $value );
		}

		return null === $value ? '' : $value;
	}

	/**
	 * Generates a random RFC 4122 version 4 UUID.
	 *
	 * @return string
	 */
	private static function uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Event ID.
	 *
	 * @return string
	 */
	public function event_id(): string {
		return $this->event_id;
	}

	/**
	 * Event category.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Action slug.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Event severity.
	 *
	 * @return string
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Actor that performed the action.
	 *
	 * @return Actor
	 */
	public function actor(): Actor {
		return $this->actor;
	}

	/**
	 * Affected object, unredacted.
	 *
	 * @return array<string, mixed>
	 */
	public function entity(): array {
		return $this->entity;
	}

	/**
	 * Changed fields, unredacted.
	 *
	 * @return array<string, mixed>|null
	 */
	public function changes(): ?array {
		return $this->changes;
	}

	/**
	 * Additional context, unredacted.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Serializes the event for the ingestion API, redacted and within its limits.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$entity = $this->entity;
		foreach ( self::ENTITY_LIMITS as $key => $limit ) {
			if ( isset( $entity[ $key ] ) && is_scalar( $entity[ $key ] ) ) {
				$entity[ $key ] = mb_substr( (string) $entity[ $key ], 0, $limit );
			}
		}

		// Empty maps are sent as objects: the API rejects `[]` where it expects `{}`.
		$event = [
			'event_id'    => $this->event_id,
			'category'    => $this->category,
			'action'      => $this->action,
			'severity'    => $this->severity,
			'occurred_at' => $this->occurred_at,
			'actor'       => self::as_map( Redactor::redact( $this->actor->to_array() ) ),
			'entity'      => self::as_map( Redactor::redact( $entity ) ),
		];

		if ( null !== $this->changes ) {
			$event['changes'] = self::redact_changes( $this->changes );
		}
		if ( [] !== $this->metadata ) {
			$event['metadata'] = Redactor::redact( $this->metadata );
		}

		return $event;
	}

	/**
	 * Redacts changes while keeping the `{old, new}` shape the API requires.
	 *
	 * @param array<string, mixed> $changes Changed fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function redact_changes( array $changes ): array {
		$result = [];
		foreach ( $changes as $field => $change ) {
			$change = is_array( $change ) ? $change : [ 'new' => $change ];
			if ( Redactor::is_sensitive_key( (string) $field ) ) {
				$result[ $field ] = [
					'old' => Redactor::REDACTED,
					'new' => Redactor::REDACTED,
				];
				continue;
			}
			$result[ $field ] = self::as_map( Redactor::redact( $change ) );
		}

		return $result;
	}

	/**
	 * Returns an empty array as an object so it encodes as `{}`.
	 *
	 * @param mixed $value Value to convert.
	 *
	 * @return mixed
	 */
	private static function as_map( mixed $value ): mixed {
		return ( [] === $value ) ? new \stdClass() : $value;
	}
}
