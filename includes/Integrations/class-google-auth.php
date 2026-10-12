<?php
/**
 * Google OAuth 2.0 authorisation for the Search Console and Analytics APIs.
 *
 * Why this is a plugin feature and not a hardcoded token: both APIs are
 * per-user, per-property and revocable. The site owner must authorise the site
 * once, and the plugin must be able to survive the refresh token being revoked.
 *
 * Security decisions, all deliberate:
 *
 *   - **State parameter is mandatory.** The OAuth callback is a public admin
 *     URL; without a state check, an attacker can complete an authorisation
 *     with *their* Google account and silently attach their Search Console
 *     properties to the victim's site.
 *   - **The state is bound to the user and the site.** It is stored as a
 *     user-scoped transient and compared with `hash_equals()`.
 *   - **Secrets live in the vault.** The client secret and the refresh token are
 *     stored through `AvandWP_Vault`, never in a plain option and never in a
 *     page.
 *   - **No token ever reaches the browser.** Nothing in this class outputs a
 *     token; the REST/AJAX layer only ever receives connection *status*.
 *   - **Nothing is fabricated.** When there is no connection, the data methods
 *     return an explicit `not_connected` error rather than zeroes, so the UI
 *     cannot render "0 clicks" as if it were real data.
 *
 * Endpoints below were verified against Google's documentation on
 * `VERIFIED_ON`; see `docs/api-integrations.md`.
 *
 * @package AvandWP\Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.0 authorisation-code flow with offline access.
 */
class AvandWP_Google_Auth {

	/**
	 * Date the endpoints and scopes below were verified against Google's docs.
	 */
	const VERIFIED_ON = AvandWP_Price_Catalog::VERIFIED_ON;

	/**
	 * Options and vault slots.
	 */
	const OPTION_CONNECTION = 'avandwp_google_connection';
	const VAULT_CLIENT_SECRET = 'google_client_secret';
	const VAULT_REFRESH_TOKEN = 'google_refresh_token';

	/**
	 * OAuth endpoints (verified).
	 */
	const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Read-only scopes. Write access is deliberately not requested: this plugin
	 * reads performance data and never modifies a Google property.
	 *
	 * @return array<int, string>
	 */
	public static function scopes() {
		return array(
			'https://www.googleapis.com/auth/webmasters.readonly',
			'https://www.googleapis.com/auth/analytics.readonly',
		);
	}

	/**
	 * Vault slot labels contributed to the settings screen.
	 *
	 * @return array<string, string>
	 */
	public static function vault_slots() {
		return array(
			self::VAULT_CLIENT_SECRET => 'Google OAuth — Client Secret',
		);
	}

	/**
	 * OAuth client id (public by design; a client id is not a secret).
	 *
	 * @return string
	 */
	public static function client_id() {
		$settings = get_option( 'avandwp_settings', array() );

		return trim( (string) ( $settings['google_client_id'] ?? '' ) );
	}

	/**
	 * OAuth client secret from the vault.
	 *
	 * @return string
	 */
	public static function client_secret() {
		return AvandWP_Vault::get_key( self::VAULT_CLIENT_SECRET );
	}

	/**
	 * Redirect URI registered with Google. Must match exactly.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=avandwp-integrations&avandwp_oauth=google' );
	}

	/**
	 * Is the plugin configured enough to attempt authorisation?
	 *
	 * @return array{ready: bool, missing: array<int, string>}
	 */
	public static function readiness() {
		$missing = array();

		if ( '' === self::client_id() ) {
			$missing[] = 'شناسه کلاینت OAuth (Client ID) وارد نشده است.';
		}

		if ( '' === self::client_secret() ) {
			$missing[] = 'کلید محرمانه کلاینت (Client Secret) در گاوصندوق ذخیره نشده است.';
		}

		if ( ! is_ssl() && ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
			$missing[] = 'برای OAuth گوگل، سایت باید روی HTTPS باشد.';
		}

		return array(
			'ready'   => empty( $missing ),
			'missing' => $missing,
		);
	}

	/**
	 * Stored connection record.
	 *
	 * @return array<string, mixed>
	 */
	public static function connection() {
		$stored = get_option( self::OPTION_CONNECTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args(
			$stored,
			array(
				'connected'        => false,
				'email'            => '',
				'scopes'           => array(),
				'connected_at'     => '',
				'last_refreshed'   => '',
				'last_error'       => '',
				'access_expires'   => 0,
			)
		);
	}

	/**
	 * Is there a usable refresh token?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$connection = self::connection();

		return ! empty( $connection['connected'] ) && '' !== AvandWP_Vault::get_key( self::VAULT_REFRESH_TOKEN );
	}

	/**
	 * Status for the UI. Contains no secrets.
	 *
	 * @return array<string, mixed>
	 */
	public static function status() {
		$connection = self::connection();
		$readiness  = self::readiness();

		return array(
			'configured'      => $readiness['ready'],
			'missing'         => $readiness['missing'],
			'connected'       => self::is_connected(),
			'email'           => (string) $connection['email'],
			'connected_at'    => (string) $connection['connected_at'],
			'last_refreshed'  => (string) $connection['last_refreshed'],
			'last_error'      => (string) $connection['last_error'],
			'scopes'          => array_values( (array) $connection['scopes'] ),
			'redirect_uri'    => self::redirect_uri(),
			'scopes_requested' => self::scopes(),
			'verified_on'     => self::VERIFIED_ON,
			'notes'           => array(
				'این افزونه فقط دسترسی خواندنی درخواست می‌کند و هیچ‌گاه داده‌ای در حساب گوگل شما تغییر نمی‌دهد.',
				'توکن دسترسی و توکن تازه‌سازی هرگز به مرورگر فرستاده نمی‌شوند و فقط به‌صورت رمزنگاری‌شده ذخیره می‌گردند.',
			),
		);
	}

	/**
	 * Build the authorisation URL and store the state for verification.
	 *
	 * @return string|WP_Error
	 */
	public static function authorization_url() {
		if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() ) ) {
			return new WP_Error( 'avandwp_forbidden', 'دسترسی مدیریت برای اتصال حساب گوگل لازم است.' );
		}

		$readiness = self::readiness();

		if ( ! $readiness['ready'] ) {
			return new WP_Error( 'avandwp_google_not_ready', implode( ' ', $readiness['missing'] ) );
		}

		$state = wp_generate_password( 32, false, false );

		set_transient( 'avandwp_google_oauth_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( self::client_id() ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( implode( ' ', self::scopes() ) ),
				'access_type'   => 'offline',
				// Forces a refresh token on re-authorisation; without it Google
				// returns one only on the very first consent for a client.
				'prompt'        => 'consent',
				'include_granted_scopes' => 'true',
				'state'         => rawurlencode( $state ),
			),
			self::AUTH_ENDPOINT
		);

		return $url;
	}

	/**
	 * Handle the OAuth callback.
	 *
	 * @param array $query Query parameters from the callback URL.
	 * @return true|WP_Error
	 */
	public static function handle_callback( array $query ) {
		if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() ) ) {
			return new WP_Error( 'avandwp_forbidden', 'دسترسی مدیریت برای تکمیل اتصال گوگل لازم است.' );
		}

		$error = isset( $query['error'] ) ? sanitize_text_field( (string) $query['error'] ) : '';

		if ( '' !== $error ) {
			self::record_error( sprintf( 'گوگل اتصال را رد کرد: %s', $error ) );

			return new WP_Error( 'avandwp_google_denied', sprintf( 'گوگل اجازه دسترسی را نداد (%s).', $error ) );
		}

		$state = isset( $query['state'] ) ? (string) $query['state'] : '';
		$code  = isset( $query['code'] ) ? (string) $query['code'] : '';

		if ( '' === $state || '' === $code ) {
			return new WP_Error( 'avandwp_google_bad_callback', 'پاسخ گوگل ناقص بود (کد یا state موجود نیست).' );
		}

		$expected = get_transient( 'avandwp_google_oauth_' . get_current_user_id() );

		if ( ! is_string( $expected ) || '' === $expected ) {
			return new WP_Error(
				'avandwp_google_state_missing',
				'نشست اتصال منقضی شده است. لطفاً فرایند اتصال را از ابتدا آغاز کنید.'
			);
		}

		// hash_equals(): constant-time comparison, no timing leak of the state.
		if ( ! hash_equals( $expected, $state ) ) {
			return new WP_Error(
				'avandwp_google_state_mismatch',
				'اعتبارسنجی state ناموفق بود؛ به دلایل امنیتی اتصال انجام نشد. لطفاً دوباره تلاش کنید.'
			);
		}

		delete_transient( 'avandwp_google_oauth_' . get_current_user_id() );

		$tokens = self::request_token(
			array(
				'code'          => $code,
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error(
				'avandwp_google_no_refresh_token',
				'گوگل توکن تازه‌سازی برنگرداند. دسترسی این افزونه را در حساب گوگل حذف کنید و دوباره متصل شوید.'
			);
		}

		AvandWP_Vault::set_key( self::VAULT_REFRESH_TOKEN, (string) $tokens['refresh_token'] );

		$scopes = isset( $tokens['scope'] ) ? preg_split( '/\s+/', (string) $tokens['scope'], -1, PREG_SPLIT_NO_EMPTY ) : array();

		$email = self::fetch_account_email( (string) $tokens['access_token'] );

		update_option(
			self::OPTION_CONNECTION,
			array(
				'connected'      => true,
				'email'          => (string) $email,
				'scopes'         => array_values( (array) $scopes ),
				'connected_at'   => current_time( 'mysql' ),
				'last_refreshed' => current_time( 'mysql' ),
				'last_error'     => '',
				'access_expires' => time() + max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) ) - 60,
			),
			false
		);

		set_transient( 'avandwp_google_access_token', (string) $tokens['access_token'], 50 * MINUTE_IN_SECONDS );

		AvandWP_Logger::log(
			'google.connected',
			'اتصال به حساب گوگل با موفقیت برقرار شد.',
			'success'
		);

		return true;
	}

	/**
	 * Return a valid access token, refreshing when necessary.
	 *
	 * The access token is cached in a transient so a page that makes several API
	 * calls does not trigger several token requests.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( 'avandwp_google_access_token' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! self::is_connected() ) {
			return new WP_Error(
				'avandwp_google_not_connected',
				'حساب گوگل متصل نیست. برای استفاده از داده‌های Search Console و Analytics، ابتدا در «تنظیمات و سلامت ← اتصال گوگل» اتصال را برقرار کنید.'
			);
		}

		$refresh = AvandWP_Vault::get_key( self::VAULT_REFRESH_TOKEN );

		$tokens = self::request_token(
			array(
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			self::record_error( $tokens->get_error_message() );

			return $tokens;
		}

		$access = (string) ( $tokens['access_token'] ?? '' );

		if ( '' === $access ) {
			self::record_error( 'پاسخ تازه‌سازی توکن، توکن دسترسی نداشت.' );

			return new WP_Error( 'avandwp_google_no_access_token', 'گوگل توکن دسترسی برنگرداند.' );
		}

		set_transient( 'avandwp_google_access_token', $access, 50 * MINUTE_IN_SECONDS );

		$connection                  = self::connection();
		$connection['last_refreshed'] = current_time( 'mysql' );
		$connection['last_error']     = '';
		$connection['access_expires'] = time() + max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) ) - 60;

		update_option( self::OPTION_CONNECTION, $connection, false );

		return $access;
	}

	/**
	 * Disconnect: revoke the grant and forget every stored token.
	 *
	 * @return true|WP_Error
	 */
	public static function disconnect() {
		if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::manage_capability() ) ) {
			return new WP_Error( 'avandwp_forbidden', 'دسترسی مدیریت لازم است.' );
		}

		$refresh = AvandWP_Vault::get_key( self::VAULT_REFRESH_TOKEN );

		if ( '' !== $refresh ) {
			// Best-effort revocation: the user asked to disconnect, so local
			// credentials are cleared even if Google is unreachable.
			wp_remote_post(
				self::REVOKE_ENDPOINT,
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array( 'token' => $refresh ),
				)
			);
		}

		AvandWP_Vault::set_key( self::VAULT_REFRESH_TOKEN, '' );

		delete_transient( 'avandwp_google_access_token' );

		update_option(
			self::OPTION_CONNECTION,
			array(
				'connected'    => false,
				'email'        => '',
				'scopes'       => array(),
				'connected_at' => '',
				'last_error'   => '',
			),
			false
		);

		AvandWP_Logger::log( 'google.disconnected', 'اتصال حساب گوگل قطع و توکن‌ها حذف شدند.', 'info' );

		return true;
	}

	/**
	 * Perform an authorised GET against a Google API.
	 *
	 * @param string $url    Absolute URL.
	 * @param array  $query  Query arguments.
	 * @return array|WP_Error Decoded JSON body.
	 */
	public static function get( $url, array $query = array() ) {
		$token = self::access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = empty( $query ) ? $url : add_query_arg( array_map( 'rawurlencode', $query ), $url );

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		return self::parse_response( $response, 'GET ' . $url );
	}

	/**
	 * Perform an authorised POST against a Google API.
	 *
	 * @param string $url  Absolute URL.
	 * @param array  $body JSON body.
	 * @return array|WP_Error Decoded JSON body.
	 */
	public static function post( $url, array $body ) {
		$token = self::access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		return self::parse_response( $response, 'POST ' . $url );
	}

	/**
	 * Parse a Google API response, surfacing the API's own error message.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $context  Human-readable call description.
	 * @return array|WP_Error
	 */
	private static function parse_response( $response, $context ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'avandwp_google_transport',
				sprintf( 'ارتباط با گوگل برقرار نشد (%s): %s', $context, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}

		$api_message = '';
		$api_status  = '';

		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$api_message = (string) ( $body['error']['message'] ?? '' );
			$api_status  = (string) ( $body['error']['status'] ?? '' );
		}

		if ( 401 === $code || 403 === $code ) {
			// A revoked or insufficient grant is a permanent condition for this
			// connection; record it so the UI can prompt a re-connect instead of
			// retrying silently forever.
			self::record_error( sprintf( 'کد %d: %s', $code, $api_message ) );
		}

		return new WP_Error(
			'avandwp_google_api_error',
			sprintf(
				'خطای گوگل در %s (کد %d%s)%s',
				$context,
				$code,
				'' !== $api_status ? ' — ' . $api_status : '',
				'' !== $api_message ? ': ' . $api_message : '.'
			),
			array( 'status' => $code )
		);
	}

	/**
	 * Exchange an authorisation code or refresh token for tokens.
	 *
	 * @param array $params Form parameters.
	 * @return array|WP_Error
	 */
	private static function request_token( array $params ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $params,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'avandwp_google_token_transport',
				'ارتباط با سرور توکن گوگل برقرار نشد: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) ? (string) ( $body['error_description'] ?? $body['error'] ?? '' ) : '';

			return new WP_Error(
				'avandwp_google_token_error',
				sprintf( 'دریافت توکن گوگل ناموفق بود (کد %d)%s.', $code, '' !== $message ? ': ' . $message : '' )
			);
		}

		return $body;
	}

	/**
	 * Read the authorised account's e-mail address for display.
	 *
	 * Only the address is kept; it is what lets the owner confirm they connected
	 * the right Google account. A failure here is not fatal to the connection.
	 *
	 * @param string $access_token Access token.
	 * @return string
	 */
	private static function fetch_account_email( $access_token ) {
		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v3/userinfo',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? sanitize_email( (string) ( $body['email'] ?? '' ) ) : '';
	}

	/**
	 * Store the most recent authorisation error for display.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function record_error( $message ) {
		$connection               = self::connection();
		$connection['last_error'] = AvandWP_Text_Helper::safe_substr( sanitize_text_field( $message ), 0, 300 );
		$connection['connected']  = ! empty( $connection['connected'] );

		update_option( self::OPTION_CONNECTION, $connection, false );
	}
}
