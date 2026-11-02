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
 * Class FileScanner
 *
 * Discovers every known WordPress and server error-log location that is
 * currently present and readable (see docs/architecture/error-log-collection.md
 * section 2.1, "Exhaustive Source Directory Matrix"). Purely read-only: this
 * class only stats and globs the filesystem, it never opens a file for writing.
 */
class FileScanner {

	/**
	 * Finds every known log file that currently exists and is readable.
	 *
	 * @return array<string, string> Absolute file path => source identifier.
	 */
	public function discover(): array {
		$candidates = [];

		foreach ( $this->debug_log_candidates() as $path ) {
			$candidates[ $path ] = 'wp_debug_log';
		}

		$ini_log = ini_get( 'error_log' );
		if ( is_string( $ini_log ) && str_contains( $ini_log, '/' ) ) {
			$candidates[ $ini_log ] ??= 'php_ini_log';
		}

		$candidates[ rtrim( ABSPATH, '/' ) . '/error_log' ]            ??= 'server_root_error_log';
		$candidates[ rtrim( ABSPATH, '/' ) . '/wp-admin/error_log' ]   ??= 'server_admin_error_log';
		$candidates[ rtrim( WP_CONTENT_DIR, '/' ) . '/error_log' ]     ??= 'server_content_error_log';

		$parent = rtrim( dirname( ABSPATH ), '/' );
		if ( '' !== $parent && $parent !== rtrim( ABSPATH, '/' ) ) {
			$candidates[ $parent . '/error_log' ]      ??= 'server_parent_error_log';
			$candidates[ $parent . '/logs/error.log' ] ??= 'server_parent_error_log';
		}

		foreach ( $this->glob_safe( WP_CONTENT_DIR . '/uploads/wc-logs/fatal-errors-*.log' ) as $path ) {
			$candidates[ $path ] ??= 'wc_fatal_logs';
		}
		foreach ( $this->glob_safe( WP_CONTENT_DIR . '/uploads/wc-logs/critical-*.log' ) as $path ) {
			$candidates[ $path ] ??= 'wc_critical_logs';
		}
		foreach ( $this->glob_safe( WP_CONTENT_DIR . '/uploads/elementor/logs/*.log' ) as $path ) {
			$candidates[ $path ] ??= 'elementor_logs';
		}

		$result = [];
		foreach ( $candidates as $path => $source ) {
			if ( $this->is_readable_file( $path ) ) {
				$result[ $path ] = $source;
			}
		}

		return $result;
	}

	/**
	 * Candidate `WP_DEBUG_LOG` paths: the custom path when one is configured,
	 * otherwise (or additionally) the default `wp-content/debug.log`.
	 *
	 * @return string[]
	 */
	private function debug_log_candidates(): array {
		$paths = [ rtrim( WP_CONTENT_DIR, '/' ) . '/debug.log' ];

		if ( defined( 'WP_DEBUG_LOG' ) ) {
			$value = constant( 'WP_DEBUG_LOG' );
			// @phpstan-ignore-next-line function.impossibleType, notIdentical.alwaysTrue -- szepeviktor/phpstan-wordpress's bootstrap hardcodes WP_DEBUG_LOG to bool(true) for analysis, but WordPress documents it as bool|string: a site's wp-config.php may set a custom log path.
			if ( is_string( $value ) && '' !== $value ) {
				$paths[] = $value;
			}
		}

		return array_unique( $paths );
	}

	/**
	 * Resolves a glob pattern, tolerating a disabled/restricted glob().
	 *
	 * @param string $pattern Glob pattern.
	 *
	 * @return string[]
	 */
	private function glob_safe( string $pattern ): array {
		$matches = @glob( $pattern ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden -- open_basedir/disable_functions restrictions must not surface as warnings.

		return is_array( $matches ) ? $matches : [];
	}

	/**
	 * Whether a path is a plain, readable file (never a directory or special file).
	 *
	 * @param string $path Candidate path.
	 *
	 * @return bool
	 */
	private function is_readable_file( string $path ): bool {
		return '' !== $path && @is_file( $path ) && @is_readable( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden -- open_basedir restrictions must not surface as warnings.
	}
}
