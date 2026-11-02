<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\ErrorLog;

use Netivo\Module\ActionLogger\ErrorLog\FileScanner;
use Netivo\Module\ActionLogger\Tests\TestCase;

final class FileScannerTest extends TestCase {
	private string|false $original_ini_error_log = false;

	protected function setUp(): void {
		parent::setUp();
		$this->original_ini_error_log = ini_get( 'error_log' );
		mkdir( WP_CONTENT_DIR . '/uploads/wc-logs', 0777, true );
		mkdir( WP_CONTENT_DIR . '/uploads/elementor/logs', 0777, true );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->original_ini_error_log ? '' : $this->original_ini_error_log );
		$this->remove_tree( WP_CONTENT_DIR );
		parent::tearDown();
	}

	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_tree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_discovers_the_default_debug_log(): void {
		file_put_contents( WP_CONTENT_DIR . '/debug.log', '' );

		$found = ( new FileScanner() )->discover();

		self::assertSame( 'wp_debug_log', $found[ WP_CONTENT_DIR . '/debug.log' ] ?? null );
	}

	public function test_discovers_the_content_directory_server_error_log(): void {
		file_put_contents( WP_CONTENT_DIR . '/error_log', '' );

		$found = ( new FileScanner() )->discover();

		self::assertSame( 'server_content_error_log', $found[ WP_CONTENT_DIR . '/error_log' ] ?? null );
	}

	public function test_discovers_woocommerce_fatal_and_critical_logs_by_glob(): void {
		$fatal    = WP_CONTENT_DIR . '/uploads/wc-logs/fatal-errors-2026-11-02.log';
		$critical = WP_CONTENT_DIR . '/uploads/wc-logs/critical-2026-11-02.log';
		file_put_contents( $fatal, '' );
		file_put_contents( $critical, '' );

		$found = ( new FileScanner() )->discover();

		self::assertSame( 'wc_fatal_logs', $found[ $fatal ] ?? null );
		self::assertSame( 'wc_critical_logs', $found[ $critical ] ?? null );
	}

	public function test_discovers_elementor_logs_by_glob(): void {
		$log = WP_CONTENT_DIR . '/uploads/elementor/logs/2026-11-02.log';
		file_put_contents( $log, '' );

		$found = ( new FileScanner() )->discover();

		self::assertSame( 'elementor_logs', $found[ $log ] ?? null );
	}

	public function test_discovers_the_configured_php_ini_error_log(): void {
		$path = WP_CONTENT_DIR . '/php-fpm-error.log';
		file_put_contents( $path, '' );
		ini_set( 'error_log', $path );

		$found = ( new FileScanner() )->discover();

		self::assertSame( 'php_ini_log', $found[ $path ] ?? null );
	}

	public function test_does_not_discover_a_file_that_does_not_exist(): void {
		$found = ( new FileScanner() )->discover();

		self::assertArrayNotHasKey( WP_CONTENT_DIR . '/debug.log', $found );
	}

	public function test_does_not_discover_a_directory(): void {
		mkdir( WP_CONTENT_DIR . '/debug.log' );

		$found = ( new FileScanner() )->discover();

		self::assertArrayNotHasKey( WP_CONTENT_DIR . '/debug.log', $found );
	}
}
