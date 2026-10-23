<?php
/**
 * Created by Netivo for wp-core-action-logger
 *
 * @package Netivo\Module\ActionLogger
 */

namespace Netivo\Module\ActionLogger\Listeners;

use DateTimeInterface;
use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Payload\Redactor;
use WC_Coupon;
use WC_Order;
use WC_Order_Refund;
use WC_Product;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class WooCommerceListener
 *
 * Orders (status changes, refunds, deletion), products and variations (with field
 * changes), coupons and WooCommerce settings. Uses WooCommerce hooks rather than
 * post hooks so it works with High-Performance Order Storage (HPOS).
 */
class WooCommerceListener extends AbstractListener {

	/**
	 * Product fields compared on update.
	 */
	private const PRODUCT_FIELDS = [
		'name',
		'status',
		'sku',
		'regular_price',
		'sale_price',
		'manage_stock',
		'stock_quantity',
		'stock_status',
		'catalog_visibility',
		'tax_status',
		'tax_class',
	];

	/**
	 * Coupon fields compared on update.
	 */
	private const COUPON_FIELDS = [
		'code',
		'status',
		'amount',
		'discount_type',
		'date_expires',
		'usage_limit',
		'individual_use',
		'free_shipping',
		'minimum_amount',
		'maximum_amount',
	];

	/**
	 * Order statuses whose arrival is a warning.
	 */
	private const WARNING_STATUSES = [ 'cancelled', 'refunded', 'failed' ];

	/**
	 * Values of array options that are shown in clear text; anything else is masked,
	 * because gateway settings hold credentials under unpredictable keys.
	 */
	private const CLEAR_SETTING_VALUES = [ 'yes', 'no', '' ];

	/**
	 * Pending product/coupon changes captured before save, keyed by "<kind>:<id>".
	 *
	 * @var array<string, array<string, array{old: mixed, new: mixed}>>
	 */
	private array $pending = [];

	/**
	 * WooCommerce option changes collected while the settings screen saves.
	 *
	 * @var array<string, array{old: mixed, new: mixed}>|null
	 */
	private ?array $settings_changes = null;

	/**
	 * Hooks the listener into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->on( 'woocommerce_order_status_changed', 'order_status_changed', 10, 4 );
		$this->on( 'woocommerce_order_refunded', 'order_refunded', 10, 2 );
		$this->on( 'woocommerce_before_delete_order', 'order_deleted', 10, 2 );
		$this->on( 'woocommerce_trash_order', 'order_trashed' );

		$this->on( 'woocommerce_before_product_object_save', 'product_before_save' );
		$this->on( 'woocommerce_new_product', 'product_created', 10, 2 );
		$this->on( 'woocommerce_update_product', 'product_updated', 10, 2 );
		$this->on( 'woocommerce_update_product_variation', 'product_updated', 10, 2 );

		$this->on( 'woocommerce_before_coupon_object_save', 'coupon_before_save' );
		$this->on( 'woocommerce_new_coupon', 'coupon_created', 10, 2 );
		$this->on( 'woocommerce_update_coupon', 'coupon_updated', 10, 2 );

		$this->on( 'trashed_post', 'post_trashed' );
		$this->on( 'before_delete_post', 'post_deleted', 10, 2 );

		$this->on( 'woocommerce_update_options', 'settings_save_started' );
		$this->on( 'updated_option', 'option_updated', 10, 3 );
		$this->on( 'woocommerce_settings_saved', 'settings_saved' );
	}

	/**
	 * Order moved to another status.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $from     Previous status (without `wc-`).
	 * @param string        $to       New status (without `wc-`).
	 * @param WC_Order|null $order    Order.
	 *
	 * @return void
	 */
	public function order_status_changed( int $order_id, string $from, string $to, ?WC_Order $order = null ): void {
		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->log(
			'woocommerce',
			'order_status_changed',
			$this->order_entity( $order ),
			[
				'status' => [
					'old' => $from,
					'new' => $to,
				],
			],
			$this->order_metadata( $order ),
			in_array( $to, self::WARNING_STATUSES, true ) ? 'warning' : 'info'
		);
	}

	/**
	 * Refund created.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 *
	 * @return void
	 */
	public function order_refunded( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$metadata = $this->order_metadata( $order ) + [ 'refund_id' => $refund_id ];
		$refund   = wc_get_order( $refund_id );
		if ( $refund instanceof WC_Order_Refund ) {
			$metadata['refund_amount'] = $refund->get_amount();
			$metadata['refund_reason'] = $refund->get_reason();
		}

		$this->log( 'woocommerce', 'order_refunded', $this->order_entity( $order ), null, $metadata, 'warning' );
	}

	/**
	 * Order about to be permanently deleted.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order (WooCommerce 7.1+).
	 *
	 * @return void
	 */
	public function order_deleted( int $order_id, mixed $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->log( 'woocommerce', 'order_deleted', $this->order_entity( $order ), null, $this->order_metadata( $order ), 'warning' );
		}
	}

	/**
	 * Order moved to the trash.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public function order_trashed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->log( 'woocommerce', 'order_trashed', $this->order_entity( $order ), null, $this->order_metadata( $order ), 'warning' );
		}
	}

	/**
	 * Captures field changes of an existing product before WooCommerce applies them.
	 *
	 * @param WC_Product $product Product being saved.
	 *
	 * @return void
	 */
	public function product_before_save( WC_Product $product ): void {
		if ( $product->get_id() > 0 ) {
			$this->pending[ 'product:' . $product->get_id() ] = $this->pending_changes( $product->get_data(), $product->get_changes(), self::PRODUCT_FIELDS );
		}
	}

	/**
	 * Product created.
	 *
	 * @param int             $product_id Product ID.
	 * @param WC_Product|null $product    Product.
	 *
	 * @return void
	 */
	public function product_created( int $product_id, ?WC_Product $product = null ): void {
		$product = $product ?? wc_get_product( $product_id );
		if ( $product instanceof WC_Product && 'auto-draft' !== $product->get_status() ) {
			$this->log( 'woocommerce', 'product_created', $this->product_entity( $product ), null, $this->product_metadata( $product ) );
		}
	}

	/**
	 * Product or variation updated; logged once per save, only when fields changed.
	 *
	 * @param int             $product_id Product ID.
	 * @param WC_Product|null $product    Product.
	 *
	 * @return void
	 */
	public function product_updated( int $product_id, ?WC_Product $product = null ): void {
		$changes = $this->pending[ 'product:' . $product_id ] ?? [];
		unset( $this->pending[ 'product:' . $product_id ] );
		if ( [] === $changes ) {
			return;
		}

		$product = $product ?? wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( isset( $changes['status'] ) && 'auto-draft' === $changes['status']['old'] ) {
			$this->log( 'woocommerce', 'product_created', $this->product_entity( $product ), null, $this->product_metadata( $product ) );

			return;
		}

		$action = 'variation' === $product->get_type() ? 'product_variation_updated' : 'product_updated';
		$this->log( 'woocommerce', $action, $this->product_entity( $product ), $changes, $this->product_metadata( $product ) );
	}

	/**
	 * Captures field changes of an existing coupon before WooCommerce applies them.
	 *
	 * @param WC_Coupon $coupon Coupon being saved.
	 *
	 * @return void
	 */
	public function coupon_before_save( WC_Coupon $coupon ): void {
		if ( $coupon->get_id() > 0 ) {
			$this->pending[ 'coupon:' . $coupon->get_id() ] = $this->pending_changes( $coupon->get_data(), $coupon->get_changes(), self::COUPON_FIELDS );
		}
	}

	/**
	 * Coupon created.
	 *
	 * @param int            $coupon_id Coupon ID.
	 * @param WC_Coupon|null $coupon    Coupon.
	 *
	 * @return void
	 */
	public function coupon_created( int $coupon_id, ?WC_Coupon $coupon = null ): void {
		$coupon = $coupon ?? new WC_Coupon( $coupon_id );
		$this->log( 'woocommerce', 'coupon_created', $this->coupon_entity( $coupon ), null, $this->coupon_metadata( $coupon ) );
	}

	/**
	 * Coupon updated; logged only when fields changed.
	 *
	 * @param int            $coupon_id Coupon ID.
	 * @param WC_Coupon|null $coupon    Coupon.
	 *
	 * @return void
	 */
	public function coupon_updated( int $coupon_id, ?WC_Coupon $coupon = null ): void {
		$changes = $this->pending[ 'coupon:' . $coupon_id ] ?? [];
		unset( $this->pending[ 'coupon:' . $coupon_id ] );
		if ( [] === $changes ) {
			return;
		}

		$coupon = $coupon ?? new WC_Coupon( $coupon_id );
		$this->log( 'woocommerce', 'coupon_updated', $this->coupon_entity( $coupon ), $changes, $this->coupon_metadata( $coupon ) );
	}

	/**
	 * Product or coupon moved to the trash (the content listener skips these types).
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function post_trashed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->log_post_removal( $post, 'trashed' );
		}
	}

	/**
	 * Product or coupon about to be permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 *
	 * @return void
	 */
	public function post_deleted( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			$this->log_post_removal( $post, 'deleted' );
		}
	}

	/**
	 * The WooCommerce settings screen started saving.
	 *
	 * @return void
	 */
	public function settings_save_started(): void {
		$this->settings_changes = [];
	}

	/**
	 * Collects WooCommerce option changes made by the settings screen.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	public function option_updated( string $option, mixed $old_value, mixed $value ): void {
		if ( null === $this->settings_changes || ! str_starts_with( $option, 'woocommerce_' ) ) {
			return;
		}

		if ( is_array( $old_value ) || is_array( $value ) ) {
			$old = is_array( $old_value ) ? $old_value : [];
			$new = is_array( $value ) ? $value : [];
			foreach ( EventPayload::diff( $old, $new ) as $key => $change ) {
				$this->settings_changes[ $option . '.' . $key ] = [
					'old' => $this->clear_or_masked( $change['old'] ),
					'new' => $this->clear_or_masked( $change['new'] ),
				];
			}

			return;
		}

		$this->settings_changes[ $option ] = [
			'old' => $old_value,
			'new' => $value,
		];
	}

	/**
	 * The WooCommerce settings screen finished saving.
	 *
	 * @return void
	 */
	public function settings_saved(): void {
		$changes                = $this->settings_changes ?? [];
		$this->settings_changes = null;
		if ( [] === $changes ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only context; WooCommerce verified the save request.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable

		$this->log(
			'woocommerce',
			'settings_updated',
			[
				'type'  => 'wc_settings',
				'title' => '' === $section ? $tab : $tab . '/' . $section,
				'slug'  => $tab,
			],
			$changes,
			array_filter( [ 'section' => $section ] ),
			in_array( $tab, [ 'checkout', 'advanced' ], true ) ? 'warning' : 'info'
		);
	}

	/**
	 * Diffs tracked fields between stored data and pending changes.
	 *
	 * @param array<string, mixed> $data    Stored data.
	 * @param array<string, mixed> $changes Pending changes.
	 * @param string[]             $fields  Tracked fields.
	 *
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function pending_changes( array $data, array $changes, array $fields ): array {
		$fields = array_values( array_intersect( $fields, array_keys( $changes ) ) );
		// Dates (WC_DateTime) are compared and sent as ISO 8601 strings.
		$scalar = static fn ( array $values ): array => array_map(
			static fn ( $value ) => $value instanceof DateTimeInterface ? $value->format( DATE_ATOM ) : $value,
			$values
		);

		return EventPayload::diff( $scalar( $data ), $scalar( $changes + $data ), $fields );
	}

	/**
	 * Logs trashing or deletion of a product, variation or coupon.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $verb `trashed` or `deleted`.
	 *
	 * @return void
	 */
	private function log_post_removal( WP_Post $post, string $verb ): void {
		$kinds = [
			'product'           => 'product',
			'product_variation' => 'product_variation',
			'shop_coupon'       => 'coupon',
		];
		if ( ! isset( $kinds[ $post->post_type ] ) ) {
			return;
		}

		$this->log(
			'woocommerce',
			$kinds[ $post->post_type ] . '_' . $verb,
			[
				'type'  => $post->post_type,
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
				'slug'  => $post->post_name,
			],
			null,
			[],
			'warning'
		);
	}

	/**
	 * Shows yes/no/empty and numeric values, masks everything else.
	 *
	 * @param mixed $value Setting value.
	 *
	 * @return mixed
	 */
	private function clear_or_masked( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && ( in_array( $value, self::CLEAR_SETTING_VALUES, true ) || is_numeric( $value ) ) ) {
			return $value;
		}

		return Redactor::REDACTED;
	}

	/**
	 * Entity describing an order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<string, mixed>
	 */
	private function order_entity( WC_Order $order ): array {
		return [
			'type'  => 'shop_order',
			'id'    => $order->get_id(),
			'title' => '#' . $order->get_order_number(),
		];
	}

	/**
	 * Non-personal order context.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<string, mixed>
	 */
	private function order_metadata( WC_Order $order ): array {
		return [
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'payment_method' => $order->get_payment_method(),
			'customer_id'    => $order->get_customer_id(),
		];
	}

	/**
	 * Entity describing a product or variation.
	 *
	 * @param WC_Product $product Product.
	 *
	 * @return array<string, mixed>
	 */
	private function product_entity( WC_Product $product ): array {
		return [
			'type'    => 'variation' === $product->get_type() ? 'product_variation' : 'product',
			'subtype' => $product->get_type(),
			'id'      => $product->get_id(),
			'title'   => $product->get_name(),
			'slug'    => $product->get_slug(),
		];
	}

	/**
	 * Product context.
	 *
	 * @param WC_Product $product Product.
	 *
	 * @return array<string, mixed>
	 */
	private function product_metadata( WC_Product $product ): array {
		return array_filter(
			[
				'sku'       => $product->get_sku(),
				'parent_id' => $product->get_parent_id(),
			]
		);
	}

	/**
	 * Entity describing a coupon.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 *
	 * @return array<string, mixed>
	 */
	private function coupon_entity( WC_Coupon $coupon ): array {
		return [
			'type'  => 'shop_coupon',
			'id'    => $coupon->get_id(),
			'title' => $coupon->get_code(),
		];
	}

	/**
	 * Coupon context.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 *
	 * @return array<string, mixed>
	 */
	private function coupon_metadata( WC_Coupon $coupon ): array {
		return [
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
		];
	}
}
