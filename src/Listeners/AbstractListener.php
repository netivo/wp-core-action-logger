<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Payload\Actor;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Transport\EventSink;
use Throwable;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class AbstractListener
 *
 * Base class for listeners that turn WordPress hooks into audit events. Hook
 * callbacks are wrapped so that an error in a listener never reaches the host site.
 */
abstract class AbstractListener {

	/**
	 * AbstractListener constructor.
	 *
	 * @param EventSink $sink   Receives captured events.
	 * @param Config    $config Module configuration.
	 */
	public function __construct(
		protected readonly EventSink $sink,
		protected readonly Config $config
	) {
	}

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	/**
	 * Registers a method of this listener as an action callback, swallowing errors.
	 *
	 * @param string $hook     Action name.
	 * @param string $method   Method of this class to call.
	 * @param int    $priority Hook priority.
	 * @param int    $args     Number of arguments passed to the method.
	 *
	 * @return void
	 */
	protected function on( string $hook, string $method, int $priority = 10, int $args = 1 ): void {
		add_action(
			$hook,
			function ( ...$params ) use ( $method ): void {
				try {
					$this->$method( ...$params );
				} catch ( Throwable $e ) {
					unset( $e );
				}
			},
			$priority,
			$args
		);
	}

	/**
	 * Registers a method as a filter callback that observes a value without
	 * changing it; the original value is always returned.
	 *
	 * @param string $hook     Filter name.
	 * @param string $method   Method of this class to call.
	 * @param int    $priority Hook priority.
	 * @param int    $args     Number of arguments passed to the method.
	 *
	 * @return void
	 */
	protected function observe( string $hook, string $method, int $priority = 10, int $args = 1 ): void {
		add_filter(
			$hook,
			function ( $value, ...$params ) use ( $method ) {
				try {
					$this->$method( $value, ...$params );
				} catch ( Throwable $e ) {
					unset( $e );
				}

				return $value;
			},
			$priority,
			$args
		);
	}

	/**
	 * Captures an event. Never throws.
	 *
	 * @param string                    $category Event category.
	 * @param string                    $action   Action slug.
	 * @param array<string, mixed>      $entity   Affected object.
	 * @param array<string, mixed>|null $changes  Changed fields.
	 * @param array<string, mixed>      $metadata Additional context.
	 * @param string                    $severity Event severity.
	 * @param Actor|null                $actor    Actor; defaults to the current user.
	 *
	 * @return void
	 */
	protected function log(
		string $category,
		string $action,
		array $entity = [],
		?array $changes = null,
		array $metadata = [],
		string $severity = 'info',
		?Actor $actor = null
	): void {
		try {
			$this->sink->dispatch(
				EventPayload::create(
					$category,
					$action,
					$actor ?? $this->actor(),
					$entity,
					$changes,
					$metadata + [ 'context' => self::context() ],
					$severity
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Actor for the current user.
	 *
	 * @return Actor
	 */
	protected function actor(): Actor {
		return Actor::current( $this->config->trusted_proxies() );
	}

	/**
	 * Actor for a specific user.
	 *
	 * @param WP_User $user WordPress user.
	 *
	 * @return Actor
	 */
	protected function actor_for( WP_User $user ): Actor {
		return Actor::from_user( $user, $this->config->trusted_proxies() );
	}

	/**
	 * Actor without an account.
	 *
	 * @param string|null $login Attempted login.
	 *
	 * @return Actor
	 */
	protected function anonymous( ?string $login = null ): Actor {
		return Actor::anonymous( $login, $this->config->trusted_proxies() );
	}

	/**
	 * Describes how the current request was made.
	 *
	 * @return string One of cli, cron, rest, ajax, admin, front.
	 */
	public static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		return is_admin() ? 'admin' : 'front';
	}
}
