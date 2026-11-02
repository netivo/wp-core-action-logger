<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\ErrorLog;

use Netivo\Module\ActionLogger\ErrorLog\LogParser;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class LogParserTest extends TestCase {
	private LogParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new LogParser();
	}

	public function test_parses_a_wp_debug_log_fatal_with_a_stack_trace(): void {
		$text = "[02-Nov-2026 14:15:02 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function get_header() in /var/www/site/index.php:12\n"
			. "Stack trace:\n"
			. "#0 /var/www/site/wp-blog-header.php(19): require()\n"
			. "#1 /var/www/site/index.php(17): require('/var/www/site/w...')\n"
			. "#2 {main}\n"
			. "  thrown in /var/www/site/index.php on line 12\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 1, $entries );
		self::assertSame( 'fatal', $entries[0]['level'] );
		self::assertSame( 'Uncaught Error: Call to undefined function get_header()', $entries[0]['message'] );
		self::assertSame( '/var/www/site/index.php', $entries[0]['file'] );
		self::assertSame( 12, $entries[0]['line'] );
		self::assertSame( '2026-11-02T14:15:02.000Z', $entries[0]['occurred_at'] );
		self::assertStringContainsString( 'Stack trace:', (string) $entries[0]['stack_trace'] );
		self::assertStringContainsString( 'thrown in /var/www/site/index.php on line 12', (string) $entries[0]['stack_trace'] );
	}

	public function test_parses_a_wp_debug_log_notice_with_the_on_line_form(): void {
		$text = '[02-Nov-2026 09:00:00 UTC] PHP Notice:  Undefined variable $foo in /var/www/site/wp-content/themes/x/index.php on line 42' . "\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 1, $entries );
		self::assertSame( 'notice', $entries[0]['level'] );
		self::assertSame( 'Undefined variable $foo', $entries[0]['message'] );
		self::assertSame( '/var/www/site/wp-content/themes/x/index.php', $entries[0]['file'] );
		self::assertSame( 42, $entries[0]['line'] );
		self::assertNull( $entries[0]['stack_trace'] );
	}

	public function test_parses_an_apache_wrapped_php_warning(): void {
		$text = '[Wed Nov 02 14:15:02.123456 2026] [php:warn] [pid 1234] [client 1.2.3.4:5678] PHP Warning:  Division by zero in /var/www/site/calc.php on line 7' . "\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 1, $entries );
		self::assertSame( 'warning', $entries[0]['level'] );
		self::assertSame( 'Division by zero', $entries[0]['message'] );
		self::assertSame( '/var/www/site/calc.php', $entries[0]['file'] );
		self::assertSame( 7, $entries[0]['line'] );
	}

	public function test_parses_a_woocommerce_critical_line(): void {
		$text = '2026-11-02T14:15:02+00:00 CRITICAL Payment gateway unreachable' . "\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 1, $entries );
		self::assertSame( 'critical', $entries[0]['level'] );
		self::assertSame( 'Payment gateway unreachable', $entries[0]['message'] );
		self::assertNull( $entries[0]['file'] );
		self::assertSame( '2026-11-02T14:15:02.000Z', $entries[0]['occurred_at'] );
	}

	public function test_maps_deprecated_and_strict_standards(): void {
		$deprecated = $this->parser->parse( '[02-Nov-2026 09:00:00 UTC] PHP Deprecated:  Something old in /x.php on line 1' . "\n" );
		$strict     = $this->parser->parse( '[02-Nov-2026 09:00:00 UTC] PHP Strict Standards:  Something in /x.php on line 1' . "\n" );

		self::assertSame( 'deprecated', $deprecated[0]['level'] );
		self::assertSame( 'notice', $strict[0]['level'] );
	}

	public function test_assembles_multiple_entries_from_one_chunk(): void {
		$text = "[02-Nov-2026 09:00:00 UTC] PHP Warning:  First in /a.php on line 1\n"
			. "[02-Nov-2026 09:01:00 UTC] PHP Notice:  Second in /b.php on line 2\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 2, $entries );
		self::assertSame( 'First', $entries[0]['message'] );
		self::assertSame( 'Second', $entries[1]['message'] );
	}

	public function test_ignores_an_orphan_line_before_any_header(): void {
		$text = "some leftover line from a previous partial read\n"
			. "[02-Nov-2026 09:00:00 UTC] PHP Warning:  Real entry in /a.php on line 1\n";

		$entries = $this->parser->parse( $text );

		self::assertCount( 1, $entries );
		self::assertSame( 'Real entry', $entries[0]['message'] );
	}

	public function test_returns_empty_for_blank_or_unrecognized_text(): void {
		self::assertSame( [], $this->parser->parse( '' ) );
		self::assertSame( [], $this->parser->parse( "just some noise\nmore noise\n" ) );
	}
}
