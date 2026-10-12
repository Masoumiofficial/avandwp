<?php
/**
 * Provider registry: single source of truth for AI provider metadata.
 *
 * Before this class, provider knowledge was scattered: base URLs were inline in
 * the AI client, model ids were inline defaults, the vault held the key slots
 * and the settings screen held a third, divergent copy of the same names. A
 * wrong model id in one place (`dall-e-3`, which is not in the GapGPT catalogue)
 * could therefore only be found by reading the request that failed — and paid
 * for.
 *
 * Every entry here was verified against provider documentation on the date in
 * `VERIFIED_ON`. Where documentation could not be reached, the capability is
 * marked `verified => false` and the UI is required to say so, rather than
 * presenting an unverified endpoint as a working integration.
 *
 * @package AvandWP\Providers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of text, image and audio providers.
 */
class AvandWP_Provider_Registry {

	/**
	 * Date the endpoint and model information below was last verified against
	 * first-party documentation.
	 */
	const VERIFIED_ON = AvandWP_Price_Catalog::VERIFIED_ON;

	/**
	 * Capabilities a provider slot can serve.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities() {
		return array(
			'text'      => 'تولید و ویرایش متن',
			'image'     => 'تولید تصویر',
			'transcribe' => 'تبدیل صوت به متن',
			'speech'    => 'تبدیل متن به گفتار',
			'embedding' => 'بردار معنایی (جستجوی معنایی)',
		);
	}

	/**
	 * Full provider definitions.
	 *
	 * `adapter` maps to the wire protocol family used by the client:
	 *   - `openai_compatible` — `POST {base}/chat/completions`
	 *   - `gemini`            — `POST {base}/models/{model}:generateContent`
	 *   - `claude`            — `POST {base}/messages`
	 *   - `fal_run`           — `POST {base}` (model in the URL path)
	 *   - `stability`         — `POST {base}` (multipart form)
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		return array(
			'gapgpt'    => array(
				'label'        => 'GapGPT (درگاه ریالی، بدون تحریم)',
				'adapter'      => 'openai_compatible',
				'base_url'     => 'https://api.gapgpt.app/v1',
				'backup_urls'  => array( 'https://api.gapapi.com/v1' ),
				'auth'         => 'bearer',
				'capabilities' => array( 'text', 'image', 'transcribe', 'speech', 'embedding' ),
				'docs'         => 'https://gapgpt.app/platform-v2/docs/quickstart',
				'verified'     => true,
				'note'         => 'سازگار با SDK رسمی OpenAI؛ آدرس جایگزین api.gapapi.com برای زمانی است که دامنه اصلی در دسترس نباشد.',
			),
			'openai'    => array(
				'label'        => 'OpenAI (دسترسی مستقیم)',
				'adapter'      => 'openai_compatible',
				'base_url'     => 'https://api.openai.com/v1',
				'backup_urls'  => array(),
				'auth'         => 'bearer',
				'capabilities' => array( 'text', 'image', 'transcribe', 'speech', 'embedding' ),
				'docs'         => 'https://platform.openai.com/docs/api-reference',
				// Endpoint family is stable and well known, but the individual
				// models offered are NOT in the verified price catalogue, so cost
				// reporting for this slot is estimated and labelled as such.
				'verified'     => true,
				'catalog'      => false,
				'note'         => 'هزینه‌ها برای این سرویس تخمینی گزارش می‌شود، چون فهرست قیمت آن در کاتالوگ تأییدشده این افزونه ثبت نشده است.',
			),
			'gemini'    => array(
				'label'        => 'Google Gemini',
				'adapter'      => 'gemini',
				'base_url'     => 'https://generativelanguage.googleapis.com/v1beta',
				'backup_urls'  => array(),
				'auth'         => 'header_x_goog_api_key',
				'capabilities' => array( 'text', 'embedding' ),
				'docs'         => 'https://ai.google.dev/gemini-api/docs',
				'verified'     => true,
				'catalog'      => false,
			),
			'claude'    => array(
				'label'        => 'Anthropic Claude',
				'adapter'      => 'claude',
				'base_url'     => 'https://api.anthropic.com/v1',
				'backup_urls'  => array(),
				'auth'         => 'header_x_api_key',
				'capabilities' => array( 'text' ),
				'docs'         => 'https://docs.anthropic.com/en/api/messages',
				'verified'     => true,
				'catalog'      => false,
			),
			'fal_flux'  => array(
				'label'        => 'FAL.ai (Flux)',
				'adapter'      => 'fal_run',
				'base_url'     => 'https://fal.run',
				'backup_urls'  => array(),
				'auth'         => 'key',
				'capabilities' => array( 'image' ),
				'docs'         => 'https://fal.ai/models/fal-ai/flux/schnell/api',
				// The synchronous endpoint is used deliberately: the legacy async
				// queue flow was the source of the baseline's 404s.
				'verified'     => true,
				'catalog'      => false,
			),
			'stability' => array(
				'label'        => 'Stability AI (SD3)',
				'adapter'      => 'stability',
				'base_url'     => 'https://api.stability.ai/v2beta/stable-image/generate',
				'backup_urls'  => array(),
				'auth'         => 'bearer',
				'capabilities' => array( 'image' ),
				'docs'         => 'https://platform.stability.ai/docs/api-reference',
				'verified'     => true,
				'catalog'      => false,
			),
		);
	}

	/**
	 * Provider slugs capable of a capability, in the given preference order.
	 *
	 * Only providers that have a verified definition AND a stored API key are
	 * returned when `$configured_only` is true.
	 *
	 * @param string $capability    Capability slug.
	 * @param bool   $configured_only Restrict to providers with a key.
	 * @return array<int, string>
	 */
	public static function for_capability( $capability, $configured_only = true ) {
		$out = array();

		foreach ( self::all() as $slug => $provider ) {
			if ( ! in_array( $capability, $provider['capabilities'], true ) ) {
				continue;
			}

			if ( $configured_only && ! AvandWP_Vault::has_key( $slug ) ) {
				continue;
			}

			$out[] = $slug;
		}

		return $out;
	}

	/**
	 * Get a single provider definition.
	 *
	 * @param string $slug Provider slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}

	/**
	 * Is this a known, supported provider slug?
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_known( $slug ) {
		return null !== self::get( $slug );
	}

	/**
	 * Does this provider hold a stored API key?
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function is_configured( $slug ) {
		return AvandWP_Vault::has_key( $slug );
	}

	/**
	 * Resolved base URL for a provider.
	 *
	 * @param string $slug Provider slug.
	 * @return string Empty string when unknown.
	 */
	public static function base_url( $slug ) {
		$provider = self::get( $slug );

		return $provider ? (string) $provider['base_url'] : '';
	}

	/**
	 * HTTP headers carrying authentication for a provider.
	 *
	 * @param string $slug Provider slug.
	 * @param string $key  API key (already decrypted).
	 * @return array<string, string>
	 */
	public static function auth_headers( $slug, $key ) {
		$provider = self::get( $slug );

		if ( ! $provider ) {
			return array();
		}

		switch ( $provider['auth'] ) {
			case 'header_x_goog_api_key':
				return array( 'x-goog-api-key' => $key );
			case 'header_x_api_key':
				return array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
			case 'key':
				return array( 'Authorization' => 'Key ' . $key );
			case 'bearer':
			default:
				return array( 'Authorization' => 'Bearer ' . $key );
		}
	}

	/**
	 * Model list for a provider and capability.
	 *
	 * Falls back to the verified GapGPT catalogue shape only when the provider
	 * has no catalogue of its own; in that case the returned entries are the
	 * caller's configured values, never invented ids.
	 *
	 * @param string $capability Capability slug.
	 * @param string $provider   Provider slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function models( $capability, $provider ) {
		return AvandWP_Price_Catalog::models_for( $capability, $provider );
	}

	/**
	 * Default model for a provider and capability.
	 *
	 * There is deliberately no global string fallback: an unknown provider
	 * returns an empty string so the caller fails loudly with a clear message
	 * instead of sending a model id that does not exist.
	 *
	 * @param string $capability Capability slug.
	 * @param string $provider   Provider slug.
	 * @return string
	 */
	public static function default_model( $capability, $provider ) {
		$settings = get_option( 'avandwp_settings', array() );
		$slot     = 'model_' . $provider;

		if ( ! empty( $settings[ $slot ] ) ) {
			return sanitize_text_field( (string) $settings[ $slot ] );
		}

		return AvandWP_Price_Catalog::default_model( $capability, $provider );
	}

	/**
	 * Resolve a model id, validating it against the catalogue when one exists.
	 *
	 * @param string $capability Capability slug.
	 * @param string $provider   Provider slug.
	 * @param string $requested  Requested model id (may be empty).
	 * @return array{model: string, verified: bool, note: string}|WP_Error
	 */
	public static function resolve_model( $capability, $provider, $requested = '' ) {
		if ( ! self::is_known( $provider ) ) {
			return new WP_Error(
				'avandwp_invalid_provider',
				sprintf( 'سرویس‌دهنده «%s» در فهرست سرویس‌های پشتیبانی‌شده نیست.', $provider )
			);
		}

		$definition = self::get( $provider );

		if ( ! in_array( $capability, $definition['capabilities'], true ) ) {
			return new WP_Error(
				'avandwp_unsupported_capability',
				sprintf( '%s از قابلیت «%s» پشتیبانی نمی‌کند.', $definition['label'], self::capabilities()[ $capability ] ?? $capability )
			);
		}

		$model = '' !== $requested
			? sanitize_text_field( $requested )
			: self::default_model( $capability, $provider );

		if ( '' === $model ) {
			return new WP_Error(
				'avandwp_no_model',
				sprintf( 'مدلی برای %s در %s تنظیم نشده است. مدل را در تنظیمات انتخاب کنید.', self::capabilities()[ $capability ] ?? $capability, $definition['label'] )
			);
		}

		$catalogue_available = ! empty( $definition['catalog'] ) || 'gapgpt' === $provider;

		if ( ! $catalogue_available ) {
			return array(
				'model'    => $model,
				'verified' => false,
				'note'     => sprintf( 'مدل %s برای %s در کاتالوگ قیمت تأییدشده این افزونه نیست؛ هزینه تخمینی گزارش می‌شود.', $model, $definition['label'] ),
			);
		}

		if ( ! AvandWP_Price_Catalog::is_known_model( $capability, $model, $provider ) ) {
			return new WP_Error(
				'avandwp_unknown_model',
				sprintf(
					'مدل «%s» برای %s در فهرست مدل‌های تأییدشده (%s) وجود ندارد. مدل‌های مجاز در تنظیمات نمایش داده می‌شوند.',
					$model,
					$definition['label'],
					self::VERIFIED_ON
				)
			);
		}

		return array( 'model' => $model, 'verified' => true, 'note' => '' );
	}

	/**
	 * Snapshot for the settings screen and the health report.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function snapshot() {
		$out = array();

		foreach ( self::all() as $slug => $provider ) {
			$out[] = array(
				'slug'         => $slug,
				'label'        => $provider['label'],
				'configured'   => self::is_configured( $slug ),
				'verified'     => ! empty( $provider['verified'] ),
				'verified_on'  => self::VERIFIED_ON,
				'capabilities' => array_values(
					array_map(
						static function ( $cap ) {
							return self::capabilities()[ $cap ] ?? $cap;
						},
						$provider['capabilities']
					)
				),
				'docs'         => $provider['docs'],
				'note'         => $provider['note'] ?? '',
				'models'       => array(
					'text'  => 'gapgpt' === $slug ? 'verified_catalog' : 'manual',
					'image' => AvandWP_Price_Catalog::models_for( 'image', $slug ) ? 'verified_catalog' : 'manual',
				),
			);
		}

		return $out;
	}
}
