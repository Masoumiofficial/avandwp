<?php
/**
 * Article brief: validated, normalised input for the Article Studio.
 *
 * The 1.0.0 Studio accepted four fields (topic, keyword, tone, length). This
 * class defines the full brief described by the product requirements and, more
 * importantly, *validates* it: a brief that cannot produce a useful article is
 * rejected with a specific Persian message before any paid API call is made.
 *
 * Length handling is centralised here because it was the source of the
 * baseline's most serious defect (a hard 3,000-word ceiling and a hardcoded
 * 3,500-token budget, which together made the required 4,500-word default
 * unreachable and silently truncated anything that got close).
 *
 * @package AvandWP\Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brief builder and validator.
 */
class AvandWP_Article_Brief {

	/**
	 * Product default article length, in the requested unit.
	 */
	const DEFAULT_LENGTH = 4500;

	/**
	 * Absolute bounds. These are generous; they exist to stop absurd input from
	 * generating an unbounded number of paid requests, not to dictate style.
	 */
	const MIN_LENGTH = 200;
	const MAX_LENGTH = 30000;

	/**
	 * Accepted search intents.
	 *
	 * @return array<string, string>
	 */
	public static function intents() {
		return array(
			'informational' => 'آموزشی / اطلاعاتی',
			'commercial'    => 'بررسی و مقایسه پیش از خرید',
			'transactional' => 'اقدام و خرید',
			'navigational'  => 'یافتن یک منبع یا برند مشخص',
			'local'         => 'منطقه‌محور',
		);
	}

	/**
	 * Accepted article types.
	 *
	 * @return array<string, string>
	 */
	public static function article_types() {
		return array(
			'guide'       => 'راهنمای جامع',
			'howto'       => 'آموزش گام‌به‌گام',
			'comparison'  => 'مقایسه گزینه‌ها',
			'listicle'    => 'فهرست‌محور',
			'review'      => 'بررسی و نقد',
			'news'        => 'خبر و تحلیل',
			'faq'         => 'پرسش و پاسخ',
			'product'     => 'معرفی محصول یا خدمت',
		);
	}

	/**
	 * Build a validated brief from raw request input.
	 *
	 * @param array $raw Raw key/value input (already unslashed by the caller).
	 * @return array|WP_Error Normalised brief, or the first validation failure.
	 */
	public static function from_array( array $raw ) {
		$topic = trim( (string) ( $raw['topic'] ?? '' ) );

		if ( '' === $topic ) {
			return new WP_Error( 'avandwp_brief_topic', 'موضوع مقاله نمی‌تواند خالی باشد.' );
		}

		if ( AvandWP_Text_Helper::length( $topic ) > 300 ) {
			return new WP_Error( 'avandwp_brief_topic_long', 'موضوع مقاله بیش از حد بلند است؛ آن را به یک عبارت کوتاه محدود کنید.' );
		}

		$length = self::normalise_length( $raw );

		if ( is_wp_error( $length ) ) {
			return $length;
		}

		$intent = sanitize_key( $raw['search_intent'] ?? 'informational' );
		if ( ! array_key_exists( $intent, self::intents() ) ) {
			$intent = 'informational';
		}

		$type = sanitize_key( $raw['article_type'] ?? 'guide' );
		if ( ! array_key_exists( $type, self::article_types() ) ) {
			$type = 'guide';
		}

		$brief = array(
			// --- identity -------------------------------------------------
			'topic'                     => $topic,
			'working_title'             => trim( (string) ( $raw['working_title'] ?? '' ) ),
			'focus_keyword'             => trim( (string) ( $raw['focus_keyword'] ?? $topic ) ),
			'search_intent'             => $intent,
			'article_type'              => $type,
			'purpose'                   => trim( (string) ( $raw['purpose'] ?? '' ) ),

			// --- audience & voice ----------------------------------------
			'audience'                  => trim( (string) ( $raw['audience'] ?? '' ) ),
			'tone'                      => trim( (string) ( $raw['tone'] ?? 'حرفه‌ای، کاربردی و روان' ) ),
			'writing_style'             => trim( (string) ( $raw['writing_style'] ?? '' ) ),
			'brand_voice'               => trim( (string) ( $raw['brand_voice'] ?? '' ) ),
			'language'                  => sanitize_key( $raw['language'] ?? 'fa' ) ?: 'fa',
			'locale'                    => sanitize_text_field( (string) ( $raw['locale'] ?? 'fa_IR' ) ),

			// --- length ---------------------------------------------------
			'length_mode'               => $length['mode'],
			'length_target'             => $length['target'],
			'length_min'                => $length['min'],
			'length_max'                => $length['max'],
			'length_include_spaces'     => $length['include_spaces'],
			'continue_until_target'     => $length['continue_until_target'],

			// --- structure & content -------------------------------------
			'outline'                   => self::string_list( $raw['outline'] ?? array() ),
			'required_sections'         => self::string_list( $raw['required_sections'] ?? array() ),
			'secondary_keywords'        => self::string_list( $raw['secondary_keywords'] ?? array() ),
			'entities'                  => self::string_list( $raw['entities'] ?? array() ),
			'required_facts'            => self::string_list( $raw['required_facts'] ?? array() ),
			'terminology'               => self::string_list( $raw['terminology'] ?? array() ),
			'forbidden_claims'          => self::string_list( $raw['forbidden_claims'] ?? array() ),
			'faqs'                      => self::string_list( $raw['faqs'] ?? array() ),
			'call_to_actions'           => self::string_list( $raw['call_to_actions'] ?? array() ),
			'avoid_topic_ids'           => array_map( 'intval', (array) ( $raw['avoid_topic_ids'] ?? array() ) ),
			'reference_urls'            => self::url_list( $raw['reference_urls'] ?? array() ),

			// --- author & links ------------------------------------------
			'author_name'               => trim( (string) ( $raw['author_name'] ?? '' ) ),
			'author_experience'         => trim( (string) ( $raw['author_experience'] ?? '' ) ),
			'internal_link_instructions' => trim( (string) ( $raw['internal_link_instructions'] ?? '' ) ),
			'external_source_requirements' => trim( (string) ( $raw['external_source_requirements'] ?? '' ) ),
			'max_internal_links'        => max( 0, min( 30, (int) ( $raw['max_internal_links'] ?? 6 ) ) ),
			'max_external_links'        => max( 0, min( 30, (int) ( $raw['max_external_links'] ?? 6 ) ) ),

			// --- media & destination -------------------------------------
			'featured_image'            => self::image_request( $raw['featured_image'] ?? array() ),
			'inline_images'             => self::image_request( $raw['inline_images'] ?? array() ),
			'post_status'               => in_array( (string) ( $raw['post_status'] ?? 'draft' ), array( 'draft', 'pending', 'publish' ), true )
				? (string) ( $raw['post_status'] ?? 'draft' )
				: 'draft',
			'category_id'               => (int) ( $raw['category_id'] ?? 0 ),
			'use_knowledge'             => ! empty( $raw['use_knowledge'] ),
		);

		// A publish request must be an explicit, separate decision.
		if ( 'publish' === $brief['post_status'] && empty( $raw['confirm_publish'] ) ) {
			return new WP_Error(
				'avandwp_brief_publish_unconfirmed',
				'انتشار مستقیم نیازمند تأیید صریح است. برای بازبینی، وضعیت «پیش‌نویس» را انتخاب کنید.'
			);
		}

		return $brief;
	}

	/**
	 * Normalise and validate the length configuration.
	 *
	 * @param array $raw Raw input.
	 * @return array|WP_Error
	 */
	private static function normalise_length( array $raw ) {
		$mode = ( 'characters' === ( $raw['length_mode'] ?? 'words' ) ) ? 'characters' : 'words';

		$target = isset( $raw['length_target'] )
			? (int) $raw['length_target']
			: self::DEFAULT_LENGTH;

		// The product default is 4,500; an explicit 0 or blank falls back to it
		// rather than silently shrinking the article.
		if ( $target <= 0 ) {
			$target = self::DEFAULT_LENGTH;
		}

		if ( $target < self::MIN_LENGTH ) {
			return new WP_Error(
				'avandwp_brief_length_small',
				sprintf( 'حداقل طول قابل پشتیبانی %s %s است.', number_format_i18n( self::MIN_LENGTH ), 'characters' === $mode ? 'کاراکتر' : 'کلمه' )
			);
		}

		if ( $target > self::MAX_LENGTH ) {
			return new WP_Error(
				'avandwp_brief_length_large',
				sprintf( 'حداکثر طول قابل پشتیبانی %s %s است.', number_format_i18n( self::MAX_LENGTH ), 'characters' === $mode ? 'کاراکتر' : 'کلمه' )
			);
		}

		$min = isset( $raw['length_min'] ) ? (int) $raw['length_min'] : (int) floor( $target * 0.9 );
		$max = isset( $raw['length_max'] ) ? (int) $raw['length_max'] : (int) ceil( $target * 1.15 );

		$min = max( self::MIN_LENGTH, min( $target, $min ) );
		$max = max( $target, min( self::MAX_LENGTH, $max ) );

		// "Continue until the target is reached" is what makes a 4,500-word
		// article achievable across multiple bounded requests.
		$continue = ! empty( $raw['continue_until_target'] );

		return array(
			'mode'                   => $mode,
			'target'                 => $target,
			'min'                    => $min,
			'max'                    => $max,
			'include_spaces'         => ! isset( $raw['length_include_spaces'] ) || ! empty( $raw['length_include_spaces'] ),
			'continue_until_target'  => $continue,
		);
	}

	/**
	 * Normalise a list of short strings.
	 *
	 * @param mixed $value Raw value (array or newline/comma separated string).
	 * @return array<int, string>
	 */
	public static function string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,،]+/u', $value ) ?: array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				continue;
			}
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = AvandWP_Text_Helper::safe_substr( $item, 0, 400 );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalise a list of URLs, keeping only safe http(s) destinations.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public static function url_list( $value ) {
		$urls = self::string_list( $value );
		$out  = array();

		foreach ( $urls as $url ) {
			$safe = AvandWP_Outbound_Guard::validate_url( $url );
			if ( ! is_wp_error( $safe ) ) {
				$out[] = esc_url_raw( $url );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalise an image-generation request block.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	private static function image_request( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$sizes = array( '1024x1024', '1792x1024', '1024x1792', '1536x1024', '1024x1536' );
		$size  = (string) ( $raw['size'] ?? '1792x1024' );
		if ( ! in_array( $size, $sizes, true ) ) {
			$size = '1792x1024';
		}

		return array(
			'enabled'     => ! empty( $raw['enabled'] ),
			'count'       => max( 0, min( 12, (int) ( $raw['count'] ?? 0 ) ) ),
			'size'        => $size,
			'style'       => AvandWP_Text_Helper::safe_substr( trim( (string) ( $raw['style'] ?? '' ) ), 0, 300 ),
			'negative'    => AvandWP_Text_Helper::safe_substr( trim( (string) ( $raw['negative'] ?? '' ) ), 0, 300 ),
			'brand_colors' => AvandWP_Text_Helper::safe_substr( trim( (string) ( $raw['brand_colors'] ?? '' ) ), 0, 120 ),
			'model'       => sanitize_text_field( (string) ( $raw['model'] ?? '' ) ),
		);
	}

	/**
	 * Render the brief as a prompt block for the planning request.
	 *
	 * Only the fields that materially change the output are included, so the
	 * prompt stays inside a predictable token budget.
	 *
	 * @param array $brief Normalised brief.
	 * @return string
	 */
	public static function to_prompt_block( array $brief ) {
		$lines = array();

		$lines[] = 'موضوع: ' . $brief['topic'];

		if ( '' !== $brief['working_title'] ) {
			$lines[] = 'عنوان پیشنهادی: ' . $brief['working_title'];
		}

		if ( '' !== $brief['focus_keyword'] ) {
			$lines[] = 'کلمه کلیدی کانونی: ' . $brief['focus_keyword'];
		}

		$lines[] = 'قصد جستجو: ' . ( self::intents()[ $brief['search_intent'] ] ?? $brief['search_intent'] );
		$lines[] = 'نوع محتوا: ' . ( self::article_types()[ $brief['article_type'] ] ?? $brief['article_type'] );

		if ( '' !== $brief['audience'] ) {
			$lines[] = 'مخاطب هدف: ' . $brief['audience'];
		}

		if ( '' !== $brief['purpose'] ) {
			$lines[] = 'هدف محتوا: ' . $brief['purpose'];
		}

		$lines[] = 'لحن نگارش: ' . $brief['tone'];

		if ( '' !== $brief['writing_style'] ) {
			$lines[] = 'سبک نگارش: ' . $brief['writing_style'];
		}

		if ( '' !== $brief['brand_voice'] ) {
			$lines[] = 'لحن برند: ' . $brief['brand_voice'];
		}

		$lines[] = sprintf(
			'طول هدف: %s %s%s',
			number_format_i18n( $brief['length_target'] ),
			'characters' === $brief['length_mode'] ? 'کاراکتر' : 'کلمه',
			'characters' === $brief['length_mode']
				? ( $brief['length_include_spaces'] ? ' (شامل فاصله‌ها)' : ' (بدون احتساب فاصله‌ها)' )
				: ''
		);

		self::append_list( $lines, 'کلیدواژه‌های فرعی', $brief['secondary_keywords'] );
		self::append_list( $lines, 'نهادها و اصطلاحات معنایی', $brief['entities'] );
		self::append_list( $lines, 'بخش‌های الزامی', $brief['required_sections'] );
		self::append_list( $lines, 'نکات و داده‌های الزامی', $brief['required_facts'] );
		self::append_list( $lines, 'اصطلاحات ثابت (بدون تغییر)', $brief['terminology'] );
		self::append_list( $lines, 'موارد ممنوع', $brief['forbidden_claims'] );
		self::append_list( $lines, 'پرسش‌های متداول مورد انتظار', $brief['faqs'] );
		self::append_list( $lines, 'فراخوان اقدام مورد نظر', $brief['call_to_actions'] );

		if ( '' !== $brief['author_name'] ) {
			$lines[] = 'نویسنده: ' . $brief['author_name'] . ( '' !== $brief['author_experience'] ? ' — ' . $brief['author_experience'] : '' );
		}

		if ( ! empty( $brief['outline'] ) ) {
			$lines[] = 'ساختار مورد نظر کاربر (باید رعایت شود):';
			foreach ( $brief['outline'] as $i => $heading ) {
				$lines[] = sprintf( '  %d. %s', $i + 1, $heading );
			}
		}

		if ( '' !== $brief['internal_link_instructions'] ) {
			$lines[] = 'دستور لینک‌سازی داخلی: ' . $brief['internal_link_instructions'];
		}

		if ( '' !== $brief['external_source_requirements'] ) {
			$lines[] = 'الزامات منابع بیرونی: ' . $brief['external_source_requirements'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Append a labelled list to prompt lines when non-empty.
	 *
	 * @param array<int, string> $lines Buffer.
	 * @param string             $label Label.
	 * @param array<int, string> $items Items.
	 * @return void
	 */
	private static function append_list( array &$lines, $label, array $items ) {
		if ( empty( $items ) ) {
			return;
		}
		$lines[] = $label . ': ' . implode( '، ', array_slice( $items, 0, 20 ) );
	}

	/**
	 * Compute the output-token budget required for a given length.
	 *
	 * Persian text runs roughly 2.5–3.5 tokens per word depending on the model's
	 * tokenizer. The estimate is deliberately generous and then clamped by the
	 * provider client, because under-budgeting is what silently truncates.
	 *
	 * @param int    $target      Target count.
	 * @param string $mode        `words` or `characters`.
	 * @param bool   $include_spaces Whether character mode counts spaces.
	 * @return int Estimated output tokens.
	 */
	public static function estimate_output_tokens( $target, $mode = 'words', $include_spaces = true ) {
		$target = max( 1, (int) $target );

		if ( 'characters' === $mode ) {
			// ~1 token per 1.6 Persian characters.
			$words = (int) ceil( $target / ( $include_spaces ? 6 : 5 ) );
		} else {
			$words = $target;
		}

		return (int) min( AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS, max( 256, (int) ceil( $words * 3.4 ) ) );
	}
}
