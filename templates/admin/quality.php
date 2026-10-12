<?php
/**
 * Admin page: Quality Lab (SEO/AIO/GEO) and Link Intelligence.
 *
 * Everything on this page is read-only until the user acts. The scores shown
 * here come from `AvandWP_Quality_Lab` for the content the user selects; the
 * page never computes a score of its own and never displays a score it did not
 * receive from the evaluator.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! AvandWP_Capabilities::user_can( AvandWP_Capabilities::create_capability() ) ) {
	wp_die( esc_html( 'شما دسترسی لازم برای مشاهده این بخش را ندارید.' ) );
}

$avandwp_profiles = AvandWP_Criteria::profiles();
$avandwp_recent   = get_posts(
	array(
		'post_type'      => AvandWP_Capabilities::managed_post_types(),
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => 30,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
?>
<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

<div class="avandwp-grid avandwp-grid-2">

	<section class="avandwp-card">
		<h2>کیفیت محتوا — سه نمایه مستقل</h2>

		<p class="avandwp-help">
			هر نمایه از معیارهای وزنی خودش محاسبه می‌شود. یک متن می‌تواند در سئو قوی و در GEO ضعیف باشد؛
			به همین دلیل به‌جای یک عدد نهایی، سه نتیجه جدا نمایش داده می‌شود.
		</p>

		<div class="avandwp-profile-list">
			<?php foreach ( $avandwp_profiles as $avandwp_key => $avandwp_profile ) : ?>
				<div class="avandwp-profile" data-profile="<?php echo esc_attr( $avandwp_key ); ?>">
					<h3><?php echo esc_html( $avandwp_profile['label'] ); ?></h3>
					<p><?php echo esc_html( $avandwp_profile['definition'] ); ?></p>
					<p class="avandwp-note"><?php echo esc_html( $avandwp_profile['not_a_claim'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<hr />

		<div class="avandwp-field">
			<label for="avandwp-quality-post">محتوا را انتخاب کنید</label>
			<select id="avandwp-quality-post" class="avandwp-input">
				<option value="0">— انتخاب نوشته —</option>
				<?php foreach ( $avandwp_recent as $avandwp_post ) : ?>
					<option value="<?php echo esc_attr( (string) $avandwp_post->ID ); ?>">
						<?php echo esc_html( $avandwp_post->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="avandwp-field">
			<label for="avandwp-quality-keyword">کلمه کلیدی کانونی</label>
			<input type="text" id="avandwp-quality-keyword" class="avandwp-input" placeholder="مثلاً: طراحی سایت فروشگاهی" />
			<p class="avandwp-help">
				اگر خالی بماند، معیارهای وابسته به کلمه کلیدی «قابل ارزیابی نیست» علامت می‌خورند و از مخرج امتیاز حذف می‌شوند —
				نه اینکه شکست بخورند.
			</p>
		</div>

		<div class="avandwp-field avandwp-field-inline">
			<label for="avandwp-quality-target">طول هدف</label>
			<input type="number" id="avandwp-quality-target" class="avandwp-input avandwp-input-sm" value="4500" min="0" max="30000" />
			<select id="avandwp-quality-mode" class="avandwp-input avandwp-input-sm">
				<option value="words">کلمه</option>
				<option value="characters">کاراکتر</option>
			</select>
			<label class="avandwp-checkbox">
				<input type="checkbox" id="avandwp-quality-spaces" checked />
				احتساب فاصله‌ها در شمارش کاراکتر
			</label>
		</div>

		<p>
			<button type="button" class="button button-primary" id="avandwp-run-quality">ارزیابی محتوا</button>
		</p>

		<div id="avandwp-quality-result" class="avandwp-result" aria-live="polite"></div>
	</section>

	<section class="avandwp-card">
		<h2>لینک‌سازی هوشمند</h2>

		<p class="avandwp-help">
			تحلیل لینک‌ها فقط خواندنی است. هیچ لینکی بدون تأیید صریح شما درج نمی‌شود و هر لینک درج‌شده
			به‌صورت جداگانه قابل بازگردانی است.
		</p>

		<div class="avandwp-field">
			<label for="avandwp-links-post">نوشته</label>
			<select id="avandwp-links-post" class="avandwp-input">
				<option value="0">— انتخاب نوشته —</option>
				<?php foreach ( $avandwp_recent as $avandwp_post ) : ?>
					<option value="<?php echo esc_attr( (string) $avandwp_post->ID ); ?>">
						<?php echo esc_html( $avandwp_post->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<p>
			<button type="button" class="button" id="avandwp-analyze-links">تحلیل لینک‌های این نوشته</button>
		</p>

		<div id="avandwp-links-result" class="avandwp-result" aria-live="polite"></div>
	</section>

</div>

<section class="avandwp-card">
	<h2>معیارهای ارزیابی</h2>
	<p class="avandwp-help">
		فهرست کامل معیارها، وزن هر معیار و اینکه هر کدام به چه پرسشی پاسخ می‌دهد.
		این فهرست همان چیزی است که موتور امتیازدهی استفاده می‌کند؛ هیچ معیار پنهانی وجود ندارد.
	</p>
	<div id="avandwp-criteria-table" class="avandwp-table-wrap"></div>
</section>

<script type="application/json" id="avandwp-criteria-data">
	<?php echo wp_json_encode( AvandWP_Quality_Lab::describe_all(), JSON_UNESCAPED_UNICODE ); ?>
</script>
