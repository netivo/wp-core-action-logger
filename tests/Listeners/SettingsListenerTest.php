<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Filters;
use Netivo\Module\ActionLogger\Listeners\SettingsListener;

final class SettingsListenerTest extends ListenerTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->listen( SettingsListener::class );
	}

	public function test_logs_tracked_option_with_old_and_new_value(): void {
		$this->fire( 'updated_option', 'siteurl', 'https://old.example', 'https://new.example' );

		$event = $this->last();
		self::assertSame( 'settings', $event['category'] );
		self::assertSame( 'option_updated', $event['action'] );
		self::assertSame( 'critical', $event['severity'] );
		self::assertSame( [ 'type' => 'option', 'title' => 'siteurl', 'slug' => 'siteurl' ], $event['entity'] );
		self::assertSame( [ 'siteurl' => [ 'old' => 'https://old.example', 'new' => 'https://new.example' ] ], $event['changes'] );
	}

	public function test_permalink_changes_have_their_own_action(): void {
		$this->fire( 'updated_option', 'permalink_structure', '/?p=%post_id%', '/%postname%/' );

		self::assertSame( 'permalink_structure_changed', $this->last()['action'] );
		self::assertSame( 'warning', $this->last()['severity'] );
	}

	public function test_administrator_default_role_is_critical(): void {
		$this->fire( 'updated_option', 'default_role', 'subscriber', 'administrator' );

		self::assertSame( 'critical', $this->last()['severity'] );
	}

	public function test_ignores_untracked_options_transients_and_own_status(): void {
		$this->fire( 'updated_option', '_transient_feed_123', 'a', 'b' );
		$this->fire( 'updated_option', 'cron', [], [ 1 ] );
		$this->fire( 'updated_option', 'nt_action_logger_status', [], [ 'last_success_at' => 1 ] );

		self::assertSame( [], $this->sink->events );
	}

	public function test_tracked_options_are_filterable(): void {
		Filters\expectApplied( 'netivo_action_logger_tracked_options' )->andReturnUsing(
			static fn ( array $options ): array => [ 'woocommerce_currency' => 'warning' ] + $options
		);

		$this->fire( 'updated_option', 'woocommerce_currency', 'PLN', 'EUR' );

		self::assertSame( 'warning', $this->last()['severity'] );
	}
}
