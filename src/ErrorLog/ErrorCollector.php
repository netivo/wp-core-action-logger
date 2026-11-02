<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\ErrorLog;

use Netivo\Module\ActionLogger\Payload\ErrorPayload;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\ErrorQueue;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ErrorCollector
 *
 * WP-Cron job (and WP-CLI entry point) that ties the scanner, tailer and
 * parser together: discovers known log files, tails each for new complete
 * lines, parses them into structured errors, and delivers the batch — falling
 * back to the ErrorQueue (drained by its own QueueWorker) on failure.
 */
class ErrorCollector {

	/**
	 * Cron hook name.
	 */
	public const HOOK = 'netivo_action_logger_collect_errors';

	/**
	 * Custom cron schedule name.
	 */
	public const SCHEDULE = 'nt_action_logger_twelve_minutes';

	/**
	 * Events per ingestion request (the API accepts up to 500).
	 */
	public const BATCH_SIZE = 100;

	/**
	 * ErrorCollector constructor.
	 *
	 * @param FileScanner $scanner Discovers known log files.
	 * @param FileTailer  $tailer  Reads new content since the last cycle.
	 * @param LogParser   $parser  Parses raw text into structured errors.
	 * @param Client      $client  Ingestion API client.
	 * @param ErrorQueue  $queue   Local fallback queue.
	 */
	public function __construct(
		private readonly FileScanner $scanner,
		private readonly FileTailer $tailer,
		private readonly LogParser $parser,
		private readonly Client $client,
		private readonly ErrorQueue $queue
	) {
	}

	/**
	 * Hooks the collector and its schedule into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- matches the architecture's documented 10-15 minute cadence.
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Adds the twelve-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => 12 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 12 minutes (Action Logger Errors)', 'netivo' ),
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
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
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
	 * Cron callback. Never throws.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			$this->collect();
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Scans, tails and parses every known log file, and delivers whatever was found.
	 *
	 * @return int Number of error entries collected.
	 */
	public function collect(): int {
		$files = $this->scanner->discover();
		$this->tailer->prune( array_keys( $files ) );

		$payloads = [];
		foreach ( $files as $path => $source ) {
			$chunk = $this->tailer->read( $path );
			if ( null === $chunk || '' === $chunk['text'] ) {
				continue;
			}

			foreach ( $this->parser->parse( $chunk['text'] ) as $entry ) {
				$payloads[] = ErrorPayload::create(
					$source,
					$entry['level'],
					$entry['message'],
					$entry['file'],
					$entry['line'],
					$entry['stack_trace'],
					[],
					$entry['occurred_at'],
					1,
					$path
				);
			}
		}

		if ( [] === $payloads ) {
			return 0;
		}

		$serialized = array_map( static fn ( ErrorPayload $payload ): array => $payload->to_array(), $payloads );
		foreach ( array_chunk( $serialized, self::BATCH_SIZE ) as $batch ) {
			$this->deliver( $batch );
		}

		return count( $serialized );
	}

	/**
	 * Sends one batch, queuing whatever the API did not accept for the error
	 * queue worker to retry.
	 *
	 * @param array<int, array<string, mixed>> $events Serialized error events.
	 *
	 * @return void
	 */
	private function deliver( array $events ): void {
		$report = $this->client->deliver_errors( $events );
		if ( [] !== $report['failed'] ) {
			$failed = array_intersect_key( $events, array_flip( $report['failed'] ) );
			$this->queue->push( array_values( $failed ), $report['result']->error() );
		}
	}
}
