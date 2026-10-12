<?php
/**
 * Plugin Activator and Database Schema Manager.
 *
 * Creates all 8 required custom tables with consistent utf8mb4 collation,
 * including the complete `avandwp_support_requests` schema (with `priority`,
 * `audio_url`, and `transcript` columns that were missing in the legacy version).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Activator {

	/**
	 * Run activation tasks: create tables, initialize options, schedule crons.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::init_default_options();
		self::schedule_crons();

		update_option( 'avandwp_version', AVANDWP_VERSION );
		update_option( 'avandwp_db_version', AVANDWP_DB_VERSION );
	}

	/**
	 * Run schema upgrades, once per version bump.
	 *
	 * `dbDelta()` is additive and never drops data, so this is safe to re-run.
	 * The guard exists so an upgrade does not repeat expensive migrations on
	 * every request.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'avandwp_db_version', 0 );

		if ( $installed >= (int) AVANDWP_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::init_default_options();
		self::schedule_crons();

		// 1001 introduced job-queue progress/backoff columns and the usage-ledger
		// cost-provenance columns. Existing rows keep working; the new columns
		// default to values that mean "unknown" rather than "free".
		if ( $installed > 0 && $installed < 1001 ) {
			self::backfill_usage_cost_source();
		}

		// 1002 added the site-audit tables. They are created by the same dbDelta
		// call above; nothing existing is modified, so no data migration is
		// required and none is attempted.


		update_option( 'avandwp_version', AVANDWP_VERSION );
		update_option( 'avandwp_db_version', AVANDWP_DB_VERSION );

		AvandWP_Logger::log(
			'db.upgraded',
			sprintf( 'نسخه دیتابیس آوند از %d به %d ارتقا یافت.', $installed, (int) AVANDWP_DB_VERSION ),
			'info'
		);
	}

	/**
	 * Mark pre-1001 usage rows as estimated rather than metered.
	 *
	 * The baseline stored a fabricated figure in `cost_usd` for every row. Rather
	 * than silently presenting those as provider-reported charges, they are
	 * relabelled so the UI can show them as estimates.
	 *
	 * @return void
	 */
	private static function backfill_usage_cost_source() {
		global $wpdb;

		$table = $wpdb->prefix . 'avandwp_usage';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET cost_source = %s, cost_note = %s
				 WHERE cost_source = 'unknown' AND cost_usd IS NOT NULL AND cost_usd > 0",
				'estimated',
				'برآورد نادرست نسخه ۱.۰.۰؛ برای محاسبه دقیق، فهرست قیمت جدید را اعمال کنید.'
			)
		);
	}

	/**
	 * List of all required custom tables (without $wpdb->prefix).
	 *
	 * @return array<int, string>
	 */
	public static function get_required_tables() {
		return array(
			'avandwp_knowledge',
			'avandwp_jobs',
			'avandwp_chat_logs',
			'avandwp_usage',
			'avandwp_support_requests',
			'avandwp_support_history',
			'avandwp_chat_feedback',
			'avandwp_activity_log',
			'avandwp_audit_runs',
			'avandwp_audit_pages',
		);
	}

	/**
	 * Create or upgrade all plugin database tables via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = array();

		// 1. Knowledge Index (Real Persian lexical + optional true vector embeddings)
		$sql[] = "CREATE TABLE {$p}avandwp_knowledge (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(32) NOT NULL DEFAULT 'post',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title text NOT NULL,
			url varchar(500) NOT NULL DEFAULT '',
			content_chunk longtext NOT NULL,
			normalized_tokens longtext NOT NULL,
			embedding_json longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_lookup (source_type, source_id)
		) {$charset_collate};";

		// 2. Background Job Queue
		$sql[] = "CREATE TABLE {$p}avandwp_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(64) NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			payload longtext NOT NULL,
			result longtext NULL,
			dedupe_key varchar(64) NULL,
			progress int(11) NULL,
			stage varchar(40) NULL,
			progress_data longtext NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 3,
			next_attempt_at datetime NULL,
			started_at datetime NULL,
			error_message text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY type_idx (job_type),
			KEY dedupe_idx (dedupe_key),
			KEY due_idx (status, next_attempt_at)
		) {$charset_collate};";

		// 3. Chat Assistant Conversation Logs
		$sql[] = "CREATE TABLE {$p}avandwp_chat_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			user_ip varchar(64) NOT NULL DEFAULT '',
			user_message text NOT NULL,
			bot_response longtext NOT NULL,
			sources_json text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_idx (session_id),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 4. Token & Cost Usage Ledger
		$sql[] = "CREATE TABLE {$p}avandwp_usage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(32) NOT NULL,
			model varchar(80) NOT NULL,
			feature varchar(32) NOT NULL DEFAULT 'general',
			operation varchar(32) NOT NULL DEFAULT 'text',
			tokens_in int(11) unsigned NOT NULL DEFAULT 0,
			tokens_out int(11) unsigned NOT NULL DEFAULT 0,
			total_tokens int(11) unsigned NOT NULL DEFAULT 0,
			units int(11) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(12,6) NULL DEFAULT NULL,
			cost_source varchar(24) NOT NULL DEFAULT 'unknown',
			cost_note text NULL,
			tokens_source varchar(24) NOT NULL DEFAULT 'provider_reported',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY provider_idx (provider),
			KEY created_idx (created_at),
			KEY feature_idx (feature)
		) {$charset_collate};";

		// 5. Support Requests (Fixed: includes priority, audio_url, transcript)
		$sql[] = "CREATE TABLE {$p}avandwp_support_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL DEFAULT '',
			user_name varchar(120) NOT NULL,
			user_contact varchar(190) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			message longtext NOT NULL,
			priority varchar(24) NOT NULL DEFAULT 'normal',
			status varchar(24) NOT NULL DEFAULT 'new',
			audio_url varchar(500) NULL,
			transcript longtext NULL,
			admin_reply longtext NULL,
			page_url varchar(500) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY priority_idx (priority),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 6. Support Request Audit History
		$sql[] = "CREATE TABLE {$p}avandwp_support_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_type varchar(40) NOT NULL,
			note text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY request_idx (request_id)
		) {$charset_collate};";

		// 7. Chat Response User Feedback
		$sql[] = "CREATE TABLE {$p}avandwp_chat_feedback (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_id varchar(64) NOT NULL DEFAULT '',
			rating varchar(16) NOT NULL DEFAULT 'up',
			question text NOT NULL,
			answer longtext NOT NULL,
			comment text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY rating_idx (rating)
		) {$charset_collate};";

		// 8. Operational Activity Log
		$sql[] = "CREATE TABLE {$p}avandwp_activity_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(100) NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level_idx (level),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 9. Site audit runs (one row per crawl pass).
		$sql[] = "CREATE TABLE {$p}avandwp_audit_runs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(24) NOT NULL DEFAULT 'running',
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			max_urls int(11) unsigned NOT NULL DEFAULT 0,
			crawled int(11) unsigned NOT NULL DEFAULT 0,
			discovered int(11) unsigned NOT NULL DEFAULT 0,
			config longtext NULL,
			summary longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 10. Site audit pages (one row per fetched URL).
		$sql[] = "CREATE TABLE {$p}avandwp_audit_pages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			url varchar(500) NOT NULL,
			depth int(11) unsigned NOT NULL DEFAULT 0,
			kind varchar(24) NOT NULL DEFAULT 'discovered',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'queued',
			skip_reason varchar(40) NULL,
			http_code int(11) NOT NULL DEFAULT 0,
			score int(11) NULL,
			title text NULL,
			title_len int(11) NOT NULL DEFAULT 0,
			meta_desc text NULL,
			meta_desc_len int(11) NOT NULL DEFAULT 0,
			canonical varchar(500) NULL,
			robots_meta varchar(120) NULL,
			h1_count int(11) NOT NULL DEFAULT 0,
			words int(11) NOT NULL DEFAULT 0,
			images int(11) NOT NULL DEFAULT 0,
			images_no_alt int(11) NOT NULL DEFAULT 0,
			links_internal int(11) NOT NULL DEFAULT 0,
			links_external int(11) NOT NULL DEFAULT 0,
			has_schema tinyint(1) NOT NULL DEFAULT 0,
			schema_types varchar(300) NULL,
			content_hash varchar(32) NULL,
			issues_json longtext NULL,
			metrics_json longtext NULL,
			notes text NULL,
			created_at datetime NOT NULL,
			fetched_at datetime NULL,
			PRIMARY KEY  (id),
			KEY run_idx (run_id),
			KEY status_idx (run_id, status),
			KEY url_idx (url(191)),
			KEY score_idx (run_id, score)
		) {$charset_collate};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	/**
	 * Initialize default plugin options if not already present.
	 *
	 * @return void
	 */
	public static function init_default_options() {
		$defaults = self::default_settings();

		$existing = get_option( 'avandwp_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Existing sites keep every value they already had; only keys that do
		// not exist yet are filled in. A stored model id is never replaced just
		// because the default changed.
		update_option( 'avandwp_settings', array_merge( $defaults, $existing ) );

		if ( false === get_option( 'avandwp_activity_log_retention_days', false ) ) {
			update_option( 'avandwp_activity_log_retention_days', 30 );
		}

		if ( false === get_option( AvandWP_Link_Intelligence::RULES_OPTION, false ) ) {
			update_option( AvandWP_Link_Intelligence::RULES_OPTION, array(), false );
		}

		self::seed_feature_options();
	}

	/**
	 * Seed the option rows owned by individual features.
	 *
	 * These lived at the end of `default_settings()` where a `return` above them
	 * made them unreachable, so a fresh install never received chat or Telegram
	 * defaults. Seeding belongs here — a getter that returns the settings array
	 * must not have write side effects.
	 *
	 * @return void
	 */
	private static function seed_feature_options() {
		$chat_defaults = array(
			'enabled'            => 1,
			'title'              => 'دستیار هوشمند سایت',
			'subtitle'           => 'پاسخ‌گویی آنی بر اساس اطلاعات سایت و ثبت تیکت پشتیبانی',
			'welcome_message'    => 'سلام! چطور می‌توانم در مورد محصولات، خدمات یا مطالب سایت به شما کمک کنم؟',
			'primary_color'      => '#2563EB',
			'position'           => 'bottom-right',
			'enable_voice_input' => 1,
			'enable_knowledge'   => 1,
			'enable_handoff'     => 1,
			'load_vazirmatn'     => 0,
			'system_prompt'      => 'شما دستیار هوشمند و رسمی این وب‌سایت هستید. فقط بر اساس اطلاعات و منابع سایت که در اختیار شما قرار می‌گیرد پاسخ دقیق، کوتاه و محترمانه به زبان فارسی بدهید. اگر پاسخ پرسش کاربر در اطلاعات سایت وجود ندارد، از حدس زدن خودداری کنید و پیشنهاد دهید از دکمه «ثبت درخواست برای کارشناس» استفاده کند.',
		);

		$existing_chat = get_option( 'avandwp_chat_settings', false );
		if ( ! is_array( $existing_chat ) ) {
			update_option( 'avandwp_chat_settings', $chat_defaults );
		}

		$tg_defaults = array(
			'chat_id'             => '',
			'proxy_url'           => '',
			'auto_publish_posts'  => 0,
			'notify_woo_orders'   => 1,
			'notify_support'      => 1,
		);
		if ( false === get_option( 'avandwp_telegram_settings', false ) ) {
			update_option( 'avandwp_telegram_settings', $tg_defaults );
		}
	}



	/**
	 * Canonical default settings.
	 *
	 * Exposed so the settings screen can show the same defaults the activator
	 * writes, instead of a second, drifting copy.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings() {
		return array(
			// Provider slots. Model identifiers are the ones the provider
			// actually publishes; the previous defaults (`gpt-4o-mini`,
			// `dall-e-3`) are absent from GapGPT's own catalogue and could only
			// produce failed, billable requests. Verified 2026-10-12 —
			// see AvandWP_Price_Catalog::GAPGPT_SOURCE.
			'default_provider'          => 'gapgpt',
			'fallback_provider'         => '',
			'model_gapgpt'              => 'gpt-5.6-terra',
			'model_openai'              => 'gpt-5.6-terra',
			'model_gemini'              => 'gemini-2.5-flash',
			'model_claude'              => 'claude-sonnet-5',
			'temperature'               => 0.7,
			'max_tokens'                => 8000,
			'request_timeout'           => 120,

			// Image generation.
			'image_provider'            => 'gapgpt',
			'image_model'               => 'gpt-image-2',

			// Long-form writer.
			'longform_default_length'   => AvandWP_Article_Brief::DEFAULT_LENGTH,
			'longform_require_confirm'  => 1,

			// Links.
			'max_internal_links'        => 6,
			'max_external_links'        => 6,
			'enable_link_suggestions'   => 1,
			'enable_auto_fix'           => 1,

			// Knowledge index.
			'enable_true_embeddings'    => 0,

			// Cost governance.
			'spend_guard_enabled'       => 1,
			'daily_spend_limit_usd'     => 5.0,
			'daily_operation_limit'     => 500,

			// Site audit.
			'audit_batch_size'          => AvandWP_Site_Auditor::DEFAULT_BATCH,
			'audit_max_urls'            => 150,

			// Google integrations (Modules H and I). The client id is public
			// and lives here; the client secret and refresh token are stored
			// encrypted by AvandWP_Vault and never appear in this array.
			'google_client_id'          => '',
			'ga4_property_id'           => '',
			// Selected on the «اتصال‌ها» screen from the account's own property
			// list; empty means "match the site's own host automatically".
			'gsc_property'              => '',

			// Data governance.
			'delete_data_on_uninstall'  => 0,
			'telemetry_enabled'         => 0,
		);
	}

	/**
	 * Register cron schedules and recurring events.
	 *
	 * @return void
	 */
	public static function schedule_crons() {
		if ( ! wp_next_scheduled( 'avandwp_cron_process_jobs' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'avandwp_cron_process_jobs' );
		}
		if ( ! wp_next_scheduled( 'avandwp_cron_daily_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'avandwp_cron_daily_maintenance' );
		}
	}
}
