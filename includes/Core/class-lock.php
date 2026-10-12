<?php
/**
 * Advisory locking for background work.
 *
 * The 1.0.0 job queue used a bare transient as a mutex and released it
 * unconditionally — including from a request that never acquired it. A
 * `poll_job` AJAX call could therefore free the lock held by a concurrently
 * running cron batch, letting two batches execute the same paid AI job at once.
 *
 * A lock now has an owner token. Only the holder may release it, and a lock
 * whose holder died is reclaimed after its TTL instead of blocking the queue
 * forever.
 *
 * @package AvandWP\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token-owned advisory lock.
 */
class AvandWP_Lock {

	/**
	 * Default TTL in seconds. A lock older than this is considered abandoned.
	 */
	const DEFAULT_TTL = 300;

	/**
	 * Generate a cryptographically random token.
	 *
	 * @return string
	 */
	public static function token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			// random_bytes() throws when no CSPRNG is available. Fall back to a
			// salted hash of unpredictable request state rather than a weak PRNG.
			return substr( hash( 'sha256', uniqid( (string) mt_rand(), true ) . microtime( true ) ), 0, 32 );
		}
	}

	/**
	 * Attempt to acquire a lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before an unreleased lock is reclaimable.
	 * @return string|false Owner token on success, false when already held.
	 */
	public static function acquire( $name, $ttl = self::DEFAULT_TTL ) {
		$key   = self::option_key( $name );
		$now   = time();
		$ttl   = max( 30, (int) $ttl );
		$token = self::token();

		// `add_option()` is atomic at the database level: it fails when the row
		// already exists, which gives us a real mutex without GET_LOCK (not
		// available on every host) or a cache backend.
		$payload = wp_json_encode(
			array(
				'token'   => $token,
				'expires' => $now + $ttl,
			)
		);

		if ( add_option( $key, $payload, '', false ) ) {
			return $token;
		}

		// Row exists. Take it over only if it has expired.
		$existing = json_decode( (string) get_option( $key, '' ), true );
		$expires  = is_array( $existing ) ? (int) ( $existing['expires'] ?? 0 ) : 0;

		if ( $expires > $now ) {
			return false;
		}

		// Expired: replace the holder. Two processes may race here, but only one
		// wins the follow-up read, and the loser returns false below.
		update_option( $key, $payload, false );

		$confirm = json_decode( (string) get_option( $key, '' ), true );
		if ( is_array( $confirm ) && isset( $confirm['token'] ) && $confirm['token'] === $token ) {
			return $token;
		}

		return false;
	}

	/**
	 * Release a lock, but only if this process owns it.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Token returned by acquire().
	 * @return bool True when the lock was released by this process.
	 */
	public static function release( $name, $token ) {
		if ( '' === (string) $token ) {
			return false;
		}

		$key      = self::option_key( $name );
		$existing = json_decode( (string) get_option( $key, '' ), true );

		if ( ! is_array( $existing ) || ! isset( $existing['token'] ) ) {
			return false;
		}

		if ( ! hash_equals( (string) $existing['token'], (string) $token ) ) {
			// Another process owns (or has reclaimed) the lock. Do not steal it.
			return false;
		}

		return delete_option( $key );
	}

	/**
	 * Is a lock currently held by a live holder?
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function is_held( $name ) {
		$existing = json_decode( (string) get_option( self::option_key( $name ), '' ), true );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		return (int) ( $existing['expires'] ?? 0 ) > time();
	}

	/**
	 * Option name for a lock.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option_key( $name ) {
		return 'avandwp_lock_' . substr( md5( (string) $name ), 0, 24 );
	}
}
