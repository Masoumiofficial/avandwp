<?php
/**
 * Global spend guard.
 *
 * The 1.0.0 plugin offered per-IP rate limiting only. A single host (or a
 * distributed low-rate attack, or a shared NAT) could still drive unlimited
 * paid provider calls, because no limit existed on total consumption.
 *
 * This guard enforces a site-wide ceiling on estimated provider spend per day
 * and stops AI operations once it is reached. It is deliberately independent of
 * the per-IP rate limiter: rate limits protect availability, this protects the
 * owner's money.
 *
 * Cost figures come from `AvandWP_Price_Catalog` and are always treated as
 * estimates. When a model has no verified price the guard falls back to
 * counting *operations*, so an unpriceable provider still cannot be abused
 * without limit.
 *
 * @package AvandWP\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily spend and operation ceiling.
 */
class AvandWP_Spend_Guard {

	/**
	 * Option holding today's counters.
	 */
	const OPTION = 'avandwp_spend_ledger';

	/**
	 * Is spend enforcement enabled by the administrator?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'avandwp_settings', array() );
		return ! empty( $settings['spend_guard_enabled'] );
	}

	/**
	 * Estimated USD ceiling for one day. 0 disables the money ceiling.
	 *
	 * @return float
	 */
	public static function daily_usd_limit() {
		$settings = get_option( 'avandwp_settings', array() );

		/**
		 * Filters the daily estimated-spend ceiling in USD.
		 *
		 * @param float $limit Ceiling in USD.
		 */
		return (float) apply_filters(
			'avandwp_daily_spend_limit_usd',
			isset( $settings['daily_spend_limit_usd'] ) ? (float) $settings['daily_spend_limit_usd'] : 0.0
		);
	}

	/**
	 * Ceiling on paid operations per day, used when cost is unknown.
	 *
	 * @return int
	 */
	public static function daily_operation_limit() {
		$settings = get_option( 'avandwp_settings', array() );

		return (int) apply_filters(
			'avandwp_daily_operation_limit',
			isset( $settings['daily_operation_limit'] ) ? (int) $settings['daily_operation_limit'] : 500
		);
	}

	/**
	 * Today's ledger, resetting automatically when the date changes.
	 *
	 * @return array{date: string, usd: float, operations: int, unknown_cost_operations: int}
	 */
	public static function ledger() {
		$today  = current_time( 'Y-m-d' );
		$ledger = get_option( self::OPTION, array() );

		if ( ! is_array( $ledger ) || ( $ledger['date'] ?? '' ) !== $today ) {
			$ledger = array(
				'date'                     => $today,
				'usd'                      => 0.0,
				'operations'               => 0,
				'unknown_cost_operations'  => 0,
			);
			update_option( self::OPTION, $ledger, false );
		}

		return $ledger;
	}

	/**
	 * May another paid operation proceed?
	 *
	 * @param int $expected_operations How many billable calls this action will make.
	 * @return true|WP_Error True when allowed.
	 */
	public static function can_spend( $expected_operations = 1 ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		$ledger   = self::ledger();
		$usd_cap  = self::daily_usd_limit();
		$ops_cap  = self::daily_operation_limit();
		$expected = max( 1, (int) $expected_operations );

		if ( $ops_cap > 0 && ( (int) $ledger['operations'] + $expected ) > $ops_cap ) {
			return new WP_Error(
				'avandwp_operation_ceiling',
				sprintf(
					'سقف عملیات هوش مصنوعی امروز (%d عملیات) تکمیل شده است. برای ادامه، حد مصرف را در «تنظیمات و سلامت ← کنترل هزینه» افزایش دهید.',
					$ops_cap
				)
			);
		}

		if ( $usd_cap > 0 && (float) $ledger['usd'] >= $usd_cap ) {
			return new WP_Error(
				'avandwp_spend_ceiling',
				sprintf(
					'سقف هزینه تخمینی امروز (%.2f دلار) تکمیل شده است. مصرف واقعی خود را در پنل GapGPT بررسی و سپس حد مصرف را افزایش دهید.',
					$usd_cap
				)
			);
		}

		return true;
	}

	/**
	 * Record a completed paid operation against today's ledger.
	 *
	 * @param float|null $cost_usd Estimated cost, or null when unknown.
	 * @param int        $operations Number of billable calls represented.
	 * @return void
	 */
	public static function record( $cost_usd, $operations = 1 ) {
		$ledger = self::ledger();

		$ledger['operations'] = (int) $ledger['operations'] + max( 1, (int) $operations );

		if ( null === $cost_usd ) {
			++$ledger['unknown_cost_operations'];
		} else {
			$ledger['usd'] = round( (float) $ledger['usd'] + (float) $cost_usd, 6 );
		}

		update_option( self::OPTION, $ledger, false );
	}

	/**
	 * Today's usage summary for the admin UI.
	 *
	 * @return array
	 */
	public static function summary() {
		$ledger = self::ledger();

		return array(
			'enabled'                  => self::is_enabled(),
			'date'                     => $ledger['date'],
			'usd_used'                 => (float) $ledger['usd'],
			'usd_limit'                => self::daily_usd_limit(),
			'operations_used'          => (int) $ledger['operations'],
			'operations_limit'         => self::daily_operation_limit(),
			'unknown_cost_operations'  => (int) $ledger['unknown_cost_operations'],
			'note'                     => 'مبالغ این بخش «تخمینی» و بر پایه فهرست قیمت تأییدشده است، نه صورتحساب واقعی ارائه‌دهنده.',
		);
	}
}
