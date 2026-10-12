<?php
/**
 * Unit tests for the SEO / AIO / GEO scoring engine.
 *
 * The purpose of these tests is to prove the properties the product promises:
 *   - the three scores are computed independently from the same evidence;
 *   - missing evidence produces `unavailable`, never a pass and never a 0;
 *   - 100 is only reachable when every applicable criterion passes;
 *   - scores move in the expected direction when the content improves.
 */

/**
 * Build a well-formed Persian article fixture.
 *
 * @param int $paragraphs How many body paragraphs to emit.
 * @return string
 */
function avandwp_test_good_article( $paragraphs = 14 ) {
	$body  = '<p>افزونه آوند یک دستیار هوشمند محتوا برای وردپرس است. این ابزار در سال 1405 با هدف ساده‌سازی تولید محتوا منتشر شد.</p>';
	$body .= '<h2>آوند چیست و چه کاری انجام می‌دهد؟</h2>';
	$body .= '<p>آوند به معنی «دستیار» است. این افزونه به معنی نرم‌افزاری است که تولید محتوای سئوشده را خودکار می‌کند.</p>';
	$body .= '<p>موتور ارزیابی این افزونه معیارهای متنی را به معنی قواعد قابل اندازه‌گیری می‌سنجد. بر اساس مستندات منتشرشده در سال 2026، 24 معیار مستقل بررسی می‌شود.</p>';
	$body .= '<p>هزینه هر مقاله با مدل gpt-5.6-terra حدود 0.08 دلار است که 60 درصد ارزان‌تر از میانگین بازار محاسبه می‌شود.</p>';
	$body .= '<ul><li>تولید مقاله بلند</li><li>ارزیابی کیفیت محتوا</li><li>پیشنهاد لینک داخلی</li></ul>';
	$body .= '<table><tr><td>ویژگی</td><td>مقدار</td></tr><tr><td>حداکثر طول</td><td>4500 کلمه</td></tr></table>';
	$body .= '<h2>چگونه از آوند استفاده کنیم؟</h2>';
	$body .= '<p>طبق راهنمای رسمی منتشرشده توسط تیم توسعه، برای شروع کافی است کلید API را وارد کنید و 3 مرحله راه‌اندازی را کامل کنید.</p>';
	$body .= '<p>نخست کلید API را وارد کنید. سپس شاخص پایگاه دانش ساخته می‌شود و در نهایت مقاله تولید می‌شود.</p>';
	$body .= '<p>آیا امکان ویرایش خروجی وجود دارد؟ بله، هر بخش قابل ویرایش است و نتیجه در 2 دقیقه ذخیره می‌شود.</p>';

	for ( $i = 1; $i <= (int) $paragraphs; $i++ ) {
		$body .= sprintf(
			'<p>بخش شماره %d از این راهنما به بررسی دقیق تنظیمات اختصاص دارد و شامل 5 نکته کاربردی برای بهینه‌سازی است. بر اساس تحلیل سال 2025، رعایت این نکات 35 درصد بهبود ایجاد می‌کند.</p>',
			$i
		);
	}

	$body .= '<h2>منابع و توضیحات تکمیلی</h2>';
	$body .= '<p>به نقل از مستندات رسمی وردپرس که در سال 2026 منتشر شده است، این ساختار استاندارد محسوب می‌شود. برای مطالعه بیشتر به <a href="https://developer.wordpress.org/plugins/">مستندات رسمی وردپرس</a> و <a href="https://gapgpt.app/platform-v2/docs/quickstart">راهنمای API</a> مراجعه کنید.</p>';
	$body .= '<p>همچنین <a href="https://example.com/hosting">راهنمای انتخاب هاست ووکامرس</a> به شما در تصمیم‌گیری کمک می‌کند.</p>';
	$body .= '<img src="a.png" alt="نمای پنل مدیریت افزونه آوند">';
	$body .= '<img src="b.png" alt="نمودار مقایسه سرعت تولید محتوا">';

	return $body;
}

/**
 * A full, well-formed context for the fixture above.
 *
 * @return array
 */
function avandwp_test_good_context() {
	return array(
		'title'              => 'راهنمای کامل افزونه آوند برای تولید محتوا',
		'meta_description'   => 'آوند یک دستیار هوشمند محتوا برای وردپرس است که مقاله بلند فارسی تولید می‌کند، کیفیت را می‌سنجد و لینک داخلی پیشنهاد می‌دهد.',
		'slug'               => 'avandwp-content-guide',
		'focus_keyword'      => 'افزونه آوند',
		'secondary_keywords' => array( 'دستیار هوشمند محتوا', 'تولید محتوا', 'لینک داخلی', 'ووکامرس' ),
		'entities'           => array( 'آوند', 'وردپرس', 'ووکامرس', 'سئو' ),
		'search_intent'      => 'informational',
		'author'             => 'تیم سئو و محتوا',
		'site_domain'        => 'example.com',
		'has_schema'         => true,
		'updated_date'       => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ),
		'length_target'      => 400,
		'length_mode'        => 'words',
		'length_include_spaces' => true,
	);
}

AvandWP_Test_Harness::group( 'Quality Lab' );

AvandWP_Test_Harness::test( 'evaluate() returns three independent profile scores', function () {
	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	AvandWP_Test_Harness::assert_key( 'scores', $result );
	foreach ( array( 'seo', 'aio', 'geo' ) as $profile ) {
		AvandWP_Test_Harness::assert_key( $profile, $result['scores'], 'missing score for ' . $profile );
	}

	// The requirement is explicit: the same generic number must not be reused.
	$values = array_values( $result['scores'] );
	AvandWP_Test_Harness::assert_true(
		count( array_unique( $values ) ) > 1,
		'SEO, AIO and GEO must not all be the same number'
	);
} );

AvandWP_Test_Harness::test( 'a good article scores highly on all three profiles', function () {
	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	foreach ( $result['scores'] as $profile => $score ) {
		AvandWP_Test_Harness::assert_true( $score >= 70, $profile . ' score should be high, got ' . $score );
	}
} );

AvandWP_Test_Harness::test( 'a thin, generic article scores low and reports blockers', function () {
	$bad = '<p>در دنیای امروز، بهترین محتوا را باید نوشت. شاید این مطلب برای شما مفید باشد.</p>';

	$result = AvandWP_Quality_Lab::evaluate( $bad, avandwp_test_good_context() );

	foreach ( $result['scores'] as $profile => $score ) {
		AvandWP_Test_Harness::assert_true( $score < 60, $profile . ' score should be low, got ' . $score );
	}

	AvandWP_Test_Harness::assert_true( count( $result['summary']['blockers'] ) > 0, 'blockers expected' );
	AvandWP_Test_Harness::assert_contains( 'اصلاح', $result['summary']['status'] );
} );

AvandWP_Test_Harness::test( 'missing evidence yields unavailable, not a pass', function () {
	// No focus keyword and no secondary keywords/entities supplied.
	$context = array(
		'title'         => 'مطلب کوتاه',
		'author'        => 'نویسنده',
		'site_domain'   => 'example.com',
	);

	$result = AvandWP_Quality_Lab::evaluate( '<p>یک متن کوتاه درباره موضوعی مشخص.</p>', $context );

	$by_id = array();
	foreach ( $result['findings'] as $finding ) {
		$by_id[ $finding['id'] ] = $finding;
	}

	AvandWP_Test_Harness::assert_same( 'unavailable', $by_id['seo.focus_keyword']['result'] );
	AvandWP_Test_Harness::assert_same( 'unavailable', $by_id['seo.keyword_stuffing']['result'] );
	AvandWP_Test_Harness::assert_same( 'unavailable', $by_id['seo.secondary_keywords']['result'] );
	AvandWP_Test_Harness::assert_true( $result['summary']['unavailable_criteria'] > 0, 'unavailable criteria must be counted and reported' );
} );

AvandWP_Test_Harness::test( 'unavailable criteria are excluded from the denominator', function () {
	$html = avandwp_test_good_article();

	$with_keyword    = AvandWP_Quality_Lab::evaluate( $html, avandwp_test_good_context() );
	$without_keyword = AvandWP_Quality_Lab::evaluate(
		$html,
		array_merge( avandwp_test_good_context(), array( 'focus_keyword' => '', 'secondary_keywords' => array(), 'entities' => array() ) )
	);

	AvandWP_Test_Harness::assert_true(
		$without_keyword['profiles']['seo']['possible_weight'] < $with_keyword['profiles']['seo']['possible_weight'],
		'removing evidence must shrink the evaluable weight, not silently pass the criteria'
	);
} );

AvandWP_Test_Harness::test( 'criteria that cannot be judged are listed explicitly', function () {
	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	AvandWP_Test_Harness::assert_key( 'unavailable_criteria', $result['profiles']['seo'] );
	AvandWP_Test_Harness::assert_key( 'unavailable_criteria', $result['profiles']['aio'] );
	AvandWP_Test_Harness::assert_key( 'unavailable_criteria', $result['profiles']['geo'] );
} );

AvandWP_Test_Harness::test( 'a 100 score requires every applicable criterion to pass outright', function () {
	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	foreach ( $result['profiles'] as $profile => $data ) {
		if ( 100 !== $data['score'] ) {
			continue;
		}
		AvandWP_Test_Harness::assert_same( 0, $data['counts']['partial'], $profile . ': a partial result must prevent a 100 score' );
		AvandWP_Test_Harness::assert_same( 0, $data['counts']['fail'], $profile . ': a failure must prevent a 100 score' );
	}
} );

AvandWP_Test_Harness::test( 'every finding carries weight, severity, evidence and explanation', function () {
	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	AvandWP_Test_Harness::assert_true( count( $result['findings'] ) > 20, 'expected the full criteria registry to run' );

	foreach ( $result['findings'] as $finding ) {
		AvandWP_Test_Harness::assert_true( $finding['weight'] > 0, $finding['id'] . ': weight must be positive' );
		AvandWP_Test_Harness::assert_true( in_array( $finding['severity'], array( 'critical', 'high', 'medium', 'low' ), true ), $finding['id'] . ': invalid severity' );
		AvandWP_Test_Harness::assert_true( '' !== $finding['measures'], $finding['id'] . ': must state what it measures' );
		AvandWP_Test_Harness::assert_true( '' !== $finding['evidence'], $finding['id'] . ': must include observed evidence' );
		AvandWP_Test_Harness::assert_true( is_bool( $finding['auto_fixable'] ), $finding['id'] . ': auto_fixable must be a boolean' );
	}
} );

AvandWP_Test_Harness::test( 'improving content raises the score (no hardcoded results)', function () {
	$thin    = AvandWP_Quality_Lab::evaluate( '<p>متن کوتاه.</p>', avandwp_test_good_context() );
	$rich    = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );

	foreach ( array( 'seo', 'aio', 'geo' ) as $profile ) {
		AvandWP_Test_Harness::assert_true(
			$rich['scores'][ $profile ] > $thin['scores'][ $profile ],
			$profile . ' must improve when the article improves'
		);
	}
} );

AvandWP_Test_Harness::test( 'length shortfall against a 4,500-word target is reported, not hidden', function () {
	$context = array_merge(
		avandwp_test_good_context(),
		array(
			'length_target' => 4500,
			'length_mode'   => 'words',
		)
	);

	$result = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), $context );

	$by_id = array();
	foreach ( $result['findings'] as $finding ) {
		$by_id[ $finding['id'] ] = $finding;
	}

	AvandWP_Test_Harness::assert_same( 'fail', $by_id['seo.length']['result'] );
	AvandWP_Test_Harness::assert_contains( 'کمبود طول', $by_id['seo.length']['evidence'] );
	AvandWP_Test_Harness::assert_true( $result['summary']['measured_length']['words'] < 4500 );
} );

AvandWP_Test_Harness::test( 'measured length reflects the real content, not the request', function () {
	$html   = '<p>یک</p><p>دو</p><p>سه</p>';
	$result = AvandWP_Quality_Lab::evaluate(
		$html,
		array_merge( avandwp_test_good_context(), array( 'length_target' => 4500 ) )
	);

	AvandWP_Test_Harness::assert_same( 3, $result['summary']['measured_length']['words'] );
} );

AvandWP_Test_Harness::test( 'summary exposes strengths, blockers, priorities and a next action', function () {
	$result  = AvandWP_Quality_Lab::evaluate( avandwp_test_good_article(), avandwp_test_good_context() );
	$summary = $result['summary'];

	foreach ( array( 'overall', 'status', 'next_action', 'strengths', 'blockers', 'high_priority', 'weaknesses', 'low_priority', 'auto_fixable_ids', 'disclaimer' ) as $key ) {
		AvandWP_Test_Harness::assert_key( $key, $summary, 'summary must expose ' . $key );
	}

	AvandWP_Test_Harness::assert_contains( 'تضمین', $summary['disclaimer'], 'the disclaimer must refuse to promise rankings' );
} );

AvandWP_Test_Harness::test( 'the criteria registry is documented and weighted', function () {
	$described = AvandWP_Quality_Lab::describe_all();

	AvandWP_Test_Harness::assert_true( count( $described ) >= 25, 'expected at least 25 documented criteria' );

	$profiles = array();
	foreach ( $described as $criterion ) {
		AvandWP_Test_Harness::assert_true( '' !== $criterion['measures'], $criterion['id'] . ' must document what it measures' );
		$profiles[ $criterion['profile'] ] = ( $profiles[ $criterion['profile'] ] ?? 0 ) + $criterion['weight'];
	}

	AvandWP_Test_Harness::assert_key( 'seo', $profiles );
	AvandWP_Test_Harness::assert_key( 'aio', $profiles );
	AvandWP_Test_Harness::assert_key( 'geo', $profiles );

	// Weight must reflect emphasis, not be an arbitrary constant.
	AvandWP_Test_Harness::assert_true( $profiles['seo'] >= 80, 'SEO weights should total at least 80' );
	AvandWP_Test_Harness::assert_true( $profiles['aio'] >= 45, 'AIO weights should total at least 45' );
	AvandWP_Test_Harness::assert_true( $profiles['geo'] >= 45, 'GEO weights should total at least 45' );
} );

AvandWP_Test_Harness::test( 'profile definitions state what they do NOT claim', function () {
	foreach ( AvandWP_Criteria::profiles() as $key => $meta ) {
		AvandWP_Test_Harness::assert_key( 'definition', $meta, $key . ' needs a definition' );
		AvandWP_Test_Harness::assert_key( 'not_a_claim', $meta, $key . ' must state its limitations' );
		AvandWP_Test_Harness::assert_true( '' !== $meta['not_a_claim'], $key . ' limitation text must not be empty' );
	}
} );
