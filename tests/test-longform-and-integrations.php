<?php
/**
 * Tests for the long-form pipeline, the image client and the provider state.
 *
 * These classes sit on the boundary between the plugin and the outside world.
 * Everything asserted here is pure logic that decides *what* would be sent or
 * saved; no test in this file performs a network request.
 *
 * @package AvandWP
 */

$GLOBALS['avandwp_tests'] = $GLOBALS['avandwp_tests'] ?? array();

// ---------------------------------------------------------------------------
// Long-form pipeline
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'long-form pipeline is a bounded, ordered stage list',
	static function () {
		$pipeline = AvandWP_Longform_Generator::pipeline();

		AvandWP_Test_Harness::assert_true( count( $pipeline ) >= 16, 'the mandated pipeline has at least 16 stages' );

		// Order matters: drafting before assembling, measuring before gap-filling,
		// evaluating before correcting, and persisting last.
		$keys = array_keys( $pipeline );
		$expected_order = array(
			'validate_brief',
			'load_context',
			'plan_outline',
			'allocate_budgets',
			'write_sections',
			'assemble',
			'measure_length',
			'fill_gaps',
			'plan_links',
			'evaluate_quality',
			'auto_correct',
			're_evaluate',
			'generate_media',
			'build_seo_meta',
			'persist_draft',
		);

		$positions = array();

		foreach ( $expected_order as $stage ) {
			$positions[ $stage ] = array_search( $stage, $keys, true );
			AvandWP_Test_Harness::assert_true( false !== $positions[ $stage ], "stage exists: {$stage}" );
		}

		// Compare the relative order of the stages we care about.
		AvandWP_Test_Harness::assert_true(
			$positions['write_sections'] < $positions['measure_length'],
			'sections are written before the length is measured'
		);
		AvandWP_Test_Harness::assert_true(
			$positions['measure_length'] < $positions['fill_gaps'],
			'gaps are filled only after a real measurement'
		);
		AvandWP_Test_Harness::assert_true(
			$positions['evaluate_quality'] < $positions['auto_correct'],
			'correction follows evaluation, never guessing'
		);
		AvandWP_Test_Harness::assert_true(
			$positions['auto_correct'] < $positions['re_evaluate'],
			'correction is followed by a fresh evaluation'
		);
		AvandWP_Test_Harness::assert_true(
			$positions['persist_draft'] === count( $keys ) - 1,
			'persisting the draft is the final stage'
		);
	}
);

AvandWP_Test_Harness::test(
	'request budget scales with the brief and stays inside the documented ceiling',
	static function () {
		$small = AvandWP_Longform_Generator::request_budget(
			array( 'length_target' => 800, 'continue_until_target' => 0 )
		);
		$large = AvandWP_Longform_Generator::request_budget(
			array( 'length_target' => 12000, 'continue_until_target' => 1 )
		);

		AvandWP_Test_Harness::assert_true( is_int( $small ) && is_int( $large ), 'budget is an integer' );
		AvandWP_Test_Harness::assert_true( $small >= 6 && $small <= 60, 'small brief budget stays inside 6..60' );
		AvandWP_Test_Harness::assert_true( $large >= $small, 'a larger brief never receives a smaller budget' );
		AvandWP_Test_Harness::assert_true( $large <= 60, 'budget never exceeds the documented ceiling' );

		// The budget must be reachable from outside the class, because the admin
		// screen shows the ceiling before the user spends anything.
		$method = new ReflectionMethod( 'AvandWP_Longform_Generator', 'request_budget' );
		AvandWP_Test_Harness::assert_true( $method->isPublic(), 'request_budget() is public' );
		AvandWP_Test_Harness::assert_true( $method->isStatic(), 'request_budget() is static' );
	}
);

AvandWP_Test_Harness::test(
	'long-form honours the default length and the published bounds',
	static function () {
		$defaults = AvandWP_Activator::default_settings();

		AvandWP_Test_Harness::assert_same(
			4500,
			AvandWP_Article_Brief::DEFAULT_LENGTH,
			'the product default is 4,500'
		);
		AvandWP_Test_Harness::assert_same(
			AvandWP_Article_Brief::DEFAULT_LENGTH,
			(int) $defaults['longform_default_length'],
			'the stored default matches the brief default'
		);

		// The token ceiling must be able to hold a 4,500-word article, otherwise
		// the requested length is unreachable by construction.
		$needed = AvandWP_Article_Brief::estimate_output_tokens( AvandWP_Article_Brief::DEFAULT_LENGTH );
		AvandWP_Test_Harness::assert_true(
			$needed <= AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS,
			'a 4,500-word article fits under the hard output ceiling'
		);
		AvandWP_Test_Harness::assert_true(
			$needed > 4096,
			'the estimate exceeds the legacy 4,096 ceiling (proving the old clamp was the blocker)'
		);
		AvandWP_Test_Harness::assert_true(
			(int) $defaults['max_tokens'] >= 4096,
			'the shipped max_tokens default no longer clamps output to 4,096'
		);
	}
);

AvandWP_Test_Harness::test(
	'long-form always persists a draft unless publication is explicitly confirmed',
	static function () {
		$draft = AvandWP_Article_Brief::from_array(
			array( 'topic' => 'موضوع آزمایشی', 'post_status' => 'publish' )
		);

		// Publication without an explicit confirmation is refused outright. It is
		// never silently downgraded, because a caller that asked to publish and
		// silently received a draft would misreport the outcome.
		AvandWP_Test_Harness::assert_true( is_wp_error( $draft ), 'publish without confirmation is refused' );
		AvandWP_Test_Harness::assert_same(
			'avandwp_brief_publish_unconfirmed',
			$draft->get_error_code(),
			'the refusal carries a specific, actionable error code'
		);

		$review = AvandWP_Article_Brief::from_array( array( 'topic' => 'موضوع آزمایشی', 'post_status' => 'pending' ) );
		AvandWP_Test_Harness::assert_true( ! is_wp_error( $review ), 'pending review needs no extra confirmation' );
		AvandWP_Test_Harness::assert_same( 'pending', $review['post_status'], 'pending is preserved' );

		$unknown = AvandWP_Article_Brief::from_array( array( 'topic' => 'موضوع آزمایشی', 'post_status' => 'trash' ) );
		AvandWP_Test_Harness::assert_true( ! is_wp_error( $unknown ), 'an unknown status is not fatal' );
		AvandWP_Test_Harness::assert_same( 'draft', $unknown['post_status'], 'an unknown status falls back to draft' );

		$confirmed = AvandWP_Article_Brief::from_array(
			array( 'topic' => 'موضوع آزمایشی', 'post_status' => 'publish', 'confirm_publish' => 1 )
		);

		AvandWP_Test_Harness::assert_true( ! is_wp_error( $confirmed ), 'confirmed publication is accepted' );
		AvandWP_Test_Harness::assert_same( 'publish', $confirmed['post_status'], 'confirmed publication is honoured' );
	}
);

// ---------------------------------------------------------------------------
// Job queue: continuation, retry and honest progress
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'job queue reports progress without inventing a percentage',
	static function () {
		AvandWP_Test_WP_State::reset();

		// A stage that cannot be measured in percent must store null, not 0:
		// a fabricated 0 % would look like "nothing happened" to the user.
		AvandWP_Job_Queue::report_progress( 7, 'crawl', null, array( 'crawled' => 3 ) );

		$update = AvandWP_Test_WP_State::last_query( 'update' );

		AvandWP_Test_Harness::assert_true( is_array( $update ), 'report_progress() wrote to the jobs table' );
		AvandWP_Test_Harness::assert_same( null, $update['args']['data']['progress'], 'an unmeasurable percent is stored as NULL' );
		AvandWP_Test_Harness::assert_same( 'crawl', $update['args']['data']['stage'], 'the stage slug is stored' );
		AvandWP_Test_Harness::assert_contains( 'crawled', (string) $update['args']['data']['progress_data'], 'progress data is persisted as JSON' );

		// A measured percentage is clamped into range.
		AvandWP_Job_Queue::report_progress( 7, 'section', 140 );

		$clamped = AvandWP_Test_WP_State::last_query( 'update' );

		AvandWP_Test_Harness::assert_same( 100, $clamped['args']['data']['progress'], 'a percentage above 100 is clamped' );

		AvandWP_Job_Queue::report_progress( 7, 'section', -5 );

		$floor = AvandWP_Test_WP_State::last_query( 'update' );

		AvandWP_Test_Harness::assert_same( 0, $floor['args']['data']['progress'], 'a negative percentage is clamped to 0' );

		// A missing job id must be a no-op rather than writing to row 0.
		$before = count( AvandWP_Test_WP_State::queries( 'update' ) );
		AvandWP_Job_Queue::report_progress( 0, 'section', 10 );
		AvandWP_Test_Harness::assert_same( $before, count( AvandWP_Test_WP_State::queries( 'update' ) ), 'job id 0 writes nothing' );
	}
);

AvandWP_Test_Harness::test(
	'every stage slug reported by the long-form pipeline has a human label',
	static function () {
		$label_for = static function ( $stage ) {
			$method = new ReflectionMethod( 'AvandWP_Job_Queue', 'stage_label' );
			return $method->invoke( null, $stage );
		};

		foreach ( array( 'queued', 'starting', 'save', 'done', 'section' ) as $stage ) {
			$label = $label_for( $stage );

			AvandWP_Test_Harness::assert_true( is_string( $label ) && '' !== $label, "stage '{$stage}' has a label" );
			AvandWP_Test_Harness::assert_true( $label !== $stage, "stage '{$stage}' is translated for the UI" );
		}

		// An unknown stage falls back to its own name rather than a fake label.
		AvandWP_Test_Harness::assert_same( 'zzz_unknown', $label_for( 'zzz_unknown' ), 'unknown stages are shown verbatim' );
	}
);

AvandWP_Test_Harness::test(
	'continuation jobs can exclude their own id from deduplication',
	static function () {
		AvandWP_Test_WP_State::reset();

		// 1) A live duplicate is still refused: the lookup finds another job.
		AvandWP_Test_WP_State::queue_db( 'get_var', 55 );

		$existing = AvandWP_Job_Queue::enqueue(
			'site_audit',
			array( 'run_id' => 9 ),
			array( 'dedupe_key' => 'site_audit_9' )
		);

		AvandWP_Test_Harness::assert_same( 55, $existing, 'an active duplicate returns the existing job id instead of queueing again' );
		AvandWP_Test_Harness::assert_same( 0, count( AvandWP_Test_WP_State::queries( 'insert' ) ), 'no row is inserted for a duplicate' );

		// 2) A continuation job excludes itself, so the crawl chain continues.
		AvandWP_Test_WP_State::reset();
		AvandWP_Test_WP_State::queue_db( 'get_var', 0 );
		AvandWP_Test_WP_State::queue_db( 'insert', 1 );
		AvandWP_Test_WP_State::queue_db( 'insert_id', 77 );

		$new_id = AvandWP_Job_Queue::enqueue(
			'site_audit',
			array( 'run_id' => 9 ),
			array(
				'dedupe_key'     => 'site_audit_9',
				'exclude_job_id' => 42,
			)
		);

		AvandWP_Test_Harness::assert_same( 77, $new_id, 'the continuation job is created' );

		$lookup = AvandWP_Test_WP_State::last_query( 'get_var' );

		AvandWP_Test_Harness::assert_true( is_array( $lookup ), 'the dedupe lookup ran' );
		AvandWP_Test_Harness::assert_contains( 'id != 42', $lookup['sql'], 'the caller job id is excluded from the dedupe lookup' );

		// The enqueue itself is the row inserted into the jobs table; the logger
		// writes to the activity log afterwards, so pick by table.
		$job_insert = null;

		foreach ( AvandWP_Test_WP_State::queries( 'insert' ) as $entry ) {
			if ( 'wp_avandwp_jobs' === $entry['sql'] ) {
				$job_insert = $entry;
				break;
			}
		}

		AvandWP_Test_Harness::assert_true( is_array( $job_insert ), 'a job row was inserted' );
		AvandWP_Test_Harness::assert_same( 'site_audit_9', $job_insert['args']['data']['dedupe_key'], 'the continuation keeps the same dedupe key' );
		AvandWP_Test_Harness::assert_same( 'pending', $job_insert['args']['data']['status'], 'the continuation is queued as pending' );
		AvandWP_Test_Harness::assert_same( 'queued', $job_insert['args']['data']['stage'], 'the continuation starts at the queued stage' );
		AvandWP_Test_Harness::assert_same( 'site_audit', $job_insert['args']['data']['job_type'], 'the continuation keeps the job type' );
	}
);

// ---------------------------------------------------------------------------
// Image client boundaries
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'image client derives mime and extension from the detected bytes',
	static function () {
		$png = "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 200 );

		$detected = AvandWP_Outbound_Guard::detect_image_mime( $png );

		AvandWP_Test_Harness::assert_same( 'image/png', $detected, 'PNG bytes are detected as image/png' );
		AvandWP_Test_Harness::assert_true( is_string( $detected ), 'detect_image_mime() returns a string, not an array' );

		$mimes = AvandWP_Outbound_Guard::allowed_image_mimes();
		AvandWP_Test_Harness::assert_same( 'png', $mimes[ $detected ], 'the allow-list maps the MIME to an extension' );

		// The client must consume that string correctly. Calling the private
		// normaliser directly is what caught the original defect (it indexed the
		// string as an array, so every saved image received a broken mime).
		$finalize = new ReflectionMethod( 'AvandWP_Image_Client', 'finalize_bytes' );

		$result = $finalize->invoke( new AvandWP_Image_Client(), $png, 'gpt-image-2', '1024x1024' );

		AvandWP_Test_Harness::assert_true( is_array( $result ), 'valid PNG bytes are accepted' );
		AvandWP_Test_Harness::assert_same( 'image/png', $result['mime'], 'the saved attachment mime is image/png' );
		AvandWP_Test_Harness::assert_same( 'png', $result['ext'], 'the saved file extension is png' );

		// Non-image bytes must be refused, not stored with a guessed type.
		$rejected = $finalize->invoke( new AvandWP_Image_Client(), str_repeat( 'not-an-image-', 30 ), 'gpt-image-2', '1024x1024' );

		AvandWP_Test_Harness::assert_true( is_wp_error( $rejected ), 'non-image bytes are refused' );
		AvandWP_Test_Harness::assert_same( 'avandwp_not_an_image', $rejected->get_error_code(), 'the refusal names the reason' );

		// Truncated payloads are refused before any sniffing.
		$tiny = $finalize->invoke( new AvandWP_Image_Client(), 'x', 'flux-1-schnell', '1024x1024' );

		AvandWP_Test_Harness::assert_true( is_wp_error( $tiny ), 'a payload under the minimum size is refused' );
		AvandWP_Test_Harness::assert_same( 'avandwp_image_too_small', $tiny->get_error_code(), 'the size refusal is explicit' );
	}
);

AvandWP_Test_Harness::test(
	'image cost is reported only when a verified rate exists',
	static function () {
		$priced = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'flux-1-schnell', '1024x1024' );

		AvandWP_Test_Harness::assert_same( 0.0014, $priced['cost'], 'the published per-image rate is used as-is' );
		AvandWP_Test_Harness::assert_same( 'estimated', $priced['source'], 'the price is labelled as an estimate' );

		$unpriced = AvandWP_Price_Catalog::estimate_image( 'gapgpt', 'gpt-image-2', '1024x1024' );

		AvandWP_Test_Harness::assert_same( null, $unpriced['cost'], 'a model without a per-image rate has no invented cost' );
		AvandWP_Test_Harness::assert_same( 'unknown', $unpriced['source'], 'the unknown source is explicit' );

		// The settings screen must explain the token-billed case instead of
		// showing an empty note.
		$note = AvandWP_Price_Catalog::image_cost_note( 'gapgpt', 'gpt-image-2' );

		AvandWP_Test_Harness::assert_true( '' !== $note, 'the cost note is never empty' );
		AvandWP_Test_Harness::assert_true(
			false !== strpos( $note, 'توکن' ),
			'the note explains that gpt-image-2 is billed per token'
		);
		AvandWP_Test_Harness::assert_true(
			false === strpos( $note, '0.04' ) && false === strpos( $note, 'رایگان' ),
			'the note does not assert a made-up flat price'
		);

		$label = AvandWP_Price_Catalog::describe_text_model( 'gpt-5.6-terra', 'gapgpt' );
		AvandWP_Test_Harness::assert_true( false !== strpos( $label, '2' ) && false !== strpos( $label, '12' ), 'the model label carries the real input/output rates' );

		$missing = AvandWP_Price_Catalog::describe_text_model( 'gpt-4o-mini', 'gapgpt' );
		AvandWP_Test_Harness::assert_true(
			false !== strpos( $missing, 'ثبت نشده' ),
			'a model outside the verified catalogue is labelled as unverified, not as free'
		);
	}
);

// ---------------------------------------------------------------------------
// Settings surface
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'settings defaults cover the integrations and stay a pure getter',
	static function () {
		AvandWP_Test_WP_State::reset();

		$defaults = AvandWP_Activator::default_settings();

		foreach ( array( 'google_client_id', 'ga4_property_id', 'gsc_property' ) as $key ) {
			AvandWP_Test_Harness::assert_key( $key, $defaults, "defaults contain {$key}" );
			AvandWP_Test_Harness::assert_same( '', $defaults[ $key ], "{$key} defaults to an empty string" );
		}

		// Reading the defaults must not write anything: the previous shape
		// returned the array before the chat/Telegram seeding blocks, which made
		// them unreachable *and* mixed a write into a getter.
		AvandWP_Test_Harness::assert_same(
			array(),
			AvandWP_Test_WP_State::$options,
			'default_settings() performs no option writes'
		);

		AvandWP_Test_Harness::assert_same(
			array(),
			AvandWP_Test_WP_State::queries( 'update' ),
			'default_settings() issues no database writes'
		);

		// Seeding still happens, but through the dedicated activation path.
		AvandWP_Activator::init_default_options();

		AvandWP_Test_Harness::assert_true(
			isset( AvandWP_Test_WP_State::$options['avandwp_settings'] ),
			'init_default_options() writes the settings row'
		);
		AvandWP_Test_Harness::assert_true(
			isset( AvandWP_Test_WP_State::$options['avandwp_chat_settings'] ),
			'init_default_options() seeds chat defaults (the previously unreachable block)'
		);
		AvandWP_Test_Harness::assert_true(
			isset( AvandWP_Test_WP_State::$options['avandwp_telegram_settings'] ),
			'init_default_options() seeds Telegram defaults'
		);

		// An existing installation keeps its own values.
		AvandWP_Test_WP_State::reset();
		AvandWP_Test_WP_State::$options['avandwp_settings'] = array( 'model_gapgpt' => 'custom-model', 'max_tokens' => 1234 );

		AvandWP_Activator::init_default_options();

		$stored = AvandWP_Test_WP_State::$options['avandwp_settings'];

		AvandWP_Test_Harness::assert_same( 'custom-model', $stored['model_gapgpt'], 'a stored model choice is never replaced by the default' );
		AvandWP_Test_Harness::assert_same( 1234, $stored['max_tokens'], 'stored values win over new defaults' );
		AvandWP_Test_Harness::assert_key( 'google_client_id', $stored, 'new keys are added to an existing settings row' );
	}
);

AvandWP_Test_Harness::test(
	'the provider registry only advertises catalogue-verified models for gapgpt',
	static function () {
		$text = AvandWP_Price_Catalog::models_for( 'text', 'gapgpt' );

		AvandWP_Test_Harness::assert_true( ! empty( $text ), 'gapgpt advertises text models' );

		foreach ( $text as $entry ) {
			AvandWP_Test_Harness::assert_true(
				AvandWP_Price_Catalog::is_known_model( 'text', $entry['id'], 'gapgpt' ),
				"advertised model {$entry['id']} is in the verified catalogue"
			);
		}

		// Providers with no verified catalogue must return an empty list rather
		// than a guessed one.
		AvandWP_Test_Harness::assert_same(
			array(),
			AvandWP_Price_Catalog::models_for( 'text', 'openai' ),
			'the OpenAI slot advertises no unverified model list'
		);
	}
);

// ---------------------------------------------------------------------------
// Google integration state (no network)
// ---------------------------------------------------------------------------

AvandWP_Test_Harness::test(
	'google connection reports blocked instead of showing placeholder data',
	static function () {
		$status = AvandWP_Google_Auth::status();

		AvandWP_Test_Harness::assert_true( array_key_exists( 'configured', $status ), 'status exposes configured' );
		AvandWP_Test_Harness::assert_true( array_key_exists( 'missing', $status ), 'status exposes what is missing' );
		AvandWP_Test_Harness::assert_same( false, $status['connected'], 'a fresh install is not connected' );

		$readiness = AvandWP_Analytics_Data::readiness_report();

		AvandWP_Test_Harness::assert_same(
			'blocked_by_credentials',
			$readiness['gsc']['state'],
			'Search Console reports blocked_by_credentials, not an empty report'
		);
		AvandWP_Test_Harness::assert_same(
			'blocked_by_credentials',
			$readiness['ga4']['state'],
			'GA4 reports blocked_by_credentials'
		);

		$notes = implode( ' ', (array) $readiness['blocked_notes'] );
		AvandWP_Test_Harness::assert_true(
			false !== strpos( $notes, 'نه مقدار صفر' ) || false !== strpos( $notes, 'صفر' ),
			'the blocked note states that no zero value is displayed'
		);

		// A blocked readiness report must not carry fabricated rows.
		foreach ( array( 'gsc', 'ga4' ) as $key ) {
			AvandWP_Test_Harness::assert_true(
				! isset( $readiness[ $key ]['rows'] ),
				"the blocked {$key} report contains no data rows"
			);
		}
	}
);

AvandWP_Test_Harness::test(
	'the settings screen is driven by the registry, price catalogue and activator defaults',
	static function () {
		// The screen itself is a PHP template that needs a WordPress runtime, so
		// what is asserted here is the contract it renders from: every provider
		// the screen lists must resolve, and every model label must be priced or
		// explicitly marked as unpublished. A separate static check in
		// tools/lint.mjs guards the template's own source.
		foreach ( array( 'text', 'image' ) as $capability ) {
			$providers = AvandWP_Provider_Registry::for_capability( $capability, false );

			AvandWP_Test_Harness::assert_true( ! empty( $providers ), "the registry lists providers for {$capability}" );

			foreach ( $providers as $slug ) {
				$definition = AvandWP_Provider_Registry::get( $slug );

				AvandWP_Test_Harness::assert_true( is_array( $definition ), "provider {$slug} resolves" );
				AvandWP_Test_Harness::assert_true( '' !== (string) $definition['base_url'], "provider {$slug} has a base URL" );
			}
		}

		// Every advertised gapgpt model must carry either a price label or an
		// explicit "not published" label — never an empty string.
		foreach ( AvandWP_Price_Catalog::models_for( 'text', 'gapgpt' ) as $entry ) {
			$label = AvandWP_Price_Catalog::describe_text_model( $entry['id'], 'gapgpt' );

			AvandWP_Test_Harness::assert_true( '' !== trim( $label ), "model {$entry['id']} has a price label" );
		}

		// And every image model in the catalogue must produce a non-empty note.
		foreach ( AvandWP_Price_Catalog::models_for( 'image', 'gapgpt' ) as $entry ) {
			$note = AvandWP_Price_Catalog::image_cost_note( 'gapgpt', $entry['id'] );

			AvandWP_Test_Harness::assert_true( '' !== trim( $note ), "image model {$entry['id']} has a cost note" );
		}
	}
);
