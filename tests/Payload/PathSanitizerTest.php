<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Payload;

use Netivo\Module\ActionLogger\Payload\PathSanitizer;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class PathSanitizerTest extends TestCase {
	public function test_strips_abspath_prefix(): void {
		$path = rtrim( ABSPATH, '/' ) . '/wp-content/plugins/revslider/revslider.php';

		self::assertSame( 'wp-content/plugins/revslider/revslider.php', PathSanitizer::strip( $path ) );
	}

	public function test_strips_server_parent_prefix_when_abspath_does_not_match(): void {
		$path = rtrim( dirname( ABSPATH ), '/' ) . '/logs/error.log';

		self::assertSame( 'logs/error.log', PathSanitizer::strip( $path ) );
	}

	public function test_leaves_an_unrelated_path_unchanged(): void {
		self::assertSame( '/var/www/other-site/index.php', PathSanitizer::strip( '/var/www/other-site/index.php' ) );
	}

	public function test_passes_through_null_and_empty(): void {
		self::assertNull( PathSanitizer::strip( null ) );
		self::assertSame( '', PathSanitizer::strip( '' ) );
	}

	public function test_strip_in_text_masks_every_occurrence_in_a_stack_trace(): void {
		$root  = rtrim( ABSPATH, '/' );
		$trace = "#0 {$root}/wp-blog-header.php(19): require()\n#1 {$root}/index.php(17): require()";

		$result = PathSanitizer::strip_in_text( $trace );

		self::assertStringNotContainsString( $root, $result );
		self::assertStringContainsString( 'wp-blog-header.php(19): require()', $result );
		self::assertStringContainsString( 'index.php(17): require()', $result );
	}
}
