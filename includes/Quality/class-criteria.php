<?php
/**
 * Quality criteria registry — the documented scoring model.
 *
 * Three independent profiles are defined:
 *
 *   SEO  Traditional search-engine optimisation.
 *   AIO  AI-oriented content quality and answer readiness.
 *   GEO  Generative-engine discoverability and citation readiness.
 *
 * AIO and GEO are NOT standardised industry metrics. The definitions used here
 * are stated explicitly in `docs/seo-scoring-model.md` and reproduced in the
 * product UI so a user can always see what a number means.
 *
 * Rules every criterion must obey:
 *   1. It evaluates *observed evidence* from AvandWP_Content_Analyzer, never a
 *      model's opinion of its own output.
 *   2. It returns `unavailable` when the evidence needed to judge it is absent.
 *      An unavailable criterion is excluded from the denominator and reported,
 *      because treating missing evidence as a pass is how fake 100s are made.
 *   3. A score of 100 means "every applicable internal check passed under this
 *      model". It is never a claim about rankings or AI citations.
 *
 * @package AvandWP\Quality
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Criteria definitions for the three quality profiles.
 */
class AvandWP_Criteria {

	/**
	 * Result constants.
	 */
	const PASS        = 'pass';
	const PARTIAL     = 'partial';
	const FAIL        = 'fail';
	const UNAVAILABLE = 'unavailable';

	/**
	 * Profile metadata: label, one-line definition and what it is NOT.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function profiles() {
		return array(
			'seo' => array(
				'label'       => 'سئو (SEO)',
				'short'       => 'سئو',
				'definition'  => 'سنجش آمادگی محتوا برای موتورهای جستجوی کلاسیک: ساختار، متادیتا، لینک‌سازی، تصاویر و پوشش موضوعی.',
				'not_a_claim' => 'این امتیاز تضمین رتبه در گوگل نیست؛ فقط نشان می‌دهد موارد داخلیِ تعریف‌شده در این افزونه رعایت شده‌اند.',
			),
			'aio' => array(
				'label'       => 'کیفیت پاسخ برای هوش مصنوعی (AIO)',
				'short'       => 'AIO',
				'definition'  => 'سنجش میزان پاسخ‌دهی مستقیم و روشن متن به پرسش‌های کاربر: تعریف صریح، بخش‌های خودبسنده، پاسخ‌های کوتاه و مستند.',
				'not_a_claim' => 'AIO یک استاندارد رسمی و جهان‌شمول نیست؛ معیارهای آن همان است که در همین صفحه فهرست شده است.',
			),
			'geo' => array(
				'label'       => 'آمادگی استناد در موتورهای مولد (GEO)',
				'short'       => 'GEO',
				'definition'  => 'سنجش ویژگی‌هایی که نقل‌قول‌پذیری یک مطلب را برای سیستم‌های مولد (مانند ChatGPT، Gemini، Perplexity) ممکن می‌کند: داده مشخص، منبع‌دهی، نهادهای روشن و شفافیت نویسنده.',
				'not_a_claim' => 'هیچ امتیازی تضمین نمی‌کند که ChatGPT، Gemini یا Claude به مطلب شما استناد کند. این معیارها فقط مشخص می‌کنند متن تا چه اندازه «قابل استناد» نوشته شده است.',
			),
		);
	}

	/**
	 * All criteria keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		$criteria = array_merge(
			self::seo_criteria(),
			self::aio_criteria(),
			self::geo_criteria()
		);

		/**
		 * Filters the quality criteria registry.
		 *
		 * @param array<string, array<string, mixed>> $criteria Criteria keyed by id.
		 */
		return (array) apply_filters( 'avandwp_quality_criteria', $criteria );
	}

	/**
	 * Criteria for a single profile, ordered by descending weight.
	 *
	 * @param string $profile Profile key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_profile( $profile ) {
		$out = array();
		foreach ( self::all() as $id => $criterion ) {
			if ( $criterion['profile'] === $profile ) {
				$out[ $id ] = $criterion;
			}
		}

		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['weight'] <=> $a['weight'];
			}
		);

		return $out;
	}

	/**
	 * Build a criterion definition.
	 *
	 * @param string   $id             Criterion id.
	 * @param string   $profile        Profile key.
	 * @param string   $label          Persian label.
	 * @param int      $weight         Weight in the profile total.
	 * @param string   $severity       Severity when it fails: critical|high|medium|low.
	 * @param bool     $auto_fixable   Whether a safe automatic correction exists.
	 * @param string   $measures       One-line statement of what is measured.
	 * @param callable $evaluate       Receives the facts array, returns a result array.
	 * @return array<string, mixed>
	 */
	private static function criterion( $id, $profile, $label, $weight, $severity, $auto_fixable, $measures, $evaluate ) {
		return array(
			'id'           => $id,
			'profile'      => $profile,
			'label'        => $label,
			'weight'       => (int) $weight,
			'severity'     => $severity,
			'auto_fixable' => (bool) $auto_fixable,
			'measures'     => $measures,
			'evaluate'     => $evaluate,
		);
	}

	/**
	 * Normalise an evaluation into the canonical result shape.
	 *
	 * @param string $result         One of the result constants.
	 * @param string $evidence       What was observed (numbers, counts, excerpts).
	 * @param string $explanation    Why it matters / why it passed or failed.
	 * @param string $recommendation What to do about it.
	 * @return array<string, string>
	 */
	private static function outcome( $result, $evidence, $explanation, $recommendation = '' ) {
		$allowed = array( self::PASS, self::PARTIAL, self::FAIL, self::UNAVAILABLE );

		return array(
			'result'         => in_array( $result, $allowed, true ) ? $result : self::UNAVAILABLE,
			'evidence'       => (string) $evidence,
			'explanation'    => (string) $explanation,
			'recommendation' => (string) $recommendation,
		);
	}

	/**
	 * Format a Persian number for evidence strings.
	 *
	 * @param int|float $value Value.
	 * @return string
	 */
	private static function n( $value ) {
		return number_format_i18n( is_float( $value ) ? round( $value, 1 ) : (int) $value );
	}

	/* ======================================================================
	 * SEO profile
	 * ==================================================================== */

	/**
	 * Traditional SEO criteria.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function seo_criteria() {
		$c = array();

		$c['seo.length'] = self::criterion(
			'seo.length',
			'seo',
			'طول محتوا نسبت به هدف و قصد جستجو',
			9,
			'high',
			false,
			'تعداد واقعی کلمات یا کاراکترهای اندازه‌گیری‌شده در برابر هدف تعیین‌شده توسط کاربر.',
			static function ( array $f ) {
				$target = (int) ( $f['length_target'] ?? 0 );

				if ( $target <= 0 ) {
					$intent = $f['search_intent'] ?? '';
					$words  = (int) $f['measurement']['words'];

					$floor = 400;
					if ( 'commercial' === $intent || 'informational' === $intent ) {
						$floor = 700;
					} elseif ( '' === $intent ) {
						return self::outcome(
							self::UNAVAILABLE,
							sprintf( '%s کلمه اندازه‌گیری شد اما هدفی تعیین نشده است.', self::n( $words ) ),
							'برای داوری طول، یا باید هدف طول مشخص شود یا قصد جستجو اعلام گردد.',
							'هدف طول (کلمه یا کاراکتر) را در بریف مقاله تعیین کنید.'
						);
					}

					if ( $words >= $floor ) {
						return self::outcome(
							self::PASS,
							sprintf( '%s کلمه (حداقل انتظار برای قصد «%s»: %s کلمه).', self::n( $words ), $intent, self::n( $floor ) ),
							'طول محتوا با قصد جستجوی اعلام‌شده هم‌خوانی دارد.',
							''
						);
					}

					return self::outcome(
						$words >= ( $floor * 0.75 ) ? self::PARTIAL : self::FAIL,
						sprintf( '%s کلمه (حداقل انتظار برای قصد «%s»: %s کلمه).', self::n( $words ), $intent, self::n( $floor ) ),
						'متن برای پاسخ کامل به قصد جستجو کوتاه است.',
						'متن را با بخش‌های تکمیلی و پرسش‌های متداول گسترش دهید.'
					);
				}

				$assessment = AvandWP_Text_Helper::assess_length(
					$f['measurement'],
					(string) ( $f['length_mode'] ?? 'words' ),
					$target,
					(bool) ( $f['length_include_spaces'] ?? true )
				);

				$result = $assessment['complete']
					? self::PASS
					: ( $assessment['percent'] >= 75 ? self::PARTIAL : self::FAIL );

				return self::outcome(
					$result,
					$assessment['message'],
					'هدف طول توسط کاربر تعیین شده و با اندازه‌گیری واقعی متن مقایسه شده است.',
					$assessment['complete'] ? '' : 'ادامه تولید در بخش‌های تکمیلی، یا کاهش آگاهانه هدف با نظر شما.'
				);
			}
		);

		$c['seo.heading_structure'] = self::criterion(
			'seo.heading_structure',
			'seo',
			'ساختار سلسله‌مراتبی عنوان‌ها',
			8,
			'high',
			false,
			'حضور H2، عدم پرش سطح و عدم تکرار H1 در متن نهایی.',
			static function ( array $f ) {
				$h = $f['heading_hierarchy'];

				if ( empty( $f['headings'] ) ) {
					return self::outcome(
						self::FAIL,
						'هیچ عنوان HTML در متن یافت نشد.',
						'متن بدون عنوان برای موتور جستجو و برای خواننده ساختاریافته نیست.',
						'متن را به بخش‌هایی با عنوان H2 و زیربخش‌های H3 تقسیم کنید.'
					);
				}

				if ( empty( $h['issues'] ) ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s عنوان H2 و حداکثر عمق H%s بدون پرش سطح.', self::n( $h['h2_count'] ), self::n( $h['max_depth'] ) ),
						'سلسله‌مراتب عنوان‌ها معتبر است.',
						''
					);
				}

				return self::outcome(
					self::PARTIAL,
					implode( ' | ', array_slice( $h['issues'], 0, 4 ) ),
					'برخی عنوان‌ها ساختار را مبهم می‌کنند اما کل ساختار بی‌اعتبار نیست.',
					'سطح عنوان‌ها را به‌ترتیب H2 → H3 مرتب کنید و تنها یک H1 داشته باشید.'
				);
			}
		);

		$c['seo.internal_links'] = self::criterion(
			'seo.internal_links',
			'seo',
			'لینک‌سازی داخلی',
			7,
			'high',
			true,
			'تعداد لینک‌های داخلی معتبر نسبت به طول محتوا.',
			static function ( array $f ) {
				$words   = (int) $f['measurement']['words'];
				$internal = (int) $f['link_stats']['internal'];

				if ( $f['site_domain'] === '' ) {
					return self::outcome(
						self::UNAVAILABLE,
						sprintf( '%s لینک داخلی شناسایی شد اما دامنه سایت در دسترس نیست.', self::n( $internal ) ),
						'برای تفکیک لینک داخلی از خارجی، دامنه سایت لازم است.',
						''
					);
				}

				$expected = $words >= 1500 ? 4 : ( $words >= 800 ? 3 : ( $words >= 400 ? 2 : 1 ) );

				if ( $internal >= $expected ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s لینک داخلی (انتظار برای این طول: %s).', self::n( $internal ), self::n( $expected ) ),
						'محتوا به صفحات مرتبط سایت متصل شده است.',
						''
					);
				}

				if ( $internal > 0 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s لینک داخلی (انتظار: %s).', self::n( $internal ), self::n( $expected ) ),
						'لینک‌سازی داخلی شروع شده اما کامل نیست.',
						'از بخش «هوش لینک‌سازی» پیشنهادهای داخلی را بررسی و تأیید کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ لینک داخلی در متن وجود ندارد.',
					'نبود لینک داخلی، ارزش صفحات مرتبط سایت را برای خواننده و موتور جستجو کاهش می‌دهد.',
					'از بخش «هوش لینک‌سازی» برای پیشنهاد مقصد و متن لینک استفاده کنید.'
				);
			}
		);

		$c['seo.focus_keyword'] = self::criterion(
			'seo.focus_keyword',
			'seo',
			'کلمه کلیدی کانونی',
			6,
			'high',
			false,
			'حضور کلمه کلیدی کانونی در متن و در آغاز آن.',
			static function ( array $f ) {
				$stats = $f['keyword_stats'];

				if ( empty( $stats['available'] ) ) {
					return self::outcome(
						self::UNAVAILABLE,
						'کلمه کلیدی کانونی برای این مقاله تعیین نشده است.',
						'بدون تعیین کلمه کلیدی، سنجش ارتباط موضوعی ممکن نیست.',
						'کلمه کلیدی کانونی را در بریف مقاله وارد کنید.'
					);
				}

				if ( $stats['total'] < 1 ) {
					return self::outcome(
						self::FAIL,
						sprintf( 'کلمه کلیدی «%s» در متن تکرار نشده است.', $stats['keyword'] ),
						'کلمه کلیدی کانونی در متن غایب است و ارتباط موضوعی متن با هدف مشخص نیست.',
						'کلمه کلیدی را به‌صورت طبیعی در عنوان، یک H2 و پاراگراف نخست بیاورید.'
					);
				}

				if ( $stats['stuffed'] ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s بار تکرار (چگالی %s%%).', self::n( $stats['total'] ), self::n( $stats['density_percent'] ) ),
						'تکرار کلمه کلیدی از حد طبیعی بیشتر است؛ این الگو می‌تواند متنی و ماشینی به نظر برسد.',
						'بخشی از تکرارها را با مترادف یا عبارت‌های معنایی جایگزین کنید.'
					);
				}

				if ( ! empty( $stats['in_first_100'] ) || (int) $stats['in_h2'] > 0 ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s بار در متن، آغاز متن یا عنوان H2.', self::n( $stats['total'] ) ),
						'کلمه کلیدی به‌صورت طبیعی و در جایگاه‌های مؤثر حضور دارد.',
						''
					);
				}

				return self::outcome(
					self::PARTIAL,
					sprintf( '%s بار در متن، اما نه در آغاز متن و نه در عنوان H2.', self::n( $stats['total'] ) ),
					'کلمه کلیدی فقط در میانه متن آمده است.',
					'یک بار در پاراگراف نخست یا یکی از عنوان‌های H2 ذکر کنید.'
				);
			}
		);

		$c['seo.image_alt'] = self::criterion(
			'seo.image_alt',
			'seo',
			'متن جانشین تصاویر (alt)',
			6,
			'medium',
			true,
			'حضور alt معنادار برای هر تصویر و نبود alt تکراری یا نام فایل.',
			static function ( array $f ) {
				$stats = $f['image_stats'];

				if ( 0 === (int) $stats['total'] ) {
					return self::outcome(
						self::UNAVAILABLE,
						'هیچ تصویری در متن وجود ندارد.',
						'نبود تصویر یک ایراد سئوی الزامی نیست؛ این معیار ارزیابی نمی‌شود.',
						'برای مقالات بلند، افزودن تصویر زمینه‌ای به خوانایی کمک می‌کند.'
					);
				}

				if ( $stats['all_have_alt'] && 0 === (int) $stats['filler_alt'] ) {
					return self::outcome(
						self::PASS,
						sprintf( 'هر %s تصویر متن جانشین معنادار دارد.', self::n( $stats['total'] ) ),
						'تصاویر برای موتور جستجو و برای صفحه‌خوان توصیف شده‌اند.',
						''
					);
				}

				$problems = array();
				if ( (int) $stats['missing_alt'] > 0 ) {
					$problems[] = sprintf( '%s تصویر بدون alt', self::n( $stats['missing_alt'] ) );
				}
				if ( (int) $stats['filler_alt'] > 0 ) {
					$problems[] = sprintf( '%s تصویر با alt نامناسب (نام فایل یا متن عمومی)', self::n( $stats['filler_alt'] ) );
				}
				if ( (int) $stats['duplicate_alts'] > 0 ) {
					$problems[] = sprintf( '%s متن alt تکراری', self::n( $stats['duplicate_alts'] ) );
				}

				return self::outcome(
					(int) $stats['missing_alt'] > 0 ? self::FAIL : self::PARTIAL,
					implode( '، ', $problems ),
					'متن جانشین تصویر هم برای دسترس‌پذیری و هم برای درک ماشینی محتوا اهمیت دارد.',
					'برای هر تصویر یک توصیف کوتاه و اختصاصی بنویسید (بدون انباشت کلمه کلیدی).'
				);
			}
		);

		$c['seo.meta_description'] = self::criterion(
			'seo.meta_description',
			'seo',
			'توضیحات متا',
			5,
			'medium',
			true,
			'وجود، طول و اختصاصی‌بودن توضیحات متا.',
			static function ( array $f ) {
				$meta = trim( (string) $f['meta_description'] );

				if ( '' === $meta ) {
					return self::outcome(
						self::FAIL,
						'توضیحات متا خالی است.',
						'نبود توضیحات متا باعث می‌شود موتور جستجو خودش متنی از صفحه انتخاب کند که همیشه مطلوب نیست.',
						'توضیحات متا بین ۷۰ تا ۱۶۰ کاراکتر بنویسید.'
					);
				}

				$len = AvandWP_Text_Helper::char_count( $meta, true );

				if ( $len < 50 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s کاراکتر.', self::n( $len ) ),
						'توضیحات متا کوتاه‌تر از حد مؤثر است.',
						'توضیحات را تا حدود ۱۲۰–۱۵۵ کاراکتر گسترش دهید.'
					);
				}

				if ( $len > 165 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s کاراکتر.', self::n( $len ) ),
						'توضیحات متا بلندتر از حد نمایش در نتایج جستجو است و بریده می‌شود.',
						'توضیحات را به حدود ۱۵۵ کاراکتر کوتاه کنید.'
					);
				}

				return self::outcome(
					self::PASS,
					sprintf( '%s کاراکتر.', self::n( $len ) ),
					'طول توضیحات متا در محدوده مؤثر است.',
					''
				);
			}
		);

		$c['seo.title'] = self::criterion(
			'seo.title',
			'seo',
			'عنوان صفحه',
			5,
			'high',
			false,
			'وجود عنوان، طول آن و حضور کلمه کلیدی.',
			static function ( array $f ) {
				$title = trim( (string) $f['title'] );

				if ( '' === $title ) {
					return self::outcome(
						self::UNAVAILABLE,
						'عنوانی برای این محتوا ثبت نشده است.',
						'سنجش عنوان بدون عنوان ممکن نیست.',
						'عنوان مقاله را تکمیل کنید.'
					);
				}

				$len = AvandWP_Text_Helper::char_count( $title, true );
				$has_keyword = true;

				if ( ! empty( $f['keyword_stats']['available'] ) && '' !== $f['keyword_stats']['keyword'] ) {
					$needle      = AvandWP_Text_Helper::normalize_persian( $f['keyword_stats']['keyword'] );
					$has_keyword = ( '' !== $needle && false !== strpos( AvandWP_Text_Helper::normalize_persian( $title ), $needle ) );
				}

				if ( $len < 15 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'عنوان %s کاراکتری.', self::n( $len ) ),
						'عنوان بسیار کوتاه است و موضوع را به‌روشنی بیان نمی‌کند.',
						'عنوان توصیفی‌تری بنویسید.'
					);
				}

				if ( $len > 70 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'عنوان %s کاراکتری.', self::n( $len ) ),
						'عنوان بلندتر از حد نمایش معمول در نتایج جستجو است.',
						'عنوان را کوتاه‌تر کنید و بخش توضیحی را به توضیحات متا منتقل کنید.'
					);
				}

				if ( ! $has_keyword ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'عنوان %s کاراکتری، بدون کلمه کلیدی کانونی.', self::n( $len ) ),
						'کلمه کلیدی کانونی در عنوان دیده نمی‌شود.',
						'کلمه کلیدی را به‌صورت طبیعی در عنوان جای دهید.'
					);
				}

				return self::outcome( self::PASS, sprintf( 'عنوان %s کاراکتری شامل کلمه کلیدی.', self::n( $len ) ), 'عنوان معتبر و هدفمند است.', '' );
			}
		);

		$c['seo.external_links'] = self::criterion(
			'seo.external_links',
			'seo',
			'منابع و لینک‌های خارجی',
			5,
			'medium',
			true,
			'وجود و تنوع دامنه منابع بیرونی مورد استناد.',
			static function ( array $f ) {
				$external = (int) $f['link_stats']['external'];
				$hosts    = (int) $f['link_stats']['unique_hosts'];

				if ( $external >= 2 && $hosts >= 2 ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s لینک خارجی از %s دامنه متفاوت.', self::n( $external ), self::n( $hosts ) ),
						'متن به منابع بیرونی متنوع استناد می‌کند.',
						''
					);
				}

				if ( $external >= 1 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s لینک خارجی از %s دامنه.', self::n( $external ), self::n( $hosts ) ),
						'استناد به منابع بیرونی محدود است.',
						'برای ادعاهای مهم، منبع بیرونی معتبرتری اضافه کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ لینک خارجی در متن وجود ندارد.',
					'ادعاهای بدون منبع، هم برای خواننده و هم برای سیستم‌های مولد کم‌اعتبارترند.',
					'برای آمارها و ادعاهای کلیدی، منبع بیرونی معتبر اضافه کنید.'
				);
			}
		);

		$c['seo.anchor_quality'] = self::criterion(
			'seo.anchor_quality',
			'seo',
			'کیفیت متن لینک‌ها',
			5,
			'medium',
			true,
			'نبود متن لینک عمومی و نبود تکرار یکسان متن لینک.',
			static function ( array $f ) {
				$stats = $f['link_stats'];

				if ( 0 === (int) $stats['total'] ) {
					return self::outcome( self::UNAVAILABLE, 'لینکی در متن وجود ندارد.', 'این معیار بدون لینک قابل ارزیابی نیست.', '' );
				}

				$generic  = (int) $stats['generic_anchors'];
				$repeated = count( (array) $stats['repeated_anchors'] );

				if ( 0 === $generic && 0 === $repeated ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s لینک با متن اختصاصی.', self::n( $stats['total'] ) ),
						'متن لینک‌ها توصیفی است و به خواننده و موتور جستجو اطلاعات می‌دهد.',
						''
					);
				}

				$parts = array();
				if ( $generic > 0 ) {
					$parts[] = sprintf( '%s لینک با متن عمومی («اینجا»، «بیشتر»)', self::n( $generic ) );
				}
				if ( $repeated > 0 ) {
					$parts[] = sprintf( '%s متن لینک تکراری', self::n( $repeated ) );
				}

				return self::outcome(
					self::PARTIAL,
					implode( '، ', $parts ),
					'متن لینک عمومی یا تکراری، ارزش معنایی لینک را کاهش می‌دهد.',
					'متن لینک را با عبارت توصیفیِ مرتبط با مقصد بازنویسی کنید.'
				);
			}
		);

		$c['seo.secondary_keywords'] = self::criterion(
			'seo.secondary_keywords',
			'seo',
			'پوشش کلیدواژه‌های فرعی',
			4,
			'medium',
			false,
			'نسبت کلیدواژه‌های فرعی و موجودیت‌های معنایی حاضر در متن.',
			static function ( array $f ) {
				$secondary = $f['secondary_coverage'];
				$total     = count( $secondary['present'] ) + count( $secondary['missing'] );

				if ( 0 === $total ) {
					return self::outcome(
						self::UNAVAILABLE,
						'کلیدواژه فرعی برای این محتوا تعیین نشده است.',
						'بدون فهرست کلیدواژه‌های فرعی، پوشش موضوعی قابل سنجش نیست.',
						'کلیدواژه‌ها یا نهادهای معنایی مرتبط را در بریف وارد کنید.'
					);
				}

				$ratio  = (float) $secondary['ratio'];
				$result = $ratio >= 0.6 ? self::PASS : ( $ratio >= 0.3 ? self::PARTIAL : self::FAIL );

				return self::outcome(
					$result,
					sprintf( '%s از %s کلیدواژه فرعی در متن حاضر است (%.0f%%).', self::n( count( $secondary['present'] ) ), self::n( $total ), $ratio * 100 ),
					'پوشش موضوعی گسترده‌تر به موتور جستجو نشان می‌دهد متن جامع است.',
					self::PARTIAL === $result || self::FAIL === $result
						? sprintf( 'موارد غایب: %s', implode( '، ', array_slice( $secondary['missing'], 0, 6 ) ) )
						: ''
				);
			}
		);

		$c['seo.entity_coverage'] = self::criterion(
			'seo.entity_coverage',
			'seo',
			'پوشش نهادها و اصطلاحات',
			4,
			'medium',
			false,
			'حضور نهادهای معنایی اعلام‌شده در متن.',
			static function ( array $f ) {
				$coverage = $f['entity_coverage'];
				$total    = count( $coverage['present'] ) + count( $coverage['missing'] );

				if ( 0 === $total ) {
					return self::outcome(
						self::UNAVAILABLE,
						'نهاد یا اصطلاح خاصی برای بررسی تعیین نشده است.',
						'بدون فهرست نهادها، پوشش معنایی قابل سنجش نیست.',
						'نهادهای اصلی موضوع (برند، فناوری، مفهوم) را در بریف وارد کنید.'
					);
				}

				$ratio = (float) $coverage['ratio'];

				return self::outcome(
					$ratio >= 0.5 ? self::PASS : ( $ratio > 0 ? self::PARTIAL : self::FAIL ),
					sprintf( '%s از %s نهاد ذکرشده در متن آمده است.', self::n( count( $coverage['present'] ) ), self::n( $total ) ),
					'حضور نهادها به موتور جستجو کمک می‌کند موضوع متن را دقیق‌تر دسته‌بندی کند.',
					$ratio >= 0.5 ? '' : sprintf( 'نهادهای غایب: %s', implode( '، ', array_slice( $coverage['missing'], 0, 6 ) ) )
				);
			}
		);

		$c['seo.structure_blocks'] = self::criterion(
			'seo.structure_blocks',
			'seo',
			'خوانایی ساختاری (لیست و جدول)',
			4,
			'low',
			false,
			'وجود عناصر اسکن‌پذیر در محتوای بلند.',
			static function ( array $f ) {
				$words = (int) $f['measurement']['words'];

				if ( $words < 500 ) {
					return self::outcome( self::UNAVAILABLE, sprintf( 'متن %s کلمه است.', self::n( $words ) ), 'برای متون کوتاه، عناصر ساختاری الزامی نیست.', '' );
				}

				$blocks = $f['blocks'];
				$mixed  = (int) $blocks['lists'] + (int) $blocks['tables'];

				if ( $mixed >= 2 ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s لیست و %s جدول.', self::n( $blocks['lists'] ), self::n( $blocks['tables'] ) ),
						'محتوا عناصر اسکن‌پذیر دارد.',
						''
					);
				}

				if ( 1 === $mixed ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'تنها %s عنصر لیست/جدول.', self::n( $mixed ) ),
						'تنوع عناصر ساختاری کم است.',
						'مراحل، ویژگی‌ها یا مقایسه‌ها را به لیست یا جدول تبدیل کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ لیست یا جدولی در متن وجود ندارد.',
					'متون بلند بدون عناصر ساختاری برای خواننده خسته‌کننده و برای استخراج اطلاعات دشوارند.',
					'حداقل یک لیست یا جدول مقایسه‌ای به متن اضافه کنید.'
				);
			}
		);

		$c['seo.repetition'] = self::criterion(
			'seo.repetition',
			'seo',
			'تکرار و محتوای تکراری',
			4,
			'medium',
			true,
			'نسبت جملات تکراری و تکرار قطعات پنج‌کلمه‌ای.',
			static function ( array $f ) {
				$rep = $f['repetition'];
				$dup = count( (array) $rep['duplicate_sentences'] );
				$ng  = count( (array) $rep['repeated_ngrams'] );

				if ( 0 === $dup && 0 === $ng ) {
					return self::outcome( self::PASS, 'هیچ جمله یا قطعه تکراری یافت نشد.', 'متن از تکرار ماشینی پاک است.', '' );
				}

				if ( $dup <= 1 && $ng <= 3 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s جمله تکراری و %s قطعه پنج‌کلمه‌ای پرتکرار.', self::n( $dup ), self::n( $ng ) ),
						'تکرار محدود است اما قابل کاهش.',
						'جملات تکراری را ادغام یا حذف کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					sprintf( '%s جمله تکراری و %s قطعه پنج‌کلمه‌ای پرتکرار.', self::n( $dup ), self::n( $ng ) ),
					'تکرار زیاد، متن را کم‌ارزش و ماشینی جلوه می‌دهد.',
					'بخش‌های تکراری را بازنویسی یا حذف کنید.'
				);
			}
		);

		$c['seo.paragraph_length'] = self::criterion(
			'seo.paragraph_length',
			'seo',
			'طول پاراگراف‌ها',
			3,
			'low',
			false,
			'میانگین طول پاراگراف‌ها.',
			static function ( array $f ) {
				$paragraphs = (int) $f['blocks']['paragraphs'];
				$words      = (int) $f['measurement']['words'];

				if ( $paragraphs < 2 || $words < 200 ) {
					return self::outcome( self::UNAVAILABLE, 'پاراگراف کافی برای سنجش وجود ندارد.', 'این معیار برای متون کوتاه ارزیابی نمی‌شود.', '' );
				}

				$average = (int) round( $words / $paragraphs );

				if ( $average <= 130 ) {
					return self::outcome( self::PASS, sprintf( 'میانگین %s کلمه در هر پاراگراف.', self::n( $average ) ), 'پاراگراف‌ها برای خواندن در وب مناسب‌اند.', '' );
				}

				if ( $average <= 200 ) {
					return self::outcome( self::PARTIAL, sprintf( 'میانگین %s کلمه در هر پاراگراف.', self::n( $average ) ), 'پاراگراف‌ها بلندتر از حد راحت در وب هستند.', 'پاراگراف‌های بلند را به دو بخش تقسیم کنید.' );
				}

				return self::outcome( self::FAIL, sprintf( 'میانگین %s کلمه در هر پاراگراف.', self::n( $average ) ), 'پاراگراف‌های بسیار بلند خوانایی وب را کاهش می‌دهند.', 'پاراگراف‌ها را به بلوک‌های ۲ تا ۴ جمله‌ای تقسیم کنید.' );
			}
		);

		$c['seo.keyword_stuffing'] = self::criterion(
			'seo.keyword_stuffing',
			'seo',
			'نبود انباشت کلمه کلیدی',
			3,
			'medium',
			true,
			'نسبت تکرار کلمه کلیدی به طول متن.',
			static function ( array $f ) {
				$stats = $f['keyword_stats'];

				if ( empty( $stats['available'] ) ) {
					return self::outcome( self::UNAVAILABLE, 'کلمه کلیدی کانونی تعیین نشده است.', 'این معیار بدون کلمه کلیدی قابل ارزیابی نیست.', '' );
				}

				if ( empty( $stats['stuffed'] ) ) {
					return self::outcome(
						self::PASS,
						sprintf( 'چگالی %s%% که در محدوده طبیعی است.', self::n( $stats['density_percent'] ) ),
						'استفاده از کلمه کلیدی طبیعی است.',
						''
					);
				}

				return self::outcome(
					self::FAIL,
					sprintf( 'چگالی %s%% برای «%s».', self::n( $stats['density_percent'] ), $stats['keyword'] ),
					'انباشت کلمه کلیدی هم برای خواننده آزاردهنده است و هم سیگنالی منفی برای موتور جستجو.',
					'بخشی از تکرارها را حذف یا با مترادف جایگزین کنید.'
				);
			}
		);

		$c['seo.slug'] = self::criterion(
			'seo.slug',
			'seo',
			'نشانی صفحه (slug)',
			3,
			'low',
			false,
			'طول و ساختار شناسه یکتای صفحه.',
			static function ( array $f ) {
				$slug = trim( (string) $f['slug'] );

				if ( '' === $slug ) {
					return self::outcome( self::UNAVAILABLE, 'برای این محتوا نشانی مشخصی ثبت نشده است.', 'پیش از انتشار می‌توان آن را تعیین کرد.', 'پیش از انتشار، slug کوتاه و توصیفی تعیین کنید.' );
				}

				$segments = count( array_filter( explode( '-', $slug ) ) );

				if ( preg_match( '/^\d+$/', $slug ) ) {
					return self::outcome( self::FAIL, sprintf( 'slug فقط عددی است: %s', $slug ), 'نشانی عددی به کاربر و موتور جستجو درباره محتوا چیزی نمی‌گوید.', 'نشانی توصیفی شامل کلمه کلیدی اصلی تعیین کنید.' );
				}

				if ( $segments > 7 || AvandWP_Text_Helper::length( $slug ) > 70 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s بخش در نشانی.', self::n( $segments ) ), 'نشانی بلندتر از حد لازم است.', 'نشانی را به ۳ تا ۵ کلمه کلیدی کوتاه کنید.' );
				}

				return self::outcome( self::PASS, sprintf( 'نشانی %s با %s بخش.', $slug, self::n( $segments ) ), 'نشانی کوتاه و توصیفی است.', '' );
			}
		);

		$c['seo.schema'] = self::criterion(
			'seo.schema',
			'seo',
			'داده ساختاریافته (Schema)',
			4,
			'medium',
			false,
			'وجود داده ساختاریافته مرتبط با نوع محتوا.',
			static function ( array $f ) {
				if ( null === $f['has_schema'] ) {
					return self::outcome(
						self::UNAVAILABLE,
						'وضعیت داده ساختاریافته سایت در دسترس نیست.',
						'برای داوری این معیار باید افزونه سئو فعال و وضعیت اسکیما خوانده شود.',
						'در بخش «سازگاری» افزونه سئوی فعال را بررسی کنید.'
					);
				}

				if ( ! empty( $f['has_schema'] ) ) {
					return self::outcome( self::PASS, 'داده ساختاریافته برای این نوع محتوا تولید می‌شود.', 'اسکیما به موتور جستجو در درک نوع محتوا کمک می‌کند.', '' );
				}

				return self::outcome(
					self::FAIL,
					'هیچ داده ساختاریافته‌ای برای این محتوا تولید نمی‌شود.',
					'بدون اسکیما، شانس نمایش غنی و درک ماشینی محتوا کاهش می‌یابد.',
					'یک افزونه سئو با پشتیبانی اسکیما فعال کنید تا خروجی تکراری ایجاد نشود.'
				);
			}
		);

		$c['seo.freshness'] = self::criterion(
			'seo.freshness',
			'seo',
			'تازگی محتوا',
			3,
			'low',
			false,
			'فاصله زمانی آخرین به‌روزرسانی محتوا.',
			static function ( array $f ) {
				$updated = $f['updated_date'] ?? null;

				if ( empty( $updated ) ) {
					return self::outcome( self::UNAVAILABLE, 'تاریخ آخرین به‌روزرسانی در دسترس نیست.', 'برای محتوای منتشرنشده، تازگی معنا ندارد.', '' );
				}

				$timestamp = strtotime( (string) $updated );
				if ( false === $timestamp ) {
					return self::outcome( self::UNAVAILABLE, 'تاریخ آخرین به‌روزرسانی قابل تفسیر نبود.', 'فرمت تاریخ نامعتبر است.', '' );
				}

				$months = (int) floor( ( time() - $timestamp ) / ( 30 * DAY_IN_SECONDS ) );

				if ( $months <= 12 ) {
					return self::outcome( self::PASS, sprintf( 'آخرین به‌روزرسانی حدود %s ماه پیش.', self::n( $months ) ), 'محتوا به‌روز است.', '' );
				}

				if ( $months <= 24 ) {
					return self::outcome( self::PARTIAL, sprintf( 'آخرین به‌روزرسانی حدود %s ماه پیش.', self::n( $months ) ), 'محتوا به بازبینی نیاز دارد.', 'اطلاعات دارای عمر کوتاه (قیمت، نسخه، آمار) را به‌روز کنید.' );
				}

				return self::outcome( self::FAIL, sprintf( 'آخرین به‌روزرسانی حدود %s ماه پیش.', self::n( $months ) ), 'محتوای قدیمی ممکن است اطلاعات نادرست داشته باشد.', 'محتوا را بازبینی و تاریخ به‌روزرسانی را ثبت کنید.' );
			}
		);

		$c['seo.accessibility'] = self::criterion(
			'seo.accessibility',
			'seo',
			'کیفیت محتوایی مرتبط با دسترس‌پذیری',
			3,
			'medium',
			true,
			'متن جانشین تصاویر، ترتیب عنوان‌ها و کاربرد لینک‌های توصیفی.',
			static function ( array $f ) {
				$problems = array();

				if ( (int) $f['image_stats']['missing_alt'] > 0 ) {
					$problems[] = sprintf( '%s تصویر بدون متن جانشین', self::n( $f['image_stats']['missing_alt'] ) );
				}
				if ( (int) $f['heading_hierarchy']['skips'] > 0 ) {
					$problems[] = sprintf( '%s پرش سطح عنوان', self::n( $f['heading_hierarchy']['skips'] ) );
				}
				if ( (int) $f['link_stats']['generic_anchors'] > 0 ) {
					$problems[] = sprintf( '%s لینک با متن غیرتوصیفی', self::n( $f['link_stats']['generic_anchors'] ) );
				}
				if ( 0 === (int) $f['blocks']['paragraphs'] && (int) $f['measurement']['words'] > 150 ) {
					$problems[] = 'متن بدون پاراگراف‌بندی';
				}

				if ( empty( $problems ) ) {
					return self::outcome( self::PASS, 'هیچ مشکل محتوایی مرتبط با دسترس‌پذیری یافت نشد.', 'ساختار متن برای صفحه‌خوان و ناوبری صفحه‌کلید مناسب است.', '' );
				}

				return self::outcome(
					self::PARTIAL,
					implode( '، ', $problems ),
					'این موارد بر تجربه کاربران دارای ابزار کمکی اثر می‌گذارند.',
					'موارد بالا را برطرف کنید؛ متن جانشین، ترتیب عنوان‌ها و متن لینک توصیفی.'
				);
			}
		);

		return $c;
	}

	/* ======================================================================
	 * AIO profile
	 * ==================================================================== */

	/**
	 * Answer-readiness criteria.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function aio_criteria() {
		$c = array();

		$c['aio.direct_answer'] = self::criterion(
			'aio.direct_answer',
			'aio',
			'پاسخ مستقیم در آغاز متن',
			9,
			'high',
			false,
			'کیفیت پاراگراف نخست: وجود پاسخ یا تعریف روشن و نه مقدمه کلی‌گو.',
			static function ( array $f ) {
				$first = trim( (string) $f['first_paragraph'] );

				if ( '' === $first ) {
					return self::outcome( self::FAIL, 'پاراگراف نخستی در متن یافت نشد.', 'نبود پاراگراف آغازین، درک سریع موضوع را ناممکن می‌کند.', 'با یک پاراگراف پاسخ‌محور آغاز کنید که موضوع را در دو جمله روشن کند.' );
				}

				$words = AvandWP_Text_Helper::word_count( $first );

				// Generic openers add words without adding information.
				$generic = preg_match(
					'/(در دنیای امروز|امروزه با پیشرفت|در عصر حاضر|همان‌طور که می‌دانید|بدون شک|شاید برای شما هم پیش آمده)/u',
					$first
				);

				if ( $generic ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'پاراگراف نخست با عبارت کلیشه‌ای آغاز شده است: «%s»', AvandWP_Text_Helper::safe_substr( $first, 0, 70 ) ),
						'مقدمه‌های کلیشه‌ای اطلاعاتی به خواننده یا به سیستم مولد اضافه نمی‌کنند و پاسخ را به تأخیر می‌اندازند.',
						'پاراگراف نخست را با پاسخ مستقیم یا تعریف موضوع بازنویسی کنید.'
					);
				}

				if ( $words >= 18 && $words <= 130 ) {
					return self::outcome(
						self::PASS,
						sprintf( 'پاراگراف نخست %s کلمه و بدون عبارت کلیشه‌ای است.', self::n( $words ) ),
						'متن با یک بخش آغازین روشن و پاسخ‌محور شروع می‌شود.',
						''
					);
				}

				return self::outcome(
					self::PARTIAL,
					sprintf( 'پاراگراف نخست %s کلمه است.', self::n( $words ) ),
					'طول بخش آغازین برای یک پاسخ کوتاه و صریح مناسب نیست.',
					$words < 18 ? 'پاراگراف نخست را با یک جمله کامل توضیحی گسترش دهید.' : 'پاراگراف نخست را کوتاه‌تر و مستقیم‌تر کنید.'
				);
			}
		);

		$c['aio.definitions'] = self::criterion(
			'aio.definitions',
			'aio',
			'تعریف صریح مفاهیم کلیدی',
			8,
			'high',
			false,
			'وجود جمله‌های تعریفی («به معنی»، «یعنی»، «عبارت است از»).',
			static function ( array $f ) {
				$count = count( (array) $f['definitions'] );

				if ( $count >= 2 ) {
					return self::outcome( self::PASS, sprintf( '%s جمله تعریفی صریح.', self::n( $count ) ), 'مفاهیم کلیدی مستقیماً تعریف شده‌اند و برای استخراج پاسخ مناسب‌اند.', '' );
				}

				if ( 1 === $count ) {
					return self::outcome( self::PARTIAL, 'تنها یک جمله تعریفی صریح یافت شد.', 'تعریف تنها یکی از مفاهیم کلیدی کافی نیست.', 'برای سایر مفاهیم اصلی مقاله نیز یک جمله تعریف صریح بنویسید.' );
				}

				return self::outcome(
					self::FAIL,
					'هیچ جمله تعریفی صریح در متن یافت نشد.',
					'بدون تعریف صریح، متن برای پاسخ‌گویی مستقیم به پرسش «X چیست؟» مناسب نیست.',
					'برای هر مفهوم کلیدی یک جمله با ساختار «X به معنی … است» بنویسید.'
				);
			}
		);

		$c['aio.question_coverage'] = self::criterion(
			'aio.question_coverage',
			'aio',
			'پوشش پرسش‌های کاربران',
			8,
			'high',
			false,
			'تعداد عنوان‌ها یا جمله‌های پرسشی و بخش پرسش‌های متداول.',
			static function ( array $f ) {
				$questions = $f['questions'];
				$headings  = count( (array) $questions['heading_questions'] );
				$total     = (int) $questions['count'];
				$words     = (int) $f['measurement']['words'];

				if ( $total >= 4 || ( $words >= 1200 && $headings >= 2 ) ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s عنوان پرسشی و %s پرسش در متن.', self::n( $headings ), self::n( $total ) ),
						'متن به پرسش‌های واقعی کاربران پاسخ می‌دهد.',
						''
					);
				}

				if ( $total >= 1 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s عنوان پرسشی و %s پرسش در متن.', self::n( $headings ), self::n( $total ) ),
						'پوشش پرسش‌ها محدود است.',
						'برای هر پرسش پرتکرار مخاطب، یک بخش پرسشی با پاسخ مستقیم اضافه کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ عنوان یا جمله پرسشی در متن یافت نشد.',
					'متن پرسش‌محور نوشته نشده و احتمال انتخاب آن به‌عنوان پاسخ کاهش می‌یابد.',
					'بخش «پرسش‌های متداول» با پرسش‌های واقعی مخاطب اضافه کنید.'
				);
			}
		);

		$c['aio.self_contained_sections'] = self::criterion(
			'aio.self_contained_sections',
			'aio',
			'بخش‌های خودبسنده',
			6,
			'medium',
			false,
			'توزیع متن میان بخش‌ها؛ بخش‌های بسیار کوتاه یا بسیار بلند خودبسنده نیستند.',
			static function ( array $f ) {
				$headings = $f['headings'];
				$words    = (int) $f['measurement']['words'];

				if ( count( $headings ) < 3 || $words < 500 ) {
					return self::outcome( self::UNAVAILABLE, sprintf( '%s عنوان و %s کلمه.', self::n( count( $headings ) ), self::n( $words ) ), 'برای ارزیابی خودبسندگی بخش‌ها، متن باید ساختار کافی داشته باشد.', '' );
				}

				$average = (int) round( $words / max( 1, count( $headings ) ) );
				$thin    = $average < 60;

				if ( $average >= 80 && $average <= 400 ) {
					return self::outcome( self::PASS, sprintf( 'میانگین %s کلمه برای هر عنوان.', self::n( $average ) ), 'هر بخش به‌تنهایی به پرسش مرتبط خود پاسخ می‌دهد.', '' );
				}

				return self::outcome(
					self::PARTIAL,
					sprintf( 'میانگین %s کلمه برای هر عنوان.', self::n( $average ) ),
					$thin ? 'برخی بخش‌ها برای پاسخ کامل کوتاه‌اند.' : 'برخی بخش‌ها برای یک پاسخ مستقل بسیار بلندند.',
					$thin ? 'به بخش‌های کوتاه، توضیح یا مثال اضافه کنید.' : 'بخش‌های بلند را به زیربخش‌های H3 تقسیم کنید.'
				);
			}
		);

		$c['aio.consistency'] = self::criterion(
			'aio.consistency',
			'aio',
			'انسجام و نبود تناقض ظاهری',
			6,
			'high',
			false,
			'نبود تکرار جملات و نبود عبارات تردیدآمیز که پیام را مبهم می‌کنند.',
			static function ( array $f ) {
				$dup     = count( (array) $f['repetition']['duplicate_sentences'] );
				$hedging = (int) $f['hedging']['total'];

				if ( 0 === $dup && $hedging <= 2 ) {
					return self::outcome( self::PASS, sprintf( '%s جمله تکراری و %s عبارت تردیدآمیز.', self::n( $dup ), self::n( $hedging ) ), 'متن یکدست و قاطع است.', '' );
				}

				$parts = array();
				if ( $dup > 0 ) {
					$parts[] = sprintf( '%s جمله تکراری', self::n( $dup ) );
				}
				if ( $hedging > 2 ) {
					$parts[] = sprintf( '%s عبارت تردیدآمیز («%s»)', self::n( $hedging ), implode( '، ', array_slice( (array) $f['hedging']['samples'], 0, 3 ) ) );
				}

				return self::outcome(
					$dup > 2 || $hedging > 6 ? self::FAIL : self::PARTIAL,
					implode( '، ', $parts ),
					'تکرار و تردید زیاد، اعتبار متن را برای پاسخ‌دهی ماشینی کاهش می‌دهد.',
					'جملات تکراری را ادغام و عبارات تردیدآمیز را با بیان روشن جایگزین کنید.'
				);
			}
		);

		$c['aio.evidence_backed'] = self::criterion(
			'aio.evidence_backed',
			'aio',
			'پشتوانه داده‌ای برای ادعاها',
			6,
			'high',
			true,
			'حضور داده‌های مشخص (عدد، درصد، تاریخ) و نشانگرهای استناد.',
			static function ( array $f ) {
				$quantities = (int) $f['quantities']['total'];
				$citations  = (int) $f['citations']['total'];
				$words      = max( 1, (int) $f['measurement']['words'] );

				// Expected density scales with length: a long article needs more
				// concrete facts than a short one to avoid being generic.
				$expected = max( 2, (int) round( $words / 400 ) );

				if ( $quantities >= $expected && ( $citations >= 1 || $f['link_stats']['external'] >= 1 ) ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s داده مشخص و %s نشانگر استناد.', self::n( $quantities ), self::n( $citations ) ),
						'ادعاها با داده و منبع پشتیبانی شده‌اند.',
						''
					);
				}

				if ( $quantities >= 1 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s داده مشخص (انتظار برای این طول: %s) و %s نشانگر استناد.', self::n( $quantities ), self::n( $expected ), self::n( $citations ) ),
						'متن داده دارد اما برای ادعاهای کلیدی منبع یا عدد کافی نیست.',
						'برای هر ادعای مهم، عدد، تاریخ یا منبع مشخص اضافه کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ عدد، درصد یا داده مشخصی در متن یافت نشد.',
					'متنی که فقط کلیات می‌گوید، برای پاسخ‌دهی و برای استناد ارزشی ندارد.',
					'آمار، اعداد یا مثال‌های مشخص (با ذکر منبع) به متن اضافه کنید.'
				);
			}
		);

		$c['aio.scannable_answers'] = self::criterion(
			'aio.scannable_answers',
			'aio',
			'پاسخ‌های کوتاه و قابل استخراج',
			5,
			'medium',
			false,
			'میانگین طول جمله‌ها؛ جمله‌های بسیار بلند برای استخراج پاسخ مناسب نیستند.',
			static function ( array $f ) {
				$sentences = (int) $f['measurement']['sentences'];
				$words     = (int) $f['measurement']['words'];

				if ( $sentences < 5 ) {
					return self::outcome( self::UNAVAILABLE, 'جمله کافی برای سنجش وجود ندارد.', 'این معیار برای متن‌های بسیار کوتاه ارزیابی نمی‌شود.', '' );
				}

				$average = (int) round( $words / $sentences );

				if ( $average <= 30 ) {
					return self::outcome( self::PASS, sprintf( 'میانگین %s کلمه در هر جمله.', self::n( $average ) ), 'جمله‌ها کوتاه و قابل استخراج هستند.', '' );
				}

				if ( $average <= 45 ) {
					return self::outcome( self::PARTIAL, sprintf( 'میانگین %s کلمه در هر جمله.', self::n( $average ) ), 'جمله‌ها بلندتر از حد مناسب برای استخراج پاسخ هستند.', 'جمله‌های بلند را به دو جمله کوتاه‌تر تقسیم کنید.' );
				}

				return self::outcome( self::FAIL, sprintf( 'میانگین %s کلمه در هر جمله.', self::n( $average ) ), 'جمله‌های بسیار بلند، پاسخ را برای خواننده و برای استخراج ماشینی مبهم می‌کنند.', 'جمله‌ها را کوتاه و هر جمله را به یک ایده محدود کنید.' );
			}
		);

		$c['aio.comparison_assets'] = self::criterion(
			'aio.comparison_assets',
			'aio',
			'جدول مقایسه یا فهرست گام‌به‌گام',
			5,
			'medium',
			false,
			'وجود جدول یا فهرست ساختاریافته در متن.',
			static function ( array $f ) {
				$tables = (int) $f['blocks']['tables'];
				$lists  = (int) $f['blocks']['lists'];
				$words  = (int) $f['measurement']['words'];

				if ( $words < 400 ) {
					return self::outcome( self::UNAVAILABLE, sprintf( 'متن %s کلمه است.', self::n( $words ) ), 'برای متون کوتاه این معیار ارزیابی نمی‌شود.', '' );
				}

				if ( $tables >= 1 || $lists >= 2 ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s جدول و %s لیست.', self::n( $tables ), self::n( $lists ) ),
						'محتوا ساختار قابل استخراج دارد.',
						''
					);
				}

				if ( $lists >= 1 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s لیست و بدون جدول.', self::n( $lists ) ), 'ساختار موجود است اما جدول مقایسه‌ای وجود ندارد.', 'اگر موضوع مقایسه‌ای است، یک جدول مقایسه اضافه کنید.' );
				}

				return self::outcome(
					self::FAIL,
					'هیچ جدول یا لیستی در متن وجود ندارد.',
					'جدول و لیست، پاسخ‌ها را برای سیستم‌های مولد قابل استخراج‌تر می‌کنند.',
					'حداقل یک لیست یا جدول ساختاریافته اضافه کنید.'
				);
			}
		);

		$c['aio.attribution'] = self::criterion(
			'aio.attribution',
			'aio',
			'شفافیت نویسنده و مرجعیت',
			5,
			'medium',
			false,
			'وجود اطلاعات نویسنده و نشانگرهای استناد.',
			static function ( array $f ) {
				$has_author = '' !== trim( (string) $f['author'] );
				$citations  = (int) $f['citations']['total'];

				if ( $has_author && $citations >= 1 ) {
					return self::outcome( self::PASS, sprintf( 'نویسنده مشخص است و %s نشانگر استناد وجود دارد.', self::n( $citations ) ), 'متن دارای مرجعیت مشخص و منبع است.', '' );
				}

				if ( $has_author ) {
					return self::outcome( self::PARTIAL, 'نویسنده مشخص است اما نشانگر استناد در متن کم است.', 'شفافیت بیشتر با ذکر منبع افزایش می‌یابد.', 'برای ادعاهای مهم، منبع یا مرجع مشخص اضافه کنید.' );
				}

				return self::outcome(
					self::FAIL,
					'اطلاعات نویسنده در محتوا ثبت نشده است.',
					'ناشناس‌بودن نویسنده، اعتبار متن را برای کاربر و برای سیستم مولد کاهش می‌دهد.',
					'نویسنده یا کارشناس بازبین را در محتوا مشخص کنید.'
				);
			}
		);

		$c['aio.vague_language'] = self::criterion(
			'aio.vague_language',
			'aio',
			'نبود گفتار مبهم و کلی',
			4,
			'medium',
			true,
			'حضور عبارت‌های تردیدآمیز یا ادعاهای بدون پشتوانه.',
			static function ( array $f ) {
				$hedging = (int) $f['hedging']['total'];

				if ( 0 === $hedging ) {
					return self::outcome( self::PASS, 'عبارت مبهم یا تردیدآمیزی یافت نشد.', 'متن با اطمینان و روشنی نوشته شده است.', '' );
				}

				if ( $hedging <= 2 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s عبارت مبهم: %s', self::n( $hedging ), implode( '، ', (array) $f['hedging']['samples'] ) ), 'حضور محدود عبارات مبهم.', 'عبارت‌های مبهم را با بیان صریح جایگزین کنید.' );
				}

				return self::outcome(
					self::FAIL,
					sprintf( '%s عبارت مبهم: %s', self::n( $hedging ), implode( '، ', array_slice( (array) $f['hedging']['samples'], 0, 5 ) ) ),
					'تکرار عبارات مبهم، متن را برای پاسخ‌دهی دقیق بی‌اعتبار می‌کند.',
					'هر ادعا را یا با منبع مستند کنید یا به شکل محدود و دقیق بیان کنید.'
				);
			}
		);

		return $c;
	}

	/* ======================================================================
	 * GEO profile
	 * ==================================================================== */

	/**
	 * Generative-engine citation readiness criteria.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function geo_criteria() {
		$c = array();

		$c['geo.factual_specificity'] = self::criterion(
			'geo.factual_specificity',
			'geo',
			'داده‌های مشخص و نقل‌پذیر',
			9,
			'high',
			true,
			'تراکم عدد، درصد، تاریخ و واحد نسبت به طول متن.',
			static function ( array $f ) {
				$quantities = (int) $f['quantities']['total'];
				$words      = max( 1, (int) $f['measurement']['words'] );
				$expected   = max( 3, (int) round( $words / 300 ) );

				if ( $quantities >= $expected ) {
					return self::outcome(
						self::PASS,
						sprintf( '%s داده مشخص در %s کلمه (نمونه: %s).', self::n( $quantities ), self::n( $words ), implode( '، ', array_slice( (array) $f['quantities']['samples'], 0, 5 ) ) ),
						'متن شامل داده‌های مشخص و قابل نقل‌قول است.',
						''
					);
				}

				if ( $quantities >= 1 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( '%s داده مشخص؛ انتظار برای این طول: %s.', self::n( $quantities ), self::n( $expected ) ),
						'داده‌ها کم هستند و متن بیشتر کلی‌گو است.',
						'برای هر بخش، حداقل یک عدد، تاریخ یا واحد مشخص اضافه کنید.'
					);
				}

				return self::outcome(
					self::FAIL,
					'هیچ داده مشخصی برای نقل‌قول وجود ندارد.',
					'سیستم‌های مولد به متن‌هایی استناد می‌کنند که حاوی داده قابل نقل باشد؛ متن کاملاً کلی نقل نمی‌شود.',
					'آمار، تاریخ، قیمت یا مقادیر مشخص و قابل صحت‌سنجی اضافه کنید.'
				);
			}
		);

		$c['geo.traceability'] = self::criterion(
			'geo.traceability',
			'geo',
			'قابلیت ردیابی منبع',
			8,
			'high',
			false,
			'وجود نشانگرهای استناد و تنوع دامنه‌های بیرونی.',
			static function ( array $f ) {
				$citations = (int) $f['citations']['total'];
				$hosts     = (int) $f['link_stats']['unique_hosts'];
				$external  = (int) $f['link_stats']['external'];

				if ( $citations >= 2 && $hosts >= 2 ) {
					return self::outcome( self::PASS, sprintf( '%s نشانگر استناد از %s دامنه.', self::n( $citations ), self::n( $hosts ) ), 'ادعاها قابل ردیابی به منبع هستند.', '' );
				}

				if ( $citations >= 1 || $external >= 2 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s نشانگر استناد و %s لینک خارجی.', self::n( $citations ), self::n( $external ) ), 'ردیابی منابع محدود است.', 'برای آمارها و ادعاهای کلیدی، لینک مستقیم به منبع اصلی اضافه کنید.' );
				}

				return self::outcome(
					self::FAIL,
					'هیچ نشانگر استناد یا لینک خارجی برای ردیابی وجود ندارد.',
					'متنی که منبع نمی‌دهد، توسط سیستم‌های مولد به‌سختی قابل تأیید است و کمتر استناد می‌شود.',
					'برای هر ادعای مهم، منبع اصلی را با لینک ذکر کنید.'
				);
			}
		);

		$c['geo.entity_clarity'] = self::criterion(
			'geo.entity_clarity',
			'geo',
			'وضوح نهادها و روابط میان مفاهیم',
			7,
			'high',
			false,
			'حضور نهادهای اعلام‌شده و جمله‌های تعریفی برای پیوند مفاهیم.',
			static function ( array $f ) {
				$coverage    = $f['entity_coverage'];
				$entityTotal = count( $coverage['present'] ) + count( $coverage['missing'] );
				$definitions = count( (array) $f['definitions'] );

				if ( 0 === $entityTotal ) {
					return self::outcome(
						self::UNAVAILABLE,
						'نهاد مشخصی برای بررسی تعیین نشده است.',
						'بدون فهرست نهادها، وضوح موجودیت‌ها سنجیده نمی‌شود.',
						'نهادهای اصلی موضوع را در بریف وارد کنید.'
					);
				}

				$ratio = (float) $coverage['ratio'];

				if ( $ratio >= 0.6 && $definitions >= 1 ) {
					return self::outcome( self::PASS, sprintf( 'پوشش نهادها %.0f%% و %s جمله تعریفی.', $ratio * 100, self::n( $definitions ) ), 'نهادها و روابط میان مفاهیم روشن هستند.', '' );
				}

				if ( $ratio >= 0.3 ) {
					return self::outcome(
						self::PARTIAL,
						sprintf( 'پوشش نهادها %.0f%% و %s جمله تعریفی.', $ratio * 100, self::n( $definitions ) ),
						'بخشی از نهادهای کلیدی موضوع در متن غایب است.',
						sprintf( 'نهادهای غایب را اضافه کنید: %s', implode( '، ', array_slice( $coverage['missing'], 0, 5 ) ) )
					);
				}

				return self::outcome(
					self::FAIL,
					sprintf( 'پوشش نهادها فقط %.0f%%.', $ratio * 100 ),
					'متن نهادهای اصلی موضوع را پوشش نمی‌دهد و تشخیص موضوع برای سیستم مولد دشوار است.',
					'نهادهای اصلی (نام محصول، فناوری، مفهوم) را به‌روشنی در متن بیاورید.'
				);
			}
		);

		$c['geo.qa_coverage'] = self::criterion(
			'geo.qa_coverage',
			'geo',
			'پوشش پرسش و پاسخ صریح',
			7,
			'high',
			false,
			'وجود بخش پرسش و پاسخ صریح در متن.',
			static function ( array $f ) {
				$questions = $f['questions'];
				$heading_q = count( (array) $questions['heading_questions'] );
				$text_q    = count( (array) $questions['text_questions'] );

				if ( $heading_q >= 2 && $text_q >= 1 ) {
					return self::outcome( self::PASS, sprintf( '%s عنوان پرسشی و %s پرسش در متن.', self::n( $heading_q ), self::n( $text_q ) ), 'ساختار پرسش و پاسخ صریح در متن وجود دارد.', '' );
				}

				if ( $heading_q + $text_q >= 1 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s عنوان پرسشی و %s پرسش.', self::n( $heading_q ), self::n( $text_q ) ), 'پوشش پرسش و پاسخ ناقص است.', 'بخش «پرسش‌های متداول» را با عنوان‌های پرسشی و پاسخ‌های کوتاه تکمیل کنید.' );
				}

				return self::outcome(
					self::FAIL,
					'هیچ ساختار پرسش و پاسخی در متن وجود ندارد.',
					'سیستم‌های مولد پاسخ‌ها را از ساختارهای صریح پرسش-پاسخ راحت‌تر استخراج می‌کنند.',
					'حداقل سه پرسش متداول واقعی با پاسخ مستقیم اضافه کنید.'
				);
			}
		);

		$c['geo.structured_data'] = self::criterion(
			'geo.structured_data',
			'geo',
			'دقت و حضور داده ساختاریافته',
			6,
			'medium',
			false,
			'وجود اسکیما و انطباق آن با نوع محتوا.',
			static function ( array $f ) {
				if ( null === $f['has_schema'] ) {
					return self::outcome( self::UNAVAILABLE, 'وضعیت داده ساختاریافته در دسترس نیست.', 'این معیار به خواندن وضعیت افزونه سئو نیاز دارد.', '' );
				}

				if ( ! empty( $f['has_schema'] ) ) {
					return self::outcome( self::PASS, 'داده ساختاریافته فعال است.', 'اسکیما به سیستم‌های مولد در تشخیص نوع محتوا کمک می‌کند.', '' );
				}

				return self::outcome( self::FAIL, 'داده ساختاریافته‌ای فعال نیست.', 'نبود اسکیما، تشخیص دقیق موجودیت و نوع محتوا را دشوار می‌کند.', 'اسکیمای مناسب (Article، FAQPage، Product) را فعال کنید.' );
			}
		);

		$c['geo.originality'] = self::criterion(
			'geo.originality',
			'geo',
			'سهم اصیل بودن در برابر متن عمومی',
			6,
			'medium',
			false,
			'ترکیب داده مشخص با نبود تکرار و نبود کلیشه آغازین.',
			static function ( array $f ) {
				$quantities = (int) $f['quantities']['total'];
				$duplicates = count( (array) $f['repetition']['duplicate_sentences'] );
				$hedging    = (int) $f['hedging']['total'];
				$first      = (string) $f['first_paragraph'];
				$generic_opener = preg_match( '/(در دنیای امروز|امروزه با پیشرفت|در عصر حاضر)/u', $first );

				$original_signals = ( $quantities >= 3 ? 1 : 0 ) + ( 0 === $duplicates ? 1 : 0 ) + ( $hedging <= 2 ? 1 : 0 ) + ( $generic_opener ? 0 : 1 );

				if ( 4 === $original_signals ) {
					return self::outcome( self::PASS, 'داده مشخص، بدون تکرار، بدون کلیشه آغازین.', 'متن سهم اصیل قابل توجهی دارد.', '' );
				}

				if ( $original_signals >= 2 ) {
					return self::outcome( self::PARTIAL, sprintf( '%s از ۴ نشانگر اصالت.', self::n( $original_signals ) ), 'متن بخشی از نشانگرهای اصالت را ندارد.', 'داده انحصاری (تجربه، آزمایش، عدد) و بازنویسی بخش‌های کلیشه‌ای را اضافه کنید.' );
				}

				return self::outcome( self::FAIL, sprintf( 'تنها %s از ۴ نشانگر اصالت.', self::n( $original_signals ) ), 'متن بیشتر عمومی و قابل بازتولید است.', 'اطلاعات یا تحلیل منحصر به فرد (آزمون، داده داخلی، مثال واقعی) اضافه کنید.' );
			}
		);

		$c['geo.transparency'] = self::criterion(
			'geo.transparency',
			'geo',
			'شفافیت سازمان و نویسنده',
			5,
			'medium',
			false,
			'وجود نویسنده مشخص و نشانگرهای استناد.',
			static function ( array $f ) {
				$has_author = '' !== trim( (string) $f['author'] );
				$citations  = (int) $f['citations']['total'];

				if ( $has_author && $citations >= 1 ) {
					return self::outcome( self::PASS, 'نویسنده و منبع مشخص است.', 'شفافیت نویسنده و منبع، شرط استنادپذیری است.', '' );
				}

				return self::outcome(
					self::FAIL,
					$has_author ? 'نویسنده مشخص است اما منبعی ذکر نشده.' : 'نویسنده مشخص نیست.',
					'سیستم‌های مولد به محتوای با مرجعیت مشخص‌تر بیشتر استناد می‌کنند.',
					'نویسنده/کارشناس و منابع اصلی را به‌روشنی ذکر کنید.'
				);
			}
		);

		$c['geo.crawlable_structure'] = self::criterion(
			'geo.crawlable_structure',
			'geo',
			'ساختار قابل خزش و خواندن',
			5,
			'low',
			false,
			'ساختار عنوان‌ها، پاراگراف‌بندی و نبود مانع متنی.',
			static function ( array $f ) {
				$problems = array();

				if ( empty( $f['heading_hierarchy']['has_h2'] ) ) {
					$problems[] = 'بدون عنوان H2';
				}
				if ( (int) $f['blocks']['paragraphs'] < 2 && (int) $f['measurement']['words'] > 200 ) {
					$problems[] = 'بدون پاراگراف‌بندی';
				}
				if ( (int) $f['measurement']['words'] < 150 ) {
					$problems[] = 'متن بسیار کوتاه';
				}

				if ( empty( $problems ) ) {
					return self::outcome( self::PASS, 'ساختار متن برای خزش و استخراج مناسب است.', 'ساختار قابل خواندن، پیش‌نیاز استناد است.', '' );
				}

				return self::outcome( self::PARTIAL, implode( '، ', $problems ), 'برخی ساختارها استخراج محتوا را دشوار می‌کنند.', 'عنوان‌بندی و پاراگراف‌بندی را اصلاح کنید.' );
			}
		);

		return $c;
	}
}
