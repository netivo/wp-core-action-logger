<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\ErrorLog;

use Netivo\Module\ActionLogger\Payload\ErrorPayload;
use Netivo\Module\ActionLogger\Transport\ErrorSink;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class RuntimeInterceptor
 *
 * Catches unhandled fatal crashes and uncaught exceptions as they happen,
 * independent of the FileTailer's cron cycle. Registered once the module is
 * active (on `plugins_loaded`/`after_setup_theme`, like every listener), so it
 * cannot see a crash earlier in the bootstrap — the FileTailer covers that gap
 * within its normal cron cycle.
 */
final class RuntimeInterceptor {

	/**
	 * PHP error types that terminate the script and are only observable via
	 * `error_get_last()` from a shutdown function.
	 */
	private const FATAL_TYPES = [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE ];

	/**
	 * Previously registered exception handler, if any, called after ours.
	 *
	 * @var callable|null
	 */
	private $previous_handler = null;

	/**
	 * Whether register() has already run.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * RuntimeInterceptor constructor.
	 *
	 * @param ErrorSink $sink Receives captured errors.
	 */
	public function __construct( private readonly ErrorSink $sink ) {
	}

	/**
	 * Hooks the fatal-error check and exception handler into PHP/WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		// Early among shutdown callbacks, so a fatal is buffered before
		// ErrorDispatcher::flush() (priority 999) and Dispatcher::flush() (1000) run.
		add_action( 'shutdown', [ $this, 'handle_fatal' ], 1 );

		$previous = set_exception_handler( [ $this, 'handle_exception' ] );
		if ( is_callable( $previous ) ) {
			$this->previous_handler = $previous;
		}
	}

	/**
	 * Shutdown callback: reports the last error if it was a fatal crash.
	 *
	 * @return void
	 */
	public function handle_fatal(): void {
		$error = error_get_last();
		if ( null === $error || ! in_array( $error['type'], self::FATAL_TYPES, true ) ) {
			return;
		}

		$this->report(
			'runtime_fatal',
			E_PARSE === $error['type'] ? 'parse' : 'fatal',
			$error['message'],
			$error['file'],
			$error['line']
		);
	}

	/**
	 * Uncaught-exception handler: reports it, then chains to any previously
	 * registered handler so other plugins' handlers still run.
	 *
	 * @param Throwable $exception Uncaught throwable.
	 *
	 * @return void
	 */
	public function handle_exception( Throwable $exception ): void {
		$this->report(
			'runtime_exception',
			'fatal',
			sprintf( 'Uncaught %s: %s', get_class( $exception ), $exception->getMessage() ),
			$exception->getFile(),
			$exception->getLine(),
			$exception->getTraceAsString()
		);

		if ( is_callable( $this->previous_handler ) ) {
			( $this->previous_handler )( $exception );
		}
	}

	/**
	 * Builds and dispatches an error payload. Never throws.
	 *
	 * @param string      $source  `runtime_fatal` or `runtime_exception`.
	 * @param string      $level   One of ErrorPayload::LEVELS.
	 * @param string      $message Error message.
	 * @param string      $file    Absolute file path.
	 * @param int         $line    Line number.
	 * @param string|null $trace   Stack trace, if any.
	 *
	 * @return void
	 */
	private function report( string $source, string $level, string $message, string $file, int $line, ?string $trace = null ): void {
		try {
			$this->sink->dispatch( ErrorPayload::create( $source, $level, $message, $file, $line, $trace ) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
