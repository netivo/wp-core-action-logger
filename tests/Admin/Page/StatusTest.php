<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Admin\Page;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Netivo\Core\Admin\Page;
use Netivo\Module\ActionLogger\Admin\Page\Status;
use Netivo\Module\ActionLogger\Config;
use Netivo\Module\ActionLogger\Module;
use Netivo\Module\ActionLogger\Tests\Support\RedirectCapturingStatusPage;
use Netivo\Module\ActionLogger\Tests\TestCase;
use Netivo\Module\ActionLogger\Transport\Client;
use Netivo\Module\ActionLogger\Transport\DeliveryResult;
use Netivo\Module\ActionLogger\Transport\Queue;
use Netivo\Module\ActionLogger\Transport\QueueWorker;
use Netivo\Module\ActionLogger\Transport\Status as DeliveryStatus;
use ReflectionProperty;

final class StatusTest extends TestCase {
	private const KEY = 'nt_live_0123456789abcdef0123456789abcdef';

	/** @var Queue&MockInterface */
	private Queue $queue;

	/** @var DeliveryStatus&MockInterface */
	private DeliveryStatus $status;

	/** @var Client&MockInterface */
	private Client $client;

	/** @var QueueWorker&MockInterface */
	private QueueWorker $worker;

	/** @var array<string, mixed> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn ( string $path ) => 'https://site.example/wp-admin/' . $path );
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias( fn ( string $key, $value ) => (bool) ( $this->transients[ $key ] = $value ) );
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );

				return true;
			}
		);

		$this->queue  = Mockery::mock( Queue::class );
		$this->status = Mockery::mock( DeliveryStatus::class );
		$this->client = Mockery::mock( Client::class );
		$this->worker = Mockery::mock( QueueWorker::class );
		$this->queue->allows( 'count' )->andReturn( 3 )->byDefault();
		$this->queue->allows( 'oldest' )->andReturn( '2026-10-23 09:00:00' )->byDefault();
		$this->status->allows( 'all' )->andReturn( [] )->byDefault();
		$this->status->allows( 'is_auth_blocked' )->andReturn( false )->byDefault();
	}

	private function page( ?Config $config = null ): RedirectCapturingStatusPage {
		$config = $config ?? Config::from_array( [ 'api_url' => 'https://logger.example', 'api_key' => self::KEY ] );

		return new RedirectCapturingStatusPage( '/views', $config, $this->queue, $this->status, $this->client, $this->worker );
	}

	public function test_registers_as_settings_subpage_with_the_installed_wp_core_property_names(): void {
		Actions\expectAdded( 'admin_menu' )->once();

		$page   = new Status( '/views', Config::from_array( [] ), $this->queue, $this->status, $this->client, $this->worker );
		$modern = property_exists( Page::class, 'menu_slug' );
		$prefix = $modern ? '' : '_';

		// wp-core 1.4 saves on admin_init, 1.2 on init.
		self::assertNotFalse( has_action( $modern ? 'admin_init' : 'init', [ $page, 'do_save' ] ) );

		self::assertSame( 'subpage', ( new ReflectionProperty( Page::class, $prefix . 'type' ) )->getValue( $page ) );
		self::assertSame( 'options-general.php', ( new ReflectionProperty( Page::class, $prefix . 'parent' ) )->getValue( $page ) );
		self::assertSame( Status::SLUG, $page->get_slug() );
		self::assertStringEndsWith( '/admin/pages/action-logger-status.phtml', $page->get_view_file() );
	}

	public function test_reports_connection_state_and_masks_the_key(): void {
		$this->status->allows( 'all' )->andReturn( [ 'last_success_at' => 1700000000 ] );

		$report = $this->page()->report();

		self::assertSame( 'connected', $report['state'] );
		self::assertSame( 'https://logger.example/api/v1/ingest', $report['endpoint'] );
		self::assertSame( 'nt_live_0123…cdef', $report['masked_key'] );
		self::assertSame( 3, $report['backlog'] );
		self::assertSame( Module::VERSION, $report['version'] );
		self::assertTrue( $report['can_send'] );
		self::assertStringNotContainsString( self::KEY, (string) json_encode( $report ) );
	}

	public function test_reports_offline_unauthorized_and_misconfigured_states(): void {
		$this->status->allows( 'all' )->andReturn( [ 'last_success_at' => 100, 'last_error_at' => 200, 'last_error' => 'HTTP 503' ] );
		self::assertSame( 'offline', $this->page()->report()['state'] );

		$this->status->allows( 'is_auth_blocked' )->andReturn( true );
		self::assertSame( 'unauthorized', $this->page()->report()['state'] );

		$report = $this->page( Config::from_array( [] ) )->report();
		self::assertSame( 'misconfigured', $report['state'] );
		self::assertCount( 2, $report['errors'] );
		self::assertFalse( $report['can_send'] );

		self::assertSame( 'disabled', $this->page( Config::from_array( [ 'enabled' => false ] ) )->report()['state'] );
	}

	public function test_ping_sends_a_test_event_and_shows_the_result_once(): void {
		$_POST['operation'] = 'ping';
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( 'save_' . Status::SLUG );
		Functions\when( 'get_bloginfo' )->justReturn( 'Netivo' );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( [ 'ID' => 1, 'user_login' => 'admin' ] ) );
		$this->client->expects( 'send' )
			->once()
			->with( Mockery::on( static fn ( array $events ): bool => 'test_ping' === $events[0]['action'] && 'system' === $events[0]['category'] ) )
			->andReturn( new DeliveryResult( DeliveryResult::DELIVERED, 202 ) );
		$this->status->expects( 'record_success' )->once();

		$page = $this->page();
		$page->save();
		unset( $_POST['operation'] );

		self::assertSame( 'https://site.example/wp-admin/options-general.php?page=' . Status::SLUG, $page->redirected );
		$notice = $page->report()['notice'];
		self::assertSame( 'success', $notice['type'] );
		self::assertStringContainsString( 'Ping delivered', $notice['message'] );
		self::assertNull( $page->report()['notice'] );
	}

	public function test_failed_ping_records_the_failure(): void {
		$_POST['operation'] = 'ping';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Netivo' );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User() );
		$failure = new DeliveryResult( DeliveryResult::UNAUTHORIZED, 401, 'HTTP 401: invalid token' );
		$this->client->expects( 'send' )->andReturn( $failure );
		$this->status->expects( 'record_failure' )->once()->with( $failure );

		$page = $this->page();
		$page->save();
		unset( $_POST['operation'] );

		self::assertSame( 'Ping failed: HTTP 401: invalid token', $page->report()['notice']['message'] );
	}

	public function test_flush_runs_the_worker(): void {
		$_POST['operation'] = 'flush';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->worker->expects( 'drain' )->once()->andReturn( 3 );
		$this->queue->allows( 'count' )->andReturn( 0 );

		$page = $this->page();
		$page->save();
		unset( $_POST['operation'] );

		self::assertSame( 'Delivered 3 queued event(s); 0 remaining.', $page->report()['notice']['message'] );
	}

	public function test_rejects_users_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'died' ) );
		$this->client->expects( 'send' )->never();

		$this->expectExceptionMessage( 'died' );
		$this->page()->save();
	}
}
