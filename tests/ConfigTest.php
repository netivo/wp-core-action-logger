<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Netivo\Core\Theme;
use Netivo\Module\ActionLogger\Config;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;

final class ConfigTest extends TestCase {
	private const KEY = 'nt_live_0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( [] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Theme::class, 'instances' ) )->setValue( null, [] );
		parent::tearDown();
	}

	public function test_is_inactive_without_credentials(): void {
		$config = Config::load();

		self::assertTrue( $config->is_enabled() );
		self::assertFalse( $config->is_active() );
		self::assertCount( 2, $config->errors() );
		self::assertSame( Config::DELIVERY_SHUTDOWN, $config->delivery() );
		self::assertSame( Config::DEFAULT_TIMEOUT, $config->timeout() );
	}

	public function test_filter_provides_lowest_priority_values(): void {
		Filters\expectApplied( 'netivo_action_logger_config' )
			->once()
			->andReturnUsing(
				static function ( array $config ): array {
					$config['api_url'] = 'https://filter.example';
					$config['api_key'] = self::KEY;

					return $config;
				}
			);
		Functions\when( 'get_option' )->justReturn( [ 'action_logger_api_url' => 'https://options.example' ] );

		$config = Config::load();

		self::assertSame( 'https://options.example', $config->api_url() );
		self::assertSame( self::KEY, $config->api_key() );
		self::assertTrue( $config->is_active() );
	}

	public function test_theme_configuration_overrides_theme_options(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'action_logger_api_url' => 'https://options.example',
				'action_logger_api_key' => self::KEY,
			]
		);
		$this->register_theme(
			[
				'action_logger' => [
					'api_url'  => 'https://theme.example',
					'delivery' => 'queue',
					'enabled'  => 'yes',
				],
			]
		);

		$config = Config::load();

		self::assertSame( 'https://theme.example', $config->api_url() );
		self::assertSame( self::KEY, $config->api_key() );
		self::assertSame( Config::DELIVERY_QUEUE, $config->delivery() );
		self::assertTrue( $config->is_active() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_constants_have_the_highest_priority(): void {
		define( 'NETIVO_ACTION_LOGGER_API_URL', 'https://constant.example' );
		define( 'NETIVO_ACTION_LOGGER_API_KEY', self::KEY );
		define( 'NETIVO_ACTION_LOGGER_ENABLED', false );
		define( 'NETIVO_ACTION_LOGGER_TRUSTED_PROXIES', '10.0.0.1, 10.0.0.2' );
		$this->register_theme( [ 'action_logger' => [ 'api_url' => 'https://theme.example' ] ] );

		$config = Config::load();

		self::assertSame( 'https://constant.example', $config->api_url() );
		self::assertFalse( $config->is_enabled() );
		self::assertFalse( $config->is_active() );
		self::assertSame( [ '10.0.0.1', '10.0.0.2' ], $config->trusted_proxies() );
	}

	public function test_validates_url_and_key_format(): void {
		$config = Config::from_array( [ 'api_url' => 'ftp://logger.example', 'api_key' => 'nt_live_short' ] );

		self::assertSame(
			[
				'API URL must be an absolute http(s) URL.',
				'API key must have the format nt_live_ followed by 32 hex characters.',
			],
			$config->errors()
		);
	}

	public function test_normalizes_delivery_and_timeout(): void {
		$config = Config::from_array( [ 'delivery' => 'carrier-pigeon', 'timeout' => '60' ] );

		self::assertSame( Config::DELIVERY_SHUTDOWN, $config->delivery() );
		self::assertSame( 10, $config->timeout() );
	}

	public function test_builds_endpoint_from_origin_or_api_base(): void {
		self::assertSame(
			'https://logger.example/api/v1/ingest',
			Config::from_array( [ 'api_url' => 'https://logger.example/' ] )->endpoint()
		);
		self::assertSame(
			'https://logger.example/api/v1/ingest',
			Config::from_array( [ 'api_url' => 'https://logger.example/api/v1' ] )->endpoint()
		);
	}

	public function test_masks_the_api_key(): void {
		self::assertSame( 'nt_live_0123…cdef', Config::from_array( [ 'api_key' => self::KEY ] )->masked_key() );
		self::assertSame( '', Config::from_array( [] )->masked_key() );
	}

	/**
	 * Registers a fake Netivo theme instance exposing the given configuration.
	 *
	 * @param array<string, mixed> $configuration Theme configuration.
	 */
	private function register_theme( array $configuration ): void {
		$theme = new class( $configuration ) extends Theme {
			/**
			 * @param array<string, mixed> $configuration
			 */
			public function __construct( array $configuration ) {
				$this->configuration = $configuration;
			}

			protected function init(): void {
			}
		};

		( new ReflectionProperty( Theme::class, 'instances' ) )->setValue( null, [ $theme::class => $theme ] );
	}
}
