<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Listeners;

use Brain\Monkey\Functions;
use Netivo\Module\ActionLogger\Listeners\WooCommerceListener;
use WC_Coupon;
use WC_Order;
use WC_Order_Refund;
use WC_Product;
use WP_Post;

final class WooCommerceListenerTest extends ListenerTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->listen( WooCommerceListener::class );
	}

	private static function order(): WC_Order {
		return new WC_Order( 501, [ 'total' => '249.00', 'currency' => 'PLN', 'payment_method' => 'przelewy24', 'customer_id' => 33 ] );
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private static function product( array $changes = [], string $type = 'simple' ): WC_Product {
		return new WC_Product(
			1024,
			[
				'type'           => $type,
				'name'           => 'Valve X-200',
				'slug'           => 'valve-x-200',
				'status'         => 'publish',
				'sku'            => 'VX-200',
				'regular_price'  => '199.00',
				'stock_quantity' => 12,
			],
			$changes
		);
	}

	public function test_logs_order_status_change_without_personal_data(): void {
		$this->fire( 'woocommerce_order_status_changed', 501, 'pending', 'processing', self::order() );

		$event = $this->last();
		self::assertSame( 'woocommerce', $event['category'] );
		self::assertSame( 'order_status_changed', $event['action'] );
		self::assertSame( 'info', $event['severity'] );
		self::assertSame( [ 'type' => 'shop_order', 'id' => 501, 'title' => '#501' ], $event['entity'] );
		self::assertSame( [ 'status' => [ 'old' => 'pending', 'new' => 'processing' ] ], $event['changes'] );
		self::assertSame( [ 'total' => '249.00', 'currency' => 'PLN', 'payment_method' => 'przelewy24', 'customer_id' => 33, 'context' => 'admin' ], $event['metadata'] );
	}

	public function test_cancellation_refund_and_removal_are_warnings(): void {
		$refund = new WC_Order_Refund( 502, [ 'amount' => '49.00', 'reason' => 'Damaged' ] );
		Functions\when( 'wc_get_order' )->alias( static fn ( int $id ) => 502 === $id ? $refund : self::order() );

		$this->fire( 'woocommerce_order_status_changed', 501, 'processing', 'cancelled', self::order() );
		$this->fire( 'woocommerce_order_refunded', 501, 502 );
		$this->fire( 'woocommerce_trash_order', 501 );
		$this->fire( 'woocommerce_before_delete_order', 501, self::order() );

		self::assertSame( [ 'order_status_changed', 'order_refunded', 'order_trashed', 'order_deleted' ], $this->sink->actions() );
		foreach ( $this->sink->events as $event ) {
			self::assertSame( 'warning', $event->severity() );
		}
		self::assertSame( '49.00', $this->sink->events[1]->metadata()['refund_amount'] );
	}

	public function test_logs_product_field_changes_once_per_save(): void {
		$product = self::product( [ 'regular_price' => '179.00', 'stock_quantity' => 10, 'description' => 'ignored' ] );

		$this->fire( 'woocommerce_before_product_object_save', $product );
		$this->fire( 'woocommerce_update_product', 1024, $product );
		$this->fire( 'woocommerce_update_product', 1024, $product );

		self::assertCount( 1, $this->sink->events );
		$event = $this->last();
		self::assertSame( 'product_updated', $event['action'] );
		self::assertSame( [ 'type' => 'product', 'subtype' => 'simple', 'id' => 1024, 'title' => 'Valve X-200', 'slug' => 'valve-x-200' ], $event['entity'] );
		self::assertSame(
			[
				'regular_price'  => [ 'old' => '199.00', 'new' => '179.00' ],
				'stock_quantity' => [ 'old' => 12, 'new' => 10 ],
			],
			$event['changes']
		);
		self::assertSame( 'VX-200', $event['metadata']['sku'] );
	}

	public function test_skips_saves_without_tracked_changes(): void {
		$product = self::product( [ 'description' => 'New copy' ] );

		$this->fire( 'woocommerce_before_product_object_save', $product );
		$this->fire( 'woocommerce_update_product', 1024, $product );

		self::assertSame( [], $this->sink->events );
	}

	public function test_first_publish_of_auto_draft_product_is_creation(): void {
		$product = new WC_Product( 77, [ 'name' => 'New', 'status' => 'auto-draft' ], [ 'status' => 'publish', 'name' => 'Pump' ] );

		$this->fire( 'woocommerce_new_product', 77, new WC_Product( 77, [ 'status' => 'auto-draft' ] ) );
		$this->fire( 'woocommerce_before_product_object_save', $product );
		$this->fire( 'woocommerce_update_product', 77, $product );

		self::assertSame( [ 'product_created' ], $this->sink->actions() );
	}

	public function test_names_variation_updates(): void {
		$variation = self::product( [ 'stock_status' => 'outofstock' ], 'variation' );

		$this->fire( 'woocommerce_before_product_object_save', $variation );
		$this->fire( 'woocommerce_update_product_variation', 1024, $variation );

		self::assertSame( 'product_variation_updated', $this->last()['action'] );
		self::assertSame( 'product_variation', $this->last()['entity']['type'] );
	}

	public function test_logs_coupon_lifecycle(): void {
		$coupon = new WC_Coupon( 60, [ 'code' => 'spring20', 'amount' => '20', 'discount_type' => 'percent' ], [ 'amount' => '25' ] );
		Functions\when( 'get_post' )->justReturn( new WP_Post( [ 'ID' => 60, 'post_type' => 'shop_coupon', 'post_title' => 'spring20' ] ) );

		$this->fire( 'woocommerce_new_coupon', 60, new WC_Coupon( 60, [ 'code' => 'spring20', 'amount' => '20', 'discount_type' => 'percent' ] ) );
		$this->fire( 'woocommerce_before_coupon_object_save', $coupon );
		$this->fire( 'woocommerce_update_coupon', 60, $coupon );
		$this->fire( 'trashed_post', 60 );

		self::assertSame( [ 'coupon_created', 'coupon_updated', 'coupon_trashed' ], $this->sink->actions() );
		self::assertSame( [ 'amount' => [ 'old' => '20', 'new' => '25' ] ], $this->sink->events[1]->changes() );
		self::assertSame( 'spring20', $this->sink->events[0]->entity()['title'] );
	}

	public function test_logs_product_deletion_but_not_regular_posts(): void {
		$this->fire( 'before_delete_post', 1024, new WP_Post( [ 'ID' => 1024, 'post_type' => 'product', 'post_title' => 'Valve' ] ) );
		$this->fire( 'before_delete_post', 5, new WP_Post( [ 'ID' => 5, 'post_type' => 'post' ] ) );

		self::assertSame( [ 'product_deleted' ], $this->sink->actions() );
		self::assertSame( 'warning', $this->last()['severity'] );
	}

	public function test_groups_settings_changes_and_masks_gateway_credentials(): void {
		$_GET['tab']     = 'checkout';
		$_GET['section'] = 'stripe';

		$this->fire( 'woocommerce_update_options' );
		$this->fire( 'updated_option', 'woocommerce_currency', 'PLN', 'EUR' );
		$this->fire(
			'updated_option',
			'woocommerce_stripe_settings',
			[ 'enabled' => 'no', 'merchant_signature' => 'old-sig', 'title' => 'Card' ],
			[ 'enabled' => 'yes', 'merchant_signature' => 'new-sig', 'title' => 'Card' ]
		);
		$this->fire( 'updated_option', 'blogname', 'a', 'b' );
		$this->fire( 'woocommerce_settings_saved' );
		unset( $_GET['tab'], $_GET['section'] );

		$event = $this->last();
		self::assertSame( 'settings_updated', $event['action'] );
		self::assertSame( 'warning', $event['severity'] );
		self::assertSame( 'checkout/stripe', $event['entity']['title'] );
		self::assertSame(
			[
				'woocommerce_currency'                           => [ 'old' => 'PLN', 'new' => 'EUR' ],
				'woocommerce_stripe_settings.enabled'            => [ 'old' => 'no', 'new' => 'yes' ],
				'woocommerce_stripe_settings.merchant_signature' => [ 'old' => '[REDACTED]', 'new' => '[REDACTED]' ],
			],
			$event['changes']
		);
		self::assertStringNotContainsString( 'new-sig', (string) json_encode( $event ) );
	}

	public function test_ignores_option_updates_outside_the_settings_screen(): void {
		$this->fire( 'updated_option', 'woocommerce_currency', 'PLN', 'EUR' );
		$this->fire( 'woocommerce_settings_saved' );

		self::assertSame( [], $this->sink->events );
	}
}
