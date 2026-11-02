<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use Netivo\Module\ActionLogger\Payload\ErrorPayload;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Interface ErrorSink
 *
 * Receives error events captured by the runtime interceptor.
 */
interface ErrorSink {

	/**
	 * Accepts an error event for delivery.
	 *
	 * @param ErrorPayload $error Captured error.
	 *
	 * @return void
	 */
	public function dispatch( ErrorPayload $error ): void;
}
