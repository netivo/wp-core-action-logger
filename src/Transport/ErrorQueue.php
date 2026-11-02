<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ErrorQueue
 *
 * Local fallback storage for error events that could not be delivered,
 * identical in every mechanic to Queue (retry counter, backoff, row cap) but
 * kept in its own table so a burst of error traffic never competes with or
 * blocks the audit-event queue.
 */
class ErrorQueue extends Queue {

	/**
	 * Table suffix (after `$wpdb->prefix`).
	 */
	public const TABLE = 'nt_action_log_error_queue';

	/**
	 * Option storing the installed schema version.
	 */
	public const VERSION_OPTION = 'nt_action_logger_error_queue_version';

	/**
	 * ErrorQueue constructor.
	 *
	 * @param wpdb|null $db Database connection; defaults to the global `$wpdb`.
	 */
	public function __construct( ?wpdb $db = null ) {
		parent::__construct( $db, self::TABLE, self::VERSION_OPTION );
	}
}
