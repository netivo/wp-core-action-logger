<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\ErrorLog;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class FileTailer
 *
 * Offset-based, strictly read-only tailer. Tracks the last read byte position
 * per file in a single WordPress option, detects rotation/truncation, caps the
 * first read of a pre-existing huge log, and caps every cycle's read so a
 * single run can never exhaust memory. Host log files are never modified.
 */
class FileTailer {

	/**
	 * WordPress option storing per-file tailing state.
	 */
	public const OPTION = 'nt_action_logger_log_offsets';

	/**
	 * On first encountering a file larger than this, seek to `filesize - this`
	 * instead of reading from byte 0 (never choke on a legacy multi-GB log).
	 */
	public const FIRST_RUN_CAP_BYTES = 256 * 1024;

	/**
	 * Maximum bytes read from one file per cycle.
	 */
	public const MAX_READ_BYTES = 512 * 1024;

	/**
	 * Cached option value for this instance.
	 *
	 * @var array<string, array{inode: int|string, offset: int, size: int, mtime: int, last_seen: int}>|null
	 */
	private ?array $state = null;

	/**
	 * Reads the new content appended to a file since the last cycle.
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return array{text: string}|null Null when the file cannot be stat'd (e.g. disappeared).
	 */
	public function read( string $path ): ?array {
		clearstatcache( true, $path );
		$size  = @filesize( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden -- a file that disappears mid-cycle must not raise a warning.
		$mtime = @filemtime( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden -- see above.
		if ( false === $size || false === $mtime ) {
			return null;
		}

		$inode    = $this->inode( $path );
		$all      = $this->load_state();
		$previous = $all[ $path ] ?? null;

		if ( null !== $previous ) {
			$offset = (int) $previous['offset'];
			if ( $size < $offset || $previous['inode'] !== $inode ) {
				// Rotated, truncated, or replaced: start over.
				$offset = 0;
			}
		} else {
			$offset = $size > self::FIRST_RUN_CAP_BYTES ? $size - self::FIRST_RUN_CAP_BYTES : 0;
		}

		[ $text, $offset ] = $this->read_chunk( $path, $offset, $size );

		$all[ $path ] = [
			'inode'     => $inode,
			'offset'    => $offset,
			'size'      => $size,
			'mtime'     => $mtime,
			'last_seen' => time(),
		];
		$this->save_state( $all );

		return [ 'text' => $text ];
	}

	/**
	 * Reads at most MAX_READ_BYTES starting at `$offset`, holding back a
	 * trailing partial line (still being written) for the next cycle.
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $offset Byte offset to start reading from.
	 * @param int    $size   Current file size.
	 *
	 * @return array{0: string, 1: int} Text read, and the offset to persist.
	 */
	private function read_chunk( string $path, int $offset, int $size ): array {
		$to_read = min( self::MAX_READ_BYTES, max( 0, $size - $offset ) );
		if ( $to_read <= 0 ) {
			return [ '', $offset ];
		}

		// phpcs:disable Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- read-only, byte-offset tailing of a host log file (often outside wp-content, e.g. a server error_log), which WP_Filesystem does not support seeking into.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return [ '', $offset ];
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}
		// fread() may return fewer bytes than requested in one call even for a
		// local file (buffering is not guaranteed to fill the request); loop
		// until the full chunk is read or EOF, rather than risk silently
		// truncating (and then re-reading, duplicating) the tail of a big log.
		$text = '';
		while ( ! feof( $handle ) ) {
			$remaining = $to_read - strlen( $text );
			if ( $remaining <= 0 ) {
				break;
			}
			$piece = fread( $handle, $remaining );
			if ( false === $piece || '' === $piece ) {
				break;
			}
			$text .= $piece;
		}
		fclose( $handle );
		// phpcs:enable

		$consumed     = strlen( $text );
		$last_newline = strrpos( $text, "\n" );
		if ( false !== $last_newline && $last_newline < $consumed - 1 ) {
			$consumed = $last_newline + 1;
			$text     = substr( $text, 0, $consumed );
		}

		return [ $text, $offset + $consumed ];
	}

	/**
	 * Drops tracked state for files no longer discovered, so a stream of
	 * date-stamped rotating logs (e.g. WooCommerce fatal-errors-*.log) does not
	 * grow the option forever.
	 *
	 * @param string[] $known_paths Currently discovered file paths.
	 *
	 * @return void
	 */
	public function prune( array $known_paths ): void {
		$all      = $this->load_state();
		$filtered = array_intersect_key( $all, array_flip( $known_paths ) );
		if ( count( $filtered ) !== count( $all ) ) {
			$this->save_state( $filtered );
		}
	}

	/**
	 * Inode of a file, or an md5 of its path on filesystems without one.
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return int|string
	 */
	private function inode( string $path ): int|string {
		$stat = @stat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden -- a file that disappears mid-cycle must not raise a warning.

		return is_array( $stat ) && $stat['ino'] > 0 ? (int) $stat['ino'] : md5( $path );
	}

	/**
	 * Loads the tailing-state option.
	 *
	 * @return array<string, array{inode: int|string, offset: int, size: int, mtime: int, last_seen: int}>
	 */
	private function load_state(): array {
		if ( null === $this->state ) {
			$value       = get_option( self::OPTION, [] );
			$this->state = is_array( $value ) ? $value : [];
		}

		return $this->state;
	}

	/**
	 * Persists the tailing-state option.
	 *
	 * @param array<string, array{inode: int|string, offset: int, size: int, mtime: int, last_seen: int}> $state Tailing state.
	 *
	 * @return void
	 */
	private function save_state( array $state ): void {
		$this->state = $state;
		// Autoloaded would cost a query on every request; this option is only touched by cron.
		update_option( self::OPTION, $state, false );
	}
}
