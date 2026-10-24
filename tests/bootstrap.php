<?php
/**
 * PHPUnit bootstrap: defines ABSPATH, loads Composer and minimal WordPress
 * class stubs. WordPress functions are mocked per test with Brain Monkey.
 */

// Ensure ABSPATH is defined before loading any code that checks it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/wordpress.php';
require_once __DIR__ . '/Stubs/woocommerce.php';
