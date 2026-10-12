<?php
/**
 * Unit tests for the measurement layer (word/character counting).
 *
 * These tests exist to pin down the documented counting rules, because the
 * requirement "the default article target is 4,500 words, and word and
 * character counting are distinct and accurate" is only meaningful if the rules
 * are fixed and verified.
 */

AvandWP_Test_Harness::group( 'Length measurement' );

AvandWP_Test_Harness::test( 'Persian words are counted (str_word_count would return 0)', function () {
	$text = 'این یک متن آزمایشی فارسی است';

	// The baseline defect this replaces: PHP's ASCII-only counter returns 0.
	if ( function_exists( 'str_word_count' ) ) {
		AvandWP_Test_Harness::assert_same( 0, str_word_count( $text ), 'str_word_count should fail on Persian' );
	}

	AvandWP_Test_Harness::assert_same( 6, AvandWP_Text_Helper::word_count( $text ) );
} );

AvandWP_Test_Harness::test( 'Zero-width non-joiner keeps a compound word as one word', function () {
	// "می‌خواهم" contains U+200C between می and خواهم.
	AvandWP_Test_Harness::assert_same( 1, AvandWP_Text_Helper::word_count( 'می‌خواهم' ) );
	AvandWP_Test_Harness::assert_same( 3, AvandWP_Text_Helper::word_count( 'او می‌خواهم رفت' ) );
} );

AvandWP_Test_Harness::test( 'HTML tags are excluded from counting', function () {
	$html = '<h2>عنوان بخش</h2><p>پاراگراف <strong>مهم</strong> است.</p>';
	AvandWP_Test_Harness::assert_same( 5, AvandWP_Text_Helper::word_count( $html ) );
} );

AvandWP_Test_Harness::test( 'Adjacent block elements do not merge into one token', function () {
	// Regression guard: without block-boundary insertion this becomes "پایانشروع".
	$html = '<p>پایان</p><p>شروع</p>';
	AvandWP_Test_Harness::assert_same( 2, AvandWP_Text_Helper::word_count( $html ) );
} );

AvandWP_Test_Harness::test( 'HTML entities are decoded before counting characters', function () {
	// "&nbsp;" must count as one rendered character, not five source characters.
	AvandWP_Test_Harness::assert_same( 3, AvandWP_Text_Helper::char_count( 'a&nbsp;b', true ) );
} );

AvandWP_Test_Harness::test( 'Character counting distinguishes spaces from no-spaces', function () {
	$text = 'سلام دنیا'; // 4 + 1 space + 4 = 9 characters, 8 without the space.

	AvandWP_Test_Harness::assert_same( 9, AvandWP_Text_Helper::char_count( $text, true ), 'with spaces' );
	AvandWP_Test_Harness::assert_same( 8, AvandWP_Text_Helper::char_count( $text, false ), 'without spaces' );
} );

AvandWP_Test_Harness::test( 'Word and character counts are different measures', function () {
	$text = 'این متن کوتاه است';
	$words = AvandWP_Text_Helper::word_count( $text );
	$chars = AvandWP_Text_Helper::char_count( $text, true );

	// "این متن کوتاه است" = 3 + 3 + 5 + 3 letters + 3 separating spaces = 17.
	AvandWP_Test_Harness::assert_same( 4, $words );
	AvandWP_Test_Harness::assert_true( $chars > $words, 'character count should exceed word count' );
	AvandWP_Test_Harness::assert_same( 17, $chars );
	AvandWP_Test_Harness::assert_same( 14, AvandWP_Text_Helper::char_count( $text, false ) );
} );

AvandWP_Test_Harness::test( 'measure() reports every documented field', function () {
	$html = '<h2>بخش</h2><p>یک پاراگراف با <a href="/x">لینک</a> و <img src="a.png" alt="توصیف"> تصویر.</p><ul><li>مورد</li></ul>';

	$m = AvandWP_Text_Helper::measure( $html );

	foreach ( array( 'words', 'chars_with_spaces', 'chars_without_spaces', 'zwnj', 'sentences', 'paragraphs', 'headings_h2', 'lists', 'links_total', 'images_total', 'reading_minutes' ) as $key ) {
		AvandWP_Test_Harness::assert_key( $key, $m, 'measure() must expose ' . $key );
	}

	AvandWP_Test_Harness::assert_same( 1, $m['headings_h2'] );
	AvandWP_Test_Harness::assert_same( 1, $m['images_total'] );
	AvandWP_Test_Harness::assert_same( 1, $m['links_total'] );
	AvandWP_Test_Harness::assert_same( 1, $m['lists'] );
	AvandWP_Test_Harness::assert_true( $m['chars_with_spaces'] > $m['chars_without_spaces'] );
} );

AvandWP_Test_Harness::test( 'ZWNJ is counted and reported separately', function () {
	$m = AvandWP_Text_Helper::measure( 'می‌خواهم' );
	AvandWP_Test_Harness::assert_same( 1, $m['zwnj'], 'one ZWNJ detected' );
	AvandWP_Test_Harness::assert_same( 1, $m['words'] );
} );

AvandWP_Test_Harness::test( 'assess_length reports a shortfall instead of claiming success', function () {
	// 4 words against a 4,500-word target.
	$measured   = AvandWP_Text_Helper::measure( 'این متن کوتاه است' );
	$assessment = AvandWP_Text_Helper::assess_length( $measured, 'words', 4500, true );

	AvandWP_Test_Harness::assert_same( 4, $assessment['achieved'] );
	AvandWP_Test_Harness::assert_same( 4500, $assessment['target'] );
	AvandWP_Test_Harness::assert_false( $assessment['complete'] );
	AvandWP_Test_Harness::assert_true( $assessment['delta'] < 0 );
	AvandWP_Test_Harness::assert_contains( 'کمبود طول', $assessment['message'] );
} );

AvandWP_Test_Harness::test( 'assess_length never reports the target as achieved without measuring', function () {
	$measured   = AvandWP_Text_Helper::measure( str_repeat( 'کلمه ', 4500 ) );
	$assessment = AvandWP_Text_Helper::assess_length( $measured, 'words', 4500, true );

	AvandWP_Test_Harness::assert_same( 4500, $assessment['achieved'] );
	AvandWP_Test_Harness::assert_true( $assessment['complete'] );
} );

AvandWP_Test_Harness::test( 'assess_length supports character mode with the spaces rule', function () {
	$measured = AvandWP_Text_Helper::measure( 'سلام دنیا' ); // chars: 9 with space, 8 without.

	$with = AvandWP_Text_Helper::assess_length( $measured, 'characters', 9, true );
	$without = AvandWP_Text_Helper::assess_length( $measured, 'characters', 9, false );

	AvandWP_Test_Harness::assert_same( 9, $with['achieved'] );
	AvandWP_Test_Harness::assert_true( $with['complete'] );
	AvandWP_Test_Harness::assert_same( 8, $without['achieved'] );
	AvandWP_Test_Harness::assert_false( $without['complete'], '8 characters must not satisfy a 9-character target' );
} );

AvandWP_Test_Harness::test( 'safe_substr degrades gracefully without mbstring', function () {
	// The bundled runtime has no mbstring, so this exercises the fallback path.
	$text = 'متن آزمایشی فارسی';

	AvandWP_Test_Harness::assert_same( 'متن', AvandWP_Text_Helper::safe_substr( $text, 0, 3 ) );
	AvandWP_Test_Harness::assert_same( 17, AvandWP_Text_Helper::length( $text ) );
	AvandWP_Test_Harness::assert_false( function_exists( 'mb_substr' ), 'runtime under test should lack mbstring' );
} );

AvandWP_Test_Harness::test( 'to_plain_text preserves paragraph boundaries as whitespace', function () {
	$plain = AvandWP_Text_Helper::to_plain_text( '<p>خط اول</p><p>خط دوم</p>' );

	AvandWP_Test_Harness::assert_contains( 'خط اول', $plain );
	AvandWP_Test_Harness::assert_contains( 'خط دوم', $plain );
	AvandWP_Test_Harness::assert_not_contains( '<p>', $plain );
	AvandWP_Test_Harness::assert_not_contains( 'خط اولخط دوم', $plain );
} );
