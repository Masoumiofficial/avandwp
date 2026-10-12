<?php
/**
 * Guarded outbound HTTP retrieval.
 *
 * Every URL that arrives from a *third party* — an AI provider's image URL, a
 * user-supplied reference link, a crawler target — is fetched through this
 * class rather than `wp_remote_get()` directly.
 *
 * The 1.0.0 image client passed provider URLs to `wp_remote_get()` after a bare
 * `wp_http_validate_url()` check with no response-size ceiling and no real
 * content-type validation, so an unexpected response could exhaust memory or
 * import non-image bytes into the Media Library as `image/png`.
 *
 * Protections applied here:
 *   - `wp_http_validate_url()` rejects loopback, private, link-local and
 *     non-HTTP(S) targets (SSRF).
 *   - `redirection => 0` so a redirect cannot bounce the request to a private
 *     address that the pre-check already rejected.
 *   - Hard byte ceiling enforced *before* the body is used.
 *   - Sniffed MIME allowed-list; the declared header is not trusted alone.
 *
 * @package AvandWP\Security
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe remote fetch helper.
 */
class AvandWP_Outbound_Guard {

	/**
	 * Maximum bytes accepted for an image payload (8 MiB).
	 */
	const MAX_IMAGE_BYTES = 8388608;

	/**
	 * Maximum bytes accepted for an HTML page used during link verification (2 MiB).
	 */
	const MAX_HTML_BYTES = 2097152;

	/**
	 * MIME types accepted as real images, mapped to their file extension.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_image_mimes() {
		return array(
			'image/png'     => 'png',
			'image/jpeg'    => 'jpg',
			'image/webp'    => 'webp',
			'image/gif'     => 'gif',
			'image/avif'    => 'avif',
		);
	}

	/**
	 * Is this URL safe to fetch?
	 *
	 * @param string $url URL to check.
	 * @return true|WP_Error
	 */
	public static function validate_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'avandwp_url_empty', 'آدرسی برای بررسی ارسال نشده است.' );
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'avandwp_url_scheme', 'فقط آدرس‌های http و https قابل استفاده هستند.' );
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'avandwp_url_rejected',
				'این آدرس از نظر امنیتی مجاز نیست (آدرس‌های داخلی، محلی و بدون دامنه معتبر پذیرفته نمی‌شوند).'
			);
		}

		/**
		 * Filters whether an outbound URL is permitted.
		 *
		 * @param true|WP_Error $allowed Current decision.
		 * @param string        $url     URL under inspection.
		 */
		return apply_filters( 'avandwp_outbound_url_allowed', true, $url );
	}

	/**
	 * Fetch an image payload with a byte ceiling and real-type validation.
	 *
	 * @param string $url       Image URL.
	 * @param int    $max_bytes Optional byte ceiling.
	 * @return array{bytes: string, mime: string, ext: string}|WP_Error
	 */
	public static function fetch_image( $url, $max_bytes = self::MAX_IMAGE_BYTES ) {
		$safe = self::validate_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 45,
				// Do not follow redirects: the SSRF pre-check applies to the
				// original URL only, so a redirect could otherwise reach a
				// private address.
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'image/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'avandwp_image_fetch_status',
				sprintf( 'دریافت تصویر با کد وضعیت %d ناموفق بود.', $code )
			);
		}

		$declared = (int) wp_remote_retrieve_header( $response, 'content-length' );
		if ( $declared > 0 && $declared > (int) $max_bytes ) {
			return new WP_Error(
				'avandwp_image_too_large',
				sprintf( 'حجم تصویر (٪%s) بیش از حد مجاز است.', size_format( $declared ) )
			);
		}

		$bytes = wp_remote_retrieve_body( $response );

		if ( strlen( $bytes ) < 128 ) {
			return new WP_Error( 'avandwp_image_empty', 'پاسخ تصویر خالی یا ناقص بود.' );
		}

		if ( strlen( $bytes ) > (int) $max_bytes ) {
			return new WP_Error(
				'avandwp_image_too_large',
				'حجم تصویر دریافتی از حد مجاز بیشتر است.'
			);
		}

		// Sniff the actual bytes; the response header is a claim, not evidence.
		$detected = self::detect_image_mime( $bytes );

		if ( null === $detected ) {
			return new WP_Error(
				'avandwp_not_an_image',
				'محتوای دریافتی یک تصویر معتبر نیست و ذخیره نشد.'
			);
		}

		$mimes = self::allowed_image_mimes();

		return array(
			'bytes' => $bytes,
			'mime'  => $detected,
			'ext'   => $mimes[ $detected ],
		);
	}

	/**
	 * Fetch an HTML document, following a bounded number of redirects.
	 *
	 * Every hop is validated separately. Following redirects blindly is the
	 * classic way an SSRF allow-list is defeated: the first URL is public and
	 * the redirect target is not. Here each `Location` is resolved against the
	 * current URL and re-validated before it is requested.
	 *
	 * The caller always receives the body it asked for *and* the redirect chain,
	 * so a report can say "this URL redirects to that one" instead of silently
	 * treating the final URL as if the original had resolved there.
	 *
	 * @param string $url       URL to fetch.
	 * @param int    $max_bytes Byte ceiling.
	 * @param int    $max_hops  Maximum redirects to follow.
	 * @return array{code: int, body: string, content_type: string, final_url: string, redirects: array<int, array{from: string, to: string, code: int}>}|WP_Error
	 */
	public static function fetch_html( $url, $max_bytes = self::MAX_HTML_BYTES, $max_hops = 3 ) {
		$current  = (string) $url;
		$redirect = array();
		$hops     = 0;

		while ( true ) {
			$safe = self::validate_url( $current );

			if ( is_wp_error( $safe ) ) {
				return $safe;
			}

			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 30,
					'redirection' => 0,
					'user-agent'  => self::user_agent(),
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );

				if ( '' === $location || $hops >= (int) $max_hops ) {
					return array(
						'code'         => $code,
						'body'         => '',
						'content_type' => '',
						'final_url'    => $current,
						'redirects'    => $redirect,
					);
				}

				$next = self::absolutize_url( $location, $current );

				$redirect[] = array( 'from' => $current, 'to' => $next, 'code' => $code );

				$current = $next;
				++$hops;

				continue;
			}

			$declared = (int) wp_remote_retrieve_header( $response, 'content-length' );

			if ( $declared > 0 && $declared > (int) $max_bytes ) {
				return new WP_Error(
					'avandwp_html_too_large',
					sprintf( 'حجم پاسخ (٪%s) از سقف مجاز برای تحلیل بیشتر است.', size_format( $declared ) )
				);
			}

			$body = (string) wp_remote_retrieve_body( $response );

			// The body is trimmed at the byte ceiling rather than rejected:
			// a truncated head is still enough to audit titles and meta tags.
			if ( strlen( $body ) > (int) $max_bytes ) {
				$body = substr( $body, 0, (int) $max_bytes );
			}

			return array(
				'code'         => $code,
				'body'         => $body,
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
				'final_url'    => $current,
				'redirects'    => $redirect,
			);
		}
	}

	/**
	 * Resolve a possibly relative URL against a base URL.
	 *
	 * @param string $url  Absolute or relative URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function absolutize_url( $url, $base ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return $base;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $base );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			return $parts['scheme'] . ':' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );

		return $origin . $dir . $url;
	}

	/**
	 * User agent used for outbound fetches.
	 *
	 * Identifies the site and the plugin so an owner who sees the traffic in a
	 * server log knows where it came from.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return sprintf( 'AvandWP/%s; %s', defined( 'AVANDWP_VERSION' ) ? AVANDWP_VERSION : '1.0.0', home_url() );
	}

	/**
	 * Detect a real image MIME type from raw bytes.
	 *
	 * Uses `getimagesizefromstring()` when available (it parses the header), then
	 * falls back to magic-number matching so behaviour is identical on hosts
	 * without the GD extension.
	 *
	 * @param string $bytes Raw payload.
	 * @return string|null Detected MIME, or null when the payload is not an image.
	 */
	public static function detect_image_mime( $bytes ) {
		$allowed = self::allowed_image_mimes();

		if ( function_exists( 'getimagesizefromstring' ) ) {
			$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $info ) && ! empty( $info['mime'] ) && isset( $allowed[ $info['mime'] ] ) ) {
				return $info['mime'];
			}
		}

		if ( strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) === 0 ) {
			return 'image/png';
		}
		if ( strncmp( $bytes, "\xFF\xD8\xFF", 3 ) === 0 ) {
			return 'image/jpeg';
		}
		if ( strncmp( $bytes, 'GIF87a', 6 ) === 0 || strncmp( $bytes, 'GIF89a', 6 ) === 0 ) {
			return 'image/gif';
		}
		if ( strncmp( $bytes, 'RIFF', 4 ) === 0 && substr( $bytes, 8, 4 ) === 'WEBP' ) {
			return 'image/webp';
		}
		if ( strncmp( $bytes, "\x00\x00\x00", 3 ) === 0 && substr( $bytes, 4, 4 ) === 'ftyp' ) {
			$brand = substr( $bytes, 8, 4 );
			if ( 'avif' === $brand || 'avis' === $brand ) {
				return 'image/avif';
			}
		}

		return null;
	}

	/**
	 * Perform a lightweight reachability check on a URL.
	 *
	 * Used by link intelligence to distinguish a verified destination from an
	 * unverified candidate. A redirect is reported as its own outcome rather
	 * than silently followed, because a redirect chain is itself a finding.
	 *
	 * @param string $url URL to check.
	 * @return array{status: string, code: int, location: string, note: string}
	 *               `status` is one of: ok, redirect, error, unreachable, blocked.
	 */
	public static function check_url( $url ) {
		$safe = self::validate_url( $url );
		if ( is_wp_error( $safe ) ) {
			return array(
				'status'   => 'blocked',
				'code'     => 0,
				'location' => '',
				'note'     => $safe->get_error_message(),
			);
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		// Some servers reject HEAD; retry with a bounded GET before concluding.
		if ( is_wp_error( $response ) || 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 'unreachable',
				'code'     => 0,
				'location' => '',
				'note'     => $response->get_error_message(),
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( $code >= 300 && $code < 400 ) {
			return array(
				'status'   => 'redirect',
				'code'     => $code,
				'location' => $location,
				'note'     => 'مقصد به آدرس دیگری هدایت می‌شود؛ برای لینک‌سازی، آدرس نهایی را تأیید کنید.',
			);
		}

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'status'   => 'ok',
				'code'     => $code,
				'location' => '',
				'note'     => 'مقصد در دسترس است.',
			);
		}

		return array(
			'status'   => 'error',
			'code'     => $code,
			'location' => '',
			'note'     => sprintf( 'مقصد با کد وضعیت %d پاسخ داد و قابل استناد نیست.', $code ),
		);
	}
}
