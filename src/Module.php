<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger;

use Netivo\Module\ActionLogger\Admin\Admin;
use Netivo\Module\ActionLogger\Listeners\AbstractListener;
use Netivo\Module\ActionLogger\Listeners\AuthListener;
use Netivo\Module\ActionLogger\Listeners\ContentListener;
use Netivo\Module\ActionLogger\Listeners\SettingsListener;
use Netivo\Module\ActionLogger\Listeners\SystemListener;
use Netivo\Module\ActionLogger\Listeners\UserListener;
use Netivo\Module\ActionLogger\Listeners\WooCommerceListener;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\Dispatcher;
use Netivo\Module\ActionLogger\Transport\EventSink;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use Netivo\Module\ActionLogger\Transport\Status;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Module
 *
 * Entry point of the Action Logger module. Instantiate it from the theme
 * (`new \Netivo\Module\ActionLogger\Module();`). Configuration is resolved on
 * `after_setup_theme`, once the theme and its config files are loaded; when the
 * module is disabled or misconfigured nothing is captured.
 */
class Module {

	/**
	 * Module version, sent in the User-Agent of API requests.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Resolved configuration.
	 *
	 * @var Config|null
	 */
	private ?Config $config;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Local fallback queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue = null;

	/**
	 * Delivery status store.
	 *
	 * @var Status|null
	 */
	private ?Status $status = null;

	/**
	 * Ingestion API client.
	 *
	 * @var Client|null
	 */
	private ?Client $client = null;

	/**
	 * Queue worker.
	 *
	 * @var QueueWorker|null
	 */
	private ?QueueWorker $worker = null;

	/**
	 * Request-scoped event dispatcher.
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher = null;

	/**
	 * Module constructor.
	 *
	 * @param Config|null $config Configuration to use instead of resolving it (mainly for tests).
	 */
	public function __construct( ?Config $config = null ) {
		$this->config = $config;

		if ( did_action( 'after_setup_theme' ) ) {
			$this->boot();
		} else {
			add_action( 'after_setup_theme', [ $this, 'boot' ], 20 );
		}
	}

	/**
	 * Resolves the configuration and wires transport, listeners and admin. Never throws.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		try {
			$config       = $this->config ?? Config::load();
			$this->config = $config;

			$this->queue  = new Queue();
			$this->status = new Status();
			$this->client = new Client( $config );
			$this->worker = new QueueWorker( $this->queue, $this->client, $this->status );

			$this->queue->install();
			$this->worker->register();

			if ( ! $config->is_active() ) {
				$this->worker->unschedule();
				$this->boot_admin();

				return;
			}

			$this->worker->schedule();
			$this->dispatcher = new Dispatcher( $config, $this->queue, $this->client, $this->status );

			foreach ( $this->listeners( $this->dispatcher, $config ) as $listener ) {
				$listener->register();
			}

			$this->boot_admin();
		} catch ( Throwable $e ) {
			// The host site must never break because of audit logging.
			unset( $e );
		}
	}

	/**
	 * Builds the listeners to register.
	 *
	 * @param EventSink $sink   Receives captured events.
	 * @param Config    $config Module configuration.
	 *
	 * @return AbstractListener[]
	 */
	protected function listeners( EventSink $sink, Config $config ): array {
		$listeners = [
			new AuthListener( $sink, $config ),
			new ContentListener( $sink, $config ),
			new SystemListener( $sink, $config ),
			new UserListener( $sink, $config ),
			new SettingsListener( $sink, $config ),
		];

		// Plugins are loaded before after_setup_theme, so WooCommerce is detectable here.
		if ( class_exists( 'WooCommerce' ) ) {
			$listeners[] = new WooCommerceListener( $sink, $config );
		}

		return $listeners;
	}

	/**
	 * Attaches the admin status page.
	 *
	 * @return void
	 */
	protected function boot_admin(): void {
		if ( ! is_admin() || null === $this->config || null === $this->queue || null === $this->status || null === $this->client || null === $this->worker ) {
			return;
		}

		new Admin( $this->config, $this->queue, $this->status, $this->client, $this->worker );
	}

	/**
	 * Resolved configuration, once booted.
	 *
	 * @return Config|null
	 */
	public function config(): ?Config {
		return $this->config;
	}

	/**
	 * Event dispatcher, when the module is active.
	 *
	 * @return Dispatcher|null
	 */
	public function dispatcher(): ?Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * Retrieves the file system path of the module directory.
	 *
	 * @return string|null Absolute path to the module directory, or null if it cannot be resolved.
	 */
	public static function get_module_path(): ?string {
		$path = realpath( __DIR__ . '/../' );

		return false === $path ? null : $path;
	}
}
