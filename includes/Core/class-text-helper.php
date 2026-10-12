<?php
/**
 * Persian-aware UTF-8 text, HTML sanitization, and token estimation helper.
 *
 * Replaces PHP's ASCII-only str_word_count() which returns 0 on Persian text.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Text_Helper {

	/**
	 * Multibyte-safe substring with a byte-oriented fallback.
	 *
	 * WordPress does not require the `mbstring` extension. The 1.0.0 code
	 * guarded `mb_substr()` in most places but not in the image client, where a
	 * missing extension produced a fatal `Error` mid-import.
	 *
	 * @param string $text   Input text.
	 * @param int    $start  Start offset.
	 * @param int|null $length Maximum characters, or null for "to the end".
	 * @return string
	 */
	public static function safe_substr( $text, $start, $length = null ) {
		$text  = (string) $text;
		$start = (int) $start;

		if ( function_exists( 'mb_substr' ) ) {
			return null === $length
				? mb_substr( $text, $start, null, 'UTF-8' )
				: mb_substr( $text, $start, (int) $length, 'UTF-8' );
		}

		// Without mbstring, degrade at a character boundary so Persian text is
		// never split mid-codepoint.
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null === $length ? substr( $text, $start ) : substr( $text, $start, (int) $length );
		}

		$slice = null === $length
			? array_slice( $chars, $start )
			: array_slice( $chars, $start, (int) $length );

		return implode( '', $slice );
	}

	/**
	 * Multibyte-safe length with a byte-oriented fallback.
	 *
	 * @param string $text Input text.
	 * @return int Character count.
	 */
	public static function length( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return 0;
		}

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		// `mbstring` is NOT a WordPress required extension. Falling back to
		// strlen() would return a *byte* count, which is roughly double the
		// character count for Persian text and would make every character-mode
		// target wrong by a factor of two. Count UTF-8 code points instead.
		$count = preg_match_all( '/./us', $text );

		return false === $count ? strlen( $text ) : (int) $count;
	}

	/**
	 * Reduce HTML to the plain text a reader actually sees.
	 *
	 * Blocks become separated by a single space so that adjacent elements do not
	 * merge into one token ("پایان" + "شروع" must not become "پایانشروع"), and
	 * HTML entities are decoded so `&nbsp;` counts as the single character it
	 * renders as rather than as five source characters.
	 *
	 * @param string $text Raw or HTML text.
	 * @return string Plain text.
	 */
	public static function to_plain_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// Close implicit block boundaries before stripping tags.
		$spaced = preg_replace( '#<(/?)(p|div|br|li|ul|ol|h[1-6]|table|tr|td|th|section|article|blockquote|figure|figcaption|pre)\b[^>]*>#i', ' $0 ', $text );
		$plain  = wp_strip_all_tags( (string) $spaced );
		$plain  = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain  = str_replace( array( "\xC2\xA0", "\xE2\x80\x89", "\xE2\x80\x8A" ), ' ', $plain ); // NBSP, thin space, hair space.
		$plain  = preg_replace( '/[ \t]+/u', ' ', (string) $plain );
		$plain  = preg_replace( '/\s*\n\s*/u', "\n", (string) $plain );

		return trim( (string) $plain );
	}

	/**
	 * Count words accurately across Persian, Arabic, and Latin scripts.
	 *
	 * Rule (documented for the UI, see docs/seo-scoring-model.md):
	 *   1. HTML tags are removed and entities decoded.
	 *   2. The zero-width non-joiner (نیم‌فاصله) is removed so that a compound
	 *      such as «می‌خواهم» counts as ONE word, matching Persian editorial
	 *      convention.
	 *   3. Punctuation and symbols are treated as separators.
	 *   4. A "word" is any run of letters (`\p{L}`) or digits (`\p{N}`) — which
	 *      covers Persian, Arabic, Latin and Cyrillic in a single rule — of one
	 *      or more characters.
	 *
	 * @param string $text Raw or HTML text.
	 * @return int Number of words.
	 */
	public static function word_count( $text ) {
		$plain = self::to_plain_text( $text );

		if ( '' === $plain ) {
			return 0;
		}

		$plain = str_replace( "\xE2\x80\x8C", '', $plain );            // ZWNJ → concatenate.
		$plain = str_replace( array( "\xE2\x80\x8B", "\xE2\x80\x8D" ), '', $plain ); // ZWSP, ZWJ.
		$plain = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', (string) $plain );
		$plain = trim( preg_replace( '/\s+/u', ' ', (string) $plain ) );

		if ( '' === $plain ) {
			return 0;
		}

		$words = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Count characters under an explicit, documented rule.
	 *
	 * The baseline plugin had no character counting at all. Because "character
	 * count" is ambiguous, the rule is fixed here and stated in the UI:
	 *
	 *   - The count is taken from the **rendered plain text**, so HTML tags,
	 *     attributes and entity markup are excluded.
	 *   - `$include_spaces = true` (the default) counts every character of the
	 *     rendered text, including the spaces *between* words, matching how meta
	 *     descriptions are measured.
	 *   - `$include_spaces = false` removes whitespace entirely first.
	 *   - The zero-width non-joiner IS counted: it is a real Unicode character in
	 *     Persian orthography. Its own count is reported separately in
	 *     `measure()` so the number can be explained rather than argued about.
	 *
	 * @param string $text           Raw or HTML text.
	 * @param bool   $include_spaces Whether spaces count towards the total.
	 * @return int Character count.
	 */
	public static function char_count( $text, $include_spaces = true ) {
		$plain = self::to_plain_text( $text );

		if ( '' === $plain ) {
			return 0;
		}

		if ( ! $include_spaces ) {
			$plain = preg_replace( '/\s+/u', '', $plain );
			return self::length( (string) $plain );
		}

		return self::length( $plain );
	}

	/**
	 * Count sentence-like units.
	 *
	 * Persian uses `؟`, `!` and `.` as terminal punctuation, and the Arabic
	 * full stop `۔`/`؛` appear in some copy. Newlines also terminate a unit.
	 *
	 * @param string $text Raw or HTML text.
	 * @return int
	 */
	public static function sentence_count( $text ) {
		$plain = self::to_plain_text( $text );
		if ( '' === $plain ) {
			return 0;
		}
		$parts = preg_split( '/[.!?؟؛۔…]+|\n+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $parts as $part ) {
			if ( self::word_count( $part ) >= 2 ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Produce a complete, honest measurement of a piece of content.
	 *
	 * Every value returned here is counted from the supplied HTML. Nothing is
	 * derived from the *requested* length.
	 *
	 * @param string $html Raw or HTML content.
	 * @return array<string, int|float>
	 */
	public static function measure( $html ) {
		$plain = self::to_plain_text( $html );
		$words = self::word_count( $html );

		return array(
			'words'                => $words,
			'chars_with_spaces'    => self::char_count( $html, true ),
			'chars_without_spaces' => self::char_count( $html, false ),
			'zwnj'                 => substr_count( $plain, "\xE2\x80\x8C" ),
			'sentences'            => self::sentence_count( $html ),
			'paragraphs'           => preg_match_all( '/<p[\s>]/i', (string) $html ),
			'headings_h2'          => preg_match_all( '/<h2[\s>]/i', (string) $html ),
			'headings_h3'          => preg_match_all( '/<h3[\s>]/i', (string) $html ),
			'headings_other'       => preg_match_all( '/<h[1456][\s>]/i', (string) $html ),
			'lists'                => preg_match_all( '/<(ul|ol)[\s>]/i', (string) $html ),
			'tables'               => preg_match_all( '/<table[\s>]/i', (string) $html ),
			'links_total'          => preg_match_all( '/<a\s[^>]*href=/i', (string) $html ),
			'images_total'         => preg_match_all( '/<img\s/i', (string) $html ),
			// Estimated reading time at 200 Persian words per minute; labelled as
			// an estimate everywhere it is displayed.
			'reading_minutes'      => $words > 0 ? round( $words / 200, 1 ) : 0.0,
		);
	}

	/**
	 * Compare an actual measurement against the requested target.
	 *
	 * @param array  $measured Measurement from `measure()`.
	 * @param string $mode     `words` or `characters`.
	 * @param int    $target   Requested target count.
	 * @param bool   $include_spaces Whether characters include spaces.
	 * @return array{achieved: int, target: int, delta: int, percent: float, complete: bool, message: string}
	 */
	public static function assess_length( array $measured, $mode, $target, $include_spaces = true ) {
		$mode   = ( 'characters' === $mode ) ? 'characters' : 'words';
		$target = max( 0, (int) $target );

		$achieved = ( 'characters' === $mode )
			? ( $include_spaces ? (int) $measured['chars_with_spaces'] : (int) $measured['chars_without_spaces'] )
			: (int) $measured['words'];

		$delta   = $achieved - $target;
		$percent = $target > 0 ? round( ( $achieved / $target ) * 100, 1 ) : 0.0;

		// Within 5 % of target counts as complete; below that is a shortfall.
		$complete = ( $target > 0 ) && ( $achieved >= ( $target * 0.95 ) );

		if ( $complete ) {
			$message = sprintf(
				'طول تولید‌شده %s است: %s از %s %s درخواستی (%.0f٪ هدف).',
				'کامل',
				number_format_i18n( $achieved ),
				number_format_i18n( $target ),
				'characters' === $mode ? 'کاراکتر' : 'کلمه',
				$percent
			);
		} elseif ( $delta < 0 ) {
			$message = sprintf(
				'کمبود طول: %s %s کمتر از هدف %s تایی اندازه‌گیری شد (%.0f٪ هدف). بخش‌های تکمیلی برای ادامه تولید لازم است.',
				number_format_i18n( abs( $delta ) ),
				'characters' === $mode ? 'کاراکتر' : 'کلمه',
				number_format_i18n( $target ),
				$percent
			);
		} else {
			$message = sprintf(
				'طول تولید‌شده %s %s بیشتر از هدف است.',
				number_format_i18n( $delta ),
				'characters' === $mode ? 'کاراکتر' : 'کلمه'
			);
		}

		return array(
			'achieved' => $achieved,
			'target'   => $target,
			'delta'    => $delta,
			'percent'  => $percent,
			'complete' => $complete,
			'message'  => $message,
		);
	}

	/**
	 * Normalize Persian and Arabic characters for consistent search & tokenization.
	 *
	 * @param string $text Input text.
	 * @return string Normalized lowercase UTF-8 string.
	 */
	public static function normalize_persian( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		$text = wp_strip_all_tags( $text );
		$map  = array(
			'ي' => 'ی',
			'ى' => 'ی',
			'ئ' => 'ی',
			'ك' => 'ک',
			'ة' => 'ه',
			'ۀ' => 'ه',
			'أ' => 'ا',
			'إ' => 'ا',
			'آ' => 'ا',
			'ؤ' => 'و',
			"\xE2\x80\x8C" => ' ', // ZWNJ to space for token matching
		);
		$text = strtr( $text, $map );
		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		return $text;
	}

	/**
	 * Extract meaningful search tokens (excluding common Persian/English stop words).
	 *
	 * @param string $text Input text.
	 * @return array<int, string> Unique tokens of length >= 2.
	 */
	public static function tokenize( $text ) {
		$norm = self::normalize_persian( $text );
		if ( '' === $norm ) {
			return array();
		}
		$parts = preg_split( '/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$stop_words = array(
			'در', 'به', 'از', 'که', 'می', 'این', 'است', 'را', 'با', 'های',
			'برای', 'آن', 'یک', 'شود', 'شده', 'خود', 'ها', 'کرد', 'بر', 'هم',
			'نیز', 'گفت', 'ما', 'شما', 'او', 'آنها', 'یا', 'اما', 'اگر', 'تا',
			'کند', 'کنند', 'بود', 'باشند', 'باشد', 'دارد', 'دارند', 'چگونه', 'چطور',
			'چیست', 'کدام', 'آیا', 'the', 'and', 'for', 'with', 'that', 'this', 'from',
		);
		$stop_lookup = array_flip( $stop_words );

		$tokens = array();
		foreach ( $parts as $word ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
			if ( $len < 2 || isset( $stop_lookup[ $word ] ) ) {
				continue;
			}
			$tokens[] = $word;
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Clean AI output (strip markdown code fences, convert basic markdown if needed, and sanitize HTML).
	 *
	 * @param string $raw Raw AI response.
	 * @return string Safe HTML suitable for WordPress post_content.
	 */
	public static function clean_ai_html( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}
		$text = trim( $raw );
		// Remove surrounding ```html ... ``` or ``` ... ``` fences.
		$text = preg_replace( '/^```(?:html|xml|markdown)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );

		// Convert stray markdown headings or bold if the model mixed markdown with HTML.
		$text = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', (string) $text );
		$text = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', (string) $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string) $text );

		return wp_kses_post( trim( (string) $text ) );
	}

	/**
	 * Parse JSON from AI output safely (tolerating markdown ```json fences).
	 *
	 * @param string $raw Raw AI response.
	 * @return array|null Decoded associative array or null on failure.
	 */
	public static function parse_json_response( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$clean = trim( $raw );
		$clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
		$clean = preg_replace( '/\s*```$/', '', (string) $clean );
		$decoded = json_decode( trim( (string) $clean ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Attempt to extract the first JSON object {...} or array [...] block.
		if ( preg_match( '/(\{[\s\S]*\}|\[[\s\S]*\])/', $clean, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Estimate token count for UTF-8 strings when provider does not return usage.
	 *
	 * @param string $text Input text.
	 * @return int Estimated tokens.
	 */
	public static function estimate_tokens( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return 0;
		}
		$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		return max( 1, (int) ceil( $chars / 3.2 ) );
	}
}
