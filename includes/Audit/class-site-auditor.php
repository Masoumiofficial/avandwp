<?php
/**
 * Site-wide SEO/AIO/GEO audit crawler.
 *
 * Scope and honesty rules this crawler obeys:
 *
 *   - It crawls **the site's own URLs only**. A URL discovered on a page is
 *     queued only when it resolves to the same host as `home_url()`; external
 *     links are counted, never fetched. That keeps the crawler from turning
 *     into an unbounded outbound scanner.
 *   - It respects `robots.txt` for the site, including `Disallow` rules and
 *     `Crawl-delay`, read from the site's own robots file.
 *   - It fetches a bounded number of URLs per run and stores every result, so
 *     an interrupted crawl resumes instead of starting over.
 *   - Every finding carries the evidence that produced it: the element, the
 *     URL it was found on, and the measured value. Nothing is reported as a
 *     problem without the observation behind it.
 *   - It never claims ranking impact. The score is an internal checklist score
 *     (see `docs/seo-scoring-model.md`) and the UI is required to say so.
 *
 * @package AvandWP\Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawl-and-audit engine for the site's own pages.
 */
class AvandWP_Site_Auditor {

	/**
	 * Crawl runs, one row per start-to-finish pass.
	 */
	const TABLE_RUNS = 'avandwp_audit_runs';

	/**
	 * Per-URL results, one row per fetched page.
	 */
	const TABLE_PAGES = 'avandwp_audit_pages';

	/**
	 * Default number of URLs fetched per run, sized for shared hosting.
	 */
	const DEFAULT_BATCH = 15;

	/**
	 * Hard ceiling on URLs in a single crawl.
	 */
	const MAX_URLS = 500;

	/**
	 * Maximum crawl depth from the start URL.
	 */
	const MAX_DEPTH = 4;

	/**
	 * Seconds to wait between requests inside one run.
	 */
	const POLITE_DELAY_SECONDS = 0.4;

	/**
	 * Pages under this word count are reported as thin content.
	 */
	const THIN_CONTENT_WORDS = 300;

	/**
	 * Run statuses.
	 */
	const STATUS_RUNNING  = 'running';
	const STATUS_PAUSED   = 'paused';
	const STATUS_COMPLETE = 'complete';
	const STATUS_FAILED   = 'failed';

	/**
	 * Check definitions: label, weight and what evidence each one produces.
	 *
	 * Weights are the crawler's own, documented in `docs/seo-scoring-model.md`.
	 * They are deliberately few and coarse: a crawler cannot judge content
	 * quality, so it must not pretend to. It measures the document.
	 *
	 * @return array<string, array{label: string, weight: int, profile: string}>
	 */
	public static function checks() {
		return array(
			'http_ok'          => array( 'label' => 'پاسخ موفق سرور (کد ۲۰۰)', 'weight' => 10, 'profile' => 'seo' ),
			'indexable'        => array( 'label' => 'قابل ایندکس (بدون noindex)', 'weight' => 10, 'profile' => 'seo' ),
			'canonical_self'   => array( 'label' => 'کانونیکال به خود صفحه', 'weight' => 6, 'profile' => 'seo' ),
			'title_present'    => array( 'label' => 'وجود تگ عنوان', 'weight' => 9, 'profile' => 'seo' ),
			'title_length'     => array( 'label' => 'طول مناسب عنوان (۲۰ تا ۶۵ کاراکتر)', 'weight' => 5, 'profile' => 'seo' ),
			'title_unique'     => array( 'label' => 'یکتایی عنوان در سایت', 'weight' => 7, 'profile' => 'seo' ),
			'description'      => array( 'label' => 'وجود توضیحات متا', 'weight' => 6, 'profile' => 'seo' ),
			'description_len'  => array( 'label' => 'طول مناسب توضیحات (۷۰ تا ۱۶۵ کاراکتر)', 'weight' => 4, 'profile' => 'seo' ),
			'description_unique' => array( 'label' => 'یکتایی توضیحات متا', 'weight' => 5, 'profile' => 'seo' ),
			'single_h1'        => array( 'label' => 'دقیقاً یک H1', 'weight' => 8, 'profile' => 'seo' ),
			'heading_order'    => array( 'label' => 'ترتیب منطقی هدینگ‌ها (بدون پرش سطح)', 'weight' => 5, 'profile' => 'seo' ),
			'content_depth'    => array( 'label' => 'عمق محتوا (حداقل ۳۰۰ کلمه)', 'weight' => 6, 'profile' => 'seo' ),
			'image_alt'        => array( 'label' => 'متن جانشین برای همه تصاویر', 'weight' => 6, 'profile' => 'aio' ),
			'internal_links'   => array( 'label' => 'حداقل یک لینک داخلی', 'weight' => 6, 'profile' => 'seo' ),
			'structured_data'  => array( 'label' => 'داده ساختاریافته در صفحه', 'weight' => 7, 'profile' => 'aio' ),
			'open_graph'       => array( 'label' => 'تگ‌های اشتراک‌گذاری (OG/Twitter)', 'weight' => 4, 'profile' => 'aio' ),
			'lang_attribute'   => array( 'label' => 'تعیین زبان صفحه (lang)', 'weight' => 4, 'profile' => 'aio' ),
			'viewport'         => array( 'label' => 'سازگاری موبایل (viewport)', 'weight' => 5, 'profile' => 'seo' ),
			'https'            => array( 'label' => 'بارگذاری روی HTTPS', 'weight' => 6, 'profile' => 'seo' ),
			'not_redirected'   => array( 'label' => 'بدون ریدایرکت زنجیره‌ای', 'weight' => 4, 'profile' => 'seo' ),
		);
	}

	// ---------------------------------------------------------------------
	// Table names
	// ---------------------------------------------------------------------

	/**
	 * Fully-qualified runs table name.
	 *
	 * @return string
	 */
	public static function runs_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_RUNS;
	}

	/**
	 * Fully-qualified pages table name.
	 *
	 * @return string
	 */
	public static function pages_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PAGES;
	}

	// ---------------------------------------------------------------------
	// Run lifecycle
	// ---------------------------------------------------------------------

	/**
	 * Start a new crawl run.
	 *
	 * @param array $args Optional: max_urls, batch_size, post_types.
	 * @return array|WP_Error Run row.
	 */
	public function start_run( array $args = array() ) {
		global $wpdb;

		$max_urls = max( 10, min( self::MAX_URLS, (int) ( $args['max_urls'] ?? self::MAX_URLS ) ) );

		$posts = array_values( array_intersect( AvandWP_Capabilities::managed_post_types(), (array) ( $args['post_types'] ?? AvandWP_Capabilities::managed_post_types() ) ) );

		if ( empty( $posts ) ) {
			$posts = AvandWP_Capabilities::managed_post_types();
		}

		$run = array(
			'status'       => self::STATUS_RUNNING,
			'started_at'   => current_time( 'mysql' ),
			'finished_at'  => null,
			'max_urls'     => $max_urls,
			'crawled'      => 0,
			'discovered'   => 0,
			'config'       => wp_json_encode(
				array(
					'post_types'       => $posts,
					'max_depth'        => (int) ( $args['max_depth'] ?? self::MAX_DEPTH ),
					'include_archives' => ! empty( $args['include_archives'] ),
				),
				JSON_UNESCAPED_UNICODE
			),
			'summary'      => null,
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( self::runs_table(), $run, array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );

		if ( ! $inserted ) {
			return new WP_Error( 'avandwp_audit_insert_failed', 'ایجاد اجرای ممیزی در دیتابیس ناموفق بود.' );
		}

		$run_id = (int) $wpdb->insert_id;

		$this->seed_urls( $run_id, $posts );

		AvandWP_Logger::log(
			'audit.started',
			sprintf( 'ممیزی سایت #%d آغاز شد (حداکثر %d نشانی).', $run_id, $max_urls ),
			'info',
			array( 'run_id' => $run_id )
		);

		return $this->get_run( $run_id );
	}

	/**
	 * Queue the site's own URLs for crawling.
	 *
	 * @param int   $run_id     Run ID.
	 * @param array $post_types Post types to include.
	 * @return int Number of URLs queued.
	 */
	private function seed_urls( $run_id, array $post_types ) {
		global $wpdb;

		$table = self::pages_table();

		$queued = 0;

		$home = home_url( '/' );
		$this->queue_url( $run_id, $home, 0, 'home' );
		++$queued;

		$front_id = (int) get_option( 'page_on_front' );

		if ( $front_id > 0 ) {
			$front = get_permalink( $front_id );

			if ( $front && $front !== $home ) {
				$this->queue_url( $run_id, $front, 0, 'front_page' );
				++$queued;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_URLS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$seen = array( $home => true );

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );

			if ( ! $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;

			$this->queue_url( $run_id, $url, 1, (string) $post->post_type, (int) $post->ID );
			++$queued;
		}

		unset( $table );

		return $queued;
	}

	/**
	 * Add a URL to the crawl queue if it is not already present.
	 *
	 * @param int    $run_id   Run ID.
	 * @param string $url      URL.
	 * @param int    $depth    Crawl depth.
	 * @param string $kind     `home`, `front_page`, `post`, `discovered`.
	 * @param int    $post_id  Related post ID when known.
	 * @return bool Whether a row was created.
	 */
	private function queue_url( $run_id, $url, $depth, $kind = 'discovered', $post_id = 0 ) {
		global $wpdb;

		$table = self::pages_table();
		$url   = esc_url_raw( $url );

		if ( '' === $url ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE run_id = %d AND url = %s LIMIT 1", (int) $run_id, $url )
		);

		if ( $exists > 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'run_id'     => (int) $run_id,
				'url'        => $url,
				'depth'      => (int) $depth,
				'kind'       => substr( sanitize_key( $kind ), 0, 24 ),
				'post_id'    => (int) $post_id,
				'status'     => 'queued',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Fetch and audit the next queued URLs of a run.
	 *
	 * @param int $run_id Run ID.
	 * @param int $limit  Maximum URLs to fetch in this call.
	 * @return array|WP_Error Progress summary.
	 */
	public function crawl_batch( $run_id, $limit = self::DEFAULT_BATCH ) {
		global $wpdb;

		$run = $this->get_run( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( self::STATUS_RUNNING !== $run['status'] ) {
			return new WP_Error( 'avandwp_audit_not_running', 'این ممیزی در وضعیت اجرا نیست.' );
		}

		$table = self::pages_table();
		$limit = max( 1, min( 40, (int) $limit ) );

		$remaining = (int) $run['max_urls'] - (int) $run['crawled'];

		if ( $remaining <= 0 ) {
			return $this->finish_run( $run_id );
		}

		$limit = min( $limit, $remaining );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, depth, kind, post_id FROM `{$table}`
				 WHERE run_id = %d AND status = 'queued'
				 ORDER BY depth ASC, id ASC
				 LIMIT %d",
				(int) $run_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $this->finish_run( $run_id );
		}

		$robots = $this->robots_rules();
		$crawled = 0;

		foreach ( $rows as $row ) {
			if ( ! $this->robots_allows( (string) $row['url'], $robots ) ) {
				$this->mark_skipped( (int) $row['id'], 'robots_disallow' );
				continue;
			}

			$result = $this->audit_url( (string) $row['url'], (int) $row['depth'], $run );

			$this->store_result( (int) $row['id'], $result );

			++$crawled;

			if ( self::POLITE_DELAY_SECONDS > 0 ) {
				usleep( (int) ( self::POLITE_DELAY_SECONDS * 1000000 ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::runs_table(),
			array(
				'crawled'    => (int) $run['crawled'] + $crawled,
				'discovered' => $this->count_rows( $run_id ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $run_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		$progress = $this->get_run( $run_id );

		if ( ! is_wp_error( $progress ) && (int) $progress['crawled'] >= (int) $progress['max_urls'] ) {
			return $this->finish_run( $run_id );
		}

		return array(
			'run_id'     => (int) $run_id,
			'crawled'    => $crawled,
			'total'      => (int) $progress['crawled'],
			'remaining'  => max( 0, (int) $run['max_urls'] - (int) $progress['crawled'] ),
			'status'     => $progress['status'],
		);
	}

	/**
	 * Mark a URL as skipped and record why.
	 *
	 * @param int    $row_id Page row ID.
	 * @param string $reason Reason slug.
	 * @return void
	 */
	private function mark_skipped( $row_id, $reason ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::pages_table(),
			array(
				'status'     => 'skipped',
				'skip_reason' => substr( sanitize_key( $reason ), 0, 40 ),
				'fetched_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count queued-or-done rows for a run.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	private function count_rows( $run_id ) {
		global $wpdb;

		$table = self::pages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d", (int) $run_id )
		);
	}

	/**
	 * Close a run and compute its summary.
	 *
	 * @param int $run_id Run ID.
	 * @return array|WP_Error
	 */
	public function finish_run( $run_id ) {
		global $wpdb;

		$summary = $this->summarize( $run_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::runs_table(),
			array(
				'status'      => self::STATUS_COMPLETE,
				'finished_at' => current_time( 'mysql' ),
				'summary'     => wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		AvandWP_Logger::log(
			'audit.completed',
			sprintf( 'ممیزی سایت #%d با بررسی %d نشانی کامل شد.', (int) $run_id, (int) ( $summary['pages'] ?? 0 ) ),
			'success',
			array( 'run_id' => (int) $run_id )
		);

		return $this->get_run( $run_id );
	}

	/**
	 * Stop a run, keeping everything already collected.
	 *
	 * @param int $run_id Run ID.
	 * @return array|WP_Error
	 */
	public function pause_run( $run_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::runs_table(),
			array(
				'status'     => self::STATUS_PAUSED,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $run_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $this->get_run( $run_id );
	}

	/**
	 * Load a run row.
	 *
	 * @param int $run_id Run ID.
	 * @return array|WP_Error
	 */
	public function get_run( $run_id ) {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $run_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'avandwp_audit_not_found', 'اجرای ممیزی مورد نظر یافت نشد.' );
		}

		$row['summary'] = ! empty( $row['summary'] ) ? json_decode( (string) $row['summary'], true ) : null;

		return $row;
	}

	/**
	 * List recent runs.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_runs( $limit = 10 ) {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", max( 1, min( 50, (int) $limit ) ) ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ---------------------------------------------------------------------
	// Single-URL audit
	// ---------------------------------------------------------------------

	/**
	 * Fetch one URL and audit the returned document.
	 *
	 * @param string $url   URL.
	 * @param int    $depth Crawl depth.
	 * @param array  $run   Run row.
	 * @return array Audit result.
	 */
	private function audit_url( $url, $depth, array $run ) {
		$fetched = AvandWP_Outbound_Guard::fetch_html( $url );

		if ( is_wp_error( $fetched ) ) {
			return array(
				'status'     => 'failed',
				'http_code'  => 0,
				'notes'      => $fetched->get_error_message(),
				'issues'     => array( array( 'code' => 'fetch_failed', 'message' => 'دریافت صفحه ناموفق بود: ' . $fetched->get_error_message(), 'weight' => 10 ) ),
				'redirects'  => array(),
				'metrics'    => array(),
				'discovered' => array(),
			);
		}

		$html = (string) $fetched['body'];

		$metrics = $this->extract_metrics( $url, $html, $fetched );

		$config = json_decode( (string) $run['config'], true );
		$max_depth = (int) ( is_array( $config ) ? ( $config['max_depth'] ?? self::MAX_DEPTH ) : self::MAX_DEPTH );

		$discovered = array();

		if ( $depth < $max_depth ) {
			foreach ( $metrics['internal_link_urls'] as $candidate ) {
				if ( $this->queue_url( (int) $run['id'], $candidate, $depth + 1, 'discovered' ) ) {
					$discovered[] = $candidate;
				}
			}
		}

		$issues = $this->evaluate( $url, $metrics, $fetched );

		return array(
			'status'     => 'done',
			'http_code'  => (int) $fetched['code'],
			'notes'      => '',
			'issues'     => $issues,
			'redirects'  => $fetched['redirects'],
			'metrics'    => $metrics,
			'discovered' => array_slice( $discovered, 0, 25 ),
		);
	}

	/**
	 * Extract everything measurable from a document.
	 *
	 * All parsing is regex-based and deliberately shallow: this code must run on
	 * shared hosting inside a request budget, and a full DOM parse of every page
	 * is what makes crawlers unusable on cheap hosting. Where DOMDocument is
	 * available it is not used, so behaviour is identical everywhere.
	 *
	 * @param string $url     URL.
	 * @param string $html    Document body.
	 * @param array  $fetched Fetch result.
	 * @return array
	 */
	private function extract_metrics( $url, $html, array $fetched ) {
		$head = $html;
		$head_end = stripos( $html, '</head>' );

		if ( false !== $head_end ) {
			$head = substr( $html, 0, $head_end + 7 );
		}

		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/isu', $head, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		$description = '';
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/iu', $head, $m ) ) {
			$description = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/iu', $head, $m ) ) {
			$description = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		$robots_meta = '';
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)["\']/iu', $head, $m ) ) {
			$robots_meta = strtolower( trim( $m[1] ) );
		}

		$canonical = '';
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']*)["\']/iu', $head, $m ) ) {
			$canonical = trim( $m[1] );
		}

		$lang = '';
		if ( preg_match( '/<html[^>]*\blang=["\']([^"\']*)["\']/iu', $head, $m ) ) {
			$lang = trim( $m[1] );
		}

		$noindex  = ( false !== strpos( $robots_meta, 'noindex' ) );
		$nofollow = ( false !== strpos( $robots_meta, 'nofollow' ) );

		// Headings, in document order, so level jumps can be detected.
		$headings = array();
		$h1_count = 0;

		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/isu', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$level = (int) $match[1];

				if ( 1 === $level ) {
					++$h1_count;
				}

				$headings[] = array(
					'level' => $level,
					'text'  => AvandWP_Text_Helper::safe_substr( trim( wp_strip_all_tags( $match[2] ) ), 0, 120 ),
				);
			}
		}

		$heading_jump = false;
		$previous     = 0;

		foreach ( $headings as $heading ) {
			if ( $previous > 0 && $heading['level'] > $previous + 1 ) {
				$heading_jump = true;
				break;
			}
			$previous = $heading['level'];
		}

		// Images: `alt` must exist and be non-empty to count.
		$images = 0;
		$missing_alt = 0;

		if ( preg_match_all( '/<img\b([^>]*)>/iu', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				++$images;

				if ( ! preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/iu', $match[1], $alt ) || '' === trim( $alt[1] ) ) {
					++$missing_alt;
				}
			}
		}

		$links      = AvandWP_Link_Intelligence::extract_links( $html );
		$internal   = array();
		$external   = 0;

		foreach ( $links as $link ) {
			if ( AvandWP_Link_Intelligence::is_internal_url( $link['url'] ) ) {
				$absolute = AvandWP_Outbound_Guard::absolutize_url( $link['url'], $url );
				$clean    = strtok( $absolute, '#' );

				if ( false !== $clean && '' !== $clean ) {
					$internal[ $clean ] = true;
				}
			} else {
				++$external;
			}
		}

		$schema_types = array();

		if ( preg_match_all( '/"@type"\s*:\s*"([^"]+)"/iu', $html, $matches ) ) {
			foreach ( $matches[1] as $type ) {
				$schema_types[ $type ] = true;
			}
		}

		$has_schema = ! empty( $schema_types ) || (bool) preg_match( '/<(script|div)[^>]+itemtype=["\']https?:\/\/schema\.org/iu', $html );

		$og = (bool) preg_match( '/<meta[^>]+property=["\']og:(title|description|image)["\']/iu', $head );
		$twitter = (bool) preg_match( '/<meta[^>]+name=["\']twitter:(card|title|description)["\']/iu', $head );
		$viewport = (bool) preg_match( '/<meta[^>]+name=["\']viewport["\']/iu', $head );

		$plain = AvandWP_Text_Helper::to_plain_text( $html );
		$measured = AvandWP_Text_Helper::measure( $html );

		return array(
			'url'                => $url,
			'final_url'          => (string) $fetched['final_url'],
			'http_code'          => (int) $fetched['code'],
			'bytes'              => strlen( $html ),
			'title'              => $title,
			'title_length'       => AvandWP_Text_Helper::length( $title ),
			'description'        => $description,
			'description_length' => AvandWP_Text_Helper::length( $description ),
			'robots_meta'        => $robots_meta,
			'noindex'            => $noindex,
			'nofollow'           => $nofollow,
			'canonical'          => $canonical,
			'lang'               => $lang,
			'h1_count'           => $h1_count,
			'heading_count'      => count( $headings ),
			'heading_jump'       => $heading_jump,
			'headings'           => array_slice( $headings, 0, 40 ),
			'images'             => $images,
			'images_missing_alt' => $missing_alt,
			'internal_links'     => count( $internal ),
			'external_links'     => $external,
			'internal_link_urls' => array_slice( array_keys( $internal ), 0, 60 ),
			'has_schema'         => $has_schema,
			'schema_types'       => array_slice( array_keys( $schema_types ), 0, 12 ),
			'has_og'             => $og || $twitter,
			'has_viewport'       => $viewport,
			'words'              => (int) $measured['words'],
			'characters'         => (int) $measured['chars_with_spaces'],
			'plain_excerpt'      => AvandWP_Text_Helper::safe_substr( $plain, 0, 200 ),
			'https'              => 0 === strpos( $url, 'https://' ),
			'redirect_count'     => count( (array) $fetched['redirects'] ),
			'redirects'          => (array) $fetched['redirects'],
		);
	}

	/**
	 * Evaluate extracted metrics into findings.
	 *
	 * @param string $url     URL.
	 * @param array  $metrics Metrics.
	 * @param array  $fetched Fetch result.
	 * @return array<int, array<string, mixed>>
	 */
	private function evaluate( $url, array $metrics, array $fetched ) {
		$issues = array();

		$code = (int) $metrics['http_code'];

		if ( $code < 200 || $code >= 300 ) {
			$issues[] = array(
				'code'     => 'non_200',
				'severity' => 'critical',
				'message'  => sprintf( 'صفحه با کد وضعیت %d پاسخ می‌دهد.', $code ),
				'evidence' => 'HTTP ' . $code,
			);
		}

		if ( ! empty( $metrics['noindex'] ) ) {
			$issues[] = array(
				'code'     => 'noindex',
				'severity' => 'high',
				'message'  => 'این صفحه با متای robots از ایندکس مستثنا شده است.',
				'evidence' => 'meta robots: ' . $metrics['robots_meta'],
			);
		}

		if ( '' === $metrics['title'] ) {
			$issues[] = array(
				'code'     => 'missing_title',
				'severity' => 'critical',
				'message'  => 'تگ عنوان صفحه وجود ندارد.',
				'evidence' => '<title> یافت نشد',
			);
		} elseif ( $metrics['title_length'] > 65 ) {
			$issues[] = array(
				'code'     => 'title_long',
				'severity' => 'medium',
				'message'  => 'عنوان صفحه بلندتر از حد معمول است و ممکن است در نتایج جستجو کوتاه شود.',
				'evidence' => sprintf( '%d کاراکتر: %s', $metrics['title_length'], AvandWP_Text_Helper::safe_substr( $metrics['title'], 0, 90 ) ),
			);
		} elseif ( $metrics['title_length'] < 20 ) {
			$issues[] = array(
				'code'     => 'title_short',
				'severity' => 'low',
				'message'  => 'عنوان صفحه بسیار کوتاه است و فرصت استفاده از کلیدواژه‌ها را از دست می‌دهد.',
				'evidence' => sprintf( '%d کاراکتر', $metrics['title_length'] ),
			);
		}

		if ( '' === $metrics['description'] ) {
			$issues[] = array(
				'code'     => 'missing_description',
				'severity' => 'medium',
				'message'  => 'توضیحات متا وجود ندارد؛ موتور جستجو بخشی از متن را خودش انتخاب می‌کند.',
				'evidence' => 'meta description یافت نشد',
			);
		} elseif ( $metrics['description_length'] > 165 ) {
			$issues[] = array(
				'code'     => 'description_long',
				'severity' => 'low',
				'message'  => 'توضیحات متا بلندتر از حد معمول است.',
				'evidence' => sprintf( '%d کاراکتر', $metrics['description_length'] ),
			);
		} elseif ( $metrics['description_length'] < 70 ) {
			$issues[] = array(
				'code'     => 'description_short',
				'severity' => 'low',
				'message'  => 'توضیحات متا کوتاه‌تر از آن است که پیام کامل را منتقل کند.',
				'evidence' => sprintf( '%d کاراکتر', $metrics['description_length'] ),
			);
		}

		if ( 0 === $metrics['h1_count'] ) {
			$issues[] = array(
				'code'     => 'missing_h1',
				'severity' => 'high',
				'message'  => 'صفحه هیچ تگ H1 ندارد؛ موضوع اصلی صفحه برای ماشین مشخص نیست.',
				'evidence' => 'تعداد H1: 0',
			);
		} elseif ( $metrics['h1_count'] > 1 ) {
			$issues[] = array(
				'code'     => 'multiple_h1',
				'severity' => 'medium',
				'message'  => 'صفحه بیش از یک H1 دارد و سلسله‌مراتب موضوعی را مبهم می‌کند.',
				'evidence' => 'تعداد H1: ' . $metrics['h1_count'],
			);
		}

		if ( ! empty( $metrics['heading_jump'] ) ) {
			$issues[] = array(
				'code'     => 'heading_jump',
				'severity' => 'low',
				'message'  => 'در ترتیب هدینگ‌ها سطحی جا افتاده است (مثلاً از H2 به H4).',
				'evidence' => 'ترتیب هدینگ‌های صفحه',
			);
		}

		if ( (int) $metrics['words'] < self::THIN_CONTENT_WORDS && $code >= 200 && $code < 300 ) {
			$issues[] = array(
				'code'     => 'thin_content',
				'severity' => 'medium',
				'message'  => sprintf( 'محتوای صفحه کمتر از %d کلمه است.', self::THIN_CONTENT_WORDS ),
				'evidence' => sprintf( '%d کلمه', (int) $metrics['words'] ),
			);
		}

		if ( (int) $metrics['images_missing_alt'] > 0 ) {
			$issues[] = array(
				'code'     => 'images_missing_alt',
				'severity' => 'medium',
				'message'  => 'برخی تصاویر متن جانشین (alt) ندارند.',
				'evidence' => sprintf( '%d از %d تصویر بدون alt', (int) $metrics['images_missing_alt'], (int) $metrics['images'] ),
			);
		}

		if ( 0 === (int) $metrics['internal_links'] ) {
			$issues[] = array(
				'code'     => 'no_internal_links',
				'severity' => 'medium',
				'message'  => 'صفحه هیچ لینک داخلی ندارد.',
				'evidence' => 'لینک داخلی: 0',
			);
		}

		if ( empty( $metrics['has_schema'] ) ) {
			$issues[] = array(
				'code'     => 'no_structured_data',
				'severity' => 'low',
				'message'  => 'داده ساختاریافته‌ای در صفحه یافت نشد.',
				'evidence' => 'هیچ @type یا itemtype مشاهده نشد',
			);
		}

		if ( empty( $metrics['has_og'] ) ) {
			$issues[] = array(
				'code'     => 'no_social_tags',
				'severity' => 'low',
				'message'  => 'تگ‌های اشتراک‌گذاری (Open Graph یا Twitter) وجود ندارد.',
				'evidence' => 'og:* و twitter:* یافت نشد',
			);
		}

		if ( '' === $metrics['lang'] ) {
			$issues[] = array(
				'code'     => 'missing_lang',
				'severity' => 'low',
				'message'  => 'زبان صفحه در تگ html تعیین نشده است.',
				'evidence' => '<html lang="…"> یافت نشد',
			);
		}

		if ( empty( $metrics['has_viewport'] ) ) {
			$issues[] = array(
				'code'     => 'missing_viewport',
				'severity' => 'medium',
				'message'  => 'متای viewport وجود ندارد؛ نمایش در موبایل تضمین نمی‌شود.',
				'evidence' => 'meta viewport یافت نشد',
			);
		}

		if ( empty( $metrics['https'] ) ) {
			$issues[] = array(
				'code'     => 'not_https',
				'severity' => 'high',
				'message'  => 'صفحه روی HTTP بارگذاری می‌شود.',
				'evidence' => $url,
			);
		}

		if ( (int) $metrics['redirect_count'] > 0 ) {
			$chain = array();

			foreach ( (array) $metrics['redirects'] as $hop ) {
				$chain[] = sprintf( '%d → %s', (int) $hop['code'], $hop['to'] );
			}

			$issues[] = array(
				'code'     => 'redirected',
				'severity' => 'low',
				'message'  => 'این نشانی ریدایرکت می‌شود.',
				'evidence' => implode( ' | ', $chain ),
			);
		}

		// Canonical pointing somewhere else is not automatically wrong (that is
		// what canonical is for), but on a page the owner expects to rank it is
		// worth surfacing with the exact target as evidence.
		if ( '' !== $metrics['canonical'] ) {
			$strip = static function ( $u ) {
				$u = (string) $u;
				$u = preg_replace( '#^https?://#i', '', $u );
				$u = preg_replace( '#^www\.#i', '', $u );
				return rtrim( $u, '/' );
			};

			if ( $strip( $metrics['canonical'] ) !== $strip( $url ) && $strip( $metrics['canonical'] ) !== $strip( (string) $fetched['final_url'] ) ) {
				$issues[] = array(
					'code'     => 'canonical_other',
					'severity' => 'high',
					'message'  => 'کانونیکال این صفحه به نشانی دیگری اشاره می‌کند؛ در این حالت نسخه فعلی از نتایج حذف می‌شود.',
					'evidence' => 'canonical: ' . $metrics['canonical'],
				);
			}
		}

		return $issues;
	}

	/**
	 * Score a set of page metrics against the declared checks.
	 *
	 * @param array $metrics Metrics for one page.
	 * @return array{score: int|null, checks: array<string, array<string, mixed>>}
	 */
	public static function score_page( array $metrics ) {
		$checks = self::checks();
		$passed = 0;
		$total  = 0;
		$detail = array();

		$results = array(
			'http_ok'          => ( (int) ( $metrics['http_code'] ?? 0 ) >= 200 && (int) ( $metrics['http_code'] ?? 0 ) < 300 ),
			'indexable'        => empty( $metrics['noindex'] ),
			'canonical_self'   => ! isset( $metrics['canonical_other'] ) || empty( $metrics['canonical_other'] ),
			'title_present'    => '' !== (string) ( $metrics['title'] ?? '' ),
			'title_length'     => (int) ( $metrics['title_length'] ?? 0 ) >= 20 && (int) ( $metrics['title_length'] ?? 0 ) <= 65,
			'title_unique'     => ! empty( $metrics['title_unique'] ),
			'description'      => '' !== (string) ( $metrics['description'] ?? '' ),
			'description_len'  => (int) ( $metrics['description_length'] ?? 0 ) >= 70 && (int) ( $metrics['description_length'] ?? 0 ) <= 165,
			'description_unique' => ! empty( $metrics['description_unique'] ),
			'single_h1'        => 1 === (int) ( $metrics['h1_count'] ?? 0 ),
			'heading_order'    => empty( $metrics['heading_jump'] ),
			'content_depth'    => (int) ( $metrics['words'] ?? 0 ) >= self::THIN_CONTENT_WORDS,
			'image_alt'        => 0 === (int) ( $metrics['images_missing_alt'] ?? 0 ),
			'internal_links'   => (int) ( $metrics['internal_links'] ?? 0 ) >= 1,
			'structured_data'  => ! empty( $metrics['has_schema'] ),
			'open_graph'       => ! empty( $metrics['has_og'] ),
			'lang_attribute'   => '' !== (string) ( $metrics['lang'] ?? '' ),
			'viewport'         => ! empty( $metrics['has_viewport'] ),
			'https'            => ! empty( $metrics['https'] ),
			'not_redirected'   => 0 === (int) ( $metrics['redirect_count'] ?? 0 ),
		);

		foreach ( $checks as $id => $check ) {
			$ok = ! empty( $results[ $id ] );

			$total += (int) $check['weight'];

			if ( $ok ) {
				$passed += (int) $check['weight'];
			}

			$detail[ $id ] = array(
				'label'  => $check['label'],
				'weight' => (int) $check['weight'],
				'status' => $ok ? 'pass' : 'fail',
				'profile' => $check['profile'],
			);
		}

		return array(
			'score'  => $total > 0 ? (int) round( ( $passed / $total ) * 100 ) : null,
			'checks' => $detail,
		);
	}

	// ---------------------------------------------------------------------
	// Storage and reporting
	// ---------------------------------------------------------------------

	/**
	 * Persist the outcome of auditing one URL.
	 *
	 * @param int   $row_id Row ID.
	 * @param array $result Audit result.
	 * @return void
	 */
	private function store_result( $row_id, array $result ) {
		global $wpdb;

		$metrics = (array) ( $result['metrics'] ?? array() );

		$scored = self::score_page( $metrics );

		$issues = (array) ( $result['issues'] ?? array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::pages_table(),
			array(
				'status'        => (string) $result['status'],
				'http_code'     => (int) $result['http_code'],
				'score'         => null === $scored['score'] ? null : (int) $scored['score'],
				'title'         => AvandWP_Text_Helper::safe_substr( (string) ( $metrics['title'] ?? '' ), 0, 300 ),
				'title_len'     => (int) ( $metrics['title_length'] ?? 0 ),
				'meta_desc'     => AvandWP_Text_Helper::safe_substr( (string) ( $metrics['description'] ?? '' ), 0, 400 ),
				'meta_desc_len' => (int) ( $metrics['description_length'] ?? 0 ),
				'canonical'     => AvandWP_Text_Helper::safe_substr( (string) ( $metrics['canonical'] ?? '' ), 0, 500 ),
				'robots_meta'   => AvandWP_Text_Helper::safe_substr( (string) ( $metrics['robots_meta'] ?? '' ), 0, 120 ),
				'h1_count'      => (int) ( $metrics['h1_count'] ?? 0 ),
				'words'         => (int) ( $metrics['words'] ?? 0 ),
				'images'        => (int) ( $metrics['images'] ?? 0 ),
				'images_no_alt' => (int) ( $metrics['images_missing_alt'] ?? 0 ),
				'links_internal' => (int) ( $metrics['internal_links'] ?? 0 ),
				'links_external' => (int) ( $metrics['external_links'] ?? 0 ),
				'has_schema'    => ! empty( $metrics['has_schema'] ) ? 1 : 0,
				'schema_types'  => AvandWP_Text_Helper::safe_substr( implode( ',', (array) ( $metrics['schema_types'] ?? array() ) ), 0, 300 ),
				'content_hash'  => '' !== (string) ( $metrics['plain_excerpt'] ?? '' ) ? md5( (string) $metrics['plain_excerpt'] ) : '',
				'issues_json'   => wp_json_encode( $issues, JSON_UNESCAPED_UNICODE ),
				'metrics_json'  => wp_json_encode(
					array(
						'checks'      => $scored['checks'],
						'heading_jump' => ! empty( $metrics['heading_jump'] ),
						'headings'    => array_slice( (array) ( $metrics['headings'] ?? array() ), 0, 20 ),
						'has_og'      => ! empty( $metrics['has_og'] ),
						'has_viewport' => ! empty( $metrics['has_viewport'] ),
						'lang'        => (string) ( $metrics['lang'] ?? '' ),
						'https'       => ! empty( $metrics['https'] ),
						'redirects'   => (array) ( $metrics['redirects'] ?? array() ),
						'bytes'       => (int) ( $metrics['bytes'] ?? 0 ),
					),
					JSON_UNESCAPED_UNICODE
				),
				'notes'        => AvandWP_Text_Helper::safe_substr( (string) $result['notes'], 0, 500 ),
				'fetched_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row_id ),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Build the run summary: per-page results plus cross-page findings.
	 *
	 * Cross-page checks (duplicate titles, duplicate descriptions, orphan pages)
	 * can only be evaluated once every page has been fetched, which is why they
	 * live here rather than in the per-page pass.
	 *
	 * @param int $run_id Run ID.
	 * @return array
	 */
	public function summarize( $run_id ) {
		global $wpdb;

		$table = self::pages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d ORDER BY id ASC", (int) $run_id ),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		$inspected = 0;
		$scores    = array();
		$issue_totals = array();
		$titles    = array();
		$descs     = array();

		foreach ( $rows as $row ) {
			if ( 'done' !== $row['status'] ) {
				continue;
			}

			++$inspected;

			if ( null !== $row['score'] ) {
				$scores[] = (int) $row['score'];
			}

			$issues = json_decode( (string) $row['issues_json'], true );
			$issues = is_array( $issues ) ? $issues : array();

			foreach ( $issues as $issue ) {
				$code = (string) ( $issue['code'] ?? 'unknown' );
				$issue_totals[ $code ] = ( $issue_totals[ $code ] ?? 0 ) + 1;
			}

			if ( '' !== (string) $row['title'] ) {
				$key = AvandWP_Text_Helper::normalize_persian( (string) $row['title'] );
				$titles[ $key ][] = (string) $row['url'];
			}

			if ( '' !== (string) $row['meta_desc'] ) {
				$key = AvandWP_Text_Helper::normalize_persian( (string) $row['meta_desc'] );
				$descs[ $key ][] = (string) $row['url'];
			}

		}

		// Duplicate detection.
		$duplicate_titles = array();
		foreach ( $titles as $urls ) {
			if ( count( $urls ) > 1 ) {
				$duplicate_titles[] = $urls;
			}
		}

		$duplicate_descriptions = array();
		foreach ( $descs as $urls ) {
			if ( count( $urls ) > 1 ) {
				$duplicate_descriptions[] = $urls;
			}
		}

		$average = ! empty( $scores ) ? (int) round( array_sum( $scores ) / count( $scores ) ) : null;

		return array(
			'pages'               => $inspected,
			'queued'              => count( $rows ),
			'average_score'       => $average,
			'worst_score'         => ! empty( $scores ) ? (int) min( $scores ) : null,
			'best_score'          => ! empty( $scores ) ? (int) max( $scores ) : null,
			'issue_totals'        => $issue_totals,
			'duplicate_titles'    => array_slice( $duplicate_titles, 0, 20 ),
			'duplicate_descriptions' => array_slice( $duplicate_descriptions, 0, 20 ),
			'score_distribution'  => self::distribution( $scores ),
			'note'                => 'این امتیاز یک چک‌لیست داخلی فنی است که وضعیت ساختار صفحه را می‌سنجد؛ معیار رسمی هیچ موتور جستجویی نیست و تضمینی درباره رتبه یا نمایش در نتایج نمی‌دهد.',
		);
	}

	/**
	 * Bucket scores into a small distribution for the UI.
	 *
	 * @param array $scores Scores.
	 * @return array<string, int>
	 */
	private static function distribution( array $scores ) {
		$buckets = array(
			'90-100' => 0,
			'75-89'  => 0,
			'60-74'  => 0,
			'40-59'  => 0,
			'0-39'   => 0,
		);

		foreach ( $scores as $score ) {
			$score = (int) $score;

			if ( $score >= 90 ) {
				$buckets['90-100']++;
			} elseif ( $score >= 75 ) {
				$buckets['75-89']++;
			} elseif ( $score >= 60 ) {
				$buckets['60-74']++;
			} elseif ( $score >= 40 ) {
				$buckets['40-59']++;
			} else {
				$buckets['0-39']++;
			}
		}

		return $buckets;
	}

	/**
	 * Pages of a run, worst score first.
	 *
	 * @param int $run_id Run ID.
	 * @param int $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function run_pages( $run_id, $limit = 100 ) {
		global $wpdb;

		$table = self::pages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, status, http_code, score, title, title_len, meta_desc_len, h1_count, words, images_no_alt, links_internal, links_external, has_schema, issues_json, skip_reason, fetched_at
				 FROM `{$table}`
				 WHERE run_id = %d
				 ORDER BY (score IS NULL), score ASC, id ASC
				 LIMIT %d",
				(int) $run_id,
				max( 1, min( 500, (int) $limit ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['issues'] = json_decode( (string) $row['issues_json'], true );
			unset( $row['issues_json'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Queue a background crawl job for this site.
	 *
	 * @param array $args Run arguments.
	 * @return array|WP_Error
	 */
	public function queue_crawl( array $args = array() ) {
		if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() ) ) {
			return new WP_Error( 'avandwp_forbidden', 'برای اجرای ممیزی سایت، دسترسی مدیریت لازم است.' );
		}

		return AvandWP_Job_Queue::enqueue(
			'site_audit',
			array( 'args' => $args ),
			array(
				'max_attempts' => 2,
				'dedupe_key'   => 'site_audit',
			)
		);
	}

	// ---------------------------------------------------------------------
	// robots.txt
	// ---------------------------------------------------------------------

	/**
	 * Read and parse the site's own robots.txt.
	 *
	 * @return array{disallow: array<int, string>, allow: array<int, string>, crawl_delay: float, found: bool}
	 */
	private function robots_rules() {
		$cache = get_transient( 'avandwp_robots_rules' );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$rules = array(
			'disallow'    => array(),
			'allow'       => array(),
			'crawl_delay' => 0.0,
			'found'       => false,
		);

		$url = home_url( '/robots.txt' );

		$fetched = AvandWP_Outbound_Guard::fetch_html( $url, 262144, 1 );

		if ( ! is_wp_error( $fetched ) && (int) $fetched['code'] >= 200 && (int) $fetched['code'] < 300 ) {
			$rules['found'] = true;

			$agent_matches = false;

			foreach ( preg_split( '/\r\n|\r|\n/', (string) $fetched['body'] ) as $line ) {
				$line = trim( (string) $line );

				if ( '' === $line || '#' === $line[0] ) {
					continue;
				}

				if ( ! preg_match( '/^([a-z-]+)\s*:\s*(.*)$/i', $line, $parts ) ) {
					continue;
				}

				$key   = strtolower( $parts[1] );
				$value = trim( $parts[2] );

				if ( 'user-agent' === $key ) {
					$agent_matches = ( '*' === $value );
					continue;
				}

				if ( ! $agent_matches ) {
					// Only the wildcard group is honoured. A rule written for a
					// named crawler does not apply to this one, and treating it
					// as if it did would block crawling the site's own pages.
					continue;
				}

				if ( 'disallow' === $key ) {
					$rules['disallow'][] = $value;
				} elseif ( 'allow' === $key ) {
					$rules['allow'][] = $value;
				} elseif ( 'crawl-delay' === $key ) {
					$rules['crawl_delay'] = max( 0.0, min( 10.0, (float) $value ) );
				}
			}
		}

		set_transient( 'avandwp_robots_rules', $rules, HOUR_IN_SECONDS );

		return $rules;
	}

	/**
	 * Does robots.txt allow fetching this URL?
	 *
	 * Longest-match precedence is applied, which is the behaviour Google
	 * documents: the most specific rule wins, and `Allow` wins a tie.
	 *
	 * @param string $url   URL.
	 * @param array  $rules Parsed rules.
	 * @return bool
	 */
	private function robots_allows( $url, array $rules ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			$path = '/';
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( $query ) {
			$path .= '?' . $query;
		}

		$best_len = -1;
		$decision = true;

		foreach ( array( 'allow', 'disallow' ) as $kind ) {
			foreach ( (array) $rules[ $kind ] as $rule ) {
				$rule = (string) $rule;

				if ( '' === $rule ) {
					// "Disallow:" with no value allows everything.
					continue;
				}

				$len = strlen( $rule );

				if ( $len < $best_len ) {
					continue;
				}

				$matches = ( 0 === strpos( $rule, '/' ) )
					? ( 0 === strpos( $path, $rule ) || false !== strpos( $path, str_replace( '*', '', $rule ) ) )
					: ( false !== strpos( $path, $rule ) );

				if ( ! $matches ) {
					continue;
				}

				if ( $len > $best_len || 'allow' === $kind ) {
					$best_len = $len;
					$decision = ( 'allow' === $kind );
				}
			}
		}

		return $decision;
	}
}
