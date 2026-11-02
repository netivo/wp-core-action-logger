<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Payload;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class PathSanitizer
 *
 * Strips the server's absolute install path from file paths found in error
 * messages and stack traces before they leave the site, turning
 * `/home/user/public_html/wp-content/plugins/x.php` into `wp-content/plugins/x.php`.
 */
final class PathSanitizer {

	/**
	 * Strips `ABSPATH` and `dirname(ABSPATH)` prefixes from one path.
	 *
	 * @param string|null $path Absolute (or already relative) path.
	 *
	 * @return string|null
	 */
	public static function strip( ?string $path ): ?string {
		if ( null === $path || '' === $path ) {
			return $path;
		}

		$path = str_replace( '\\', '/', $path );

		foreach ( self::roots() as $root ) {
			if ( '' !== $root && str_starts_with( $path, $root ) ) {
				return ltrim( substr( $path, strlen( $root ) ), '/' );
			}
		}

		return $path;
	}

	/**
	 * Strips server-root prefixes from every `/path/to/file.php:123`-shaped
	 * occurrence inside a longer string (a message or a multi-line stack trace).
	 *
	 * @param string $text Free-form text possibly containing absolute paths.
	 *
	 * @return string
	 */
	public static function strip_in_text( string $text ): string {
		$text = str_replace( '\\', '/', $text );

		foreach ( self::roots() as $root ) {
			if ( '' !== $root ) {
				$text = str_replace( $root . '/', '', $text );
			}
		}

		return $text;
	}

	/**
	 * Candidate absolute roots to strip, longest first so the most specific match wins.
	 *
	 * @return string[]
	 */
	private static function roots(): array {
		$abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$parent  = rtrim( str_replace( '\\', '/', dirname( ABSPATH ) ), '/' );

		$roots = array_unique( [ $abspath, $parent ] );
		usort( $roots, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $roots;
	}
}
