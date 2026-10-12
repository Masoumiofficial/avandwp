<?php
/**
 * Provider registry, failure classification and Google integration tests.
 *
 * The classification tests protect a cost decision: retrying a request the
 * provider has already rejected as invalid spends money for nothing, while not
 * retrying a 429 leaves a working article half-generated.
 *
 * The Google tests protect an honesty decision: with no credentials the plugin
 * must report a blocked state, not zeros.
 */

AvandWP_Test_Harness::group( 'Provider registry (Module F)' );

AvandWP_Test_Harness::test(
	'every declared provider has a verified definition',
	function () {
		$providers = AvandWP_Provider_Registry::all();

		foreach ( $providers as $slug => $provider ) {
			AvandWP_Test_Harness::assert_key( 'label', $provider, $slug . ' needs a label' );
			AvandWP_Test_Harness::assert_key( 'base_url', $provider, $slug . ' needs a base URL' );
			AvandWP_Test_Harness::assert_key( 'adapter', $provider, $slug . ' needs an adapter family' );
			AvandWP_Test_Harness::assert_true(
				in_array( $provider['adapter'], array( 'openai_compatible', 'gemini', 'claude', 'fal_run', 'stability' ), true ),
				$slug . ' must use a known adapter'
			);
			AvandWP_Test_Harness::assert_true( ! empty( $provider['capabilities'] ), $slug . ' must declare capabilities' );
		}
	}
);

AvandWP_Test_Harness::test(
	'GapGPT uses the documented base URL and alternative host',
	function () {
		$provider = AvandWP_Provider_Registry::get( 'gapgpt' );

		AvandWP_Test_Harness::assert_same( 'https://api.gapgpt.app/v1', $provider['base_url'] );
		AvandWP_Test_Harness::assert_true( in_array( 'https://api.gapapi.com/v1', $provider['backup_urls'], true ) );
	}
);

AvandWP_Test_Harness::test(
	'an unknown provider slug is rejected rather than defaulted',
	function () {
		AvandWP_Test_Harness::assert_false( AvandWP_Provider_Registry::is_known( 'some-random-provider' ) );
		AvandWP_Test_Harness::assert_null( AvandWP_Provider_Registry::get( 'some-random-provider' ) );

		$resolved = AvandWP_Provider_Registry::resolve_model( 'text', 'some-random-provider', 'x' );

		AvandWP_Test_Harness::assert_true( is_wp_error( $resolved ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_invalid_provider', $resolved->get_error_code() );
	}
);

AvandWP_Test_Harness::test(
	'regression guard: dall-e-3 is NOT an accepted GapGPT image model',
	function () {
		// The 1.0.0 image client hardcoded `dall-e-3`. It is absent from
		// GapGPT's published catalogue, so the request could only fail — after
		// being billed. This test fails if it is ever reintroduced.
		AvandWP_Test_Harness::assert_false(
			AvandWP_Price_Catalog::is_known_model( 'image', 'dall-e-3', 'gapgpt' ),
			'dall-e-3 must not be treated as a known GapGPT model'
		);

		$resolved = AvandWP_Provider_Registry::resolve_model( 'image', 'gapgpt', 'dall-e-3' );

		AvandWP_Test_Harness::assert_true( is_wp_error( $resolved ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_unknown_model', $resolved->get_error_code() );
	}
);

AvandWP_Test_Harness::test(
	'a catalogue model resolves successfully and reports its verification date',
	function () {
		$default = AvandWP_Price_Catalog::default_model( 'image', 'gapgpt' );

		AvandWP_Test_Harness::assert_true( '' !== $default, 'the catalogue must publish an image default' );

		$resolved = AvandWP_Provider_Registry::resolve_model( 'image', 'gapgpt', $default );

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $resolved ) );
		AvandWP_Test_Harness::assert_true( $resolved['verified'] );
		AvandWP_Test_Harness::assert_same( $default, $resolved['model'] );
	}
);

AvandWP_Test_Harness::test(
	'a text model is never suggested for an image request',
	function () {
		$resolved = AvandWP_Provider_Registry::resolve_model( 'image', 'gemini', 'gemini-2.5-flash' );

		// The Gemini slot declares text/embedding only; asking it for an image
		// must fail loudly instead of sending a text model to an image endpoint.
		AvandWP_Test_Harness::assert_true( is_wp_error( $resolved ) );
	}
);

AvandWP_Test_Harness::test(
	'authentication headers follow each provider convention',
	function () {
		$gapgpt = AvandWP_Provider_Registry::auth_headers( 'gapgpt', 'KEY' );
		AvandWP_Test_Harness::assert_same( 'Bearer KEY', $gapgpt['Authorization'] );

		$gemini = AvandWP_Provider_Registry::auth_headers( 'gemini', 'KEY' );
		AvandWP_Test_Harness::assert_same( 'KEY', $gemini['x-goog-api-key'] );
		AvandWP_Test_Harness::assert_false( isset( $gemini['Authorization'] ), 'the Gemini key must not travel as a bearer token' );

		$claude = AvandWP_Provider_Registry::auth_headers( 'claude', 'KEY' );
		AvandWP_Test_Harness::assert_same( 'KEY', $claude['x-api-key'] );
		AvandWP_Test_Harness::assert_key( 'anthropic-version', $claude );
	}
);

AvandWP_Test_Harness::test(
	'configured-only capability lookup ignores providers without a stored key',
	function () {
		AvandWP_Test_WP_State::reset();

		$without_keys = AvandWP_Provider_Registry::for_capability( 'text', true );

		AvandWP_Test_Harness::assert_same( array(), $without_keys, 'no keys stored means no provider is offered' );

		AvandWP_Vault::set_key( 'gapgpt', 'sk-test-key-1234567890' );

		$with_keys = AvandWP_Provider_Registry::for_capability( 'text', true );

		AvandWP_Test_Harness::assert_same( array( 'gapgpt' ), $with_keys );
	}
);

AvandWP_Test_Harness::group( 'Failure classification (F-06 / F-09)' );

AvandWP_Test_Harness::test(
	'rate limits and server faults are retryable',
	function () {
		$rate = AvandWP_AI_Client::classify_failure( 429, 'too many requests' );
		AvandWP_Test_Harness::assert_same( 'rate_limit', $rate['reason'] );
		AvandWP_Test_Harness::assert_true( $rate['retryable'] );

		$server = AvandWP_AI_Client::classify_failure( 503, 'upstream unavailable' );
		AvandWP_Test_Harness::assert_same( 'server', $server['reason'] );
		AvandWP_Test_Harness::assert_true( $server['retryable'] );

		$network = AvandWP_AI_Client::classify_failure( 0, 'connection reset' );
		AvandWP_Test_Harness::assert_same( 'network', $network['reason'] );
		AvandWP_Test_Harness::assert_true( $network['retryable'] );
	}
);

AvandWP_Test_Harness::test(
	'authentication failures are NOT retryable',
	function () {
		foreach ( array( 401, 403 ) as $code ) {
			$classified = AvandWP_AI_Client::classify_failure( $code, 'invalid api key' );

			AvandWP_Test_Harness::assert_same( 'auth', $classified['reason'] );
			AvandWP_Test_Harness::assert_false( $classified['retryable'], 'an invalid key will not become valid by retrying' );
		}
	}
);

AvandWP_Test_Harness::test(
	'content refusals are distinguished from malformed requests',
	function () {
		$refusal = AvandWP_AI_Client::classify_failure( 400, 'Your request was rejected as a result of our safety system.' );
		AvandWP_Test_Harness::assert_same( 'content', $refusal['reason'] );
		AvandWP_Test_Harness::assert_false( $refusal['retryable'] );

		$malformed = AvandWP_AI_Client::classify_failure( 400, 'invalid request: model not found' );
		AvandWP_Test_Harness::assert_same( 'invalid_request', $malformed['reason'] );
		AvandWP_Test_Harness::assert_false( $malformed['retryable'] );
	}
);

AvandWP_Test_Harness::group( 'Cost integrity (F-08)' );

AvandWP_Test_Harness::test(
	'an unknown model produces no cost figure rather than a guess',
	function () {
		$estimate = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', 'totally-made-up-model', 1000, 1000 );

		AvandWP_Test_Harness::assert_null( $estimate['cost'], 'no published price means no number' );
		AvandWP_Test_Harness::assert_same( 'unknown', $estimate['source'] );
		AvandWP_Test_Harness::assert_true( '' !== $estimate['note'], 'the reason must be stated' );
	}
);

AvandWP_Test_Harness::test(
	'a catalogue model produces a sourced estimate',
	function () {
		$model    = AvandWP_Price_Catalog::default_model( 'text', 'gapgpt' );
		$estimate = AvandWP_Price_Catalog::estimate_tokens( 'gapgpt', $model, 1000, 1000 );

		AvandWP_Test_Harness::assert_true( is_float( $estimate['cost'] ) || is_int( $estimate['cost'] ) );
		AvandWP_Test_Harness::assert_same( 'estimated', $estimate['source'] );
	}
);

AvandWP_Test_Harness::test(
	'a published per-image rate produces a numeric estimate',
	function () {
		$estimate = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'flux-1-schnell', '1024x1024' );

		AvandWP_Test_Harness::assert_true( null !== $estimate['cost'] );
		AvandWP_Test_Harness::assert_same( 'estimated', $estimate['source'] );
		AvandWP_Test_Harness::assert_close( 0.0014, (float) $estimate['cost'], 0.000001 );
	}
);

AvandWP_Test_Harness::test(
	'a per-megapixel rate scales with the requested size',
	function () {
		$small = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'gapgpt/qwen-image-2.1', '1024x1024' );
		$large = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'gapgpt/qwen-image-2.1', '1536x1024' );

		AvandWP_Test_Harness::assert_true( null !== $small['cost'] && null !== $large['cost'] );
		AvandWP_Test_Harness::assert_true( $large['cost'] > $small['cost'], 'a bigger image must cost more' );
	}
);

AvandWP_Test_Harness::test(
	'the default image model reports an unknown cost rather than inventing one',
	function () {
		$model    = AvandWP_Price_Catalog::default_model( 'image', 'gapgpt' );
		$estimate = AvandWP_Price_Catalog::estimate_image( 'gapgpt', $model, '1536x1024' );

		// GapGPT publishes an identifier for this model but no per-image price.
		// The honest output is "no figure", with the reason attached — the
		// opposite of the baseline, which stored a fabricated cost for every
		// image it generated.
		AvandWP_Test_Harness::assert_null( $estimate['cost'] );
		AvandWP_Test_Harness::assert_same( 'unknown', $estimate['source'] );
		AvandWP_Test_Harness::assert_contains( 'منتشر نشده', $estimate['note'] );
	}
);

AvandWP_Test_Harness::group( 'SEO plugin adapter (F-14)' );

AvandWP_Test_Harness::test(
	'no active SEO plugin means no writes, with a stated reason',
	function () {
		AvandWP_Test_WP_State::reset();

		AvandWP_Test_Harness::assert_same( 'none', AvandWP_SEO_Adapter::active_plugin() );

		$support = AvandWP_SEO_Adapter::write_support();

		AvandWP_Test_Harness::assert_false( $support['supported'] );
		AvandWP_Test_Harness::assert_contains( 'هیچ افزونه سئویی فعال نیست', $support['message'] );

		AvandWP_Test_WP_State::add_post( 55 );

		$write = AvandWP_SEO_Adapter::set_meta( 55, array( 'title' => 'عنوان', 'description' => 'توضیح' ) );

		AvandWP_Test_Harness::assert_same( array(), $write['written'], 'nothing may be written without an owner plugin' );
		AvandWP_Test_Harness::assert_same( '', (string) get_post_meta( 55, '_yoast_wpseo_title', true ) );
		AvandWP_Test_Harness::assert_same( '', (string) get_post_meta( 55, 'rank_math_title', true ) );
	}
);

AvandWP_Test_Harness::test(
	'meta keys are the owning plugin\'s own keys, and the plugin never writes the other one',
	function () {
		$yoast    = AvandWP_SEO_Adapter::meta_keys( 'yoast' );
		$rankmath = AvandWP_SEO_Adapter::meta_keys( 'rankmath' );

		AvandWP_Test_Harness::assert_same( '_yoast_wpseo_title', $yoast['title'] );
		AvandWP_Test_Harness::assert_same( '_yoast_wpseo_metadesc', $yoast['description'] );
		AvandWP_Test_Harness::assert_same( 'rank_math_title', $rankmath['title'] );
		AvandWP_Test_Harness::assert_same( 'rank_math_description', $rankmath['description'] );

		AvandWP_Test_Harness::assert_true( $yoast['title'] !== $rankmath['title'] );
	}
);

AvandWP_Test_Harness::test(
	'All in One SEO is reported as table-backed rather than silently written',
	function () {
		$keys = AvandWP_SEO_Adapter::meta_keys( 'aioseo' );

		AvandWP_Test_Harness::assert_same( '', $keys['title'], 'AIOSEO stores SEO data in its own table, not in post meta' );
	}
);

AvandWP_Test_Harness::test(
	'the FAQ schema decision defers to a capable SEO plugin',
	function () {
		AvandWP_Test_Harness::assert_true( is_bool( AvandWP_SEO_Adapter::plugin_outputs_faq_schema() ) );

		// With no SEO plugin active, nothing else owns the schema output, so this
		// plugin may emit an FAQ graph itself. With a capable plugin active the
		// answer is the opposite, which is what prevents the duplicate FAQPage
		// block found in the 1.0.0 output (F-14).
		AvandWP_Test_Harness::assert_false(
			AvandWP_SEO_Adapter::plugin_outputs_faq_schema(),
			'with no SEO plugin there is no other owner of the FAQ graph'
		);

		$report = AvandWP_SEO_Adapter::report();

		AvandWP_Test_Harness::assert_key( 'plugin', $report );
		AvandWP_Test_Harness::assert_key( 'write_message', $report );
	}
);

AvandWP_Test_Harness::group( 'Google integrations (Modules H and I)' );

AvandWP_Test_Harness::test(
	'without credentials the integration reports a blocked state, not zeros',
	function () {
		AvandWP_Test_WP_State::reset();

		$readiness = AvandWP_Google_Auth::readiness();

		AvandWP_Test_Harness::assert_false( $readiness['ready'] );
		AvandWP_Test_Harness::assert_true( count( $readiness['missing'] ) >= 2, 'both the client id and the secret must be reported as missing' );

		AvandWP_Test_Harness::assert_false( AvandWP_Google_Auth::is_connected() );
	}
);

AvandWP_Test_Harness::test(
	'the connection status never contains a token',
	function () {
		AvandWP_Test_WP_State::reset();

		$status = AvandWP_Google_Auth::status();

		foreach ( array_keys( $status ) as $key ) {
			AvandWP_Test_Harness::assert_not_contains( 'token', strtolower( (string) $key ) );
			AvandWP_Test_Harness::assert_not_contains( 'secret', strtolower( (string) $key ) );
		}

		AvandWP_Test_Harness::assert_false( $status['connected'] );
	}
);

AvandWP_Test_Harness::test(
	'only read-only Google scopes are requested',
	function () {
		foreach ( AvandWP_Google_Auth::scopes() as $scope ) {
			AvandWP_Test_Harness::assert_contains( '.readonly', $scope );
		}
	}
);

AvandWP_Test_Harness::test(
	'the OAuth redirect URI is the plugin\'s own admin page',
	function () {
		AvandWP_Test_Harness::assert_same(
			'https://example.com/wp-admin/admin.php?page=avandwp-integrations&avandwp_oauth=google',
			AvandWP_Google_Auth::redirect_uri()
		);
	}
);

AvandWP_Test_Harness::test(
	'an unconfigured GA4 property produces an explicit error',
	function () {
		AvandWP_Test_WP_State::reset();

		$analytics = new AvandWP_Analytics_Data();

		$error = $analytics->selected_property();

		AvandWP_Test_Harness::assert_true( is_wp_error( $error ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_ga4_no_property', $error->get_error_code() );

		update_option( 'avandwp_settings', array( 'ga4_property_id' => 'properties/123' ) );

		$malformed = $analytics->selected_property();

		AvandWP_Test_Harness::assert_true( is_wp_error( $malformed ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_ga4_bad_property', $malformed->get_error_code() );

		update_option( 'avandwp_settings', array( 'ga4_property_id' => '123456789' ) );

		AvandWP_Test_Harness::assert_same( '123456789', $analytics->selected_property() );
	}
);

AvandWP_Test_Harness::test(
	'GA4 helpers declare the metrics they label, and refuse to invent others',
	function () {
		$labels = AvandWP_Analytics_Data::metric_labels();

		AvandWP_Test_Harness::assert_key( 'sessions', $labels );
		AvandWP_Test_Harness::assert_key( 'totalUsers', $labels );
		AvandWP_Test_Harness::assert_true( ! isset( $labels['madeUpMetric'] ) );
	}
);

AvandWP_Test_Harness::test(
	'the readiness report names the blocking reason in Persian',
	function () {
		AvandWP_Test_WP_State::reset();

		$report = AvandWP_Analytics_Data::readiness_report();

		AvandWP_Test_Harness::assert_same( 'blocked_by_credentials', $report['gsc']['state'] );
		AvandWP_Test_Harness::assert_same( 'blocked_by_credentials', $report['ga4']['state'] );
		AvandWP_Test_Harness::assert_contains( 'Client ID', $report['gsc']['message'] );
		AvandWP_Test_Harness::assert_true( ! empty( $report['blocked_notes'] ) );

		// Explicitly: no fabricated figures are present anywhere in the report.
		$encoded = wp_json_encode( $report );
		AvandWP_Test_Harness::assert_not_contains( '"clicks"', $encoded );
		AvandWP_Test_Harness::assert_not_contains( '"sessions":0', $encoded );
	}
);

AvandWP_Test_Harness::test(
	'Search Console and Analytics classes are loadable and expose their endpoints',
	function () {
		AvandWP_Test_Harness::assert_same( 'https://www.googleapis.com/webmasters/v3', AvandWP_Search_Console::API_BASE );
		AvandWP_Test_Harness::assert_same( 'https://analyticsdata.googleapis.com/v1beta', AvandWP_Analytics_Data::DATA_BASE );
		AvandWP_Test_Harness::assert_same( 'https://analyticsadmin.googleapis.com/v1beta', AvandWP_Analytics_Data::ADMIN_BASE );
	}
);

AvandWP_Test_Harness::test(
	'a data call without a connection returns an error instead of an empty result set',
	function () {
		AvandWP_Test_WP_State::reset();

		$console = new AvandWP_Search_Console();

		$result = $console->query( array( 'days' => 7 ) );

		AvandWP_Test_Harness::assert_true( is_wp_error( $result ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_google_not_connected', $result->get_error_code() );
	}
);

// ---------------------------------------------------------------------------
// Search Console: response parsing, property selection and truncation honesty.
//
// These tests drive the real HTTP layer through the shim: a response is queued
// and the class must parse exactly that. Parsing is asserted against a known
// payload, so the tests prove the *parsing and the refusals*, not that Google
// answered — no live call is made anywhere in this suite.
// ---------------------------------------------------------------------------

/**
 * Put the suite into "connected to Google" state without an OAuth round trip:
 * a cached access token is what `AvandWP_Google_Auth::get()/post()` need.
 *
 * @return void
 */
function avandwp_test_connect_google() {
	set_transient( 'avandwp_google_access_token', 'test-access-token', 3600 );
	AvandWP_Search_Console::clear_cache();
}

AvandWP_Test_Harness::test(
	'the Search Console property list is parsed from the API response as-is',
	function () {
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'https://example.com/',
						'permissionLevel' => 'siteOwner',
					),
					array(
						'siteUrl'         => 'sc-domain:example.net',
						'permissionLevel' => 'siteFullUser',
					),
					// A malformed entry without a URL must be dropped, not
					// turned into an empty property.
					array( 'permissionLevel' => 'siteOwner' ),
				),
			)
		);

		$sites = ( new AvandWP_Search_Console() )->list_sites();

		AvandWP_Test_Harness::assert_true( is_array( $sites ) );
		AvandWP_Test_Harness::assert_same( 2, count( $sites ) );
		AvandWP_Test_Harness::assert_same( 'https://example.com/', $sites[0]['url'] );
		AvandWP_Test_Harness::assert_same( 'siteOwner', $sites[0]['permission'] );
		AvandWP_Test_Harness::assert_same( 'sc-domain:example.net', $sites[1]['url'] );

		$request = AvandWP_Test_HTTP::last_request();

		AvandWP_Test_Harness::assert_same( 'GET', $request['method'] );
		AvandWP_Test_Harness::assert_same( 'https://www.googleapis.com/webmasters/v3/sites', $request['url'] );
		AvandWP_Test_Harness::assert_same( 'Bearer test-access-token', $request['args']['headers']['Authorization'] );

		// Second call is served from the transient cache: a page render must not
		// spend API quota twice.
		$again = ( new AvandWP_Search_Console() )->list_sites();

		AvandWP_Test_Harness::assert_same( 2, count( $again ) );
		AvandWP_Test_Harness::assert_same( 1, count( AvandWP_Test_WP_State::$http_requests ) );
	}
);

AvandWP_Test_Harness::test(
	'a stored Search Console property must still exist in the account list',
	function () {
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'https://gone.example/' ) );

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'https://example.com/',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		$selected = ( new AvandWP_Search_Console() )->selected_site();

		AvandWP_Test_Harness::assert_true( is_wp_error( $selected ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_gsc_property_gone', $selected->get_error_code() );

		// A property that *is* in the list is returned verbatim.
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'https://example.com/' ) );

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'https://example.com/',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		AvandWP_Test_Harness::assert_same( 'https://example.com/', ( new AvandWP_Search_Console() )->selected_site() );
	}
);

AvandWP_Test_Harness::test(
	'without an explicit choice the property is matched by host, never guessed',
	function () {
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		AvandWP_Test_WP_State::$home_url = 'https://example.com';

		// No property belongs to this site: refuse rather than report another
		// site's numbers under this site's name.
		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'https://unrelated.test/',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		$none = ( new AvandWP_Search_Console() )->selected_site();

		AvandWP_Test_Harness::assert_true( is_wp_error( $none ) );
		AvandWP_Test_Harness::assert_same( 'avandwp_gsc_no_matching_property', $none->get_error_code() );

		// The `sc-domain:` form carries the host after the prefix and matches.
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		AvandWP_Test_WP_State::$home_url = 'https://example.com';

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'sc-domain:example.com',
						'permissionLevel' => 'siteOwner',
					),
					array(
						'siteUrl'         => 'https://unrelated.test/',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		AvandWP_Test_Harness::assert_same( 'sc-domain:example.com', ( new AvandWP_Search_Console() )->selected_site() );
	}
);

AvandWP_Test_Harness::test(
	'Search Analytics totals are summed honestly and flagged when the API truncates',
	function () {
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'sc-domain:example.com' ) );

		// 1) Property list, 2) search analytics response returning exactly the
		// requested row limit.
		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'sc-domain:example.com',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		AvandWP_Test_HTTP::queue_json(
			array(
				'rows' => array(
					array(
						'keys'        => array( 'کلمه اول' ),
						'clicks'      => 3,
						'impressions' => 30,
						'ctr'         => 0.1,
						'position'    => 5.0,
					),
					array(
						'keys'        => array( 'کلمه دوم' ),
						'clicks'      => 1,
						'impressions' => 10,
						'ctr'         => 0.1,
						'position'    => 7.0,
					),
				),
			)
		);

		$result = ( new AvandWP_Search_Console() )->query(
			array(
				'days'       => 7,
				'dimensions' => array( 'query' ),
				'row_limit'  => 2,
			)
		);

		AvandWP_Test_Harness::assert_true( is_array( $result ) );
		AvandWP_Test_Harness::assert_same( 2, $result['totals']['rows_returned'] );
		AvandWP_Test_Harness::assert_same( 4.0, $result['totals']['clicks'] );
		AvandWP_Test_Harness::assert_same( 40.0, $result['totals']['impressions'] );
		AvandWP_Test_Harness::assert_same( 0.1, $result['totals']['ctr'] );
		AvandWP_Test_Harness::assert_same( 5.5, $result['totals']['position'] );
		AvandWP_Test_Harness::assert_same( true, $result['totals']['truncated'] );
		AvandWP_Test_Harness::assert_contains( 'محدود', $result['note'] );
		AvandWP_Test_Harness::assert_same( 'Google Search Console API', $result['source'] );

		// The date range is derived from the API's own rule: Search Console data
		// is not final for the current or previous day.
		AvandWP_Test_Harness::assert_same( gmdate( 'Y-m-d', strtotime( '-2 days' ) ), $result['end_date'] );
		AvandWP_Test_Harness::assert_same( 7, (int) ( ( strtotime( $result['end_date'] ) - strtotime( $result['start_date'] ) ) / DAY_IN_SECONDS ) + 1 );

		// A response below the limit is not labelled as truncated.
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'sc-domain:example.com' ) );

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'sc-domain:example.com',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		AvandWP_Test_HTTP::queue_json(
			array(
				'rows' => array(
					array(
						'keys'        => array( 'تنها کلمه' ),
						'clicks'      => 2,
						'impressions' => 20,
						'ctr'         => 0.1,
						'position'    => 3.0,
					),
				),
			)
		);

		$small = ( new AvandWP_Search_Console() )->query(
			array(
				'days'       => 7,
				'dimensions' => array( 'query' ),
				'row_limit'  => 10,
			)
		);

		AvandWP_Test_Harness::assert_same( false, $small['totals']['truncated'] );

		// The request that went out is inspected, not trusted: the property is
		// encoded into the path and only the verified dimension is forwarded.
		$request = AvandWP_Test_HTTP::last_request();
		$sent    = json_decode( (string) $request['args']['body'], true );

		AvandWP_Test_Harness::assert_same(
			'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( 'sc-domain:example.com' ) . '/searchAnalytics/query',
			$request['url']
		);
		AvandWP_Test_Harness::assert_same( array( 'query' ), $sent['dimensions'] );
		AvandWP_Test_Harness::assert_same( 10, $sent['rowLimit'] );
		AvandWP_Test_Harness::assert_true( ! isset( $sent['type'] ) );

		// A dimension the API does not document is dropped before sending.
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'sc-domain:example.com' ) );

		AvandWP_Test_HTTP::queue_json(
			array(
				'siteEntry' => array(
					array(
						'siteUrl'         => 'sc-domain:example.com',
						'permissionLevel' => 'siteOwner',
					),
				),
			)
		);

		AvandWP_Test_HTTP::queue_json( array( 'rows' => array() ) );

		$filtered = ( new AvandWP_Search_Console() )->query(
			array(
				'days'       => 7,
				'dimensions' => array( 'query', 'madeUpDimension' ),
			)
		);

		AvandWP_Test_Harness::assert_same( array( 'query' ), $filtered['dimensions'] );

		$sent_again = json_decode( (string) AvandWP_Test_HTTP::last_request()['args']['body'], true );

		AvandWP_Test_Harness::assert_same( array( 'query' ), $sent_again['dimensions'] );
	}
);

AvandWP_Test_Harness::test(
	'an unstubbed network call fails loudly instead of returning fake data',
	function () {
		AvandWP_Test_WP_State::reset();
		avandwp_test_connect_google();

		update_option( 'avandwp_settings', array( 'gsc_property' => 'sc-domain:example.com' ) );

		// No response queued: the shim must refuse, and the class must surface
		// that as an error rather than an empty property list.
		$sites = ( new AvandWP_Search_Console() )->list_sites();

		AvandWP_Test_Harness::assert_true( is_wp_error( $sites ) );
	}
);
