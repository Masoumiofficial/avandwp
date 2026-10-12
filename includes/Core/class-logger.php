<?php
/**
 * Activity Logger and Token/Cost Usage Tracker.
 *
 * Records operational events in `avandwp_activity_log` and real token consumption
 * in `avandwp_usage`. Never fabricates numbers.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Logger {

	/**
	 * Record an operational activity log entry.
	 *
	 * @param string $action  Short action identifier (e.g. 'studio.article', 'woo.optimize').
	 * @param string $message Human-readable Persian description.
	 * @param string $level   'info' | 'success' | 'warning' | 'error'.
	 * @param array  $context Additional metadata stored as JSON.
	 * @return int|false Inserted row ID or false.
	 */
	public static function log( $action, $message, $level = 'info', $context = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_activity_log';

		$allowed_levels = array( 'info', 'success', 'warning', 'error' );
		if ( ! in_array( $level, $allowed_levels, true ) ) {
			$level = 'info';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'    => get_current_user_id(),
				'action'     => sanitize_text_field( substr( (string) $action, 0, 100 ) ),
				'level'      => $level,
				'message'    => sanitize_text_field( (string) $message ),
				'context'    => ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Record real API token usage.
	 *
	 * @param string $provider  Provider key (e.g. 'gapgpt', 'openai', 'gemini', 'claude').
	 * @param string $model     Model identifier.
	 * @param int    $tokens_in Prompt tokens.
	 * @param int    $tokens_out Completion tokens.
	 * @param string $feature   Feature slug (e.g. 'studio', 'woo', 'chat').
	 * @param int    $job_id    Optional background job ID.
	 * @return int|false
	 */
	public static function record_usage( $provider, $model, $tokens_in, $tokens_out, $feature = 'general', $job_id = 0, array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_usage';

		$provider = sanitize_key( $provider );
		$model    = sanitize_text_field( (string) $model );

		$tokens_in  = max( 0, (int) $tokens_in );
		$tokens_out = max( 0, (int) $tokens_out );
		$total      = $tokens_in + $tokens_out;

		$operation     = sanitize_key( $args['operation'] ?? 'text' );
		$units         = max( 0, (int) ( $args['units'] ?? 0 ) );
		$tokens_source = (string) ( $args['tokens_source'] ?? 'provider_reported' );

		// Cost is NEVER invented. Either the caller supplies a verified figure,
		// or the price catalogue is consulted, or the column stays NULL and the
		// source is recorded as `unknown` so the UI can say so.
		$cost_usd    = null;
		$cost_source = 'unknown';
		$cost_note   = '';

		if ( array_key_exists( 'cost_usd', $args ) && null !== $args['cost_usd'] ) {
			$cost_usd    = round( (float) $args['cost_usd'], 6 );
			$cost_source = (string) ( $args['cost_source'] ?? 'provider_reported' );
			$cost_note   = (string) ( $args['cost_note'] ?? 'مبلغ گزارش‌شده توسط ارائه‌دهنده.' );
		} elseif ( 'image' === $operation ) {
			$estimate    = AvandWP_Price_Catalog::estimate_image( $provider, $model, (string) ( $args['size'] ?? '1024x1024' ) );
			$cost_usd    = $estimate['cost'];
			$cost_source = $estimate['source'];
			$cost_note   = $estimate['note'];
		} else {
			$estimate    = AvandWP_Price_Catalog::estimate_tokens( $provider, $model, $tokens_in, $tokens_out );
			$cost_usd    = $estimate['cost'];
			$cost_source = $estimate['source'];
			$cost_note   = $estimate['note'];
		}

		if ( ! in_array( $cost_source, array( 'provider_reported', 'estimated', 'unknown' ), true ) ) {
			$cost_source = 'unknown';
		}

		if ( ! in_array( $tokens_source, array( 'provider_reported', 'estimated', 'not_applicable' ), true ) ) {
			$tokens_source = 'estimated';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'       => get_current_user_id(),
				'job_id'        => max( 0, (int) $job_id ),
				'provider'      => $provider,
				'model'         => $model,
				'feature'       => sanitize_key( $feature ),
				'operation'     => $operation,
				'tokens_in'     => $tokens_in,
				'tokens_out'    => $tokens_out,
				'total_tokens'  => $total,
				'units'         => $units,
				'cost_usd'      => $cost_usd,
				'cost_source'   => $cost_source,
				'cost_note'     => '' !== $cost_note ? $cost_note : null,
				'tokens_source' => $tokens_source,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( $ok ) {
			// Feed the global spend guard with the same figure recorded here.
			AvandWP_Spend_Guard::record( $cost_usd, 1 );
		}

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Estimate USD cost using the verified price catalogue.
	 *
	 * Retained for backwards compatibility with 1.0.0 call sites. It now returns
	 * `null` for a model whose price has not been verified, instead of silently
	 * applying an unrelated default rate.
	 *
	 * @deprecated 2.0.0 Use AvandWP_Price_Catalog::estimate_tokens().
	 *
	 * @param string $model      Model name.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @param string $provider   Provider slug.
	 * @return float|null
	 */
	public static function estimate_cost_usd( $model, $tokens_in, $tokens_out, $provider = 'gapgpt' ) {
		$estimate = AvandWP_Price_Catalog::estimate_tokens( $provider, $model, $tokens_in, $tokens_out );
		return $estimate['cost'];
	}

	/**
	 * Fetch recent activity logs.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_recent_logs( $limit = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_activity_log';
		$limit = max( 1, min( 200, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get aggregated usage summary for the last N days.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public static function get_usage_summary( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_usage';
		$days  = max( 1, min( 365, (int) $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in), 0) AS tokens_in,
				        COALESCE(SUM(tokens_out), 0) AS tokens_out,
				        COALESCE(SUM(total_tokens), 0) AS total_tokens,
				        COALESCE(SUM(cost_usd), 0) AS cost_usd,
				        COALESCE(SUM(CASE WHEN cost_source = 'provider_reported' THEN cost_usd ELSE 0 END), 0) AS reported_cost,
				        COALESCE(SUM(CASE WHEN cost_source = 'estimated' THEN cost_usd ELSE 0 END), 0) AS estimated_cost,
				        SUM(CASE WHEN cost_source = 'unknown' THEN 1 ELSE 0 END) AS unknown_cost_requests,
				        SUM(CASE WHEN tokens_source = 'estimated' THEN 1 ELSE 0 END) AS estimated_token_requests
				 FROM `{$table}`
				 WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_provider = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, model, COUNT(*) AS requests, SUM(total_tokens) AS total_tokens, SUM(cost_usd) AS cost_usd
				 FROM `{$table}`
				 WHERE created_at >= %s
				 GROUP BY provider, model
				 ORDER BY total_tokens DESC",
				$since
			),
			ARRAY_A
		);

		return array(
			'requests'                 => isset( $totals['requests'] ) ? (int) $totals['requests'] : 0,
			'tokens_in'                => isset( $totals['tokens_in'] ) ? (int) $totals['tokens_in'] : 0,
			'tokens_out'               => isset( $totals['tokens_out'] ) ? (int) $totals['tokens_out'] : 0,
			'total_tokens'             => isset( $totals['total_tokens'] ) ? (int) $totals['total_tokens'] : 0,
			'cost_usd'                 => isset( $totals['cost_usd'] ) ? round( (float) $totals['cost_usd'], 4 ) : 0.0,
			'cost_reported_usd'        => isset( $totals['reported_cost'] ) ? round( (float) $totals['reported_cost'], 4 ) : 0.0,
			'cost_estimated_usd'       => isset( $totals['estimated_cost'] ) ? round( (float) $totals['estimated_cost'], 4 ) : 0.0,
			'unknown_cost_requests'    => isset( $totals['unknown_cost_requests'] ) ? (int) $totals['unknown_cost_requests'] : 0,
			'estimated_token_requests' => isset( $totals['estimated_token_requests'] ) ? (int) $totals['estimated_token_requests'] : 0,
			'by_provider'              => is_array( $by_provider ) ? $by_provider : array(),
		);
	}
}
