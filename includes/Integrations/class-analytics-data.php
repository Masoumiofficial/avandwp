<?php
/**
 * Google Analytics 4 Data API access.
 *
 * Two rules govern this class:
 *
 *   1. It reads only. The requested scope is `analytics.readonly`; no GA4
 *      configuration is ever created, modified or deleted by this plugin.
 *   2. It never invents a number. GA4 reports have a "thresholding" behaviour —
 *      rows derived from a small number of users are withheld — and the API
 *      returns an explicit `dataLossFromOtherRow` / `subjectToThresholding`
 *      signal. That signal is passed through to the UI instead of being hidden,
 *      because a silently incomplete total is worse than no total.
 *
 * Endpoints verified against Google's documentation on
 * `AvandWP_Google_Auth::VERIFIED_ON`:
 *   - `GET  https://analyticsadmin.googleapis.com/v1beta/accountSummaries`
 *   - `POST https://analyticsdata.googleapis.com/v1beta/properties/{propertyId}:runReport`
 *   - scope `https://www.googleapis.com/auth/analytics.readonly`
 *
 * @package AvandWP\Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GA4 Data API reporting.
 */
class AvandWP_Analytics_Data {

	/**
	 * Admin API base, for property discovery (verified).
	 */
	const ADMIN_BASE = 'https://analyticsadmin.googleapis.com/v1beta';

	/**
	 * Data API base, for reporting (verified).
	 */
	const DATA_BASE = 'https://analyticsdata.googleapis.com/v1beta';

	/**
	 * Cache lifetime for report results.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Maximum rows requested from the Data API.
	 */
	const MAX_ROWS = 200;

	/**
	 * Metrics this plugin asks for, with Persian labels for the UI.
	 *
	 * @return array<string, string>
	 */
	public static function metric_labels() {
		return array(
			'sessions'                => 'نشست‌ها',
			'totalUsers'              => 'کاربران',
			'screenPageViews'         => 'بازدید صفحات',
			'averageSessionDuration'  => 'میانگین مدت نشست (ثانیه)',
			'engagementRate'          => 'نرخ تعامل',
			'keyEvents'               => 'رویدادهای کلیدی',
		);
	}

	/**
	 * List GA4 properties the connected account can read.
	 *
	 * @return array<int, array{property: string, property_id: string, account: string, name: string}>|WP_Error
	 */
	public function list_properties() {
		$cached = get_transient( 'avandwp_ga4_properties' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$body = AvandWP_Google_Auth::get(
			self::ADMIN_BASE . '/accountSummaries',
			array( 'pageSize' => 200 )
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$properties = array();

		foreach ( (array) ( $body['accountSummaries'] ?? array() ) as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			$account_name = (string) ( $account['displayName'] ?? '' );

			foreach ( (array) ( $account['propertySummaries'] ?? array() ) as $property ) {
				if ( ! is_array( $property ) || empty( $property['property'] ) ) {
					continue;
				}

				$resource = (string) $property['property'];

				$properties[] = array(
					'property'    => $resource,
					'property_id' => preg_replace( '#^properties/#', '', $resource ),
					'account'     => $account_name,
					'name'        => (string) ( $property['displayName'] ?? $resource ),
				);
			}
		}

		set_transient( 'avandwp_ga4_properties', $properties, self::CACHE_TTL );

		return $properties;
	}

	/**
	 * The GA4 property configured for this site.
	 *
	 * @return string|WP_Error Property id, or an error explaining what is missing.
	 */
	public function selected_property() {
		$settings = get_option( 'avandwp_settings', array() );
		$selected = (string) ( $settings['ga4_property_id'] ?? '' );

		if ( '' === $selected ) {
			return new WP_Error(
				'avandwp_ga4_no_property',
				'شناسه پراپرتی GA4 تنظیم نشده است. پس از اتصال حساب گوگل، پراپرتی مورد نظر را در تنظیمات انتخاب کنید.'
			);
		}

		if ( ! preg_match( '/^\d+$/', $selected ) ) {
			return new WP_Error(
				'avandwp_ga4_bad_property',
				'شناسه پراپرتی GA4 باید فقط رقم باشد (مثال: 123456789). مقدار «properties/…» یا نام پراپرتی پذیرفته نمی‌شود.'
			);
		}

		return $selected;
	}

	/**
	 * Run a GA4 report.
	 *
	 * @param array $args {
	 *     @type array  $metrics    Metric names.
	 *     @type array  $dimensions Dimension names.
	 *     @type int    $days       Look-back window.
	 *     @type int    $row_limit  Maximum rows.
	 *     @type array  $order_by   Optional orderBys passthrough.
	 * }
	 * @return array|WP_Error
	 */
	public function run_report( array $args = array() ) {
		$property = $this->selected_property();

		if ( is_wp_error( $property ) ) {
			return $property;
		}

		$days = max( 1, min( 365, (int) ( $args['days'] ?? 28 ) ) );

		$metrics = array();

		foreach ( (array) ( $args['metrics'] ?? array( 'sessions', 'totalUsers', 'screenPageViews' ) ) as $metric ) {
			$metric = (string) $metric;

			// Only metrics this plugin declares are requested: an arbitrary
			// metric name would produce an API error the user cannot act on.
			if ( isset( self::metric_labels()[ $metric ] ) ) {
				$metrics[] = array( 'name' => $metric );
			}
		}

		if ( empty( $metrics ) ) {
			$metrics = array( array( 'name' => 'sessions' ), array( 'name' => 'totalUsers' ) );
		}

		$dimensions = array();

		foreach ( (array) ( $args['dimensions'] ?? array() ) as $dimension ) {
			$dimension = (string) $dimension;

			if ( in_array( $dimension, array( 'date', 'pagePath', 'sessionDefaultChannelGroup', 'deviceCategory', 'country' ), true ) ) {
				$dimensions[] = array( 'name' => $dimension );
			}
		}

		$row_limit = max( 1, min( self::MAX_ROWS, (int) ( $args['row_limit'] ?? 25 ) ) );

		$payload = array(
			'dateRanges' => array(
				array(
					'startDate' => $days . 'daysAgo',
					'endDate'   => 'today',
				),
			),
			'metrics'   => $metrics,
			'dimensions' => $dimensions,
			'limit'     => $row_limit,
		);

		if ( ! empty( $args['order_by'] ) ) {
			$payload['orderBys'] = $args['order_by'];
		} elseif ( in_array( 'date', array_column( $dimensions, 'name' ), true ) ) {
			$payload['orderBys'] = array(
				array( 'dimension' => array( 'dimensionName' => 'date' ) ),
			);
		}

		$cache_key = 'avandwp_ga4_' . md5( wp_json_encode( array( $property, $payload ) ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$cached['from_cache'] = true;

			return $cached;
		}

		$endpoint = sprintf( '%s/properties/%s:runReport', self::DATA_BASE, rawurlencode( $property ) );

		$body = AvandWP_Google_Auth::post( $endpoint, $payload );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$metric_headers   = array();
		$dimension_headers = array();

		foreach ( (array) ( $body['metricHeaders'] ?? array() ) as $header ) {
			$metric_headers[] = (string) ( $header['name'] ?? '' );
		}

		foreach ( (array) ( $body['dimensionHeaders'] ?? array() ) as $header ) {
			$dimension_headers[] = (string) ( $header['name'] ?? '' );
		}

		$rows = array();

		foreach ( (array) ( $body['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$parsed = array( 'dimensions' => array(), 'metrics' => array() );

			foreach ( (array) ( $row['dimensionValues'] ?? array() ) as $index => $value ) {
				$parsed['dimensions'][ $dimension_headers[ $index ] ?? ( 'dimension_' . $index ) ] = (string) ( $value['value'] ?? '' );
			}

			foreach ( (array) ( $row['metricValues'] ?? array() ) as $index => $value ) {
				$name = $metric_headers[ $index ] ?? ( 'metric_' . $index );
				// GA4 returns every value as a string, including counts and
				// rates. Parsing to float here means the UI cannot display "1"
				// where the API said "1.0"; the raw string is kept as evidence.
				$parsed['metrics'][ $name ] = array(
					'value' => is_numeric( $value['value'] ?? '' ) ? (float) $value['value'] : null,
					'raw'   => (string) ( $value['value'] ?? '' ),
				);
			}

			$rows[] = $parsed;
		}

		$totals = array();

		foreach ( (array) ( $body['totals'] ?? array() ) as $total ) {
			foreach ( (array) ( $total['metricValues'] ?? array() ) as $index => $value ) {
				$name = $metric_headers[ $index ] ?? ( 'metric_' . $index );
				$totals[ $name ] = array(
					'value' => is_numeric( $value['value'] ?? '' ) ? (float) $value['value'] : null,
					'raw'   => (string) ( $value['value'] ?? '' ),
				);
			}
		}

		// These two flags are the API telling us the data is incomplete. They are
		// surfaced, never swallowed.
		$thresholded   = ! empty( $body['metadata']['subjectToThresholding'] );
		$has_other_row = isset( $body['rows'] ) && $this->has_other_row( (array) $body['rows'] );

		$result = array(
			'property'      => (string) $property,
			'property_name' => $this->property_label( (string) $property ),
			'days'          => $days,
			'metrics'       => $metric_headers,
			'dimensions'    => $dimension_headers,
			'rows'          => $rows,
			'totals'        => $totals,
			'from_cache'    => false,
			'source'        => 'Google Analytics 4 Data API',
			'fetched_at'    => current_time( 'mysql' ),
			'completeness'  => array(
				'subject_to_thresholding' => $thresholded,
				'has_other_row'           => $has_other_row,
				'note'                    => ( $thresholded || $has_other_row )
					? 'گوگل بخشی از داده‌های این گزارش را به دلیل آستانه‌گذاری کاربران یا جمع‌کردن ردیف‌ها در «(other)» پنهان کرده است؛ بنابراین جمع‌ها کامل نیستند و نباید به‌عنوان کل بازدید سایت خوانده شوند.'
					: 'گوگل برای این گزارش نشانه‌ای از پنهان‌سازی داده علامت نزده است.',
			),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Does the response contain GA4's aggregated "(other)" row?
	 *
	 * @param array $rows Raw rows.
	 * @return bool
	 */
	private function has_other_row( array $rows ) {
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['dimensionValues'] ?? array() ) as $value ) {
				if ( '(other)' === (string) ( $value['value'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Human label for a property id, when the account summary is available.
	 *
	 * @param string $property_id Property id.
	 * @return string
	 */
	private function property_label( $property_id ) {
		$properties = $this->list_properties();

		if ( is_wp_error( $properties ) ) {
			return '';
		}

		foreach ( $properties as $property ) {
			if ( (string) $property['property_id'] === (string) $property_id ) {
				return $property['name'] . ' — ' . $property['account'];
			}
		}

		return '';
	}

	/**
	 * Traffic overview shaped for the dashboard.
	 *
	 * @param int $days Look-back window.
	 * @return array|WP_Error
	 */
	public function overview( $days = 28 ) {
		$report = $this->run_report(
			array(
				'days'     => $days,
				'metrics'  => array( 'sessions', 'totalUsers', 'screenPageViews', 'averageSessionDuration', 'engagementRate', 'keyEvents' ),
				'dimensions' => array(),
				'row_limit'  => 1,
			)
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		return array(
			'property'     => $report['property_name'] ? $report['property_name'] : $report['property'],
			'days'         => $report['days'],
			'totals'       => $report['totals'],
			'labels'       => self::metric_labels(),
			'completeness' => $report['completeness'],
			'source'       => $report['source'],
			'fetched_at'   => $report['fetched_at'],
		);
	}

	/**
	 * Top landing pages by organic sessions.
	 *
	 * @param int $days  Look-back window.
	 * @param int $limit Maximum rows.
	 * @return array|WP_Error
	 */
	public function top_landing_pages( $days = 28, $limit = 20 ) {
		$report = $this->run_report(
			array(
				'days'       => $days,
				'metrics'    => array( 'sessions', 'screenPageViews', 'engagementRate' ),
				'dimensions' => array( 'pagePath' ),
				'row_limit'  => $limit,
				'order_by'   => array(
					array(
						'metric' => array( 'metricName' => 'sessions' ),
						'desc'   => true,
					),
				),
			)
		);

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$pages = array();

		foreach ( $report['rows'] as $row ) {
			$path = (string) ( $row['dimensions']['pagePath'] ?? '' );

			if ( '' === $path ) {
				continue;
			}

			$pages[] = array(
				'path'         => $path,
				'sessions'     => $row['metrics']['sessions']['value'] ?? null,
				'pageviews'    => $row['metrics']['screenPageViews']['value'] ?? null,
				'engagement'   => $row['metrics']['engagementRate']['value'] ?? null,
			);
		}

		return array(
			'property'     => $report['property_name'] ? $report['property_name'] : $report['property'],
			'days'         => $report['days'],
			'pages'        => $pages,
			'completeness' => $report['completeness'],
			'note'         => 'نشست‌ها به‌صورت کلی گزارش شده‌اند، نه فقط ورودی جستجوی ارگانیک؛ برای تفکیک منبع ورودی، بُعد کانال پیش‌فرض نشست را اضافه کنید.',
		);
	}

	/**
	 * Clear cached GA4 responses.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		global $wpdb;

		delete_transient( 'avandwp_ga4_properties' );

		foreach ( array( '_transient_avandwp_ga4_', '_transient_timeout_avandwp_ga4_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}

	/**
	 * Combined readiness report for both Google integrations.
	 *
	 * @return array<string, mixed>
	 */
	public static function readiness_report() {
		$auth = AvandWP_Google_Auth::status();

		$report = array(
			'auth'          => $auth,
			'gsc'           => array( 'state' => 'unknown', 'message' => '' ),
			'ga4'           => array( 'state' => 'unknown', 'message' => '' ),
			'blocked_notes' => array(),
		);

		if ( ! $auth['configured'] ) {
			$report['gsc'] = array(
				'state'   => 'blocked_by_credentials',
				'message' => 'برای فعال‌سازی، Client ID و Client Secret یک پروژه Google Cloud را در تنظیمات وارد کنید. تا آن زمان هیچ داده‌ای از Search Console نمایش داده نمی‌شود.',
			);
			$report['ga4'] = array(
				'state'   => 'blocked_by_credentials',
				'message' => 'برای فعال‌سازی، ابتدا اتصال گوگل را کامل و سپس شناسه پراپرتی GA4 را انتخاب کنید.',
			);

			$report['blocked_notes'] = array(
				'این افزونه تا زمانی که اعتبارنامه OAuth ثبت نشود و اتصال انجام نشود، هیچ داده‌ای از Search Console یا Analytics نمایش نمی‌دهد — نه مقدار صفر و نه مقدار نمونه.',
				'دلیل: نمایش مقدار صفر به‌جای «داده موجود نیست» می‌تواند به تصمیم‌گیری نادرست درباره محتوا منجر شود.',
			);

			return $report;
		}

		if ( ! $auth['connected'] ) {
			$report['gsc'] = array(
				'state'   => 'ready_to_connect',
				'message' => 'اعتبارنامه‌ها ثبت شده‌اند؛ برای دریافت داده، دکمه «اتصال به حساب گوگل» را بزنید.',
			);
			$report['ga4'] = $report['gsc'];

			return $report;
		}

		$sc = new AvandWP_Search_Console();
		$sites = $sc->list_sites();

		if ( is_wp_error( $sites ) ) {
			$report['gsc'] = array( 'state' => 'error', 'message' => $sites->get_error_message() );
		} elseif ( empty( $sites ) ) {
			$report['gsc'] = array(
				'state'   => 'no_property_access',
				'message' => 'حساب متصل به هیچ پرتالی در Search Console دسترسی ندارد. دسترسی را در Search Console بررسی کنید.',
			);
		} else {
			$report['gsc'] = array(
				'state'   => 'connected',
				'message' => sprintf( '%d پرتال در دسترس است.', count( $sites ) ),
				'sites'   => $sites,
			);
		}

		$ga = new AvandWP_Analytics_Data();
		$properties = $ga->list_properties();

		if ( is_wp_error( $properties ) ) {
			$report['ga4'] = array( 'state' => 'error', 'message' => $properties->get_error_message() );
		} elseif ( empty( $properties ) ) {
			$report['ga4'] = array(
				'state'   => 'no_property_access',
				'message' => 'حساب متصل به هیچ پراپرتی GA4 دسترسی خواندنی ندارد.',
			);
		} else {
			$selected = (string) ( get_option( 'avandwp_settings', array() )['ga4_property_id'] ?? '' );

			$report['ga4'] = array(
				'state'      => '' !== $selected ? 'connected' : 'needs_property_selection',
				'message'    => '' !== $selected
					? sprintf( 'پراپرتی %s انتخاب شده است.', $selected )
					: 'پراپرتی مورد نظر را از فهرست زیر انتخاب کنید.',
				'properties' => $properties,
			);
		}

		return $report;
	}
}
