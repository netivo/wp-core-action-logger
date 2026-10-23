<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Queue
 *
 * Local fallback storage (`{prefix}nt_action_log_queue`) for events that could not be
 * delivered. Rows carry a retry counter and the time of the next attempt so the cron
 * worker can back off while the API is unavailable.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; caching would serve stale queue state.
 */
class Queue {

	/**
	 * Schema version; bump when the table definition changes.
	 */
	public const DB_VERSION = '1';

	/**
	 * Option storing the installed schema version.
	 */
	public const VERSION_OPTION = 'nt_action_logger_queue_version';

	/**
	 * Maximum rows kept; the oldest events are dropped beyond this so a long outage
	 * cannot grow the table without bound.
	 */
	public const MAX_ROWS = 10000;

	/**
	 * Longest wait between retries of one row, in seconds.
	 */
	public const MAX_BACKOFF = 3600;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Queue constructor.
	 *
	 * @param wpdb|null $db Database connection; defaults to the global `$wpdb`.
	 */
	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->db->prefix . 'nt_action_log_queue';
	}

	/**
	 * Creates or upgrades the table when the stored schema version differs.
	 *
	 * @return void
	 */
	public function install(): void {
		if ( self::DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->table();
		$charset = $this->db->get_charset_collate();

		// dbDelta requires this exact formatting (two spaces after PRIMARY KEY, KEY not INDEX).
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_payload longtext NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  next_attempt_at datetime NULL DEFAULT NULL,
  last_error text NULL,
  PRIMARY KEY  (id),
  KEY idx_created_at (created_at),
  KEY idx_next_attempt_at (next_attempt_at)
) {$charset};"
		);

		// Autoloaded: install() runs on every request and must not cost a query.
		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Stores events for a later delivery attempt.
	 *
	 * @param array<int, array<string, mixed>> $events     Serialized events (EventPayload::to_array()).
	 * @param string                           $last_error Why delivery failed, if it was attempted.
	 *
	 * @return int Number of stored events.
	 */
	public function push( array $events, string $last_error = '' ): int {
		$rows   = [];
		$values = [];
		$now    = gmdate( 'Y-m-d H:i:s' );
		$error  = '' === $last_error ? null : substr( $last_error, 0, 1000 );

		foreach ( $events as $event ) {
			$json = wp_json_encode( $event );
			if ( false === $json ) {
				continue;
			}
			$rows[]   = '(%s, %d, %s, ' . ( null === $error ? 'NULL' : '%s' ) . ')';
			$values[] = $json;
			$values[] = null === $error ? 0 : 1;
			$values[] = $now;
			if ( null !== $error ) {
				$values[] = $error;
			}
		}

		if ( [] === $rows ) {
			return 0;
		}

		$sql = 'INSERT INTO %i (event_payload, attempts, created_at, last_error) VALUES ' . implode( ', ', $rows );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and filled by prepare().
		$this->db->query( $this->db->prepare( $sql, $this->table(), ...$values ) );

		$this->trim();

		return count( $rows );
	}

	/**
	 * Fetches events whose next attempt is due, oldest first.
	 *
	 * @param int $limit Maximum rows to return.
	 *
	 * @return array<int, array{id: int, attempts: int, payload: object}>
	 */
	public function due( int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT id, attempts, event_payload FROM %i WHERE next_attempt_at IS NULL OR next_attempt_at <= %s ORDER BY id ASC LIMIT %d',
				$this->table(),
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);

		$result = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			// Decoded as objects so empty maps stay `{}` when re-encoded for the API.
			$payload = json_decode( (string) $row['event_payload'] );
			if ( ! is_object( $payload ) ) {
				$this->delete( [ (int) $row['id'] ] );
				continue;
			}
			$result[] = [
				'id'       => (int) $row['id'],
				'attempts' => (int) $row['attempts'],
				'payload'  => $payload,
			];
		}

		return $result;
	}

	/**
	 * Removes delivered (or permanently rejected) events.
	 *
	 * @param int[] $ids Row IDs.
	 *
	 * @return void
	 */
	public function delete( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only %d placeholders are interpolated.
		$this->db->query( $this->db->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", $this->table(), ...$ids ) );
	}

	/**
	 * Records a failed attempt and schedules the next one with exponential backoff
	 * (1, 2, 4 ... minutes, capped at MAX_BACKOFF).
	 *
	 * @param int[]  $ids   Row IDs.
	 * @param string $error Failure reason.
	 *
	 * @return void
	 */
	public function release( array $ids, string $error ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only %d placeholders are interpolated.
				"UPDATE %i SET attempts = attempts + 1, last_error = %s, next_attempt_at = DATE_ADD(%s, INTERVAL LEAST(%d, 60 * POW(2, LEAST(attempts, 6))) SECOND) WHERE id IN ({$placeholders})",
				$this->table(),
				substr( $error, 0, 1000 ),
				gmdate( 'Y-m-d H:i:s' ),
				self::MAX_BACKOFF,
				...$ids
			)
		);
	}

	/**
	 * Deletes events that failed too many times.
	 *
	 * @param int $max_attempts Attempts after which an event is given up.
	 *
	 * @return int Number of deleted rows.
	 */
	public function drop_exhausted( int $max_attempts ): int {
		$deleted = $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE attempts >= %d', $this->table(), $max_attempts ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Number of queued events.
	 *
	 * @return int
	 */
	public function count(): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );
	}

	/**
	 * Creation time (UTC, MySQL format) of the oldest queued event.
	 *
	 * @return string|null
	 */
	public function oldest(): ?string {
		$value = $this->db->get_var( $this->db->prepare( 'SELECT MIN(created_at) FROM %i', $this->table() ) );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Drops the oldest rows beyond MAX_ROWS.
	 *
	 * @return void
	 */
	private function trim(): void {
		$cutoff = $this->db->get_var(
			$this->db->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $this->table(), self::MAX_ROWS )
		);
		if ( null !== $cutoff ) {
			$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE id <= %d', $this->table(), (int) $cutoff ) );
		}
	}
}
