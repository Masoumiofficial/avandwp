<?php
/**
 * Article brief and length-budget tests.
 *
 * These cover the single most important product requirement: a 4,500-word
 * default that the pipeline can actually reach. The baseline clamped input to
 * 3,000 words and the completion to a budget that could not hold 4,500 Persian
 * words, so the requirement was unreachable by construction rather than by
 * accident.
 */

AvandWP_Test_Harness::group( 'Article brief (Module A)' );

AvandWP_Test_Harness::test(
	'default length is 4,500 and no longer capped at 3,000',
	function () {
		$brief = AvandWP_Article_Brief::from_array( array( 'topic' => 'راهنمای سئو فنی' ) );

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $brief ), 'a topic alone must be enough to build a brief' );
		AvandWP_Test_Harness::assert_same( 4500, $brief['length_target'], 'the product default must be 4,500' );
		AvandWP_Test_Harness::assert_same( 'words', $brief['length_mode'] );
		AvandWP_Test_Harness::assert_true( 4500 > 3000, 'regression guard: the 1.0.0 ceiling was 3,000' );
	}
);

AvandWP_Test_Harness::test(
	'explicit length above the old ceiling is accepted',
	function () {
		$brief = AvandWP_Article_Brief::from_array(
			array(
				'topic'         => 'مقالات بلند',
				'length_target' => 6000,
			)
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $brief ) );
		AvandWP_Test_Harness::assert_same( 6000, $brief['length_target'] );
	}
);

AvandWP_Test_Harness::test(
	'character mode is honoured and remembered',
	function () {
		$brief = AvandWP_Article_Brief::from_array(
			array(
				'topic'                 => 'مقایسه ابزارها',
				'length_mode'           => 'characters',
				'length_target'         => 12000,
				'length_include_spaces' => 0,
			)
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $brief ) );
		AvandWP_Test_Harness::assert_same( 'characters', $brief['length_mode'] );
		AvandWP_Test_Harness::assert_same( 12000, $brief['length_target'] );
		AvandWP_Test_Harness::assert_false( $brief['length_include_spaces'], 'spaces must be excludable' );
	}
);

AvandWP_Test_Harness::test(
	'an empty topic is rejected with a specific message',
	function () {
		$brief = AvandWP_Article_Brief::from_array( array( 'topic' => '   ' ) );

		AvandWP_Test_Harness::assert_true( is_wp_error( $brief ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_brief_topic', $brief->get_error_code() );
	}
);

AvandWP_Test_Harness::test(
	'length outside the supported range is rejected, not silently clamped',
	function () {
		$too_small = AvandWP_Article_Brief::from_array(
			array(
				'topic'         => 'کوتاه',
				'length_target' => 50,
			)
		);

		$too_large = AvandWP_Article_Brief::from_array(
			array(
				'topic'         => 'بلند',
				'length_target' => 99999,
			)
		);

		AvandWP_Test_Harness::assert_true( is_wp_error( $too_small ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_brief_length_small', $too_small->get_error_code() );
		AvandWP_Test_Harness::assert_true( is_wp_error( $too_large ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_brief_length_large', $too_large->get_error_code() );
	}
);

AvandWP_Test_Harness::test(
	'publishing requires an explicit confirmation flag',
	function () {
		$unconfirmed = AvandWP_Article_Brief::from_array(
			array(
				'topic'       => 'خبر فوری',
				'post_status' => 'publish',
			)
		);

		AvandWP_Test_Harness::assert_true( is_wp_error( $unconfirmed ), 'publishing without confirmation must be refused' );
		AvandWP_Test_Harness::assert_same( 'avandwp_brief_publish_unconfirmed', $unconfirmed->get_error_code() );

		$confirmed = AvandWP_Article_Brief::from_array(
			array(
				'topic'           => 'خبر فوری',
				'post_status'     => 'publish',
				'confirm_publish' => 1,
			)
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $confirmed ) );
		AvandWP_Test_Harness::assert_same( 'publish', $confirmed['post_status'] );
	}
);

AvandWP_Test_Harness::test(
	'the default destination is a draft',
	function () {
		$brief = AvandWP_Article_Brief::from_array( array( 'topic' => 'بدون تأیید انتشار' ) );

		AvandWP_Test_Harness::assert_same( 'draft', $brief['post_status'] );
	}
);

AvandWP_Test_Harness::test(
	'the output-token budget for 4,500 words exceeds the legacy 3,500 ceiling',
	function () {
		$tokens = AvandWP_Article_Brief::estimate_output_tokens( 4500, 'words' );

		AvandWP_Test_Harness::assert_true( $tokens > 3500, 'the budget must exceed the budget that made the default unreachable' );
		AvandWP_Test_Harness::assert_true( $tokens <= AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS, 'the budget must stay within the provider ceiling' );
	}
);

AvandWP_Test_Harness::test(
	'the token budget is bounded for absurd and tiny inputs',
	function () {
		AvandWP_Test_Harness::assert_true( AvandWP_Article_Brief::estimate_output_tokens( 1, 'words' ) >= 256 );
		AvandWP_Test_Harness::assert_true( AvandWP_Article_Brief::estimate_output_tokens( 999999, 'words' ) <= AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS );
	}
);

AvandWP_Test_Harness::test(
	'character targets are converted to a word equivalent for budgeting',
	function () {
		$with_spaces    = AvandWP_Article_Brief::estimate_output_tokens( 12000, 'characters', true );
		$without_spaces = AvandWP_Article_Brief::estimate_output_tokens( 12000, 'characters', false );

		AvandWP_Test_Harness::assert_true( $without_spaces > $with_spaces, 'excluding spaces means more words for the same character count' );
	}
);

AvandWP_Test_Harness::test(
	'list fields accept newline and Persian-comma separated text',
	function () {
		$list = AvandWP_Article_Brief::string_list( "کلیدواژه یک\nکلیدواژه دو، کلیدواژه سه\r\nکلیدواژه یک" );

		AvandWP_Test_Harness::assert_same( 3, count( $list ), 'duplicates are removed' );
		AvandWP_Test_Harness::assert_same( 'کلیدواژه یک', $list[0] );
		AvandWP_Test_Harness::assert_same( 'کلیدواژه سه', $list[2] );
	}
);

AvandWP_Test_Harness::test(
	'reference URLs keep only safe http(s) destinations',
	function () {
		$urls = AvandWP_Article_Brief::url_list(
			array(
				'https://example.com/source',
				'javascript:alert(1)',
				'http://127.0.0.1/internal',
				'not a url',
			)
		);

		AvandWP_Test_Harness::assert_same( array( 'https://example.com/source' ), $urls );
	}
);

AvandWP_Test_Harness::test(
	'unknown enum values fall back to a documented default instead of being sent through',
	function () {
		$brief = AvandWP_Article_Brief::from_array(
			array(
				'topic'         => 'محتوا',
				'search_intent' => 'not-a-real-intent',
				'article_type'  => 'not-a-real-type',
			)
		);

		AvandWP_Test_Harness::assert_same( 'informational', $brief['search_intent'] );
		AvandWP_Test_Harness::assert_same( 'guide', $brief['article_type'] );
	}
);

AvandWP_Test_Harness::test(
	'the prompt block carries the length target and the mandatory facts',
	function () {
		$brief = AvandWP_Article_Brief::from_array(
			array(
				'topic'             => 'کودکان و فناوری',
				'length_target'     => 4500,
				'required_facts'    => array( 'میانگین زمان استفاده روزانه', 'توصیه سازمان بهداشت' ),
				'forbidden_claims'  => array( 'ادعای درمان' ),
			)
		);

		$block = AvandWP_Article_Brief::to_prompt_block( $brief );

		AvandWP_Test_Harness::assert_contains( '4,500', $block );
		AvandWP_Test_Harness::assert_contains( 'میانگین زمان استفاده روزانه', $block );
		AvandWP_Test_Harness::assert_contains( 'ادعای درمان', $block );
	}
);
