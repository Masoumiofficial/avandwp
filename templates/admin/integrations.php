<?php
/**
 * Admin page: Google integrations (Search Console, GA4) and the site audit.
 *
 * The page renders the *state* of each integration. When an integration is not
 * connected it shows the blocking reason, never a placeholder number: a
 * dashboard reading "0 clicks" when the truth is "not connected" is exactly the
 * kind of fabrication this plugin exists to remove.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html( 'شما دسترسی لازم برای مشاهده این بخش را ندارید.' ) );
}

$avandwp_google      = AvandWP_Google_Auth::status();
$avandwp_readiness   = AvandWP_Analytics_Data::readiness_report();
$avandwp_auditor     = new AvandWP_Site_Auditor();
$avandwp_audit_runs  = $avandwp_auditor->recent_runs( 8 );
$avandwp_settings    = get_option( 'avandwp_settings', array() );
$avandwp_latest_run  = ! empty( $avandwp_audit_runs ) ? (int) $avandwp_audit_runs[0]['id'] : 0;

// The Search Console property is resolved without an extra API call on page
// load: only the stored choice is read here, and the property list is fetched
// when the owner asks for it.
$avandwp_gsc_selected = (string) ( $avandwp_settings['gsc_property'] ?? '' );
?>
<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

<section class="avandwp-card">
	<h2>اتصال به گوگل (Search Console و Analytics)</h2>

	<div class="avandwp-status-grid">
		<div class="avandwp-status-tile">
			<span class="avandwp-status-label">وضعیت اتصال</span>
			<span class="avandwp-status-value <?php echo $avandwp_google['connected'] ? 'is-good' : 'is-blocked'; ?>">
				<?php echo $avandwp_google['connected'] ? 'متصل' : 'متصل نیست'; ?>
			</span>
			<?php if ( '' !== $avandwp_google['email'] ) : ?>
				<small><?php echo esc_html( $avandwp_google['email'] ); ?></small>
			<?php endif; ?>
		</div>

		<div class="avandwp-status-tile">
			<span class="avandwp-status-label">Search Console</span>
			<span class="avandwp-status-value"><?php echo esc_html( $avandwp_readiness['gsc']['state'] ); ?></span>
			<small><?php echo esc_html( $avandwp_readiness['gsc']['message'] ); ?></small>
		</div>

		<div class="avandwp-status-tile">
			<span class="avandwp-status-label">Google Analytics 4</span>
			<span class="avandwp-status-value"><?php echo esc_html( $avandwp_readiness['ga4']['state'] ); ?></span>
			<small><?php echo esc_html( $avandwp_readiness['ga4']['message'] ); ?></small>
		</div>
	</div>

	<?php if ( ! empty( $avandwp_readiness['blocked_notes'] ) ) : ?>
		<div class="avandwp-notice avandwp-notice-info">
			<ul>
				<?php foreach ( $avandwp_readiness['blocked_notes'] as $avandwp_note ) : ?>
					<li><?php echo esc_html( $avandwp_note ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! $avandwp_google['configured'] ) : ?>
		<div class="avandwp-notice avandwp-notice-warning">
			<strong>اتصال تا تکمیل اعتبارنامه‌ها مسدود است.</strong>
			<ul>
				<?php foreach ( $avandwp_google['missing'] as $avandwp_missing ) : ?>
					<li><?php echo esc_html( $avandwp_missing ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				مراحل: یک پروژه در Google Cloud بسازید، دو API یعنی
				«Google Search Console API» و «Google Analytics Data API» را فعال کنید،
				یک OAuth Client از نوع Web Application بسازید و نشانی بازگشت زیر را در آن ثبت کنید.
				سپس Client ID را در «تنظیمات و سلامت» و Client Secret را در بخش کلیدها وارد کنید.
			</p>
			<p>
				<label>نشانی بازگشت (Redirect URI) — دقیقاً همین مقدار را در Google Cloud ثبت کنید:</label>
				<code><?php echo esc_html( $avandwp_google['redirect_uri'] ); ?></code>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $avandwp_google['last_error'] ) : ?>
		<div class="avandwp-notice avandwp-notice-error">
			<strong>آخرین خطای گوگل:</strong>
			<?php echo esc_html( $avandwp_google['last_error'] ); ?>
		</div>
	<?php endif; ?>

	<p>
		<?php if ( $avandwp_google['connected'] ) : ?>
			<button type="button" class="button" id="avandwp-google-disconnect">قطع اتصال و حذف توکن‌ها</button>
		<?php else : ?>
			<button type="button" class="button button-primary" id="avandwp-google-connect" <?php disabled( ! $avandwp_google['configured'] ); ?>>
				اتصال به حساب گوگل
			</button>
		<?php endif; ?>
		<button type="button" class="button" id="avandwp-google-readiness">بررسی وضعیت</button>
	</p>

	<div id="avandwp-google-result" class="avandwp-result" aria-live="polite"></div>
</section>

<?php if ( $avandwp_google['connected'] ) : ?>
	<section class="avandwp-card">
		<h2>انتخاب پرتال Search Console</h2>

		<p class="avandwp-help">
			گزارش‌های Search Console برای یک «پرتال» خوانده می‌شوند، نه برای یک دامنه. نشانی باید دقیقاً
			همان چیزی باشد که در Search Console ثبت شده است: یا شکل <code>https://example.com/</code> یا شکل
			<code>sc-domain:example.com</code>. پرتال فقط از میان دسترسی‌های همین حساب گوگل انتخاب می‌شود؛
			عدد یا نشانی دلخواه پذیرفته نمی‌شود.
		</p>

		<p class="avandwp-help">
			وضعیت فعلی:
			<?php if ( '' !== $avandwp_gsc_selected ) : ?>
				<code dir="ltr"><?php echo esc_html( $avandwp_gsc_selected ); ?></code> (انتخاب‌شده)
			<?php else : ?>
				انتخاب نشده — هنگام خواندن گزارش، پرتالی که با دامنه همین سایت هم‌خوانی داشته باشد
				به‌صورت خودکار انتخاب می‌شود و اگر چنین پرتالی وجود نداشته باشد، خطای صریح نمایش داده می‌شود،
				نه عدد سایت دیگری.
			<?php endif; ?>
		</p>

		<p class="avandwp-buttons">
			<button type="button" class="button" id="avandwp-google-sites">بارگذاری فهرست پرتال‌ها</button>
		</p>

		<div id="avandwp-google-site-list" class="avandwp-result" data-current="<?php echo esc_attr( $avandwp_gsc_selected ); ?>" aria-live="polite"></div>
	</section>

	<section class="avandwp-card">
		<h2>داده‌های عملکرد</h2>
		<p class="avandwp-help">
			همه اعداد این بخش مستقیماً از API گوگل خوانده می‌شوند. اگر پاسخ گوگل ناقص باشد
			(آستانه‌گذاری یا جمع‌شدن ردیف‌ها در «(other)») همان وضعیت در همان‌جا ذکر می‌شود.
		</p>

		<p class="avandwp-field-inline">
			<label for="avandwp-google-days">بازه زمانی (روز)</label>
			<input type="number" id="avandwp-google-days" class="avandwp-input avandwp-input-sm" value="28" min="1" max="180" />
		</p>

		<p class="avandwp-buttons">
			<button type="button" class="button avandwp-google-load" data-source="gsc_queries">عبارت‌های جستجو (Search Console)</button>
			<button type="button" class="button avandwp-google-load" data-source="gsc_pages">صفحه‌های برتر (Search Console)</button>
			<button type="button" class="button avandwp-google-load" data-source="gsc_opportunities">فرصت‌های نرخ کلیک</button>
			<button type="button" class="button avandwp-google-load" data-source="ga4_overview">نمای کلی Analytics</button>
			<button type="button" class="button avandwp-google-load" data-source="ga4_pages">صفحه‌های برتر (Analytics)</button>
		</p>

		<div id="avandwp-google-data" class="avandwp-result" aria-live="polite"></div>

		<hr />

		<h3>انتخاب پراپرتی Google Analytics 4</h3>

		<p class="avandwp-help">
			Data API گوگل نمی‌تواند خودش پراپرتی‌ها را فهرست کند؛ فهرست از Administration API خوانده می‌شود
			و پس از انتخاب، فقط شناسه عددی پراپرتی ذخیره می‌شود. اگر پراپرتی دلخواه در این فهرست نیست،
			یعنی حساب متصل به آن دسترسی ندارد.
		</p>

		<p class="avandwp-buttons">
			<button type="button" class="button" id="avandwp-google-properties">بارگذاری فهرست پراپرتی‌ها</button>
		</p>

		<div id="avandwp-google-property-list" class="avandwp-result" data-current="<?php echo esc_attr( (string) ( $avandwp_settings['ga4_property_id'] ?? '' ) ); ?>" aria-live="polite"></div>
	</section>
<?php endif; ?>

<section class="avandwp-card">
	<h2>ممیزی فنی سایت</h2>

	<p class="avandwp-help">
		ممیزی فقط نشانی‌های همین سایت را بررسی می‌کند، به <code>robots.txt</code> خودتان احترام می‌گذارد
		و در دسته‌های کوچک اجرا می‌شود تا روی هاست اشتراکی قابل استفاده باشد.
		امتیاز این بخش یک چک‌لیست داخلی است؛ معیار رسمی هیچ موتور جستجویی نیست و تضمینی درباره رتبه نمی‌دهد.
	</p>

	<p class="avandwp-field-inline">
		<label for="avandwp-audit-max">حداکثر نشانی در این اجرا</label>
		<input type="number" id="avandwp-audit-max" class="avandwp-input avandwp-input-sm"
			value="<?php echo esc_attr( (string) ( $avandwp_settings['audit_max_urls'] ?? 150 ) ); ?>" min="10" max="<?php echo esc_attr( (string) AvandWP_Site_Auditor::MAX_URLS ); ?>" />
		<button type="button" class="button button-primary" id="avandwp-audit-start">شروع ممیزی</button>
		<button type="button" class="button" id="avandwp-audit-step" <?php disabled( ! $avandwp_latest_run ); ?>>ادامه بررسی</button>
		<button type="button" class="button" id="avandwp-audit-pause" <?php disabled( ! $avandwp_latest_run ); ?>>توقف</button>
	</p>

	<div id="avandwp-audit-progress" class="avandwp-progress-wrap" hidden>
		<div class="avandwp-progress"><div class="avandwp-progress-bar" style="width:0%"></div></div>
		<p class="avandwp-progress-text"></p>
	</div>

	<div id="avandwp-audit-result" class="avandwp-result" aria-live="polite"></div>
</section>

<?php if ( ! empty( $avandwp_audit_runs ) ) : ?>
	<section class="avandwp-card">
		<h2>اجراهای قبلی</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>#</th>
					<th>وضعیت</th>
					<th>بررسی‌شده</th>
					<th>کشف‌شده</th>
					<th>شروع</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $avandwp_audit_runs as $avandwp_run ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $avandwp_run['id'] ); ?></td>
						<td><?php echo esc_html( (string) $avandwp_run['status'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $avandwp_run['crawled'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $avandwp_run['discovered'] ) ); ?></td>
						<td><?php echo esc_html( (string) $avandwp_run['started_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif;