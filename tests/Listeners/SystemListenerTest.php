<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Listeners\SystemListener;
use Plugin_Upgrader;
use Theme_Upgrader;
use WP_Theme;
use WP_Upgrader;

final class SystemListenerTest extends ListenerTestCase {
	/** @var array<string, array<string, string>> */
	private array $plugins = [
		'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '9.4.0' ],
	];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.8.3';
		Functions\when( 'get_plugins' )->alias( fn () => $this->plugins );
		Functions\when( 'get_plugin_data' )->justReturn( [ 'Name' => 'WooCommerce', 'Version' => '9.5.1' ] );
		$this->listen( SystemListener::class );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	public function test_logs_plugin_activation_with_name_and_version(): void {
		$this->fire( 'activated_plugin', 'woocommerce/woocommerce.php', false );
		$this->fire( 'deactivated_plugin', 'hello.php', true );

		self::assertSame( [ 'plugin_activated', 'plugin_deactivated' ], $this->sink->actions() );
		$first = $this->sink->events[0]->to_array();
		self::assertSame( [ 'type' => 'plugin', 'title' => 'WooCommerce', 'slug' => 'woocommerce/woocommerce.php' ], $first['entity'] );
		self::assertSame( 'warning', $first['severity'] );
		self::assertSame( [ 'network_wide' => false, 'version' => '9.4.0', 'context' => 'admin' ], $first['metadata'] );
		self::assertSame( 'hello', $this->last()['entity']['title'] );
		self::assertTrue( $this->last()['metadata']['network_wide'] );
	}

	public function test_logs_plugin_deletion_with_details_captured_beforehand(): void {
		$this->fire( 'delete_plugin', 'woocommerce/woocommerce.php' );
		$this->plugins = [];
		$this->fire( 'deleted_plugin', 'woocommerce/woocommerce.php', true );
		$this->fire( 'delete_plugin', 'akismet/akismet.php' );
		$this->fire( 'deleted_plugin', 'akismet/akismet.php', false );

		self::assertSame( [ 'plugin_deleted' ], $this->sink->actions() );
		self::assertSame( 'WooCommerce', $this->last()['entity']['title'] );
		self::assertSame( '9.4.0', $this->last()['metadata']['version'] );
	}

	public function test_logs_theme_switch_and_deletion(): void {
		$this->fire( 'switch_theme', 'Netivo', new WP_Theme( 'netivo-theme', [ 'Name' => 'Netivo' ] ), new WP_Theme( 'twentytwentyfive' ) );
		$this->fire( 'deleted_theme', 'twentytwentyfive', true );

		self::assertSame( [ 'theme_switched', 'theme_deleted' ], $this->sink->actions() );
		self::assertSame( [ 'stylesheet' => [ 'old' => 'twentytwentyfive', 'new' => 'netivo-theme' ] ], $this->sink->events[0]->changes() );
	}

	public function test_logs_plugin_update_with_old_and_new_version(): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/nt-al-plugins' );
		}
		if ( ! is_file( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) {
			mkdir( WP_PLUGIN_DIR . '/woocommerce', 0777, true );
			file_put_contents( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php', '<?php' );
		}

		$this->fire( 'upgrader_pre_install', true, [ 'plugin' => 'woocommerce/woocommerce.php' ] );
		$this->fire( 'upgrader_process_complete', new WP_Upgrader(), [ 'type' => 'plugin', 'action' => 'update', 'plugins' => [ 'woocommerce/woocommerce.php' ] ] );

		self::assertSame( 'plugin_updated', $this->last()['action'] );
		self::assertSame( [ 'version' => [ 'old' => '9.4.0', 'new' => '9.5.1' ] ], $this->last()['changes'] );
	}

	public function test_upgrader_pre_install_filter_returns_value_unchanged(): void {
		self::assertTrue( $this->fire( 'upgrader_pre_install', true, [ 'plugin' => 'woocommerce/woocommerce.php' ] ) );
	}

	public function test_logs_theme_update_and_installs(): void {
		Functions\when( 'wp_get_theme' )->alias( static fn ( string $s ) => new WP_Theme( $s, [ 'Name' => 'Netivo', 'Version' => '2.0.0' ] ) );

		$this->fire( 'upgrader_process_complete', new WP_Upgrader(), [ 'type' => 'theme', 'action' => 'update', 'theme' => 'netivo-theme' ] );

		$plugin_upgrader                  = new Plugin_Upgrader();
		$plugin_upgrader->new_plugin_data = [ 'Name' => 'Yoast SEO', 'Version' => '24.0' ];
		$plugin_upgrader->result          = [ 'destination_name' => 'wordpress-seo' ];
		$this->fire( 'upgrader_process_complete', $plugin_upgrader, [ 'type' => 'plugin', 'action' => 'install' ] );

		$theme_upgrader         = new Theme_Upgrader();
		$theme_upgrader->result = [ 'destination_name' => 'astra' ];
		$this->fire( 'upgrader_process_complete', $theme_upgrader, [ 'type' => 'theme', 'action' => 'install' ] );

		self::assertSame( [ 'theme_updated', 'plugin_installed', 'theme_installed' ], $this->sink->actions() );
		self::assertSame( [ 'version' => [ 'old' => null, 'new' => '2.0.0' ] ], $this->sink->events[0]->changes() );
		self::assertSame( [ 'type' => 'plugin', 'title' => 'Yoast SEO', 'slug' => 'wordpress-seo' ], $this->sink->events[1]->entity() );
		self::assertSame( 'astra', $this->last()['entity']['title'] );
	}

	public function test_ignores_translation_and_core_upgrader_events(): void {
		$this->fire( 'upgrader_process_complete', new WP_Upgrader(), [ 'type' => 'translation', 'action' => 'update' ] );
		$this->fire( 'upgrader_process_complete', new WP_Upgrader(), [ 'type' => 'core', 'action' => 'update' ] );

		self::assertSame( [], $this->sink->events );
	}

	public function test_logs_core_update_with_version_from_request_start(): void {
		$GLOBALS['wp_version'] = '6.9';

		$this->fire( '_core_updated_successfully', '6.9' );

		self::assertSame( 'core_updated', $this->last()['action'] );
		self::assertSame( [ 'version' => [ 'old' => '6.8.3', 'new' => '6.9' ] ], $this->last()['changes'] );
	}
}
