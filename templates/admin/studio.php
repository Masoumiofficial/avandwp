<?php
/**
 * Admin Page 2: Content & Media Studio (`templates/admin/studio.php`).
 *
 * Unified 3-tab studio:
 * 1) SEO Article Writer (with featured image, FAQ schema, audio, and background queue support).
 * 2) AI Image Generator (DALL-E 3, FAL Flux, Stability SD3).
 * 3) Podcast Audio Synthesizer (TTS MP3).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$categories = get_categories( array( 'hide_empty' => false ) );
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="studio">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-article">۱. نگارش مقاله سئوشده</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-image">۲. کارگاه تصویر هوشمند</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-longform">۳. کارگاه مقاله بلند (۴٫۵۰۰ کلمه و بیشتر)</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-audio">۴. تبدیل متن به پادکست صوتی (TTS)</button>
	</div>

	<!-- TAB 1: Article Generator -->
	<div id="tab-article" class="avandwp-tab-panel is-active">
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>مشخصات مقاله جدید</h2>
				</div>
				<form id="avandwp-article-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-topic">موضوع یا عنوان پیشنهادی مقاله <span class="required">*</span></label>
						<input type="text" id="avandwp-topic" name="topic" required placeholder="مثال: راهنمای جامع انتخاب هاست مناسب برای فروشگاه ووکامرس">
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-keyword">کلمه کلیدی کانونی (سئو)</label>
							<input type="text" id="avandwp-keyword" name="keyword" placeholder="مثال: خرید هاست ووکامرس">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-tone">لحن نگارش</label>
							<select id="avandwp-tone" name="tone">
								<option value="حرفه‌ای، کاربردی و روان">حرفه‌ای، کاربردی و روان</option>
								<option value="آموزشی و گام‌به‌گام">آموزشی و گام‌به‌گام</option>
								<option value="تحلیلی و تخصصی">تحلیلی و تخصصی</option>
								<option value="صمیمی و محاوره‌ای استاندارد">صمیمی و روان</option>
							</select>
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-3">
						<div class="avandwp-field">
							<label for="avandwp-target-words">طول هدف (کلمه)</label>
							<select id="avandwp-target-words" name="target_words">
								<option value="600">کوتاه (~۶۰۰ کلمه)</option>
								<option value="1000" selected>استاندارد (~۱۰۰۰ کلمه)</option>
								<option value="1600">جامع (~۱۶۰۰ کلمه)</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-category">دسته‌بندی نوشته</label>
							<select id="avandwp-category" name="category_id">
								<option value="0">— پیش‌فرض سایت —</option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-post-status">وضعیت ذخیره</label>
							<select id="avandwp-post-status" name="post_status">
								<option value="draft" selected>پیش‌نویس (توصیه‌شده برای بازبینی)</option>
								<option value="pending">در انتظار بازبینی</option>
								<option value="publish">انتشار مستقیم</option>
							</select>
						</div>
					</div>

					<div class="avandwp-checkbox-group">
						<label class="avandwp-checkbox">
							<input type="checkbox" name="use_knowledge" value="1" checked>
							<span>استفاده از پایگاه دانش سایت برای لینک‌سازی داخلی واقعی</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="generate_image" value="1">
							<span>ساخت و الصاق خودکار تصویر شاخص مرتبط</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="generate_audio" value="1">
							<span>تولید خلاصه صوتی (پادکست MP3) برای مقاله</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="background" value="1">
							<span>اجرا در صف پردازش پس‌زمینه (مناسب هاست‌های اشتراکی با محدودیت Timeout)</span>
						</label>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary" id="avandwp-article-submit">
							تولید مقاله و ذخیره در وردپرس
						</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پیش‌نمایش و خروجی مقاله</h2>
				</div>
				<div id="avandwp-article-result" class="avandwp-result-container">
					<div class="avandwp-empty-state">
						<p>هنوز مقاله‌ای در این نشست تولید نشده است.</p>
						<span class="avandwp-text-muted">پس از تکمیل فرم روبه‌رو، عنوان سئو، تعداد دقیق کلمات فارسی، لینک ویرایش پیش‌نویس، کپشن تلگرام و پیش‌نمایش مقاله در این کادر نمایش داده می‌شود.</span>
					</div>
				</div>
			</div>
		</div>
	</div>


	<!-- TAB 3: Long-form Article Studio (Module A) -->
	<div id="tab-longform" class="avandwp-tab-panel" hidden>
		<?php
		$longform_default = (int) ( $settings['longform_default_length'] ?? AvandWP_Article_Brief::DEFAULT_LENGTH );
		$longform_budget  = AvandWP_Longform_Generator::request_budget(
			array( 'length_target' => $longform_default, 'continue_until_target' => 1 )
		);
		?>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<div>
						<h2>بریف مقاله بلند</h2>
						<p class="avandwp-text-muted">
							تولید در <?php echo esc_html( number_format_i18n( count( AvandWP_Longform_Generator::pipeline() ) ) ); ?> مرحله جداگانه و به‌صورت صف پس‌زمینه اجرا می‌شود.
							سقف درخواست‌ها برای این بریف: <strong><?php echo esc_html( number_format_i18n( $longform_budget ) ); ?></strong> درخواست.
							هر مرحله پیشرفت واقعی خودش را گزارش می‌کند؛ صفحه را می‌توانید ببندید و بعد ادامه دهید.
						</p>
					</div>
				</div>

				<form id="avandwp-longform-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-lf-topic">موضوع <span class="required">*</span></label>
						<input type="text" id="avandwp-lf-topic" name="topic" required maxlength="300"
							placeholder="مثال: راهنمای کامل انتخاب هاست فروشگاهی در ایران">
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-lf-title">عنوان کاری</label>
							<input type="text" id="avandwp-lf-title" name="working_title">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-keyword">کلمه کلیدی کانونی</label>
							<input type="text" id="avandwp-lf-keyword" name="focus_keyword">
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-3">
						<div class="avandwp-field">
							<label for="avandwp-lf-intent">قصد جستجوی کاربر</label>
							<select id="avandwp-lf-intent" name="search_intent">
								<?php foreach ( AvandWP_Article_Brief::intents() as $avandwp_k => $avandwp_label ) : ?>
									<option value="<?php echo esc_attr( $avandwp_k ); ?>"><?php echo esc_html( $avandwp_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-type">نوع مقاله</label>
							<select id="avandwp-lf-type" name="article_type">
								<?php foreach ( AvandWP_Article_Brief::article_types() as $avandwp_k => $avandwp_label ) : ?>
									<option value="<?php echo esc_attr( $avandwp_k ); ?>"><?php echo esc_html( $avandwp_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-category">دسته‌بندی</label>
							<select id="avandwp-lf-category" name="category_id">
								<option value="0">— پیش‌فرض سایت —</option>
								<?php foreach ( $categories as $avandwp_cat ) : ?>
									<option value="<?php echo esc_attr( (string) $avandwp_cat->term_id ); ?>"><?php echo esc_html( $avandwp_cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-3">
						<div class="avandwp-field">
							<label for="avandwp-lf-length">طول هدف</label>
							<input type="number" id="avandwp-lf-length" name="length_target"
								value="<?php echo esc_attr( (string) $longform_default ); ?>"
								min="<?php echo esc_attr( (string) AvandWP_Article_Brief::MIN_LENGTH ); ?>"
								max="<?php echo esc_attr( (string) AvandWP_Article_Brief::MAX_LENGTH ); ?>">
							<p class="avandwp-help">
								معادل حدود <?php echo esc_html( number_format_i18n( AvandWP_Article_Brief::estimate_output_tokens( $longform_default ) ) ); ?> توکن خروجی.
							</p>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-mode">واحد شمارش</label>
							<select id="avandwp-lf-mode" name="length_mode">
								<option value="words">کلمه</option>
								<option value="characters">کاراکتر</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-status">وضعیت پس از تولید</label>
							<select id="avandwp-lf-status" name="post_status">
								<option value="draft">پیش‌نویس</option>
								<option value="pending">در انتظار بررسی</option>
								<option value="publish">انتشار عمومی</option>
							</select>
						</div>
					</div>

					<div class="avandwp-checkbox-group">
						<label class="avandwp-checkbox">
							<input type="checkbox" id="avandwp-lf-continue" name="continue_until_target" value="1" checked>
							<span>ادامه بده تا طول هدف کامل شود (درخواست‌های بیشتر، مقاله بلندتر)</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" id="avandwp-lf-spaces" name="length_include_spaces" value="1" checked>
							<span>در حالت کاراکتر، فاصله‌ها هم شمرده شوند</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" id="avandwp-lf-knowledge" name="use_knowledge" value="1" checked>
							<span>استفاده از دانش داخلی سایت (محصولات و مطالب موجود)</span>
						</label>
						<label class="avandwp-checkbox avandwp-danger-check">
							<input type="checkbox" id="avandwp-lf-confirm" name="confirm_publish" value="1">
							<span>تأیید می‌کنم در صورت انتخاب «انتشار عمومی»، نوشته بدون بازبینی من منتشر شود</span>
						</label>
					</div>

					<div class="avandwp-grid avandwp-grid-3">
						<div class="avandwp-field">
							<label for="avandwp-lf-img-featured">
								<input type="checkbox" id="avandwp-lf-img-featured" name="generate_featured_image" value="1">
								تصویر شاخص
							</label>
							<select id="avandwp-lf-img-size" name="image_size">
								<option value="1024x1024">۱:۱ مربع</option>
								<option value="1536x1024" selected>۳:۲ افقی</option>
								<option value="1024x1536">۲:۳ عمودی</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-img-inline">
								<input type="checkbox" id="avandwp-lf-img-inline" name="generate_inline_images" value="1">
								تصاویر داخل متن
							</label>
							<input type="number" id="avandwp-lf-img-count" name="inline_image_count" value="0" min="0" max="4">
							<p class="avandwp-help">
								<?php echo esc_html( AvandWP_Price_Catalog::image_cost_note( (string) ( $settings['image_provider'] ?? 'gapgpt' ), (string) ( $settings['image_model'] ?? '' ) ) ); ?>
							</p>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-lf-image-model">مدل تصویر</label>
							<input type="text" id="avandwp-lf-image-model" name="image_model" dir="ltr"
								value="<?php echo esc_attr( (string) ( $settings['image_model'] ?? '' ) ); ?>">
						</div>
					</div>

					<details class="avandwp-advanced-details">
						<summary>اطلاعات تکمیلی (مخاطب، منابع، نهادها، ساختار دلخواه)</summary>
						<div class="avandwp-advanced-body">
							<div class="avandwp-grid avandwp-grid-2">
								<div class="avandwp-field">
									<label for="avandwp-lf-audience">مخاطب هدف</label>
									<input type="text" id="avandwp-lf-audience" name="audience" placeholder="مثال: مدیران فروشگاه‌های اینترنتی تازه‌کار">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-lf-tone2">لحن</label>
									<input type="text" id="avandwp-lf-tone2" name="tone" value="حرفه‌ای، کاربردی و روان">
								</div>
							</div>

							<div class="avandwp-field">
								<label for="avandwp-lf-purpose">هدف مقاله از نگاه کسب‌وکار</label>
								<textarea id="avandwp-lf-purpose" name="purpose" rows="3" placeholder="مثال: پاسخ به پرسش‌های پیش از خرید و هدایت به صفحه مقایسه پلن‌ها"></textarea>
							</div>

							<div class="avandwp-grid avandwp-grid-2">
								<div class="avandwp-field">
									<label for="avandwp-lf-secondary">کلمات کلیدی فرعی (هر خط یک مورد)</label>
									<textarea id="avandwp-lf-secondary" name="secondary_keywords" rows="4"></textarea>
								</div>
								<div class="avandwp-field">
									<label for="avandwp-lf-entities">نهادها و نام‌های خاص (هر خط یک مورد)</label>
									<textarea id="avandwp-lf-entities" name="entities" rows="4" placeholder="مثال: ووکامرس&#10;وردپرس&#10;هاست اشتراکی"></textarea>
								</div>
							</div>

							<div class="avandwp-grid avandwp-grid-2">
								<div class="avandwp-field">
									<label for="avandwp-lf-facts">حقایق و اعدادی که باید ذکر شوند (هر خط یک مورد)</label>
									<textarea id="avandwp-lf-facts" name="required_facts" rows="4"></textarea>
								</div>
								<div class="avandwp-field">
									<label for="avandwp-lf-forbidden">ادعاهایی که نباید نوشته شوند (هر خط یک مورد)</label>
									<textarea id="avandwp-lf-forbidden" name="forbidden_claims" rows="4" placeholder="مثال: تضمین رتبه یک گوگل&#10;ارزان‌ترین هاست ایران"></textarea>
								</div>
							</div>

							<div class="avandwp-field">
								<label for="avandwp-lf-outline">ساختار دلخواه (هر خط یک سرفصل؛ خالی بگذارید تا خودش بسازد)</label>
								<textarea id="avandwp-lf-outline" name="outline" rows="6"></textarea>
							</div>

							<div class="avandwp-field">
								<label for="avandwp-lf-refs">منابع و نشانی‌های مرجع (هر خط یک نشانی)</label>
								<textarea id="avandwp-lf-refs" name="reference_urls" rows="4" dir="ltr"></textarea>
							</div>

							<div class="avandwp-grid avandwp-grid-3">
								<div class="avandwp-field">
									<label for="avandwp-lf-internal">حداکثر لینک داخلی</label>
									<input type="number" id="avandwp-lf-internal" name="max_internal_links" value="<?php echo esc_attr( (string) ( $settings['max_internal_links'] ?? 6 ) ); ?>" min="0" max="30">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-lf-external">حداکثر لینک خارجی</label>
									<input type="number" id="avandwp-lf-external" name="max_external_links" value="<?php echo esc_attr( (string) ( $settings['max_external_links'] ?? 6 ) ); ?>" min="0" max="30">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-lf-author">نام نویسنده</label>
									<input type="text" id="avandwp-lf-author" name="author_name">
								</div>
							</div>

							<div class="avandwp-field">
								<label for="avandwp-lf-style">سبک نگارش</label>
								<textarea id="avandwp-lf-style" name="writing_style" rows="3" placeholder="مثال: جمله‌های کوتاه، پرهیز از کلی‌گویی، مثال‌های عددی"></textarea>
							</div>

							<div class="avandwp-field">
								<label for="avandwp-lf-linknote">دستور لینک‌سازی داخلی</label>
								<textarea id="avandwp-lf-linknote" name="internal_link_instructions" rows="3" placeholder="مثال: به صفحه پلن‌های هاست و به مقاله پشتیبان‌گیری لینک بده"></textarea>
							</div>
						</div>
					</details>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary" id="avandwp-longform-submit">ثبت تولید مقاله بلند در صف</button>
						<span class="avandwp-text-muted">سقف درخواست این بریف: <?php echo esc_html( number_format_i18n( $longform_budget ) ); ?> — محدودیت هزینه روزانه در تنظیمات اعمال می‌شود.</span>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پیشرفت واقعی اجرا</h2>
				</div>
				<div id="avandwp-longform-result" class="avandwp-result-container" aria-live="polite">
					<div class="avandwp-empty-state">
						<p>هنوز کاری ثبت نشده است. بریف را پر کنید و تولید را آغاز کنید.</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 2: Image Studio -->
	<div id="tab-image" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تولید تصویر برای رسانه وردپرس</h2>
				</div>
				<form id="avandwp-image-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-img-prompt">توضیح تصویر (پرامپت فارسی یا انگلیسی) <span class="required">*</span></label>
						<textarea id="avandwp-img-prompt" name="prompt" rows="4" required placeholder="مثال: A clean minimalist workspace with a laptop showing e-commerce analytics charts, warm natural lighting"></textarea>
					</div>

					<div class="avandwp-field">
						<label for="avandwp-img-alt">متن جایگزین سئو (Alt Text فارسی)</label>
						<input type="text" id="avandwp-img-alt" name="alt_text" placeholder="مثال: میز کار مدیریت فروشگاه اینترنتی ووکامرس">
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-img-size">ابعاد تصویر</label>
							<select id="avandwp-img-size" name="size">
								<option value="1792x1024">افقی عریض (1792×1024 — مناسب تصویر شاخص مقاله)</option>
								<option value="1024x1024" selected>مربع (1024×1024 — مناسب محصول و شبکه اجتماعی)</option>
								<option value="1024x1792">عمودی (1024×1792 — مناسب استوری)</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-img-provider">سرویس تصویرساز</label>
							<select id="avandwp-img-provider" name="provider">
								<option value="gapgpt">GapGPT (DALL-E 3)</option>
								<option value="openai">OpenAI مستقیم (DALL-E 3)</option>
								<option value="fal_flux">FAL.ai (Flux Schnell)</option>
								<option value="stability">Stability AI (SD3)</option>
							</select>
						</div>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ساخت تصویر و افزودن به کتابخانه رسانه</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تصویر تولیدشده</h2>
				</div>
				<div id="avandwp-image-result">
					<div class="avandwp-empty-state">
						<p>تصویر ساخته‌شده بلافاصله در کتابخانه رسانه وردپرس ذخیره شده و در این بخش نمایش داده می‌شود.</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 3: Audio TTS -->
	<div id="tab-audio" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تبدیل متن به گفتار (پادکست MP3)</h2>
				</div>
				<form id="avandwp-tts-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-tts-text">متن ورودی برای خوانش <span class="required">*</span></label>
						<textarea id="avandwp-tts-text" name="text" rows="5" required placeholder="متن خلاصه مقاله یا معرفی محصول را وارد کنید..."></textarea>
					</div>
					<div class="avandwp-field">
						<label for="avandwp-tts-voice">انتخاب لحن و صدای گوینده</label>
						<select id="avandwp-tts-voice" name="voice">
							<option value="nova" selected>Nova (شفاف و طبیعی — پیشنهادی)</option>
							<option value="alloy">Alloy (متعادل و رسمی)</option>
							<option value="onyx">Onyx (بم و خبری)</option>
							<option value="shimmer">Shimmer (ملایم و صمیمی)</option>
						</select>
					</div>
					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ساخت فایل صوتی MP3</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پخش‌کننده و لینک فایل صوتی</h2>
				</div>
				<div id="avandwp-tts-result">
					<div class="avandwp-empty-state">
						<p>فایل MP3 تولیدشده در کتابخانه رسانه ذخیره شده و کد کوتاه پخش‌کننده آن در اینجا قرار می‌گیرد.</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
