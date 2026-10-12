<?php
/**
 * Deterministic content analyzer — the evidence layer of the Quality Lab.
 *
 * Every score in the Quality Lab must be derived from *observed facts about the
 * supplied content*, never from a model's self-assessment and never from the
 * requested parameters. This class produces those facts once, deterministically,
 * so that criteria are cheap to evaluate and testable in isolation.
 *
 * Design rules:
 *   - No AI call. No network access. Pure function of the input HTML + context.
 *   - Every value is counted or measured, not guessed.
 *   - Anything that cannot be established is reported as `null`/absent so the
 *     criteria can mark the check as *unavailable* rather than passing it.
 *
 * @package AvandWP\Quality
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts measurable facts from a piece of content.
 */
class AvandWP_Content_Analyzer {

	/**
	 * Analyse content and its context.
	 *
	 * @param string $html    Article body (rendered HTML as stored in post_content).
	 * @param array  $context Optional context:
	 *                        - title (string), meta_description (string), slug (string),
	 *                        - focus_keyword (string), secondary_keywords (array),
	 *                        - entities (array), search_intent (string),
	 *                        - source_urls (array), author (string), has_schema (bool|null),
	 *                        - site_domain (string), existing_internal_links (array),
	 *                        - published_date (string|null), updated_date (string|null).
	 * @return array<string, mixed> Analysis facts.
	 */
	public static function analyze( $html, array $context = array() ) {
		$html = (string) $html;

		$plain       = AvandWP_Text_Helper::to_plain_text( $html );
		$measurement = AvandWP_Text_Helper::measure( $html );

		$headings = self::headings( $html );
		$links    = self::links( $html, (string) ( $context['site_domain'] ?? '' ) );
		$images   = self::images( $html );
		$blocks   = self::blocks( $html );

		$focus     = trim( (string) ( $context['focus_keyword'] ?? '' ) );
		$secondary = array_filter( array_map( 'trim', (array) ( $context['secondary_keywords'] ?? array() ) ) );
		$entities  = array_filter( array_map( 'trim', (array) ( $context['entities'] ?? array() ) ) );

		return array(
			// --- length -----------------------------------------------------
			'measurement'        => $measurement,
			'plain_text'         => $plain,

			// --- structure --------------------------------------------------
			'headings'           => $headings,
			'heading_hierarchy'  => self::heading_hierarchy( $headings ),
			'blocks'             => $blocks,

			// --- links ------------------------------------------------------
			'links'              => $links,
			'link_stats'         => self::link_stats( $links ),

			// --- media ------------------------------------------------------
			'images'             => $images,
			'image_stats'        => self::image_stats( $images ),

			// --- keywords & entities ---------------------------------------
			'focus_keyword'      => $focus,
			'keyword_stats'      => self::keyword_stats( $html, $plain, $focus, $measurement ),
			'secondary_keywords' => array_values( $secondary ),
			'secondary_coverage' => self::coverage( $plain, $secondary ),
			'entity_coverage'    => self::coverage( $plain, $entities ),

			// --- answer readiness (AIO/GEO evidence) ------------------------
			'questions'          => self::questions( $html, $plain ),
			'definitions'        => self::definitions( $plain ),
			'quantities'         => self::quantities( $plain ),
			'citations'          => self::citation_markers( $html, $plain ),
			'hedging'            => self::hedging_phrases( $plain ),
			'first_paragraph'    => self::first_paragraph_text( $html, (int) ( $context['first_paragraph_index'] ?? 0 ) ),

			// --- repetition -------------------------------------------------
			'repetition'         => self::repetition( $plain ),

			// --- context passthrough ---------------------------------------
			'title'              => (string) ( $context['title'] ?? '' ),
			'meta_description'   => (string) ( $context['meta_description'] ?? '' ),
			'slug'               => (string) ( $context['slug'] ?? '' ),
			'search_intent'      => (string) ( $context['search_intent'] ?? '' ),
			'source_urls'        => array_values( array_filter( (array) ( $context['source_urls'] ?? array() ) ) ),
			'author'             => (string) ( $context['author'] ?? '' ),
			'has_schema'         => $context['has_schema'] ?? null,
			'site_domain'        => (string) ( $context['site_domain'] ?? '' ),
		);
	}

	/* ======================================================================
	 * Structure
	 * ==================================================================== */

	/**
	 * Extract headings with their level and text.
	 *
	 * @param string $html Content HTML.
	 * @return array<int, array{level: int, text: string}>
	 */
	public static function headings( $html ) {
		$out = array();

		if ( ! preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $m ) {
			$out[] = array(
				'level' => (int) $m[1],
				'text'  => trim( AvandWP_Text_Helper::to_plain_text( $m[2] ) ),
			);
		}

		return $out;
	}

	/**
	 * Detect heading-hierarchy problems (skipped levels, missing H2, multiple H1).
	 *
	 * @param array<int, array{level: int, text: string}> $headings Headings.
	 * @return array{issues: array<int, string>, h1_count: int, h2_count: int, max_depth: int, has_h2: bool, skips: int}
	 */
	public static function heading_hierarchy( array $headings ) {
		$issues    = array();
		$h1        = 0;
		$h2        = 0;
		$max_depth = 0;
		$skips     = 0;
		$previous  = 0;

		foreach ( $headings as $h ) {
			$level = (int) $h['level'];

			if ( 1 === $level ) {
				++$h1;
			}
			if ( 2 === $level ) {
				++$h2;
			}

			$max_depth = max( $max_depth, $level );

			// A jump from H2 straight to H4 leaves the outline ambiguous.
			if ( $previous > 0 && ( $level - $previous ) > 1 ) {
				++$skips;
				$issues[] = sprintf(
					'پرش سطح عنوان: از H%d مستقیماً به H%d («%s»).',
					$previous,
					$level,
					$h['text']
				);
			}

			$previous = $level;
		}

		if ( $h1 > 1 ) {
			$issues[] = sprintf( '%d عنوان H1 در متن وجود دارد؛ تنها یک H1 مجاز است.', $h1 );
		}

		if ( $h2 < 1 && ! empty( $headings ) ) {
			$issues[] = 'هیچ عنوان H2 در متن وجود ندارد؛ ساختار مقاله برای اسکن‌شدن مناسب نیست.';
		}

		return array(
			'issues'    => $issues,
			'h1_count'  => $h1,
			'h2_count'  => $h2,
			'max_depth' => $max_depth,
			'has_h2'    => $h2 > 0,
			'skips'     => $skips,
		);
	}

	/**
	 * Count structural block elements.
	 *
	 * @param string $html Content HTML.
	 * @return array<string, int>
	 */
	public static function blocks( $html ) {
		return array(
			'paragraphs' => (int) preg_match_all( '/<p\b[^>]*>/i', $html ),
			'lists'      => (int) preg_match_all( '/<(ul|ol)\b[^>]*>/i', $html ),
			'list_items' => (int) preg_match_all( '/<li\b[^>]*>/i', $html ),
			'tables'     => (int) preg_match_all( '/<table\b[^>]*>/i', $html ),
			'table_rows' => (int) preg_match_all( '/<tr\b[^>]*>/i', $html ),
			'blockquotes' => (int) preg_match_all( '/<blockquote\b[^>]*>/i', $html ),
			'sections'   => (int) preg_match_all( '/<section\b[^>]*>/i', $html ),
		);
	}

	/* ======================================================================
	 * Links
	 * ==================================================================== */

	/**
	 * Extract anchors with destination classification.
	 *
	 * @param string $html        Content HTML.
	 * @param string $site_domain Site host, used to classify internal links.
	 * @return array<int, array<string, mixed>>
	 */
	public static function links( $html, $site_domain = '' ) {
		$out = array();

		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $m ) {
			$attrs = $m[1];
			$href  = '';

			if ( preg_match( '/href\s*=\s*("|\')(.*?)\1/i', $attrs, $h ) ) {
				$href = trim( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}

			if ( '' === $href ) {
				continue;
			}

			$rel = '';
			if ( preg_match( '/rel\s*=\s*("|\')(.*?)\1/i', $attrs, $r ) ) {
				$rel = strtolower( trim( $r[2] ) );
			}

			$out[] = array(
				'href'      => $href,
				'anchor'    => trim( AvandWP_Text_Helper::to_plain_text( $m[2] ) ),
				'rel'       => $rel,
				'type'      => self::classify_link( $href, $site_domain ),
				'is_email'  => ( 0 === strpos( $href, 'mailto:' ) ),
				'is_anchor' => ( 0 === strpos( $href, '#' ) ),
				'target'    => preg_match( '/target\s*=\s*("|\')_blank\1/i', $attrs ) ? '_blank' : '',
			);
		}

		return $out;
	}

	/**
	 * Classify a destination as internal, external or relative.
	 *
	 * @param string $href        URL.
	 * @param string $site_domain Host of the current site.
	 * @return string internal|external|relative|special
	 */
	public static function classify_link( $href, $site_domain ) {
		if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
			return 'special';
		}

		$host = wp_parse_url( $href, PHP_URL_HOST );

		if ( empty( $host ) ) {
			return 0 === strpos( $href, '/' ) ? 'internal' : 'relative';
		}

		$host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
		$site = strtolower( preg_replace( '/^www\./', '', (string) $site_domain ) );

		if ( '' !== $site && $host === $site ) {
			return 'internal';
		}

		return 'external';
	}

	/**
	 * Aggregate link statistics.
	 *
	 * @param array<int, array<string, mixed>> $links Links.
	 * @return array<string, mixed>
	 */
	public static function link_stats( array $links ) {
		$internal    = 0;
		$external    = 0;
		$nofollow    = 0;
		$sponsored   = 0;
		$empty       = 0;
		$hosts       = array();
		$anchors     = array();

		foreach ( $links as $link ) {
			if ( 'internal' === $link['type'] ) {
				++$internal;
			} elseif ( 'external' === $link['type'] ) {
				++$external;

				$host = strtolower( (string) wp_parse_url( $link['href'], PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$hosts[ $host ] = ( $hosts[ $host ] ?? 0 ) + 1;
				}
			}

			if ( false !== strpos( $link['rel'], 'nofollow' ) ) {
				++$nofollow;
			}
			if ( false !== strpos( $link['rel'], 'sponsored' ) ) {
				++$sponsored;
			}

			// Generic anchors give search engines and readers nothing to work with.
			if ( '' === $link['anchor'] || preg_match( '/^(اینجا|کلیک کنید|بیشتر|ادامه|here|click here|read more|این لینک)$/u', $link['anchor'] ) ) {
				++$empty;
			}

			if ( '' !== $link['anchor'] ) {
				$key            = AvandWP_Text_Helper::normalize_persian( $link['anchor'] );
				$anchors[ $key ] = ( $anchors[ $key ] ?? 0 ) + 1;
			}
		}

		$repeated_anchor = array();
		foreach ( $anchors as $anchor => $count ) {
			if ( $count > 1 ) {
				$repeated_anchor[ $anchor ] = $count;
			}
		}

		return array(
			'total'            => count( $links ),
			'internal'         => $internal,
			'external'         => $external,
			'nofollow'         => $nofollow,
			'sponsored'        => $sponsored,
			'generic_anchors'  => $empty,
			'unique_hosts'     => count( $hosts ),
			'hosts'            => $hosts,
			'repeated_anchors' => $repeated_anchor,
		);
	}

	/* ======================================================================
	 * Images
	 * ==================================================================== */

	/**
	 * Extract images with their alt text.
	 *
	 * @param string $html Content HTML.
	 * @return array<int, array{src: string, alt: string, has_alt: bool, title: string}>
	 */
	public static function images( $html ) {
		$out = array();

		if ( ! preg_match_all( '/<img\b([^>]*)>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $m ) {
			$attrs = $m[1];
			$src   = '';
			$alt   = null;
			$title = '';

			if ( preg_match( '/src\s*=\s*("|\')(.*?)\1/i', $attrs, $s ) ) {
				$src = $s[2];
			}
			if ( preg_match( '/alt\s*=\s*("|\')(.*?)\1/i', $attrs, $a ) ) {
				$alt = html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			if ( preg_match( '/title\s*=\s*("|\')(.*?)\1/i', $attrs, $t ) ) {
				$title = $t[2];
			}

			$out[] = array(
				'src'     => $src,
				'alt'     => null === $alt ? '' : $alt,
				'has_alt' => null !== $alt && '' !== trim( $alt ),
				'title'   => $title,
			);
		}

		return $out;
	}

	/**
	 * Aggregate image statistics.
	 *
	 * @param array<int, array<string, mixed>> $images Images.
	 * @return array<string, mixed>
	 */
	public static function image_stats( array $images ) {
		$missing_alt = 0;
		$filler_alt  = 0;
		$alt_counts  = array();

		foreach ( $images as $image ) {
			if ( ! $image['has_alt'] ) {
				++$missing_alt;
				continue;
			}

			$alt = trim( $image['alt'] );

			// Filenames reused as alt text are a common, low-value pattern.
			if ( preg_match( '/\.(png|jpe?g|webp|gif|avif)$/i', $alt ) || preg_match( '/^img[_-]?\d+/i', $alt ) ) {
				++$filler_alt;
			}

			$key                = AvandWP_Text_Helper::normalize_persian( $alt );
			$alt_counts[ $key ] = ( $alt_counts[ $key ] ?? 0 ) + 1;
		}

		$duplicate_alts = 0;
		foreach ( $alt_counts as $count ) {
			if ( $count > 1 ) {
				++$duplicate_alts;
			}
		}

		return array(
			'total'          => count( $images ),
			'missing_alt'    => $missing_alt,
			'filler_alt'     => $filler_alt,
			'duplicate_alts' => $duplicate_alts,
			'all_have_alt'   => ! empty( $images ) && 0 === $missing_alt,
		);
	}

	/* ======================================================================
	 * Keywords & entities
	 * ==================================================================== */

	/**
	 * Measure focus-keyword usage.
	 *
	 * Deliberately reports *signals*, not a prescribed density target. Keyword
	 * density is not a ranking requirement, and a hard density rule damages
	 * Persian prose.
	 *
	 * @param string $html        Content HTML.
	 * @param string $plain       Plain text.
	 * @param string $keyword     Focus keyword.
	 * @param array  $measurement Length measurement.
	 * @return array<string, mixed>
	 */
	public static function keyword_stats( $html, $plain, $keyword, array $measurement ) {
		if ( '' === trim( (string) $keyword ) ) {
			return array(
				'keyword'         => '',
				'available'       => false,
				'total'           => 0,
				'density_percent' => null,
				'in_title'        => null,
				'in_first_100'    => null,
				'in_h2'           => 0,
				'in_meta'         => null,
				'stuffed'         => false,
			);
		}

		$norm_plain   = AvandWP_Text_Helper::normalize_persian( $plain );
		$norm_keyword = AvandWP_Text_Helper::normalize_persian( $keyword );

		$occurrences = '' === $norm_keyword ? 0 : substr_count( $norm_plain, $norm_keyword );
		$words       = max( 1, (int) $measurement['words'] );
		$density     = round( ( $occurrences / $words ) * 100, 2 );

		$keyword_words = max( 1, AvandWP_Text_Helper::word_count( $keyword ) );
		// Keyword stuffing is judged on phrase recurrence relative to length,
		// not on a fixed percentage.
		$stuffed = ( $keyword_words <= 2 )
			? ( $occurrences > 0 && ( $occurrences / $words ) > 0.035 )
			: ( ( $occurrences / $words ) > 0.03 );

		$headings = self::headings( $html );
		$in_h2    = 0;
		foreach ( $headings as $heading ) {
			if ( 2 === $heading['level'] && false !== strpos( AvandWP_Text_Helper::normalize_persian( $heading['text'] ), $norm_keyword ) ) {
				++$in_h2;
			}
		}

		$first_chunk = AvandWP_Text_Helper::normalize_persian(
			AvandWP_Text_Helper::safe_substr( $plain, 0, 400 )
		);

		return array(
			'keyword'         => $keyword,
			'available'       => true,
			'total'           => $occurrences,
			'word_count'      => $keyword_words,
			'density_percent' => $density,
			'in_first_100'    => ( '' !== $norm_keyword && false !== strpos( $first_chunk, $norm_keyword ) ),
			'in_h2'           => $in_h2,
			'stuffed'         => $stuffed,
		);
	}

	/**
	 * How many of these terms actually appear in the text?
	 *
	 * @param string $plain Plain text.
	 * @param array  $terms Terms to look for.
	 * @return array{present: array<int, string>, missing: array<int, string>, ratio: float}
	 */
	public static function coverage( $plain, array $terms ) {
		$norm  = AvandWP_Text_Helper::normalize_persian( $plain );
		$found = array();
		$lost  = array();

		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			if ( '' === $term ) {
				continue;
			}
			$needle = AvandWP_Text_Helper::normalize_persian( $term );
			if ( '' !== $needle && false !== strpos( $norm, $needle ) ) {
				$found[] = $term;
			} else {
				$lost[] = $term;
			}
		}

		$total = count( $found ) + count( $lost );

		return array(
			'present' => $found,
			'missing' => $lost,
			'ratio'   => $total > 0 ? round( count( $found ) / $total, 3 ) : 0.0,
		);
	}

	/* ======================================================================
	 * Answer readiness
	 * ==================================================================== */

	/**
	 * Detect question-form headings and question sentences.
	 *
	 * @param string $html  Content HTML.
	 * @param string $plain Plain text.
	 * @return array{heading_questions: array<int, string>, text_questions: array<int, string>, count: int}
	 */
	public static function questions( $html, $plain ) {
		$heading_questions = array();

		foreach ( self::headings( $html ) as $heading ) {
			if ( preg_match( '/[?؟]\s*$|^(چگونه|چطور|چیست|کدام|آیا|چرا|چه زمانی|چه مقدار|how|what|why|which|when|where)\b/iu', $heading['text'] ) ) {
				$heading_questions[] = $heading['text'];
			}
		}

		$text_questions = array();

		foreach ( preg_split( '/[.!؟?]+|\n+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( preg_match( '/(چیست|کدام|چگونه|چطور|چرا|آیا|چقدر|چه زمانی)\s*$/u', $candidate ) || preg_match( '/\?\s*$/', $candidate ) ) {
				$text_questions[] = $candidate;
			}
		}

		return array(
			'heading_questions' => $heading_questions,
			'text_questions'    => array_slice( $text_questions, 0, 20 ),
			'count'             => count( $heading_questions ) + count( $text_questions ),
		);
	}

	/**
	 * Detect explicit definition patterns.
	 *
	 * @param string $plain Plain text.
	 * @return array<int, string>
	 */
	public static function definitions( $plain ) {
		$out = array();

		$patterns = array(
			'/([^.\n]{2,80})\s+(?:به معنی|یعنی|عبارت است از|تعریف می‌شود به|گفته می‌شود)\s+[^.\n]{5,200}/u',
			'/([A-Za-z\x{0600}-\x{06FF}\s]{3,60})\s+(?:is defined as|refers to|means)\s+[^.\n]{5,200}/iu',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $plain, $matches ) ) {
				foreach ( $matches[0] as $sentence ) {
					$out[] = trim( $sentence );
				}
			}
		}

		return array_slice( array_unique( $out ), 0, 15 );
	}

	/**
	 * Count concrete quantities, dates and named units.
	 *
	 * Specific figures are a strong signal that text is substantive rather than
	 * generic, and they are what a generative engine can quote.
	 *
	 * @param string $plain Plain text.
	 * @return array{total: int, samples: array<int, string>}
	 */
	public static function quantities( $plain ) {
		$samples = array();

		// Persian and Latin digits, optional decimal separator, optional unit.
		$pattern = '/[0-9\x{06F0}-\x{06F9}]+(?:[.,،٫][0-9\x{06F0}-\x{06F9}]+)?\s*(?:%|٪|درصد|تومان|ریال|دلار|گیگابایت|مگابایت|کیلوبایت|ثانیه|دقیقه|ساعت|روز|هفته|ماه|سال|نفر|عدد|میلیون|هزار|میلیارد|GB|MB|KB|ms|kg|km)?/u';

		if ( preg_match_all( $pattern, $plain, $matches ) ) {
			foreach ( $matches[0] as $hit ) {
				$hit = trim( $hit );
				// Ignore bare single digits, which are rarely meaningful facts.
				if ( '' !== $hit && AvandWP_Text_Helper::length( $hit ) > 1 ) {
					$samples[] = $hit;
				}
			}
		}

		return array(
			'total'   => count( $samples ),
			'samples' => array_slice( array_unique( $samples ), 0, 20 ),
		);
	}

	/**
	 * Detect citation and attribution markers.
	 *
	 * This measures *markers* in the text (a named source, a year reference, a
	 * link to an external document). It cannot and does not verify that a claim
	 * is true.
	 *
	 * @param string $html  Content HTML.
	 * @param string $plain Plain text.
	 * @return array{total: int, external_links: int, patterns: array<int, string>}
	 */
	public static function citation_markers( $html, $plain ) {
		$patterns = array(
			'/(?:طبق|بر اساس|به گزارش|به نقل از|per|according to|research by|مطالعه|پژوهش)\s+[^.\n]{3,60}/u',
			'/\((?:[^()]{4,80}?)(?:\d{4}|\x{06F0}-\x{06F9}{4})(?:[^()]{0,40}?)\)/u',
			'/\[\d+\]/u',
		);

		$hits = array();
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $plain, $matches ) ) {
				foreach ( $matches[0] as $m ) {
					$hits[] = trim( $m );
				}
			}
		}

		$external = 0;
		foreach ( self::links( $html ) as $link ) {
			if ( 'external' === $link['type'] ) {
				++$external;
			}
		}

		return array(
			'total'          => count( array_unique( $hits ) ),
			'external_links' => $external,
			'patterns'       => array_slice( array_unique( $hits ), 0, 15 ),
		);
	}

	/**
	 * Detect hedging and unsupported-generalisation phrases.
	 *
	 * @param string $plain Plain text.
	 * @return array{total: int, samples: array<int, string>}
	 */
	public static function hedging_phrases( $plain ) {
		$needles = array(
			'به نظر می‌رسد',
			'شاید',
			'احتمالاً',
			'گمان می‌رود',
			'معمولاً همه',
			'همه می‌دانند',
			'بهترین در جهان',
			'بدون شک بهترین',
			'قطعاً بهترین',
			'همیشه بهترین',
			'هیچ‌کس نمی‌داند',
			'ممکن است',
			'بعضی‌ها می‌گویند',
			'it is believed',
			'everyone knows',
			'undoubtedly the best',
			'might possibly',
		);

		$norm    = AvandWP_Text_Helper::normalize_persian( $plain );
		$samples = array();

		foreach ( $needles as $needle ) {
			$n = AvandWP_Text_Helper::normalize_persian( $needle );
			if ( '' !== $n && false !== strpos( $norm, $n ) ) {
				$samples[] = $needle;
			}
		}

		return array(
			'total'   => count( $samples ),
			'samples' => $samples,
		);
	}

	/**
	 * Return the first substantive paragraph's text.
	 *
	 * @param string $html  Content HTML.
	 * @param int    $index Which paragraph to take (0-based among real paragraphs).
	 * @return string
	 */
	public static function first_paragraph_text( $html, $index = 0 ) {
		if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches ) ) {
			return '';
		}

		$index = max( 0, min( (int) $index, count( $matches[1] ) - 1 ) );

		return AvandWP_Text_Helper::to_plain_text( $matches[1][ $index ] );
	}

	/* ======================================================================
	 * Repetition
	 * ==================================================================== */

	/**
	 * Detect duplicated sentences and uncommon n-grams that repeat.
	 *
	 * @param string $plain Plain text.
	 * @return array{duplicate_sentences: array<int, string>, repeated_ngrams: array<string, int>, duplicate_ratio: float}
	 */
	public static function repetition( $plain ) {
		$sentences = preg_split( '/[.!?؟؛]+|\n+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$seen      = array();
		$duplicate = array();

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( AvandWP_Text_Helper::word_count( $sentence ) < 6 ) {
				continue;
			}
			$key = AvandWP_Text_Helper::normalize_persian( $sentence );
			if ( isset( $seen[ $key ] ) ) {
				$duplicate[ $key ] = $sentence;
			} else {
				$seen[ $key ] = true;
			}
		}

		// Repeated 5-grams catch rephrased-but-cloned paragraphs.
		$ngrams = array();
		$words  = preg_split( '/\s+/u', AvandWP_Text_Helper::normalize_persian( $plain ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		for ( $i = 0; $i + 5 <= count( $words ); $i++ ) {
			$gram = implode( ' ', array_slice( $words, $i, 5 ) );
			$ngrams[ $gram ] = ( $ngrams[ $gram ] ?? 0 ) + 1;
		}

		$repeated = array_filter(
			$ngrams,
			static function ( $count ) {
				return $count > 1;
			}
		);

		arsort( $repeated );

		$considerable = count( $sentences ) > 0
			? round( count( $duplicate ) / max( 1, count( $sentences ) ), 3 )
			: 0.0;

		return array(
			'duplicate_sentences' => array_slice( array_values( $duplicate ), 0, 10 ),
			'repeated_ngrams'     => array_slice( $repeated, 0, 10, true ),
			'duplicate_ratio'     => $considerable,
		);
	}
}
