<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Module
 *
 * Entry point of the Action Logger module.
 */
class Module {

	/**
	 * Module version, sent in the User-Agent of API requests.
	 */
	public const VERSION = '1.0.0';
}
