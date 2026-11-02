<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Cli;

use Netivo\Module\ActionLogger\ErrorLog\ErrorCollector;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ErrorLogCommand
 *
 * WP-CLI command running the error-log collection cycle on demand
 * (`wp action-logger collect-errors`), useful on hosts where WP-Cron is
 * disabled in favor of a system cron calling `wp cron event run`.
 */
final class ErrorLogCommand {

	/**
	 * ErrorLogCommand constructor.
	 *
	 * @param ErrorCollector $collector Runs the scan/tail/parse/deliver cycle.
	 */
	public function __construct( private readonly ErrorCollector $collector ) {
	}

	/**
	 * Registers `wp action-logger collect-errors` with WP-CLI.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		WP_CLI::add_command( 'action-logger collect-errors', [ $this, 'collect_errors' ] );
	}

	/**
	 * Runs one error-log collection cycle immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp action-logger collect-errors
	 *
	 * @return void
	 */
	public function collect_errors(): void {
		$count = $this->collector->collect();
		WP_CLI::success( sprintf( '%d error log entr%s collected.', $count, 1 === $count ? 'y' : 'ies' ) );
	}
}
