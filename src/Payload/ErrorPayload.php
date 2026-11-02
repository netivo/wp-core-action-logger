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
 * Class ErrorPayload
 *
 * One error-log event in the `/ingest/errors` API shape (see
 * docs/architecture/error-log-collection.md section 5.1). Values are
 * redacted and truncated to the API's limits when serialized.
 */
final class ErrorPayload {

	/**
	 * Levels accepted by the error-log ingestion API.
	 */
	public const LEVELS = [ 'fatal', 'parse', 'critical', 'error', 'warning', 'notice', 'deprecated', 'debug' ];

	/**
	 * ErrorPayload constructor.
	 *
	 * @param string                    $error_id    UUID used by the API to drop duplicate deliveries.
	 * @param string                    $source      Source identifier, e.g. `wp_debug_log`, `runtime_fatal`.
	 * @param string|null               $source_file Relative path of the tailed log file, when applicable.
	 * @param string                    $level       One of LEVELS.
	 * @param string                    $message     Normalized error message.
	 * @param string|null               $file        Relative path of the script where the error originated.
	 * @param int|null                  $line        Line number.
	 * @param string|null               $stack_trace Multiline backtrace, if any.
	 * @param string                    $occurred_at ISO 8601 UTC timestamp.
	 * @param int                       $occurrences How many times this already happened before this report.
	 * @param array<string, mixed>      $metadata    Additional client context (PHP version, memory, request URI, ...).
	 */
	private function __construct(
		private readonly string $error_id,
		private readonly string $source,
		private readonly ?string $source_file,
		private readonly string $level,
		private readonly string $message,
		private readonly ?string $file,
		private readonly ?int $line,
		private readonly ?string $stack_trace,
		private readonly string $occurred_at,
		private readonly int $occurrences,
		private readonly array $metadata
	) {
	}

	/**
	 * Creates an error payload, normalizing and truncating fields to the API's limits.
	 *
	 * @param string                $source      Source identifier.
	 * @param string                $level       One of LEVELS.
	 * @param string                $message     Error message.
	 * @param string|null           $file        Path of the script where the error originated (already relative, or absolute to be stripped).
	 * @param int|null              $line        Line number.
	 * @param string|null           $stack_trace Multiline backtrace, if any.
	 * @param array<string, mixed>  $metadata    Additional client context.
	 * @param string|null           $occurred_at ISO 8601 UTC timestamp; defaults to now.
	 * @param int                   $occurrences How many times this already happened before this report.
	 * @param string|null           $source_file Path of the tailed log file, if any (already relative, or absolute to be stripped).
	 *
	 * @return self
	 * @throws InvalidArgumentException When the level is unknown.
	 */
	public static function create(
		string $source,
		string $level,
		string $message,
		?string $file = null,
		?int $line = null,
		?string $stack_trace = null,
		array $metadata = [],
		?string $occurred_at = null,
		int $occurrences = 1,
		?string $source_file = null
	): self {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown error level "%s".', $level ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered.
		}

		$message     = (string) Redactor::redact( mb_substr( trim( $message ), 0, 10000 ) );
		$stack_trace = null === $stack_trace || '' === trim( $stack_trace )
			? null
			: (string) Redactor::redact( mb_substr( PathSanitizer::strip_in_text( $stack_trace ), 0, 20000 ) );

		return new self(
			self::uuid4(),
			mb_substr( $source, 0, 50 ),
			null === $source_file ? null : mb_substr( PathSanitizer::strip( $source_file ) ?? '', 0, 255 ),
			$level,
			'' === $message ? 'Unknown error' : $message,
			null === $file ? null : mb_substr( PathSanitizer::strip( $file ) ?? '', 0, 500 ),
			$line,
			$stack_trace,
			$occurred_at ?? ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s.v\Z' ),
			max( 1, $occurrences ),
			$metadata
		);
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
	 * Error level, one of LEVELS.
	 *
	 * @return string
	 */
	public function level(): string {
		return $this->level;
	}

	/**
	 * Serializes the error for the `/ingest/errors` API, redacted and within its limits.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$event = [
			'error_id'    => $this->error_id,
			'source'      => $this->source,
			'level'       => $this->level,
			'message'     => $this->message,
			'occurred_at' => $this->occurred_at,
			'occurrences' => $this->occurrences,
		];

		if ( null !== $this->source_file ) {
			$event['source_file'] = $this->source_file;
		}
		if ( null !== $this->file ) {
			$event['file'] = $this->file;
		}
		if ( null !== $this->line ) {
			$event['line'] = $this->line;
		}
		if ( null !== $this->stack_trace ) {
			$event['stack_trace'] = $this->stack_trace;
		}
		if ( [] !== $this->metadata ) {
			$event['metadata'] = Redactor::redact( $this->metadata );
		}

		return $event;
	}
}
