<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Admin;

use Netivo\Module\ActionLogger\Admin\Page\Status;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Module;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use Netivo\Module\ActionLogger\Transport\Status as DeliveryStatus;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Admin
 *
 * Bootstraps the module's admin pages.
 */
class Admin {

	/**
	 * Admin constructor.
	 *
	 * @param Config         $config Module configuration.
	 * @param Queue          $queue  Local fallback queue.
	 * @param DeliveryStatus $status Delivery status store.
	 * @param Client         $client Ingestion API client.
	 * @param QueueWorker    $worker Queue worker.
	 */
	public function __construct( Config $config, Queue $queue, DeliveryStatus $status, Client $client, QueueWorker $worker ) {
		new Status( (string) Module::get_module_path() . '/views', $config, $queue, $status, $client, $worker );
	}
}
