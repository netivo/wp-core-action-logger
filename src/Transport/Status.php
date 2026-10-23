<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Status
 *
 * Delivery health stored in a non-autoloaded option: last success, last error and
 * whether the API refused the site token. Read by the status page and used to pause
 * direct sending while the token is refused.
 */
class Status {

	/**
	 * Option holding the status array.
	 */
	public const OPTION = 'nt_action_logger_status';

	/**
	 * Successful deliveries are recorded at most this often (seconds), to avoid an
	 * option write on every request.
	 */
	private const SUCCESS_WRITE_INTERVAL = 60;

	/**
	 * Cached option value.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Records an accepted delivery and clears any error state.
	 *
	 * @return void
	 */
	public function record_success(): void {
		$data = $this->all();
		$last = (int) ( $data['last_success_at'] ?? 0 );

		if ( empty( $data['auth_failed_at'] ) && time() - $last < self::SUCCESS_WRITE_INTERVAL ) {
			return;
		}

		$data['last_success_at'] = time();
		unset( $data['auth_failed_at'] );
		$this->save( $data );
	}

	/**
	 * Records a failed delivery.
	 *
	 * @param DeliveryResult $result Failed result.
	 *
	 * @return void
	 */
	public function record_failure( DeliveryResult $result ): void {
		$data                    = $this->all();
		$data['last_error']      = $result->error();
		$data['last_error_code'] = $result->code();
		$data['last_error_at']   = time();

		if ( DeliveryResult::UNAUTHORIZED === $result->status() ) {
			$data['auth_failed_at'] = $data['auth_failed_at'] ?? time();
		}

		$this->save( $data );
	}

	/**
	 * Records events that were given up (rejected payloads or too many attempts).
	 *
	 * @param int    $count  Number of dropped events.
	 * @param string $reason Why they were dropped.
	 *
	 * @return void
	 */
	public function record_dropped( int $count, string $reason ): void {
		if ( $count <= 0 ) {
			return;
		}

		$data                  = $this->all();
		$data['dropped_total'] = (int) ( $data['dropped_total'] ?? 0 ) + $count;
		$data['last_error']    = sprintf( '%d event(s) dropped: %s', $count, $reason );
		$data['last_error_at'] = time();
		$this->save( $data );
	}

	/**
	 * Whether the API refused the site token and direct sending is paused.
	 *
	 * @return bool
	 */
	public function is_auth_blocked(): bool {
		return ! empty( $this->all()['auth_failed_at'] );
	}

	/**
	 * Full status array (timestamps are Unix seconds).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->data ) {
			$value      = get_option( self::OPTION, [] );
			$this->data = is_array( $value ) ? $value : [];
		}

		return $this->data;
	}

	/**
	 * Persists the status without autoloading it on every request.
	 *
	 * @param array<string, mixed> $data Status array.
	 *
	 * @return void
	 */
	private function save( array $data ): void {
		$this->data = $data;
		update_option( self::OPTION, $data, false );
	}
}
