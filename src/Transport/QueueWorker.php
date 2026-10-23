<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class QueueWorker
 *
 * WP-Cron job that drains the local queue in batches. It stops at the first
 * unavailable response (the remaining rows wait for their backoff), never runs
 * twice at once, and gives up on events that failed too many times.
 */
class QueueWorker {

	/**
	 * Cron hook name.
	 */
	public const HOOK = 'nt_action_logger_flush_queue';

	/**
	 * Custom cron schedule name.
	 */
	public const SCHEDULE = 'nt_action_logger_five_minutes';

	/**
	 * Events per request.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Batches per run at most.
	 */
	public const MAX_BATCHES = 10;

	/**
	 * Failed attempts after which an event is dropped (about two days with backoff).
	 */
	public const MAX_ATTEMPTS = 48;

	/**
	 * Seconds a run may spend sending before it stops.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Transient preventing overlapping runs.
	 */
	private const LOCK = 'nt_action_logger_worker_lock';

	/**
	 * QueueWorker constructor.
	 *
	 * @param Queue  $queue  Local fallback queue.
	 * @param Client $client Ingestion API client.
	 * @param Status $status Delivery status store.
	 */
	public function __construct(
		private readonly Queue $queue,
		private readonly Client $client,
		private readonly Status $status
	) {
	}

	/**
	 * Hooks the worker and its schedule into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes keeps the queue backlog short.
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Adds the five-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Action Logger)', 'netivo' ),
		];

		return $schedules;
	}

	/**
	 * Schedules the recurring event when it is missing.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Removes the recurring event.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->drain();
	}

	/**
	 * Drains due events. Never throws.
	 *
	 * @return int Number of delivered events.
	 */
	public function drain(): int {
		if ( false !== get_transient( self::LOCK ) ) {
			return 0;
		}
		set_transient( self::LOCK, time(), 2 * self::TIME_BUDGET );

		$delivered = 0;
		try {
			$this->status->record_dropped( $this->queue->drop_exhausted( self::MAX_ATTEMPTS ), 'too many failed delivery attempts' );

			$started = time();
			for ( $batch = 0; $batch < self::MAX_BATCHES && time() - $started < self::TIME_BUDGET; $batch++ ) {
				$rows = $this->queue->due( self::BATCH_SIZE );
				if ( [] === $rows ) {
					break;
				}

				$events = array_column( $rows, 'payload', 'id' );
				$report = $this->client->deliver( $events );

				$this->queue->delete( array_map( 'intval', array_merge( $report['delivered'], $report['rejected'] ) ) );
				$delivered += count( $report['delivered'] );

				if ( [] !== $report['delivered'] ) {
					$this->status->record_success();
				}
				if ( [] !== $report['rejected'] ) {
					$this->status->record_dropped( count( $report['rejected'] ), $report['result']->error() );
				}
				if ( [] !== $report['failed'] ) {
					$this->status->record_failure( $report['result'] );
					// A refused token is not the events' fault: keep them due without
					// spending attempts, and try again on the next run.
					if ( DeliveryResult::UNAUTHORIZED !== $report['result']->status() ) {
						$this->queue->release( array_map( 'intval', $report['failed'] ), $report['result']->error() );
					}
					break;
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		} finally {
			delete_transient( self::LOCK );
		}

		return $delivered;
	}
}
