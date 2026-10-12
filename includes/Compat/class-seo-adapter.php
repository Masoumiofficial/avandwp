<?php
/**
 * Third-party SEO plugin compatibility adapter.
 *
 * The 1.0.0 content engine wrote `_yoast_wpseo_*` AND `rank_math_*` post meta
 * unconditionally, on every generation, regardless of which plugin (if any) was
 * actually installed. That produces dead data in the wrong plugin's keys and —
 * if the site later migrates — stale metadata that silently overrides real
 * values.
 *
 * This adapter detects the active SEO plugin and writes **only** to that
 * plugin's own keys through its own conventions. When a plugin stores its data
 * in a place that cannot be written safely (AIOSEO 4 keeps SEO data in a custom
 * table, not post meta), the adapter reports that clearly instead of writing
 * meta that the plugin will ignore.
 *
 * The plugin never becomes a second SEO system: it does not register its own
 * title/description/canonical/sitemap output. If no SEO plugin is active it
 * reports that, and the user is told what the consequence is.
 *
 * @package AvandWP\Compat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO plugin detection and metadata bridge.
 */
class AvandWP_SEO_Adapter {

	/**
	 * Which SEO plugin is active.
	 *
	 * @return string `rankmath` | `yoast` | `aioseo` | `seopress` | `none`
	 */
	public static function active_plugin() {
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) || function_exists( 'YoastSEO' ) ) {
			return 'yoast';
		}

		if ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}

		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) {
			return 'seopress';
		}

		return 'none';
	}

	/**
	 * Human-readable name of the detected plugin.
	 *
	 * @return string
	 */
	public static function active_plugin_label() {
		$labels = array(
			'rankmath' => 'Rank Math',
			'yoast'    => 'Yoast SEO',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'none'     => 'هیچ افزونه سئویی فعال نیست',
		);

		return $labels[ self::active_plugin() ] ?? 'نامشخص';
	}

	/**
	 * Meta keys owned by the given plugin, for stored values AvandWP can write.
	 *
	 * @param string $plugin Plugin slug.
	 * @return array{title: string, description: string, keyword: string}
	 */
	public static function meta_keys( $plugin ) {
		$map = array(
			'yoast' => array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'keyword'     => '_yoast_wpseo_focuskw',
			),
			'rankmath' => array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'keyword'     => 'rank_math_focus_keyword',
			),
			'seopress' => array(
				'title'       => '_seopress_titles_title',
				'description' => '_seopress_titles_desc',
				'keyword'     => '_seopress_analysis_target_kw',
			),
			// AIOSEO 4+ stores its data in a dedicated table, not post meta.
			'aioseo' => array(
				'title'       => '',
				'description' => '',
				'keyword'     => '',
			),
			'none' => array(
				'title'       => '',
				'description' => '',
				'keyword'     => '',
			),
		);

		return $map[ $plugin ] ?? $map['none'];
	}

	/**
	 * Can AvandWP safely write SEO metadata for this site right now?
	 *
	 * @return array{supported: bool, plugin: string, message: string}
	 */
	public static function write_support() {
		$plugin = self::active_plugin();

		if ( 'none' === $plugin ) {
			return array(
				'supported' => false,
				'plugin'    => 'none',
				'message'   => 'هیچ افزونه سئویی فعال نیست. آوند متادیتای سئو را در جای دیگری ذخیره نمی‌کند تا سیستم سئوی موازی و متناقض ایجاد نشود. برای تولید عنوان و توضیحات متا، Rank Math، Yoast SEO یا SEOPress را نصب کنید.',
			);
		}

		if ( 'aioseo' === $plugin ) {
			return array(
				'supported' => false,
				'plugin'    => 'aioseo',
				'message'   => 'All in One SEO نسخه ۴ داده‌های سئو را در جدول اختصاصی خود نگه می‌دارد، نه در متای نوشته. نوشتن مستقیم در دیتابیس آن امن نیست و انجام نمی‌شود؛ می‌توانید عنوان و توضیحات تولیدشده را از این صفحه کپی و در AIOSEO وارد کنید.',
			);
		}

		return array(
			'supported' => true,
			'plugin'    => $plugin,
			'message'   => sprintf( 'متادیتای سئو در کلیدهای اختصاصی %s ذخیره می‌شود.', self::active_plugin_label() ),
		);
	}

	/**
	 * Read the current SEO metadata for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title: string, description: string, keyword: string, plugin: string}
	 */
	public static function get_meta( $post_id ) {
		$plugin = self::active_plugin();
		$keys   = self::meta_keys( $plugin );
		$post_id = (int) $post_id;

		if ( '' === $keys['title'] ) {
			return array( 'title' => '', 'description' => '', 'keyword' => '', 'plugin' => $plugin );
		}

		return array(
			'title'       => (string) get_post_meta( $post_id, $keys['title'], true ),
			'description' => (string) get_post_meta( $post_id, $keys['description'], true ),
			'keyword'     => (string) get_post_meta( $post_id, $keys['keyword'], true ),
			'plugin'      => $plugin,
		);
	}

	/**
	 * Write SEO metadata, but only where it is safe and appropriate.
	 *
	 * Every value is sanitised and length-bounded before storage. A field is
	 * skipped rather than emptied when the supplied value is blank, so an
	 * existing, deliberate value is never destroyed by a partial update.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $values  Optional: title, description, keyword.
	 * @return array{written: array<int, string>, skipped: array<int, string>, plugin: string, message: string}
	 */
	public static function set_meta( $post_id, array $values ) {
		$support = self::write_support();
		$post_id = (int) $post_id;
		$written = array();
		$skipped = array();

		if ( ! $support['supported'] || $post_id <= 0 ) {
			return array(
				'written' => array(),
				'skipped' => array( 'title', 'description', 'keyword' ),
				'plugin'  => $support['plugin'],
				'message' => $support['message'],
			);
		}

		$keys = self::meta_keys( $support['plugin'] );

		$limits = array(
			'title'       => 200,
			'description' => 400,
			'keyword'     => 200,
		);

		foreach ( array( 'title', 'description', 'keyword' ) as $field ) {
			if ( ! array_key_exists( $field, $values ) || '' === $keys[ $field ] ) {
				$skipped[] = $field;
				continue;
			}

			$value = trim( wp_strip_all_tags( (string) $values[ $field ] ) );

			if ( '' === $value ) {
				$skipped[] = $field;
				continue;
			}

			update_post_meta( $post_id, $keys[ $field ], AvandWP_Text_Helper::safe_substr( $value, 0, $limits[ $field ] ) );
			$written[] = $field;
		}

		return array(
			'written' => $written,
			'skipped' => $skipped,
			'plugin'  => $support['plugin'],
			'message' => $support['message'],
		);
	}

	/**
	 * Does the active SEO plugin already output an FAQPage graph?
	 *
	 * Used to prevent duplicate structured data. Emitting a second FAQPage block
	 * on a page that already has one risks invalidating rich-result eligibility
	 * instead of winning it.
	 *
	 * @return bool
	 */
	public static function plugin_outputs_faq_schema() {
		$plugin = self::active_plugin();

		// Rank Math and Yoast both provide FAQ blocks that emit their own graph,
		// and both can be enabled per post; without reading the individual post's
		// block configuration the safe assumption is that a capable plugin is
		// responsible for its own schema output.
		return in_array( $plugin, array( 'rankmath', 'yoast', 'aioseo', 'seopress' ), true );
	}

	/**
	 * Compatibility report for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		$plugin  = self::active_plugin();
		$support = self::write_support();

		return array(
			'plugin'           => $plugin,
			'plugin_label'     => self::active_plugin_label(),
			'can_write_meta'   => $support['supported'],
			'write_message'    => $support['message'],
			'handles_faq_schema' => self::plugin_outputs_faq_schema(),
			'notes'            => array(
				'این افزونه سیستم سئوی موازی ایجاد نمی‌کند: عنوان، توضیحات، کانونیکال، سایت‌مپ و اسکیمای پیش‌فرض متعلق به افزونه سئوی فعال سایت است.',
				'آوند فقط کلیدهای اختصاصی همان افزونه را می‌نویسد و در صورت نبود افزونه سئو، هیچ متایی ذخیره نمی‌کند.',
			),
		);
	}
}
