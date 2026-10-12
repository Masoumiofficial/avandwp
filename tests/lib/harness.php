<?php
/**
 * Tiny zero-dependency test harness.
 *
 * PHPUnit cannot run here: Composer is unavailable and the bundled PHP runtime
 * does not compile the `dom`/`xml` extensions PHPUnit requires. Rather than skip
 * testing, this harness provides the assertions the suite actually needs and
 * reports real pass/fail counts.
 *
 * Assertions throw on failure; the runner catches and records them, so a
 * failing assertion never hides the remaining tests.
 */

/**
 * Test harness: registry, assertions and reporting.
 */
class AvandWP_Test_Harness {

	/** @var array<int, array{name: string, group: string, fn: callable}> */
	private static $tests = array();

	/** @var array<int, string> */
	private static $failures = array();

	/** @var int */
	private static $passed = 0;

	/** @var string */
	private static $current = '';

	/** @var string */
	private static $group = '';

	/**
	 * Begin a named group of tests.
	 *
	 * @param string $name Group label.
	 * @return void
	 */
	public static function group( $name ) {
		self::$group = (string) $name;
	}

	/**
	 * Register a test.
	 *
	 * @param string   $name Test name.
	 * @param callable $fn   Test body.
	 * @return void
	 */
	public static function test( $name, callable $fn ) {
		self::$tests[] = array(
			'name'  => $name,
			'group' => self::$group,
			'fn'    => $fn,
		);
	}

	/**
	 * Run every registered test.
	 *
	 * @return int Exit code.
	 */
	public static function run() {
		$started = microtime( true );

		foreach ( self::$tests as $test ) {
			self::$current = $test['name'];
			self::$group   = $test['group'];

			try {
				call_user_func( $test['fn'] );
				++self::$passed;
			} catch ( Throwable $e ) {
				self::$failures[] = sprintf(
					'%s :: %s — %s',
					$test['group'],
					$test['name'],
					$e->getMessage()
				);
			}
		}

		$elapsed = round( ( microtime( true ) - $started ) * 1000 );

		echo "\n";
		echo str_repeat( '=', 66 ) . "\n";
		echo 'AvandWP unit suite — PHP ' . PHP_VERSION . "\n";
		echo str_repeat( '=', 66 ) . "\n";
		echo 'Passed:   ' . self::$passed . "\n";
		echo 'Failed:   ' . count( self::$failures ) . "\n";
		echo 'Duration: ' . $elapsed . "ms\n";

		if ( self::$failures ) {
			echo "\nFAILURES\n";
			foreach ( self::$failures as $failure ) {
				echo '  ✗ ' . $failure . "\n";
			}
		}

		echo "\nRESULT: " . ( self::$failures ? 'FAIL' : 'PASS' ) . "\n";

		echo "\n__AVANDWP_TEST_SUMMARY__ " . wp_json_encode(
			array(
				'passed'   => self::$passed,
				'failed'   => count( self::$failures ),
				'failures' => self::$failures,
				'php'      => PHP_VERSION,
				'duration' => $elapsed,
			)
		) . "\n";

		return self::$failures ? 1 : 0;
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Assert a condition is true.
	 *
	 * @param mixed  $condition Condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	public static function assert_true( $condition, $message = 'expected true' ) {
		if ( true !== $condition ) {
			throw new Exception( $message . ' (got ' . var_export( $condition, true ) . ')' );
		}
	}

	/**
	 * Assert a condition is false.
	 *
	 * @param mixed  $condition Condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	public static function assert_false( $condition, $message = 'expected false' ) {
		if ( false !== $condition ) {
			throw new Exception( $message . ' (got ' . var_export( $condition, true ) . ')' );
		}
	}

	/**
	 * Assert strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @return void
	 */
	public static function assert_same( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new Exception(
				sprintf(
					'%sexpected %s but got %s',
					'' !== $message ? $message . ': ' : '',
					var_export( $expected, true ),
					var_export( $actual, true )
				)
			);
		}
	}

	/**
	 * Assert a value lies within an inclusive range.
	 *
	 * @param int|float $min     Minimum.
	 * @param int|float $max     Maximum.
	 * @param int|float $actual  Actual value.
	 * @param string    $message Failure message.
	 * @return void
	 */
	public static function assert_between( $min, $max, $actual, $message = '' ) {
		if ( $actual < $min || $actual > $max ) {
			throw new Exception(
				sprintf(
					'%sexpected a value between %s and %s but got %s',
					'' !== $message ? $message . ': ' : '',
					var_export( $min, true ),
					var_export( $max, true ),
					var_export( $actual, true )
				)
			);
		}
	}

	/**
	 * Assert a float matches within a tolerance.
	 *
	 * @param float  $expected  Expected value.
	 * @param float  $actual    Actual value.
	 * @param float  $tolerance Allowed difference.
	 * @param string $message   Failure message.
	 * @return void
	 */
	public static function assert_close( $expected, $actual, $tolerance = 0.0001, $message = '' ) {
		if ( null === $actual || abs( (float) $expected - (float) $actual ) > $tolerance ) {
			throw new Exception(
				sprintf(
					'%sexpected ≈%s but got %s',
					'' !== $message ? $message . ': ' : '',
					var_export( $expected, true ),
					var_export( $actual, true )
				)
			);
		}
	}

	/**
	 * Assert a value is null.
	 *
	 * @param mixed  $actual  Actual value.
	 * @param string $message Failure message.
	 * @return void
	 */
	public static function assert_null( $actual, $message = '' ) {
		if ( null !== $actual ) {
			throw new Exception(
				sprintf( '%sexpected null but got %s', '' !== $message ? $message . ': ' : '', var_export( $actual, true ) )
			);
		}
	}

	/**
	 * Assert a string contains a substring.
	 *
	 * @param string $needle   Needle.
	 * @param string $haystack Haystack.
	 * @param string $message  Failure message.
	 * @return void
	 */
	public static function assert_contains( $needle, $haystack, $message = '' ) {
		if ( false === strpos( (string) $haystack, (string) $needle ) ) {
			throw new Exception(
				sprintf(
					'%sexpected to find %s in %s',
					'' !== $message ? $message . ': ' : '',
					var_export( $needle, true ),
					var_export( $haystack, true )
				)
			);
		}
	}

	/**
	 * Assert a string does NOT contain a substring.
	 *
	 * @param string $needle   Needle.
	 * @param string $haystack Haystack.
	 * @param string $message  Failure message.
	 * @return void
	 */
	public static function assert_not_contains( $needle, $haystack, $message = '' ) {
		if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
			throw new Exception(
				sprintf(
					'%sdid not expect %s to appear in %s',
					'' !== $message ? $message . ': ' : '',
					var_export( $needle, true ),
					var_export( $haystack, true )
				)
			);
		}
	}

	/**
	 * Assert a value is an instance of a class.
	 *
	 * @param string $class  Class name.
	 * @param mixed  $actual Value.
	 * @param string $message Failure message.
	 * @return void
	 */
	public static function assert_instance( $class, $actual, $message = '' ) {
		if ( ! ( $actual instanceof $class ) ) {
			throw new Exception(
				sprintf(
					'%sexpected instance of %s but got %s',
					'' !== $message ? $message . ': ' : '',
					$class,
					is_object( $actual ) ? get_class( $actual ) : gettype( $actual )
				)
			);
		}
	}

	/**
	 * Assert an array has a key.
	 *
	 * @param string $key     Key.
	 * @param mixed  $array   Array.
	 * @param string $message Failure message.
	 * @return void
	 */
	public static function assert_key( $key, $array, $message = '' ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			throw new Exception(
				sprintf( '%sexpected key %s', '' !== $message ? $message . ': ' : '', var_export( $key, true ) )
			);
		}
	}
}
