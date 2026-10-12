<?php
/**
 * Link intelligence: analysis, proposal and approval-based insertion.
 *
 * Design rules, from the product constraints:
 *
 *   - Never silently modify user content. Analysis and proposal are read-only;
 *     insertion happens only for suggestions the user explicitly approved.
 *   - Never invent a link target. External targets come from a list the site
 *     owner defines; the plugin does not guess URLs.
 *   - Every insertion is reversible. Each applied link is recorded with its
 *     exact anchor, URL, byte offset and timestamp, so a single change can be
 *     undone without touching anything else in the post.
 *   - Anchors are user-defined. The plugin proposes existing phrases found in
 *     the text and the owner's configured anchors; it does not rewrite
 *     sentences to manufacture an anchor.
 *
 * What this module deliberately does NOT do: it does not fetch external pages
 * to verify they exist (that would be an unbounded outbound surface and would
 * fail on many hosts), and it does not claim to improve rankings. It reports
 * link structure defects it can measure and proposes concrete changes.
 *
 * @package AvandWP\Links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal and external link analysis with approval-based insertion.
 */
class AvandWP_Link_Intelligence {

	/**
	 * Option holding owner-defined link rules and external sources.
	 */
	const RULES_OPTION = 'avandwp_link_rules';

	/**
	 * Post meta key holding the audit trail of applied links.
	 */
	const APPLIED_META = '_avandwp_applied_links';

	/**
	 * Post meta key holding an anchor the owner locked.
	 */
	const LOCKED_ANCHOR_META = '_avandwp_locked_anchor';

	/**
	 * Maximum links this module will insert into a single post in one action.
	 */
	const MAX_INSERTS_PER_ACTION = 10;

	/**
	 * Default ceiling of internal links per post.
	 */
	const DEFAULT_MAX_INTERNAL = 8;

	// ---------------------------------------------------------------------
	// Owner-defined rules
	// ---------------------------------------------------------------------

	/**
	 * Owner-defined link rules.
	 *
	 * Shape:
	 *   [
	 *     [
	 *       'type'      => 'internal'|'external',
	 *       'url'       => 'https://…',        // required
	 *       'label'     => 'human label',
	 *       'anchors'   => ['anchor one', …],  // user-defined anchors
	 *       'max_uses'  => 2,
	 *       'post_ids'  => [12, 34],           // optional: only inside these posts
	 *       'enabled'   => true,
	 *     ],
	 *   ]
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules() {
		$rules = get_option( self::RULES_OPTION, array() );

		if ( ! is_array( $rules ) ) {
			return array();
		}

		$out = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['url'] ) ) {
				continue;
			}

			$url = esc_url_raw( (string) $rule['url'] );

			if ( '' === $url ) {
				continue;
			}

			$type = ( 'external' === ( $rule['type'] ?? '' ) ) ? 'external' : 'internal';

			$out[] = array(
				'type'     => $type,
				'url'      => $url,
				'label'    => sanitize_text_field( (string) ( $rule['label'] ?? $url ) ),
				'anchors'  => AvandWP_Article_Brief::string_list( $rule['anchors'] ?? array() ),
				'max_uses' => max( 1, min( 20, (int) ( $rule['max_uses'] ?? 2 ) ) ),
				'post_ids' => array_map( 'intval', (array) ( $rule['post_ids'] ?? array() ) ),
				'enabled'  => ! isset( $rule['enabled'] ) || ! empty( $rule['enabled'] ),
			);
		}

		return $out;
	}

	/**
	 * Save owner-defined rules (used by the settings/REST layer).
	 *
	 * @param array $rules Raw rules.
	 * @return array<int, array<string, mixed>> Stored rules.
	 */
	public static function save_rules( array $rules ) {
		update_option( self::RULES_OPTION, $rules, false );

		return self::rules();
	}

	// ---------------------------------------------------------------------
	// Analysis
	// ---------------------------------------------------------------------

	/**
	 * Analyse a post's links and return measurable findings plus suggestions.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'avandwp_invalid_post', 'نوشته مورد نظر یافت نشد.' );
		}

		$html = (string) $post->post_content;
		$rate = self::MAX_INSERTS_PER_ACTION;
		unset( $rate );

		$existing = self::extract_links( $html );

		$internal = array();
		$external = array();

		foreach ( $existing as $link ) {
			if ( self::is_internal_url( $link['url'] ) ) {
				$internal[] = $link;
			} else {
				$external[] = $link;
			}
		}

		$measured   = AvandWP_Text_Helper::measure( $html );
		$word_count = max( 1, (int) $measured['words'] );

		$suggestions = $this->suggest( $post, $html, array_column( $existing, 'url' ) );

		return array(
			'post_id'      => $post_id,
			'title'        => (string) $post->post_title,
			'words'        => $word_count,
			'existing'     => array(
				'internal'  => $internal,
				'external'  => $external,
				'nofollow'  => count( array_filter( $external, static function ( $l ) {
					return ! empty( $l['nofollow'] );
				} ) ),
				'empty_text' => count(
					array_filter(
						$existing,
						static function ( $l ) {
							return '' === trim( (string) $l['anchor'] );
						}
					)
				),
			),
			'findings'     => $this->findings( $word_count, $internal, $external, $existing ),
			'suggestions'  => $suggestions,
			'limits'       => array(
				'internal_configured' => (int) ( get_option( 'avandwp_settings', array() )['max_internal_links'] ?? self::DEFAULT_MAX_INTERNAL ),
				'per_action'          => self::MAX_INSERTS_PER_ACTION,
			),
			'disclaimer'   => 'این تحلیل ساختار لینک‌های همین نوشته را می‌سنجد. تضمینی درباره رتبه یا اعتبار دامنه ارائه نمی‌شود و وضعیت لینک‌های بیرونی (زنده بودن مقصد) بررسی نمی‌شود.',
		);
	}

	/**
	 * Extract anchors from HTML, with their attributes.
	 *
	 * @param string $html Content.
	 * @return array<int, array<string, mixed>>
	 */
	public static function extract_links( $html ) {
		$links = array();

		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/isu', (string) $html, $matches, PREG_SET_ORDER ) ) {
			return $links;
		}

		foreach ( $matches as $match ) {
			$attrs = $match[1];

			if ( ! preg_match( '/href\s*=\s*["\']([^"\']*)["\']/iu', $attrs, $href ) ) {
				continue;
			}

			$links[] = array(
				'url'      => (string) $href[1],
				'anchor'   => trim( wp_strip_all_tags( $match[2] ) ),
				'nofollow' => (bool) preg_match( '/rel\s*=\s*["\'][^"\']*nofollow/iu', $attrs ),
				'target'   => preg_match( '/target\s*=\s*["\']_blank["\']/iu', $attrs ) ? '_blank' : '',
			);
		}

		return $links;
	}

	/**
	 * Is a URL internal to this site?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_internal_url( $url ) {
		$url = (string) $url;

		if ( '' === $url || 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) ) {
			// A fragment or contact link is neither internal nor external for
			// the purposes of link structure.
			return false;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return true;
		}

		$home = wp_parse_url( home_url() );

		if ( empty( $home['host'] ) ) {
			return false;
		}

		$target = wp_parse_url( $url );

		if ( empty( $target['host'] ) ) {
			return false;
		}

		$home_host   = strtolower( preg_replace( '/^www\./', '', (string) $home['host'] ) );
		$target_host = strtolower( preg_replace( '/^www\./', '', (string) $target['host'] ) );

		return $home_host === $target_host;
	}

	/**
	 * Measurable link-structure findings for one post.
	 *
	 * @param int   $word_count Words in the post.
	 * @param array $internal   Internal links.
	 * @param array $external   External links.
	 * @param array $all        All links.
	 * @return array<int, array<string, mixed>>
	 */
	private function findings( $word_count, array $internal, array $external, array $all ) {
		$findings = array();
		$settings = get_option( 'avandwp_settings', array() );

		$max_internal = max( 1, (int) ( $settings['max_internal_links'] ?? self::DEFAULT_MAX_INTERNAL ) );

		if ( 0 === count( $internal ) && $word_count >= 400 ) {
			$findings[] = array(
				'code'     => 'no_internal_links',
				'severity' => 'high',
				'message'  => 'این نوشته هیچ لینک داخلی ندارد. لینک داخلی به صفحات مرتبط، مسیر خزش و کشف محتوا را بهبود می‌دهد.',
				'evidence' => sprintf( 'تعداد کلمات: %d — لینک داخلی: 0', $word_count ),
			);
		}

		if ( count( $internal ) > $max_internal ) {
			$findings[] = array(
				'code'     => 'too_many_internal_links',
				'severity' => 'medium',
				'message'  => sprintf( 'تعداد لینک‌های داخلی از سقف تعیین‌شده (%d) بیشتر است.', $max_internal ),
				'evidence' => sprintf( 'لینک داخلی موجود: %d', count( $internal ) ),
			);
		}

		if ( count( $internal ) + count( $external ) > 0 && count( $external ) === 0 ) {
			$findings[] = array(
				'code'     => 'no_external_links',
				'severity' => 'low',
				'message'  => 'به هیچ منبع بیرونی ارجاع نشده است. ارجاع به منابع معتبر به اعتماد‌پذیری محتوا کمک می‌کند.',
				'evidence' => 'لینک بیرونی: 0',
			);
		}

		$empty = array_filter(
			$all,
			static function ( $link ) {
				return '' === trim( (string) $link['anchor'] );
			}
		);

		if ( ! empty( $empty ) ) {
			$findings[] = array(
				'code'     => 'empty_anchor',
				'severity' => 'medium',
				'message'  => 'برخی لینک‌ها متن لنگر ندارند. لینک بدون متن برای کاربر و برای موتور جستجو معنایی منتقل نمی‌کند.',
				'evidence' => sprintf( 'تعداد لینک بدون متن: %d', count( $empty ) ),
			);
		}

		$blank_without_rel = array_filter(
			$external,
			static function ( $link ) {
				return '_blank' === ( $link['target'] ?? '' ) && empty( $link['nofollow'] );
			}
		);

		$noopener = array_filter(
			$external,
			static function ( $link ) {
				return '_blank' === ( $link['target'] ?? '' );
			}
		);

		if ( ! empty( $noopener ) ) {
			$findings[] = array(
				'code'     => 'target_blank_review',
				'severity' => 'low',
				'message'  => 'لینک‌هایی که در تب جدید باز می‌شوند باید rel="noopener" داشته باشند (و در صورت نبود رابطه تجاری، rel="nofollow" بررسی شود).',
				'evidence' => sprintf( 'لینک با target=_blank: %d — بدون nofollow: %d', count( $noopener ), count( $blank_without_rel ) ),
			);
		}

		return $findings;
	}

	/**
	 * Build link suggestions for a post.
	 *
	 * Two sources, both evidence-based:
	 *   - owner-defined rules whose anchors appear in the text;
	 *   - site content that is topically close, matched by the knowledge base,
	 *     with the anchor taken from text that already exists in the post.
	 *
	 * @param object $post          Post object.
	 * @param string $html          Post content.
	 * @param array  $existing_urls URLs already linked.
	 * @return array<int, array<string, mixed>>
	 */
	private function suggest( $post, $html, array $existing_urls ) {
		$suggestions = array();
		$used        = array_flip( array_map( 'strval', $existing_urls ) );
		$plain       = AvandWP_Text_Helper::to_plain_text( $html );

		foreach ( self::rules() as $rule ) {
			if ( ! $rule['enabled'] ) {
				continue;
			}

			if ( ! empty( $rule['post_ids'] ) && ! in_array( (int) $post->ID, $rule['post_ids'], true ) ) {
				continue;
			}

			$already = isset( $used[ $rule['url'] ] );

			$matched = array();

			foreach ( $rule['anchors'] as $anchor ) {
				$occurrences = AvandWP_Text_Helper::length( $anchor ) >= 3 ? substr_count( $plain, $anchor ) : 0;

				if ( $occurrences > 0 ) {
					$matched[] = array( 'anchor' => $anchor, 'occurrences' => $occurrences );
				}
			}

			$suggestions[] = array(
				'id'             => md5( $rule['type'] . '|' . $rule['url'] . '|' . $post->ID ),
				'type'           => $rule['type'],
				'url'            => $rule['url'],
				'label'          => $rule['label'],
				'origin'         => 'rule',
				'already_linked' => $already,
				'anchors'        => $matched,
				'max_uses'       => $rule['max_uses'],
				'confidence'     => $matched ? 'high' : 'none',
				'reason'         => $matched
					? 'عبارت لنگر تعریف‌شده شما در متن این نوشته پیدا شد.'
					: 'عبارت لنگر تعریف‌شده شما در این نوشته پیدا نشد؛ برای درج باید عبارت لنگر را تغییر دهید یا متن را ویرایش کنید.',
			);
		}

		// Topical internal candidates from the site's own index.
		$settings = get_option( 'avandwp_settings', array() );

		if ( ! empty( $settings['enable_link_suggestions'] ) ) {
			$kb = new AvandWP_Knowledge_Base();

			$matches = $kb->search( (string) $post->post_title . ' ' . AvandWP_Text_Helper::safe_substr( $plain, 0, 400 ), 6 );

			foreach ( (array) $matches as $match ) {
				$url = (string) ( $match['url'] ?? '' );

				if ( '' === $url || isset( $used[ $url ] ) || (int) ( $match['source_id'] ?? 0 ) === (int) $post->ID ) {
					continue;
				}

				$title  = (string) ( $match['title'] ?? '' );
				$anchor = $this->anchor_candidate_in_text( $plain, $title );

				$suggestions[] = array(
					'id'             => md5( 'internal|' . $url . '|' . $post->ID ),
					'type'           => 'internal',
					'url'            => $url,
					'label'          => $title,
					'origin'         => 'site_index',
					'already_linked' => false,
					'anchors'        => $anchor ? array( array( 'anchor' => $anchor, 'occurrences' => substr_count( $plain, $anchor ) ) ) : array(),
					'max_uses'       => 1,
					'confidence'     => $anchor ? 'medium' : 'none',
					'reason'         => $anchor
						? 'عنوان نوشته مرتبط، به‌صورت عبارت در متن این نوشته وجود دارد.'
						: 'بین این نوشته و نوشته مرتبط شباهت موضوعی هست، اما عبارت مناسبی برای لنگر در متن پیدا نشد.',
				);
			}
		}

		return $suggestions;
	}

	/**
	 * Find a phrase from the target's title that already exists in the text.
	 *
	 * Only whole-word phrases are returned: a partial match would produce an
	 * anchor that reads as a typo once it is underlined.
	 *
	 * @param string $plain Post plain text.
	 * @param string $title Target title.
	 * @return string Anchor phrase, or empty string.
	 */
	private function anchor_candidate_in_text( $plain, $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		if ( AvandWP_Text_Helper::length( $title ) < 6 ) {
			return '';
		}

		if ( false !== strpos( $plain, $title ) ) {
			return $title;
		}

		// Try progressively shorter prefixes on a word boundary, so "راهنمای
		// کامل سئو" can still match when the title ends with a year or suffix
		// that the post does not mention.
		$words = preg_split( '/\s+/u', $title ) ?: array();

		if ( count( $words ) < 3 ) {
			return '';
		}

		for ( $take = count( $words ) - 1; $take >= 3; $take-- ) {
			$candidate = implode( ' ', array_slice( $words, 0, $take ) );

			if ( false !== strpos( $plain, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	// ---------------------------------------------------------------------
	// Insertion (approval-based)
	// ---------------------------------------------------------------------

	/**
	 * Apply an explicit set of approved links to a post.
	 *
	 * Each item must carry the URL and the exact anchor the user approved. The
	 * method never searches for "something plausible" to link: no approval, no
	 * change.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $items   Items: [ ['url' => '', 'anchor' => '', 'type' => 'internal', 'rel' => ''] ].
	 * @return array|WP_Error { applied: [], skipped: [] }
	 */
	public function apply_links( $post_id, array $items ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'avandwp_invalid_post', 'نوشته مورد نظر یافت نشد.' );
		}

		$caps = AvandWP_Capabilities::user_can_edit_object( $post_id, array( 'post' ) );

		if ( is_wp_error( $caps ) ) {
			return $caps;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'avandwp_no_selection', 'هیچ لینکی برای درج تأیید نشده است.' );
		}

		$items = array_slice( $items, 0, self::MAX_INSERTS_PER_ACTION );

		$html    = (string) $post->post_content;
		$applied = array();
		$skipped = array();
		$used    = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url    = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			$anchor = trim( wp_strip_all_tags( (string) ( $item['anchor'] ?? '' ) ) );
			$type   = ( 'external' === ( $item['type'] ?? '' ) ) ? 'external' : 'internal';

			if ( '' === $url ) {
				$skipped[] = array( 'anchor' => $anchor, 'reason' => 'نشانی نامعتبر است.' );
				continue;
			}

			if ( AvandWP_Text_Helper::length( $anchor ) < 3 ) {
				$skipped[] = array( 'url' => $url, 'reason' => 'متن لنگر خیلی کوتاه است (حداقل ۳ کاراکتر).' );
				continue;
			}

			if ( isset( $used[ $url ] ) ) {
				$skipped[] = array( 'url' => $url, 'reason' => 'این نشانی در همین درخواست یک‌بار درج شده است.' );
				continue;
			}

			if ( $this->already_links_to( $html, $url ) ) {
				$skipped[] = array( 'url' => $url, 'reason' => 'این نوشته از قبل به همین نشانی لینک دارد.' );
				continue;
			}

			if ( $this->anchor_already_linked( $html, $anchor ) ) {
				$skipped[] = array( 'url' => $url, 'anchor' => $anchor, 'reason' => 'این عبارت از قبل لنگر یک لینک دیگر است.' );
				continue;
			}

			$rel = array();

			if ( 'external' === $type ) {
				$rel[] = 'noopener';

				if ( ! empty( $item['nofollow'] ) ) {
					$rel[] = 'nofollow';
				}
			}

			$replacement = sprintf(
				'<a href="%s"%s>%s</a>',
				$url,
				$rel ? ' rel="' . esc_attr( implode( ' ', $rel ) ) . '"' : '',
				esc_html( $anchor )
			);

			$result = self::replace_first_eligible( $html, $anchor, $replacement );

			if ( is_wp_error( $result ) ) {
				$skipped[] = array( 'url' => $url, 'anchor' => $anchor, 'reason' => $result->get_error_message() );
				continue;
			}

			$html = $result['html'];

			$applied[] = array(
				'id'       => uniqid( 'lnk', true ),
				'url'      => $url,
				'anchor'   => $anchor,
				'type'     => $type,
				'offset'   => (int) $result['offset'],
				'applied_at' => current_time( 'mysql' ),
			);

			$used[ $url ] = true;
		}

		if ( empty( $applied ) ) {
			return array( 'applied' => array(), 'skipped' => $skipped, 'changed' => false );
		}

		// The post is updated once, with all approved links, so the revision
		// history shows a single attributable change.
		$update = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $html,
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			return $update;
		}

		$log = get_post_meta( $post_id, self::APPLIED_META, true );
		$log = is_array( $log ) ? $log : array();
		$log = array_merge( $log, $applied );

		update_post_meta( $post_id, self::APPLIED_META, $log );

		AvandWP_Logger::log(
			'links.applied',
			sprintf( '%d لینک تأییدشده به نوشته #%d افزوده شد.', count( $applied ), $post_id ),
			'success',
			array( 'post_id' => $post_id, 'count' => count( $applied ) )
		);

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
			'changed' => true,
		);
	}

	/**
	 * Undo a single applied link by its recorded id.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $link_id Applied link id.
	 * @return array|WP_Error
	 */
	public function revert_link( $post_id, $link_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'avandwp_invalid_post', 'نوشته مورد نظر یافت نشد.' );
		}

		$caps = AvandWP_Capabilities::user_can_edit_object( $post_id, array( 'post' ) );

		if ( is_wp_error( $caps ) ) {
			return $caps;
		}

		$log = get_post_meta( $post_id, self::APPLIED_META, true );
		$log = is_array( $log ) ? $log : array();

		$target = null;
		$kept   = array();

		foreach ( $log as $entry ) {
			if ( is_array( $entry ) && ( $entry['id'] ?? '' ) === $link_id ) {
				$target = $entry;
				continue;
			}
			$kept[] = $entry;
		}

		if ( null === $target ) {
			return new WP_Error( 'avandwp_link_not_found', 'این لینک در سابقه درج این افزونه ثبت نشده است.' );
		}

		$html = (string) $post->post_content;

		// Unwrap exactly the one anchor whose href and text match the record,
		// leaving the anchor text in place.
		$pattern = '/<a\b[^>]*href\s*=\s*["\']' . preg_quote( $target['url'], '/' ) . '["\'][^>]*>' . preg_quote( $target['anchor'], '/' ) . '<\/a>/iu';

		$count   = 0;
		$updated = preg_replace( $pattern, $target['anchor'], $html, 1, $count );

		if ( ! is_string( $updated ) || 0 === $count ) {
			return new WP_Error(
				'avandwp_link_revert_failed',
				'متن لینک در نوشته پیدا نشد؛ احتمالاً پس از درج، متن به‌صورت دستی تغییر کرده است. بازگردانی خودکار انجام نشد تا محتوای جدید از بین نرود.'
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post_id, self::APPLIED_META, $kept );

		return array( 'reverted' => $target, 'remaining' => count( $kept ) );
	}

	/**
	 * Replace the first eligible occurrence of a phrase with markup.
	 *
	 * "Eligible" excludes occurrences inside an existing anchor, inside any
	 * heading, and inside code/pre blocks — inserting a link in those places
	 * breaks the document structure.
	 *
	 * @param string $html        Content.
	 * @param string $anchor      Phrase to replace.
	 * @param string $replacement Replacement markup.
	 * @return array|WP_Error ['html' => string, 'offset' => int]
	 */
	public static function replace_first_eligible( $html, $anchor, $replacement ) {
		$html   = (string) $html;
		$anchor = (string) $anchor;
		$ranges = self::protected_ranges( $html );

		$search_at = 0;

		while ( true ) {
			$pos = strpos( $html, $anchor, $search_at );

			if ( false === $pos ) {
				return new WP_Error(
					'avandwp_anchor_not_found',
					sprintf( 'عبارت «%s» در محل مجاز متن پیدا نشد (ممکن است داخل لینک، عنوان یا بلوک کد باشد).', $anchor )
				);
			}

			$end = $pos + strlen( $anchor );

			if ( ! self::in_ranges( $pos, $end, $ranges ) && self::is_word_boundary( $html, $pos, $end ) ) {
				return array(
					'html'   => substr( $html, 0, $pos ) . $replacement . substr( $html, $end ),
					'offset' => $pos,
				);
			}

			$search_at = $pos + 1;
		}
	}

	/**
	 * Byte ranges that must not be modified.
	 *
	 * @param string $html Content.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function protected_ranges( $html ) {
		$ranges = array();

		$patterns = array(
			'/<a\b[^>]*>.*?<\/a>/isu',
			'/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/isu',
			'/<(code|pre|script|style|textarea|button)\b[^>]*>.*?<\/\1>/isu',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $match ) {
					$ranges[] = array( (int) $match[1], (int) $match[1] + strlen( $match[0] ) );
				}
			}
		}

		return $ranges;
	}

	/**
	 * Is a byte range inside any protected range?
	 *
	 * @param int   $start  Range start.
	 * @param int   $end    Range end.
	 * @param array $ranges Protected ranges.
	 * @return bool
	 */
	private static function in_ranges( $start, $end, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $start >= $range[0] && $end <= $range[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Conservative word-boundary test that does not require mbstring.
	 *
	 * A byte at or above 0x80 belongs to a multi-byte character — for Persian
	 * text that is a letter, so it counts as "inside a word" and the match is
	 * rejected. That is deliberately stricter than a Unicode-aware check: a
	 * missed suggestion is harmless, a mangled word is not.
	 *
	 * @param string $html  Content.
	 * @param int    $start Match start offset.
	 * @param int    $end   Match end offset.
	 * @return bool
	 */
	private static function is_word_boundary( $html, $start, $end ) {
		if ( $start > 0 ) {
			$before = $html[ $start - 1 ];

			if ( ord( $before ) >= 0x80 || preg_match( '/[A-Za-z0-9_]/', $before ) ) {
				return false;
			}
		}

		if ( $end < strlen( $html ) ) {
			$after = $html[ $end ];

			if ( ord( $after ) >= 0x80 || preg_match( '/[A-Za-z0-9_]/', $after ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Does the content already link to a URL?
	 *
	 * @param string $html Content.
	 * @param string $url  URL.
	 * @return bool
	 */
	private function already_links_to( $html, $url ) {
		foreach ( self::extract_links( $html ) as $link ) {
			if ( rtrim( $link['url'], '/' ) === rtrim( $url, '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this phrase already the anchor of some link?
	 *
	 * @param string $html   Content.
	 * @param string $anchor Anchor text.
	 * @return bool
	 */
	private function anchor_already_linked( $html, $anchor ) {
		foreach ( self::extract_links( $html ) as $link ) {
			if ( trim( $link['anchor'] ) === $anchor ) {
				return true;
			}
		}

		return false;
	}

	// ---------------------------------------------------------------------
	// Site-level reporting
	// ---------------------------------------------------------------------

	/**
	 * Site-wide link inventory for the reports screen.
	 *
	 * @param int $limit Maximum posts to inspect.
	 * @return array
	 */
	public function site_link_report( $limit = 200 ) {
		$limit = max( 10, min( 500, (int) $limit ) );

		$posts = get_posts(
			array(
				'post_type'      => AvandWP_Capabilities::managed_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$rows           = array();
		$total_internal = 0;
		$total_external = 0;
		$without_links  = 0;

		foreach ( $posts as $post ) {
			$links     = self::extract_links( (string) $post->post_content );
			$internal  = 0;
			$external  = 0;

			foreach ( $links as $link ) {
				if ( self::is_internal_url( $link['url'] ) ) {
					++$internal;
				} else {
					++$external;
				}
			}

			$words = AvandWP_Text_Helper::word_count( (string) $post->post_content );

			if ( 0 === $internal && $words >= 400 ) {
				++$without_links;
			}

			$total_internal += $internal;
			$total_external += $external;

			$rows[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'status'    => (string) $post->post_status,
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
				'words'     => $words,
				'internal'  => $internal,
				'external'  => $external,
				'orphan_risk' => 0 === $internal && $words >= 400,
			);
		}

		return array(
			'inspected'       => count( $rows ),
			'limit'           => $limit,
			'total_internal'  => $total_internal,
			'total_external'  => $total_external,
			'posts_without_internal' => $without_links,
			'rows'            => $rows,
			'notes'           => array(
				'این گزارش فقط ساختار لینک‌های موجود را می‌شمارد و وضعیت زنده بودن مقصد لینک‌های بیرونی بررسی نمی‌شود.',
				'«ریسک یتیم‌بودن» یعنی نوشته‌ای با حداقل ۴۰۰ کلمه که هیچ لینک داخلی به آن داده نشده است؛ این معیار داخلی است، نه معیاری از رتبه.',
			),
		);
	}
}
