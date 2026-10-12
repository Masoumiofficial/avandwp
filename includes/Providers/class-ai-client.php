<?php
/**
 * Multi-Provider AI Client (GapGPT, OpenAI, Google Gemini, Anthropic Claude).
 *
 * Supports chat completions, automatic provider fallback on quota/rate-limit errors,
 * Whisper audio transcription, TTS audio synthesis, and real vector embeddings.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_AI_Client {

	/**
	 * Absolute ceiling for a single completion request.
	 *
	 * Providers reject larger values; clamping here keeps a request valid rather
	 * than burning an attempt on a guaranteed 400.
	 */
	const HARD_MAX_OUTPUT_TOKENS = 32000;

	/**
	 * Correlation id for the request currently being dispatched.
	 *
	 * @var string
	 */
	private $correlation_id = '';

	/**
	 * Note about how the model/cost for the current request was determined.
	 *
	 * @var string
	 */
	private $last_model_note = '';

	/**
	 * Plugin settings array.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( 'avandwp_settings', array() );
	}

	/**
	 * Generate text response from the configured AI provider with automatic fallback.
	 *
	 * @param array $messages Array of ['role' => 'system'|'user'|'assistant', 'content' => '...'].
	 * @param array $args     Optional overrides: provider, model, temperature, max_tokens, feature, job_id.
	 * @return array|WP_Error ['content' => string, 'provider' => string, 'model' => string, 'tokens_in' => int, 'tokens_out' => int]
	 */
	public function chat( array $messages, array $args = array() ) {
		$primary  = ! empty( $args['provider'] ) ? sanitize_key( $args['provider'] ) : ( $this->settings['default_provider'] ?? 'gapgpt' );
		$fallback = ! empty( $this->settings['fallback_provider'] ) ? sanitize_key( $this->settings['fallback_provider'] ) : '';

		$correlation_id = isset( $args['correlation_id'] )
			? (string) $args['correlation_id']
			: substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );

		// Candidate order: the requested provider, then the configured fallback,
		// then every other text-capable provider that actually holds a key. The
		// list comes from the registry so a provider cannot be tried simply
		// because its slug is hardcoded here.
		$candidates = array_values(
			array_unique(
				array_filter( array( $primary, $fallback ) )
			)
		);

		$available = array();
		foreach ( $candidates as $provider ) {
			if ( AvandWP_Provider_Registry::is_known( $provider ) && AvandWP_Vault::has_key( $provider ) ) {
				$available[] = $provider;
			}
		}

		foreach ( AvandWP_Provider_Registry::for_capability( 'text' ) as $provider ) {
			if ( ! in_array( $provider, $available, true ) ) {
				$available[] = $provider;
			}
		}

		// A provider slot whose key is missing is the most common cause of "the
		// plugin does nothing", so name it explicitly instead of a generic error.
		if ( empty( $available ) ) {
			$hint = '';
			if ( $primary && AvandWP_Provider_Registry::is_known( $primary ) && ! AvandWP_Vault::has_key( $primary ) ) {
				$hint = sprintf( ' سرویس انتخاب‌شده (%s) کلید ذخیره‌شده ندارد.', $primary );
			}
			return new WP_Error(
				'avandwp_no_api_key',
				'هیچ کلید API فعالی در «تنظیمات و سلامت ← کلیدهای API» ثبت نشده است. لطفاً ابتدا کلید GapGPT یا OpenAI خود را وارد کنید.' . $hint
			);
		}

		// Global spend ceiling: checked before the first paid call so a single
		// action can never exceed the owner's configured daily budget.
		$spend = AvandWP_Spend_Guard::can_spend( 1 );
		if ( is_wp_error( $spend ) ) {
			return $spend;
		}

		$last_error = null;

		foreach ( $available as $provider ) {
			// Transient failures are retried against the SAME provider first;
			// switching provider is a second resort, not the first reaction.
			$res = $this->attempt_with_retry( $provider, $messages, $args, $correlation_id );

			if ( ! is_wp_error( $res ) ) {
				$feature = ! empty( $args['feature'] ) ? sanitize_key( $args['feature'] ) : 'general';
				$job_id  = isset( $args['job_id'] ) ? (int) $args['job_id'] : 0;

				AvandWP_Logger::record_usage(
					$res['provider'],
					$res['model'],
					$res['tokens_in'],
					$res['tokens_out'],
					$feature,
					$job_id,
					array(
						'tokens_source' => ! empty( $res['usage_reported'] ) ? 'provider_reported' : 'estimated',
					)
				);

				return $res;
			}

			$last_error = $res;
			$reason     = (string) ( $res->get_error_data()['reason'] ?? 'unknown' );

			AvandWP_Logger::log(
				'ai.fallback',
				sprintf(
					'[%s] خطا در سرویس %s (%s / %s)؛ تلاش با سرویس جایگزین.',
					$correlation_id,
					$provider,
					$reason,
					$res->get_error_message()
				),
				'warning'
			);

			// A bad key, a malformed request or a content refusal will not be
			// fixed by a different provider: stop instead of spending more.
			if ( in_array( $reason, array( 'auth', 'invalid_request', 'content' ), true ) ) {
				break;
			}
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error( 'avandwp_ai_failed', 'خطا در ارتباط با سرویس هوش مصنوعی.' );
	}

	/**
	 * Attempt a provider call, retrying genuinely transient failures.
	 *
	 * Only network errors, HTTP 429 and HTTP 5xx are retried. A 4xx is never
	 * retried: repeating a non-idempotent paid request that the provider already
	 * rejected only spends money and time.
	 *
	 * @param string $provider       Provider slug.
	 * @param array  $messages       Messages array.
	 * @param array  $args           Request options.
	 * @param string $correlation_id Correlation id for log correlation.
	 * @return array|WP_Error
	 */
	private function attempt_with_retry( $provider, array $messages, array $args, $correlation_id ) {
		$max_attempts = max( 1, min( 4, (int) ( $args['max_attempts'] ?? 3 ) ) );
		$last         = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$last = $this->dispatch_provider( $provider, $messages, $args, $correlation_id );

			if ( ! is_wp_error( $last ) ) {
				return $last;
			}

			$reason    = (string) ( $last->get_error_data()['reason'] ?? 'unknown' );
			$retryable = (bool) ( $last->get_error_data()['retryable'] ?? false );

			if ( ! $retryable || $attempt === $max_attempts ) {
				return $last;
			}

			// Exponential backoff with jitter: 1s, 2s, 4s (+ up to 40% jitter).
			$delay_ms = (int) ( 1000 * ( 2 ** ( $attempt - 1 ) ) );
			$delay_ms += wp_rand( 0, (int) ( $delay_ms * 0.4 ) );

			AvandWP_Logger::log(
				'ai.retry',
				sprintf(
					'[%s] تلاش %d از %d برای %s (%s) پس از %d میلی‌ثانیه.',
					$correlation_id,
					$attempt,
					$max_attempts,
					$provider,
					$reason,
					$delay_ms
				),
				'warning'
			);

			usleep( $delay_ms * 1000 );
		}

		return $last;
	}

	/**
	 * Classify a provider failure so the caller can decide whether to retry or
	 * fall back, without inspecting provider-specific message text.
	 *
	 * @param int    $http_code HTTP status code (0 for transport failures).
	 * @param string $message   Provider error message.
	 * @return array{reason: string, retryable: bool, status: int}
	 */
	public static function classify_failure( $http_code, $message = '' ) {
		$http_code = (int) $http_code;
		$message   = strtolower( (string) $message );

		if ( 0 === $http_code ) {
			return array( 'reason' => 'network', 'retryable' => true, 'status' => 0 );
		}

		if ( 429 === $http_code ) {
			return array( 'reason' => 'rate_limit', 'retryable' => true, 'status' => 429 );
		}

		if ( $http_code >= 500 ) {
			return array( 'reason' => 'server', 'retryable' => true, 'status' => $http_code );
		}

		if ( 401 === $http_code || 403 === $http_code ) {
			return array( 'reason' => 'auth', 'retryable' => false, 'status' => $http_code );
		}

		if ( in_array( $http_code, array( 402, 413, 415, 422 ), true ) ) {
			return array( 'reason' => 'invalid_request', 'retryable' => false, 'status' => $http_code );
		}

		// Content policy refusals arrive as 400 with a recognisable message.
		if ( 400 === $http_code && preg_match( '/(content[_ ]policy|safety|moderation|flagged|refus)/', $message ) ) {
			return array( 'reason' => 'content', 'retryable' => false, 'status' => 400 );
		}

		if ( 400 === $http_code ) {
			return array( 'reason' => 'invalid_request', 'retryable' => false, 'status' => 400 );
		}

		return array( 'reason' => 'unknown', 'retryable' => false, 'status' => $http_code );
	}

	/**
	 * Dispatch request to a specific provider.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $messages Messages array.
	 * @param array  $args     Request options.
	 * @return array|WP_Error
	 */
	private function dispatch_provider( $provider, array $messages, array $args, $correlation_id = '' ) {
		$key = AvandWP_Vault::get_key( $provider );
		if ( '' === $key ) {
			return new WP_Error( 'avandwp_missing_key', sprintf( 'کلید سرویس %s تنظیم نشده است.', $provider ) );
		}

		$this->correlation_id = (string) $correlation_id;

		$temperature = isset( $args['temperature'] ) ? (float) $args['temperature'] : (float) ( $this->settings['temperature'] ?? 0.7 );
		$timeout     = isset( $args['timeout'] ) ? (int) $args['timeout'] : (int) ( $this->settings['request_timeout'] ?? 60 );
		$timeout     = max( 15, min( 300, $timeout ) );

		// An explicit per-request budget is honoured up to a hard provider
		// ceiling. The settings field only governs the *default*; the sectioned
		// long-form writer must be able to request a larger completion than the
		// admin-configured default, otherwise long articles truncate silently.
		$max_tokens = isset( $args['max_tokens'] )
			? (int) $args['max_tokens']
			: (int) ( $this->settings['max_tokens'] ?? 2500 );
		$max_tokens = max( 64, min( self::HARD_MAX_OUTPUT_TOKENS, $max_tokens ) );

		$definition = AvandWP_Provider_Registry::get( $provider );

		if ( null === $definition ) {
			return new WP_Error( 'avandwp_invalid_provider', 'سرویس‌دهنده نامعتبر است.' );
		}

		$requested = ! empty( $args['model'] ) ? sanitize_text_field( $args['model'] ) : '';
		$resolved  = AvandWP_Provider_Registry::resolve_model( 'text', $provider, $requested );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$model    = $resolved['model'];
		$base_url = (string) $definition['base_url'];

		$this->last_model_note = (string) $resolved['note'];

		switch ( $definition['adapter'] ) {
			case 'openai_compatible':
				return $this->call_openai_compatible( $provider, $base_url, $key, $model, $messages, $temperature, $max_tokens, $timeout );

			case 'gemini':
				return $this->call_gemini( $key, $model, $messages, $temperature, $max_tokens, $timeout, $base_url );

			case 'claude':
				return $this->call_claude( $key, $model, $messages, $temperature, $max_tokens, $timeout, $base_url );

			default:
				return new WP_Error(
					'avandwp_unsupported_provider',
					sprintf( 'سرویس %s برای تولید متن پشتیبانی نمی‌شود.', $provider )
				);
		}
	}

	/**
	 * Call OpenAI / GapGPT chat completions endpoint.
	 */
	private function call_openai_compatible( $provider, $base_url, $key, $model, array $messages, $temperature, $max_tokens, $timeout ) {
		$endpoint = rtrim( $base_url, '/' ) . '/chat/completions';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization'     => 'Bearer ' . $key,
					'Content-Type'      => 'application/json',
					'X-Correlation-Id'  => $this->correlation_id,
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => $temperature,
						'max_tokens'  => $max_tokens,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $provider, $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['choices'][0]['message']['content'] ) ) {
			$err_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'کد وضعیت HTTP ' . $code;
			return $this->api_error( strtoupper( $provider ), $code, $err_msg );
		}

		$content     = (string) $body['choices'][0]['message']['content'];
		$usage_given = isset( $body['usage']['prompt_tokens'] );

		$tokens_in  = $usage_given ? (int) $body['usage']['prompt_tokens'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usage']['completion_tokens'] ) ? (int) $body['usage']['completion_tokens'] : AvandWP_Text_Helper::estimate_tokens( $content );

		// `finish_reason` is how a provider says "I was cut off". Ignoring it is
		// what allowed a truncated article to be presented as a complete one.
		$finish_reason = isset( $body['choices'][0]['finish_reason'] ) ? (string) $body['choices'][0]['finish_reason'] : 'unknown';

		return array(
			'content'       => $content,
			'provider'      => $provider,
			'model'         => $model,
			'tokens_in'     => $tokens_in,
			'tokens_out'    => $tokens_out,
			'usage_reported' => (bool) $usage_given,
			'finish_reason' => $finish_reason,
			'truncated'     => in_array( $finish_reason, array( 'length', 'max_tokens' ), true ),
		);
	}

	/**
	 * Call Google Gemini generateContent endpoint.
	 */
	private function call_gemini( $key, $model, array $messages, $temperature, $max_tokens, $timeout, $base_url = '' ) {
		$base = '' !== $base_url ? rtrim( $base_url, '/' ) : AvandWP_Provider_Registry::base_url( 'gemini' );

		// The key travels in the `x-goog-api-key` header rather than the query
		// string: query strings are retained by proxies, CDNs and error pages.
		$endpoint = sprintf(
			'%s/models/%s:generateContent',
			$base,
			rawurlencode( $model )
		);

		$contents          = array();
		$system_parts_text = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role ) {
				$system_parts_text[] = $text;
				continue;
			}
			$contents[] = array(
				'role'  => ( 'assistant' === $role ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $text ) ),
			);
		}

		if ( empty( $contents ) ) {
			$contents[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => implode( "\n", $system_parts_text ) ) ),
			);
			$system_parts_text = array();
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => $temperature,
				'maxOutputTokens' => $max_tokens,
			),
		);

		if ( ! empty( $system_parts_text ) ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => implode( "\n\n", $system_parts_text ) ) ),
			);
		}

		$headers = array_merge(
			array( 'Content-Type' => 'application/json' ),
			AvandWP_Provider_Registry::auth_headers( 'gemini', $key )
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( 'gemini', $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( $code < 200 || $code >= 300 || '' === $content ) {
			$err_msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return $this->api_error( 'GEMINI', $code, $err_msg );
		}

		$finish_reason = isset( $body['candidates'][0]['finishReason'] ) ? (string) $body['candidates'][0]['finishReason'] : 'unknown';

		$tokens_in  = isset( $body['usageMetadata']['promptTokenCount'] ) ? (int) $body['usageMetadata']['promptTokenCount'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usageMetadata']['candidatesTokenCount'] ) ? (int) $body['usageMetadata']['candidatesTokenCount'] : AvandWP_Text_Helper::estimate_tokens( $content );

		return array(
			'content'        => (string) $content,
			'provider'       => 'gemini',
			'model'          => $model,
			'tokens_in'      => $tokens_in,
			'tokens_out'     => $tokens_out,
			'usage_reported' => isset( $body['usageMetadata']['promptTokenCount'] ),
			'finish_reason'  => $finish_reason,
			'truncated'      => in_array( $finish_reason, array( 'MAX_TOKENS', 'max_tokens' ), true ),
		);
	}

	/**
	 * Call Anthropic Claude messages endpoint.
	 */
	private function call_claude( $key, $model, array $messages, $temperature, $max_tokens, $timeout, $base_url = '' ) {
		$system_prompt = '';
		$chat_messages = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role ) {
				$system_prompt .= ( '' !== $system_prompt ? "\n\n" : '' ) . $text;
			} else {
				$chat_messages[] = array(
					'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
					'content' => $text,
				);
			}
		}

		$payload = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => $chat_messages,
		);
		if ( '' !== $system_prompt ) {
			$payload['system'] = $system_prompt;
		}

		$base = '' !== $base_url ? rtrim( $base_url, '/' ) : AvandWP_Provider_Registry::base_url( 'claude' );

		$response = wp_remote_post(
			$base . '/messages',
			array(
				'timeout' => $timeout,
				'headers' => array_merge(
					AvandWP_Provider_Registry::auth_headers( 'claude', $key ),
					array( 'Content-Type' => 'application/json' )
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( 'claude', $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$content = $body['content'][0]['text'] ?? '';
		if ( $code < 200 || $code >= 300 || '' === $content ) {
			$err_msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return $this->api_error( 'CLAUDE', $code, $err_msg );
		}

		$stop_reason = isset( $body['stop_reason'] ) ? (string) $body['stop_reason'] : 'unknown';

		$tokens_in  = isset( $body['usage']['input_tokens'] ) ? (int) $body['usage']['input_tokens'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usage']['output_tokens'] ) ? (int) $body['usage']['output_tokens'] : AvandWP_Text_Helper::estimate_tokens( $content );

		return array(
			'content'        => (string) $content,
			'provider'       => 'claude',
			'model'          => $model,
			'tokens_in'      => $tokens_in,
			'tokens_out'     => $tokens_out,
			'usage_reported' => isset( $body['usage']['input_tokens'] ),
			'finish_reason'  => $stop_reason,
			'truncated'      => in_array( $stop_reason, array( 'max_tokens', 'length' ), true ),
		);
	}

	/**
	 * Build a transport (network) failure error.
	 *
	 * @param string   $provider Provider slug.
	 * @param WP_Error $error    Transport error from wp_remote_*.
	 * @return WP_Error
	 */
	private function transport_error( $provider, $error ) {
		$classified = self::classify_failure( 0, $error->get_error_message() );

		return new WP_Error(
			'avandwp_http_error',
			sprintf( 'خطای شبکه در اتصال به %s: %s', $provider, $error->get_error_message() ),
			$classified
		);
	}

	/**
	 * Build a classified API failure error.
	 *
	 * The error data carries `reason`, `retryable` and `status` so the caller can
	 * decide between retrying, switching provider, or stopping immediately.
	 *
	 * @param string $provider_label Display label.
	 * @param int    $http_code      HTTP status.
	 * @param string $message        Provider message.
	 * @return WP_Error
	 */
	private function api_error( $provider_label, $http_code, $message ) {
		$classified = self::classify_failure( $http_code, $message );

		$suffix = '';
		if ( 'auth' === $classified['reason'] ) {
			$suffix = ' — کلید API نامعتبر است یا دسترسی ندارد؛ درخواست تکرار نمی‌شود.';
		} elseif ( 'rate_limit' === $classified['reason'] ) {
			$suffix = ' — محدودیت نرخ درخواست؛ پس از مکث کوتاه دوباره تلاش می‌شود.';
		}

		return new WP_Error(
			'avandwp_api_error',
			sprintf( 'خطای %s: %s%s', $provider_label, $message, $suffix ),
			$classified
		);
	}

	/**
	 * Test live connection to a specific AI provider.
	 *
	 * @param string $provider Provider slug.
	 * @return array|WP_Error
	 */
	public function test_connection( $provider ) {
		$provider = sanitize_key( $provider );
		$start    = microtime( true );
		$res      = $this->dispatch_provider(
			$provider,
			array(
				array(
					'role'    => 'user',
					'content' => 'فقط کلمه «متصل» را برگردان.',
				),
			),
			array(
				'max_tokens' => 20,
				'timeout'    => 20,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		return array(
			'provider'   => $provider,
			'model'      => $res['model'],
			'reply'      => trim( $res['content'] ),
			'latency_ms' => $latency_ms,
		);
	}

	/**
	 * Transcribe an uploaded audio file using Whisper (`whisper-1`).
	 *
	 * Fixes the bug where $file['tmp_name'] was passed after wp_handle_upload()
	 * had already moved the file.
	 *
	 * @param string $file_path Absolute path to the saved audio file (`$uploaded['file']`).
	 * @return string|WP_Error Transcribed text or WP_Error.
	 */
	public function transcribe_audio_file( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path || ! is_readable( $file_path ) ) {
			return new WP_Error( 'avandwp_audio_unreadable', 'فایل صوتی برای تبدیل به متن قابل خواندن نیست.' );
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_whisper_no_key', 'برای تبدیل صوت به متن، کلید GapGPT یا OpenAI الزامی است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/audio/transcriptions'
			: 'https://api.openai.com/v1/audio/transcriptions';

		$boundary      = '----AvandWPBoundary' . wp_generate_password( 16, false );
		$file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_contents ) {
			return new WP_Error( 'avandwp_audio_read_error', 'خطا در خواندن محتوای فایل صوتی.' );
		}

		$filename = basename( $file_path );
		$body     = '';
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"language\"\r\n\r\nfa\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body    .= "Content-Type: application/octet-stream\r\n\r\n";
		$body    .= $file_contents . "\r\n";
		$body    .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['text'] ) ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_whisper_failed', 'خطا در تبدیل صوت به متن: ' . $msg );
		}

		return sanitize_textarea_field( (string) $data['text'] );
	}

	/**
	 * Synthesize speech (TTS) from text and save MP3 to WordPress Media Library.
	 *
	 * @param string $text  Input text (up to 3500 chars).
	 * @param string $voice Voice ID ('alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer').
	 * @return array|WP_Error ['url' => string, 'attachment_id' => int]
	 */
	public function generate_speech( $text, $voice = 'nova' ) {
		$plain = wp_strip_all_tags( (string) $text );
		if ( function_exists( 'mb_substr' ) ) {
			$plain = mb_substr( $plain, 0, 3500, 'UTF-8' );
		} else {
			$plain = substr( $plain, 0, 3500 );
		}

		if ( '' === trim( $plain ) ) {
			return new WP_Error( 'avandwp_tts_empty', 'متن ورودی برای تولید فایل صوتی خالی است.' );
		}

		$allowed_voices = array( 'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer' );
		if ( ! in_array( $voice, $allowed_voices, true ) ) {
			$voice = 'nova';
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_tts_no_key', 'برای تولید پادکست صوتی، کلید GapGPT یا OpenAI الزامی است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/audio/speech'
			: 'https://api.openai.com/v1/audio/speech';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => 'tts-1',
						'input'           => $plain,
						'voice'           => $voice,
						'response_format' => 'mp3',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$bytes = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || strlen( $bytes ) < 256 ) {
			return new WP_Error( 'avandwp_tts_api_error', 'خطا در دریافت فایل صوتی از سرویس هوش مصنوعی (کد ' . $code . ').' );
		}

		$filename = 'avandwp-audio-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.mp3';
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'avandwp_tts_upload_error', $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => 'audio/mpeg',
			'post_title'     => 'پادکست صوتی آوند — ' . current_time( 'Y-m-d H:i' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id  = wp_insert_attachment( $attachment, $upload['file'] );

		AvandWP_Logger::record_usage( $provider, 'tts-1', AvandWP_Text_Helper::estimate_tokens( $plain ), 0, 'tts' );

		return array(
			'url'           => $upload['url'],
			'attachment_id' => (int) $attach_id,
			'voice'         => $voice,
		);
	}

	/**
	 * Generate a real embedding vector via `/v1/embeddings` (`text-embedding-3-small`).
	 *
	 * @param string $text Input text.
	 * @return array<int, float>|WP_Error Array of floats or WP_Error.
	 */
	public function create_embedding( $text ) {
		$plain = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $plain ) {
			return new WP_Error( 'avandwp_embed_empty', 'متن ورودی برای ساخت Embedding خالی است.' );
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_embed_no_key', 'کلید GapGPT یا OpenAI برای Embeddings تنظیم نشده است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/embeddings'
			: 'https://api.openai.com/v1/embeddings';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => 'text-embedding-3-small',
						'input' => function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 4000, 'UTF-8' ) : substr( $plain, 0, 4000 ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['data'][0]['embedding'] ) || ! is_array( $body['data'][0]['embedding'] ) ) {
			return new WP_Error( 'avandwp_embed_failed', 'خطا در دریافت بردار معنایی (Embedding).' );
		}

		$tokens = isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : AvandWP_Text_Helper::estimate_tokens( $plain );
		AvandWP_Logger::record_usage( $provider, 'text-embedding-3-small', $tokens, 0, 'embedding' );

		return array_map( 'floatval', $body['data'][0]['embedding'] );
	}
}
