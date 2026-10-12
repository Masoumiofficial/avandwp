<?php
/**
 * Site auditor scoring and link-insertion safety tests.
 *
 * The link tests matter more than they look: the requirement is "no silent
 * modification of user content". Inserting an anchor inside an existing link,
 * inside a heading, or across a word boundary are exactly the ways a link
 * inserter damages a page without anyone noticing.
 */

AvandWP_Test_Harness::group( 'Site auditor (Module G)' );

AvandWP_Test_Harness::test(
	'every check declares a label, a positive weight and a profile',
	function () {
		$checks = AvandWP_Site_Auditor::checks();

		AvandWP_Test_Harness::assert_true( count( $checks ) >= 15, 'the checklist must cover the document, not one attribute' );

		foreach ( $checks as $id => $check ) {
			AvandWP_Test_Harness::assert_key( 'label', $check, 'check ' . $id . ' needs a human label' );
			AvandWP_Test_Harness::assert_true( $check['weight'] > 0, 'check ' . $id . ' must carry weight' );
			AvandWP_Test_Harness::assert_true(
				in_array( $check['profile'], array( 'seo', 'aio', 'geo' ), true ),
				'check ' . $id . ' must belong to a declared profile'
			);
		}
	}
);

AvandWP_Test_Harness::test(
	'a fully correct page scores 100',
	function () {
		$scored = AvandWP_Site_Auditor::score_page(
			array(
				'http_code'          => 200,
				'noindex'            => false,
				'canonical_other'    => false,
				'title'              => 'راهنمای کامل سئوی فنی برای سایت‌های فارسی',
				'title_length'       => 41,
				'title_unique'       => true,
				'description'        => 'در این راهنما گام‌به‌گام بررسی می‌کنیم که چگونه سئوی فنی سایت را اندازه‌گیری و اصلاح کنید.',
				'description_length' => 95,
				'description_unique' => true,
				'h1_count'           => 1,
				'heading_jump'       => false,
				'words'              => 900,
				'images_missing_alt' => 0,
				'internal_links'     => 4,
				'has_schema'         => true,
				'has_og'             => true,
				'lang'               => 'fa-IR',
				'has_viewport'       => true,
				'https'              => true,
				'redirect_count'     => 0,
			)
		);

		AvandWP_Test_Harness::assert_same( 100, $scored['score'] );
		AvandWP_Test_Harness::assert_same( 'pass', $scored['checks']['title_present']['status'] );
	}
);

AvandWP_Test_Harness::test(
	'a page missing its title and H1 scores well below 100',
	function () {
		$scored = AvandWP_Site_Auditor::score_page(
			array(
				'http_code'          => 200,
				'title'              => '',
				'title_length'       => 0,
				'description'        => '',
				'description_length' => 0,
				'h1_count'           => 0,
				'words'              => 900,
				'images_missing_alt' => 0,
				'internal_links'     => 2,
				'has_schema'         => true,
				'has_og'             => true,
				'lang'               => 'fa-IR',
				'has_viewport'       => true,
				'https'              => true,
				'redirect_count'     => 0,
			)
		);

		AvandWP_Test_Harness::assert_true( $scored['score'] < 80 );
		AvandWP_Test_Harness::assert_same( 'fail', $scored['checks']['title_present']['status'] );
		AvandWP_Test_Harness::assert_same( 'fail', $scored['checks']['single_h1']['status'] );
	}
);

AvandWP_Test_Harness::test(
	'a canonical pointing elsewhere is recorded as a failure, not ignored',
	function () {
		$scored = AvandWP_Site_Auditor::score_page(
			array(
				'http_code'       => 200,
				'canonical_other' => true,
				'title'           => 'عنوان معتبر برای این صفحه آزمایشی',
				'title_length'    => 34,
				'title_unique'    => true,
				'description'     => 'توضیح متای کافی بلند برای عبور از سنجه طول توضیحات متا در این آزمون.',
				'description_length' => 74,
				'description_unique' => true,
				'h1_count'        => 1,
				'words'           => 700,
				'internal_links'  => 1,
				'has_schema'      => true,
				'has_og'          => true,
				'lang'            => 'fa',
				'has_viewport'    => true,
				'https'           => true,
			)
		);

		AvandWP_Test_Harness::assert_same( 'fail', $scored['checks']['canonical_self']['status'] );
		AvandWP_Test_Harness::assert_true( $scored['score'] < 100 );
	}
);

AvandWP_Test_Harness::test(
	'absent evidence fails every presence check but not the negative checks',
	function () {
		$scored = AvandWP_Site_Auditor::score_page( array() );

		AvandWP_Test_Harness::assert_true( null !== $scored['score'], 'the score is always a number, never null' );

		// Presence checks must fail when nothing was observed: an empty page is
		// not a good page just because no problem was found.
		foreach ( array( 'title_present', 'description', 'single_h1', 'content_depth', 'structured_data', 'open_graph', 'internal_links' ) as $id ) {
			AvandWP_Test_Harness::assert_same( 'fail', $scored['checks'][ $id ]['status'], $id . ' must fail on an empty document' );
		}

		// Negative checks ("no noindex", "no redirect", "no missing alt") are
		// satisfied by absence, so a worthless page still scores above zero.
		AvandWP_Test_Harness::assert_true( $scored['score'] < 30, 'an empty document must not score well' );
	}
);

AvandWP_Test_Harness::test(
	'thin content lowers the content-depth check without touching the title checks',
	function () {
		$scored = AvandWP_Site_Auditor::score_page(
			array(
				'http_code'          => 200,
				'title'              => 'عنوان درست و کافی برای سنجش',
				'title_length'       => 27,
				'title_unique'       => true,
				'description'        => 'توضیحات متای این صفحه به اندازه کافی بلند نوشته شده تا سنجه طول آن عبور کند.',
				'description_length' => 76,
				'description_unique' => true,
				'h1_count'           => 1,
				'words'              => 120,
				'internal_links'     => 1,
				'has_schema'         => true,
				'has_og'             => true,
				'lang'               => 'fa',
				'has_viewport'       => true,
				'https'              => true,
			)
		);

		AvandWP_Test_Harness::assert_same( 'fail', $scored['checks']['content_depth']['status'] );
		AvandWP_Test_Harness::assert_same( 'pass', $scored['checks']['title_present']['status'] );
	}
);

AvandWP_Test_Harness::test(
	'the audit score is documented as an internal checklist, not a ranking guarantee',
	function () {
		$checks = AvandWP_Site_Auditor::checks();

		// The wording that must never appear: a promise about position.
		foreach ( $checks as $check ) {
			AvandWP_Test_Harness::assert_not_contains( 'رتبه', $check['label'] );
			AvandWP_Test_Harness::assert_not_contains( 'تضمین', $check['label'] );
		}
	}
);

AvandWP_Test_Harness::group( 'Link intelligence (Module B)' );

AvandWP_Test_Harness::test(
	'anchor extraction reports href, text and rel',
	function () {
		$links = AvandWP_Link_Intelligence::extract_links(
			'<p>متن <a href="https://example.com/a" rel="nofollow noopener">لینک یک</a> و <a href="/b">لینک دو</a>.</p>'
		);

		AvandWP_Test_Harness::assert_same( 2, count( $links ) );
		AvandWP_Test_Harness::assert_same( 'https://example.com/a', $links[0]['url'] );
		AvandWP_Test_Harness::assert_same( 'لینک یک', $links[0]['anchor'] );
		AvandWP_Test_Harness::assert_true( $links[0]['nofollow'] );
		AvandWP_Test_Harness::assert_false( $links[1]['nofollow'] );
	}
);

AvandWP_Test_Harness::test(
	'internal detection compares hosts, ignoring www and scheme',
	function () {
		AvandWP_Test_Harness::assert_true( AvandWP_Link_Intelligence::is_internal_url( 'https://example.com/x' ) );
		AvandWP_Test_Harness::assert_true( AvandWP_Link_Intelligence::is_internal_url( 'https://www.example.com/x' ) );
		AvandWP_Test_Harness::assert_true( AvandWP_Link_Intelligence::is_internal_url( '/relative/path' ) );
		AvandWP_Test_Harness::assert_false( AvandWP_Link_Intelligence::is_internal_url( 'https://other-site.com/x' ) );
		AvandWP_Test_Harness::assert_false( AvandWP_Link_Intelligence::is_internal_url( 'mailto:info@example.com' ) );
		AvandWP_Test_Harness::assert_false( AvandWP_Link_Intelligence::is_internal_url( '#section' ) );
	}
);

AvandWP_Test_Harness::test(
	'insertion replaces the first eligible occurrence only',
	function () {
		$html = '<p>سئوی فنی مهم است. سئوی فنی را جدی بگیرید.</p>';

		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			$html,
			'سئوی فنی',
			'<a href="https://example.com/seo">سئوی فنی</a>'
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $result ) );
		AvandWP_Test_Harness::assert_same( 1, substr_count( $result['html'], '<a href=' ) );
		AvandWP_Test_Harness::assert_contains( 'سئوی فنی را جدی بگیرید', $result['html'] );
	}
);

AvandWP_Test_Harness::test(
	'insertion refuses to nest inside an existing link',
	function () {
		$html = '<p><a href="https://example.com/old">سئوی فنی</a> توضیح داده شده است.</p>';

		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			$html,
			'سئوی فنی',
			'<a href="https://example.com/new">سئوی فنی</a>'
		);

		AvandWP_Test_Harness::assert_true( is_wp_error( $result ), 'a phrase already linked must not be wrapped again' );
		AvandWP_Test_Harness::assert_same( 'avandwp_anchor_not_found', $result->get_error_code() );
	}
);

AvandWP_Test_Harness::test(
	'insertion refuses to modify a heading',
	function () {
		$html = '<h2>سئوی فنی</h2><p>متن سئوی فنی در پاراگراف.</p>';

		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			$html,
			'سئوی فنی',
			'<a href="https://example.com/seo">سئوی فنی</a>'
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $result ) );
		// The heading must be untouched and the paragraph must carry the link.
		AvandWP_Test_Harness::assert_contains( '<h2>سئوی فنی</h2>', $result['html'] );
		AvandWP_Test_Harness::assert_true( strpos( $result['html'], '<a href=' ) > strpos( $result['html'], '</h2>' ) );
	}
);

AvandWP_Test_Harness::test(
	'insertion refuses a partial word match',
	function () {
		// «سئو» appears inside «سئوی» — matching there would underline half a word.
		$html = '<p>سئوی فنی و سرعت سایت.</p>';

		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			$html,
			'سئو',
			'<a href="https://example.com/seo">سئو</a>'
		);

		AvandWP_Test_Harness::assert_true( is_wp_error( $result ), 'a mid-word match must be rejected' );
	}
);

AvandWP_Test_Harness::test(
	'insertion refuses to touch a code block',
	function () {
		$html = '<pre><code>سئوی فنی</code></pre><p>سئوی فنی در متن.</p>';

		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			$html,
			'سئوی فنی',
			'<a href="https://example.com/seo">سئوی فنی</a>'
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $result ) );
		AvandWP_Test_Harness::assert_contains( '<code>سئوی فنی</code>', $result['html'] );
	}
);

AvandWP_Test_Harness::test(
	'a missing phrase is reported instead of inserting something plausible',
	function () {
		$result = AvandWP_Link_Intelligence::replace_first_eligible(
			'<p>متن کاملاً بی‌ربط.</p>',
			'عبارت ناموجود',
			'<a href="https://example.com/x">عبارت ناموجود</a>'
		);

		AvandWP_Test_Harness::assert_true( is_wp_error( $result ) );
	}
);

AvandWP_Test_Harness::test(
	'owner-defined rules are sanitised on read',
	function () {
		AvandWP_Test_WP_State::reset();

		update_option(
			AvandWP_Link_Intelligence::RULES_OPTION,
			array(
				array(
					'type'     => 'external',
					'url'      => 'https://trusted-source.example/page',
					'label'    => 'منبع معتبر',
					'anchors'  => array( 'تحقیقات منتشر شده', 'منبع اصلی' ),
					'max_uses' => 99,
					'enabled'  => true,
				),
				array(
					'type' => 'internal',
					'url'  => '',
				),
			),
			false
		);

		$rules = AvandWP_Link_Intelligence::rules();

		AvandWP_Test_Harness::assert_same( 1, count( $rules ), 'a rule without a URL is discarded' );
		AvandWP_Test_Harness::assert_same( 'external', $rules[0]['type'] );
		AvandWP_Test_Harness::assert_same( 20, $rules[0]['max_uses'], 'max_uses is bounded' );
		AvandWP_Test_Harness::assert_same( 2, count( $rules[0]['anchors'] ) );
	}
);

// ---------------------------------------------------------------------------
// "Continue the previous audit" resolution.
//
// The admin page has no run attached when it is first opened, so the server has
// to resolve "the newest run" itself. These tests pin that query down: an empty
// history must be distinguishable from a run id, and the lookup is bounded.
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'the newest audit run is looked up newest-first and bounded',
	function () {
		AvandWP_Test_WP_State::reset();

		AvandWP_Test_WP_State::queue_db( 'get_results', array( array( 'id' => 42, 'status' => 'paused' ) ) );

		$runs = ( new AvandWP_Site_Auditor() )->recent_runs( 1 );

		AvandWP_Test_Harness::assert_same( 1, count( $runs ) );
		AvandWP_Test_Harness::assert_same( 42, (int) $runs[0]['id'] );

		$first = AvandWP_Test_WP_State::last_query( 'get_results' );

		AvandWP_Test_Harness::assert_contains( 'ORDER BY id DESC', $first['sql'] );
		AvandWP_Test_Harness::assert_contains( 'LIMIT 1', $first['sql'], 'the newest run is fetched with LIMIT 1' );
		AvandWP_Test_Harness::assert_contains( AvandWP_Site_Auditor::runs_table(), $first['sql'] );

		// The limit is clamped: an unbounded list request cannot be turned into a
		// full-table read by passing a large number.
		AvandWP_Test_WP_State::reset();
		AvandWP_Test_WP_State::queue_db( 'get_results', array() );

		$runs = ( new AvandWP_Site_Auditor() )->recent_runs( 9999 );

		AvandWP_Test_Harness::assert_same( array(), $runs, 'an empty history returns an empty list, not null' );
		AvandWP_Test_Harness::assert_contains( 'LIMIT 50', AvandWP_Test_WP_State::last_query( 'get_results' )['sql'] );
	}
);
