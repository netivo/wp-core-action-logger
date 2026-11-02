<?php
/**
 * Minimal WP-CLI stub for PHPStan only (not shipped with the module itself,
 * see .gitattributes export-ignore). php-stubs/wp-cli-stubs is pinned to a
 * wordpress-stubs major this package has outgrown, so this declares just the
 * two static methods Cli\ErrorLogCommand actually calls.
 */

if ( ! class_exists( 'WP_CLI', false ) ) {
	/**
	 * Stub of the WP-CLI facade class.
	 */
	class WP_CLI {

		/**
		 * Registers a command.
		 *
		 * @param string   $name     Command name.
		 * @param callable $callable Command callback.
		 *
		 * @return void
		 */
		public static function add_command( string $name, callable $callable ): void {
		}

		/**
		 * Prints a success message.
		 *
		 * @param string $message Message.
		 *
		 * @return void
		 */
		public static function success( string $message ): void {
		}
	}
}
