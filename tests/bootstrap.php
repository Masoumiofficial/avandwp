<?php
/**
 * Minimal WordPress shim for the AvandWP unit suite.
 *
 * The unit tests run inside a PHP 8.2 (WebAssembly) interpreter with no
 * WordPress installation available, so the handful of WordPress functions the
 * pure-logic classes depend on are reproduced here with faithful semantics.
 *
 * Scope and honesty:
 *   - These shims are deliberately small and are NOT a WordPress emulator. A
 *     test that passes here proves the *logic under test* is correct; it does
 *     not prove the plugin works inside WordPress. Integration testing against
 *     a real installation is still required and is tracked in
 *     docs/testing-report.md.
 *   - Functions are only defined when WordPress has not already provided them,
 *     so the same file remains usable if this suite is ever executed with a real
 *     WordPress test library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fake-wordpress/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Output-format constants the $wpdb double accepts. They are defined here
// because the double's method signatures reference them as default values, and
// an undefined constant in a default value is a fatal error at call time.
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Faithful reimplementation of wp_strip_all_tags().
	 *
	 * @param string $text           Input.
	 * @param bool   $remove_breaks  Whether to collapse whitespace.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( (string) $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL, tolerating a missing scheme like WordPress does.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component constant.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter shim that returns the value unchanged.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		// Extra arguments are accepted for signature compatibility.
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter (no-op shim).
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 * @return true
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Locale-aware number formatting shim (Western digits, thousands separator).
	 *
	 * @param float|int $number   Number.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a key.
	 *
	 * @param string $key Input.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize a single-line text field.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape for HTML output.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode shim.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Random integer shim.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( (int) $min, (int) $max );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Password generator shim.
	 *
	 * @param int  $length              Length.
	 * @param bool $special_chars       Include special characters.
	 * @param bool $extra_special_chars Include extra characters.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < (int) $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}

/* -------------------------------------------------------------------------
 * WordPress core classes.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in with the same public surface the plugin uses.
	 */
	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/* -------------------------------------------------------------------------
 * Test-controlled WordPress state (capability and post fixtures).
 * ---------------------------------------------------------------------- */

/**
 * Fixture store for posts and the current user's capability set.
 */
class AvandWP_Test_WP_State {

	/** @var array<int, object> */
	public static $posts = array();

	/** @var array<int, string> */
	public static $caps = array();

	/** @var bool */
	public static $logged_in = true;

	/** @var array<string, mixed> Options written through update_option(). */
	public static $options = array();

	/** @var array<string, mixed> Transients. */
	public static $transients = array();

	/** @var array<string, int> Recorded transient TTLs. */
	public static $transient_ttl = array();

	/** @var array<int, array<string, mixed>> Post meta keyed by post ID. */
	public static $post_meta = array();

	/** @var string Site URL used by home_url()/admin_url()/is_ssl(). */
	public static $home_url = 'https://example.com';

	/** @var array<int, int> Recorded HTTP requests (verifies that no network call is attempted). */
	public static $http_requests = array();

	/** @var array<int, array<string, mixed>> Queries recorded by the $wpdb double. */
	public static $db_queries = array();

	/**
	 * Canned values returned by the $wpdb double, consumed in order.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public static $db_queue = array();

	/**
	 * Reset all fixtures.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$posts         = array();
		self::$caps          = array();
		self::$logged_in     = true;
		self::$options       = array();
		self::$transients    = array();
		self::$transient_ttl = array();
		self::$post_meta     = array();
		self::$home_url      = 'https://example.com';

		self::$http_requests = array();
		self::$db_queries    = array();
		self::$db_queue      = array();

		AvandWP_Test_HTTP::$queue = array();
	}

	/**
	 * Queue a canned return value for the next $wpdb call of a given method.
	 *
	 * @param string $method Method name: get_var, get_row, get_results, insert, update, delete, query.
	 * @param mixed  $value  Value to return.
	 * @return void
	 */
	public static function queue_db( $method, $value ) {
		self::$db_queue[ $method ][] = $value;
	}

	/**
	 * Recorded queries for one method.
	 *
	 * @param string $method Method name.
	 * @return array<int, array<string, mixed>>
	 */
	public static function queries( $method ) {
		return array_values(
			array_filter(
				self::$db_queries,
				static function ( $entry ) use ( $method ) {
					return $entry['method'] === $method;
				}
			)
		);
	}

	/**
	 * Last recorded query for a method.
	 *
	 * @param string $method Method name.
	 * @return array<string, mixed>|null
	 */
	public static function last_query( $method ) {
		$all = self::queries( $method );

		return empty( $all ) ? null : $all[ count( $all ) - 1 ];
	}

	/**
	 * Register a post fixture.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_type Post type.
	 * @param int    $author    Author ID.
	 * @return void
	 */
	public static function add_post( $id, $post_type = 'post', $author = 1 ) {
		self::$posts[ (int) $id ] = (object) array(
			'ID'        => (int) $id,
			'post_type' => $post_type,
			'post_author' => $author,
		);
	}
}

/**
 * Minimal `$wpdb` double.
 *
 * It records every call and returns canned values queued through
 * `AvandWP_Test_WP_State::queue_db()`. It does not parse SQL: a test that needs
 * to prove a *query* is correct inspects the recorded SQL string, and a test
 * that needs to prove *control flow* is correct queues the row the real database
 * would have returned. This is enough to exercise the branches that depend on
 * database results (dedupe lookup, job status reads, cache clearing) without
 * pretending to be a database engine.
 */
if ( ! class_exists( 'AvandWP_Test_WPDB' ) ) {
	class AvandWP_Test_WPDB {

		/** @var string Table prefix. */
		public $prefix = 'wp_';

		/** @var string Options table name, as WordPress exposes it. */
		public $options = 'wp_options';

		/** @var array<string, string> Table names the plugin asks for by property. */
		public $users = 'wp_users';

		/**
		 * Escape a value for use inside a LIKE pattern, exactly as WordPress does.
		 *
		 * The plugin uses this when it clears its own cached transients by
		 * prefix; without it the double would fatal instead of recording the
		 * statement, which is how this gap was found.
		 *
		 * @param string $text Value to escape.
		 * @return string
		 */
		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/** @var int Rows affected by the last write. */
		public $rows_affected = 0;

		/** @var string Last insert id. */
		public $insert_id = 0;

		/**
		 * Record one call.
		 *
		 * @param string $method Method.
		 * @param mixed  $sql    SQL or table.
		 * @param array  $args   Extra arguments.
		 * @return void
		 */
		private function record( $method, $sql, array $args = array() ) {
			AvandWP_Test_WP_State::$db_queries[] = array(
				'method' => $method,
				'sql'    => is_string( $sql ) ? $sql : '',
				'args'   => $args,
			);
		}

		/**
		 * Consume the next canned value for a method.
		 *
		 * @param string $method Method.
		 * @param mixed  $default Fallback.
		 * @return mixed
		 */
		private function consume( $method, $default ) {
			if ( empty( AvandWP_Test_WP_State::$db_queue[ $method ] ) ) {
				return $default;
			}

			return array_shift( AvandWP_Test_WP_State::$db_queue[ $method ] );
		}

		/**
		 * Prepare a query string.
		 *
		 * `%s` is quoted, `%d`/`%f` are formatted. Enough for the plugin's
		 * statements and, importantly, it makes the bound values visible in the
		 * recorded SQL so tests can assert on them.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$args = ( count( $args ) === 1 && is_array( $args[0] ) ) ? $args[0] : $args;

			$out = '';
			$i   = 0;
			$len = strlen( (string) $query );

			for ( $pos = 0; $pos < $len; $pos++ ) {
				$char = $query[ $pos ];

				if ( '%' !== $char || $pos + 1 >= $len ) {
					$out .= $char;
					continue;
				}

				$type = $query[ $pos + 1 ];

				if ( '%' === $type ) {
					$out .= '%';
					$pos++;
					continue;
				}

				if ( ! in_array( $type, array( 's', 'd', 'f' ), true ) ) {
					$out .= $char;
					continue;
				}

				$value = $args[ $i ] ?? null;
				$i++;

				if ( 'd' === $type ) {
					$out .= (string) (int) $value;
				} elseif ( 'f' === $type ) {
					$out .= (string) (float) $value;
				} else {
					$out .= "'" . addslashes( (string) $value ) . "'";
				}

				$pos++;
			}

			return $out;
		}

		/**
		 * Run a query.
		 *
		 * @param string $query SQL.
		 * @return int
		 */
		public function query( $query ) {
			$this->record( 'query', $query );
			return (int) $this->consume( 'query', 1 );
		}

		/**
		 * Fetch a single value.
		 *
		 * @param string $query SQL.
		 * @return mixed
		 */
		public function get_var( $query = null ) {
			if ( null !== $query ) {
				$this->record( 'get_var', $query );
			}

			return $this->consume( 'get_var', null );
		}

		/**
		 * Fetch a single row.
		 *
		 * @param string $query SQL.
		 * @param int    $output Output format.
		 * @return mixed
		 */
		public function get_row( $query = null, $output = OBJECT ) {
			if ( null !== $query ) {
				$this->record( 'get_row', $query, array( $output ) );
			}

			return $this->consume( 'get_row', null );
		}

		/**
		 * Fetch rows.
		 *
		 * @param string $query SQL.
		 * @param int    $output Output format.
		 * @return array
		 */
		public function get_results( $query = null, $output = OBJECT ) {
			if ( null !== $query ) {
				$this->record( 'get_results', $query, array( $output ) );
			}

			$rows = $this->consume( 'get_results', array() );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Fetch a column.
		 *
		 * @param string $query SQL.
		 * @return array
		 */
		public function get_col( $query = null ) {
			if ( null !== $query ) {
				$this->record( 'get_col', $query );
			}

			$rows = $this->consume( 'get_col', array() );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Insert a row.
		 *
		 * @param string $table Table.
		 * @param array  $data  Column values.
		 * @param array  $format Formats.
		 * @return int
		 */
		public function insert( $table, $data, $format = null ) {
			$result = (int) $this->consume( 'insert', 1 );

			$this->record( 'insert', (string) $table, array( 'data' => $data, 'format' => $format, 'result' => $result ) );

			if ( $result > 0 ) {
				$this->rows_affected = 1;
				$this->insert_id     = (int) $this->consume( 'insert_id', 101 );
			}

			return $result;
		}

		/**
		 * Update rows.
		 *
		 * @param string $table  Table.
		 * @param array  $data   Values.
		 * @param array  $where  Where clause.
		 * @param array  $format Formats.
		 * @param array  $where_format Where formats.
		 * @return int
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$result = (int) $this->consume( 'update', 1 );

			$this->record(
				'update',
				(string) $table,
				array(
					'data'  => $data,
					'where' => $where,
					'result' => $result,
				)
			);

			return $result;
		}

		/**
		 * Delete rows.
		 *
		 * @param string $table Table.
		 * @param array  $where Where clause.
		 * @param array  $where_format Formats.
		 * @return int
		 */
		public function delete( $table, $where, $where_format = null ) {
			$result = (int) $this->consume( 'delete', 1 );

			$this->record( 'delete', (string) $table, array( 'where' => $where, 'result' => $result ) );

			return $result;
		}

		/**
		 * Table charset/collation helper used by dbDelta statements.
		 *
		 * @return string
		 */
		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}
	}
}

$GLOBALS['wpdb'] = new AvandWP_Test_WPDB();

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Fetch a post fixture.
	 *
	 * @param int $id Post ID.
	 * @return object|null
	 */
	function get_post( $id ) {
		return AvandWP_Test_WP_State::$posts[ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Whether a post type exists.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	function post_type_exists( $type ) {
		return in_array( (string) $type, array( 'post', 'page', 'product', 'attachment' ), true );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Whether a user is logged in.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return AvandWP_Test_WP_State::$logged_in;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability check against the fixture set.
	 *
	 * Object-level checks use the exact WordPress convention:
	 * `current_user_can( 'edit_post', $id )`.
	 *
	 * @param string $cap  Capability.
	 * @param mixed  $args Extra arguments.
	 * @return bool
	 */
	function current_user_can( $cap, ...$args ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( 'edit_post' === $cap || 'edit_product' === $cap ) {
			$post_id = (int) ( $args[0] ?? 0 );
			if ( $post_id <= 0 ) {
				return false;
			}
			// Grant only when the fixture explicitly says so.
			return in_array( 'edit_post:' . $post_id, AvandWP_Test_WP_State::$caps, true );
		}

		return in_array( (string) $cap, AvandWP_Test_WP_State::$caps, true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Current user ID.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return AvandWP_Test_WP_State::$logged_in ? 7 : 0;
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	/**
	 * URL safety check shim mirroring WordPress' private/loopback rejection.
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	function wp_http_validate_url( $url ) {
		$url    = trim( (string) $url );
		$parts  = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		// Reject loopback, private and reserved addresses the way WordPress does.
		$blocked = array( 'localhost', '127.0.0.1', '0.0.0.0', '::1', '10.0.0.1', '192.168.1.1', '169.254.1.1', '172.16.0.1' );
		if ( in_array( $host, $blocked, true ) ) {
			return false;
		}

		if ( ! str_contains( $host, '.' ) ) {
			return false;
		}

		return $url;
	}
}

/* -------------------------------------------------------------------------
 * Options, transients and post meta.
 *
 * These shims implement real read-after-write semantics (rather than returning
 * fixed values) because several tests assert on state transitions: a setting
 * written by one call must be observable by the next.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Read an option from the in-memory store.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, AvandWP_Test_WP_State::$options )
			? AvandWP_Test_WP_State::$options[ $name ]
			: $default;
	}

	/**
	 * Write an option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $value   Value.
	 * @param mixed  $autoload Ignored by the shim.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		AvandWP_Test_WP_State::$options[ $name ] = $value;
		return true;
	}

	/**
	 * Delete an option.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( AvandWP_Test_WP_State::$options[ $name ] );
		return true;
	}

	/**
	 * Read a transient (stored without the expiry suffix).
	 *
	 * @param string $name Transient name.
	 * @return mixed
	 */
	function get_transient( $name ) {
		return AvandWP_Test_WP_State::$transients[ $name ] ?? false;
	}

	/**
	 * Write a transient.
	 *
	 * @param string $name  Transient name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Expiry in seconds (recorded, not enforced).
	 * @return bool
	 */
	function set_transient( $name, $value, $ttl = 0 ) {
		AvandWP_Test_WP_State::$transients[ $name ] = $value;
		AvandWP_Test_WP_State::$transient_ttl[ $name ] = (int) $ttl;
		return true;
	}

	/**
	 * Delete a transient.
	 *
	 * @param string $name Transient name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( AvandWP_Test_WP_State::$transients[ $name ], AvandWP_Test_WP_State::$transient_ttl[ $name ] );
		return true;
	}

	/**
	 * Read post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$store = AvandWP_Test_WP_State::$post_meta[ (int) $post_id ] ?? array();

		if ( '' === $key ) {
			return $store;
		}

		return $store[ $key ] ?? ( $single ? '' : array() );
	}

	/**
	 * Write post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		AvandWP_Test_WP_State::$post_meta[ (int) $post_id ][ (string) $key ] = $value;
		return true;
	}

	/**
	 * Delete post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_post_meta( $post_id, $key ) {
		unset( AvandWP_Test_WP_State::$post_meta[ (int) $post_id ][ (string) $key ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Strip tags and collapse nothing; mirrors WordPress' behaviour closely
	 * enough for validation tests.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Produces a URL-safe slug.
	 *
	 * @param string $title     Title.
	 * @param string $fallback  Fallback.
	 * @param string $context   Context.
	 * @return string
	 */
	function sanitize_title( $title, $fallback = '', $context = 'save' ) {
		$slug = strtolower( trim( (string) $title ) );
		$slug = preg_replace( '/[^a-z0-9\-]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		return '' !== $slug ? $slug : (string) $fallback;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Sanitise a URL for storage.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );

		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape for an HTML attribute.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for output.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return esc_attr( $url );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * Escape textarea content.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Reverse of wp_slash().
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer.
	 *
	 * @param mixed $n Value.
	 * @return int
	 */
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merge user arguments over defaults.
	 *
	 * @param mixed $args     Arguments.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		$args = (array) $args;

		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Site home URL from the fixture.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return rtrim( AvandWP_Test_WP_State::$home_url, '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Admin URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return rtrim( AvandWP_Test_WP_State::$home_url, '/' ) . '/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	/**
	 * Whether the site is served over HTTPS (fixture-controlled).
	 *
	 * @return bool
	 */
	function is_ssl() {
		return 0 === strpos( AvandWP_Test_WP_State::$home_url, 'https://' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Current time in WordPress' local format.
	 *
	 * @param string $type Date or mysql.
	 * @return string
	 */
	function current_time( $type = 'mysql' ) {
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * Human-readable byte size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	function size_format( $bytes ) {
		return number_format_i18n( (int) $bytes ) . ' B';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	// Already defined earlier in this file; the guard keeps the shim idempotent.
}

/* -------------------------------------------------------------------------
 * HTTP stack shim.
 *
 * The plugin talks to six AI providers and two Google APIs. Nothing in this
 * sandbox has network access, so the transport is replaced by a queue: a test
 * decides exactly what the "server" answers, and any call with an empty queue
 * returns a WP_Error instead of silently succeeding. That keeps the rule that a
 * passing test proves parsing of a *known* response, and never proves that a
 * live endpoint was reached.
 * ---------------------------------------------------------------------- */

/**
 * Canned HTTP responses, consumed in order by the wp_remote_* shims.
 */
class AvandWP_Test_HTTP {

	/**
	 * Pending responses: `[ ['code' => int, 'body' => string, 'headers' => array] ]`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $queue = array();

	/**
	 * Queue one response.
	 *
	 * @param string               $body    Raw response body.
	 * @param int                  $code    HTTP status code.
	 * @param array<string,string> $headers Optional response headers.
	 * @return void
	 */
	public static function queue( $body, $code = 200, array $headers = array() ) {
		self::$queue[] = array(
			'code'    => (int) $code,
			'body'    => (string) $body,
			'headers' => $headers,
		);
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param array<string, mixed> $payload Decoded payload.
	 * @param int                  $code    HTTP status code.
	 * @return void
	 */
	public static function queue_json( array $payload, $code = 200 ) {
		self::$queue[] = array(
			'code'    => (int) $code,
			'body'    => (string) wp_json_encode( $payload ),
			'headers' => array( 'content-type' => 'application/json' ),
		);
	}

	/**
	 * Consume the next queued response.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Requested URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return array|WP_Error
	 */
	public static function respond( $method, $url, $args ) {
		AvandWP_Test_WP_State::$http_requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);

		if ( empty( self::$queue ) ) {
			return new WP_Error(
				'avandwp_test_network_unstubbed',
				sprintf( 'The test suite made an unexpected %s request to %s with no queued response.', $method, $url )
			);
		}

		$next = array_shift( self::$queue );

		return array(
			'headers'  => $next['headers'],
			'body'     => $next['body'],
			'response' => array( 'code' => $next['code'], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Last recorded request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_request() {
		return empty( AvandWP_Test_WP_State::$http_requests )
			? null
			: AvandWP_Test_WP_State::$http_requests[ count( AvandWP_Test_WP_State::$http_requests ) - 1 ];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Shim of wp_remote_get().
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) {
		return AvandWP_Test_HTTP::respond( 'GET', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_head' ) ) {
	/**
	 * Shim of wp_remote_head().
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array|WP_Error
	 */
	function wp_remote_head( $url, $args = array() ) {
		return AvandWP_Test_HTTP::respond( 'HEAD', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Shim of wp_remote_post().
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array|WP_Error
	 */
	function wp_remote_post( $url, $args = array() ) {
		return AvandWP_Test_HTTP::respond( 'POST', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Shim of wp_remote_retrieve_response_code().
	 *
	 * @param array|WP_Error $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) ) {
			return '';
		}

		return (int) $response['response']['code'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Shim of wp_remote_retrieve_body().
	 *
	 * @param array|WP_Error $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
			return '';
		}

		return (string) $response['body'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Shim of wp_remote_retrieve_header().
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $header   Header name.
	 * @return string
	 */
	function wp_remote_retrieve_header( $response, $header ) {
		if ( is_wp_error( $response ) || ! isset( $response['headers'] ) ) {
			return '';
		}

		$headers = (array) $response['headers'];
		$key     = strtolower( (string) $header );

		foreach ( $headers as $name => $value ) {
			if ( strtolower( (string) $name ) === $key ) {
				return (string) $value;
			}
		}

		return '';
	}
}
