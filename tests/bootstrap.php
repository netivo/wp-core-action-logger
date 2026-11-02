<?php
/**
 * PHPUnit bootstrap: defines ABSPATH, loads Composer and minimal WordPress
 * class stubs. WordPress functions are mocked per test with Brain Monkey.
 */

// Ensure ABSPATH is defined before loading any code that checks it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// A dedicated, cleanable sandbox for tests that touch the filesystem (FileScanner,
// FileTailer), kept out of ABSPATH so it never collides with its parent directory.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/nt-al-tests/wp-content' );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/wordpress.php';
require_once __DIR__ . '/Stubs/woocommerce.php';
