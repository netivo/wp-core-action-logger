<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Admin\Page;

use Netivo\Core\Admin\Page;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Module;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use Netivo\Module\ActionLogger\Transport\Status as DeliveryStatus;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Status
 *
 * Read-only status page under Settings → Action Logger: connection state, endpoint,
 * masked key, queue backlog and last delivery. Its only form actions are a test ping
 * and draining the queue; configuration is never editable here.
 */
#[\Netivo\Attributes\View( 'action-logger-status' )]
class Status extends Page {

	/**
	 * Menu slug of the page.
	 */
	public const SLUG = 'nt-action-logger';

	/**
	 * Transient prefix for the last action result shown to a user.
	 */
	private const NOTICE_TRANSIENT = 'nt_action_logger_notice_';

	/**
	 * Status constructor.
	 *
	 * @param string         $path   Views directory of the module.
	 * @param Config         $config Module configuration.
	 * @param Queue          $queue  Local fallback queue.
	 * @param DeliveryStatus $status Delivery status store.
	 * @param Client         $client Ingestion API client.
	 * @param QueueWorker    $worker Queue worker.
	 */
	public function __construct(
		string $path,
		private readonly Config $config,
		private readonly Queue $queue,
		private readonly DeliveryStatus $status,
		private readonly Client $client,
		private readonly QueueWorker $worker
	) {
		$this->configure();
		parent::__construct( $path, [] );
	}

	/**
	 * Sets the page definition. wp-core 1.4 (PHP 8.4+) renamed the configuration
	 * properties without the `_` prefix and deprecates the old names, while 1.2 (the
	 * newest release installable on PHP 8.2/8.3) only knows the prefixed ones, so the
	 * names are chosen at runtime.
	 *
	 * @return void
	 */
	private function configure(): void {
		$settings = [
			'type'       => 'subpage',
			'parent'     => 'options-general.php',
			'page_title' => 'Action Logger',
			'menu_text'  => 'Action Logger',
			'capability' => 'manage_options',
			'menu_slug'  => self::SLUG,
		];

		$prefix = property_exists( Page::class, 'menu_slug' ) ? '' : '_';
		foreach ( $settings as $property => $value ) {
			$this->set_page_property( $prefix . $property, $value );
		}
	}

	/**
	 * Writes a page configuration property declared by the installed wp-core version.
	 *
	 * @param string $name  Property name (with or without the `_` prefix).
	 * @param string $value Value.
	 *
	 * @return void
	 */
	private function set_page_property( string $name, string $value ): void {
		$this->{$name} = $value;
	}

	/**
	 * Prepares view data before rendering.
	 *
	 * @return void
	 */
	public function do_action(): void {
		foreach ( $this->report() as $key => $value ) {
			$this->view->$key = $value;
		}
	}

	/**
	 * Handles the test ping and queue drain buttons, then redirects back.
	 *
	 * @return void
	 */
	public function save(): void {
		// wp-core 1.4 checks these before calling save(), but 1.2 does not.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'netivo' ), 403 );
		}
		check_admin_referer( 'save_' . self::SLUG );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		$notice = match ( $operation ) {
			'ping'  => $this->ping(),
			'flush' => $this->flush_queue(),
			default => null,
		};

		if ( null !== $notice ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		}

		$this->redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
	}

	/**
	 * Collects everything the view displays.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$status = $this->status->all();

		if ( ! $this->config->is_enabled() ) {
			$state = 'disabled';
		} elseif ( [] !== $this->config->errors() ) {
			$state = 'misconfigured';
		} elseif ( $this->status->is_auth_blocked() ) {
			$state = 'unauthorized';
		} elseif ( ! empty( $status['last_error_at'] ) && (int) $status['last_error_at'] > (int) ( $status['last_success_at'] ?? 0 ) ) {
			$state = 'offline';
		} elseif ( ! empty( $status['last_success_at'] ) ) {
			$state = 'connected';
		} else {
			$state = 'waiting';
		}

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		if ( false !== $notice ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		}

		return [
			'state'           => $state,
			'errors'          => $this->config->errors(),
			'endpoint'        => '' === $this->config->api_url() ? '' : $this->config->endpoint(),
			'masked_key'      => $this->config->masked_key(),
			'delivery'        => $this->config->delivery(),
			'backlog'         => $this->queue->count(),
			'oldest_queued'   => $this->queue->oldest(),
			'last_success_at' => isset( $status['last_success_at'] ) ? (int) $status['last_success_at'] : null,
			'last_error'      => isset( $status['last_error'] ) ? (string) $status['last_error'] : '',
			'last_error_at'   => isset( $status['last_error_at'] ) ? (int) $status['last_error_at'] : null,
			'dropped_total'   => (int) ( $status['dropped_total'] ?? 0 ),
			'next_run'        => wp_next_scheduled( QueueWorker::HOOK ),
			'version'         => Module::VERSION,
			'notice'          => is_array( $notice ) ? $notice : null,
			'can_send'        => $this->config->is_active(),
		];
	}

	/**
	 * Sends a synthetic event straight to the API and reports the round trip.
	 *
	 * @return array{type: string, message: string}
	 */
	private function ping(): array {
		if ( ! $this->config->is_active() ) {
			return [
				'type'    => 'error',
				'message' => __( 'The module is not configured, so no ping was sent.', 'netivo' ),
			];
		}

		$event = EventPayload::create(
			'system',
			'test_ping',
			Actor::current( $this->config->trusted_proxies() ),
			[
				'type'  => 'site',
				'title' => (string) get_bloginfo( 'name' ),
			],
			null,
			[
				'module_version' => Module::VERSION,
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			]
		);

		$started = microtime( true );
		$result  = $this->client->send( [ $event->to_array() ] );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( $result->is_delivered() ) {
			$this->status->record_success();

			return [
				'type'    => 'success',
				/* translators: %d: round trip in milliseconds. */
				'message' => sprintf( __( 'Ping delivered in %d ms. The event appears in the dashboard as "test_ping".', 'netivo' ), $elapsed ),
			];
		}

		$this->status->record_failure( $result );

		return [
			'type'    => 'error',
			/* translators: %s: failure reason. */
			'message' => sprintf( __( 'Ping failed: %s', 'netivo' ), $result->error() ),
		];
	}

	/**
	 * Runs the queue worker immediately.
	 *
	 * @return array{type: string, message: string}
	 */
	private function flush_queue(): array {
		$delivered = $this->worker->drain();
		$remaining = $this->queue->count();

		return [
			'type'    => 0 === $remaining ? 'success' : 'warning',
			/* translators: 1: delivered events, 2: events still queued. */
			'message' => sprintf( __( 'Delivered %1$d queued event(s); %2$d remaining.', 'netivo' ), $delivered, $remaining ),
		];
	}

	/**
	 * Redirects and stops the request.
	 *
	 * @param string $url Target URL.
	 *
	 * @return void
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
