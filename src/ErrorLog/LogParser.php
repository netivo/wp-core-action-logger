<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\ErrorLog;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class LogParser
 *
 * Parses complete log lines (as handed over by FileTailer) into structured
 * error entries. Understands the standard PHP/WordPress error format (used
 * verbatim by WP_DEBUG_LOG and, wrapped in extra brackets, by Apache/LiteSpeed/
 * cPanel server logs) and the WooCommerce `WC_Logger` ISO-8601 format, with
 * multiline stack trace assembly: any line that isn't itself a recognized
 * header is appended to the stack trace of the entry above it.
 */
class LogParser {

	/**
	 * Native PHP error-label (lowercased) => API level.
	 */
	private const PHP_LEVEL_MAP = [
		'fatal error'           => 'fatal',
		'parse error'           => 'parse',
		'compile error'         => 'parse',
		'core error'            => 'fatal',
		'catchable fatal error' => 'error',
		'recoverable error'     => 'error',
		'user error'            => 'error',
		'warning'               => 'warning',
		'core warning'          => 'warning',
		'compile warning'       => 'warning',
		'user warning'          => 'warning',
		'notice'                => 'notice',
		'user notice'           => 'notice',
		'deprecated'            => 'deprecated',
		'user deprecated'       => 'deprecated',
	];

	/**
	 * WooCommerce `WC_Logger` level word => API level.
	 */
	private const WC_LEVEL_MAP = [
		'EMERGENCY' => 'critical',
		'ALERT'     => 'critical',
		'CRITICAL'  => 'critical',
		'ERROR'     => 'error',
		'WARNING'   => 'warning',
		'NOTICE'    => 'notice',
		'INFO'      => 'debug',
		'DEBUG'     => 'debug',
	];

	/**
	 * Parses a chunk of complete log lines into structured error entries.
	 *
	 * @param string $text One or more complete, newline-terminated log lines.
	 *
	 * @return array<int, array{level: string, message: string, file: string|null, line: int|null, stack_trace: string|null, occurred_at: string|null}>
	 */
	public function parse( string $text ): array {
		if ( '' === trim( $text ) ) {
			return [];
		}

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$lines = is_array( $lines ) ? $lines : [];
		if ( [] !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		$entries     = [];
		$current     = null;
		$trace_lines = [];

		foreach ( $lines as $line ) {
			$header = $this->match_wc_header( $line ) ?? $this->match_php_header( $line );

			if ( null !== $header ) {
				if ( null !== $current ) {
					$entries[] = $this->finish( $current, $trace_lines );
				}
				$current     = $header;
				$trace_lines = [];
				continue;
			}

			if ( null !== $current && '' !== trim( $line ) ) {
				$trace_lines[] = $line;
			}
			// A line before any recognized header belongs to no entry; ignore it.
		}

		if ( null !== $current ) {
			$entries[] = $this->finish( $current, $trace_lines );
		}

		return $entries;
	}

	/**
	 * Attaches the assembled stack trace (if any) to a finished entry.
	 *
	 * @param array{level: string, message: string, file: string|null, line: int|null, stack_trace: string|null, occurred_at: string|null} $entry       Entry built from the header line.
	 * @param string[]                                                                                                                       $trace_lines Continuation lines collected after it.
	 *
	 * @return array{level: string, message: string, file: string|null, line: int|null, stack_trace: string|null, occurred_at: string|null}
	 */
	private function finish( array $entry, array $trace_lines ): array {
		$entry['stack_trace'] = [] === $trace_lines ? null : implode( "\n", $trace_lines );

		return $entry;
	}

	/**
	 * Matches a standard PHP error line, in WordPress's own format
	 * (`[d-M-Y H:i:s UTC] PHP Level: message`) or wrapped in extra
	 * Apache/LiteSpeed/cPanel brackets (`[date] [php:error] [pid N] PHP Level: message`).
	 *
	 * @param string $line One log line.
	 *
	 * @return array{level: string, message: string, file: string|null, line: int|null, stack_trace: null, occurred_at: string|null}|null
	 */
	private function match_php_header( string $line ): ?array {
		if ( 1 !== preg_match( '/^(?:\[(?<ts>[^\]]+)\]\s*)?(?:\[[^\]]*\]\s*)*PHP\s+(?<level>[A-Za-z ]+?):\s*(?<message>.*)$/', $line, $m ) ) {
			return null;
		}

		$level = $this->map_php_level( $m['level'] );
		if ( null === $level ) {
			return null;
		}

		[ $file, $lineno, $message ] = $this->extract_location( trim( $m['message'] ) );

		return [
			'level'       => $level,
			'message'     => '' === $message ? 'Unknown error' : $message,
			'file'        => $file,
			'line'        => $lineno,
			'stack_trace' => null,
			'occurred_at' => $this->parse_timestamp( $m['ts'] ),
		];
	}

	/**
	 * Matches a WooCommerce `WC_Logger` line (`{ISO-8601} {LEVEL} message`).
	 *
	 * @param string $line One log line.
	 *
	 * @return array{level: string, message: string, file: null, line: null, stack_trace: null, occurred_at: string|null}|null
	 */
	private function match_wc_header( string $line ): ?array {
		if ( 1 !== preg_match( '/^(?<ts>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(?<level>[A-Z]+)\s+(?<message>.*)$/', $line, $m ) ) {
			return null;
		}

		$level = self::WC_LEVEL_MAP[ $m['level'] ] ?? null;
		if ( null === $level ) {
			return null;
		}

		$message = trim( $m['message'] );

		return [
			'level'       => $level,
			'message'     => '' === $message ? 'Unknown error' : $message,
			'file'        => null,
			'line'        => null,
			'stack_trace' => null,
			'occurred_at' => $this->parse_iso_timestamp( $m['ts'] ),
		];
	}

	/**
	 * Normalizes a raw PHP error-label to an API level, or null when unrecognized.
	 *
	 * @param string $raw Raw label, e.g. "Fatal error" or "Strict Standards".
	 *
	 * @return string|null
	 */
	private function map_php_level( string $raw ): ?string {
		$key = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $raw ) ) );
		if ( str_starts_with( $key, 'strict' ) ) {
			return 'notice';
		}

		return self::PHP_LEVEL_MAP[ $key ] ?? null;
	}

	/**
	 * Splits a trailing "... in FILE on line N" or "... in FILE:N" off a message.
	 *
	 * @param string $message Message, possibly with a trailing location.
	 *
	 * @return array{0: string|null, 1: int|null, 2: string} File, line, message without the location.
	 */
	private function extract_location( string $message ): array {
		if ( 1 === preg_match( '/^(?<msg>.*)\s+in\s+(?<file>\S+?)(?:\s+on\s+line\s+(?<l1>\d+)|:(?<l2>\d+))\s*$/s', $message, $m ) ) {
			$line = '' !== ( $m['l1'] ?? '' ) ? $m['l1'] : ( $m['l2'] ?? '' );

			return [ trim( $m['file'] ), '' === $line ? null : (int) $line, trim( $m['msg'] ) ];
		}

		return [ null, null, $message ];
	}

	/**
	 * Parses a WP/Apache-style timestamp into ISO-8601 UTC, or null if unrecognized.
	 *
	 * @param string $raw Raw timestamp, e.g. "02-Nov-2026 14:15:02 UTC" or "Wed Nov 02 14:15:02.123456 2026".
	 *
	 * @return string|null
	 */
	private function parse_timestamp( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		// WordPress debug.log always logs UTC; drop the trailing zone name rather
		// than rely on createFromFormat()'s inconsistent handling of "T".
		$clean = trim( (string) preg_replace( '/\s+[A-Za-z]{2,5}$/', '', $raw ) );
		$dt    = DateTimeImmutable::createFromFormat( 'd-M-Y H:i:s', $clean, new DateTimeZone( 'UTC' ) );

		if ( false === $dt ) {
			$dt = DateTimeImmutable::createFromFormat( 'D M d H:i:s.u Y', $raw, new DateTimeZone( 'UTC' ) );
		}
		if ( false === $dt ) {
			$dt = DateTimeImmutable::createFromFormat( 'D M d H:i:s Y', $raw, new DateTimeZone( 'UTC' ) );
		}

		return false === $dt ? null : $dt->format( 'Y-m-d\TH:i:s.v\Z' );
	}

	/**
	 * Parses an ISO-8601 timestamp into the API's UTC format, or null if unrecognized.
	 *
	 * @param string $raw ISO-8601 timestamp with an explicit offset.
	 *
	 * @return string|null
	 */
	private function parse_iso_timestamp( string $raw ): ?string {
		try {
			$dt = new DateTimeImmutable( $raw );
		} catch ( Exception ) {
			return null;
		}

		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' );
	}
}
