<?php
/**
 * Minimal WooCommerce class stubs for unit tests: data objects keep their stored
 * `data` and pending `changes` like WC_Data does.
 *
 * phpcs:disable
 */

if ( ! class_exists( 'WC_Data' ) ) {
	abstract class WC_Data {
		protected int $id = 0;
		/** @var array<string, mixed> */
		protected array $data = [];
		/** @var array<string, mixed> */
		protected array $changes = [];

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $changes
		 */
		public function __construct( int $id = 0, array $data = [], array $changes = [] ) {
			$this->id      = $id;
			$this->data    = $data;
			$this->changes = $changes;
		}

		public function get_id(): int {
			return $this->id;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_data(): array {
			return [ 'id' => $this->id ] + $this->data;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_changes(): array {
			return $this->changes;
		}

		protected function prop( string $key ): mixed {
			return array_key_exists( $key, $this->changes ) ? $this->changes[ $key ] : ( $this->data[ $key ] ?? '' );
		}
	}

	class WC_Product extends WC_Data {
		public function get_name(): string {
			return (string) $this->prop( 'name' );
		}

		public function get_slug(): string {
			return (string) $this->prop( 'slug' );
		}

		public function get_sku(): string {
			return (string) $this->prop( 'sku' );
		}

		public function get_status(): string {
			return (string) $this->prop( 'status' );
		}

		public function get_type(): string {
			return (string) ( $this->data['type'] ?? 'simple' );
		}

		public function get_parent_id(): int {
			return (int) ( $this->data['parent_id'] ?? 0 );
		}
	}

	class WC_Coupon extends WC_Data {
		public function get_code(): string {
			return (string) $this->prop( 'code' );
		}

		public function get_discount_type(): string {
			return (string) $this->prop( 'discount_type' );
		}

		public function get_amount(): string {
			return (string) $this->prop( 'amount' );
		}
	}

	class WC_Abstract_Order extends WC_Data {
		public function get_total(): string {
			return (string) $this->prop( 'total' );
		}

		public function get_currency(): string {
			return (string) $this->prop( 'currency' );
		}
	}

	class WC_Order extends WC_Abstract_Order {
		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_payment_method(): string {
			return (string) $this->prop( 'payment_method' );
		}

		public function get_customer_id(): int {
			return (int) $this->prop( 'customer_id' );
		}
	}

	class WC_Order_Refund extends WC_Abstract_Order {
		public function get_amount(): string {
			return (string) $this->prop( 'amount' );
		}

		public function get_reason(): string {
			return (string) $this->prop( 'reason' );
		}
	}
}
