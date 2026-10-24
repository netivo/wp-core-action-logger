<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Dispatcher
 *
 * Buffers events captured during a request and delivers them once, on `shutdown`.
 * Under PHP-FPM (or LiteSpeed) the response is finished first, so the visitor never
 * waits for the API; the request itself is blocking so its result can be checked.
 * Failed deliveries go to the local queue for the cron worker; payloads the API
 * rejects are dropped and recorded.
 */
class Dispatcher implements EventSink {

	/**
	 * Events per ingestion request (the API accepts up to 500).
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Events captured during this request.
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
	 * Dispatcher constructor.
	 *
	 * @param Config $config Module configuration.
	 * @param Queue  $queue  Local fallback queue.
	 * @param Client $client Ingestion API client.
	 * @param Status $status Delivery status store.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Queue $queue,
		private readonly Client $client,
		private readonly Status $status
	) {
	}

	/**
	 * Buffers an event; delivery happens on shutdown.
	 *
	 * @param EventPayload $event Captured event.
	 *
	 * @return void
	 */
	public function dispatch( EventPayload $event ): void {
		$this->buffer[] = $event->to_array();

		if ( ! $this->registered ) {
			// After wp_ob_end_flush_all (priority 1), so all output has been sent.
			add_action( 'shutdown', [ $this, 'flush' ], 1000 );
			$this->registered = true;
		}
	}

	/**
	 * Number of buffered events.
	 *
	 * @return int
	 */
	public function pending(): int {
		return count( $this->buffer );
	}

	/**
	 * Delivers (or queues) all buffered events. Never throws.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( [] === $this->buffer ) {
			return;
		}

		$events       = $this->buffer;
		$this->buffer = [];

		try {
			if ( Config::DELIVERY_QUEUE === $this->config->delivery() || $this->status->is_auth_blocked() ) {
				$this->queue->push( $events );

				return;
			}

			$this->finish_request();

			foreach ( array_chunk( $events, self::BATCH_SIZE ) as $chunk ) {
				$this->deliver( $chunk );
			}
		} catch ( Throwable $e ) {
			// The host site must never break because of audit logging.
			unset( $e );
		}
	}

	/**
	 * Sends one batch and routes events by outcome.
	 *
	 * @param array<int, array<string, mixed>> $events Serialized events.
	 *
	 * @return void
	 */
	private function deliver( array $events ): void {
		$report = $this->client->deliver( $events );

		if ( [] !== $report['delivered'] ) {
			$this->status->record_success();
		}

		if ( [] !== $report['failed'] ) {
			$failed = array_intersect_key( $events, array_flip( $report['failed'] ) );
			$this->queue->push( array_values( $failed ), $report['result']->error() );
			$this->status->record_failure( $report['result'] );
		}

		if ( [] !== $report['rejected'] ) {
			$this->status->record_dropped( count( $report['rejected'] ), $report['result']->error() );
		}
	}

	/**
	 * Sends the response to the client before talking to the API, when the SAPI allows.
	 *
	 * @return void
	 */
	protected function finish_request(): void {
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
}
