<?php
/**
 * Main Plugin Singleton Controller (`AvandWP_Plugin`).
 *
 * Coordinates REST API routes, authenticated Admin AJAX actions, cron callbacks,
 * database version upgrades, and frontend JSON-LD FAQ schema output.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AvandWP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AvandWP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->maybe_upgrade_db();
		$this->init_modules();
		$this->register_hooks();
	}

	/**
	 * Ensure database tables are up to date when version changes.
	 *
	 * @return void
	 */
	private function maybe_upgrade_db() {
		AvandWP_Activator::maybe_upgrade();
	}

	/**
	 * Initialize admin menu, frontend chat assistant, and Telegram hooks.
	 *
	 * @return void
	 */
	private function init_modules() {
		if ( is_admin() ) {
			$menu = new AvandWP_Admin_Menu();
			$menu->register();
		}

		$chat = new AvandWP_Chat_Assistant();
		$chat->register();

		$tg = new AvandWP_Telegram();
		$tg->register_hooks();
	}

	/**
	 * Register REST API, AJAX, Cron, and Frontend hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Cron jobs
		add_action( 'avandwp_cron_process_jobs', array( 'AvandWP_Job_Queue', 'process_batch' ) );
		add_action( 'avandwp_cron_daily_maintenance', array( $this, 'run_daily_maintenance' ) );

		// FAQ Schema JSON-LD on singular posts & products
		add_action( 'wp_head', array( $this, 'output_faq_schema_jsonld' ) );

		// Authenticated XLSX Export
		add_action( 'admin_post_avandwp_export_xlsx', array( $this, 'handle_export_xlsx' ) );

		// Admin AJAX actions
		$ajax_actions = array(
			'save_settings',
			'test_provider',
			'repair_system',
			'generate_article',
			'generate_image',
			'generate_speech',
			'woo_scan',
			'woo_optimize',
			'woo_create_draft',
			'poll_job',
			'process_jobs_now',
			'update_ticket',
			'save_faqs',
			'sync_knowledge',
			'save_chat_settings',
			'save_telegram',
			'test_telegram',
			'publish_post_telegram',
			'run_content_audit',
			'fix_post_seo',
			// Long-form article pipeline (Module A).
			'generate_longform',
			// Quality Lab and link intelligence (Modules B and C).
			'analyze_content',
			'analyze_links',
			'apply_links',
			'revert_link',
			'insert_links_in_post',
			// Site audit (Module G).
			'audit_start',
			'audit_step',
			'audit_report',
			'audit_pause',
			// Google integrations (Modules H and I).
			'google_connect',
			'google_disconnect',
			'google_data',
			'google_select_property',
			'google_select_site',
		);

		foreach ( $ajax_actions as $act ) {
			add_action( 'wp_ajax_avandwp_' . $act, array( $this, 'ajax_' . $act ) );
		}
	}

	/**
	 * Daily maintenance cron: cleanup old finished jobs.
	 *
	 * @return void
	 */
	public function run_daily_maintenance() {
		AvandWP_Job_Queue::cleanup_old_jobs( 14 );
	}

	/**
	 * Output FAQPage JSON-LD Schema in `<head>` for posts/products with `_avandwp_faq_items`.
	 *
	 * @return void
	 */
	public function output_faq_schema_jsonld() {
		if ( ! is_singular( array( 'post', 'product', 'page' ) ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		$faqs    = get_post_meta( $post_id, '_avandwp_faq_items', true );
		if ( empty( $faqs ) || ! is_array( $faqs ) ) {
			return;
		}

		$entities = array();
		foreach ( $faqs as $f ) {
			$q = trim( (string) ( $f['question'] ?? '' ) );
			$a = trim( (string) ( $f['answer'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $q,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $a,
					),
				);
			}
		}

		if ( empty( $entities ) ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);

		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * Handle authenticated XLSX export download.
	 *
	 * @return void
	 */
	public function handle_export_xlsx() {
		if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() ) ) {
			wp_die( 'شما دسترسی لازم برای دریافت این گزارش را ندارید.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'avandwp_export_xlsx_nonce', '_wpnonce' );
		AvandWP_Reports::stream_xlsx_report();
	}

	/* ==========================================================================
	 * REST API Endpoints (`/wp-json/avandwp/v1/...`)
	 * ========================================================================== */

	/**
	 * Register REST API routes for frontend chat, support tickets, voice upload, and feedback.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'avandwp/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/support',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_support' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/support/audio',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_support_audio' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_feedback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST POST `/avandwp/v1/chat`
	 */
	public function rest_handle_chat( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'chat', 15, 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$message    = (string) $request->get_param( 'message' );
		$session_id = (string) $request->get_param( 'session_id' );
		$history    = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$chat = new AvandWP_Chat_Assistant();
		$res  = $chat->handle_message( $message, $session_id, $history );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $res->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'reply'      => $res['reply'],
				'sources'    => $res['sources'],
				'log_id'     => $res['log_id'],
				'session_id' => $res['session_id'],
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/support`
	 */
	public function rest_handle_support( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'support', 5, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$ticket_id = AvandWP_Support_Desk::create_ticket(
			array(
				'session_id'   => (string) $request->get_param( 'session_id' ),
				'user_name'    => (string) $request->get_param( 'user_name' ),
				'user_contact' => (string) $request->get_param( 'user_contact' ),
				'subject'      => (string) $request->get_param( 'subject' ),
				'message'      => (string) $request->get_param( 'message' ),
				'priority'     => (string) $request->get_param( 'priority' ),
				'page_url'     => (string) $request->get_param( 'page_url' ),
			)
		);

		if ( is_wp_error( $ticket_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $ticket_id->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'ticket_id' => $ticket_id,
				'message'   => sprintf( 'درخواست شما با کد پیگیری #%d ثبت شد. کارشناسان ما به‌زودی با شما تماس می‌گیرند.', $ticket_id ),
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/support/audio`
	 *
	 * Fixes the bug where $file['tmp_name'] was passed after wp_handle_upload() moved the file.
	 */
	public function rest_handle_support_audio( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'support_audio', 3, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$files = $request->get_file_params();
		if ( empty( $files['audio'] ) || ! is_array( $files['audio'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'فایل صوتی ارسال نشده است.' ), 400 );
		}

		$file = $files['audio'];
		if ( ! empty( $file['size'] ) && (int) $file['size'] > 6 * 1024 * 1024 ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'حجم فایل صوتی نباید بیشتر از ۶ مگابایت باشد.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'webm' => 'audio/webm',
					'ogg'  => 'audio/ogg',
					'mp3'  => 'audio/mpeg',
					'wav'  => 'audio/wav',
					'm4a'  => 'audio/mp4',
				),
			)
		);

		if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) || empty( $uploaded['url'] ) ) {
			$err = $uploaded['error'] ?? 'خطا در بارگذاری فایل صوتی.';
			return new WP_REST_Response( array( 'success' => false, 'message' => $err ), 400 );
		}

		// Transcribe using the moved file path ($uploaded['file']) — NOT $file['tmp_name']!
		$ai         = new AvandWP_AI_Client();
		$transcript = $ai->transcribe_audio_file( $uploaded['file'] );
		$trans_text = ! is_wp_error( $transcript ) ? $transcript : '';

		$user_contact = sanitize_text_field( (string) $request->get_param( 'user_contact' ) );
		$user_name    = sanitize_text_field( (string) $request->get_param( 'user_name' ) );
		$ticket_id    = 0;

		if ( '' !== $user_contact ) {
			$created = AvandWP_Support_Desk::create_ticket(
				array(
					'session_id'   => (string) $request->get_param( 'session_id' ),
					'user_name'    => $user_name,
					'user_contact' => $user_contact,
					'subject'      => 'پیام صوتی پشتیبانی',
					'message'      => '' !== $trans_text ? $trans_text : '[پیام صوتی پیوست‌شده]',
					'audio_url'    => $uploaded['url'],
					'transcript'   => $trans_text,
				)
			);
			if ( ! is_wp_error( $created ) ) {
				$ticket_id = (int) $created;
			}
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'audio_url'  => $uploaded['url'],
				'transcript' => $trans_text,
				'ticket_id'  => $ticket_id,
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/feedback`
	 */
	public function rest_handle_feedback( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'feedback', 20, 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$id = AvandWP_Support_Desk::record_feedback(
			array(
				'log_id'     => (int) $request->get_param( 'log_id' ),
				'session_id' => (string) $request->get_param( 'session_id' ),
				'rating'     => (string) $request->get_param( 'rating' ),
				'question'   => (string) $request->get_param( 'question' ),
				'answer'     => (string) $request->get_param( 'answer' ),
				'comment'    => (string) $request->get_param( 'comment' ),
			)
		);

		return new WP_REST_Response( array( 'success' => (bool) $id ), 200 );
	}

	/* ==========================================================================
	 * Admin AJAX Handlers
	 * ========================================================================== */

	/**
	 * Read a sanitised text field from the current AJAX request.
	 *
	 * All AJAX input flows through these helpers so that unslashing and
	 * sanitising happen in exactly one place per type. Scattering
	 * `wp_unslash( $_POST[...] )` across dozens of handlers is how a single
	 * missed sanitiser becomes a stored-XSS finding, and it makes the raw-input
	 * audit impossible to complete by reading.
	 *
	 * @param string $key     Field name.
	 * @param string $default Fallback when the field is absent.
	 * @return string
	 */
	private function post_text( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Read a sanitised textarea field (keeps newlines, strips tags).
	 *
	 * @param string $key     Field name.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function post_textarea( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Read a sanitised key/slug field.
	 *
	 * @param string $key     Field name.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function post_key( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return sanitize_key( wp_unslash( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Read an integer field.
	 *
	 * @param string $key     Field name.
	 * @param int    $default Fallback.
	 * @return int
	 */
	private function post_int( $key, $default = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return (int) ( $_POST[ $key ] ?? $default );
	}

	/**
	 * Read an array field (e.g. a set of form inputs sharing one name).
	 *
	 * The values are unslashed but not sanitised here: the caller knows what each
	 * element means and is responsible for validating it.
	 *
	 * @param string $key Field name.
	 * @return array<string, mixed>
	 */
	private function post_array( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		$raw = wp_unslash( $_POST[ $key ] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are validated by the caller.

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Read a float field.
	 *
	 * @param string $key     Field name.
	 * @param float  $default Fallback.
	 * @return float
	 */
	private function post_float( $key, $default = 0.0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return (float) ( $_POST[ $key ] ?? $default );
	}

	/**
	 * Read a boolean checkbox field.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private function post_flag( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Read a raw, unslashed field for values that are decoded and validated by
	 * the caller (JSON payloads, URL lists, multi-line keyword lists).
	 *
	 * @param string $key     Field name.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function post_raw( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce first.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the caller decodes and validates the value.
		return (string) wp_unslash( $_POST[ $key ] ?? $default );
	}

	/**
	 * Verify AJAX nonce and required capability.
	 *
	 * @param string $capability WordPress capability.
	 * @return void
	 */
	private function verify_ajax( $capability = 'edit_posts' ) {
		check_ajax_referer( 'avandwp_admin_nonce', 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => 'شما سطح دسترسی لازم برای انجام این عملیات را ندارید.' ), 403 );
		}
	}

	/**
	 * AJAX: Save API keys to Vault and general settings (`manage_options`).
	 */
	public function ajax_save_settings() {
		$this->verify_ajax( 'manage_options' );

		// Keys arrive as one array field; only the vault's own slot names are
		// accepted, and each value is sanitised below before it is stored.
		$keys = $this->post_array( 'keys' );
		foreach ( array_keys( AvandWP_Vault::supported_slots() ) as $slot ) {
			if ( isset( $keys[ $slot ] ) ) {
				$val = trim( sanitize_text_field( (string) $keys[ $slot ] ) );

				// Only update when the user typed a new value or explicitly asked
				// to clear; the masked placeholder must never be stored.
				if ( '' !== $val && false === strpos( $val, '••••' ) ) {
					AvandWP_Vault::set_key( $slot, $val );
				} elseif ( $this->post_flag( 'clear_keys' ) ) {
					// The user explicitly asked to remove this slot.
					AvandWP_Vault::set_key( $slot, '' );
				}
			}
		}

		$settings = get_option( 'avandwp_settings', array() );
		$defaults = AvandWP_Activator::default_settings();

		// Provider slots. A submitted model id is validated against that
		// provider's catalogue: an unverified identifier is refused with an
		// explanation instead of being stored and then sent to the API.
		$requested_provider = $this->post_key( 'default_provider' );
		$settings['default_provider'] = AvandWP_Provider_Registry::is_known( $requested_provider )
			? $requested_provider
			: $defaults['default_provider'];

		$fallback_raw                  = $this->post_key( 'fallback_provider' );
		$settings['fallback_provider'] = ( '' === $fallback_raw || AvandWP_Provider_Registry::is_known( $fallback_raw ) )
			? $fallback_raw
			: '';

		$rejected_models = array();
		$rejected        = array();

		foreach ( array( 'gapgpt', 'openai', 'gemini', 'claude' ) as $slot ) {
			$key   = 'model_' . $slot;
			$value = $this->post_text( $key );

			if ( '' === $value ) {
				$settings[ $key ] = $defaults[ $key ];
				continue;
			}

			$resolved = AvandWP_Provider_Registry::resolve_model( 'text', $slot, $value );

			if ( is_wp_error( $resolved ) ) {
				$rejected_models[] = sprintf( '%s → %s', $slot, $value );
				$settings[ $key ]  = $defaults[ $key ];
				continue;
			}

			$settings[ $key ] = $resolved['model'];
		}

		$image_provider = $this->post_key( 'image_provider', 'gapgpt' );

		$settings['image_provider'] = AvandWP_Provider_Registry::is_known( $image_provider )
			? $image_provider
			: $defaults['image_provider'];

		$image_model = $this->post_text( 'image_model' );

		if ( '' !== $image_model ) {
			$resolved_image = AvandWP_Provider_Registry::resolve_model( 'image', $settings['image_provider'], $image_model );

			if ( is_wp_error( $resolved_image ) ) {
				$rejected_models[]       = sprintf( '%s (تصویر) → %s', $settings['image_provider'], $image_model );
				$settings['image_model'] = $defaults['image_model'];
			} else {
				$settings['image_model'] = $resolved_image['model'];
			}
		} else {
			$settings['image_model'] = $defaults['image_model'];
		}

		$settings['temperature'] = max( 0.0, min( 1.5, $this->post_float( 'temperature', (float) $defaults['temperature'] ) ) );

		// The ceiling is the provider limit, not an arbitrary 4,096: the previous
		// clamp made the required 4,500-word article impossible to produce.
		$settings['max_tokens']      = max( 200, min( AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS, $this->post_int( 'max_tokens', (int) $defaults['max_tokens'] ) ) );
		$settings['request_timeout'] = max( 15, min( 300, $this->post_int( 'request_timeout', (int) $defaults['request_timeout'] ) ) );

		$settings['longform_default_length'] = max(
			AvandWP_Article_Brief::MIN_LENGTH,
			min( AvandWP_Article_Brief::MAX_LENGTH, $this->post_int( 'longform_default_length', (int) $defaults['longform_default_length'] ) )
		);
		$settings['longform_require_confirm'] = $this->post_flag( 'longform_require_confirm' ) ? 1 : 0;

		$settings['max_internal_links']      = max( 0, min( 30, $this->post_int( 'max_internal_links', (int) $defaults['max_internal_links'] ) ) );
		$settings['max_external_links']      = max( 0, min( 30, $this->post_int( 'max_external_links', (int) $defaults['max_external_links'] ) ) );
		$settings['enable_link_suggestions'] = $this->post_flag( 'enable_link_suggestions' ) ? 1 : 0;
		$settings['enable_auto_fix']         = $this->post_flag( 'enable_auto_fix' ) ? 1 : 0;
		$settings['enable_true_embeddings']  = $this->post_flag( 'enable_true_embeddings' ) ? 1 : 0;

		$settings['spend_guard_enabled']   = $this->post_flag( 'spend_guard_enabled' ) ? 1 : 0;
		$settings['daily_spend_limit_usd'] = max( 0.0, min( 10000.0, $this->post_float( 'daily_spend_limit_usd', (float) $defaults['daily_spend_limit_usd'] ) ) );
		$settings['daily_operation_limit'] = max( 1, min( 100000, $this->post_int( 'daily_operation_limit', (int) $defaults['daily_operation_limit'] ) ) );

		$settings['audit_batch_size'] = max( 1, min( 40, $this->post_int( 'audit_batch_size', (int) $defaults['audit_batch_size'] ) ) );
		$settings['audit_max_urls']   = max( 10, min( AvandWP_Site_Auditor::MAX_URLS, $this->post_int( 'audit_max_urls', (int) $defaults['audit_max_urls'] ) ) );

		// Google integration identity. The client id is public by design; the
		// secret lives in the vault, and the GA4 property id is validated as a
		// plain numeric id because the Data API accepts nothing else.
		$client_id_raw = trim( $this->post_text( 'google_client_id' ) );

		if ( '' === $client_id_raw ) {
			$settings['google_client_id'] = '';
		} elseif ( preg_match( '/^[0-9A-Za-z._-]+\.apps\.googleusercontent\.com$/', $client_id_raw ) ) {
			$settings['google_client_id'] = $client_id_raw;
		} else {
			$rejected[]                   = 'Google Client ID (قالب معتبر: xxxx.apps.googleusercontent.com)';
			$settings['google_client_id'] = (string) ( $settings['google_client_id'] ?? '' );
		}

		$property_raw                = preg_replace( '/[^0-9]/', '', $this->post_raw( 'ga4_property_id' ) );
		$settings['ga4_property_id'] = '' === $property_raw ? '' : (string) absint( $property_raw );

		$settings['delete_data_on_uninstall'] = $this->post_flag( 'delete_data_on_uninstall' ) ? 1 : 0;
		$settings['telemetry_enabled']        = $this->post_flag( 'telemetry_enabled' ) ? 1 : 0;

		update_option( 'avandwp_settings', $settings );
		AvandWP_Logger::log( 'settings.updated', 'تنظیمات و کلیدهای دسترسی آوند ذخیره شدند.', 'success' );

		$message = 'تنظیمات و کلیدهای API ذخیره شدند.';

		if ( AvandWP_Vault::crypto_available() ) {
			$message = 'تنظیمات و کلیدهای API با رمزنگاری AES-256-GCM ذخیره شدند.';
		} else {
			// Never claim encryption that did not happen.
			$message = 'تنظیمات ذخیره شد، اما افزونه OpenSSL روی این سرور فعال نیست؛ کلیدها فقط با base64 ذخیره شده‌اند و رمزنگاری نشده‌اند.';
		}

		if ( ! empty( $rejected ) ) {
			$message .= ' مقادیر زیر پذیرفته نشدند: ' . implode( ' ، ', $rejected );
		}

		if ( ! empty( $rejected_models ) ) {
			// Never silently replace a choice: say exactly which identifier was
			// refused, why, and what was stored instead.
			$message .= ' موارد زیر پذیرفته نشدند چون در فهرست تأییدشده سرویس‌دهنده نیستند و مقدار پیش‌فرض جایگزین شد: '
				. implode( ' ، ', $rejected_models )
				. sprintf( ' (فهرست در %s بازبینی شده است).', AvandWP_Price_Catalog::VERIFIED_ON );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	public function ajax_test_provider() {
		$this->verify_ajax( 'manage_options' );
		$provider = sanitize_key( $_POST['provider'] ?? 'gapgpt' );

		$ai  = new AvandWP_AI_Client();
		$res = $ai->test_connection( $provider );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf( 'اتصال به %s (%s) برقرار است — زمان پاسخ: %d میلی‌ثانیه', strtoupper( $res['provider'] ), $res['model'], $res['latency_ms'] ),
				'data'    => $res,
			)
		);
	}

	/**
	 * AJAX: Repair DB tables & WP-Cron (`manage_options`).
	 */
	public function ajax_repair_system() {
		$this->verify_ajax( 'manage_options' );
		$report = AvandWP_Health::repair_system();
		wp_send_json_success(
			array(
				'message' => 'جداول دیتابیس و زمان‌بندی کرون با موفقیت بررسی و ترمیم شدند.',
				'report'  => $report,
			)
		);
	}

	/**
	 * AJAX: Generate article (either directly or queued in background).
	 */
	public function ajax_generate_article() {
		$this->verify_ajax( 'edit_posts' );

		$payload = array(
			'topic'          => sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) ),
			'keyword'        => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
			'tone'           => sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'حرفه‌ای و کاربردی' ) ),
			'target_words'   => (int) ( $_POST['target_words'] ?? 1000 ),
			'post_status'    => sanitize_key( $_POST['post_status'] ?? 'draft' ),
			'category_id'    => (int) ( $_POST['category_id'] ?? 0 ),
			'generate_image' => $this->post_flag( 'generate_image' ) ? 1 : 0,
			'generate_audio' => $this->post_flag( 'generate_audio' ) ? 1 : 0,
			'use_knowledge'  => $this->post_flag( 'use_knowledge' ) ? 1 : 0,
		);

		if ( '' === $payload['topic'] ) {
			wp_send_json_error( array( 'message' => 'لطفاً موضوع مقاله را وارد کنید.' ) );
		}

		$run_background = $this->post_flag( 'background' );
		if ( $run_background ) {
			$job_id = AvandWP_Job_Queue::enqueue( 'studio_article', $payload );
			if ( ! $job_id ) {
				wp_send_json_error( array( 'message' => 'خطا در ثبت کار در صف پس‌زمینه.' ) );
			}
			wp_send_json_success(
				array(
					'queued'  => true,
					'job_id'  => $job_id,
					'message' => sprintf( 'تولید مقاله در صف پس‌زمینه (#%d) قرار گرفت و اکنون در حال پردازش است.', $job_id ),
				)
			);
		}

		$studio = new AvandWP_Content_Studio();
		$res    = $studio->generate_article_package( $payload );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'queued'  => false,
				'article' => $res,
				'message' => sprintf( 'پیش‌نویس مقاله «%s» (%d کلمه) با موفقیت ساخته شد.', $res['title'], $res['word_count'] ),
			)
		);
	}

	/**
	 * AJAX: Standalone Image Generation in Studio.
	 */
	public function ajax_generate_image() {
		$this->verify_ajax( 'edit_posts' );

		$prompt   = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$alt_text = sanitize_text_field( wp_unslash( $_POST['alt_text'] ?? $prompt ) );
		$size     = sanitize_text_field( wp_unslash( $_POST['size'] ?? '1024x1024' ) );
		$provider = sanitize_key( $_POST['provider'] ?? '' );

		$client = new AvandWP_Image_Client();
		$res    = $client->generate_and_attach(
			$prompt,
			array(
				'provider' => $provider,
				'size'     => $size,
				'alt_text' => $alt_text,
			)
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'image'   => $res,
				'message' => 'تصویر با موفقیت تولید و به کتابخانه رسانه وردپرس اضافه شد.',
			)
		);
	}

	/**
	 * AJAX: Standalone Speech (TTS) Generation in Studio.
	 */
	public function ajax_generate_speech() {
		$this->verify_ajax( 'edit_posts' );

		$text  = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		$voice = sanitize_key( $_POST['voice'] ?? 'nova' );

		$ai  = new AvandWP_AI_Client();
		$res = $ai->generate_speech( $text, $voice );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'audio'   => $res,
				'message' => 'فایل صوتی MP3 با موفقیت ساخته و در کتابخانه رسانه ذخیره شد.',
			)
		);
	}

	/**
	 * AJAX: Scan weak WooCommerce products.
	 */
	public function ajax_woo_scan() {
		$this->verify_ajax( 'edit_posts' );
		$min_words = max( 20, (int) ( $_POST['min_words'] ?? 80 ) );
		$woo       = new AvandWP_Woo_Assistant();
		$products  = $woo->scan_weak_products( $min_words, 60 );

		wp_send_json_success(
			array(
				'products' => $products,
				'count'    => count( $products ),
			)
		);
	}

	/**
	 * AJAX: Optimize single or bulk WooCommerce products.
	 */
	public function ajax_woo_optimize() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$bulk_ids   = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'intval', $_POST['product_ids'] ) : array();
		$tone       = sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'مشاور خرید حرفه‌ای' ) );
		$specs_hint = sanitize_textarea_field( wp_unslash( $_POST['specs_hint'] ?? '' ) );

		// SECURITY (baseline F-03): optimisation rewrites the product's
		// description, short description, tags and SEO metadata. Every target
		// (including each bulk id) must be an object the user may edit.
		if ( ! empty( $bulk_ids ) ) {
			foreach ( array_slice( $bulk_ids, 0, 20 ) as $pid ) {
				AvandWP_Capabilities::enforce_or_die(
					AvandWP_Capabilities::user_can_edit_object( (int) $pid )
				);
			}
		} else {
			AvandWP_Capabilities::enforce_or_die(
				AvandWP_Capabilities::user_can_edit_object( $product_id )
			);
		}

		if ( ! empty( $bulk_ids ) ) {
			$queued_jobs = array();
			foreach ( array_slice( $bulk_ids, 0, 20 ) as $pid ) {
				if ( $pid > 0 ) {
					$jid = AvandWP_Job_Queue::enqueue(
						'woo_optimize',
						array(
							'product_id' => $pid,
							'options'    => array( 'tone' => $tone ),
						)
					);
					if ( $jid ) {
						$queued_jobs[] = $jid;
					}
				}
			}
			wp_send_json_success(
				array(
					'queued'  => true,
					'job_ids' => $queued_jobs,
					'message' => sprintf( '%d محصول در صف پردازش پس‌زمینه قرار گرفتند.', count( $queued_jobs ) ),
				)
			);
		}

		$woo = new AvandWP_Woo_Assistant();
		$res = $woo->optimize_product(
			$product_id,
			array(
				'tone'       => $tone,
				'specs_hint' => $specs_hint,
			)
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'queued'  => false,
				'product' => $res,
				'message' => sprintf( 'محصول «%s» (%d کلمه) با موفقیت بهینه‌سازی شد.', $res['title'], $res['word_count'] ),
			)
		);
	}

	/**
	 * AJAX: Create new draft WooCommerce product from brief.
	 */
	public function ajax_woo_create_draft() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		// Creating a product requires the product-specific capability, which
		// WooCommerce maps separately from ordinary post editing.
		if ( ! AvandWP_Woo_Assistant::current_user_can_create_product() ) {
			wp_send_json_error(
				array( 'message' => 'شما اجازه ساخت محصول جدید را ندارید.' ),
				403
			);
		}

		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$price = sanitize_text_field( wp_unslash( $_POST['price'] ?? '' ) );
		$brief = sanitize_textarea_field( wp_unslash( $_POST['brief'] ?? '' ) );

		$woo = new AvandWP_Woo_Assistant();
		$res = $woo->create_product_draft( $name, $price, $brief );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'product' => $res,
				'message' => sprintf( 'پیش‌نویس محصول «%s» با موفقیت ساخته و بهینه‌سازی شد.', $res['title'] ),
			)
		);
	}

	/**
	 * AJAX: Poll status of a background job (and trigger execution if still pending).
	 *
	 * SECURITY (baseline F-03): job rows contain the full generated article and
	 * provider metadata. The 1.0.0 handler returned any job to any user holding
	 * `edit_posts`. Access is now restricted to the job's creator or an operator.
	 */
	public function ajax_poll_job() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$job    = AvandWP_Job_Queue::get_job( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'کار مورد نظر در صف یافت نشد.' ) );
		}

		$is_owner   = ( (int) $job['created_by'] === get_current_user_id() );
		$is_manager = AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() );

		if ( ! $is_owner && ! $is_manager ) {
			wp_send_json_error( array( 'message' => 'شما به این کار در صف دسترسی ندارید.' ), 403 );
		}

		// A specific job may only be nudged along by someone allowed to see it.
		if ( 'pending' === $job['status'] ) {
			AvandWP_Job_Queue::process_batch( 1, $job_id );
			$job = AvandWP_Job_Queue::get_job( $job_id );
		}

		wp_send_json_success( array( 'job' => AvandWP_Job_Queue::public_view( $job ) ) );
	}

	/**
	 * AJAX: Process pending jobs batch immediately.
	 *
	 * This runs *every* queued job regardless of who queued it, so it is an
	 * operator-level action.
	 */
	public function ajax_process_jobs_now() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );
		$res = AvandWP_Job_Queue::process_batch( 2 );
		wp_send_json_success(
			array(
				'processed' => $res['processed'],
				'message'   => sprintf( '%d کار از صف پس‌زمینه پردازش شد.', $res['processed'] ),
			)
		);
	}

	/**
	 * AJAX: Update support ticket status/reply.
	 */
	public function ajax_update_ticket() {
		// Support tickets contain customer contact details and message bodies,
		// so handling them requires a role that already manages other people's
		// content (Editor and above) rather than plain `edit_posts`.
		$this->verify_ajax( AvandWP_Capabilities::operate_others_capability() );

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;

		if ( $ticket_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'شناسه تیکت نامعتبر است.' ), 400 );
		}
		$status    = sanitize_key( $_POST['status'] ?? 'in_progress' );
		$reply     = sanitize_textarea_field( wp_unslash( $_POST['admin_reply'] ?? '' ) );
		$priority  = sanitize_key( $_POST['priority'] ?? '' );

		$res = AvandWP_Support_Desk::update_ticket_admin( $ticket_id, $status, $reply, $priority );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'وضعیت و پاسخ تیکت با موفقیت ذخیره شد.' ) );
	}

	/**
	 * AJAX: Save custom FAQs and rebuild Knowledge Base.
	 */
	public function ajax_save_faqs() {
		// Site-wide knowledge, served to every visitor by the public chat
		// widget: an administrator-level surface.
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );
		$faqs = isset( $_POST['faqs'] ) && is_array( $_POST['faqs'] ) ? wp_unslash( $_POST['faqs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		AvandWP_Knowledge_Base::save_faqs( $faqs );
		$kb   = new AvandWP_Knowledge_Base();
		$sync = $kb->sync_all_content();

		wp_send_json_success(
			array(
				'message' => sprintf( 'پرسش‌های متداول ذخیره شدند و پایگاه دانش (%d مورد) به‌روزرسانی شد.', $sync['total'] ),
				'sync'    => $sync,
			)
		);
	}

	/**
	 * AJAX: Rebuild Knowledge Base index.
	 */
	public function ajax_sync_knowledge() {
		// Rebuilding the index truncates and repopulates a site-wide table and
		// can issue a paid embedding request per document: administrator-level.
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );
		$kb   = new AvandWP_Knowledge_Base();
		$sync = $kb->sync_all_content();

		wp_send_json_success(
			array(
				'message' => sprintf( 'شاخص پایگاه دانش سایت با موفقیت بازسازی شد (%d نوشته/محصول و %d پرسش متداول).', $sync['indexed_posts'], $sync['indexed_faqs'] ),
				'sync'    => $sync,
			)
		);
	}

	/**
	 * AJAX: Save frontend chat assistant settings (`manage_options`).
	 */
	public function ajax_save_chat_settings() {
		$this->verify_ajax( 'manage_options' );

		$chat_settings = array(
			'enabled'            => $this->post_flag( 'enabled' ) ? 1 : 0,
			'title'              => sanitize_text_field( wp_unslash( $_POST['title'] ?? 'دستیار هوشمند سایت' ) ),
			'subtitle'           => sanitize_text_field( wp_unslash( $_POST['subtitle'] ?? '' ) ),
			'welcome_message'    => sanitize_textarea_field( wp_unslash( $_POST['welcome_message'] ?? '' ) ),
			'primary_color'      => sanitize_hex_color( wp_unslash( $_POST['primary_color'] ?? '#2563EB' ) ) ?: '#2563EB',
			'position'           => ( 'bottom-left' === $this->post_key( 'position', 'bottom-right' ) ) ? 'bottom-left' : 'bottom-right',
			'enable_voice_input' => $this->post_flag( 'enable_voice_input' ) ? 1 : 0,
			'enable_knowledge'   => $this->post_flag( 'enable_knowledge' ) ? 1 : 0,
			'enable_handoff'     => $this->post_flag( 'enable_handoff' ) ? 1 : 0,
			'load_vazirmatn'     => $this->post_flag( 'load_vazirmatn' ) ? 1 : 0,
			'system_prompt'      => sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ),
		);

		update_option( 'avandwp_chat_settings', $chat_settings );
		wp_send_json_success( array( 'message' => 'تنظیمات ویجت چت‌بات ذخیره شد.' ) );
	}

	/**
	 * AJAX: Save Telegram settings (`manage_options`).
	 */
	public function ajax_save_telegram() {
		$this->verify_ajax( 'manage_options' );

		$proxy_url = trim( sanitize_text_field( wp_unslash( $_POST['proxy_url'] ?? '' ) ) );
		if ( '' !== $proxy_url ) {
			$scheme = wp_parse_url( $proxy_url, PHP_URL_SCHEME );
			if ( ! wp_http_validate_url( $proxy_url ) || 'https' !== strtolower( (string) $scheme ) ) {
				wp_send_json_error( array( 'message' => 'آدرس پروکسی باید یک URL معتبر با پروتکل https:// باشد.' ) );
			}
		}

		$bot_token = trim( $this->post_text( 'bot_token' ) );

		if ( '' !== $bot_token ) {
			$token = $bot_token;
			if ( '' !== $token && false === strpos( $token, '••••' ) ) {
				AvandWP_Vault::set_key( 'telegram_bot', $token );
			}
		}

		$tg_settings = array(
			'chat_id'            => sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) ),
			'proxy_url'          => $proxy_url,
			'auto_publish_posts' => $this->post_flag( 'auto_publish_posts' ) ? 1 : 0,
			'notify_woo_orders'  => $this->post_flag( 'notify_woo_orders' ) ? 1 : 0,
			'notify_support'     => $this->post_flag( 'notify_support' ) ? 1 : 0,
		);

		update_option( 'avandwp_telegram_settings', $tg_settings );
		wp_send_json_success( array( 'message' => 'تنظیمات تلگرام با موفقیت ذخیره شد.' ) );
	}

	/**
	 * AJAX: Run Telegram 3-step live diagnostics & send test message (`manage_options`).
	 */
	public function ajax_test_telegram() {
		$this->verify_ajax( 'manage_options' );
		$tg   = new AvandWP_Telegram();
		$diag = $tg->run_diagnostics();
		if ( is_wp_error( $diag ) ) {
			wp_send_json_error( array( 'message' => $diag->get_error_message() ) );
		}

		if ( $this->post_flag( 'send_test_message' ) ) {
			$sent = $tg->send_message( '✅ پیام آزمایشی از افزونه هوشمند آوند (AvandWP) — اتصال تلگرام برقرار است.' );
			if ( is_wp_error( $sent ) ) {
				wp_send_json_error( array( 'message' => $sent->get_error_message(), 'diagnostics' => $diag ) );
			}
		}

		wp_send_json_success(
			array(
				'message'     => sprintf( 'ربات @%s متصل و آماده ارسال است.', $diag['bot_username'] ),
				'diagnostics' => $diag,
			)
		);
	}

	/**
	 * AJAX: Publish a specific post to Telegram (`edit_posts`).
	 */
	public function ajax_publish_post_telegram() {
		// Broadcasting to the site's public channel is a brand-level action: it
		// requires authority over the specific post AND the capability to
		// publish posts, not merely to save drafts.
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		AvandWP_Capabilities::enforce_or_die(
			AvandWP_Capabilities::user_can_edit_object( $post_id )
		);

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( array( 'message' => 'شما اجازه انتشار در کانال تلگرام را ندارید.' ), 403 );
		}

		$tg  = new AvandWP_Telegram();
		$res = $tg->publish_post( $post_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'نوشته با موفقیت در کانال تلگرام منتشر شد.' ) );
	}

	/**
	 * AJAX: Run non-destructive Content & SEO Audit.
	 *
	 * The report summarises every author's content, so it requires the
	 * "operate on others' content" capability rather than plain `edit_posts`.
	 */
	public function ajax_run_content_audit() {
		$this->verify_ajax( AvandWP_Capabilities::operate_others_capability() );
		$auditor = new AvandWP_Content_Auditor();
		$report  = $auditor->run_audit( 50 );
		wp_send_json_success(
			array(
				'message' => sprintf( '%d نوشته بررسی شد (%d مورد نیازمند تکمیل).', $report['scanned_count'], $report['flagged_count'] ),
				'report'  => $report,
			)
		);
	}

	/**
	 * AJAX: Generate and apply SEO meta for a single audited post.
	 *
	 * SECURITY (baseline F-03): the 1.0.0 implementation accepted any
	 * attacker-controlled `post_id` behind a plain `edit_posts` check, so an
	 * Author account could overwrite the SEO title and meta description of any
	 * post on the site — including the front page. The handler now verifies
	 * authority over the specific object before touching its metadata.
	 */
	public function ajax_fix_post_seo() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		AvandWP_Capabilities::enforce_or_die(
			AvandWP_Capabilities::user_can_edit_object( $post_id )
		);

		$auditor = new AvandWP_Content_Auditor();
		$res     = $auditor->generate_seo_meta_for_post( $post_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'عنوان و توضیحات سئو با موفقیت تولید و در متای افزونه سئوی فعال ذخیره شد.',
				'seo'     => $res,
			)
		);
	}

	// =====================================================================
	// Long-form article pipeline (Module A)
	// =====================================================================

	/**
	 * AJAX: Queue (or run) a long-form, sectioned article.
	 *
	 * Long-form generation always runs in the background. A 4,500-word article
	 * is dozens of provider requests; attempting that inside one AJAX request
	 * would time out on any realistic shared host and lose the work.
	 */
	public function ajax_generate_longform() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$raw = array(
			'topic'                 => $this->post_text( 'topic' ),
			'working_title'         => $this->post_text( 'working_title' ),
			'focus_keyword'         => $this->post_text( 'focus_keyword' ),
			'purpose'               => $this->post_textarea( 'purpose' ),
			'audience'              => $this->post_text( 'audience' ),
			'tone'                  => $this->post_text( 'tone' ),
			'writing_style'         => $this->post_textarea( 'writing_style' ),
			'search_intent'         => $this->post_key( 'search_intent', 'informational' ),
			'article_type'          => $this->post_key( 'article_type', 'guide' ),
			'length_mode'           => $this->post_key( 'length_mode', 'words' ),
			'length_target'         => $this->post_int( 'length_target', AvandWP_Article_Brief::DEFAULT_LENGTH ),
			'length_include_spaces' => $this->post_flag( 'length_include_spaces' ) ? 1 : 0,
			'continue_until_target' => $this->post_flag( 'continue_until_target' ) ? 1 : 0,
			'post_status'           => $this->post_key( 'post_status', 'draft' ),
			'confirm_publish'       => $this->post_flag( 'confirm_publish' ) ? 1 : 0,
			'category_id'           => $this->post_int( 'category_id' ),
			'use_knowledge'         => $this->post_flag( 'use_knowledge' ) ? 1 : 0,
			'author_name'           => $this->post_text( 'author_name' ),
			'secondary_keywords'    => AvandWP_Article_Brief::string_list( $this->post_raw( 'secondary_keywords' ) ),
			'entities'              => AvandWP_Article_Brief::string_list( $this->post_raw( 'entities' ) ),
			'required_facts'        => AvandWP_Article_Brief::string_list( $this->post_raw( 'required_facts' ) ),
			'forbidden_claims'      => AvandWP_Article_Brief::string_list( $this->post_raw( 'forbidden_claims' ) ),
			'outline'               => AvandWP_Article_Brief::string_list( $this->post_raw( 'outline' ) ),
			'reference_urls'        => AvandWP_Article_Brief::url_list( $this->post_raw( 'reference_urls' ) ),
			'internal_link_instructions' => $this->post_textarea( 'internal_link_instructions' ),
			'max_internal_links'    => $this->post_int( 'max_internal_links', 6 ),
			'max_external_links'    => $this->post_int( 'max_external_links', 6 ),
			'featured_image'        => array(
				'enabled' => $this->post_flag( 'generate_featured_image' ) ? 1 : 0,
				'size'    => $this->post_text( 'image_size', '1536x1024' ),
				'style'   => $this->post_text( 'image_style' ),
				'model'   => $this->post_text( 'image_model' ),
			),
			'inline_images'         => array(
				'enabled' => $this->post_flag( 'generate_inline_images' ) ? 1 : 0,
				'count'   => $this->post_int( 'inline_image_count' ),
				'size'    => '1024x1024',
			),
		);

		// Validate before queueing: a rejected brief must not create a job that
		// then fails after spending money.
		$brief = AvandWP_Article_Brief::from_array( $raw );

		if ( is_wp_error( $brief ) ) {
			wp_send_json_error( array( 'message' => $brief->get_error_message() ) );
		}

		$job_id = AvandWP_Job_Queue::enqueue( 'longform_article', $raw, array( 'max_attempts' => 2 ) );

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( array( 'message' => $job_id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'queued' => true,
				'job_id' => (int) $job_id,
				'brief'  => array(
					'topic'                 => $brief['topic'],
					'length_target'         => (int) $brief['length_target'],
					'length_mode'           => $brief['length_mode'],
					'post_status'           => $brief['post_status'],
					'continue_until_target' => (bool) $brief['continue_until_target'],
					'requests_budget'       => AvandWP_Longform_Generator::request_budget( $brief ),
				),
				'message' => sprintf(
					'تولید مقاله بلند در صف پس‌زمینه (#%d) ثبت شد. طول هدف: %s %s. تا پایان کار، پیشرفت واقعی هر مرحله نمایش داده می‌شود و می‌توانید این صفحه را ببندید.',
					(int) $job_id,
					number_format_i18n( (int) $brief['length_target'] ),
					'characters' === $brief['length_mode'] ? 'کاراکتر' : 'کلمه'
				),
			)
		);
	}

	// =====================================================================
	// Quality Lab (Module C)
	// =====================================================================

	/**
	 * AJAX: Evaluate content against the three quality profiles.
	 *
	 * Read-only: this never modifies the post it is given. Any correction is a
	 * separate, explicit action.
	 */
	public function ajax_analyze_content() {
		$this->verify_ajax( 'edit_posts' );

		$post_id = $this->post_int( 'post_id' );
		$html    = '';

		if ( $post_id > 0 ) {
			$caps = AvandWP_Capabilities::user_can_edit_object( $post_id );

			if ( is_wp_error( $caps ) ) {
				wp_send_json_error( array( 'message' => $caps->get_error_message() ) );
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				wp_send_json_error( array( 'message' => 'نوشته مورد نظر یافت نشد.' ) );
			}

			$html = (string) $post->post_content;
		} else {
			// Evaluating pasted text is allowed, but only with create rights:
			// the analysis itself costs nothing, yet the endpoint should still
			// not be usable by a subscriber as a free proxy.
			$html = wp_kses_post( $this->post_raw( 'content' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			wp_send_json_error( array( 'message' => 'محتوایی برای ارزیابی وجود ندارد.' ) );
		}

		$context = array(
			'focus_keyword'         => $this->post_text( 'focus_keyword' ),
			'secondary_keywords'    => AvandWP_Article_Brief::string_list( $this->post_raw( 'secondary_keywords' ) ),
			'key_questions'         => AvandWP_Article_Brief::string_list( $this->post_raw( 'key_questions' ) ),
			'length_mode'           => $this->post_key( 'length_mode', 'words' ),
			'length_target'         => $this->post_int( 'length_target' ),
			'length_include_spaces' => $this->post_flag( 'length_include_spaces' ) ? 1 : 0,
			'updated_date'          => $post_id > 0 ? get_the_modified_date( 'Y-m-d', $post_id ) : current_time( 'Y-m-d' ),
		);

		// An absent target means the length criterion is not applicable; passing
		// 0 would have made the criterion fail for a reason the user never
		// chose, so the context is left without a target instead.
		if ( $context['length_target'] <= 0 ) {
			unset( $context['length_target'], $context['length_mode'] );
		}

		$lab    = new AvandWP_Quality_Lab();
		$report = $lab->evaluate( $html, $context );

		wp_send_json_success(
			array(
				'report'  => $report,
				'message' => 'ارزیابی انجام شد. امتیازها از معیارهای وزنی داخلی محاسبه شده‌اند و تضمینی درباره رتبه یا ارجاع هوش مصنوعی نیستند.',
			)
		);
	}

	// =====================================================================
	// Link intelligence (Module B)
	// =====================================================================

	/**
	 * AJAX: Analyse a post's links and propose insertions. Read-only.
	 */
	public function ajax_analyze_links() {
		$this->verify_ajax( 'edit_posts' );

		$post_id = $this->post_int( 'post_id' );

		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'شناسه نوشته ارسال نشده است.' ) );
		}

		$caps = AvandWP_Capabilities::user_can_edit_object( $post_id );

		if ( is_wp_error( $caps ) ) {
			wp_send_json_error( array( 'message' => $caps->get_error_message() ) );
		}

		$links = new AvandWP_Link_Intelligence();
		$report = $links->analyze_post( $post_id );

		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ) );
		}

		wp_send_json_success( array( 'analysis' => $report ) );
	}

	/**
	 * AJAX: Apply only the links the user approved.
	 */
	public function ajax_apply_links() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$post_id = $this->post_int( 'post_id' );
		$raw     = $this->post_raw( 'links' );

		$items = json_decode( (string) $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( array( 'message' => 'هیچ لینکی برای درج تأیید نشده است.' ) );
		}

		$links  = new AvandWP_Link_Intelligence();
		$result = $links->apply_links( $post_id, $items );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'message' => sprintf(
					'%d لینک درج شد%s. متن نوشته در «بازبینی‌ها» قابل بازگردانی است و هر لینک درج‌شده از همین صفحه قابل حذف است.',
					count( $result['applied'] ),
					! empty( $result['skipped'] ) ? sprintf( ' و %d مورد درج نشد (دلیل هر مورد در فهرست آمده است)', count( $result['skipped'] ) ) : ''
				),
			)
		);
	}

	/**
	 * AJAX: Undo a single applied link.
	 */
	public function ajax_revert_link() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$post_id = $this->post_int( 'post_id' );
		$link_id = $this->post_text( 'link_id' );

		if ( '' === $link_id ) {
			wp_send_json_error( array( 'message' => 'شناسه لینک ارسال نشده است.' ) );
		}

		$links  = new AvandWP_Link_Intelligence();
		$result = $links->revert_link( $post_id, $link_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'message' => 'لینک بازگردانی شد؛ متن لنگر در جای خود باقی مانده است.',
			)
		);
	}

	/**
	 * AJAX: Insert internal links that the user explicitly selected from the
	 * site-wide report.
	 */
	public function ajax_insert_links_in_post() {
		$this->verify_ajax( AvandWP_Capabilities::create_capability() );

		$post_id = $this->post_int( 'post_id' );
		$url     = esc_url_raw( $this->post_raw( 'url' ) );
		$anchor  = $this->post_text( 'anchor' );

		$links  = new AvandWP_Link_Intelligence();
		$result = $links->apply_links(
			$post_id,
			array(
				array(
					'url'    => $url,
					'anchor' => $anchor,
					'type'   => AvandWP_Link_Intelligence::is_internal_url( $url ) ? 'internal' : 'external',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( empty( $result['applied'] ) ) {
			$reason = ! empty( $result['skipped'][0]['reason'] ) ? $result['skipped'][0]['reason'] : 'درج انجام نشد.';

			wp_send_json_error( array( 'message' => $reason ) );
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'message' => 'لینک درج شد. هر زمان بخواهید می‌توانید همین لینک را بازگردانی کنید.',
			)
		);
	}

	// =====================================================================
	// Site audit (Module G)
	// =====================================================================

	/**
	 * AJAX: Start a site audit run and crawl the first batch.
	 */
	/**
	 * Normalise a crawl run row into one stable progress payload.
	 *
	 * The crawl API returns different shapes at different points (a batch
	 * result, a finished run row, a run row with a decoded summary), so the UI
	 * would otherwise have to guess which one it received. Everything the audit
	 * endpoints hand to the browser is built here instead, and percentages are
	 * computed server-side from real row counts — never estimated in the view.
	 *
	 * @param int  $run_id Run id.
	 * @param bool $report Whether to include the full summary and page rows.
	 * @return array|WP_Error
	 */
	/**
	 * Resolve the run an audit action refers to.
	 *
	 * `run_id = 0` means "whatever ran last": the «ادامه بررسی» and «توقف»
	 * buttons on a freshly loaded page have no run attached yet. Returning an
	 * error there would make the button look broken, and guessing an id would be
	 * worse, so the newest run is looked up explicitly and an empty history is
	 * reported as such.
	 *
	 * @param int $run_id Requested run id, `0` for the latest.
	 * @return int|WP_Error
	 */
	private function resolve_audit_run_id( $run_id ) {
		$run_id = (int) $run_id;

		if ( $run_id > 0 ) {
			return $run_id;
		}

		$recent = ( new AvandWP_Site_Auditor() )->recent_runs( 1 );

		if ( empty( $recent ) ) {
			return new WP_Error( 'avandwp_audit_no_runs', 'هنوز ممیزی‌ای اجرا نشده است؛ ابتدا دکمه «شروع ممیزی» را بزنید.' );
		}

		return (int) $recent[0]['id'];
	}

	private function audit_progress_payload( $run_id, $report = false ) {
		$auditor = new AvandWP_Site_Auditor();
		$run_id  = $this->resolve_audit_run_id( $run_id );

		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		$run = $auditor->get_run( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$max     = max( 1, (int) $run['max_urls'] );
		$crawled = (int) $run['crawled'];
		$done    = in_array( (string) $run['status'], array( AvandWP_Site_Auditor::STATUS_COMPLETE, AvandWP_Site_Auditor::STATUS_FAILED ), true );

		$payload = array(
			'run_id'     => (int) $run['id'],
			'status'     => (string) $run['status'],
			'crawled'    => $crawled,
			'discovered' => (int) $run['discovered'],
			'max_urls'   => (int) $run['max_urls'],
			'percent'    => (int) min( 100, floor( ( $crawled / $max ) * 100 ) ),
			'done'       => $done,
			'report'     => null,
		);

		if ( $done ) {
			$payload['report'] = isset( $run['summary'] ) && is_array( $run['summary'] )
				? $run['summary']
				: $auditor->summarize( $run_id );
		}

		if ( $report ) {
			$payload['pages'] = $auditor->run_pages( $run_id, 200 );
		}

		return $payload;
	}

	public function ajax_audit_start() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$settings = get_option( 'avandwp_settings', array() );
		$batch    = max( 1, (int) ( $settings['audit_batch_size'] ?? AvandWP_Site_Auditor::DEFAULT_BATCH ) );
		$auditor  = new AvandWP_Site_Auditor();

		$run = $auditor->start_run(
			array(
				'max_urls'   => $this->post_int( 'max_urls', (int) ( $settings['audit_max_urls'] ?? 150 ) ),
				'batch_size' => $batch,
			)
		);

		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}

		$progress = $auditor->crawl_batch( (int) $run['id'], $batch );

		if ( is_wp_error( $progress ) ) {
			wp_send_json_error( array( 'message' => $progress->get_error_message() ) );
		}

		$payload = $this->audit_progress_payload( (int) $run['id'] );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'progress' => $payload,
				'batch'    => $progress,
				'note'     => 'این شماره‌ها از ردیف‌های واقعی دیتابیس خوانده می‌شوند؛ درصد پیشرفت تخمینی نیست.',
				'message'  => 'ممیزی آغاز شد و دسته اول نشانی‌ها بررسی شد. هر دسته بعدی در یک درخواست جداگانه اجرا می‌شود تا سرور تحت فشار قرار نگیرد.',
			)
		);
	}

	public function ajax_audit_step() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$settings = get_option( 'avandwp_settings', array() );
		$auditor  = new AvandWP_Site_Auditor();
		$run_id   = $this->resolve_audit_run_id( $this->post_int( 'run_id' ) );

		if ( is_wp_error( $run_id ) ) {
			wp_send_json_error( array( 'message' => $run_id->get_error_message() ) );
		}

		$run = $auditor->get_run( $run_id );

		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}

		// A run that already finished must not crawl again: re-running the batch
		// on a completed run would re-insert page rows and double-count issues.
		if ( AvandWP_Site_Auditor::STATUS_COMPLETE === (string) $run['status'] ) {
			$payload = $this->audit_progress_payload( $run_id );

			wp_send_json_success(
				array(
					'progress' => is_wp_error( $payload ) ? null : $payload,
					'message'  => 'این ممیزی قبلاً کامل شده است؛ گزارش نهایی نمایش داده می‌شود.',
				)
			);
		}

		$batch = $auditor->crawl_batch( $run_id, max( 1, (int) ( $settings['audit_batch_size'] ?? AvandWP_Site_Auditor::DEFAULT_BATCH ) ) );

		if ( is_wp_error( $batch ) ) {
			wp_send_json_error( array( 'message' => $batch->get_error_message() ) );
		}

		$payload = $this->audit_progress_payload( $run_id );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
		}

		wp_send_json_success( array( 'progress' => $payload ) );
	}

	public function ajax_audit_report() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$payload = $this->audit_progress_payload( $this->post_int( 'run_id' ), true );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
		}

		wp_send_json_success( $payload );
	}

	public function ajax_audit_pause() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$auditor = new AvandWP_Site_Auditor();
		$run_id  = $this->resolve_audit_run_id( $this->post_int( 'run_id' ) );

		if ( is_wp_error( $run_id ) ) {
			wp_send_json_error( array( 'message' => $run_id->get_error_message() ) );
		}

		$run = $auditor->pause_run( $run_id );

		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}

		$payload = $this->audit_progress_payload( $run_id );

		wp_send_json_success(
			array(
				'progress' => is_wp_error( $payload ) ? null : $payload,
				'message'  => 'ممیزی متوقف شد؛ نتایج جمع‌آوری‌شده تا این لحظه حفظ شده و با «ادامه بررسی» قابل پیگیری است.',
			)
		);
	}

	/**
	 * Persist the selected GA4 property.
	 *
	 * The property id comes from the account's own property list, but that list
	 * is fetched over HTTP, so the value is re-validated here rather than trusted:
	 * the Data API only accepts `properties/<digits>`.
	 *
	 * @return void
	 */
	public function ajax_google_select_property() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$property = preg_replace( '/[^0-9]/', '', $this->post_raw( 'property_id' ) );

		if ( '' === $property ) {
			wp_send_json_error( array( 'message' => 'شناسه پراپرتی نامعتبر است؛ شناسه باید فقط رقم باشد.' ) );
		}

		$settings                    = get_option( 'avandwp_settings', array() );
		$settings['ga4_property_id'] = (string) absint( $property );

		update_option( 'avandwp_settings', $settings );

		// The cached report was produced for a different property; keeping it
		// would show another property's numbers under the new selection.
		AvandWP_Analytics_Data::clear_cache();

		AvandWP_Logger::log(
			'google.property_selected',
			sprintf( 'پراپرتی GA4 روی %s تنظیم شد.', $settings['ga4_property_id'] ),
			'success'
		);

		wp_send_json_success(
			array(
				'property_id' => $settings['ga4_property_id'],
				'message'     => 'پراپرتی GA4 ذخیره شد. گزارش‌های قبلی از حافظه پنهان پاک شدند تا داده پراپرتی قبلی نمایش داده نشود.',
			)
		);
	}

	/**
	 * Persist the selected Search Console property.
	 *
	 * The property is *not* free text: it must be one of the properties the
	 * connected account can actually read, because a mistyped property produces
	 * an API error at report time and a hand-typed URL could point at a site the
	 * owner does not control. An empty value clears the explicit selection and
	 * restores host-based matching.
	 *
	 * @return void
	 */
	public function ajax_google_select_site() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$requested = trim( $this->post_text( 'gsc_property' ) );
		$settings  = get_option( 'avandwp_settings', array() );

		if ( '' === $requested ) {
			$settings['gsc_property'] = '';
			update_option( 'avandwp_settings', $settings );
			AvandWP_Search_Console::clear_cache();

			AvandWP_Logger::log( 'google.site_cleared', 'انتخاب پرتال Search Console پاک شد؛ تطبیق خودکار دامنه فعال می‌شود.', 'success' );

			wp_send_json_success(
				array(
					'gsc_property' => '',
					'message'      => 'انتخاب دستی پاک شد؛ افزونه دوباره پرتالی را انتخاب می‌کند که با دامنه همین سایت هم‌خوانی داشته باشد.',
				)
			);
		}

		$console = new AvandWP_Search_Console();
		$sites   = $console->list_sites();

		if ( is_wp_error( $sites ) ) {
			wp_send_json_error(
				array(
					'message' => 'فهرست پرتال‌های Search Console خوانده نشد: ' . $sites->get_error_message(),
					'code'    => $sites->get_error_code(),
				)
			);
		}

		$valid = false;

		foreach ( $sites as $site ) {
			if ( isset( $site['url'] ) && (string) $site['url'] === $requested ) {
				$valid = true;
				break;
			}
		}

		if ( ! $valid ) {
			wp_send_json_error(
				array(
					'message' => 'این نشانی در فهرست دسترسی‌های حساب گوگل شما نیست؛ پرتال را از فهرست همین صفحه انتخاب کنید.',
				)
			);
		}

		$settings['gsc_property'] = $requested;

		update_option( 'avandwp_settings', $settings );

		// Reports cached for the previous property must not be served under the
		// new one: that would show one site's numbers labelled as another's.
		AvandWP_Search_Console::clear_cache();

		AvandWP_Logger::log(
			'google.site_selected',
			sprintf( 'پرتال Search Console روی %s تنظیم شد.', $settings['gsc_property'] ),
			'success'
		);

		wp_send_json_success(
			array(
				'gsc_property' => $settings['gsc_property'],
				'message'      => 'پرتال Search Console ذخیره شد و گزارش‌های قبلی از حافظه پنهان پاک شدند.',
			)
		);
	}

	// =====================================================================
	// Google integrations (Modules H and I)
	// =====================================================================

	/**
	 * AJAX: Return the OAuth URL, or the reason it cannot be produced yet.
	 */
	public function ajax_google_connect() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$url = AvandWP_Google_Auth::authorization_url();

		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'url'     => $url,
				'message' => 'برای تکمیل اتصال، به صفحه گوگل هدایت می‌شوید. این افزونه فقط دسترسی خواندنی درخواست می‌کند.',
			)
		);
	}

	/**
	 * AJAX: Disconnect Google and delete stored tokens.
	 */
	public function ajax_google_disconnect() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$result = AvandWP_Google_Auth::disconnect();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		AvandWP_Search_Console::clear_cache();
		AvandWP_Analytics_Data::clear_cache();

		wp_send_json_success( array( 'message' => 'اتصال گوگل قطع شد و توکن‌ها از دیتابیس حذف شدند.' ) );
	}

	/**
	 * AJAX: Fetch Google data (Search Console or GA4).
	 */
	public function ajax_google_data() {
		$this->verify_ajax( AvandWP_Capabilities::manage_capability() );

		$source = $this->post_key( 'source' );
		$days   = $this->post_int( 'days', 28 );

		switch ( $source ) {
			case 'gsc_queries':
				$console = new AvandWP_Search_Console();
				$data    = $console->query(
					array(
						'days'       => $days,
						'dimensions' => array( 'query' ),
						'row_limit'  => 50,
					)
				);
				break;

			case 'gsc_pages':
				$console = new AvandWP_Search_Console();
				$data    = $console->query(
					array(
						'days'       => $days,
						'dimensions' => array( 'page' ),
						'row_limit'  => 50,
					)
				);
				break;

			case 'gsc_opportunities':
				$console = new AvandWP_Search_Console();
				$data    = $console->improvement_opportunities( $days );
				break;

			case 'ga4_overview':
				$analytics = new AvandWP_Analytics_Data();
				$data      = $analytics->overview( $days );
				break;

			case 'ga4_pages':
				$analytics = new AvandWP_Analytics_Data();
				$data      = $analytics->top_landing_pages( $days );
				break;

			case 'gsc_sites':
				$console = new AvandWP_Search_Console();
				$data    = $console->list_sites();
				break;

			case 'ga4_properties':
				$analytics = new AvandWP_Analytics_Data();
				$data      = $analytics->list_properties();
				break;

			case 'readiness':
				$data = AvandWP_Analytics_Data::readiness_report();
				break;

			default:
				wp_send_json_error( array( 'message' => 'منبع داده درخواستی شناسایی نشد.' ) );
		}

		if ( is_wp_error( $data ) ) {
			wp_send_json_error(
				array(
					'message' => $data->get_error_message(),
					'code'    => $data->get_error_code(),
					'blocked' => in_array( $data->get_error_code(), array( 'avandwp_google_not_connected', 'avandwp_ga4_no_property', 'avandwp_google_not_ready' ), true ),
				)
			);
		}

		wp_send_json_success( array( 'data' => $data ) );
	}

}
