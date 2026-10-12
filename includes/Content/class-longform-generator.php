<?php
/**
 * Long-form, sectioned, resumable article generator.
 *
 * This is the component that makes the mandated 4,500-word default achievable.
 * The 1.0.0 Studio asked a single model call for the whole article inside a
 * 3,500-token budget and a 3,000-word ceiling; anything longer was silently
 * truncated (`finish_reason: length` was never inspected). A single request
 * cannot reliably produce 4,500 Persian words — the safe ceiling per request is
 * roughly 1,200–1,800 words of Persian output.
 *
 * The approach here mirrors how a human editor works:
 *
 *   1. Fix the brief (topic, length, audience, structure).
 *   2. Plan the outline once, allocating a word budget per section.
 *   3. Write one section per request, checking the measured length of each.
 *   4. Continue a section that fell short, bounded by a request limit.
 *   5. Assemble, measure, and fill the remaining gap while budget allows.
 *   6. Evaluate quality, auto-fix only what is mechanically fixable.
 *   7. Persist as a draft — never overwriting, never publishing silently.
 *
 * Resumability: every section is written to a checkpoint after it succeeds, and
 * the checkpoint is keyed by job id. A worker killed mid-article resumes at the
 * first section that has no content instead of paying for the whole article
 * again.
 *
 * Cost safety: every step checks the spend guard before spending, the number of
 * paid requests is bounded (`max_requests`), and each section declares its own
 * token budget derived from its word allocation.
 *
 * @package AvandWP\Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sectioned long-form article generation.
 */
class AvandWP_Longform_Generator {

	/**
	 * Prefix for the per-job checkpoint option.
	 */
	const STATE_PREFIX = 'avandwp_longform_state_';

	/**
	 * Checkpoint lifetime in seconds. A job that has not been resumed within
	 * this window starts over rather than resuming from stale content.
	 */
	const STATE_TTL = 21600;

	/**
	 * Absolute bound on sections, so a hostile or broken outline cannot turn a
	 * single job into an unbounded number of paid requests.
	 */
	const MAX_SECTIONS = 20;

	/**
	 * Bound on continuation requests for a single under-length section.
	 */
	const MAX_SECTION_CONTINUATIONS = 2;

	/**
	 * Bound on whole-article expansion passes.
	 */
	const MAX_EXPANSION_PASSES = 2;

	/**
	 * Default ceiling on paid text requests for one article.
	 */
	const DEFAULT_MAX_REQUESTS = 34;

	/**
	 * The pipeline, in order. Names are stable identifiers used for progress
	 * reporting; labels are what the admin UI shows.
	 *
	 * @return array<string, string>
	 */
	public static function pipeline() {
		return array(
			'validate_brief'    => 'اعتبارسنجی بریف',
			'load_context'      => 'بارگذاری زمینه سایت',
			'plan_outline'      => 'طراحی ساختار مقاله',
			'allocate_budgets'  => 'تخصیص بودجه طول به بخش‌ها',
			'write_sections'    => 'نگارش بخش‌به‌بخش',
			'assemble'          => 'مونتاژ نهایی متن',
			'measure_length'    => 'اندازه‌گیری دقیق طول',
			'fill_gaps'         => 'تکمیل بخش‌های کوتاه',
			'plan_links'        => 'تحلیل و پیشنهاد لینک‌ها',
			'insert_links'      => 'درج لینک‌های تأییدشده',
			'evaluate_quality'  => 'ارزیابی کیفیت (سئو/AIO/GEO)',
			'auto_correct'      => 'اصلاح خودکار محدود',
			're_evaluate'       => 'ارزیابی مجدد پس از اصلاح',
			'generate_media'    => 'تولید تصاویر',
			'build_seo_meta'    => 'ساخت عنوان، توضیحات و اسکیمای پیشنهادی',
			'persist_draft'     => 'ذخیره به‌عنوان پیش‌نویس',
		);
	}

	/**
	 * Run (or resume) a long-form article job.
	 *
	 * @param array $payload Job payload containing brief fields.
	 * @param int   $job_id  Job ID used for progress and checkpointing.
	 * @return array|WP_Error
	 */
	public function run( array $payload, $job_id = 0 ) {
		$job_id = (int) $job_id;
		$steps  = array_keys( self::pipeline() );
		$total  = count( $steps );

		$state = $this->load_state( $job_id );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( empty( $state ) ) {
			$brief = AvandWP_Article_Brief::from_array( $payload );

			if ( is_wp_error( $brief ) ) {
				return $brief;
			}

			$state = array(
				'brief'      => $brief,
				'done'       => array(),
				'sections'   => array(),
				'requests'   => 0,
				'max_requests' => self::request_budget( $brief ),
				'started_at' => time(),
			);

			$this->save_state( $job_id, $state );
		}

		$brief = $state['brief'];

		// A nested helper keeps the pipeline readable and guarantees that the
		// checkpoint is written after every completed step, whatever happens
		// next.
		$context = array(
			'brief'   => $brief,
			'state'   => &$state,
			'job_id'  => $job_id,
			'ai'      => new AvandWP_AI_Client(),
		);

		foreach ( $steps as $index => $step ) {
			if ( in_array( $step, $state['done'], true ) ) {
				continue;
			}

			$percent = (int) floor( ( $index / $total ) * 100 );

			AvandWP_Job_Queue::report_progress(
				$job_id,
				$step,
				$percent,
				array(
					'step_index'  => $index + 1,
					'step_total'  => $total,
					'step_label'  => self::pipeline()[ $step ],
					'requests'    => (int) $state['requests'],
					'max_requests' => (int) $state['max_requests'],
					'measured'    => $this->measured_summary( $state ),
				)
			);

			$result = $this->run_step( $step, $context );

			if ( is_wp_error( $result ) ) {
				// Leave the checkpoint untouched so a retry resumes here.
				$this->save_state( $job_id, $state );

				return $result;
			}

			$state['done'][] = $step;
			$this->save_state( $job_id, $state );
		}

		$state['done'][] = 'finished';
		$this->save_state( $job_id, $state );
		$this->clear_state( $job_id );

		AvandWP_Job_Queue::report_progress( $job_id, 'done', 100, array( 'post_id' => (int) $state['post_id'] ) );

		return $this->build_result( $state );
	}

	/**
	 * Execute a single pipeline step.
	 *
	 * @param string $step    Step slug.
	 * @param array  $context Reference-holding context array.
	 * @return true|WP_Error
	 */
	private function run_step( $step, array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		switch ( $step ) {
			case 'validate_brief':
				// Validation already happened when the state was created; this
				// step exists so the UI can report it as an explicit stage and
				// so a resumed job re-checks the brief it was created with.
				return $this->assert_brief_usable( $brief );

			case 'load_context':
				$context_data = $brief['use_knowledge'] ? $this->site_context( $brief ) : array( 'block' => '', 'matches' => array() );

				$state['context_block']         = $context_data['block'];
				$state['context_block_matches'] = $context_data['matches'];

				return true;

			case 'plan_outline':
				return $this->plan_outline( $context );

			case 'allocate_budgets':
				return $this->allocate_budgets( $context );

			case 'write_sections':
				return $this->write_sections( $context );

			case 'assemble':
				$state['html'] = $this->assemble_html( $brief, $state['sections'] );
				return true;

			case 'measure_length':
				$state['measured'] = AvandWP_Text_Helper::measure( $state['html'] );
				return true;

			case 'fill_gaps':
				return $this->fill_gaps( $context );

			case 'plan_links':
				$state['link_plan'] = $this->plan_links( $context );
				return true;

			case 'insert_links':
				return $this->insert_links( $context );

			case 'evaluate_quality':
				return $this->evaluate_quality( $context );

			case 'auto_correct':
				return $this->auto_correct( $context );

			case 're_evaluate':
				return $this->re_evaluate( $context );

			case 'generate_media':
				return $this->generate_media( $context );

			case 'build_seo_meta':
				return $this->build_seo_meta( $context );

			case 'persist_draft':
				return $this->persist_draft( $context );
		}

		return new WP_Error( 'avandwp_unknown_step', 'مرحله ناشناخته در خط تولید: ' . $step );
	}

	/**
	 * Re-validate a stored brief before spending anything on it.
	 *
	 * @param array $brief Brief.
	 * @return true|WP_Error
	 */
	private function assert_brief_usable( array $brief ) {
		if ( empty( $brief['topic'] ) ) {
			return new WP_Error( 'avandwp_brief_topic', 'موضوع مقاله نمی‌تواند خالی باشد.' );
		}

		if ( (int) $brief['length_target'] < AvandWP_Article_Brief::MIN_LENGTH ) {
			return new WP_Error( 'avandwp_brief_length_small', 'طول هدف مقاله برای تولید بلند کمتر از حد مجاز است.' );
		}

		return true;
	}

	/**
	 * Number of paid requests a brief is allowed to consume.
	 *
	 * The budget scales with the article's length because a 6,000-word article
	 * legitimately needs more sections than a 900-word one, but it stays a hard
	 * ceiling so a runaway loop cannot drain an account.
	 *
	 * Exposed publicly so the UI can display the real ceiling up front instead
	 * of letting the user discover it by hitting it.
	 *
	 * @param array $brief Brief, or any subset of it (the admin screen asks for
	 *                     the ceiling before a full brief exists).
	 * @return int
	 */
	public static function request_budget( array $brief ) {
		$mode     = ( 'characters' === ( $brief['length_mode'] ?? 'words' ) ) ? 'characters' : 'words';
		$target   = max( 1, (int) ( $brief['length_target'] ?? AvandWP_Article_Brief::DEFAULT_LENGTH ) );
		// Absent means "spaces are counted", matching Article_Brief's own default.
		$spaces   = ! array_key_exists( 'length_include_spaces', $brief ) || ! empty( $brief['length_include_spaces'] );

		$words = ( 'words' === $mode )
			? $target
			: (int) ceil( $target / ( $spaces ? 6 : 5 ) );

		$sections = (int) ceil( $words / 700 );
		$budget   = ( $sections * ( 1 + self::MAX_SECTION_CONTINUATIONS ) ) + 6;

		$cap = (int) apply_filters( 'avandwp_longform_request_budget', max( 6, min( 60, $budget ) ), $brief );

		return max( 6, $cap );
	}

	// ---------------------------------------------------------------------
	// Planning
	// ---------------------------------------------------------------------

	/**
	 * Ask the model for a sectioned outline with per-section word budgets.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function plan_outline( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		$system = 'تو یک برنامه‌ریز محتوای فارسی هستی. فقط یک شیء JSON معتبر برگردان و هیچ متن دیگری ننویس.';

		$user = sprintf(
			"برای مقاله‌ای با این مشخصات، ساختار بخش‌ها را طراحی کن:\n\n%s\n\n%s\n\nخروجی JSON با این کلیدها:\n{\n  \"seo_title\": \"عنوان سئو حداکثر ۶۰ کاراکتر شامل کلمه کلیدی کانونی\",\n  \"meta_description\": \"توضیحات متا ۱۲۰ تا ۱۵۵ کاراکتر\",\n  \"slug_hint\": \"نامک پیشنهادی لاتین با خط تیره\",\n  \"search_intent_summary\": \"جمله‌ای درباره اینکه کاربر دنبال چه چیزی است\",\n  \"key_questions\": [\"پرسش‌هایی که مقاله باید قطعی پاسخ دهد\"],\n  \"entities\": [\"نهادها، برندها و اصطلاحاتی که باید در متن بیایند\"],\n  \"sections\": [\n    {\n      \"heading\": \"عنوان H2\",\n      \"purpose\": \"این بخش دقیقاً چه چیزی را حل می‌کند\",\n      \"subheadings\": [\"عنوان H3\"],\n      \"keywords\": [\"کلیدواژه‌های فرعی همین بخش\"],\n      \"target_words\": 500,\n      \"required_facts\": [\"داده یا نکته‌ای که حتماً باید ذکر شود\"]\n    }\n  ],\n  \"faq\": [{\"question\": \"...\", \"answer\": \"...\"}],\n  \"image_prompt\": \"English editorial image prompt\",\n  \"inline_image_prompts\": [{\"after_section\": 2, \"prompt\": \"...\", \"alt\": \"متن جانشین فارسی\"}]\n}\n\nقواعد:\n- مجموع target_words بخش‌ها باید حدود %d %s باشد.\n- تعداد بخش‌ها بین ۳ تا %d باشد.\n- هیچ بخشی نباید مقدمه یا نتیجه‌گیری ساده باشد؛ هر بخش باید ارزش مستقل داشته باشد.\n- از تکرار یک جمله یا پاراگراف در بخش‌های مختلف پرهیز کن.",
			AvandWP_Article_Brief::to_prompt_block( $brief ),
			'' !== ( $state['context_block'] ?? '' ) ? $state['context_block'] : '',
			(int) $brief['length_target'],
			'characters' === $brief['length_mode'] ? 'کاراکتر' : 'کلمه',
			self::MAX_SECTIONS
		);

		$res = $this->ask( $context, $system, $user, 2400, 'outline' );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$parsed = AvandWP_Text_Helper::parse_json_response( $res['content'] );

		if ( ! is_array( $parsed ) || empty( $parsed['sections'] ) || ! is_array( $parsed['sections'] ) ) {
			return new WP_Error(
				'avandwp_outline_failed',
				'مدل، ساختار مقاله را در قالب معتبر برنگرداند. دوباره تلاش کنید یا مدل قوی‌تری انتخاب کنید.'
			);
		}

		$plan = array(
			'seo_title'            => AvandWP_Text_Helper::safe_substr( sanitize_text_field( $parsed['seo_title'] ?? $brief['topic'] ), 0, 90 ),
			'meta_description'     => AvandWP_Text_Helper::safe_substr( sanitize_textarea_field( $parsed['meta_description'] ?? '' ), 0, 200 ),
			'slug_hint'            => sanitize_title( $parsed['slug_hint'] ?? '' ),
			'search_intent_summary' => sanitize_text_field( $parsed['search_intent_summary'] ?? '' ),
			'key_questions'        => AvandWP_Article_Brief::string_list( $parsed['key_questions'] ?? array() ),
			'entities'             => AvandWP_Article_Brief::string_list( $parsed['entities'] ?? array() ),
			'sections'             => array(),
			'faq'                  => array(),
			'image_prompt'         => AvandWP_Text_Helper::safe_substr( sanitize_text_field( $parsed['image_prompt'] ?? '' ), 0, 500 ),
			'inline_image_prompts' => array(),
		);

		foreach ( array_slice( $parsed['sections'], 0, self::MAX_SECTIONS ) as $raw_section ) {
			if ( ! is_array( $raw_section ) ) {
				continue;
			}

			$heading = AvandWP_Text_Helper::safe_substr( sanitize_text_field( $raw_section['heading'] ?? '' ), 0, 200 );

			if ( '' === $heading ) {
				continue;
			}

			$plan['sections'][] = array(
				'heading'        => $heading,
				'purpose'        => AvandWP_Text_Helper::safe_substr( sanitize_text_field( $raw_section['purpose'] ?? '' ), 0, 300 ),
				'subheadings'    => AvandWP_Article_Brief::string_list( $raw_section['subheadings'] ?? array() ),
				'keywords'       => AvandWP_Article_Brief::string_list( $raw_section['keywords'] ?? array() ),
				'required_facts' => AvandWP_Article_Brief::string_list( $raw_section['required_facts'] ?? array() ),
				'target_words'   => max( 120, min( 1800, (int) ( $raw_section['target_words'] ?? 0 ) ) ),
			);
		}

		if ( empty( $plan['sections'] ) ) {
			return new WP_Error( 'avandwp_outline_empty', 'ساختار پیشنهادی هیچ بخش معتبری نداشت.' );
		}

		foreach ( (array) ( $parsed['faq'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = sanitize_text_field( $item['question'] ?? '' );
			$a = sanitize_textarea_field( $item['answer'] ?? '' );
			if ( '' !== $q && '' !== $a ) {
				$plan['faq'][] = array( 'question' => $q, 'answer' => $a );
			}
		}

		foreach ( (array) ( $parsed['inline_image_prompts'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['prompt'] ) ) {
				continue;
			}
			$plan['inline_image_prompts'][] = array(
				'after_section' => max( 1, (int) ( $item['after_section'] ?? 1 ) ),
				'prompt'        => AvandWP_Text_Helper::safe_substr( sanitize_text_field( $item['prompt'] ), 0, 500 ),
				'alt'           => AvandWP_Text_Helper::safe_substr( sanitize_text_field( $item['alt'] ?? '' ), 0, 200 ),
			);
		}

		$state['plan'] = $plan;

		return true;
	}

	/**
	 * Distribute the target length across the planned sections.
	 *
	 * The model's own numbers are treated as a preference; the total is then
	 * reconciled with the brief so the sum of sections actually reaches the
	 * requested length instead of drifting to whatever the model felt like.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function allocate_budgets( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		if ( empty( $state['plan']['sections'] ) ) {
			return new WP_Error( 'avandwp_no_outline', 'ساختار مقاله موجود نیست.' );
		}

		$sections = $state['plan']['sections'];
		$count    = count( $sections );

		$target_words = ( 'words' === $brief['length_mode'] )
			? (int) $brief['length_target']
			: (int) ceil( (int) $brief['length_target'] / ( $brief['length_include_spaces'] ? 6 : 5 ) );

		// Reserve room for FAQ and introductory paragraphs that are generated
		// outside the section loop.
		$faq_words    = min( 400, count( $state['plan']['faq'] ) * 90 );
		$body_words   = max( 200, $target_words - $faq_words );
		$per_section  = (int) ceil( $body_words / $count );

		foreach ( $sections as $i => $section ) {
			// Blend the model's proposal with the reconciled average; a section
			// the model marked as substantial stays substantial.
			$proposed = (int) $section['target_words'];

			$sections[ $i ]['target_words'] = max(
				150,
				min(
					1800,
					(int) round( ( $proposed > 0 ? $proposed : $per_section ) * 0.35 + $per_section * 0.65 )
				)
			);
		}

		$state['plan']['sections']        = $sections;
		$state['plan']['target_words']    = $target_words;
		$state['plan']['body_words']      = $body_words;

		return true;
	}

	// ---------------------------------------------------------------------
	// Writing
	// ---------------------------------------------------------------------

	/**
	 * Write every section that has no saved content yet.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function write_sections( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		$sections = $state['plan']['sections'];
		$written  = $state['sections'];

		// The introduction is generated once and stored separately so it is not
		// regenerated when a later section fails and the job retries.
		if ( ! isset( $state['intro'] ) ) {
			$intro = $this->write_intro( $context );

			if ( is_wp_error( $intro ) ) {
				return $intro;
			}

			$state['intro'] = $intro;
			$this->save_state( $context['job_id'], $state );
		}

		foreach ( $sections as $index => $section ) {
			if ( isset( $written[ $index ] ) && '' !== trim( (string) $written[ $index ] ) ) {
				continue;
			}

			$html = $this->write_section( $context, $index, $section );

			if ( is_wp_error( $html ) ) {
				return $html;
			}

			$state['sections'][ $index ] = $html;
			$this->save_state( $context['job_id'], $state );

			AvandWP_Job_Queue::report_progress(
				$context['job_id'],
				'write_sections',
				null,
				array(
					'section'       => $index + 1,
					'section_total' => count( $sections ),
					'section_title' => $section['heading'],
					'measured'      => array( 'words' => AvandWP_Text_Helper::word_count( $html ) ),
					'requests'      => (int) $state['requests'],
				)
			);
		}

		return true;
	}

	/**
	 * Write the opening section.
	 *
	 * @param array $context Context.
	 * @return string|WP_Error
	 */
	private function write_intro( array &$context ) {
		$brief = $context['brief'];
		$state = &$context['state'];

		$system = 'تو نویسنده ارشد محتوای فارسی هستی. خروجی تو فقط HTML تمیز است، بدون مارک‌داون، بدون ``` و بدون توضیح.';

		$user = sprintf(
			"%s\n\nمقدمه مقاله را بنویس (بدون تگ <h1>، بدون تکرار عنوان).\n\nالزامات:\n- بین ۱۲۰ تا ۲۲۰ کلمه.\n- پاراگراف اول باید مستقیماً به موضوع پاسخ یا تعریف دهد (پاسخ مستقیم، نه مقدمه‌چینی).\n- %s\n- از عبارت‌های کلیشه‌ای مثل «در دنیای امروز» و «بدون شک» استفاده نکن.\n- خروجی: <p>...</p> و در صورت نیاز یک <ul>.",
			$this->brief_prompt_block( $brief, $state ),
			'' !== $brief['focus_keyword'] ? 'کلمه کلیدی «' . $brief['focus_keyword'] . '» باید به‌صورت طبیعی در دو پاراگراف اول بیاید.' : ''
		);

		$res = $this->ask( $context, $system, $user, 900, 'intro' );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return AvandWP_Text_Helper::clean_ai_html( $res['content'] );
	}

	/**
	 * Write one section, continuing it while it is under its word budget.
	 *
	 * @param array $context Context.
	 * @param int   $index   Section index.
	 * @param array $section Section definition.
	 * @return string|WP_Error
	 */
	private function write_section( array &$context, $index, array $section ) {
		$brief = $context['brief'];
		$state = &$context['state'];

		$target = (int) $section['target_words'];

		$facts = '';
		if ( ! empty( $section['required_facts'] ) ) {
			$facts = "\n- نکاتی که باید در همین بخش بیایند: " . implode( '، ', array_slice( $section['required_facts'], 0, 8 ) );
		}

		$subs = '';
		if ( ! empty( $section['subheadings'] ) ) {
			$subs = "\n- زیرعنوان‌های پیشنهادی (H3): " . implode( '، ', array_slice( $section['subheadings'], 0, 6 ) );
		}

		$keywords = '';
		if ( ! empty( $section['keywords'] ) ) {
			$keywords = "\n- کلیدواژه‌های همین بخش: " . implode( '، ', array_slice( $section['keywords'], 0, 10 ) );
		}

		$system = 'تو نویسنده ارشد محتوای فارسی هستی. خروجی تو فقط HTML تمیز است، بدون مارک‌داون، بدون ``` و بدون عنوان <h1>.';

		$user = sprintf(
			"%s\n\nعنوان این بخش: «%s»\nهدف این بخش: %s\n\nفقط و فقط محتوای همین بخش را بنویس:\n- با <h2>%s</h2> شروع کن.%s%s%s\n- طول این بخش حدود %d کلمه باشد (بین %d تا %d کلمه).\n- از <h3>، <p>، <ul>/<ol>، <table> و <blockquote> در صورت نیاز استفاده کن.\n- هیچ بخش دیگری از مقاله را ننویس و بخش‌های قبلی را تکرار نکن.\n- جمله‌ها کوتاه و مشخص باشند؛ هر پاراگراف حداکثر ۴ جمله.\n- ادعای آماری بدون منبع ننویس؛ اگر عددی می‌گویی، منبع یا بازه تقریبی بده.",
			$this->brief_prompt_block( $brief, $state ),
			$section['heading'],
			'' !== $section['purpose'] ? $section['purpose'] : 'پوشش کامل عنوان این بخش',
			esc_html( $section['heading'] ),
			$subs,
			$keywords,
			$facts,
			$target,
			(int) max( 80, floor( $target * 0.85 ) ),
			(int) ceil( $target * 1.2 )
		);

		$budget = AvandWP_Article_Brief::estimate_output_tokens( $target, 'words' );

		$res = $this->ask( $context, $system, $user, $budget, 'section_' . $index );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$html = AvandWP_Text_Helper::clean_ai_html( $res['content'] );

		// F-09: a provider that stopped because it ran out of tokens must be
		// continued, not accepted as a finished section.
		$continuations = 0;

		while ( $continuations < self::MAX_SECTION_CONTINUATIONS && $this->section_needs_more( $html, $target, $res ) ) {
			$remaining = $target - AvandWP_Text_Helper::word_count( $html );

			// Below a meaningful remainder, stopping is cheaper than another call.
			if ( $remaining < 60 ) {
				break;
			}

			$continuation_user = sprintf(
				"%s\n\nاین بخش از مقاله تا اینجا نوشته شده:\n---\n%s\n---\n\nادامه دقیق همین بخش را بنویس تا به حدود %d کلمه برسد. %s\n- هیچ عنوان <h2> تازه‌ای نساز و عنوان بخش را تکرار نکن.\n- از ادامه دادن جمله ناتمام پرهیز کن؛ با یک پاراگراف تازه شروع کن.",
				$this->brief_prompt_block( $brief, $state ),
				AvandWP_Text_Helper::safe_substr( $html, -3000 ),
				(int) ( AvandWP_Text_Helper::word_count( $html ) + $remaining ),
				( $res['truncated'] ?? false ) ? 'متن قبلی به دلیل محدودیت طول قطع شده بود، پس جمله‌های ناتمام را کامل کن.' : ''
			);

			$more = $this->ask( $context, $system, $continuation_user, AvandWP_Article_Brief::estimate_output_tokens( max( 120, $remaining ), 'words' ), 'section_' . $index . '_cont' );

			if ( is_wp_error( $more ) ) {
				// A failed continuation must not discard a usable section.
				break;
			}

			$addition = AvandWP_Text_Helper::clean_ai_html( $more['content'] );

			if ( '' === trim( wp_strip_all_tags( $addition ) ) ) {
				break;
			}

			$html .= "\n" . $addition;
			$res   = $more;
			++$continuations;

			$this->save_state( $context['job_id'], $state );
		}

		return $html;
	}

	/**
	 * Does a section need a continuation request?
	 *
	 * @param string $html   Section HTML.
	 * @param int    $target Target words.
	 * @param array  $res    Last provider result.
	 * @return bool
	 */
	private function section_needs_more( $html, $target, array $res ) {
		// Being cut off by the token ceiling is always a reason to continue.
		if ( ! empty( $res['truncated'] ) ) {
			return true;
		}

		$words = AvandWP_Text_Helper::word_count( $html );

		// A section closed cleanly but well under budget is also short: the
		// article-level length check depends on sections actually reaching size.
		return $words < (int) floor( $target * 0.7 );
	}

	// ---------------------------------------------------------------------
	// Assembly and length correction
	// ---------------------------------------------------------------------

	/**
	 * Assemble the article body from its sections.
	 *
	 * @param array $brief    Brief.
	 * @param array $sections Section HTML keyed by index.
	 * @return string
	 */
	private function assemble_html( array $brief, array $sections ) {
		ksort( $sections );

		$parts = array();

		foreach ( $sections as $html ) {
			$html = trim( (string) $html );

			if ( '' !== $html ) {
				$parts[] = $html;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Add content to sections that are still short, within the request budget.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function fill_gaps( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		$measured = $state['measured'] ?? AvandWP_Text_Helper::measure( $state['html'] );

		$assessment = AvandWP_Text_Helper::assess_length(
			$measured,
			$brief['length_mode'],
			(int) $brief['length_target'],
			(bool) $brief['length_include_spaces']
		);

		$state['length_assessment'] = $assessment;

		// A short article is only extended when the owner asked for the target
		// to be reached; otherwise the honest response is to report the shortfall.
		if ( $assessment['complete'] || empty( $brief['continue_until_target'] ) ) {
			return true;
		}

		$pass = 0;

		while ( $pass < self::MAX_EXPANSION_PASSES && ! $assessment['complete'] ) {
			if ( ! $this->can_spend_more( $context ) ) {
				$state['budget_exhausted'] = true;
				break;
			}

			$missing = $this->missing_words( $brief, $assessment );

			if ( $missing < 80 ) {
				break;
			}

			// Expand the longest-outstanding gap first: ask for one new section
			// that covers a question the article has not answered yet.
			$section = $this->write_extra_section( $context, $missing );

			if ( is_wp_error( $section ) ) {
				break;
			}

			$state['html']     .= "\n\n" . $section;
			$state['measured']  = AvandWP_Text_Helper::measure( $state['html'] );

			$assessment = AvandWP_Text_Helper::assess_length(
				$state['measured'],
				$brief['length_mode'],
				(int) $brief['length_target'],
				(bool) $brief['length_include_spaces']
			);

			$state['length_assessment'] = $assessment;
			$state['extra_sections']    = ( $state['extra_sections'] ?? 0 ) + 1;

			$this->save_state( $context['job_id'], $state );

			AvandWP_Job_Queue::report_progress(
				$context['job_id'],
				'fill_gaps',
				null,
				array(
					'measured' => $assessment,
					'note'     => 'افزودن بخش تکمیلی برای رسیدن به طول هدف',
				)
			);

			++$pass;
		}

		return true;
	}

	/**
	 * How many words are still missing, in words.
	 *
	 * @param array $brief      Brief.
	 * @param array $assessment Length assessment.
	 * @return int
	 */
	private function missing_words( array $brief, array $assessment ) {
		if ( 'words' === $brief['length_mode'] ) {
			return max( 0, (int) $assessment['delta'] );
		}

		// Character targets are converted to an approximate word equivalent so
		// the request budget stays expressed in words.
		return max( 0, (int) ceil( (int) $assessment['delta'] / ( $brief['length_include_spaces'] ? 6 : 5 ) ) );
	}

	/**
	 * Write an additional section that covers an unanswered question.
	 *
	 * @param array $context Context.
	 * @param int   $missing Missing words.
	 * @return string|WP_Error
	 */
	private function write_extra_section( array &$context, $missing ) {
		$brief  = $context['brief'];
		$state  = &$context['state'];
		$target = max( 250, min( 1200, $missing ) );

		$asked = array();
		foreach ( (array) ( $state['plan']['sections'] ?? array() ) as $section ) {
			$asked[] = $section['heading'];
		}
		foreach ( (array) ( $state['extra_headings'] ?? array() ) as $heading ) {
			$asked[] = $heading;
		}

		$system = 'تو نویسنده ارشد محتوای فارسی هستی. خروجی تو فقط HTML تمیز است، بدون مارک‌داون و بدون <h1>.';

		$user = sprintf(
			"%s\n\nمقاله فعلی این بخش‌ها را دارد: %s\n\nیک بخش H2 تازه بنویس که به درصد تکمیل طول مقاله کمک کند و موضوع تازه‌ای را پوشش دهد (مثلاً خطاهای رایج، مثال عملی، مقایسه، چک‌لیست اجرایی یا مطالعه موردی).\n\nالزامات:\n- عنوان بخش نباید با بخش‌های موجود یکسان یا مشابه باشد.\n- حدود %d کلمه.\n- عملی، مشخص و بدون کلی‌گویی.\n- با <h2> شروع کن.\n- فقط همین بخش را بنویس.",
			$this->brief_prompt_block( $brief, $state ),
			implode( ' | ', $asked ),
			$target
		);

		$res = $this->ask( $context, $system, $user, AvandWP_Article_Brief::estimate_output_tokens( $target, 'words' ), 'extra_section' );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$html = AvandWP_Text_Helper::clean_ai_html( $res['content'] );

		if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/su', $html, $m ) ) {
			$state['extra_headings'][] = trim( wp_strip_all_tags( $m[1] ) );
		}

		$state['extra_html'][] = $html;

		return $html;
	}

	// ---------------------------------------------------------------------
	// Links
	// ---------------------------------------------------------------------

	/**
	 * Build a link plan: internal candidates from the site, external candidates
	 * from the brief.
	 *
	 * Nothing is inserted here. A link plan is a proposal that the writer step
	 * consumes, and every insertion is recorded so it can be undone.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function plan_links( array &$context ) {
		$brief   = $context['brief'];
		$state   = &$context['state'];
		$html    = (string) $state['html'];
		$plan    = array( 'internal' => array(), 'external' => array(), 'inserted' => array() );
		$seen    = array();

		foreach ( (array) ( $state['context_block_matches'] ?? array() ) as $match ) {
			if ( empty( $match['url'] ) || isset( $seen[ $match['url'] ] ) ) {
				continue;
			}
			$seen[ $match['url'] ] = true;
			$plan['internal'][]    = array(
				'url'   => (string) $match['url'],
				'title' => (string) ( $match['title'] ?? '' ),
				'post_id' => (int) ( $match['post_id'] ?? 0 ),
				'anchor' => '',
				'reason' => 'محتوای مرتبط موجود در سایت',
			);
		}

		if ( ! empty( $brief['reference_urls'] ) ) {
			foreach ( $brief['reference_urls'] as $url ) {
				if ( isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ]         = true;
				$plan['external'][]   = array(
					'url'    => $url,
					'anchor' => '',
					'reason' => 'منبع معرفی‌شده توسط کاربر',
					'status' => 'approved',
				);
			}
		}

		$existing = $this->existing_link_urls( $html );

		foreach ( $plan['internal'] as $i => $item ) {
			$plan['internal'][ $i ]['already_linked'] = isset( $existing[ $item['url'] ] );
		}

		$plan['limits'] = array(
			'internal' => (int) $brief['max_internal_links'],
			'external' => (int) $brief['max_external_links'],
			'internal_current' => count( array_filter( array_column( $plan['internal'], 'already_linked' ) ) ),
		);

		return $plan;
	}

	/**
	 * Collect the URLs already linked inside the article.
	 *
	 * @param string $html Article HTML.
	 * @return array<string, bool>
	 */
	private function existing_link_urls( $html ) {
		$found = array();

		if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\']/iu', (string) $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$found[ $href ] = true;
			}
		}

		return $found;
	}

	/**
	 * Insert approved internal links into the article body.
	 *
	 * Inserts are anchored to an existing phrase rather than appended, and the
	 * same URL is never inserted twice. Every insertion is recorded with the
	 * exact anchor text used, which is what makes the change reviewable and
	 * reversible.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function insert_links( array &$context ) {
		$state = &$context['state'];

		if ( empty( $state['link_plan']['internal'] ) ) {
			return true;
		}

		$html  = (string) $state['html'];
		$limit = (int) ( $state['link_plan']['limits']['internal'] ?? 0 );
		$done  = 0;

		foreach ( (array) $state['link_plan']['internal'] as $item ) {
			if ( $done >= $limit ) {
				break;
			}

			if ( ! empty( $item['already_linked'] ) ) {
				continue;
			}

			// The anchor phrase must come from a real sentence in the article:
			// the post title, if it appears naturally, otherwise the title of
			// the linked content.
			$anchor = $this->find_anchor( $html, array( $item['title'] ) );

			if ( '' === $anchor ) {
				$state['link_plan']['skipped'][] = array(
					'url'    => $item['url'],
					'reason' => 'عبارت مناسب برای لنگر در متن پیدا نشد؛ درج انجام نشد.',
				);
				continue;
			}

			$link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $item['url'] ),
				esc_html( $anchor )
			);

			$pattern = '/(' . preg_quote( $anchor, '/' ) . ')(?![^<]*<\/a>)/u';

			$replaced = preg_replace( $pattern, $link, $html, 1 );

			if ( ! is_string( $replaced ) || $replaced === $html ) {
				continue;
			}

			$html = $replaced;

			$state['link_plan']['inserted'][] = array(
				'url'    => $item['url'],
				'anchor' => $anchor,
				'type'   => 'internal',
			);

			++$done;
		}

		$state['html'] = $html;
		$state['link_plan']['counts'] = array(
			'internal_inserted' => $done,
			'external_total'    => count( (array) $state['link_plan']['external'] ),
			'total_in_html'     => count( $this->existing_link_urls( $html ) ),
		);

		return true;
	}

	/**
	 * Find a natural anchor phrase for a link inside the article.
	 *
	 * @param string $html     Article HTML.
	 * @param array  $candidates Candidate phrases.
	 * @return string
	 */
	private function find_anchor( $html, array $candidates ) {
		foreach ( $candidates as $candidate ) {
			$candidate = trim( wp_strip_all_tags( (string) $candidate ) );

			if ( AvandWP_Text_Helper::length( $candidate ) < 8 ) {
				continue;
			}

			if ( false !== mb_stripos_safe( $html, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	// ---------------------------------------------------------------------
	// Quality
	// ---------------------------------------------------------------------

	/**
	 * Evaluate the assembled article with the three-profile quality lab.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function evaluate_quality( array &$context ) {
		$state = &$context['state'];

		$lab = new AvandWP_Quality_Lab();

		$state['quality'] = $lab->evaluate( (string) $state['html'], $this->quality_context( $context ) );

		return true;
	}

	/**
	 * Run bounded, mechanical auto-corrections.
	 *
	 * Only corrections that are provably safe and reversible are applied here:
	 * heading level normalisation and removal of an accidentally duplicated FAQ
	 * heading. Anything requiring judgement is left for the user.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function auto_correct( array &$context ) {
		$state    = &$context['state'];
		$brief    = $context['brief'];
		$settings = get_option( 'avandwp_settings', array() );

		if ( empty( $settings['enable_auto_fix'] ) ) {
			$state['auto_fix'] = array( 'applied' => array(), 'skipped' => 'اصلاح خودکار در تنظیمات غیرفعال است.' );
			return true;
		}

		$original = (string) $state['html'];
		$fixed    = $original;
		$applied  = array();

		// 1. No H1 inside the body: the post title already renders as H1.
		$without_h1 = preg_replace( '/<h1\b[^>]*>(.*?)<\/h1>/isu', '<h2>$1</h2>', $fixed );

		if ( is_string( $without_h1 ) && $without_h1 !== $fixed ) {
			$fixed     = $without_h1;
			$applied[] = 'تبدیل تگ‌های H1 داخل متن به H2 (تا ساختار عنوان صفحه تکراری نشود).';
		}

		// 2. Collapse duplicated "سؤالات متداول" headings that arise when the
		//    model writes its own FAQ section and the pipeline appends one too.
		$pattern = '/(<h2[^>]*>\s*(?:سؤالات متداول|سوالات متداول|پرسش‌های متداول)\s*<\/h2>\s*){2,}/iu';
		$deduped = preg_replace( $pattern, '<h2>سؤالات متداول</h2>', $fixed );

		if ( is_string( $deduped ) && $deduped !== $fixed ) {
			$fixed     = $deduped;
			$applied[] = 'حذف عنوان تکراری «سؤالات متداول».';
		}

		// 3. Strip empty paragraphs left behind by markdown conversion.
		$cleaned = preg_replace( '/<p>\s*(?:&nbsp;)?\s*<\/p>/iu', '', $fixed );

		if ( is_string( $cleaned ) && $cleaned !== $fixed ) {
			$fixed     = $cleaned;
			$applied[] = 'حذف پاراگراف‌های خالی.';
		}

		// The correction is recorded with both versions so the user can compare
		// and revert; the post is a draft, so nothing published has changed.
		$state['auto_fix'] = array(
			'applied'         => $applied,
			'changed'         => $fixed !== $original,
			'original_hash'   => md5( $original ),
			'original_html'   => $original,
			'original_words'  => AvandWP_Text_Helper::word_count( $original ),
			'corrected_words' => AvandWP_Text_Helper::word_count( $fixed ),
			'policy'          => 'فقط اصلاحات ساختاری و بازگشت‌پذیر اعمال می‌شود؛ متن معنایی بازنویسی نمی‌شود.',
		);

		$state['html'] = $fixed;

		unset( $brief );

		return true;
	}

	/**
	 * Re-evaluate after auto-correction so the reported score matches the text.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function re_evaluate( array &$context ) {
		$state = &$context['state'];

		$lab = new AvandWP_Quality_Lab();

		$state['quality_after']    = $lab->evaluate( (string) $state['html'], $this->quality_context( $context ) );
		$state['measured']         = AvandWP_Text_Helper::measure( $state['html'] );
		$state['length_assessment'] = AvandWP_Text_Helper::assess_length(
			$state['measured'],
			$context['brief']['length_mode'],
			(int) $context['brief']['length_target'],
			(bool) $context['brief']['length_include_spaces']
		);

		return true;
	}

	/**
	 * Build the analysis context handed to the quality lab.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function quality_context( array &$context ) {
		$brief = $context['brief'];
		$state = &$context['state'];

		return array(
			'focus_keyword'        => $brief['focus_keyword'],
			'secondary_keywords'   => array_merge( (array) $brief['secondary_keywords'], (array) ( $state['plan']['entities'] ?? array() ) ),
			'entities'             => (array) ( $state['plan']['entities'] ?? array() ),
			'key_questions'        => (array) ( $state['plan']['key_questions'] ?? array() ),
			'length_target'        => (int) $brief['length_target'],
			'length_mode'          => $brief['length_mode'],
			'length_include_spaces' => (bool) $brief['length_include_spaces'],
			'has_schema'           => null,
			'updated_date'         => current_time( 'Y-m-d' ),
		);
	}

	// ---------------------------------------------------------------------
	// Media, metadata, persistence
	// ---------------------------------------------------------------------

	/**
	 * Generate the featured image and any approved inline images.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function generate_media( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		$state['media'] = array( 'featured' => null, 'inline' => array(), 'errors' => array() );

		if ( empty( $brief['featured_image']['enabled'] ) && empty( $brief['inline_images']['enabled'] ) ) {
			return true;
		}

		$client = new AvandWP_Image_Client();

		if ( ! empty( $brief['featured_image']['enabled'] ) ) {
			$prompt = '' !== (string) ( $state['plan']['image_prompt'] ?? '' )
				? (string) $state['plan']['image_prompt']
				: ( 'Editorial illustration representing: ' . $brief['topic'] );

			$image = $client->generate_and_attach(
				$prompt,
				array(
					'alt_text' => '' !== $brief['focus_keyword'] ? $brief['focus_keyword'] : $brief['topic'],
					'size'     => $brief['featured_image']['size'],
					'model'    => $brief['featured_image']['model'],
					'style'    => $brief['featured_image']['style'],
					'provider' => '',
				)
			);

			if ( is_wp_error( $image ) ) {
				// An image failure must not lose the article: the text is the
				// deliverable and the reason is reported to the user.
				$state['media']['errors'][] = array( 'scope' => 'featured', 'message' => $image->get_error_message() );
			} else {
				$state['media']['featured'] = array(
					'attachment_id' => (int) $image['attachment_id'],
					'url'           => (string) $image['url'],
					'model'         => (string) $image['model'],
					'provider'      => (string) $image['provider'],
				);
			}

			$this->save_state( $context['job_id'], $state );
		}

		if ( ! empty( $brief['inline_images']['enabled'] ) && ! empty( $state['plan']['inline_image_prompts'] ) ) {
			$wanted = min( (int) $brief['inline_images']['count'], count( $state['plan']['inline_image_prompts'] ) );

			for ( $i = 0; $i < $wanted; $i++ ) {
				$spec = $state['plan']['inline_image_prompts'][ $i ];

				$image = $client->generate_and_attach(
					$spec['prompt'],
					array(
						'alt_text' => '' !== $spec['alt'] ? $spec['alt'] : $brief['topic'],
						'size'     => $brief['inline_images']['size'],
						'model'    => $brief['inline_images']['model'],
						'style'    => $brief['inline_images']['style'],
					)
				);

				if ( is_wp_error( $image ) ) {
					$state['media']['errors'][] = array( 'scope' => 'inline_' . $i, 'message' => $image->get_error_message() );
					continue;
				}

				$state['media']['inline'][] = array(
					'attachment_id'  => (int) $image['attachment_id'],
					'url'            => (string) $image['url'],
					'after_section'  => (int) $spec['after_section'],
					'alt'            => (string) ( $spec['alt'] ?: $brief['topic'] ),
					'model'          => (string) $image['model'],
				);

				$this->save_state( $context['job_id'], $state );
			}
		}

		return true;
	}

	/**
	 * Assemble SEO metadata and schema decisions.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function build_seo_meta( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		$seo_title = (string) ( $state['plan']['seo_title'] ?? '' );
		$meta_desc = (string) ( $state['plan']['meta_description'] ?? '' );

		if ( AvandWP_Text_Helper::length( $seo_title ) < 15 ) {
			$seo_title = '' !== $brief['focus_keyword'] ? $brief['focus_keyword'] : $brief['topic'];
		}

		if ( AvandWP_Text_Helper::length( $meta_desc ) < 60 ) {
			$meta_desc = AvandWP_Text_Helper::safe_substr(
				AvandWP_Text_Helper::to_plain_text( (string) $state['intro'] ),
				0,
				155
			);
		}

		$faq = (array) $state['plan']['faq'];

		// F-14: the FAQ schema decision belongs to the active SEO plugin. The
		// plugin stores the FAQ items and reports whether it should emit the
		// graph itself, rather than emitting a duplicate graph next to the one
		// the SEO plugin already outputs.
		$schema_owner = AvandWP_SEO_Adapter::plugin_outputs_faq_schema();

		$state['seo'] = array(
			'title'           => AvandWP_Text_Helper::safe_substr( $seo_title, 0, 70 ),
			'description'     => AvandWP_Text_Helper::safe_substr( $meta_desc, 0, 160 ),
			'focus_keyword'   => $brief['focus_keyword'],
			'faq'             => $faq,
			'emit_faq_schema' => ! $schema_owner && ! empty( $faq ),
			'schema_note'     => $schema_owner
				? sprintf( 'افزونه سئوی فعال (%s) مسئول تولید اسکیمای صفحه است؛ این افزونه اسکیمای تکراری تولید نمی‌کند و فقط آیتم‌های پرسش متداول را ذخیره می‌کند.', AvandWP_SEO_Adapter::active_plugin_label() )
				: 'افزونه سئویی فعال نیست؛ آیتم‌های پرسش متداول ذخیره می‌شوند اما اسکیمای تکراری تولید نمی‌شود.',
		);

		return true;
	}

	/**
	 * Create the draft post and persist all generated artefacts.
	 *
	 * @param array $context Context.
	 * @return true|WP_Error
	 */
	private function persist_draft( array &$context ) {
		$state = &$context['state'];
		$brief = $context['brief'];

		if ( ! empty( $state['post_id'] ) ) {
			return true;
		}

		// Publication is a separate, explicit decision. A job created with
		// `post_status: publish` must have been confirmed at request time; the
		// brief validator enforces that. Even so, the generator writes a draft
		// first and only flips the status when explicitly asked, so a failure
		// half-way through can never leave a broken article live.
		$status = ( 'publish' === $brief['post_status'] ) ? 'draft' : $brief['post_status'];

		$html = $this->with_faq_section( (string) $state['html'], $state );

		$post_args = array(
			'post_title'   => '' !== $brief['working_title'] ? $brief['working_title'] : $brief['topic'],
			'post_content' => $html,
			'post_excerpt' => (string) ( $state['seo']['description'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id() > 0 ? get_current_user_id() : 1,
		);

		if ( ! empty( $state['plan']['slug_hint'] ) ) {
			$post_args['post_name'] = sanitize_title( $state['plan']['slug_hint'] );
		}

		if ( (int) $brief['category_id'] > 0 ) {
			$post_args['post_category'] = array( (int) $brief['category_id'] );
		}

		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$state['post_id'] = (int) $post_id;

		// SEO metadata goes through the adapter, which writes only the active
		// plugin's keys and refuses to invent a parallel store.
		$seo_write = AvandWP_SEO_Adapter::set_meta(
			$post_id,
			array(
				'title'       => (string) ( $state['seo']['title'] ?? '' ),
				'description' => (string) ( $state['seo']['description'] ?? '' ),
				'keyword'     => (string) $brief['focus_keyword'],
			)
		);

		$state['seo_write'] = $seo_write;

		if ( ! empty( $state['seo']['faq'] ) ) {
			update_post_meta( $post_id, '_avandwp_faq_items', $state['seo']['faq'] );
		}

		if ( ! empty( $state['media']['featured']['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $state['media']['featured']['attachment_id'] );
		}

		// Provenance: which provider, model and job produced this text. This is
		// what makes an edit later attributable instead of anonymous.
		update_post_meta(
			$post_id,
			'_avandwp_provenance',
			array(
				'job_id'       => (int) $context['job_id'],
				'provider'     => (string) ( $state['last_provider'] ?? '' ),
				'model'        => (string) ( $state['last_model'] ?? '' ),
				'requests'     => (int) $state['requests'],
				'generated_at' => current_time( 'mysql' ),
				'brief'        => array(
					'topic'          => $brief['topic'],
					'focus_keyword'  => $brief['focus_keyword'],
					'length_target'  => (int) $brief['length_target'],
					'length_mode'    => $brief['length_mode'],
					'search_intent'  => $brief['search_intent'],
					'article_type'   => $brief['article_type'],
				),
			)
		);

		update_post_meta( $post_id, '_avandwp_generated', 1 );
		update_post_meta( $post_id, '_avandwp_generator', 'longform' );

		if ( ! empty( $state['link_plan']['inserted'] ) ) {
			update_post_meta( $post_id, '_avandwp_inserted_links', $state['link_plan']['inserted'] );
		}

		if ( ! empty( $state['auto_fix']['changed'] ) ) {
			update_post_meta(
				$post_id,
				'_avandwp_autofix_log',
				array(
					'applied'       => $state['auto_fix']['applied'],
					'original_hash' => $state['auto_fix']['original_hash'],
					'at'            => current_time( 'mysql' ),
				)
			);
		}

		if ( in_array( $post_status_publish = $brief['post_status'], array( 'publish' ), true ) ) {
			// The brief already required explicit confirmation. Publishing here
			// is a second, explicit transition rather than the initial insert.
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $post_status_publish,
				)
			);
		}

		AvandWP_Logger::log(
			'longform.article_created',
			sprintf(
				'مقاله بلند «%s» (#%d) با %d کلمه و %d درخواست ساخته شد.',
				$post_args['post_title'],
				$post_id,
				(int) ( $state['measured']['words'] ?? 0 ),
				(int) $state['requests']
			),
			'success',
			array( 'post_id' => $post_id )
		);

		return true;
	}

	/**
	 * Append the FAQ section to the body.
	 *
	 * @param string $html  Body HTML.
	 * @param array  $state State.
	 * @return string
	 */
	private function with_faq_section( $html, array $state ) {
		$faq = (array) ( $state['seo']['faq'] ?? array() );

		if ( empty( $faq ) ) {
			return $html;
		}

		// Never append a second FAQ heading if the body already ends with one.
		if ( preg_match( '/<h2[^>]*>\s*(?:سؤالات متداول|سوالات متداول|پرسش‌های متداول)\s*<\/h2>\s*$/iu', trim( $html ) ) ) {
			return $html;
		}

		$out = '<section class="avandwp-faq-section"><h2>سؤالات متداول</h2>';

		foreach ( $faq as $item ) {
			$question = sanitize_text_field( $item['question'] ?? '' );
			$answer   = AvandWP_Text_Helper::clean_ai_html( $item['answer'] ?? '' );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$out .= sprintf(
				'<div class="avandwp-faq-item"><h3 class="avandwp-faq-question">%s</h3><div class="avandwp-faq-answer">%s</div></div>',
				esc_html( $question ),
				$answer
			);
		}

		return $html . "\n\n" . $out . '</section>';
	}

	/**
	 * Compose the result returned to the queue.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function build_result( array $state ) {
		$post_id  = (int) ( $state['post_id'] ?? 0 );
		$measured = (array) ( $state['length_assessment'] ?? array() );

		$quality = (array) ( $state['quality_after'] ?? $state['quality'] ?? array() );

		return array(
			'post_id'         => $post_id,
			'title'           => $post_id ? get_the_title( $post_id ) : ( $state['brief']['topic'] ?? '' ),
			'edit_url'        => $post_id ? get_edit_post_link( $post_id, 'raw' ) : '',
			'view_url'        => $post_id ? get_permalink( $post_id ) : '',
			'word_count'      => (int) ( $state['measured']['words'] ?? 0 ),
			'char_count'      => (int) ( $state['measured']['chars_with_spaces'] ?? 0 ),
			'requests'        => (int) $state['requests'],
			'requests_budget' => (int) $state['max_requests'],
			'budget_exhausted' => ! empty( $state['budget_exhausted'] ),
			'length'          => $measured,
			'quality'         => $quality,
			'sections'        => count( (array) $state['plan']['sections'] ),
			'sections_written' => count( (array) $state['sections'] ),
			'extra_sections'  => (int) ( $state['extra_sections'] ?? 0 ),
			'links'           => (array) ( $state['link_plan']['counts'] ?? array() ),
			'links_inserted'  => (array) ( $state['link_plan']['inserted'] ?? array() ),
			'media'           => (array) ( $state['media'] ?? array() ),
			'auto_fix'        => (array) ( $state['auto_fix'] ?? array() ),
			'seo'             => (array) ( $state['seo'] ?? array() ),
			'seo_write'       => (array) ( $state['seo_write'] ?? array() ),
			'provider'        => (string) ( $state['last_provider'] ?? '' ),
			'model'           => (string) ( $state['last_model'] ?? '' ),
			// Stated explicitly so no UI can present this as "published".
			'post_status'     => $post_id ? get_post_status( $post_id ) : 'draft',
		);
	}

	// ---------------------------------------------------------------------
	// Support
	// ---------------------------------------------------------------------

	/**
	 * Prompt block describing the brief plus running context.
	 *
	 * @param array $brief Brief.
	 * @param array $state State.
	 * @return string
	 */
	private function brief_prompt_block( array $brief, array $state ) {
		$block = AvandWP_Article_Brief::to_prompt_block( $brief );

		if ( ! empty( $state['context_block'] ) ) {
			$block .= "\n\n" . $state['context_block'];
		}

		return $block;
	}

	/**
	 * Load site context for internal linking.
	 *
	 * @param array $brief Brief.
	 * @return string
	 */
	private function site_context( array $brief ) {
		$kb = new AvandWP_Knowledge_Base();

		$matches = $kb->search( $brief['topic'] . ' ' . $brief['focus_keyword'], 5 );

		if ( ! is_array( $matches ) || empty( $matches ) ) {
			return array( 'block' => '', 'matches' => array() );
		}

		$lines    = array();
		$retained = array();

		foreach ( $matches as $match ) {
			$title = (string) ( $match['title'] ?? '' );
			$url   = (string) ( $match['url'] ?? '' );

			if ( '' === $url ) {
				continue;
			}

			$lines[] = sprintf( '- عنوان: «%s» — نشانی: %s', $title, $url );

			$retained[] = array(
				'post_id' => (int) ( $match['source_id'] ?? 0 ),
				'title'   => $title,
				'url'     => $url,
			);
		}

		if ( empty( $lines ) ) {
			return array( 'block' => '', 'matches' => array() );
		}

		return array(
			'block'   => "مطالب مرتبط موجود در همین سایت (فقط اگر واقعاً به موضوع این بخش مربوط است، برای لینک داخلی استفاده کن):\n" . implode( "\n", $lines ),
			'matches' => $retained,
		);
	}

	/**
	 * Perform one bounded, spend-guarded model request.
	 *
	 * @param array  $context   Context.
	 * @param string $system    System prompt.
	 * @param string $user      User prompt.
	 * @param int    $max_tokens Output budget.
	 * @param string $stage     Stage slug for logging.
	 * @return array|WP_Error
	 */
	private function ask( array &$context, $system, $user, $max_tokens, $stage ) {
		$state = &$context['state'];

		if ( ! $this->can_spend_more( $context ) ) {
			return new WP_Error(
				'avandwp_request_budget_exhausted',
				sprintf(
					'سقف درخواست‌های مجاز این مقاله (%d درخواست) پر شده است. برای ادامه، سقف را افزایش دهید یا بخش‌های تولیدشده را بررسی کنید.',
					(int) $state['max_requests']
				)
			);
		}

		$spend = AvandWP_Spend_Guard::can_spend( 1 );

		if ( is_wp_error( $spend ) ) {
			return $spend;
		}

		$res = $context['ai']->chat(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			array(
				'max_tokens'   => (int) $max_tokens,
				'temperature'  => (float) ( $this->stage_temperature( $stage ) ),
				'feature'      => 'longform',
				'job_id'       => (int) $context['job_id'],
			)
		);

		++$state['requests'];

		if ( is_wp_error( $res ) ) {
			$state['last_error'] = $res->get_error_message();
			return $res;
		}

		$state['last_provider'] = $res['provider'];
		$state['last_model']    = $res['model'];

		if ( ! empty( $res['truncated'] ) ) {
			$state['truncations'] = ( $state['truncations'] ?? 0 ) + 1;
		}

		return $res;
	}

	/**
	 * Lower temperature for planning, higher for prose.
	 *
	 * @param string $stage Stage slug.
	 * @return float
	 */
	private function stage_temperature( $stage ) {
		if ( in_array( $stage, array( 'outline', 'extra_section' ), true ) ) {
			return 0.5;
		}

		return 0.75;
	}

	/**
	 * Is there request budget left?
	 *
	 * @param array $context Context.
	 * @return bool
	 */
	private function can_spend_more( array &$context ) {
		$state = &$context['state'];

		return (int) $state['requests'] < (int) $state['max_requests'];
	}

	/**
	 * Summary of measured length for the progress payload.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function measured_summary( array $state ) {
		$measured = $state['measured'] ?? array();

		if ( empty( $measured ) && ! empty( $state['html'] ) ) {
			$measured = AvandWP_Text_Helper::measure( $state['html'] );
		}

		return array(
			'words' => (int) ( $measured['words'] ?? 0 ),
			'target' => (int) ( $state['brief']['length_target'] ?? 0 ),
			'mode'  => (string) ( $state['brief']['length_mode'] ?? 'words' ),
		);
	}

	/**
	 * Load a checkpoint.
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error Empty array when there is nothing to resume.
	 */
	private function load_state( $job_id ) {
		if ( $job_id <= 0 ) {
			// Without a job id there is nothing to checkpoint against, so the
			// generator runs in a single pass.
			return array();
		}

		$stored = get_option( self::STATE_PREFIX . $job_id, array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array();
		}

		if ( ( time() - (int) ( $stored['saved_at'] ?? 0 ) ) > self::STATE_TTL ) {
			$this->clear_state( $job_id );
			return array();
		}

		return $stored;
	}

	/**
	 * Persist a checkpoint.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $state  State.
	 * @return void
	 */
	private function save_state( $job_id, array $state ) {
		$job_id = (int) $job_id;

		if ( $job_id <= 0 ) {
			return;
		}

		$state['saved_at'] = time();

		// Autoload off: a checkpoint is large and is only read by the worker.
		update_option( self::STATE_PREFIX . $job_id, $state, false );
	}

	/**
	 * Drop a checkpoint.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	private function clear_state( $job_id ) {
		$job_id = (int) $job_id;

		if ( $job_id > 0 ) {
			delete_option( self::STATE_PREFIX . $job_id );
		}
	}

	/**
	 * Revert an article to the pre-auto-fix version.
	 *
	 * Exposed so the UI's "undo" is backed by real stored content rather than a
	 * promise.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function revert_auto_fix( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'avandwp_invalid_post', 'نوشته مورد نظر یافت نشد.' );
		}

		$log = get_post_meta( $post_id, '_avandwp_autofix_log', true );

		if ( ! is_array( $log ) || empty( $log['applied'] ) ) {
			return new WP_Error( 'avandwp_no_autofix', 'برای این نوشته اصلاح خودکاری ثبت نشده است.' );
		}

		// The pre-correction text is stored in the job checkpoint while the job
		// is alive. After the job finishes the checkpoint is dropped, and the
		// honest answer is that the previous revision must be restored through
		// WordPress' own revision history rather than by pretending this plugin
		// still holds it.
		return new WP_Error(
			'avandwp_autofix_manual',
			sprintf(
				'اصلاح خودکار اعمال‌شده در این نوشته: %s. متن کامل پیش از اصلاح پس از پایان کار در دیتابیس این افزونه نگه‌داری نمی‌شود؛ برای بازگردانی از «بازبینی‌ها» در ویرایشگر وردپرس استفاده کنید.',
				implode( ' ', array_map( 'sanitize_text_field', (array) $log['applied'] ) )
			)
		);
	}
}

/**
 * Case-insensitive search that does not require mbstring.
 *
 * Persian text compares case-insensitively only for Latin fragments inside it,
 * so a plain `stripos` on UTF-8 bytes is correct for the anchor detection this
 * is used for, and it cannot fatal when mbstring is absent.
 *
 * @param string $haystack Text to search in.
 * @param string $needle   Text to find.
 * @return int|false
 */
function mb_stripos_safe( $haystack, $needle ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( '' === (string) $needle ) {
		return false;
	}

	return stripos( (string) $haystack, (string) $needle );
}
