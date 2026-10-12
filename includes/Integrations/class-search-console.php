<?php
/**
 * Google Search Console: property discovery and Search Analytics queries.
 *
 * All data returned by this class comes from Google's API. Nothing is
 * estimated, interpolated or defaulted: if the API is unavailable, the caller
 * receives a `WP_Error` and the UI is required to show the reason rather than a
 * zero. A "0 clicks" figure that is really "no data" is the exact failure this
 * plugin exists to avoid.
 *
 * Endpoints verified against Google's documentation on
 * `AvandWP_Google_Auth::VERIFIED_ON`:
 *   - `GET  https://www.googleapis.com/webmasters/v3/sites`
 *   - `POST https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query`
 *   - scope `https://www.googleapis.com/auth/webmasters.readonly`
 *
 * @package AvandWP\Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console data access.
 */
class AvandWP_Search_Console {

	/**
	 * API base URL (verified).
	 */
	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * Cache lifetime for query results, in seconds.
	 *
	 * Search Console data updates over hours, not seconds; caching keeps a
	 * dashboard refresh from burning the per-site API quota.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Maximum rows the API will be asked for in one query.
	 */
	const MAX_ROWS = 500;

	/**
	 * List Search Console properties the connected account can read.
	 *
	 * @return array<int, array{url: string, permission: string}>|WP_Error
	 */
	public function list_sites() {
		$cached = get_transient( 'avandwp_gsc_sites' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$body = AvandWP_Google_Auth::get( self::API_BASE . '/sites' );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$sites = array();

		foreach ( (array) ( $body['siteEntry'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['siteUrl'] ) ) {
				continue;
			}

			$sites[] = array(
				'url'        => (string) $entry['siteUrl'],
				'permission' => (string) ( $entry['permissionLevel'] ?? '' ),
			);
		}

		set_transient( 'avandwp_gsc_sites', $sites, self::CACHE_TTL );

		return $sites;
	}

	/**
	 * The property the owner selected, falling back to the site's own host when
	 * a matching property exists.
	 *
	 * @return string|WP_Error
	 */
	public function selected_site() {
		$settings = get_option( 'avandwp_settings', array() );
		$selected = (string) ( $settings['gsc_property'] ?? '' );

		$sites = $this->list_sites();

		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		if ( '' !== $selected ) {
			foreach ( $sites as $site ) {
				if ( $site['url'] === $selected ) {
					return $selected;
				}
			}

			return new WP_Error(
				'avandwp_gsc_property_gone',
				sprintf(
					'پرتال انتخاب‌شده (%s) در فهرست دسترسی‌های این حساب گوگل نیست. ممکن است دسترسی لغو شده باشد؛ اتصال را بازبینی کنید.',
					$selected
				)
			);
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		foreach ( $sites as $site ) {
			$property_host = strtolower( (string) wp_parse_url( $site['url'], PHP_URL_HOST ) );

			if ( '' === $property_host && 0 === strpos( $site['url'], 'sc-domain:' ) ) {
				$property_host = substr( $site['url'], strlen( 'sc-domain:' ) );
			}

			if ( $property_host === $home_host ) {
				return $site['url'];
			}
		}

		if ( ! empty( $sites ) ) {
			// Nothing matched the site's host: do not silently pick an unrelated
			// property, which would report another site's numbers here.
			return new WP_Error(
				'avandwp_gsc_no_matching_property',
				sprintf(
					'هیچ پرتالی در Search Console با دامنه این سایت (%s) مطابقت ندارد. یکی از پرتال‌های موجود را در تنظیمات انتخاب کنید.',
					$home_host
				)
			);
		}

		return new WP_Error(
			'avandwp_gsc_empty',
			'حساب گوگل متصل، دسترسی به هیچ پرتالی در Search Console ندارد.'
		);
	}

	/**
	 * Run a Search Analytics query.
	 *
	 * @param array $args {
	 *     @type string $start_date Start date (Y-m-d), inclusive.
	 *     @type string $end_date   End date (Y-m-d), inclusive.
	 *     @type array  $dimensions Dimensions, e.g. ['query'] or ['page'].
	 *     @type int    $row_limit  Maximum rows.
	 *     @type array  $filters    Optional dimensionFilterGroups.
	 * }
	 * @return array|WP_Error {
	 *     @type string $property   Property queried.
	 *     @type string $start_date Start date.
	 *     @type string $end_date   End date.
	 *     @type array  $dimensions Dimensions.
	 *     @type array  $rows       Rows with keys/click/impressions/ctr/position.
	 *     @type array  $totals     Summed clicks/impressions and weighted CTR/position.
	 * }
	 */
	public function query( array $args = array() ) {
		$property = $this->selected_site();

		if ( is_wp_error( $property ) ) {
			return $property;
		}

		$days = max( 1, min( 480, (int) ( $args['days'] ?? 28 ) ) );

		$end   = isset( $args['end_date'] ) ? (string) $args['end_date'] : gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start = isset( $args['start_date'] ) ? (string) $args['start_date'] : gmdate( 'Y-m-d', strtotime( $end . ' -' . ( $days - 1 ) . ' days' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			return new WP_Error( 'avandwp_gsc_bad_dates', 'قالب تاریخ باید YYYY-MM-DD باشد.' );
		}

		$allowed_dimensions = array( 'query', 'page', 'country', 'device', 'date', 'searchAppearance' );

		$dimensions = array();

		foreach ( (array) ( $args['dimensions'] ?? array( 'query' ) ) as $dimension ) {
			$dimension = (string) $dimension;

			if ( in_array( $dimension, $allowed_dimensions, true ) ) {
				$dimensions[] = $dimension;
			}
		}

		if ( empty( $dimensions ) ) {
			$dimensions = array( 'query' );
		}

		$row_limit = max( 1, min( self::MAX_ROWS, (int) ( $args['row_limit'] ?? 50 ) ) );

		$payload = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => $dimensions,
			'rowLimit'   => $row_limit,
		);

		if ( ! empty( $args['filters'] ) && is_array( $args['filters'] ) ) {
			$payload['dimensionFilterGroups'] = $args['filters'];
		}

		$cache_key = 'avandwp_gsc_' . md5( wp_json_encode( array( $property, $payload ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$cached['from_cache'] = true;

			return $cached;
		}

		$endpoint = sprintf(
			'%s/sites/%s/searchAnalytics/query',
			self::API_BASE,
			rawurlencode( $property )
		);

		$body = AvandWP_Google_Auth::post( $endpoint, $payload );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$rows      = array();
		$max_rows  = 0;
		$clicks    = 0.0;
		$impressions = 0.0;
		$position_weighted = 0.0;

		foreach ( (array) ( $body['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$keys = array();

			foreach ( (array) ( $row['keys'] ?? array() ) as $key ) {
				$keys[] = (string) $key;
			}

			$row_clicks      = (float) ( $row['clicks'] ?? 0 );
			$row_impressions = (float) ( $row['impressions'] ?? 0 );

			$rows[] = array(
				'keys'        => $keys,
				'clicks'      => $row_clicks,
				'impressions' => $row_impressions,
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);

			$clicks            += $row_clicks;
			$impressions       += $row_impressions;
			$position_weighted += (float) ( $row['position'] ?? 0 ) * $row_impressions;
			$max_rows          += 1;
		}

		// The API returns "top rows", not every row. Saying so explicitly is the
		// difference between an honest report and a misleading total.
		$totals = array(
			'clicks'        => $clicks,
			'impressions'   => $impressions,
			'ctr'           => $impressions > 0 ? $clicks / $impressions : 0.0,
			'position'      => $impressions > 0 ? $position_weighted / $impressions : 0.0,
			'rows_returned' => $max_rows,
			'row_limit'     => $row_limit,
			'truncated'     => $max_rows >= $row_limit,
		);

		$result = array(
			'property'    => $property,
			'start_date'  => $start,
			'end_date'    => $end,
			'dimensions'  => $dimensions,
			'rows'        => $rows,
			'totals'      => $totals,
			'from_cache'  => false,
			'source'      => 'Google Search Console API',
			'fetched_at'  => current_time( 'mysql' ),
			'note'        => $totals['truncated']
				? 'داده‌های Search Console محدود است و API فقط ردیف‌های برتر را برمی‌گرداند؛ جمع‌ها بر همین مجموعه محاسبه شده‌اند.'
				: 'داده مستقیم از API گوگل Search Console دریافت شده است.',
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Queries whose position is good but click-through rate is low.
	 *
	 * This is deliberately a *measured* observation — the query has enough
	 * impressions to be meaningful and sits on a results page where titles and
	 * descriptions are what decide the click — not a claim about ranking.
	 *
	 * @param int $days  Look-back window.
	 * @param int $limit Maximum rows.
	 * @return array|WP_Error
	 */
	public function improvement_opportunities( $days = 28, $limit = 20 ) {
		$data = $this->query(
			array(
				'days'       => $days,
				'dimensions' => array( 'query' ),
				'row_limit'  => 200,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$opportunities = array();

		foreach ( $data['rows'] as $row ) {
			$impressions = (float) $row['impressions'];
			$position    = (float) $row['position'];
			$ctr         = (float) $row['ctr'];

			if ( $impressions < 50 ) {
				continue;
			}

			if ( $position <= 12 && $ctr < 0.03 ) {
				$opportunities[] = array(
					'query'        => (string) ( $row['keys'][0] ?? '' ),
					'impressions'  => $impressions,
					'clicks'       => (float) $row['clicks'],
					'ctr'          => $ctr,
					'position'     => $position,
					'observation'  => sprintf(
						'این عبارت %s نمایش و %s کلیک داشته است (نرخ کلیک ٪%s) در حالی که میانگین جایگاه آن %s است.',
						number_format_i18n( (int) $impressions ),
						number_format_i18n( (int) $row['clicks'] ),
						number_format_i18n( round( $ctr * 100, 1 ) ),
						number_format_i18n( round( $position, 1 ) )
					),
					'suggestion'   => 'عنوان و توضیحات متای صفحه‌ای که برای این عبارت نمایش داده می‌شود را بازبینی کنید؛ بهبود متن نتیجه، مستقل از جایگاه، نرخ کلیک را تغییر می‌دهد.',
				);
			}
		}

		usort(
			$opportunities,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);

		return array(
			'days'          => $days,
			'property'      => $data['property'],
			'items'         => array_slice( $opportunities, 0, $limit ),
			'analysed_rows' => count( $data['rows'] ),
			'criteria'      => 'عبارت‌هایی با حداقل ۵۰ نمایش، میانگین جایگاه ۱۲ یا بهتر، و نرخ کلیک کمتر از ۳٪.',
			'disclaimer'    => 'این معیارها از داده واقعی Search Console محاسبه شده‌اند و مشاهدات آماری‌اند؛ تضمینی درباره نتیجه تغییرات ارائه نمی‌شود.',
		);
	}

	/**
	 * Clear cached Search Console responses.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		global $wpdb;

		delete_transient( 'avandwp_gsc_sites' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_avandwp_gsc_' ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_avandwp_gsc_' ) . '%'
			)
		);
	}
}
