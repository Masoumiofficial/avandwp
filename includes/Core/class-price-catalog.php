<?php
/**
 * Verified, dated price catalogue for AI providers.
 *
 * The 1.0.0 baseline shipped a three-entry OpenAI rate table and fell back to
 * "$0.15 / $0.60 per 1M" for *every* other model, which silently mispriced all
 * GapGPT models and presented invented numbers with the same authority as
 * metered ones.
 *
 * This catalogue is deliberately conservative:
 *
 *   - Every rate carries the date it was read from the provider's own pricing
 *     page, so a stale catalogue is visible rather than hidden.
 *   - A model that is not in the catalogue returns `null`, never a guess. The
 *     UI must render "unknown", not "$0.00" or a fabricated figure.
 *   - Prices are per provider. GapGPT's published rates are NOT OpenAI's rates,
 *     and the two are never mixed.
 *
 * Source of truth (verified 2026-10-12):
 *   https://gapgpt.app/platform-v2/pricing
 *
 * @package AvandWP\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider price catalogue and cost estimation.
 */
class AvandWP_Price_Catalog {

	/**
	 * Date the catalogue was last verified against provider documentation.
	 */
	const VERIFIED_ON = '2026-10-12';

	/**
	 * Source URL for the GapGPT rates below.
	 */
	const GAPGPT_SOURCE = 'https://gapgpt.app/platform-v2/pricing';

	/**
	 * Token-priced models: `provider => model => [usd_per_1m_input, usd_per_1m_output]`.
	 *
	 * @return array<string, array<string, array{0: float, 1: float}>>
	 */
	public static function token_rates() {
		$rates = array(
			'gapgpt' => array(
				// Text models (verified 2026-10-12).
				'gapgpt-gemma-26b-a4b'         => array( 0.15, 0.60 ),
				'gapgpt-qwen-3.6'              => array( 0.25, 2.00 ),
				'gapgpt-qwen-3.6-thinking'     => array( 0.25, 2.00 ),
				'gapgpt-agent'                 => array( 0.10, 0.50 ),
				'gpt-6.1-sol'                  => array( 2.00, 10.00 ),
				'gpt-6-luna'                   => array( 0.10, 0.50 ),
				'gpt-6-sol'                    => array( 2.00, 10.00 ),
				'gpt-5.6-sol'                  => array( 2.50, 15.00 ),
				'gpt-5.6-luna'                 => array( 0.20, 1.20 ),
				'gpt-5.6-terra'                => array( 2.00, 12.00 ),
				'gpt-5.5'                      => array( 5.00, 30.00 ),
				'gpt-5.4'                      => array( 2.50, 15.00 ),
				'gpt-5.2-pro'                  => array( 21.00, 126.00 ),
				'gpt-5.3-codex-spark'          => array( 1.75, 14.00 ),
				'typesafe/jev-1.13'            => array( 0.042, 0.00 ),
				'claude-fable-5'               => array( 10.00, 50.00 ),
				'claude-fable-5-1'             => array( 10.00, 50.00 ),
				'claude-sonnet-5'              => array( 2.00, 10.00 ),
				'claude-sonnet-5-5'            => array( 2.00, 10.00 ),
				'claude-opus-5'                => array( 5.00, 25.00 ),

				// Image models billed per token by the provider.
				'gpt-image-2'                  => array( 8.00, 30.00 ),
				'gpt-image-1'                  => array( 10.00, 40.00 ),
				'gpt-image-1-mini'             => array( 2.00, 4.00 ),
				'gemini-3-pro-image-preview'   => array( 2.00, 120.00 ),

				// Speech to text (token-billed listing).
				'gapgpt/whisper-1'             => array( 24.00, 24.00 ),
				'whisper-1'                    => array( 30.00, 30.00 ),

				// Text to speech.
				'gpt-4o-mini-tts'              => array( 0.60, 12.00 ),
				'tts-1'                        => array( 15.00, 15.00 ),
				'gemini-2.5-pro-preview-tts'   => array( 2.00, 40.00 ),
				'gemini-2.5-flash-preview-tts' => array( 0.50, 10.00 ),

				// Embeddings.
				'text-embedding-3-large'       => array( 0.13, 0.13 ),
				'text-embedding-3-small'       => array( 0.020, 0.020 ),
				'text-embedding-ada-002'       => array( 0.10, 0.10 ),
				'text-embedding-v1'            => array( 0.10, 0.10 ),
				'gemini-embedding-001'         => array( 0.15, 0.15 ),
			),
		);

		/**
		 * Filters the AI price catalogue.
		 *
		 * Site owners on first-party providers (api.openai.com, Anthropic direct,
		 * Google AI Studio) can add their own verified rates here, or via the
		 * "custom rates" settings field, without patching the plugin.
		 *
		 * @param array<string, array<string, array{0: float, 1: float}>> $rates Rates per 1M tokens.
		 */
		return (array) apply_filters( 'avandwp_price_catalog', $rates );
	}

	/**
	 * Flat per-image prices, where the provider publishes one.
	 *
	 * `per_megapixel` entries are converted using the requested dimensions, since
	 * the provider bills by output resolution.
	 *
	 * @return array<string, array<string, array<string, float|string>>>
	 */
	public static function image_rates() {
		$rates = array(
			'gapgpt' => array(
				'gapgpt/qwen-image-2.1'  => array( 'per_megapixel' => 0.005 ),
				'flux-1-schnell'         => array( 'per_image' => 0.0014 ),
				'gemini-2.5-flash-image' => array( 'per_image' => 0.040 ),
			),
		);

		/**
		 * Filters the per-image price catalogue.
		 *
		 * @param array<string, array<string, array<string, float|string>>> $rates Image rates.
		 */
		return (array) apply_filters( 'avandwp_image_price_catalog', $rates );
	}

	/**
	 * Human-readable list of models advertised for a given capability.
	 *
	 * The UI populates its model dropdown from this list so that it can only
	 * ever offer identifiers the provider actually publishes. This is what stops
	 * unverified ids such as `dall-e-3` from reaching GapGPT.
	 *
	 * @param string $capability One of: text, image, speech_to_text, text_to_speech, embedding.
	 * @param string $provider   Provider slug.
	 * @return array<int, array{id: string, label: string, verified: string}>
	 */
	public static function models_for( $capability, $provider = 'gapgpt' ) {
		$provider = sanitize_key( $provider );

		$catalog = array(
			'gapgpt' => array(
				'text' => array(
					array( 'id' => 'gpt-5.6-terra', 'label' => 'GPT‑5.6 Terra — متعادل برای تولید محتوای بلند' ),
					array( 'id' => 'gpt-5.6-luna', 'label' => 'GPT‑5.6 Luna — اقتصادی برای حجم بالا' ),
					array( 'id' => 'gpt-5.6-sol', 'label' => 'GPT‑5.6 Sol — تحلیل عمیق‌تر' ),
					array( 'id' => 'gpt-6-luna', 'label' => 'GPT‑6 Luna — سریع و کم‌هزینه' ),
					array( 'id' => 'gpt-6.1-sol', 'label' => 'GPT‑6.1 Sol — استدلال پیشرفته' ),
					array( 'id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5' ),
					array( 'id' => 'gapgpt-qwen-3.6', 'label' => 'Qwen 3.6 — میزبانی داخلی GapGPT' ),
					array( 'id' => 'gapgpt-gemma-26b-a4b', 'label' => 'Gemma 26B A4B — میزبانی داخلی GapGPT' ),
					array( 'id' => 'gapgpt-agent', 'label' => 'GapGPT Agent — ارزان‌ترین گزینه' ),
				),
				'image' => array(
					array( 'id' => 'gpt-image-2', 'label' => 'GPT Image 2 — بالاترین کیفیت' ),
					array( 'id' => 'gpt-image-1-mini', 'label' => 'GPT Image 1 Mini — اقتصادی' ),
					array( 'id' => 'flux-1-schnell', 'label' => 'Flux 1 Schnell — سریع‌ترین و ارزان‌ترین' ),
					array( 'id' => 'gapgpt/qwen-image-2.1', 'label' => 'Qwen Image 2.1 — ارزان، میزبانی داخلی' ),
					array( 'id' => 'gemini-2.5-flash-image', 'label' => 'Gemini 2.5 Flash Image' ),
				),
				'speech_to_text' => array(
					array( 'id' => 'gapgpt/whisper-1', 'label' => 'GapGPT Whisper (ارزان‌تر)' ),
					array( 'id' => 'whisper-1', 'label' => 'Whisper 1' ),
				),
				'text_to_speech' => array(
					array( 'id' => 'gpt-4o-mini-tts', 'label' => 'GPT‑4o mini TTS' ),
					array( 'id' => 'gemini-2.5-flash-preview-tts', 'label' => 'Gemini 2.5 Flash TTS' ),
					array( 'id' => 'tts-1', 'label' => 'TTS‑1' ),
				),
				'embedding' => array(
					array( 'id' => 'text-embedding-3-small', 'label' => 'text-embedding-3-small' ),
					array( 'id' => 'text-embedding-3-large', 'label' => 'text-embedding-3-large' ),
					array( 'id' => 'gemini-embedding-001', 'label' => 'gemini-embedding-001' ),
				),
			),
		);

		$list = $catalog[ $provider ][ $capability ] ?? array();

		/**
		 * Filters the advertised model list for a capability.
		 *
		 * @param array<int, array<string, string>> $list       Models.
		 * @param string                            $capability Capability slug.
		 * @param string                            $provider   Provider slug.
		 */
		$list = (array) apply_filters( 'avandwp_models_for_capability', $list, $capability, $provider );

		foreach ( $list as &$entry ) {
			$entry['verified'] = self::VERIFIED_ON;
		}
		unset( $entry );

		return $list;
	}

	/**
	 * Is this a model identifier the provider publishes?
	 *
	 * @param string $capability Capability slug.
	 * @param string $model      Model id.
	 * @param string $provider   Provider slug.
	 * @return bool
	 */
	public static function is_known_model( $capability, $model, $provider = 'gapgpt' ) {
		$model = (string) $model;
		foreach ( self::models_for( $capability, $provider ) as $entry ) {
			if ( $entry['id'] === $model ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable price for one model, for use in a picker label.
	 *
	 * Returns an explicit "not published" string rather than an empty value so a
	 * model with no verified rate is visibly unverified instead of silently
	 * looking free.
	 *
	 * @param string $model    Model id.
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function describe_text_model( $model, $provider = 'gapgpt' ) {
		$rates = self::token_rates();

		if ( ! isset( $rates[ sanitize_key( $provider ) ][ (string) $model ] ) ) {
			return 'نرخ این مدل در کاتالوگ تأییدشده ثبت نشده است';
		}

		$rate = $rates[ sanitize_key( $provider ) ][ (string) $model ];

		return sprintf(
			'ورودی %s٪ دلار / خروجی %s دلار به ازای هر ۱ میلیون توکن (تأییدشده %s)',
			self::trim_number( (float) $rate[0] ),
			self::trim_number( (float) $rate[1] ),
			self::VERIFIED_ON
		);
	}

	/**
	 * Cost note for an image model, for the settings screen.
	 *
	 * Two billing shapes exist in the catalogue: a flat per-image (or
	 * per-megapixel) rate, and models the provider bills per output token. The
	 * second shape has no fixed per-image price, so the note says exactly that
	 * instead of inventing a number.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model id.
	 * @return string
	 */
	public static function image_cost_note( $provider, $model ) {
		$provider = sanitize_key( $provider );
		$model    = (string) $model;
		$estimate = self::estimate_image( $provider, $model );

		if ( null !== $estimate['cost'] ) {
			return $estimate['note'];
		}

		$rates = self::token_rates();

		if ( isset( $rates[ $provider ][ $model ] ) ) {
			$rate = $rates[ $provider ][ $model ];

			return sprintf(
				'این مدل بر پایه توکن محاسبه می‌شود (%s٪ دلار ورودی و %s دلار خروجی به ازای هر ۱ میلیون توکن، تأییدشده %s). هزینه هر تصویر به ابعاد و توکن مصرفی همان درخواست بستگی دارد؛ عدد ثابتی برای هر تصویر اعلام نشده است.',
				self::trim_number( (float) $rate[0] ),
				self::trim_number( (float) $rate[1] ),
				self::VERIFIED_ON
			);
		}

		return $estimate['note'];
	}

	/**
	 * Format a rate without trailing zeros (`2.00` → `2`).
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function trim_number( $value ) {
		$formatted = rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Default model for a capability (the first catalogue entry).
	 *
	 * @param string $capability Capability slug.
	 * @param string $provider   Provider slug.
	 * @return string Model id, or empty string when the provider publishes none.
	 */
	public static function default_model( $capability, $provider = 'gapgpt' ) {
		$models = self::models_for( $capability, $provider );
		return $models ? (string) $models[0]['id'] : '';
	}

	/**
	 * Estimate the USD cost of a token-billed operation.
	 *
	 * @param string $provider   Provider slug.
	 * @param string $model      Model id.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @return array{cost: float|null, source: string, note: string}
	 *               `source` is `estimated` when a rate was found and applied, or
	 *               `unknown` when it was not. Never returns a guessed number.
	 */
	public static function estimate_tokens( $provider, $model, $tokens_in, $tokens_out ) {
		$provider = sanitize_key( $provider );
		$model    = (string) $model;
		$rates    = self::token_rates();

		if ( ! isset( $rates[ $provider ][ $model ] ) ) {
			return array(
				'cost'   => null,
				'source' => 'unknown',
				'note'   => sprintf(
					'نرخ این مدل در فهرست قیمت تأییدشده (%s) موجود نیست؛ هزینه محاسبه نشد.',
					self::VERIFIED_ON
				),
			);
		}

		list( $in_rate, $out_rate ) = $rates[ $provider ][ $model ];

		$cost = ( ( max( 0, (int) $tokens_in ) / 1000000 ) * (float) $in_rate )
			+ ( ( max( 0, (int) $tokens_out ) / 1000000 ) * (float) $out_rate );

		return array(
			'cost'   => round( $cost, 6 ),
			'source' => 'estimated',
			'note'   => sprintf(
				'برآورد بر پایه نرخ منتشرشده %s در تاریخ %s (تخمین، نه صورتحساب واقعی).',
				'gapgpt' === $provider ? 'GapGPT' : $provider,
				self::VERIFIED_ON
			),
		);
	}

	/**
	 * Estimate the USD cost of one generated image.
	 *
	 * @param string $provider  Provider slug.
	 * @param string $model     Model id.
	 * @param string $size      Requested size, e.g. `1024x1024`.
	 * @return array{cost: float|null, source: string, note: string}
	 */
	public static function estimate_image( $provider, $model, $size = '1024x1024' ) {
		$provider = sanitize_key( $provider );
		$model    = (string) $model;
		$rates    = self::image_rates();

		if ( ! isset( $rates[ $provider ][ $model ] ) ) {
			return array(
				'cost'   => null,
				'source' => 'unknown',
				'note'   => sprintf(
					'قیمت هر تصویر این مدل منتشر نشده است؛ هزینه محاسبه نشد (تأییدشده در %s).',
					self::VERIFIED_ON
				),
			);
		}

		$entry = $rates[ $provider ][ $model ];

		if ( isset( $entry['per_image'] ) ) {
			return array(
				'cost'   => round( (float) $entry['per_image'], 6 ),
				'source' => 'estimated',
				'note'   => sprintf( 'نرخ ثابت هر تصویر طبق فهرست رسمی (%s).', self::VERIFIED_ON ),
			);
		}

		if ( isset( $entry['per_megapixel'] ) ) {
			$megapixels = self::megapixels( $size );
			if ( null === $megapixels ) {
				return array(
					'cost'   => null,
					'source' => 'unknown',
					'note'   => 'برای محاسبه هزینه بر مبنای مگاپیکسل، ابعاد تصویر لازم است.',
				);
			}
			return array(
				'cost'   => round( $megapixels * (float) $entry['per_megapixel'], 6 ),
				'source' => 'estimated',
				'note'   => sprintf(
					'محاسبه بر پایه %.2f مگاپیکسل و نرخ %.4f دلار هر مگاپیکسل (%s).',
					$megapixels,
					(float) $entry['per_megapixel'],
					self::VERIFIED_ON
				),
			);
		}

		return array(
			'cost'   => null,
			'source' => 'unknown',
			'note'   => 'نرخ این مدل قابل محاسبه نیست.',
		);
	}

	/**
	 * Convert a `WxH` size string into megapixels.
	 *
	 * @param string $size Size string.
	 * @return float|null Megapixels, or null when the string is unparseable.
	 */
	private static function megapixels( $size ) {
		if ( ! preg_match( '/^(\d{2,5})\s*[x×]\s*(\d{2,5})$/', trim( (string) $size ), $m ) ) {
			return null;
		}
		return ( ( (int) $m[1] ) * ( (int) $m[2] ) ) / 1000000;
	}
}
