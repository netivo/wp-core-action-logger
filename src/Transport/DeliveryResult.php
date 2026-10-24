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
 * Class DeliveryResult
 *
 * Outcome of one ingestion request, classified by what the caller should do next.
 */
final class DeliveryResult {

	/**
	 * The API accepted the batch (2xx).
	 */
	public const DELIVERED = 'delivered';

	/**
	 * Temporary failure (network error, timeout, 429, 5xx): keep the events and retry.
	 */
	public const RETRY = 'retry';

	/**
	 * The site token was refused (401/403): keep the events, pause direct sending.
	 */
	public const UNAUTHORIZED = 'unauthorized';

	/**
	 * The API rejected the payload itself (400/413/422): retrying it unchanged cannot succeed.
	 */
	public const REJECTED = 'rejected';

	/**
	 * DeliveryResult constructor.
	 *
	 * @param string   $status One of the class constants.
	 * @param int|null $code   HTTP status code, null for transport errors.
	 * @param string   $error  Human-readable failure reason, empty on success.
	 */
	public function __construct(
		private readonly string $status,
		private readonly ?int $code = null,
		private readonly string $error = ''
	) {
	}

	/**
	 * Classifies an HTTP response code.
	 *
	 * @param int    $code  HTTP status code.
	 * @param string $error Error message from the response body, if any.
	 *
	 * @return self
	 */
	public static function from_status_code( int $code, string $error = '' ): self {
		if ( $code >= 200 && $code < 300 ) {
			return new self( self::DELIVERED, $code );
		}

		$message = '' === $error ? sprintf( 'HTTP %d', $code ) : sprintf( 'HTTP %d: %s', $code, $error );

		if ( 401 === $code || 403 === $code ) {
			return new self( self::UNAUTHORIZED, $code, $message );
		}
		if ( 400 === $code || 413 === $code || 422 === $code ) {
			return new self( self::REJECTED, $code, $message );
		}

		return new self( self::RETRY, $code, $message );
	}

	/**
	 * Status, one of the class constants.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * HTTP status code, null for transport errors.
	 *
	 * @return int|null
	 */
	public function code(): ?int {
		return $this->code;
	}

	/**
	 * Failure reason, empty on success.
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Whether the events were accepted.
	 *
	 * @return bool
	 */
	public function is_delivered(): bool {
		return self::DELIVERED === $this->status;
	}
}
