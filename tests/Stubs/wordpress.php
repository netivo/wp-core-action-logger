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
