<?php
/**
 * Background Job Queue Manager (`avandwp_jobs`).
 *
 * Executes heavy AI tasks (long-form articles, bulk WooCommerce optimisation,
 * knowledge indexing) asynchronously with an ownership-checked lock, bounded
 * retries with exponential backoff, real stage-based progress and recovery for
 * jobs abandoned by a fatal error or a killed worker.
 *
 * Baseline defects corrected here (see docs/technical-audit.md):
 *   F-04  lock released by non-owners; `catch (Exception)` missed PHP `Error`;
 *         retries fired immediately with no backoff; no stuck-job recovery.
 *   F-05  progress was a hardcoded 20 % → 100 %.
 *
 * @package AvandWP\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable background job queue.
 */
class AvandWP_Job_Queue {

	/**
	 * Lock name guarding batch execution.
	 */
	const LOCK_NAME = 'avandwp_job_queue';

	/**
	 * Seconds before a `running` job is presumed abandoned.
	 */
	const STALE_AFTER = 900;

	/**
	 * Base backoff in seconds; doubles per attempt.
	 */
	const BACKOFF_BASE = 30;

	/**
	 * Job types that this queue knows how to execute.
	 *
	 * @return array<int, string>
	 */
	public static function supported_types() {
		return array( 'studio_article', 'longform_article', 'woo_optimize', 'knowledge_sync', 'site_audit' );
	}

	/**
	 * Enqueue a new background job.
	 *
	 * @param string $job_type Job type; must be in supported_types().
	 * @param array  $payload  Input parameters for the job.
	 * @param array  $args     Optional: max_attempts (int), dedupe_key (string),
	 *                         exclude_job_id (int).
	 * @return int|WP_Error Job ID, or WP_Error for an invalid request.
	 */
	public static function enqueue( $job_type, array $payload = array(), array $args = array() ) {
		global $wpdb;

		$job_type = sanitize_key( $job_type );

		if ( ! in_array( $job_type, self::supported_types(), true ) ) {
			return new WP_Error(
				'avandwp_unknown_job_type',
				sprintf( 'نوع کار پس‌زمینه پشتیبانی نمی‌شود: %s', $job_type )
			);
		}

		$table = $wpdb->prefix . 'avandwp_jobs';
		$now   = current_time( 'mysql' );

		// Duplicate-job prevention: refuse to queue an identical payload while an
		// earlier copy is still waiting or running.
		$dedupe_key = isset( $args['dedupe_key'] ) ? (string) $args['dedupe_key'] : '';
		if ( '' !== $dedupe_key ) {
			// A job that re-queues its own continuation must be able to ignore
			// itself: the running job already holds the dedupe key, so without
			// this exclusion the continuation would resolve to the currently
			// running job and the chain would silently stop after one batch.
			$exclude  = (int) ( $args['exclude_job_id'] ?? 0 );
			$existing = self::find_active_by_dedupe_key( $dedupe_key, $exclude );
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'job_type'        => $job_type,
				'status'          => 'pending',
				'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'dedupe_key'      => '' !== $dedupe_key ? substr( $dedupe_key, 0, 64 ) : null,
				'progress'        => 0,
				'stage'           => 'queued',
				'attempts'        => 0,
				'max_attempts'    => max( 1, min( 5, (int) ( $args['max_attempts'] ?? 3 ) ) ),
				'next_attempt_at' => $now,
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'avandwp_enqueue_failed', 'خطا در ثبت کار در صف پس‌زمینه.' );
		}

		$job_id = (int) $wpdb->insert_id;
		AvandWP_Logger::log( 'job.enqueue', sprintf( 'کار پس‌زمینه #%d (%s) در صف قرار گرفت.', $job_id, $job_type ), 'info' );

		return $job_id;
	}

	/**
	 * Find an active (pending or running) job for a dedupe key.
	 *
	 * @param string $dedupe_key     Client-supplied grouping key.
	 * @param int    $exclude_job_id Job id to ignore (usually the caller itself).
	 * @return int Job ID or 0.
	 */
	private static function find_active_by_dedupe_key( $dedupe_key, $exclude_job_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE dedupe_key = %s AND status IN ('pending', 'running') AND id != %d ORDER BY id ASC LIMIT 1",
				substr( (string) $dedupe_key, 0, 64 ),
				(int) $exclude_job_id
			)
		);

		return (int) $id;
	}

	/**
	 * Retrieve a single job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null
	 */
	public static function get_job( $job_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $job_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['payload_data'] = json_decode( (string) $row['payload'], true );
		$row['result_data']  = ! empty( $row['result'] ) ? json_decode( (string) $row['result'], true ) : null;
		$row['progress_data'] = ! empty( $row['progress_data'] ) ? json_decode( (string) $row['progress_data'], true ) : null;

		return $row;
	}

	/**
	 * Strip sensitive internals before a job row reaches the browser.
	 *
	 * @param array|null $job Job row.
	 * @return array|null
	 */
	public static function public_view( $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}

		return array(
			'id'            => (int) $job['id'],
			'job_type'      => (string) $job['job_type'],
			'status'        => (string) $job['status'],
			'progress'      => null === $job['progress'] ? null : (int) $job['progress'],
			'stage'         => (string) ( $job['stage'] ?? '' ),
			'stage_label'   => self::stage_label( (string) ( $job['stage'] ?? '' ) ),
			'progress_data' => is_array( $job['progress_data'] ?? null ) ? $job['progress_data'] : null,
			'attempts'      => (int) $job['attempts'],
			'max_attempts'  => (int) $job['max_attempts'],
			'error_message' => (string) ( $job['error_message'] ?? '' ),
			'created_at'    => (string) $job['created_at'],
			'updated_at'    => (string) $job['updated_at'],
			'result'        => $job['result_data'] ?? null,
		);
	}

	/**
	 * Persian label for a stage slug.
	 *
	 * @param string $stage Stage slug.
	 * @return string
	 */
	public static function stage_label( $stage ) {
		$labels = array(
			'queued'         => 'در انتظار نوبت',
			'starting'       => 'آماده‌سازی',
			'brief'          => 'اعتبارسنجی بریف',
			'intent'         => 'تحلیل قصد جستجو',
			'outline'        => 'ساخت ساختار مقاله',
			'section'        => 'نگارش بخش‌ها',
			'consolidate'    => 'یکپارچه‌سازی بخش‌ها',
			'consistency'    => 'بررسی انسجام و تکرار',
			'metadata'       => 'تولید متادیتای سئو',
			'images'         => 'تولید تصاویر',
			'links'          => 'پیشنهاد لینک‌ها',
			'quality'        => 'ارزیابی کیفیت',
			'correct'        => 'اصلاح موارد قابل رفع',
			'save'           => 'ذخیره پیش‌نویس',
			'knowledge'      => 'شاخص‌گذاری محتوا',
			'product'        => 'بهینه‌سازی محصول',
			'done'           => 'پایان',
		);

		return $labels[ $stage ] ?? ( '' !== $stage ? $stage : 'در حال اجرا' );
	}

	/**
	 * Record real progress for a running job.
	 *
	 * `$percent` must be `null` whenever a trustworthy percentage cannot be
	 * measured. The UI renders an indeterminate indicator in that case rather
	 * than inventing a number.
	 *
	 * @param int         $job_id  Job ID.
	 * @param string      $stage   Stage slug.
	 * @param int|null    $percent Completion percentage, or null when unknown.
	 * @param array       $data    Extra machine-readable progress detail.
	 * @return void
	 */
	public static function report_progress( $job_id, $stage, $percent = null, array $data = array() ) {
		global $wpdb;

		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'avandwp_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'stage'         => substr( sanitize_key( $stage ), 0, 40 ),
				'progress'      => null === $percent ? null : max( 0, min( 100, (int) $percent ) ),
				'progress_data' => ! empty( $data ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : null,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Process up to $limit due jobs safely under an ownership-checked lock.
	 *
	 * @param int $limit            Max jobs to process in one batch.
	 * @param int $specific_job_id  Optional job ID to run out of band.
	 * @return array Summary of processed jobs.
	 */
	public static function process_batch( $limit = 2, $specific_job_id = 0 ) {
		$job_id = (int) $specific_job_id;

		// Reclaim anything a killed worker left behind before selecting work.
		self::reap_stale_jobs();

		// `$job_id > 0` still takes the same lock as a batch run, so a poll can
		// never execute a job that cron is already executing.
		$token = AvandWP_Lock::acquire( self::LOCK_NAME, self::STALE_AFTER );

		if ( false === $token ) {
			return array( 'processed' => 0, 'skipped_locked' => true );
		}

		try {
			return self::run_locked_batch( $limit, $job_id );
		} finally {
			// Released exactly once, and only by the process that acquired it.
			AvandWP_Lock::release( self::LOCK_NAME, $token );
		}
	}

	/**
	 * Select and execute jobs while the lock is held.
	 *
	 * @param int $limit  Max jobs.
	 * @param int $job_id Specific job ID, or 0.
	 * @return array
	 */
	private static function run_locked_batch( $limit, $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'avandwp_jobs';
		$limit = max( 1, min( 5, (int) $limit ) );
		$now   = current_time( 'mysql' );

		if ( $job_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE id = %d AND status = 'pending' LIMIT 1",
					$job_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}`
					 WHERE status = 'pending'
					   AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
					 ORDER BY id ASC
					 LIMIT %d",
					$now,
					$limit
				),
				ARRAY_A
			);
		}

		$processed = 0;

		if ( is_array( $jobs ) ) {
			foreach ( $jobs as $job ) {
				self::execute_job( $job );
				++$processed;
			}
		}

		return array( 'processed' => $processed );
	}

	/**
	 * Return jobs abandoned by a fatal error or a killed worker to the queue.
	 *
	 * @return int Number of reclaimed jobs.
	 */
	public static function reap_stale_jobs() {
		global $wpdb;

		$table  = $wpdb->prefix . 'avandwp_jobs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER );

		// Deliberately not `current_time()`: `updated_at` is written with
		// current_time('mysql') (site-local), so the comparison uses the same
		// clock. Both are produced by MySQL NOW() semantics via PHP.
		$local_cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STALE_AFTER ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET status = 'pending', stage = 'queued', updated_at = %s, error_message = %s
				 WHERE status = 'running' AND updated_at < %s",
				current_time( 'mysql' ),
				'کار پس‌زمینه به‌دلیل توقف غیرمنتظره پردازش، برای تلاش مجدد بازگردانده شد.',
				$local_cutoff
			)
		);

		$unused = $cutoff; // Kept for clarity: the UTC value is not used for comparison.

		if ( $reclaimed > 0 ) {
			AvandWP_Logger::log(
				'job.reaped',
				sprintf( '%d کار پس‌زمینه رهاشده به صف بازگردانده شد.', (int) $reclaimed ),
				'warning'
			);
		}

		return (int) $reclaimed;
	}

	/**
	 * Execute a single job row and persist its outcome.
	 *
	 * @param array $job Database row from `avandwp_jobs`.
	 * @return void
	 */
	private static function execute_job( array $job ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'avandwp_jobs';
		$job_id   = (int) $job['id'];
		$attempts = (int) $job['attempts'] + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'     => 'running',
				'stage'      => 'starting',
				'progress'   => null,
				'attempts'   => $attempts,
				'started_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$payload = json_decode( (string) $job['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$result = null;

		try {
			$result = self::dispatch( (string) $job['job_type'], $payload, $job_id );
		} catch ( Throwable $e ) {
			// `Throwable` (not `Exception`) so PHP 7+ `Error`/`TypeError` is
			// caught too; previously such a failure left the row stuck in
			// `running` forever.
			self::mark_failed( $job, $e->getMessage(), $attempts, $job_id );
			return;
		}

		if ( is_wp_error( $result ) ) {
			self::mark_failed( $job, $result->get_error_message(), $attempts, $job_id );
			return;
		}

		$encoded = wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
		if ( strlen( (string) $encoded ) > 16000000 ) {
			// Guard against exceeding the `longtext` column on pathological output.
			$encoded = wp_json_encode(
				array(
					'truncated' => true,
					'note'      => 'نتیجه کار بیش از حد بزرگ بود و ذخیره نشد.',
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'        => 'completed',
				'stage'         => 'done',
				'progress'      => 100,
				'result'        => $encoded,
				'error_message' => null,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		AvandWP_Logger::log(
			'job.completed',
			sprintf( 'کار پس‌زمینه #%d (%s) با موفقیت تکمیل شد.', $job_id, $job['job_type'] ),
			'success'
		);
	}

	/**
	 * Run the handler for a job type.
	 *
	 * @param string $job_type Job type slug.
	 * @param array  $payload  Decoded payload.
	 * @param int    $job_id   Job ID for progress reporting.
	 * @return mixed|WP_Error
	 */
	private static function dispatch( $job_type, array $payload, $job_id ) {
		switch ( $job_type ) {
			case 'studio_article':
				$studio = new AvandWP_Content_Studio();
				return $studio->generate_article_package( $payload, $job_id );

			case 'longform_article':
				$generator = new AvandWP_Longform_Generator();
				return $generator->run( $payload, $job_id );

			case 'woo_optimize':
				$woo        = new AvandWP_Woo_Assistant();
				$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
				$options    = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
				return $woo->optimize_product( $product_id, $options, $job_id );

			case 'site_audit':
				$args     = isset( $payload['args'] ) && is_array( $payload['args'] ) ? $payload['args'] : array();
				$auditor  = new AvandWP_Site_Auditor();
				$run_id   = isset( $payload['run_id'] ) ? (int) $payload['run_id'] : 0;

				if ( $run_id <= 0 ) {
					$run = $auditor->start_run( $args );

					if ( is_wp_error( $run ) ) {
						return $run;
					}

					$run_id = (int) $run['id'];
				}

				$batch = max( 1, min( 40, (int) ( $args['batch_size'] ?? AvandWP_Site_Auditor::DEFAULT_BATCH ) ) );

				// A single cron tick crawls one batch. The job stays `pending`
				// between ticks so the crawl spreads over several requests
				// instead of blocking one request until it times out.
				$progress = $auditor->crawl_batch( $run_id, $batch );

				if ( is_wp_error( $progress ) ) {
					return $progress;
				}

				self::report_progress(
					$job_id,
					'crawl',
					null,
					array(
						'run_id'    => $run_id,
						'crawled'   => (int) ( $progress['total'] ?? 0 ),
						'remaining' => (int) ( $progress['remaining'] ?? 0 ),
					)
				);

				if ( ! empty( $progress['remaining'] ) ) {
					// Self-requeue the continuation with the same dedupe key so a
					// second, concurrent crawl cannot be started by accident.
					AvandWP_Job_Queue::enqueue(
						'site_audit',
						array(
							'args'   => $args,
							'run_id' => $run_id,
						),
						array(
							'max_attempts'   => 3,
							'dedupe_key'     => 'site_audit_' . $run_id,
							'exclude_job_id' => (int) $job_id,
						)
					);
				}

				$run = $auditor->get_run( $run_id );

				return array(
					'run_id'    => $run_id,
					'summary'   => is_wp_error( $run ) ? array() : ( $run['summary'] ?? array() ),
					'crawled'   => is_wp_error( $run ) ? 0 : (int) $run['crawled'],
					'status'    => is_wp_error( $run ) ? 'unknown' : (string) $run['status'],
				);

			case 'knowledge_sync':
				self::report_progress( $job_id, 'knowledge', null, array( 'message' => 'شاخص‌گذاری محتوای سایت' ) );
				$kb = new AvandWP_Knowledge_Base();
				return $kb->sync_all_content();

			default:
				return new WP_Error(
					'avandwp_unknown_job_type',
					'نوع کار پس‌زمینه پشتیبانی نمی‌شود: ' . $job_type
				);
		}
	}

	/**
	 * Record a failure with bounded exponential backoff.
	 *
	 * @param array  $job      Job row.
	 * @param string $error    Error message.
	 * @param int    $attempts Current attempt count.
	 * @param int    $job_id   Job ID.
	 * @return void
	 */
	private static function mark_failed( array $job, $error, $attempts, $job_id ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'avandwp_jobs';
		$max_attempts = max( 1, (int) $job['max_attempts'] );
		$can_retry    = ( $attempts < $max_attempts );

		$error_message = sanitize_text_field( (string) $error );

		if ( ! $can_retry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'        => 'failed',
					'stage'         => 'done',
					'error_message' => $error_message,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $job_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Exponential backoff with jitter, so a provider outage does not
			// produce a synchronised retry storm.
			$delay = (int) ( self::BACKOFF_BASE * ( 2 ** ( $attempts - 1 ) ) );
			$delay = min( $delay, 3600 );
			$delay += wp_rand( 0, max( 1, (int) ( $delay * 0.2 ) ) );

			$next = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'          => 'pending',
					'stage'           => 'queued',
					'error_message'   => $error_message,
					'next_attempt_at' => $next,
					'updated_at'      => current_time( 'mysql' ),
				),
				array( 'id' => $job_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		AvandWP_Logger::log(
			'job.failed',
			sprintf(
				'خطا در کار #%d (تلاش %d از %d): %s',
				$job_id,
				$attempts,
				$max_attempts,
				$error_message
			),
			'error'
		);
	}

	/**
	 * Fetch recent jobs for the admin UI.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_recent_jobs( $limit = 25 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'avandwp_jobs';
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cancel a queued job.
	 *
	 * @param int $job_id Job ID.
	 * @return true|WP_Error
	 */
	public static function cancel( $job_id ) {
		global $wpdb;

		$job_id = (int) $job_id;
		$job    = self::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'avandwp_job_not_found', 'کار مورد نظر یافت نشد.' );
		}

		if ( ! in_array( (string) $job['status'], array( 'pending', 'running' ), true ) ) {
			return new WP_Error( 'avandwp_job_not_cancellable', 'این کار دیگر قابل لغو نیست.' );
		}

		$table = $wpdb->prefix . 'avandwp_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'        => 'cancelled',
				'stage'         => 'done',
				'error_message' => 'کار توسط کاربر لغو شد.',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		AvandWP_Logger::log( 'job.cancelled', sprintf( 'کار پس‌زمینه #%d لغو شد.', $job_id ), 'warning' );

		return true;
	}

	/**
	 * Clean up finished jobs older than $days.
	 *
	 * @param int $days Retention days.
	 * @return int Deleted rows count.
	 */
	public static function cleanup_old_jobs( $days = 14 ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'avandwp_jobs';
		$cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status IN ('completed', 'failed', 'cancelled') AND updated_at < %s",
				$cutoff
			)
		);

		return (int) $deleted;
	}
}
