<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\ErrorLog;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\ErrorLog\FileTailer;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class FileTailerTest extends TestCase {
	private string $dir;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/nt-al-tests/tailer-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0777, true );
		$this->options = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, mixed $default = false ): mixed {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value, mixed $autoload = true ): bool {
				unset( $autoload );
				$this->options[ $name ] = $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	private function write( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );

		return $path;
	}

	public function test_reads_a_small_file_from_the_start(): void {
		$path = $this->write( 'debug.log', "line one\nline two\n" );

		$result = ( new FileTailer() )->read( $path );

		self::assertSame( "line one\nline two\n", $result['text'] );
	}

	public function test_a_second_read_only_returns_newly_appended_content(): void {
		$path   = $this->write( 'debug.log', "line one\n" );
		$tailer = new FileTailer();

		$tailer->read( $path );
		file_put_contents( $path, "line two\n", FILE_APPEND );
		$second = $tailer->read( $path );

		self::assertSame( "line two\n", $second['text'] );
	}

	public function test_holds_back_a_trailing_partial_line_for_the_next_cycle(): void {
		$path   = $this->write( 'debug.log', "complete line\npartial line without a newline" );
		$tailer = new FileTailer();

		$first = $tailer->read( $path );
		self::assertSame( "complete line\n", $first['text'] );

		file_put_contents( $path, " now finished\n", FILE_APPEND );
		$second = $tailer->read( $path );
		self::assertSame( "partial line without a newline now finished\n", $second['text'] );
	}

	public function test_first_run_on_a_huge_pre_existing_log_seeks_near_the_end(): void {
		$old_marker = "START-MARKER\n";
		$padding    = str_repeat( 'x', FileTailer::FIRST_RUN_CAP_BYTES + 1024 ) . "\n";
		$end_marker = "END-MARKER\n";
		$path       = $this->write( 'debug.log', $old_marker . $padding . $end_marker );

		$result = ( new FileTailer() )->read( $path );

		self::assertStringNotContainsString( 'START-MARKER', $result['text'] );
		self::assertStringContainsString( 'END-MARKER', $result['text'] );
	}

	public function test_caps_the_amount_read_per_cycle(): void {
		// A small first read establishes offset state without tripping the
		// first-run cap, so the large append below exercises MAX_READ_BYTES alone.
		$path   = $this->write( 'debug.log', "seed\n" );
		$tailer = new FileTailer();
		$tailer->read( $path );

		$line     = str_repeat( 'a', 999 ) . "\n"; // 1000 bytes per line.
		$appended = str_repeat( $line, 600 ); // 600,000 bytes: over MAX_READ_BYTES.
		file_put_contents( $path, $appended, FILE_APPEND );

		$first = $tailer->read( $path );
		self::assertLessThanOrEqual( FileTailer::MAX_READ_BYTES, strlen( $first['text'] ) );
		self::assertGreaterThan( 0, strlen( $first['text'] ) );

		$second = $tailer->read( $path );
		self::assertSame( $appended, $first['text'] . $second['text'] );
	}

	public function test_detects_truncation_and_restarts_from_the_beginning(): void {
		$path   = $this->write( 'debug.log', "line one\nline two\nline three\n" );
		$tailer = new FileTailer();
		$tailer->read( $path );

		file_put_contents( $path, "fresh start\n" );
		$result = $tailer->read( $path );

		self::assertSame( "fresh start\n", $result['text'] );
	}

	public function test_returns_empty_text_when_nothing_new_was_written(): void {
		$path   = $this->write( 'debug.log', "line one\n" );
		$tailer = new FileTailer();
		$tailer->read( $path );

		$result = $tailer->read( $path );

		self::assertSame( '', $result['text'] );
	}

	public function test_returns_null_for_a_missing_file(): void {
		self::assertNull( ( new FileTailer() )->read( $this->dir . '/does-not-exist.log' ) );
	}

	public function test_prune_drops_state_for_files_no_longer_discovered(): void {
		$kept    = $this->write( 'kept.log', "a\n" );
		$dropped = $this->write( 'dropped.log', "b\n" );
		$tailer  = new FileTailer();
		$tailer->read( $kept );
		$tailer->read( $dropped );

		$tailer->prune( [ $kept ] );

		$state = $this->options[ FileTailer::OPTION ];
		self::assertArrayHasKey( $kept, $state );
		self::assertArrayNotHasKey( $dropped, $state );
	}
}
