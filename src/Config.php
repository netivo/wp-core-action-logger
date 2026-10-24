<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger;

use Netivo\Core\Theme;
use ReflectionException;
use ReflectionProperty;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Config
 *
 * Immutable module configuration. Values are resolved from (lowest to highest priority):
 * the `netivo_action_logger_config` filter, theme options stored in the database,
 * the Netivo theme configuration (`<theme>/config/*.config.php`, key `action_logger`)
 * and finally `NETIVO_ACTION_LOGGER_*` constants. There is intentionally no writable
 * admin form.
 */
final class Config {

	/**
	 * Deliver events at the end of the request, after the response was sent when possible.
	 */
	public const DELIVERY_SHUTDOWN = 'shutdown';

	/**
	 * Write every event to the local queue and let the cron worker deliver it.
	 */
	public const DELIVERY_QUEUE = 'queue';

	/**
	 * Default total HTTP timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 3;

	/**
	 * Site token format issued by the dashboard.
	 */
	private const KEY_PATTERN = '/^nt_live_[0-9a-f]{32}$/';

	/**
	 * Configuration key => constant name.
	 */
	private const CONSTANTS = [
		'api_url'         => 'NETIVO_ACTION_LOGGER_API_URL',
		'api_key'         => 'NETIVO_ACTION_LOGGER_API_KEY',
		'enabled'         => 'NETIVO_ACTION_LOGGER_ENABLED',
		'delivery'        => 'NETIVO_ACTION_LOGGER_DELIVERY',
		'timeout'         => 'NETIVO_ACTION_LOGGER_TIMEOUT',
		'trusted_proxies' => 'NETIVO_ACTION_LOGGER_TRUSTED_PROXIES',
	];

	/**
	 * Configuration key => key inside the `theme_options` option.
	 */
	private const THEME_OPTIONS = [
		'api_url' => 'action_logger_api_url',
		'api_key' => 'action_logger_api_key',
		'enabled' => 'action_logger_enabled',
	];

	/**
	 * Config constructor.
	 *
	 * @param string   $api_url         Base URL of the Action Logger API.
	 * @param string   $api_key         Site token (`nt_live_...`).
	 * @param bool     $enabled         Whether logging is switched on.
	 * @param string   $delivery        One of the DELIVERY_* constants.
	 * @param int      $timeout         Total HTTP timeout in seconds.
	 * @param string[] $trusted_proxies Proxy IPs whose X-Forwarded-For header is trusted.
	 */
	public function __construct(
		private readonly string $api_url,
		private readonly string $api_key,
		private readonly bool $enabled,
		private readonly string $delivery,
		private readonly int $timeout,
		private readonly array $trusted_proxies
	) {
	}

	/**
	 * Resolves the configuration from all supported sources.
	 *
	 * @return self
	 */
	public static function load(): self {
		$values = self::defaults();

		$filtered = apply_filters( 'netivo_action_logger_config', $values );
		if ( is_array( $filtered ) ) {
			$values = array_merge( $values, self::known( $filtered ) );
		}

		$values = array_merge(
			$values,
			self::from_theme_options(),
			self::from_theme_config(),
			self::from_constants()
		);

		return self::from_array( $values );
	}

	/**
	 * Builds a configuration from raw values, normalizing types.
	 *
	 * @param array<string, mixed> $values Raw configuration values.
	 *
	 * @return self
	 */
	public static function from_array( array $values ): self {
		$values = array_merge( self::defaults(), $values );

		$delivery = is_string( $values['delivery'] ) ? strtolower( trim( $values['delivery'] ) ) : '';
		if ( ! in_array( $delivery, [ self::DELIVERY_SHUTDOWN, self::DELIVERY_QUEUE ], true ) ) {
			$delivery = self::DELIVERY_SHUTDOWN;
		}

		$timeout = is_numeric( $values['timeout'] ) ? (int) $values['timeout'] : self::DEFAULT_TIMEOUT;

		$proxies = $values['trusted_proxies'];
		if ( is_string( $proxies ) ) {
			$proxies = explode( ',', $proxies );
		}
		$proxies = is_array( $proxies ) ? array_values( array_filter( array_map( 'trim', array_filter( $proxies, 'is_string' ) ) ) ) : [];

		return new self(
			is_string( $values['api_url'] ) ? trim( $values['api_url'] ) : '',
			is_string( $values['api_key'] ) ? trim( $values['api_key'] ) : '',
			self::to_bool( $values['enabled'] ),
			$delivery,
			max( 1, min( 10, $timeout ) ),
			$proxies
		);
	}

	/**
	 * Default values used when no source provides a setting.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return [
			'api_url'         => '',
			'api_key'         => '',
			'enabled'         => true,
			'delivery'        => self::DELIVERY_SHUTDOWN,
			'timeout'         => self::DEFAULT_TIMEOUT,
			'trusted_proxies' => [],
		];
	}

	/**
	 * Keeps only recognized, non-null configuration keys.
	 *
	 * @param array<mixed> $values Values from an external source.
	 *
	 * @return array<string, mixed>
	 */
	private static function known( array $values ): array {
		$result = [];
		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( array_key_exists( $key, $values ) && null !== $values[ $key ] && '' !== $values[ $key ] ) {
				$result[ $key ] = $values[ $key ];
			}
		}

		return $result;
	}

	/**
	 * Reads `action_logger_*` keys from the `theme_options` option.
	 *
	 * @return array<string, mixed>
	 */
	private static function from_theme_options(): array {
		$options = get_option( 'theme_options', [] );
		if ( ! is_array( $options ) ) {
			return [];
		}

		$values = [];
		foreach ( self::THEME_OPTIONS as $key => $option_key ) {
			if ( array_key_exists( $option_key, $options ) ) {
				$values[ $key ] = $options[ $option_key ];
			}
		}

		return self::known( $values );
	}

	/**
	 * Reads the `action_logger` section of the active Netivo theme configuration.
	 *
	 * `Theme::get_instance()` cannot be called on the abstract base class, so the
	 * already-created theme instance is looked up instead. It exists once the theme's
	 * functions.php has run, which is why the module resolves its configuration on
	 * `after_setup_theme`.
	 *
	 * @return array<string, mixed>
	 */
	private static function from_theme_config(): array {
		if ( ! class_exists( Theme::class ) ) {
			return [];
		}

		try {
			$instances = ( new ReflectionProperty( Theme::class, 'instances' ) )->getValue();
		} catch ( ReflectionException ) {
			return [];
		}

		if ( ! is_array( $instances ) ) {
			return [];
		}

		foreach ( $instances as $instance ) {
			if ( $instance instanceof Theme ) {
				$section = $instance->get_configuration()['action_logger'] ?? [];

				return is_array( $section ) ? self::known( $section ) : [];
			}
		}

		return [];
	}

	/**
	 * Reads `NETIVO_ACTION_LOGGER_*` constants.
	 *
	 * @return array<string, mixed>
	 */
	private static function from_constants(): array {
		$values = [];
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$values[ $key ] = constant( $constant );
			}
		}

		return self::known( $values );
	}

	/**
	 * Interprets booleans written as strings or numbers in config files.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) || is_int( $value ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}

		return (bool) $value;
	}

	/**
	 * Returns the configured API base URL.
	 *
	 * @return string
	 */
	public function api_url(): string {
		return $this->api_url;
	}

	/**
	 * Returns the configured site token.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return $this->api_key;
	}

	/**
	 * Whether logging is switched on (independent of validity).
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Returns the delivery mode (one of the DELIVERY_* constants).
	 *
	 * @return string
	 */
	public function delivery(): string {
		return $this->delivery;
	}

	/**
	 * Returns the total HTTP timeout in seconds (1-10).
	 *
	 * @return int
	 */
	public function timeout(): int {
		return $this->timeout;
	}

	/**
	 * Returns proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return string[]
	 */
	public function trusted_proxies(): array {
		return $this->trusted_proxies;
	}

	/**
	 * Full URL of the ingestion endpoint.
	 *
	 * @return string
	 */
	public function endpoint(): string {
		$base = rtrim( $this->api_url, '/' );
		if ( ! str_ends_with( $base, '/api/v1' ) ) {
			$base .= '/api/v1';
		}

		return $base . '/ingest';
	}

	/**
	 * Lists configuration problems that prevent delivery.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = [];

		if ( '' === $this->api_url ) {
			$errors[] = 'API URL is not configured (NETIVO_ACTION_LOGGER_API_URL).';
		} elseif ( ! self::is_valid_url( $this->api_url ) ) {
			$errors[] = 'API URL must be an absolute http(s) URL.';
		}

		if ( '' === $this->api_key ) {
			$errors[] = 'API key is not configured (NETIVO_ACTION_LOGGER_API_KEY).';
		} elseif ( 1 !== preg_match( self::KEY_PATTERN, $this->api_key ) ) {
			$errors[] = 'API key must have the format nt_live_ followed by 32 hex characters.';
		}

		return $errors;
	}

	/**
	 * Whether the module should capture and deliver events.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->enabled && [] === $this->errors();
	}

	/**
	 * Site token with the middle part hidden, safe to display.
	 *
	 * @return string
	 */
	public function masked_key(): string {
		if ( strlen( $this->api_key ) <= 16 ) {
			return '' === $this->api_key ? '' : str_repeat( '•', 8 );
		}

		return substr( $this->api_key, 0, 12 ) . '…' . substr( $this->api_key, -4 );
	}

	/**
	 * Checks for an absolute http(s) URL with a host.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool
	 */
	private static function is_valid_url( string $url ): bool {
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, [ 'http', 'https' ], true ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}
}
