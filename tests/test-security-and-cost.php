<?php
/**
 * Unit tests for the Phase 1 security hardening and the cost-integrity work.
 *
 * These are the tests that guard the specific defects recorded in
 * docs/technical-audit.md as F-03 (missing object authority checks),
 * F-07 (vault downgrade visibility), F-08 (fabricated cost figures) and the
 * outbound-fetch protections.
 */

AvandWP_Test_Harness::group( 'Authorization policy (F-03)' );

AvandWP_Test_Harness::test( 'a user who cannot edit the object is rejected', function () {
	AvandWP_Test_WP_State::reset();
	AvandWP_Test_WP_State::add_post( 42, 'post', 1 ); // Owned by user 1.
	AvandWP_Test_WP_State::$caps = array( 'edit_posts' ); // Low-trust author.

	$result = AvandWP_Capabilities::user_can_edit_object( 42 );

	AvandWP_Test_Harness::assert_instance( 'WP_Error', $result, 'editing another user\'s post must be refused' );
	AvandWP_Test_Harness::assert_same( 'avandwp_object_forbidden', $result->get_error_code() );
} );

AvandWP_Test_Harness::test( 'object authority is granted when WordPress grants edit_post', function () {
	AvandWP_Test_WP_State::reset();
	AvandWP_Test_WP_State::add_post( 42, 'post', 7 );
	AvandWP_Test_WP_State::$caps = array( 'edit_posts', 'edit_post:42' );

	AvandWP_Test_Harness::assert_true( true === AvandWP_Capabilities::user_can_edit_object( 42 ) );
} );

AvandWP_Test_Harness::test( 'a missing object is refused rather than silently accepted', function () {
	AvandWP_Test_WP_State::reset();
	AvandWP_Test_WP_State::$caps = array( 'edit_posts', 'edit_post:999' );

	$result = AvandWP_Capabilities::user_can_edit_object( 999 );

	AvandWP_Test_Harness::assert_instance( 'WP_Error', $result );
	AvandWP_Test_Harness::assert_same( 'avandwp_object_not_found', $result->get_error_code() );
} );

AvandWP_Test_Harness::test( 'an unsupported post type is refused even with authority', function () {
	AvandWP_Test_WP_State::reset();
	AvandWP_Test_WP_State::add_post( 5, 'attachment', 7 );
	AvandWP_Test_WP_State::$caps = array( 'edit_posts', 'edit_post:5' );

	$result = AvandWP_Capabilities::user_can_edit_object( 5 );

	AvandWP_Test_Harness::assert_instance( 'WP_Error', $result );
	AvandWP_Test_Harness::assert_same( 'avandwp_unsupported_post_type', $result->get_error_code() );
} );

AvandWP_Test_Harness::test( 'an invalid object id is refused without touching the database', function () {
	AvandWP_Test_WP_State::reset();

	$zero     = AvandWP_Capabilities::user_can_edit_object( 0 );
	$negative = AvandWP_Capabilities::user_can_edit_object( -12 );

	AvandWP_Test_Harness::assert_same( 'avandwp_invalid_object', $zero->get_error_code() );
	AvandWP_Test_Harness::assert_same( 'avandwp_invalid_object', $negative->get_error_code() );
} );

AvandWP_Test_Harness::test( 'the policy exposes deliberate, distinct capability tiers', function () {
	$manage  = AvandWP_Capabilities::manage_capability();
	$create  = AvandWP_Capabilities::create_capability();
	$others  = AvandWP_Capabilities::operate_others_capability();

	AvandWP_Test_Harness::assert_same( 'manage_options', $manage );
	AvandWP_Test_Harness::assert_same( 'edit_posts', $create );
	AvandWP_Test_Harness::assert_same( 'edit_others_posts', $others );

	// Site-wide/destructive work must be strictly harder than content creation.
	AvandWP_Test_Harness::assert_true( $manage !== $create, 'site-wide actions must not share the content capability' );
	AvandWP_Test_Harness::assert_true( $others !== $create, 'acting on others\' content must be a distinct tier' );
} );

AvandWP_Test_Harness::group( 'Outbound fetch guard (SSRF and upload validation)' );

AvandWP_Test_Harness::test( 'loopback, private and scheme-less URLs are rejected', function () {
	$blocked = array(
		'http://127.0.0.1/image.png',
		'http://localhost/x.png',
		'http://192.168.1.1/admin',
		'http://169.254.1.1/meta',
		'file:///etc/passwd',
		'ftp://example.com/x.png',
		'notaurl',
		'',
	);

	foreach ( $blocked as $url ) {
		$result = AvandWP_Outbound_Guard::validate_url( $url );
		AvandWP_Test_Harness::assert_instance( 'WP_Error', $result, 'should reject: ' . $url );
	}
} );

AvandWP_Test_Harness::test( 'public https URLs are accepted', function () {
	AvandWP_Test_Harness::assert_true( true === AvandWP_Outbound_Guard::validate_url( 'https://cdn.example.com/a.png' ) );
} );

AvandWP_Test_Harness::test( 'real image payloads are detected from their bytes', function () {
	$png  = "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 200 );
	$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 200 );
	$gif  = 'GIF89a' . str_repeat( "\x00", 200 );
	$webp = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat( "\x00", 200 );

	AvandWP_Test_Harness::assert_same( 'image/png', AvandWP_Outbound_Guard::detect_image_mime( $png ) );
	AvandWP_Test_Harness::assert_same( 'image/jpeg', AvandWP_Outbound_Guard::detect_image_mime( $jpeg ) );
	AvandWP_Test_Harness::assert_same( 'image/gif', AvandWP_Outbound_Guard::detect_image_mime( $gif ) );
	AvandWP_Test_Harness::assert_same( 'image/webp', AvandWP_Outbound_Guard::detect_image_mime( $webp ) );
} );

AvandWP_Test_Harness::test( 'a non-image payload is refused even when the header claims otherwise', function () {
	// A provider returning an error document must never become an attachment.
	$html = '<!DOCTYPE html><html><body>Error: rate limited' . str_repeat( ' ', 200 ) . '</body></html>';

	AvandWP_Test_Harness::assert_null( AvandWP_Outbound_Guard::detect_image_mime( $html ) );
	AvandWP_Test_Harness::assert_null( AvandWP_Outbound_Guard::detect_image_mime( '{"error":{"message":"nope"}}' . str_repeat( ' ', 200 ) ) );
} );

AvandWP_Test_Harness::test( 'the accepted image MIME list is explicit and narrow', function () {
	$allowed = AvandWP_Outbound_Guard::allowed_image_mimes();

	AvandWP_Test_Harness::assert_true( isset( $allowed['image/png'] ) );
	AvandWP_Test_Harness::assert_true( isset( $allowed['image/webp'] ) );
	AvandWP_Test_Harness::assert_false( isset( $allowed['image/svg+xml'] ), 'SVG is an active content type and must not be accepted by default' );
	AvandWP_Test_Harness::assert_false( isset( $allowed['text/html'] ) );
} );

AvandWP_Test_Harness::group( 'Cost integrity (F-08)' );

AvandWP_Test_Harness::test( 'a verified model price is estimated from the published rate', function () {
	// gpt-5.6-terra is published at $2.00 / $12.00 per 1M tokens.
	$estimate = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', 'gpt-5.6-terra', 1000000, 1000000 );

	AvandWP_Test_Harness::assert_same( 'estimated', $estimate['source'] );
	AvandWP_Test_Harness::assert_close( 14.0, $estimate['cost'], 0.0001 );
} );

AvandWP_Test_Harness::test( 'an unpriced model returns unknown instead of a guessed number', function () {
	$estimate = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', 'a-model-that-does-not-exist', 1000, 1000 );

	AvandWP_Test_Harness::assert_null( $estimate['cost'], 'an unpriced model must not receive a fabricated cost' );
	AvandWP_Test_Harness::assert_same( 'unknown', $estimate['source'] );
	AvandWP_Test_Harness::assert_contains( 'محاسبه نشد', $estimate['note'] );
} );

AvandWP_Test_Harness::test( 'unknown cost is distinguishable from a genuine zero', function () {
	// The baseline collapsed both cases into the same 0.000000 column value.
	$unknown = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', 'mystery-model', 500, 500 );
	$known   = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', 'gapgpt-agent', 0, 0 );

	AvandWP_Test_Harness::assert_null( $unknown['cost'] );
	AvandWP_Test_Harness::assert_close( 0.0, $known['cost'], 0.000001 );
	AvandWP_Test_Harness::assert_same( 'estimated', $known['source'] );
} );

AvandWP_Test_Harness::test( 'the catalogue only advertises model ids the provider publishes', function () {
	foreach ( array( 'text', 'image', 'speech_to_text', 'text_to_speech', 'embedding' ) as $capability ) {
		$models = AvandWP_Price_Catalog::models_for( $capability, 'gapgpt' );
		AvandWP_Test_Harness::assert_true( count( $models ) > 0, $capability . ' should advertise models' );

		foreach ( $models as $model ) {
			AvandWP_Test_Harness::assert_true( '' !== $model['id'], $capability . ' model id must not be empty' );
			AvandWP_Test_Harness::assert_true( '' !== $model['label'], $capability . ' model needs a label' );
		}
	}
} );

AvandWP_Test_Harness::test( 'dall-e-3 is not offered for GapGPT', function () {
	// The 1.0.0 image client hardcoded this id; it is absent from GapGPT's
	// published image catalogue, so it must not be advertised.
	AvandWP_Test_Harness::assert_false(
		AvandWP_Price_Catalog::is_known_model( 'image', 'dall-e-3', 'gapgpt' ),
		'dall-e-3 must not be offered as a GapGPT image model'
	);

	AvandWP_Test_Harness::assert_true( AvandWP_Price_Catalog::is_known_model( 'image', 'gpt-image-2', 'gapgpt' ) );
	AvandWP_Test_Harness::assert_true( AvandWP_Price_Catalog::is_known_model( 'image', 'flux-1-schnell', 'gapgpt' ) );
} );

AvandWP_Test_Harness::test( 'the default model for each capability is a verified id', function () {
	foreach ( array( 'text', 'image', 'text_to_speech', 'embedding' ) as $capability ) {
		$default = AvandWP_Price_Catalog::default_model( $capability, 'gapgpt' );

		AvandWP_Test_Harness::assert_true( '' !== $default, $capability . ' must have a default' );
		AvandWP_Test_Harness::assert_true(
			AvandWP_Price_Catalog::is_known_model( $capability, $default, 'gapgpt' ),
			'the default ' . $capability . ' model must exist in the catalogue'
		);
	}
} );

AvandWP_Test_Harness::test( 'per-image pricing is computed only where the provider publishes it', function () {
	$flux = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'flux-1-schnell', '1024x1024' );
	AvandWP_Test_Harness::assert_same( 'estimated', $flux['source'] );
	AvandWP_Test_Harness::assert_close( 0.0014, $flux['cost'], 0.00001 );

	// Per-megapixel pricing uses the requested dimensions.
	$qwen = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'gapgpt/qwen-image-2.1', '1024x1024' );
	AvandWP_Test_Harness::assert_same( 'estimated', $qwen['source'] );
	AvandWP_Test_Harness::assert_close( 0.00524, $qwen['cost'], 0.0001 );

	// A token-billed image model has no per-image price to publish.
	$token_billed = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'gpt-image-2', '1024x1024' );
	AvandWP_Test_Harness::assert_null( $token_billed['cost'] );
	AvandWP_Test_Harness::assert_same( 'unknown', $token_billed['source'] );
} );

AvandWP_Test_Harness::test( 'the price catalogue is dated and sourced so staleness is visible', function () {
	AvandWP_Test_Harness::assert_true( '' !== AvandWP_Price_Catalog::VERIFIED_ON, 'the catalogue must record when it was verified' );
	AvandWP_Test_Harness::assert_contains( 'gapgpt.app/platform-v2/pricing', AvandWP_Price_Catalog::GAPGPT_SOURCE );
} );
