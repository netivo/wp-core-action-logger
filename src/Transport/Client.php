<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Transport;

use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Module;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Client
 *
 * Sends event batches to the ingestion API with blocking requests, so every
 * response code and transport error is observed. (Non-blocking requests in
 * WordPress return an empty response and hide all failures.)
 */
class Client {

	/**
	 * Client constructor.
	 *
	 * @param Config $config Module configuration.
	 */
	public function __construct( private readonly Config $config ) {
	}

	/**
	 * Sends one batch and classifies the outcome.
	 *
	 * @param array<int|string, array<string, mixed>|object> $events Serialized events.
	 *
	 * @return DeliveryResult
	 */
	public function send( array $events ): DeliveryResult {
		$body = wp_json_encode(
			[
				'system_type' => 'wordpress',
				'events'      => array_values( $events ),
			]
		);
		if ( false === $body ) {
			return new DeliveryResult( DeliveryResult::REJECTED, null, 'Events could not be encoded as JSON.' );
		}

		$response = wp_remote_post(
			$this->config->endpoint(),
			[
				'blocking'    => true,
				'timeout'     => $this->config->timeout(),
				'redirection' => 0,
				'user-agent'  => 'netivo-action-logger/' . Module::VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				'headers'     => [
					'Authorization' => 'Bearer ' . $this->config->api_key(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( $response instanceof WP_Error ) {
			return new DeliveryResult( DeliveryResult::RETRY, null, $response->get_error_message() );
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$error = '';
		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$error = $decoded['error']['message'];
			}
		}

		return DeliveryResult::from_status_code( $code, $error );
	}

	/**
	 * Sends events and sorts their keys by outcome. When the API rejects a batch,
	 * it is bisected so that only the events it actually refuses are given up.
	 *
	 * @param array<int|string, array<string, mixed>|object> $events Serialized events keyed by caller ID.
	 *
	 * @return array{delivered: array<int, int|string>, failed: array<int, int|string>, rejected: array<int, int|string>, result: DeliveryResult}
	 */
	public function deliver( array $events ): array {
		$report = [
			'delivered' => [],
			'failed'    => [],
			'rejected'  => [],
			'result'    => new DeliveryResult( DeliveryResult::DELIVERED ),
		];
		if ( [] === $events ) {
			return $report;
		}

		$result = $this->send( $events );

		if ( $result->is_delivered() ) {
			$report['delivered'] = array_keys( $events );

			return $report;
		}

		if ( DeliveryResult::REJECTED !== $result->status() || 1 === count( $events ) ) {
			$bucket            = DeliveryResult::REJECTED === $result->status() ? 'rejected' : 'failed';
			$report[ $bucket ] = array_keys( $events );
			$report['result']  = $result;

			return $report;
		}

		$half = intdiv( count( $events ), 2 );
		foreach ( [ array_slice( $events, 0, $half, true ), array_slice( $events, $half, null, true ) ] as $part ) {
			$sub = $this->deliver( $part );

			$report['delivered'] = array_merge( $report['delivered'], $sub['delivered'] );
			$report['failed']    = array_merge( $report['failed'], $sub['failed'] );
			$report['rejected']  = array_merge( $report['rejected'], $sub['rejected'] );
			if ( ! $sub['result']->is_delivered() ) {
				$report['result'] = $sub['result'];
			}
			if ( DeliveryResult::REJECTED !== $sub['result']->status() && ! $sub['result']->is_delivered() ) {
				// The API became unavailable mid-way: keep everything not yet sent.
				$remaining          = array_diff_key( $events, array_flip( array_merge( $report['delivered'], $report['failed'], $report['rejected'] ) ) );
				$report['failed']   = array_merge( $report['failed'], array_keys( $remaining ) );
				break;
			}
		}

		return $report;
	}
}
