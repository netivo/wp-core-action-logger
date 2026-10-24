<?php
/**
 * Minimal WordPress core class stubs for unit tests. Only the members the
 * module reads are declared; behaviour lives in the tests themselves.
 *
 * phpcs:disable
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, string[]> */
		public array $errors = [];

		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function get_error_code(): string {
			return (string) ( array_key_first( $this->errors ) ?? '' );
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();

			return $this->errors[ $code ][0] ?? '';
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		public string $user_login = '';
		public string $user_email = '';
		public string $display_name = '';
		public string $user_nicename = '';
		public string $user_url = '';
		public string $user_pass = '';
		/** @var string[] */
		public array $roles = [];

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}

		public function exists(): bool {
			return $this->ID > 0;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_status = 'publish';
		public string $post_title = '';
		public string $post_name = '';
		public string $post_content = '';
		public string $post_excerpt = '';
		public string $post_author = '0';
		public int $post_parent = 0;
		public string $post_mime_type = '';
		public string $post_password = '';
		public string $post_date = '2026-10-23 10:00:00';
		public int $menu_order = 0;
		public string $comment_status = 'open';

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
		public string $taxonomy = '';
		public int $parent = 0;
		public string $description = '';

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Recording wpdb double: prepare() substitutes placeholders like the real
	 * one, and every executed statement is kept in $queries. Results for reads
	 * are queued per method in $results.
	 */
	class wpdb {
		public string $prefix = 'wp_';
		/** @var string[] */
		public array $queries = [];
		/** @var array<string, array<int, mixed>> */
		public array $results = [ 'get_results' => [], 'get_var' => [], 'query' => [] ];

		public function prepare( string $query, mixed ...$args ): string {
			$i = 0;

			return (string) preg_replace_callback(
				'/%[isd]/',
				static function ( array $match ) use ( &$i, $args ): string {
					$value = $args[ $i++ ] ?? '';

					return match ( $match[0] ) {
						'%i' => '`' . $value . '`',
						'%d' => (string) (int) $value,
						default => "'" . addslashes( (string) $value ) . "'",
					};
				},
				$query
			);
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;

			return array_shift( $this->results['query'] ) ?? 1;
		}

		/**
		 * @return array<int, array<string, mixed>>|null
		 */
		public function get_results( string $query, string $output = 'OBJECT' ): ?array {
			$this->queries[] = $query;

			return array_shift( $this->results['get_results'] ) ?? [];
		}

		public function get_var( string $query ): mixed {
			$this->queries[] = $query;

			return array_shift( $this->results['get_var'] );
		}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Theme' ) ) {
	class WP_Theme {
		/**
		 * @param array<string, string> $headers
		 */
		public function __construct( private string $stylesheet, private array $headers = [] ) {
		}

		public function get( string $header ): string|false {
			return $this->headers[ $header ] ?? false;
		}

		public function get_stylesheet(): string {
			return $this->stylesheet;
		}
	}
}

if ( ! class_exists( 'WP_Upgrader' ) ) {
	class WP_Upgrader {
		/** @var array<string, mixed>|false */
		public $result = [];
	}

	class Plugin_Upgrader extends WP_Upgrader {
		/** @var array<string, mixed> */
		public $new_plugin_data = [];
	}

	class Theme_Upgrader extends WP_Upgrader {
		/** @var array<string, mixed> */
		public $new_theme_data = [];
	}
}
