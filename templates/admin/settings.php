<?php
/**
 * Admin Page 6: Settings, Vault & System Health (`templates/admin/settings.php`).
 *
 * 3-tab workspace:
 * 1) AES-256-GCM Credential Vault, Provider/Model Selection, and collapsible Advanced Settings.
 * 2) System Health & Live Diagnostics (verifies all 8 DB tables, OpenSSL, WP-Cron, WooCommerce).
 * 3) Job Queue, Real Token Usage Ledger, and Authenticated XLSX / Print-to-PDF Reports.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings    = get_option( 'avandwp_settings', array() );
$health      = AvandWP_Health::get_report();
$usage_30d   = AvandWP_Logger::get_usage_summary( 30 );
$recent_jobs = AvandWP_Job_Queue::get_recent_jobs( 25 );
$xlsx_url    = wp_nonce_url( admin_url( 'admin-post.php?action=avandwp_export_xlsx' ), 'avandwp_export_xlsx_nonce', '_wpnonce' );

// Every field falls back to the same defaults the activator writes. Hardcoding
// fallbacks here produced a screen that displayed retired model ids
// (`gpt-4o-mini`, `dall-e-3`) as if they were current.
$defaults   = AvandWP_Activator::default_settings();
$vault_slots = AvandWP_Vault::supported_slots();
$google      = AvandWP_Google_Auth::status();

/**
 * Read a settings value with its canonical default behind it.
 *
 * @param array  $settings Settings array.
 * @param array  $defaults Defaults array.
 * @param string $key      Key.
 * @param mixed  $fallback Last-resort fallback.
 * @return mixed
 */
$avandwp_value = static function ( $settings, $defaults, $key, $fallback = '' ) {
	if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
		return $settings[ $key ];
	}

	return $defaults[ $key ] ?? $fallback;
};
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="settings">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-vault">۱. کلیدهای API و مدل‌ها</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-health">۲. سلامت سامانه و عیب‌یاب</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-usage">۳. صف پردازش، مصرف توکن و گزارش‌ها</button>
	</div>

	<!-- TAB 1: API Keys Vault & Model Settings -->
	<div id="tab-vault" class="avandwp-tab-panel is-active">
		<form id="avandwp-settings-form" class="avandwp-form">
			<div class="avandwp-grid avandwp-grid-2">
				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<div>
							<h2>گاوصندوق رمزنگاری‌شده کلیدهای API</h2>
							<p class="avandwp-text-muted">کلیدها با الگوریتم استاندارد <code>AES-256-GCM</code> در دیتابیس ذخیره می‌شوند و هرگز در کد صفحه افشا نمی‌گردند.</p>
						</div>
					</div>

					<?php
					// Text providers can be probed with a live request; the rest
					// cannot be tested meaningfully without spending money or
					// sending a message, so they only get an input.
					$testable_slots = array( 'gapgpt', 'openai', 'gemini', 'claude' );

					foreach ( $vault_slots as $slot => $slot_label ) :
						$masked = AvandWP_Vault::get_masked_key( $slot );
						?>
						<div class="avandwp-field">
							<label for="avandwp-key-<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $slot_label ); ?></label>
							<div style="display:flex;gap:8px;">
								<input type="text" id="avandwp-key-<?php echo esc_attr( $slot ); ?>"
								       name="keys[<?php echo esc_attr( $slot ); ?>]"
								       dir="ltr"
								       autocomplete="off"
								       value="<?php echo esc_attr( $masked ); ?>"
								       placeholder="کلید API را وارد کنید...">
								<?php if ( in_array( $slot, $testable_slots, true ) ) : ?>
									<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm avandwp-test-provider-btn" data-provider="<?php echo esc_attr( $slot ); ?>">
										تست اتصال
									</button>
								<?php endif; ?>
							</div>
							<label class="avandwp-checkbox avandwp-clear-key">
								<input type="checkbox" name="clear_keys[<?php echo esc_attr( $slot ); ?>]" value="1">
								<span>پاک کردن این کلید از دیتابیس</span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<h2>انتخاب سرویس پیش‌فرض و مدل‌ها</h2>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-default-provider">سرویس هوش مصنوعی اصلی</label>
							<select id="avandwp-default-provider" name="default_provider">
								<?php foreach ( AvandWP_Provider_Registry::for_capability( 'text', false ) as $avandwp_slug ) : ?>
									<?php $avandwp_provider = AvandWP_Provider_Registry::get( $avandwp_slug ); ?>
									<option value="<?php echo esc_attr( $avandwp_slug ); ?>" <?php selected( $avandwp_value( $settings, $defaults, 'default_provider', 'gapgpt' ), $avandwp_slug ); ?>>
										<?php echo esc_html( $avandwp_provider['label'] ); ?><?php echo AvandWP_Vault::has_key( $avandwp_slug ) ? '' : ' — بدون کلید'; ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-fallback-provider">سرویس پشتیبان خودکار (Fallback)</label>
							<select id="avandwp-fallback-provider" name="fallback_provider">
								<option value="" <?php selected( $avandwp_value( $settings, $defaults, 'fallback_provider', '' ), '' ); ?>>بدون سرویس پشتیبان</option>
								<?php foreach ( AvandWP_Provider_Registry::for_capability( 'text', false ) as $avandwp_slug ) : ?>
									<?php $avandwp_provider = AvandWP_Provider_Registry::get( $avandwp_slug ); ?>
									<option value="<?php echo esc_attr( $avandwp_slug ); ?>" <?php selected( $avandwp_value( $settings, $defaults, 'fallback_provider', '' ), $avandwp_slug ); ?>>
										<?php echo esc_html( $avandwp_provider['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="avandwp-help">اگر سرویس اصلی خطای موقت بدهد، تنها درخواست همان مرحله به سرویس پشتیبان منتقل می‌شود. خطاهای احراز هویت و درخواست نامعتبر منتقل نمی‌شوند.</p>
						</div>
					</div>

					<?php
					// Model inputs are paired with a datalist built from the
					// provider's own published catalogue. A free-text field alone
					// let a retired identifier be saved and then sent to the API,
					// which is exactly how the baseline produced payable 404s.
					$text_slots = array( 'gapgpt', 'openai', 'gemini', 'claude' );
					?>
					<div class="avandwp-grid avandwp-grid-2">
						<?php foreach ( $text_slots as $slot ) : ?>
							<?php
							$catalog = AvandWP_Provider_Registry::models( 'text', $slot );
							$field   = 'model_' . $slot;
							?>
							<div class="avandwp-field">
								<label for="avandwp-model-<?php echo esc_attr( $slot ); ?>">مدل <?php echo esc_html( AvandWP_Provider_Registry::get( $slot )['label'] ?? $slot ); ?></label>
								<input type="text" id="avandwp-model-<?php echo esc_attr( $slot ); ?>" name="<?php echo esc_attr( $field ); ?>" dir="ltr"
									list="avandwp-models-<?php echo esc_attr( $slot ); ?>"
									value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, $field, '' ) ); ?>">
								<?php if ( empty( $catalog ) ) : ?>
									<p class="avandwp-help">این سرویس فهرست قیمت منتشرشده در کاتالوگ تأییدشده این افزونه ندارد؛ مدل باید از مستندات رسمی خود سرویس گرفته شود.</p>
								<?php else : ?>
									<datalist id="avandwp-models-<?php echo esc_attr( $slot ); ?>">
										<?php foreach ( $catalog as $avandwp_model ) : ?>
											<option value="<?php echo esc_attr( $avandwp_model['id'] ); ?>">
												<?php echo esc_html( AvandWP_Price_Catalog::describe_text_model( $avandwp_model['id'], $slot ) ); ?>
											</option>
										<?php endforeach; ?>
									</datalist>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-image-provider">سرویس تصویرساز</label>
							<select id="avandwp-image-provider" name="image_provider">
								<?php foreach ( AvandWP_Provider_Registry::for_capability( 'image', false ) as $avandwp_slug ) : ?>
									<option value="<?php echo esc_attr( $avandwp_slug ); ?>" <?php selected( $avandwp_value( $settings, $defaults, 'image_provider', 'gapgpt' ), $avandwp_slug ); ?>>
										<?php echo esc_html( AvandWP_Provider_Registry::get( $avandwp_slug )['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-image-model">مدل تصویر</label>
							<input type="text" id="avandwp-image-model" name="image_model" dir="ltr"
								value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'image_model', '' ) ); ?>">
							<p class="avandwp-help">
								<?php echo esc_html( AvandWP_Price_Catalog::image_cost_note( (string) $avandwp_value( $settings, $defaults, 'image_provider', 'gapgpt' ), (string) $avandwp_value( $settings, $defaults, 'image_model', '' ) ) ); ?>
							</p>
						</div>
					</div>

					<details class="avandwp-advanced-details">
						<summary>تنظیمات پیشرفته (دما، سقف توکن، زمان انتظار)</summary>
						<div class="avandwp-advanced-body">
							<div class="avandwp-grid avandwp-grid-3">
								<div class="avandwp-field">
									<label for="avandwp-temp">دمای خلاقیت (Temperature)</label>
									<input type="number" step="0.1" min="0" max="1.5" id="avandwp-temp" name="temperature" dir="ltr" value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'temperature', 0.7 ) ); ?>">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-max-tokens">سقف توکن خروجی</label>
									<input type="number" min="200" max="<?php echo esc_attr( (string) AvandWP_AI_Client::HARD_MAX_OUTPUT_TOKENS ); ?>" id="avandwp-max-tokens" name="max_tokens" dir="ltr" value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'max_tokens', 8000 ) ); ?>">
									<p class="avandwp-help">یک مقاله ۴۵۰۰ کلمه‌ای به حدود <?php echo esc_html( number_format_i18n( AvandWP_Article_Brief::estimate_output_tokens( AvandWP_Article_Brief::DEFAULT_LENGTH ) ) ); ?> توکن خروجی نیاز دارد؛ سقف پیش‌فرض قدیمی (۴۰۹۶) همین هدف را غیرقابل‌دستیابی می‌کرد.</p>
								</div>
								<div class="avandwp-field">
									<label for="avandwp-timeout">زمان انتظار هر درخواست (ثانیه)</label>
									<input type="number" min="15" max="300" id="avandwp-timeout" name="request_timeout" dir="ltr" value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'request_timeout', 120 ) ); ?>">
								</div>
							</div>
						</div>
					</details>


					<div class="avandwp-card">
						<div class="avandwp-card-header">
							<div>
								<h2>اتصال گوگل (Search Console و Google Analytics 4)</h2>
								<p class="avandwp-text-muted">دسترسی فقط خواندنی. داده‌ها تنها پس از اتصال کامل شما نمایش داده می‌شوند؛ تا آن زمان هیچ عددی — نه صفر و نه نمونه — نشان داده نمی‌شود.</p>
							</div>
						</div>

						<div class="avandwp-field">
							<label for="avandwp-google-client-id">Google OAuth Client ID</label>
							<input type="text" id="avandwp-google-client-id" name="google_client_id" dir="ltr" autocomplete="off"
								value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'google_client_id', '' ) ); ?>"
								placeholder="1234567890-xxxxxxxx.apps.googleusercontent.com">
							<p class="avandwp-help">
								Client ID عمومی است و اینجا ذخیره می‌شود. Client Secret در گاوصندوق رمزنگاری‌شده (کارت بالا، ردیف «Google OAuth — Client Secret») ثبت می‌شود.
							</p>
						</div>

						<div class="avandwp-field">
							<label>Redirect URI — دقیقاً این مقدار را در Google Cloud Console ثبت کنید</label>
							<code dir="ltr"><?php echo esc_html( $google['redirect_uri'] ); ?></code>
							<p class="avandwp-help">هر تفاوتی در این نشانی باعث خطای <code>redirect_uri_mismatch</code> در گوگل می‌شود.</p>
						</div>

						<div class="avandwp-field">
							<label for="avandwp-ga4-property">شناسه پراپرتی GA4 (اختیاری — پس از اتصال از فهرست انتخاب کنید)</label>
							<input type="text" id="avandwp-ga4-property" name="ga4_property_id" dir="ltr"
								value="<?php echo esc_attr( (string) $avandwp_value( $settings, $defaults, 'ga4_property_id', '' ) ); ?>"
								placeholder="123456789">
							<p class="avandwp-help">
								ورود دستی فقط رقم است و در غیر این صورت رد می‌شود. توصیه‌شده: در صفحه «اتصال‌ها»
								دکمه «بارگذاری فهرست پراپرتی‌ها» را بزنید تا پراپرتی از دسترسی‌های واقعی همین حساب
								انتخاب شود. پرتال Search Console نیز همان‌جا از فهرست دسترسی‌های حساب انتخاب می‌شود.
							</p>
						</div>

						<p class="avandwp-text-muted">
							وضعیت فعلی:
							<?php if ( ! $google['configured'] ) : ?>
								<span class="avandwp-badge avandwp-badge-warning">اعتبارنامه‌ها ناقص است</span>
								<?php foreach ( $google['missing'] as $avandwp_missing ) : ?>
									<br><?php echo esc_html( $avandwp_missing ); ?>
								<?php endforeach; ?>
							<?php elseif ( $google['connected'] ) : ?>
								<span class="avandwp-badge avandwp-badge-success">متصل<?php echo '' !== $google['email'] ? ' (' . esc_html( $google['email'] ) . ')' : ''; ?></span>
							<?php else : ?>
								<span class="avandwp-badge avandwp-badge-info">اعتبارنامه‌ها ثبت شده — اتصال انجام نشده</span>
							<?php endif; ?>
							<br>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-integrations' ) ); ?>">صفحه اتصال گوگل، داده‌های عملکرد و ممیزی سایت</a>
						</p>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ذخیره تمامی تنظیمات و کلیدها</button>
					</div>
				</div>
			</div>
		</form>
	</div>

	<!-- TAB 2: System Health & Diagnostics -->
	<div id="tab-health" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>بررسی وضعیت زیرساخت و محیط سرور</h2>
					<button type="button" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm" id="avandwp-repair-btn">ترمیم خودکار جداول و کرون</button>
				</div>
				<table class="avandwp-table">
					<tbody>
						<tr>
							<td>نسخه افزونه و دیتابیس</td>
							<td><code>v<?php echo esc_html( $health['plugin_version'] ); ?> (DB: <?php echo esc_html( $health['db_version'] ); ?>)</code></td>
						</tr>
						<tr>
							<td>نسخه PHP و وردپرس</td>
							<td><code>PHP <?php echo esc_html( $health['php_version'] ); ?> | WP <?php echo esc_html( $health['wp_version'] ); ?></code></td>
						</tr>
						<tr>
							<td>رمزنگاری سخت‌افزاری OpenSSL (AES-256-GCM)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo $health['openssl_aes_gcm'] ? 'success' : 'warning'; ?>">
									<?php echo $health['openssl_aes_gcm'] ? 'فعال و ایمن' : 'غیرفعال (Fallback)'; ?>
								</span>
							</td>
						</tr>
						<tr>
							<td>وضعیت زمان‌بندی پس‌زمینه (WP-Cron)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo ! $health['wp_cron_disabled'] ? 'success' : 'warning'; ?>">
									<?php echo ! $health['wp_cron_disabled'] ? 'فعال' : 'DISABLE_WP_CRON فعال است'; ?>
								</span>
							</td>
						</tr>
						<tr>
							<td>ماژول ZipArchive (خروجی اکسل)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo $health['ziparchive_available'] ? 'success' : 'warning'; ?>">
									<?php echo $health['ziparchive_available'] ? 'فعال' : 'غیرفعال'; ?>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>وضعیت ۸ جدول اختصاصی دیتابیس</h2>
				</div>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>نام جدول</th>
							<th>وضعیت</th>
							<th>تعداد رکورد</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $health['tables'] as $t_name => $t_info ) : ?>
							<tr>
								<td><code><?php echo esc_html( $t_name ); ?></code></td>
								<td>
									<span class="avandwp-badge avandwp-badge-<?php echo $t_info['exists'] ? 'success' : 'danger'; ?>">
										<?php echo $t_info['exists'] ? 'سالم' : 'ایجاد نشده'; ?>
									</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $t_info['rows'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- TAB 3: Usage, Jobs & Executive Reports -->
	<div id="tab-usage" class="avandwp-tab-panel" hidden>
		<div class="avandwp-card" style="margin-bottom:20px;">
			<div class="avandwp-card-header">
				<div>
					<h2>گزارش مدیریتی و مصرف توکن (۳۰ روز اخیر)</h2>
					<p class="avandwp-text-muted">آمار واقعی ثبت‌شده از فراخوانی‌های موفق API</p>
				</div>
				<div style="display:flex;gap:8px;">
					<a href="<?php echo esc_url( $xlsx_url ); ?>" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">دانلود گزارش اکسل (.xlsx)</a>
					<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm" onclick="window.print();">چاپ / ذخیره PDF استاندارد</button>
				</div>
			</div>

			<?php if ( empty( $usage_30d['by_provider'] ) ) : ?>
				<div class="avandwp-empty-state">
					<p>هنوز هیچ مصرف توکنی در ۳۰ روز گذشته ثبت نشده است.</p>
				</div>
			<?php else : ?>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>سرویس‌دهنده</th>
							<th>مدل</th>
							<th>تعداد درخواست</th>
							<th>مجموع توکن</th>
							<th>هزینه تخمینی (USD)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $usage_30d['by_provider'] as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( strtoupper( $row['provider'] ) ); ?></strong></td>
								<td><code><?php echo esc_html( $row['model'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['total_tokens'] ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $row['cost_usd'], 5 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
