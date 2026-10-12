<?php
/**
 * Multi-provider image generation client.
 *
 * Corrections over the 1.0.0 implementation, each traceable to a finding in
 * `docs/technical-audit.md`:
 *
 *   - F-02  `dall-e-3` was hardcoded. That identifier is not in the GapGPT
 *           catalogue; the request could only fail. Models now come from the
 *           verified catalogue, and an unknown id is rejected *before* the
 *           request is sent.
 *   - F-02  `response_format: b64_json` was requested unconditionally. GapGPT's
 *           documented image response carries a URL, not inline base64, so the
 *           b64 branch was dead code guarding a paid failure. The response
 *           shape is now detected instead of assumed.
 *   - F-13  `mb_substr()` was called unguarded in the media-library path; on a
 *           host without mbstring this is a fatal error, not a degradation.
 *   - F-15a Stability ignored the requested size entirely and always generated
 *           16:9. The mapping is now explicit and documented.
 *   - F-15b The downloaded image was saved with whatever MIME the remote server
 *           claimed. Bytes are now sniffed against an allow-list.
 *   - F-15c There was no cap on the downloaded body size. Downloads now go
 *           through the outbound guard's byte ceiling.
 *   - F-08  Usage was recorded as a fabricated "500 tokens". Cost is now
 *           estimated from the verified price catalogue and labelled with its
 *           source.
 *
 * @package AvandWP\Providers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image generation and Media Library attachment.
 */
class AvandWP_Image_Client {

	/**
	 * Sizes the plugin offers, mapped to the closest size each provider accepts.
	 *
	 * @return array<int, string>
	 */
	public static function supported_sizes() {
		return array( '1024x1024', '1536x1024', '1024x1536', '1792x1024', '1024x1792' );
	}

	/**
	 * Normalise a requested size to one the provider accepts.
	 *
	 * @param string $size    Requested size.
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function normalize_size( $size, $provider = 'gapgpt' ) {
		$size = (string) $size;

		$openai_like = array( '1024x1024', '1536x1024', '1024x1536' );
		$legacy      = array( '1024x1024', '1792x1024', '1024x1792' );

		if ( 'openai' === $provider ) {
			return in_array( $size, $openai_like, true ) ? $size : '1024x1024';
		}

		if ( 'gapgpt' === $provider ) {
			// GapGPT publishes both families; accept whichever the UI offered.
			$allowed = array_unique( array_merge( $openai_like, $legacy ) );
			return in_array( $size, $allowed, true ) ? $size : '1024x1024';
		}

		return '1024x1024';
	}

	/**
	 * Generate an image and save it to the WordPress Media Library.
	 *
	 * @param string $prompt Visual description prompt.
	 * @param array  $args   Optional: provider, model, size, alt_text, post_id, caption, title, inline (bool).
	 * @return array|WP_Error {
	 *     @type int    $attachment_id Attachment ID.
	 *     @type string $url           Public URL.
	 *     @type string $provider      Provider slug actually used.
	 *     @type string $model         Model id actually used.
	 *     @type string $mime          Sniffed MIME type.
	 *     @type int    $bytes         Byte size.
	 *     @type bool   $is_placeholder Always false; kept for API compatibility.
	 * }
	 */
	public function generate_and_attach( $prompt, array $args = array() ) {
		$prompt = trim( sanitize_textarea_field( (string) $prompt ) );

		if ( '' === $prompt ) {
			return new WP_Error( 'avandwp_empty_image_prompt', 'توضیح تصویر (پرامپت) نمی‌تواند خالی باشد.' );
		}

		$settings  = get_option( 'avandwp_settings', array() );
		$preferred = ! empty( $args['provider'] ) ? sanitize_key( $args['provider'] ) : ( $settings['image_provider'] ?? 'gapgpt' );
		$alt_text  = ! empty( $args['alt_text'] ) ? sanitize_text_field( $args['alt_text'] ) : '';
		$parent_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		// The alt text is what a blind user hears; falling back to the raw prompt
		// is better than an empty attribute, but it is never the prompt verbatim
		// when the caller supplied something meaningful.
		if ( '' === $alt_text ) {
			$alt_text = AvandWP_Text_Helper::safe_substr( $prompt, 0, 125 );
		}

		$spend = AvandWP_Spend_Guard::can_spend( 1 );
		if ( is_wp_error( $spend ) ) {
			return $spend;
		}

		$candidates = array_values(
			array_unique(
				array_merge(
					array_filter( array( $preferred ) ),
					AvandWP_Provider_Registry::for_capability( 'image' )
				)
			)
		);

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'avandwp_no_image_key',
				'هیچ کلید فعالی برای تولید تصویر (GapGPT، OpenAI، FAL Flux یا Stability) در گاوصندوق کلیدها ثبت نشده است.'
			);
		}

		$last_error = null;

		foreach ( $candidates as $provider ) {
			$resolved = AvandWP_Provider_Registry::resolve_model(
				'image',
				$provider,
				! empty( $args['model'] ) ? (string) $args['model'] : ''
			);

			if ( is_wp_error( $resolved ) ) {
				$last_error = $resolved;
				continue;
			}

			$res = $this->call_provider( $provider, $resolved['model'], $prompt, $args );

			if ( is_wp_error( $res ) ) {
				$last_error = $res;

				AvandWP_Logger::log(
					'image.failed',
					sprintf( 'تولید تصویر با %s ناموفق بود: %s', $provider, $res->get_error_message() ),
					'warning'
				);

				// An invalid key or an unsupported model will fail identically on
				// every other provider that shares the adapter family, so there is
				// no value in spending another paid request.
				$reason = (string) ( $res->get_error_data()['reason'] ?? '' );
				if ( 'auth' === $reason ) {
					break;
				}

				continue;
			}

			$saved = $this->save_to_media_library( $res, $alt_text, $parent_id, $args );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$this->record_image_usage( $provider, $res['model'], $res['size'], $resolved['note'] );

			AvandWP_Logger::log(
				'image.generated',
				sprintf( 'تصویر با سرویس %s (مدل %s) تولید و در رسانه ذخیره شد (#%d).', $provider, $res['model'], $saved['attachment_id'] ),
				'success'
			);

			return array(
				'attachment_id'  => $saved['attachment_id'],
				'url'            => $saved['url'],
				'provider'       => $provider,
				'model'          => $res['model'],
				'mime'           => $res['mime'],
				'bytes'          => strlen( $res['bytes'] ),
				'is_placeholder' => false,
			);
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error( 'avandwp_image_failed', 'تولید تصویر با هیچ‌یک از سرویس‌های فعال انجام نشد.' );
	}

	/**
	 * Dispatch an image request to a provider.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Resolved, catalogue-verified model id.
	 * @param string $prompt   Prompt.
	 * @param array  $args     Request options.
	 * @return array|WP_Error ['bytes' => string, 'mime' => string, 'ext' => string, 'model' => string, 'size' => string]
	 */
	private function call_provider( $provider, $model, $prompt, array $args ) {
		$key = AvandWP_Vault::get_key( $provider );

		if ( '' === $key ) {
			return new WP_Error( 'avandwp_missing_key', sprintf( 'کلید سرویس %s تنظیم نشده است.', $provider ) );
		}

		switch ( $provider ) {
			case 'gapgpt':
			case 'openai':
				return $this->call_images_api( $provider, $key, $model, $prompt, $args );

			case 'fal_flux':
				return $this->call_fal_flux( $key, $model, $prompt, $args );

			case 'stability':
				return $this->call_stability( $key, $prompt, $args );

			default:
				return new WP_Error( 'avandwp_unknown_image_provider', 'سرویس تصویر نامعتبر است.' );
		}
	}

	/**
	 * Call the OpenAI-compatible `/images/generations` endpoint.
	 *
	 * The response shape is detected rather than assumed: GapGPT documents a URL
	 * response, OpenAI can return base64. Both paths validate exactly the same
	 * way once bytes are in hand.
	 *
	 * @param string $provider `gapgpt` or `openai`.
	 * @param string $key      API key.
	 * @param string $model    Model id.
	 * @param string $prompt   Prompt.
	 * @param array  $args     Options.
	 * @return array|WP_Error
	 */
	private function call_images_api( $provider, $key, $model, $prompt, array $args ) {
		$base     = AvandWP_Provider_Registry::base_url( $provider );
		$endpoint = rtrim( $base, '/' ) . '/images/generations';
		$size     = self::normalize_size( $args['size'] ?? '1024x1024', $provider );
		$timeout  = max( 30, min( 180, (int) ( $args['timeout'] ?? 120 ) ) );

		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $size,
		);

		// Style guidance is appended to the prompt text, not sent as an
		// undocumented parameter: unknown fields are rejected by strict gateways.
		if ( ! empty( $args['style'] ) ) {
			$body['prompt'] = $prompt . "\n\nسبک تصویر: " . AvandWP_Text_Helper::safe_substr( (string) $args['style'], 0, 300 );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array_merge(
					AvandWP_Provider_Registry::auth_headers( $provider, $key ),
					array( 'Content-Type' => 'application/json' )
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: ( 'HTTP ' . $code );

			return new WP_Error(
				'avandwp_image_api_error',
				sprintf( 'خطای تصویرساز %s: %s', strtoupper( $provider ), $msg ),
				AvandWP_AI_Client::classify_failure( $code, $msg )
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'avandwp_image_bad_response', 'پاسخ تصویرساز قابل خواندن نبود (JSON نامعتبر).' );
		}

		$entry = $decoded['data'][0] ?? null;

		if ( ! is_array( $entry ) ) {
			return new WP_Error( 'avandwp_image_empty', 'پاسخ تصویرساز فاقد داده تصویر بود.' );
		}

		if ( ! empty( $entry['b64_json'] ) ) {
			$bytes = base64_decode( (string) $entry['b64_json'], true );

			if ( false === $bytes ) {
				return new WP_Error( 'avandwp_image_bad_base64', 'داده تصویر پایه‌۶۴ دریافتی معتبر نبود.' );
			}

			return $this->finalize_bytes( $bytes, $model, $size );
		}

		if ( ! empty( $entry['url'] ) ) {
			// Documented GapGPT behaviour: the API returns a URL to the generated
			// file, so a second, guarded request is required to obtain the bytes.
			return $this->download_image_bytes( (string) $entry['url'], $model, $size );
		}

		return new WP_Error( 'avandwp_image_empty', 'پاسخ تصویرساز فاقد داده تصویر بود.' );
	}

	/**
	 * Call FAL.ai's synchronous Flux endpoint.
	 *
	 * @param string $key    API key.
	 * @param string $model  Model id.
	 * @param string $prompt Prompt.
	 * @param array  $args   Options.
	 * @return array|WP_Error
	 */
	private function call_fal_flux( $key, $model, $prompt, array $args ) {
		$size = self::normalize_size( $args['size'] ?? '1024x1024', 'fal_flux' );

		$image_size = 'square_hd';
		if ( in_array( $size, array( '1536x1024', '1792x1024' ), true ) ) {
			$image_size = 'landscape_16_9';
		} elseif ( in_array( $size, array( '1024x1536', '1024x1792' ), true ) ) {
			$image_size = 'portrait_16_9';
		}

		$model_path = ( 'flux-1-schnell' === $model ) ? 'fal-ai/flux/schnell' : $model;
		$endpoint   = rtrim( AvandWP_Provider_Registry::base_url( 'fal_flux' ), '/' ) . '/' . ltrim( $model_path, '/' );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 90,
				'headers' => array_merge(
					AvandWP_Provider_Registry::auth_headers( 'fal_flux', $key ),
					array( 'Content-Type' => 'application/json' )
				),
				'body'    => wp_json_encode(
					array(
						'prompt'              => $prompt,
						'image_size'          => $image_size,
						'num_inference_steps' => 4,
						'num_images'          => 1,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['images'][0]['url'] ) ) {
			$msg = is_array( $decoded ) ? ( $decoded['detail'] ?? ( 'HTTP ' . $code ) ) : ( 'HTTP ' . $code );

			return new WP_Error(
				'avandwp_flux_error',
				'خطای FAL Flux: ' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ),
				AvandWP_AI_Client::classify_failure( $code, is_string( $msg ) ? $msg : '' )
			);
		}

		return $this->download_image_bytes( (string) $decoded['images'][0]['url'], $model, $size );
	}

	/**
	 * Call the Stability AI SD3 endpoint (multipart form).
	 *
	 * @param string $key    API key.
	 * @param string $prompt Prompt.
	 * @param array  $args   Options.
	 * @return array|WP_Error
	 */
	private function call_stability( $key, $prompt, array $args ) {
		$size = self::normalize_size( $args['size'] ?? '1024x1024', 'stability' );

		// F-15a: the requested aspect ratio is honoured now instead of always
		// producing 16:9 regardless of what the user chose.
		$aspect = '1:1';
		if ( in_array( $size, array( '1536x1024', '1792x1024' ), true ) ) {
			$aspect = '16:9';
		} elseif ( in_array( $size, array( '1024x1536', '1024x1792' ), true ) ) {
			$aspect = '9:16';
		}

		$boundary = '----AvandWPStability' . wp_generate_password( 12, false );

		$fields = array(
			'prompt'        => $prompt,
			'output_format' => 'png',
			'aspect_ratio'  => $aspect,
		);

		$body = '';
		foreach ( $fields as $name => $val ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$val}\r\n";
		}
		$body .= "--{$boundary}--\r\n";

		$endpoint = rtrim( AvandWP_Provider_Registry::base_url( 'stability' ), '/' ) . '/sd3';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'image/*',
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$bytes = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( $bytes, true );
			$msg     = is_array( $decoded ) ? (string) ( $decoded['errors'][0] ?? $decoded['message'] ?? '' ) : '';

			return new WP_Error(
				'avandwp_stability_error',
				'خطای Stability AI (کد ' . $code . ')' . ( '' !== $msg ? ': ' . $msg : '.' ),
				AvandWP_AI_Client::classify_failure( $code, $msg )
			);
		}

		return $this->finalize_bytes( $bytes, 'sd3', $size );
	}

	/**
	 * Download generated image bytes through the outbound guard.
	 *
	 * @param string $url   Remote URL.
	 * @param string $model Model id.
	 * @param string $size  Requested size.
	 * @return array|WP_Error
	 */
	private function download_image_bytes( $url, $model, $size ) {
		// F-15b/F-15c: SSRF validation, an 8 MiB ceiling and MIME sniffing all
		// happen inside the guard. Only allow-listed raster formats are accepted.
		$fetched = AvandWP_Outbound_Guard::fetch_image( $url );

		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		return array(
			'bytes' => $fetched['bytes'],
			'mime'  => $fetched['mime'],
			'ext'   => $fetched['ext'],
			'model' => $model,
			'size'  => $size,
		);
	}

	/**
	 * Validate raw bytes that came back inline (base64 branch of the API).
	 *
	 * @param string $bytes Raw image bytes.
	 * @param string $model Model id.
	 * @param string $size  Requested size.
	 * @return array|WP_Error
	 */
	private function finalize_bytes( $bytes, $model, $size ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 64 ) {
			return new WP_Error( 'avandwp_image_too_small', 'تصویر دریافتی از سرویس، معتبر یا کامل نیست.' );
		}

		if ( strlen( $bytes ) > AvandWP_Outbound_Guard::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'avandwp_image_too_large', 'حجم تصویر دریافتی از حد مجاز بیشتر است.' );
		}

		// detect_image_mime() returns a MIME string (or null), not a
		// {mime, ext} array. Treating it as an array produced an invalid mime
		// and extension for every generated image, so the two values are
		// derived here from the guard's own allow-list.
		$detected = AvandWP_Outbound_Guard::detect_image_mime( $bytes );

		if ( null === $detected ) {
			return new WP_Error(
				'avandwp_not_an_image',
				'محتوای بازگشتی از سرویس تصویر، یک تصویر معتبر نیست و ذخیره نشد.'
			);
		}

		$mimes = AvandWP_Outbound_Guard::allowed_image_mimes();

		if ( ! isset( $mimes[ $detected ] ) ) {
			return new WP_Error(
				'avandwp_image_mime_rejected',
				sprintf( 'قالب تصویر بازگشتی (%s) پشتیبانی نمی‌شود.', $detected )
			);
		}

		return array(
			'bytes' => $bytes,
			'mime'  => $detected,
			'ext'   => (string) $mimes[ $detected ],
			'model' => $model,
			'size'  => $size,
		);
	}

	/**
	 * Save raw image binary to the uploads directory and create an attachment.
	 *
	 * @param array  $res       Result from a provider call.
	 * @param string $alt_text  Alt text.
	 * @param int    $parent_id Parent post ID.
	 * @param array  $args      Original arguments (title, caption).
	 * @return array|WP_Error ['attachment_id' => int, 'url' => string]
	 */
	private function save_to_media_library( array $res, $alt_text, $parent_id, array $args ) {
		$ext      = (string) $res['ext'];
		$filename = 'avandwp-img-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $res['bytes'] );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'avandwp_upload_error', $upload['error'] );
		}

		$title = ! empty( $args['title'] )
			? sanitize_text_field( (string) $args['title'] )
			: $alt_text;

		$attachment = array(
			'post_mime_type' => $res['mime'],
			// F-13: safe_substr instead of an unguarded mb_substr() call.
			'post_title'     => AvandWP_Text_Helper::safe_substr( $title, 0, 80 ),
			'post_content'   => '',
			'post_excerpt'   => AvandWP_Text_Helper::safe_substr( $alt_text, 0, 200 ),
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], (int) $parent_id );

		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'avandwp_attach_error', 'خطا در ثبت تصویر در کتابخانه رسانه وردپرس.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );

		if ( is_array( $attach_data ) ) {
			$attach_data['avandwp_model'] = (string) $res['model'];
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		update_post_meta( $attach_id, '_avandwp_generated_image', 1 );
		update_post_meta( $attach_id, '_avandwp_image_model', sanitize_text_field( (string) $res['model'] ) );

		return array(
			'attachment_id' => (int) $attach_id,
			'url'           => $upload['url'],
		);
	}

	/**
	 * Record a real, sourced cost estimate for an image operation.
	 *
	 * @param string $provider    Provider slug.
	 * @param string $model       Model id.
	 * @param string $size        Size string.
	 * @param string $model_note  Note about verification status of the model.
	 * @return void
	 */
	private function record_image_usage( $provider, $model, $size, $model_note = '' ) {
		$estimate = AvandWP_Price_Catalog::estimate_image( $provider, $model, $size );

		AvandWP_Logger::record_usage(
			$provider,
			$model,
			0,
			0,
			'image',
			0,
			array(
				'operation'   => 'image',
				'units'       => 1,
				'cost_usd'    => $estimate['cost'],
				'cost_source' => $estimate['source'],
				'cost_note'   => $estimate['note'] . ( '' !== $model_note ? ' ' . $model_note : '' ),
				'size'        => $size,
			)
		);
	}

	/**
	 * Generate a featured image for a post and set it as the post thumbnail.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $prompt  Prompt.
	 * @param array  $args    Options.
	 * @return array|WP_Error
	 */
	public function generate_featured_image( $post_id, $prompt, array $args = array() ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'avandwp_invalid_post', 'نوشته مقصد برای تصویر شاخص یافت نشد.' );
		}

		$args['post_id'] = $post_id;

		if ( empty( $args['size'] ) ) {
			// A 16:9 crop is the conventional featured-image ratio.
			$args['size'] = '1536x1024';
		}

		$image = $this->generate_and_attach( $prompt, $args );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		set_post_thumbnail( $post_id, $image['attachment_id'] );

		return $image;
	}

	/**
	 * Suggest alt text for an existing attachment that has none.
	 *
	 * The suggestion is generated by the configured text model and requires the
	 * caller to confirm it; nothing is written here.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error
	 */
	public function suggest_alt_text( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'avandwp_invalid_attachment', 'پیوست مورد نظر یافت نشد.' );
		}

		$title = (string) $attachment->post_title;

		$ai = new AvandWP_AI_Client();

		$res = $ai->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'تو متن جانشین (alt) تصویر را برای دسترس‌پذیری می‌نویسی. فقط خود متن را برگردان، بدون توضیح، بدون نقل‌قول و در حداکثر ۱۲۵ کاراکتر.',
				),
				array(
					'role'    => 'user',
					'content' => 'عنوان تصویر: ' . ( '' !== $title ? $title : 'بدون عنوان' ),
				),
			),
			array(
				'feature'    => 'image_alt',
				'max_tokens' => 120,
				'temperature' => 0.2,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return AvandWP_Text_Helper::safe_substr( trim( wp_strip_all_tags( $res['content'] ) ), 0, 125 );
	}
}
