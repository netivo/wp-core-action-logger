<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Payload\ErrorPayload;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ErrorDispatcher
 *
 * Buffers runtime errors captured during a request (fatals, uncaught
 * exceptions) and delivers them once, on `shutdown` — the same mechanic as
 * Dispatcher, kept separate so a burst of errors can never starve or delay
 * audit-event delivery. Failed deliveries go to the ErrorQueue for the error
 * queue worker; payloads the API rejects are dropped and recorded.
 */
class ErrorDispatcher implements ErrorSink {

	/**
	 * Events per ingestion request (the API accepts up to 500).
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Errors captured during this request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $buffer = [];

	/**
	 * Whether the shutdown hook has been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * ErrorDispatcher constructor.
	 *
	 * @param Config     $config Module configuration.
	 * @param ErrorQueue $queue  Local fallback queue.
	 * @param Client     $client Ingestion API client.
	 * @param Status     $status Delivery status store.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ErrorQueue $queue,
		private readonly Client $client,
		private readonly Status $status
	) {
	}

	/**
	 * Buffers an error; delivery happens on shutdown.
	 *
	 * @param ErrorPayload $error Captured error.
	 *
	 * @return void
	 */
	public function dispatch( ErrorPayload $error ): void {
		$this->buffer[] = $error->to_array();

		if ( ! $this->registered ) {
			// Runs on the same hook as Dispatcher::flush(), slightly earlier so a
			// fatal reported by RuntimeInterceptor (priority 1) is buffered in time.
			add_action( 'shutdown', [ $this, 'flush' ], 999 );
			$this->registered = true;
		}
	}

	/**
	 * Number of buffered errors.
	 *
	 * @return int
	 */
	public function pending(): int {
		return count( $this->buffer );
	}

	/**
	 * Delivers (or queues) all buffered errors. Never throws.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( [] === $this->buffer ) {
			return;
		}

		$errors       = $this->buffer;
		$this->buffer = [];

		try {
			if ( Config::DELIVERY_QUEUE === $this->config->delivery() || $this->status->is_auth_blocked() ) {
				$this->queue->push( $errors );

				return;
			}

			$this->finish_request();

			foreach ( array_chunk( $errors, self::BATCH_SIZE ) as $chunk ) {
				$this->deliver( $chunk );
			}
		} catch ( Throwable $e ) {
			// The host site must never break because of error logging.
			unset( $e );
		}
	}

	/**
	 * Sends the response to the client before talking to the API, when the SAPI
	 * allows. Idempotent: harmless if Dispatcher already finished the request.
	 *
	 * @return void
	 */
	private function finish_request(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}

		ignore_user_abort( true );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Sends one batch and routes events by outcome.
	 *
	 * @param array<int, array<string, mixed>> $errors Serialized error events.
	 *
	 * @return void
	 */
	private function deliver( array $errors ): void {
		$report = $this->client->deliver_errors( $errors );

		if ( [] !== $report['delivered'] ) {
			$this->status->record_success();
		}

		if ( [] !== $report['failed'] ) {
			$failed = array_intersect_key( $errors, array_flip( $report['failed'] ) );
			$this->queue->push( array_values( $failed ), $report['result']->error() );
			$this->status->record_failure( $report['result'] );
		}

		if ( [] !== $report['rejected'] ) {
			$this->status->record_dropped( count( $report['rejected'] ), $report['result']->error() );
		}
	}
}
