/**
 * AvandWP Admin Controller (`assets/js/admin.js`).
 *
 * Strictly sanitizes all dynamic strings with `escHtml()` before DOM insertion
 * to prevent DOM-based XSS vulnerabilities.
 */
(function ($) {
	'use strict';

	/**
	 * Escape HTML entities to prevent XSS in dynamic templates.
	 *
	 * @param {string} str Raw input string.
	 * @return {string} Escaped string.
	 */
	function escHtml(str) {
		if (str === null || str === undefined) {
			return '';
		}
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Show non-blocking toast notification.
	 */
	function showToast(message, type) {
		var $toast = $('#avandwp-toast');
		if (!$toast.length) {
			return;
		}
		$toast
			.removeClass('is-error is-success')
			.addClass(type === 'error' ? 'is-error' : 'is-success')
			.text(message)
			.prop('hidden', false);

		setTimeout(function () {
			$toast.prop('hidden', true);
		}, 4500);
	}

	/**
	 * Generic AJAX helper with nonce.
	 */
	function postAjax(action, data, $btn) {
		var payload = $.extend({}, data, {
			action: 'avandwp_' + action,
			nonce: AvandWPAdmin.nonce
		});

		var origText = $btn ? $btn.text() : '';
		if ($btn) {
			$btn.prop('disabled', true).text('در حال پردازش...');
		}

		return $.post(AvandWPAdmin.ajaxUrl, payload).always(function () {
			if ($btn) {
				$btn.prop('disabled', false).text(origText);
			}
		});
	}

	/**
	 * Poll a background job until completion.
	 */
	function pollJob(jobId, onComplete, onError) {
		var timer = setInterval(function () {
			$.post(AvandWPAdmin.ajaxUrl, {
				action: 'avandwp_poll_job',
				nonce: AvandWPAdmin.nonce,
				job_id: jobId
			}).done(function (res) {
				if (!res || !res.success || !res.data || !res.data.job) {
					clearInterval(timer);
					if (onError) onError('خطا در دریافت وضعیت صف.');
					return;
				}
				var job = res.data.job;
				if (job.status === 'completed') {
					clearInterval(timer);
					if (onComplete) onComplete(job.result_data || {});
				} else if (job.status === 'failed') {
					clearInterval(timer);
					if (onError) onError(job.error_message || 'خطا در اجرای کار پس‌زمینه.');
				}
			});
		}, 3500);
	}

	/**
	 * Render generated article result card safely.
	 */
	function renderArticleResult(article) {
		var html = '<div class="avandwp-article-summary">';
		html += '<h3>' + escHtml(article.title) + '</h3>';
		html += '<p><span class="avandwp-badge avandwp-badge-success">' + escHtml(article.word_count) + ' کلمه فارسی</span> ';
		html += '<span class="avandwp-badge avandwp-badge-info">مدل: ' + escHtml(article.model || '') + '</span></p>';
		html += '<p><strong>عنوان سئو:</strong> ' + escHtml(article.seo_title) + '</p>';
		html += '<p><strong>توضیحات متا:</strong> ' + escHtml(article.meta_description) + '</p>';

		if (article.image && article.image.url) {
			html += '<p><img src="' + escHtml(article.image.url) + '" alt="' + escHtml(article.title) + '" style="max-width:100%;border-radius:8px;max-height:220px;object-fit:cover;"></p>';
		}
		if (article.audio && article.audio.url) {
			html += '<p><audio controls src="' + escHtml(article.audio.url) + '" style="width:100%;"></audio></p>';
		}

		html += '<div style="display:flex;gap:8px;margin:12px 0;">';
		if (article.edit_url) {
			html += '<a href="' + escHtml(article.edit_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">ویرایش پیش‌نویس در وردپرس</a>';
		}
		if (article.view_url) {
			html += '<a href="' + escHtml(article.view_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm">پیش‌نمایش نوشته</a>';
		}
		html += '</div>';
		html += '</div>';

		$('#avandwp-article-result').html(html);
	}

	$(function () {
		// Tab Switching
		$('.avandwp-tab-btn').on('click', function () {
			var $btn = $(this);
			var target = $btn.data('tab-target');
			var $container = $btn.closest('.avandwp-wrap');

			$btn.siblings('.avandwp-tab-btn').removeClass('is-active');
			$btn.addClass('is-active');

			$container.find('.avandwp-tab-panel').removeClass('is-active').prop('hidden', true);
			$('#' + target).addClass('is-active').prop('hidden', false);
		});

		// Dashboard: Process jobs now
		$('#avandwp-run-jobs-btn').on('click', function () {
			var $btn = $(this);
			postAjax('process_jobs_now', {}, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 1000);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Studio Tab 1: Generate Article
		$('#avandwp-article-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $('#avandwp-article-submit');

			var data = {
				topic: $form.find('[name="topic"]').val(),
				keyword: $form.find('[name="keyword"]').val(),
				tone: $form.find('[name="tone"]').val(),
				target_words: $form.find('[name="target_words"]').val(),
				category_id: $form.find('[name="category_id"]').val(),
				post_status: $form.find('[name="post_status"]').val(),
				use_knowledge: $form.find('[name="use_knowledge"]').is(':checked') ? 1 : 0,
				generate_image: $form.find('[name="generate_image"]').is(':checked') ? 1 : 0,
				generate_audio: $form.find('[name="generate_audio"]').is(':checked') ? 1 : 0,
				background: $form.find('[name="background"]').is(':checked') ? 1 : 0
			};

			postAjax('generate_article', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید مقاله', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				if (res.data.queued && res.data.job_id) {
					$('#avandwp-article-result').html(
						'<div class="avandwp-empty-state"><p>در حال تولید مقاله در صف پس‌زمینه (کار #' + escHtml(res.data.job_id) + ')...</p></div>'
					);
					pollJob(
						res.data.job_id,
						function (resultData) {
							showToast('مقاله پس‌زمینه با موفقیت آماده شد.', 'success');
							renderArticleResult(resultData);
						},
						function (errMsg) {
							showToast(errMsg, 'error');
						}
					);
				} else if (res.data.article) {
					renderArticleResult(res.data.article);
				}
			});
		});

		// Studio Tab 2: Generate Image
		$('#avandwp-image-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				prompt: $form.find('[name="prompt"]').val(),
				alt_text: $form.find('[name="alt_text"]').val(),
				size: $form.find('[name="size"]').val(),
				provider: $form.find('[name="provider"]').val()
			};

			postAjax('generate_image', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید تصویر', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var img = res.data.image;
				$('#avandwp-image-result').html(
					'<div style="text-align:center;">' +
					'<img src="' + escHtml(img.url) + '" style="max-width:100%;border-radius:10px;margin-bottom:10px;">' +
					'<p><span class="avandwp-badge avandwp-badge-success">ذخیره‌شده در رسانه (#' + escHtml(img.attachment_id) + ')</span></p>' +
					'</div>'
				);
			});
		});

		// Studio Tab 3: Generate TTS Speech
		$('#avandwp-tts-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				text: $form.find('[name="text"]').val(),
				voice: $form.find('[name="voice"]').val()
			};

			postAjax('generate_speech', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید صوت', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var audio = res.data.audio;
				$('#avandwp-tts-result').html(
					'<div>' +
					'<audio controls src="' + escHtml(audio.url) + '" style="width:100%;margin-bottom:12px;"></audio>' +
					'<p><input type="text" dir="ltr" readonly value="' + escHtml(audio.url) + '" style="width:100%;"></p>' +
					'</div>'
				);
			});
		});

		// WooCommerce: Select All & Single/Bulk Optimize
		$('#avandwp-woo-select-all').on('change', function () {
			$('.avandwp-woo-item-cb').prop('checked', $(this).is(':checked'));
		});

		$(document).on('click', '.avandwp-woo-opt-single', function () {
			var $btn = $(this);
			var pid = $btn.data('id');
			postAjax('woo_optimize', { product_id: pid }, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در بهینه‌سازی محصول', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				$btn.closest('tr').fadeOut(300);
			});
		});

		$('#avandwp-woo-bulk-btn').on('click', function () {
			var ids = [];
			$('.avandwp-woo-item-cb:checked').each(function () {
				ids.push($(this).val());
			});
			if (!ids.length) {
				showToast('لطفاً حداقل یک محصول را انتخاب کنید.', 'error');
				return;
			}
			postAjax('woo_optimize', { product_ids: ids }, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-woo-create-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				name: $form.find('[name="name"]').val(),
				price: $form.find('[name="price"]').val(),
				brief: $form.find('[name="brief"]').val()
			};

			postAjax('woo_create_draft', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در ساخت محصول', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var p = res.data.product;
				$('#avandwp-woo-create-result').html(
					'<div>' +
					'<h3>' + escHtml(p.title) + '</h3>' +
					'<p><span class="avandwp-badge avandwp-badge-success">' + escHtml(p.word_count) + ' کلمه توضیحات</span></p>' +
					'<p><strong>عنوان سئو:</strong> ' + escHtml(p.seo_title) + '</p>' +
					'<p><a href="' + escHtml(p.edit_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">مشاهده و ویرایش محصول در ووکامرس</a></p>' +
					'</div>'
				);
			});
		});

		// Support Desk: Save Ticket Reply & Status
		$(document).on('click', '.avandwp-save-ticket-btn', function () {
			var $btn = $(this);
			var $box = $btn.closest('.avandwp-ticket-action-box');
			var data = {
				ticket_id: $box.data('ticket-id'),
				status: $box.find('.avandwp-ticket-status').val(),
				admin_reply: $box.find('.avandwp-ticket-reply').val()
			};
			postAjax('update_ticket', data, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Support Desk: Add & Save FAQs
		$('#avandwp-add-faq-row').on('click', function () {
			$('#avandwp-faqs-list').append(
				'<div class="avandwp-faq-row">' +
				'<input type="text" name="faq_q[]" placeholder="عنوان پرسش متداول">' +
				'<textarea name="faq_a[]" rows="2" placeholder="پاسخ دقیق و رسمی"></textarea>' +
				'</div>'
			);
		});

		$('.avandwp-promote-faq-btn').on('click', function () {
			var q = $(this).data('q');
			var a = $(this).data('a');
			$('#avandwp-faqs-list').append(
				'<div class="avandwp-faq-row">' +
				'<input type="text" name="faq_q[]" value="' + escHtml(q) + '">' +
				'<textarea name="faq_a[]" rows="2">' + escHtml(a) + '</textarea>' +
				'</div>'
			);
			showToast('پرسش به لیست FAQ اضافه شد؛ پاسخ را بازبینی و ذخیره کنید.', 'success');
		});

		$('#avandwp-faqs-form').on('submit', function (e) {
			e.preventDefault();
			var faqs = [];
			$('#avandwp-faqs-list .avandwp-faq-row').each(function () {
				var q = $(this).find('[name="faq_q[]"]').val();
				var a = $(this).find('[name="faq_a[]"]').val();
				if (q && a) {
					faqs.push({ question: q, answer: a });
				}
			});
			postAjax('save_faqs', { faqs: faqs }, $(this).find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-sync-kb-btn').on('click', function () {
			postAjax('sync_knowledge', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Chat Widget Settings
		$('#avandwp-chat-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var data = {
				enabled: $form.find('[name="enabled"]').is(':checked') ? 1 : 0,
				enable_knowledge: $form.find('[name="enable_knowledge"]').is(':checked') ? 1 : 0,
				enable_voice_input: $form.find('[name="enable_voice_input"]').is(':checked') ? 1 : 0,
				enable_handoff: $form.find('[name="enable_handoff"]').is(':checked') ? 1 : 0,
				load_vazirmatn: $form.find('[name="load_vazirmatn"]').is(':checked') ? 1 : 0,
				title: $form.find('[name="title"]').val(),
				subtitle: $form.find('[name="subtitle"]').val(),
				primary_color: $form.find('[name="primary_color"]').val(),
				position: $form.find('[name="position"]').val(),
				welcome_message: $form.find('[name="welcome_message"]').val(),
				system_prompt: $form.find('[name="system_prompt"]').val()
			};
			postAjax('save_chat_settings', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Telegram Settings, Diagnostics & Publish
		$('#avandwp-telegram-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var data = {
				bot_token: $form.find('[name="bot_token"]').val(),
				chat_id: $form.find('[name="chat_id"]').val(),
				proxy_url: $form.find('[name="proxy_url"]').val(),
				auto_publish_posts: $form.find('[name="auto_publish_posts"]').is(':checked') ? 1 : 0,
				notify_woo_orders: $form.find('[name="notify_woo_orders"]').is(':checked') ? 1 : 0,
				notify_support: $form.find('[name="notify_support"]').is(':checked') ? 1 : 0
			};
			postAjax('save_telegram', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-test-tg-btn').on('click', function () {
			postAjax('test_telegram', { send_test_message: 1 }, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					var d = res.data.diagnostics || {};
					$('#avandwp-tg-diag-result').html(
						'<div class="avandwp-badge avandwp-badge-success">ربات @' + escHtml(d.bot_username || '') + ' تأیید شد.</div>'
					);
				} else {
					showToast((res.data && res.data.message) || 'خطا در اتصال تلگرام', 'error');
				}
			});
		});

		$('#avandwp-tg-publish-form').on('submit', function (e) {
			e.preventDefault();
			var postId = $(this).find('[name="post_id"]').val();
			postAjax('publish_post_telegram', { post_id: postId }, $(this).find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Non-destructive Content Auditor
		$('#avandwp-run-audit-btn').on('click', function () {
			postAjax('run_content_audit', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$(document).on('click', '.avandwp-fix-seo-btn', function () {
			var $btn = $(this);
			var postId = $btn.data('post-id');
			postAjax('fix_post_seo', { post_id: postId }, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					$btn.replaceWith('<span class="avandwp-badge avandwp-badge-success">تکمیل شد</span>');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Settings & Vault
		$('#avandwp-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var serialized = $form.serializeArray();
			var data = {};
			$.each(serialized, function (_, field) {
				data[field.name] = field.value;
			});
			postAjax('save_settings', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('.avandwp-test-provider-btn').on('click', function () {
			var $btn = $(this);
			var provider = $btn.data('provider');
			postAjax('test_provider', { provider: provider }, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا در اتصال', 'error');
				}
			});
		});

		$('#avandwp-repair-btn').on('click', function () {
			postAjax('repair_system', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});
	});

		// -----------------------------------------------------------------
		// Quality Lab (three independent profiles)
		// -----------------------------------------------------------------
		function scoreClass(score) {
			if (score >= 80) return 'is-good';
			if (score >= 55) return 'is-fair';
			return 'is-poor';
		}

		function resultLabel(result) {
			return {
				pass: 'قبول',
				partial: 'نسبی',
				fail: 'رد',
				unavailable: 'قابل ارزیابی نیست'
			}[result] || String(result);
		}

		function renderScoreCard(profile) {
			var meta = profile.meta || {};
			var hasScore = typeof profile.score === 'number';
			var html = '<div class="avandwp-score-card ' + (hasScore ? scoreClass(profile.score) : 'is-poor') + '">';
			html += '<h4>' + escHtml(meta.label || profile.profile) + '</h4>';
			html += hasScore
				? '<div class="avandwp-score-value">' + escHtml(profile.score) + '<small>/100</small></div>'
				: '<div class="avandwp-score-value">—<small> امتیازی محاسبه نشد</small></div>';
			html += '<p class="avandwp-note">' + escHtml(meta.definition || '') + '</p>';
			html += '<p class="avandwp-note">' + escHtml(meta.not_a_claim || '') + '</p>';
			html += '<p class="avandwp-note">وزن ارزیابی‌شده: ' + escHtml(profile.earned_weight) + ' از ' + escHtml(profile.possible_weight) + '</p>';

			html += '<table class="widefat striped"><thead><tr><th>معیار</th><th>نتیجه</th><th>شاهد</th><th>وزن</th><th>توضیح</th></tr></thead><tbody>';
			(profile.criteria || []).forEach(function (c) {
				html += '<tr class="is-' + escHtml(c.result) + '">';
				html += '<td>' + escHtml(c.label) + '<br><small>' + escHtml(c.measures || '') + '</small></td>';
				html += '<td>' + escHtml(resultLabel(c.result)) + '</td>';
				html += '<td>' + escHtml(c.evidence || '') + '</td>';
				html += '<td>' + escHtml(c.weight) + '</td>';
				html += '<td>' + escHtml(c.explanation || '') + (c.recommendation ? '<br><em>' + escHtml(c.recommendation) + '</em>' : '') + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
			return html;
		}

		$('#avandwp-criteria-table').each(function () {
			var raw = $('#avandwp-criteria-data').text();
			if (!raw) return;

			var list;
			try { list = JSON.parse(raw); } catch (e) { return; }

			var labels = {};
			$('.avandwp-profile').each(function () {
				var key = $(this).data('profile');
				labels[key] = $(this).find('h3').text();
			});

			var html = '<table class="widefat striped"><thead><tr><th>نمایه</th><th>معیار</th><th>وزن</th><th>چه چیزی سنجیده می‌شود</th><th>اصلاح خودکار</th></tr></thead><tbody>';
			list.forEach(function (c, index) {
				var isFirstOfProfile = index === 0 || list[index - 1].profile !== c.profile;
				html += '<tr>';
				html += '<td>' + (isFirstOfProfile ? escHtml(labels[c.profile] || c.profile) : '') + '</td>';
				html += '<td>' + escHtml(c.label) + '</td>';
				html += '<td>' + escHtml(c.weight) + '</td>';
				html += '<td>' + escHtml(c.measures || '') + '</td>';
				html += '<td>' + (c.auto_fixable ? 'دارد' : 'ندارد') + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			$(this).html(html);
		});

		$('#avandwp-run-quality').on('click', function () {
			var $btn = $(this);
			var $out = $('#avandwp-quality-result');
			var postId = parseInt($('#avandwp-quality-post').val(), 10) || 0;
			var target = parseInt($('#avandwp-quality-target').val(), 10) || 0;

			if (!postId) {
				showToast('ابتدا یک نوشته انتخاب کنید.', 'error');
				return;
			}

			$out.html('<p class="avandwp-loading">در حال ارزیابی…</p>');

			postAjax('analyze_content', {
				post_id: postId,
				focus_keyword: $('#avandwp-quality-keyword').val(),
				length_mode: $('#avandwp-quality-mode').val(),
				length_target: target,
				length_include_spaces: $('#avandwp-quality-spaces').is(':checked') ? 1 : 0
			}, $btn).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'ارزیابی انجام نشد.') + '</p>');
					return;
				}

				var report = res.data.report || {};
				var summary = report.summary || {};
				var html = '';

				html += '<div class="avandwp-summary-box">';
				html += '<p><strong>وضعیت:</strong> ' + escHtml(summary.status || '') + '</p>';
				html += '<p><strong>گام بعدی:</strong> ' + escHtml(summary.next_action || '') + '</p>';
				if (summary.measured_length) {
					html += '<p class="avandwp-note">اندازه‌گیری واقعی: ' +
						escHtml(summary.measured_length.words) + ' کلمه، ' +
						escHtml(summary.measured_length.chars_with_spaces) + ' کاراکتر با فاصله، ' +
						escHtml(summary.measured_length.chars_without_spaces) + ' کاراکتر بدون فاصله، ' +
						escHtml(summary.measured_length.reading_minutes) + ' دقیقه مطالعه</p>';
				}
				html += '<p class="avandwp-note">' + escHtml(summary.disclaimer || '') + '</p>';
				html += '</div>';

				var profiles = report.profiles || {};
				Object.keys(profiles).forEach(function (key) {
					html += renderScoreCard(profiles[key]);
				});

				if (summary.blockers && summary.blockers.length) {
					html += '<h4>موارد بازدارنده</h4><ul>';
					summary.blockers.forEach(function (b) {
						html += '<li><strong>' + escHtml(b.label) + '</strong> — ' + escHtml(b.recommendation || b.evidence) + '</li>';
					});
					html += '</ul>';
				}

				html += '<details class="avandwp-details"><summary>همه شاخص‌های سنجیده‌شده متن</summary><pre dir="ltr">' +
					escHtml(JSON.stringify(report.facts || {}, null, 2)) + '</pre></details>';

				$out.html(html);
			});
		});

		// -----------------------------------------------------------------
		// Link Intelligence
		// -----------------------------------------------------------------
		function renderLinkReport(report) {
			var html = '<div class="avandwp-stat-row">';
			html += '<span class="avandwp-badge avandwp-badge-info">کل لینک: ' + escHtml(report.total_links) + '</span>';
			html += '<span class="avandwp-badge avandwp-badge-success">داخلی: ' + escHtml(report.internal_count) + '</span>';
			html += '<span class="avandwp-badge">خارجی: ' + escHtml(report.external_count) + '</span>';
			html += '<span class="avandwp-badge avandwp-badge-warning">بدون متن لنگر: ' + escHtml(report.empty_anchor_count) + '</span>';
			html += '</div>';

			html += '<table class="widefat striped"><thead><tr><th>متن لنگر</th><th>مقصد</th><th>نوع</th><th>متن پیشنهادی</th></tr></thead><tbody>';
			(report.links || []).forEach(function (l) {
				html += '<tr><td>' + escHtml(l.anchor) + '</td><td dir="ltr" class="avandwp-url">' + escHtml(l.url) + '</td>';
				html += '<td>' + escHtml(l.internal ? 'داخلی' : 'خارجی') + '</td>';
				html += '<td>' + escHtml(l.suggested_anchor || '—') + '</td></tr>';
			});
			html += '</tbody></table>';

			if (report.suggestions && report.suggestions.length) {
				html += '<h4>پیشنهاد درج لینک (نیازمند تأیید شما)</h4>';
				html += '<table class="widefat striped"><thead><tr><th>متن لنگر</th><th>مقصد</th><th>وضعیت</th><th></th></tr></thead><tbody>';
				report.suggestions.forEach(function (s) {
					html += '<tr><td>' + escHtml(s.anchor) + '</td><td dir="ltr" class="avandwp-url">' + escHtml(s.url) + '</td>';
					html += '<td>' + escHtml(s.reason || '') + '</td>';
					html += '<td>' + (s.insertable
						? '<button type="button" class="button button-small avandwp-apply-link" data-url="' + escHtml(s.url) + '" data-anchor="' + escHtml(s.anchor) + '">درج این لینک</button>'
						: '<span class="avandwp-note">' + escHtml(s.blocked_reason || 'قابل درج نیست') + '</span>') + '</td></tr>';
				});
				html += '</tbody></table>';
			}

			if (report.applied && report.applied.length) {
				html += '<h4>لینک‌های درج‌شده قبلی (قابل بازگردانی)</h4>';
				html += '<table class="widefat striped"><thead><tr><th>متن لنگر</th><th>مقصد</th><th></th></tr></thead><tbody>';
				report.applied.forEach(function (a) {
					html += '<tr><td>' + escHtml(a.anchor) + '</td><td dir="ltr" class="avandwp-url">' + escHtml(a.url) + '</td>';
					html += '<td><button type="button" class="button button-small avandwp-revert-link" data-link-id="' + escHtml(a.id) + '">بازگردانی</button></td></tr>';
				});
				html += '</tbody></table>';
			}

			return html;
		}

		function analyzeLinks($btn) {
			var postId = parseInt($('#avandwp-links-post').val(), 10) || 0;
			var $out = $('#avandwp-links-result');

			if (!postId) {
				showToast('ابتدا یک نوشته انتخاب کنید.', 'error');
				return;
			}

			$out.html('<p class="avandwp-loading">در حال تحلیل لینک‌ها…</p>');

			postAjax('analyze_links', { post_id: postId }, $btn).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'تحلیل انجام نشد.') + '</p>');
					return;
				}
				$out.html(renderLinkReport(res.data.report || res.data)).data('post-id', postId);
			});
		}

		$('#avandwp-analyze-links').on('click', function () { analyzeLinks($(this)); });

		$(document).on('click', '.avandwp-apply-link', function () {
			var $btn = $(this);
			var $out = $('#avandwp-links-result');
			var postId = parseInt($out.data('post-id'), 10) || parseInt($('#avandwp-links-post').val(), 10) || 0;

			postAjax('apply_links', {
				post_id: postId,
				url: $btn.data('url'),
				anchor: $btn.data('anchor')
			}, $btn).done(function (res) {
				if (res && res.success) {
					showToast(res.data.message, 'success');
					analyzeLinks(null);
				} else {
					showToast((res && res.data && res.data.message) || 'درج لینک انجام نشد.', 'error');
				}
			});
		});

		$(document).on('click', '.avandwp-revert-link', function () {
			var $btn = $(this);
			var $out = $('#avandwp-links-result');
			var postId = parseInt($out.data('post-id'), 10) || parseInt($('#avandwp-links-post').val(), 10) || 0;

			if (!window.confirm('این لینک از متن حذف شود؟')) {
				return;
			}

			postAjax('revert_link', { post_id: postId, link_id: $btn.data('linkId') }, $btn).done(function (res) {
				if (res && res.success) {
					showToast(res.data.message, 'success');
					analyzeLinks(null);
				} else {
					showToast((res && res.data && res.data.message) || 'بازگردانی انجام نشد.', 'error');
				}
			});
		});

		// -----------------------------------------------------------------
		// Site audit (batched, resumable)
		// -----------------------------------------------------------------
		function renderAuditProgress(progress) {
			if (!progress) return;

			var $wrap = $('#avandwp-audit-progress');
			$wrap.prop('hidden', false);
			$wrap.find('.avandwp-progress-bar').css('width', Math.max(0, Math.min(100, progress.percent)) + '%');
			$wrap.find('.avandwp-progress-text').text(
				'بررسی‌شده ' + progress.crawled + ' از ' + progress.max_urls +
				' نشانی (' + progress.percent + '%) — نشانی‌های کشف‌شده: ' + progress.discovered +
				' — وضعیت: ' + progress.status
			);
		}

		function auditDone($btn) {
			if ($btn) {
				$btn.prop('disabled', false).text('ادامه بررسی');
			}
		}

		function auditLoop() {
			var runId = parseInt($('#avandwp-audit-result').data('run-id'), 10) || 0;

			postAjax('audit_step', { run_id: runId }, null)
				.done(function (res) {
					if (!res || !res.success) {
						auditDone($('#avandwp-audit-step'));
						$('#avandwp-audit-result').append('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'اجرای ممیزی متوقف شد.') + '</p>');
						return;
					}

					var progress = res.data.progress || null;
					renderAuditProgress(progress);

					if (progress && progress.done) {
						auditDone($('#avandwp-audit-step'));
						renderAuditReport(progress.report);
						return;
					}

					window.setTimeout(auditLoop, 900);
				})
				.fail(function () {
					// The crawl is resumable by design: a dropped connection must
					// degrade to a retryable button, not to lost work.
					auditDone($('#avandwp-audit-step'));
					$('#avandwp-audit-result').append('<p class="avandwp-error">ارتباط با سرور قطع شد. با دکمه «ادامه بررسی» ممیزی از همان‌جا که بود ادامه پیدا می‌کند؛ نتایج قبلی حفظ شده‌اند.</p>');
				});
		}

		function renderAuditReport(report) {
			if (!report) return;

			var html = '<h3>گزارش ممیزی</h3>';
			html += '<p class="avandwp-note">' + escHtml(report.note || '') + '</p>';
			html += '<div class="avandwp-stat-row">';
			html += '<span class="avandwp-badge avandwp-badge-info">صفحه‌های بررسی‌شده: ' + escHtml(report.pages) + '</span>';
			html += '<span class="avandwp-badge avandwp-badge-info">میانگین امتیاز: ' + escHtml(report.average_score) + '</span>';
			html += '<span class="avandwp-badge avandwp-badge-warning">ضعیف‌ترین: ' + escHtml(report.worst_score) + '</span>';
			html += '<span class="avandwp-badge avandwp-badge-success">بهترین: ' + escHtml(report.best_score) + '</span>';
			html += '</div>';

			var issues = report.issue_totals || {};
			var keys = Object.keys(issues);
			if (keys.length) {
				html += '<h4>پرتکرارترین مشکلات</h4>';
				html += '<table class="widefat striped"><thead><tr><th>بررسی</th><th>تعداد صفحه دارای مشکل</th></tr></thead><tbody>';
				keys.sort(function (a, b) { return issues[b] - issues[a]; });
				keys.forEach(function (key) {
					html += '<tr><td>' + escHtml(key) + '</td><td>' + escHtml(issues[key]) + '</td></tr>';
				});
				html += '</tbody></table>';
			}

			if (report.duplicate_titles || report.duplicate_descriptions) {
				html += '<p class="avandwp-note">عنوان‌های تکراری: ' + escHtml(report.duplicate_titles) +
					' — توضیحات متای تکراری: ' + escHtml(report.duplicate_descriptions) + '</p>';
			}

			html += '<p><button type="button" class="button avandwp-audit-download">دانلود گزارش کامل JSON</button></p>';

			$('#avandwp-audit-result').append(html).data('report', report);
		}

		$(document).on('click', '.avandwp-audit-download', function () {
			var report = $('#avandwp-audit-result').data('report');
			if (!report) return;

			var blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
			var link = document.createElement('a');
			link.href = URL.createObjectURL(blob);
			link.download = 'avandwp-audit-report.json';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			URL.revokeObjectURL(link.href);
		});

		$('#avandwp-audit-start').on('click', function () {
			var $btn = $(this);
			var maxUrls = parseInt($('#avandwp-audit-max').val(), 10) || 150;
			var $out = $('#avandwp-audit-result');

			if (!window.confirm('ممیزی ' + maxUrls + ' نشانی از همین سایت بازدید می‌کند. این بازدید روی سرور بار ایجاد می‌کند؛ ادامه می‌دهید؟')) {
				return;
			}

			$out.empty();
			postAjax('audit_start', { max_urls: maxUrls }, $btn).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'ممیزی آغاز نشد.') + '</p>');
					return;
				}

				var progress = res.data.progress;
				$out.data('run-id', progress.run_id);
				renderAuditProgress(progress);

				if (progress.done) {
					renderAuditReport(progress.report);
					return;
				}

				auditLoop();
			});
		});

		$('#avandwp-audit-step').on('click', function () {
			var $btn = $(this);
			var runId = parseInt($('#avandwp-audit-result').data('run-id'), 10) || 0;

			if (!runId) {
				// No run attached to this page view: ask the server for the most
				// recent run instead of silently doing nothing.
				postAjax('audit_report', { run_id: 0 }, $btn).done(function (res) {
					if (res && res.success) {
						$('#avandwp-audit-result').data('run-id', res.data.run_id);
						renderAuditProgress(res.data);
						if (res.data.done) renderAuditReport(res.data.report);
					} else {
						showToast((res && res.data && res.data.message) || 'ممیزی فعالی یافت نشد.', 'error');
					}
				});
				return;
			}

			$btn.prop('disabled', true).text('در حال بررسی...');
			auditLoop();
		});

		$('#avandwp-audit-pause').on('click', function () {
			var $btn = $(this);
			var runId = parseInt($('#avandwp-audit-result').data('run-id'), 10) || 0;

			postAjax('audit_pause', { run_id: runId }, $btn).done(function (res) {
				if (res && res.success) {
					showToast(res.data.message, 'success');
					auditDone($('#avandwp-audit-step'));
				} else {
					showToast((res && res.data && res.data.message) || 'توقف انجام نشد.', 'error');
				}
			});
		});

		// -----------------------------------------------------------------
		// Google integrations
		// -----------------------------------------------------------------
		// The consent URL is requested at the moment of the click rather than
		// embedded in the page. `authorization_url()` stores a one-time state
		// value with a 15-minute lifetime, so generating it on every page load
		// would litter the options table with states that nobody uses — and the
		// endpoint answers with a readable reason when the client is not
		// configured yet instead of redirecting to a broken consent screen.
		$('#avandwp-google-connect').on('click', function () {
			postAjax('google_connect', {}, $(this)).done(function (res) {
				if (res && res.success && res.data && res.data.url) {
					window.location.href = res.data.url;
					return;
				}
				showToast((res && res.data && res.data.message) || 'ساخت نشانی اتصال گوگل ناموفق بود.', 'error');
			});
		});

		$('#avandwp-google-disconnect').on('click', function () {
			if (!window.confirm('توکن‌های گوگل حذف شوند؟ برای نمایش داده‌ها باید دوباره متصل شوید.')) {
				return;
			}
			postAjax('google_disconnect', {}, $(this)).done(function (res) {
				if (res && res.success) {
					showToast(res.data.message, 'success');
					window.setTimeout(function () { window.location.reload(); }, 800);
				} else {
					showToast((res && res.data && res.data.message) || 'قطع اتصال انجام نشد.', 'error');
				}
			});
		});

		// --- Search Console property picker -----------------------------
		$('#avandwp-google-sites').on('click', function () {
			var $out = $('#avandwp-google-site-list');

			postAjax('google_data', { source: 'gsc_sites' }, $(this)).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'فهرست پرتال‌ها خوانده نشد.') + '</p>');
					return;
				}

				var sites = (res.data && res.data.data) || [];
				if (!sites.length) {
					$out.html('<p class="avandwp-note">این حساب گوگل به هیچ پرتالی در Search Console دسترسی ندارد. ابتدا در Search Console مالکیت سایت را تأیید کنید.</p>');
					return;
				}

				var current = $out.data('current') || '';
				var html = '<p class="avandwp-field-inline">' +
					'<label for="avandwp-google-site">پرتال</label>' +
					'<select id="avandwp-google-site" class="avandwp-input">';

				sites.forEach(function (site) {
					var url = site && site.url ? String(site.url) : '';
					html += '<option value="' + escHtml(url) + '"' + (url === current ? ' selected' : '') + '>' +
						escHtml(url) + (site.permission ? ' — ' + escHtml(site.permission) : '') + '</option>';
				});

				html += '</select> ' +
					'<button type="button" class="button button-primary" id="avandwp-google-site-save">ذخیره پرتال</button> ' +
					'<button type="button" class="button" id="avandwp-google-site-clear">حذف انتخاب و بازگشت به تطبیق خودکار</button>' +
					'</p>';

				$out.html(html);
			});
		});

		$(document).on('click', '#avandwp-google-site-save', function () {
			var $btn = $(this);
			var value = $('#avandwp-google-site').val() || '';

			postAjax('google_select_site', { gsc_property: value }, $btn).done(function (res) {
				if (res && res.success) {
					$('#avandwp-google-site-list').data('current', res.data.gsc_property || '');
					showToast(res.data.message, 'success');
					window.setTimeout(function () { window.location.reload(); }, 900);
					return;
				}
				showToast((res && res.data && res.data.message) || 'ذخیره پرتال انجام نشد.', 'error');
			});
		});

		$(document).on('click', '#avandwp-google-site-clear', function () {
			var $btn = $(this);

			postAjax('google_select_site', { gsc_property: '' }, $btn).done(function (res) {
				if (res && res.success) {
					$('#avandwp-google-site-list').data('current', '');
					showToast(res.data.message, 'success');
					window.setTimeout(function () { window.location.reload(); }, 900);
					return;
				}
				showToast((res && res.data && res.data.message) || 'حذف انتخاب انجام نشد.', 'error');
			});
		});

		// --- GA4 property picker ---------------------------------------
		//
		// The Data API cannot enumerate properties, so the list comes from the
		// Administration API. Only the numeric id is sent back for storage.
		$('#avandwp-google-properties').on('click', function () {
			var $out = $('#avandwp-google-property-list');

			postAjax('google_data', { source: 'ga4_properties' }, $(this)).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'فهرست پراپرتی‌ها خوانده نشد.') + '</p>');
					return;
				}

				var properties = (res.data && res.data.data) || [];
				if (!properties.length) {
					$out.html('<p class="avandwp-note">این حساب گوگل به هیچ پراپرتی GA4 دسترسی ندارد.</p>');
					return;
				}

				var current = String($out.data('current') || '');
				var html = '<p class="avandwp-field-inline">' +
					'<label for="avandwp-google-property">پراپرتی</label>' +
					'<select id="avandwp-google-property" class="avandwp-input">';

				properties.forEach(function (property) {
					var id = property && property.property_id ? String(property.property_id) : '';
					var label = (property.name || property.property || '') +
						(property.account ? ' — ' + property.account : '') + ' (ID: ' + id + ')';

					html += '<option value="' + escHtml(id) + '"' + (id === current ? ' selected' : '') + '>' +
						escHtml(label) + '</option>';
				});

				html += '</select> ' +
					'<button type="button" class="button button-primary" id="avandwp-google-property-save">ذخیره پراپرتی</button>' +
					'</p>';

				$out.html(html);
			});
		});

		$(document).on('click', '#avandwp-google-property-save', function () {
			var value = $('#avandwp-google-property').val() || '';

			postAjax('google_select_property', { property_id: value }, $(this)).done(function (res) {
				if (res && res.success) {
					$('#avandwp-google-property-list').data('current', res.data.property_id || '');
					showToast(res.data.message, 'success');
					return;
				}
				showToast((res && res.data && res.data.message) || 'ذخیره پراپرتی انجام نشد.', 'error');
			});
		});

		$('#avandwp-google-readiness').on('click', function () {
			postAjax('google_data', { source: 'readiness' }, $(this)).done(function (res) {
				var $out = $('#avandwp-google-result');
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'بررسی انجام نشد.') + '</p>');
					return;
				}
				$out.html('<pre dir="ltr">' + escHtml(JSON.stringify(res.data, null, 2)) + '</pre>');
			});
		});

		$('.avandwp-google-load').on('click', function () {
			var $btn = $(this);
			var source = $btn.data('source');
			var days = parseInt($('#avandwp-google-days').val(), 10) || 28;
			var $out = $('#avandwp-google-data');

			$out.html('<p class="avandwp-loading">در حال دریافت داده از گوگل…</p>');

			postAjax('google_data', { source: source, days: days }, $btn).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'دریافت داده انجام نشد.') + '</p>');
					return;
				}

				var d = res.data;

				if (d.blocked) {
					$out.html('<p class="avandwp-notice avandwp-notice-warning">' + escHtml(d.message || 'این داده‌ها تا زمان اتصال حساب گوگل در دسترس نیست.') + '</p>');
					return;
				}

				var html = '';
				if (d.note) {
					html += '<p class="avandwp-note">' + escHtml(d.note) + '</p>';
				}
				if (d.completeness) {
					html += '<p class="avandwp-note">' + escHtml(d.completeness) + '</p>';
				}
				if (d.headers && d.rows) {
					html += '<table class="widefat striped"><thead><tr>';
					d.headers.forEach(function (h) { html += '<th>' + escHtml(h) + '</th>'; });
					html += '</tr></thead><tbody>';
					d.rows.forEach(function (row) {
						html += '<tr>';
						row.forEach(function (cell) { html += '<td>' + escHtml(cell) + '</td>'; });
						html += '</tr>';
					});
					html += '</tbody></table>';
				} else {
					html += '<pre dir="ltr">' + escHtml(JSON.stringify(d.data || d, null, 2)) + '</pre>';
				}
				$out.html(html);
			});
		});

		// -----------------------------------------------------------------
		// Long-form Article Studio (Module A)
		// -----------------------------------------------------------------
		function renderLongformProgress(job) {
			var data = job.progress_data || {};
			var html = '<div class="avandwp-job-card">';
			html += '<h3>کار #' + escHtml(job.id) + ' — ' + escHtml(job.stage_label || job.stage || '') + '</h3>';

			// A percentage is only shown when the server actually measured one.
			if (job.progress === null || job.progress === undefined) {
				html += '<div class="avandwp-progress"><div class="avandwp-progress-bar is-indeterminate"></div></div>';
				html += '<p class="avandwp-progress-text">درصد قابل اندازه‌گیری نیست؛ مرحله جاری نمایش داده می‌شود.</p>';
			} else {
				html += '<div class="avandwp-progress"><div class="avandwp-progress-bar" style="width:' + Math.max(0, Math.min(100, job.progress)) + '%"></div></div>';
				html += '<p class="avandwp-progress-text">' + escHtml(job.progress) + '٪';
				if (data.step_index && data.step_total) {
					html += ' — مرحله ' + escHtml(data.step_index) + ' از ' + escHtml(data.step_total);
				}
				html += '</p>';
			}

			if (data.step_label) {
				html += '<p class="avandwp-note">مرحله: ' + escHtml(data.step_label) + '</p>';
			}

			if (data.requests !== undefined && data.max_requests) {
				html += '<p class="avandwp-note">درخواست‌های مصرف‌شده: ' + escHtml(data.requests) + ' از ' + escHtml(data.max_requests) + '</p>';
			}

			if (data.measured && data.measured.words !== undefined) {
				var m = data.measured;
				html += '<p class="avandwp-note">اندازه‌گیری واقعی متن تا این لحظه: ' + escHtml(m.words) + ' کلمه' +
					(m.target ? ' از هدف ' + escHtml(m.target) + ' ' + escHtml(m.mode === 'characters' ? 'کاراکتر' : 'کلمه') : '') + '</p>';
			}

			if (job.attempts > 1) {
				html += '<p class="avandwp-note">تلاش: ' + escHtml(job.attempts) + ' از ' + escHtml(job.max_attempts) + '</p>';
			}

			html += '</div>';
			return html;
		}

		function renderLongformResult(result) {
			if (!result) {
				return '<p class="avandwp-note">کار تمام شد اما نتیجه‌ای گزارش نشد.</p>';
			}

			var length = result.length || {};
			var quality = result.quality || {};
			var scores = quality.scores || {};

			var html = '<div class="avandwp-article-summary">';
			html += '<h3>' + escHtml(result.title || '') + '</h3>';

			html += '<p><span class="avandwp-badge avandwp-badge-success">' + escHtml(result.word_count) + ' کلمه</span> ';
			html += '<span class="avandwp-badge avandwp-badge-info">' + escHtml(result.char_count) + ' کاراکتر با فاصله</span> ';
			html += '<span class="avandwp-badge">' + escHtml(result.post_status === 'publish' ? 'منتشرشده' : (result.post_status === 'pending' ? 'در انتظار بررسی' : 'پیش‌نویس')) + '</span></p>';

			if (length.message) {
				html += '<p class="avandwp-note">' + escHtml(length.message) + '</p>';
			}

			html += '<p class="avandwp-note">درخواست‌های مصرف‌شده: ' + escHtml(result.requests) + ' از ' + escHtml(result.requests_budget) +
				(result.budget_exhausted ? ' — سقف درخواست به پایان رسید' : '') + '</p>';

			if (scores.seo !== undefined) {
				html += '<p><strong>امتیازها:</strong> سئو ' +
					escHtml(scores.seo === null ? 'محاسبه نشد' : scores.seo) + ' | AIO ' +
					escHtml(scores.aio === null ? 'محاسبه نشد' : scores.aio) + ' | GEO ' +
					escHtml(scores.geo === null ? 'محاسبه نشد' : scores.geo) + '</p>';
				if (quality.summary && quality.summary.disclaimer) {
					html += '<p class="avandwp-note">' + escHtml(quality.summary.disclaimer) + '</p>';
				}
			}

			var fix = result.auto_fix || {};
			if (fix.applied && fix.applied.length) {
				html += '<details class="avandwp-details"><summary>اصلاحات خودکار اعمال‌شده (' + escHtml(fix.applied.length) + ')</summary><ul>';
				fix.applied.forEach(function (item) {
					html += '<li>' + escHtml(item) + '</li>';
				});
				html += '</ul><p class="avandwp-note">' + escHtml(fix.policy || '') + '</p></details>';
			} else if (fix.skipped) {
				html += '<p class="avandwp-note">' + escHtml(fix.skipped) + '</p>';
			}

			var links = result.links || {};
			if (links.total !== undefined || (result.links_inserted && result.links_inserted.length)) {
				html += '<p class="avandwp-note">لینک‌ها — داخلی: ' + escHtml(links.internal || 0) +
					' ، خارجی: ' + escHtml(links.external || 0) +
					' ، درج‌شده در متن: ' + escHtml((result.links_inserted || []).length) + '</p>';
			}

			if (result.media && (result.media.featured || result.media.inline)) {
				html += '<p class="avandwp-note">تصاویر: ';
				if (result.media.featured) {
					html += 'شاخص ثبت شد';
				}
				if (result.media.inline) {
					html += (result.media.featured ? ' — ' : '') + 'داخل متن: ' + escHtml((result.media.inline_count || 0));
				}
				html += '</p>';
			}

			if (result.model) {
				html += '<p class="avandwp-note">مدل استفاده‌شده: ' + escHtml(result.model) + (result.provider ? ' (' + escHtml(result.provider) + ')' : '') + '</p>';
			}

			html += '<div style="display:flex;gap:8px;margin:12px 0;">';
			if (result.edit_url) {
				html += '<a href="' + escHtml(result.edit_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">ویرایش در وردپرس</a>';
			}
			if (result.view_url) {
				html += '<a href="' + escHtml(result.view_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm">پیش‌نمایش</a>';
			}
			html += '</div>';
			html += '</div>';
			return html;
		}

		$('#avandwp-longform-form').on('submit', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $('#avandwp-longform-submit');
			var $out = $('#avandwp-longform-result');
			var status = $form.find('[name="post_status"]').val();

			// Publishing is the one irreversible action here, so it is confirmed
			// explicitly rather than inferred from a checkbox alone.
			if (status === 'publish' && !$form.find('[name="confirm_publish"]').is(':checked')) {
				showToast('برای انتشار عمومی، کادر تأیید را علامت بزنید. در غیر این صورت پیش‌نویس ذخیره می‌شود.', 'error');
				return;
			}

			var data = {
				topic: $form.find('[name="topic"]').val(),
				working_title: $form.find('[name="working_title"]').val(),
				focus_keyword: $form.find('[name="focus_keyword"]').val(),
				search_intent: $form.find('[name="search_intent"]').val(),
				article_type: $form.find('[name="article_type"]').val(),
				category_id: $form.find('[name="category_id"]').val(),
				length_target: $form.find('[name="length_target"]').val(),
				length_mode: $form.find('[name="length_mode"]').val(),
				post_status: status,
				confirm_publish: $form.find('[name="confirm_publish"]').is(':checked') ? 1 : 0,
				continue_until_target: $form.find('[name="continue_until_target"]').is(':checked') ? 1 : 0,
				length_include_spaces: $form.find('[name="length_include_spaces"]').is(':checked') ? 1 : 0,
				use_knowledge: $form.find('[name="use_knowledge"]').is(':checked') ? 1 : 0,
				audience: $form.find('[name="audience"]').val(),
				tone: $form.find('[name="tone"]').val(),
				purpose: $form.find('[name="purpose"]').val(),
				secondary_keywords: $form.find('[name="secondary_keywords"]').val(),
				entities: $form.find('[name="entities"]').val(),
				required_facts: $form.find('[name="required_facts"]').val(),
				forbidden_claims: $form.find('[name="forbidden_claims"]').val(),
				outline: $form.find('[name="outline"]').val(),
				reference_urls: $form.find('[name="reference_urls"]').val(),
				writing_style: $form.find('[name="writing_style"]').val(),
				internal_link_instructions: $form.find('[name="internal_link_instructions"]').val(),
				author_name: $form.find('[name="author_name"]').val(),
				max_internal_links: $form.find('[name="max_internal_links"]').val(),
				max_external_links: $form.find('[name="max_external_links"]').val(),
				generate_featured_image: $form.find('[name="generate_featured_image"]').is(':checked') ? 1 : 0,
				image_size: $form.find('[name="image_size"]').val(),
				generate_inline_images: $form.find('[name="generate_inline_images"]').is(':checked') ? 1 : 0,
				inline_image_count: $form.find('[name="inline_image_count"]').val(),
				image_model: $form.find('[name="image_model"]').val()
			};

			postAjax('generate_longform', data, $btn).done(function (res) {
				if (!res || !res.success) {
					$out.html('<p class="avandwp-error">' + escHtml((res && res.data && res.data.message) || 'ثبت کار انجام نشد.') + '</p>');
					return;
				}

				var brief = res.data.brief || {};
				var html = '<div class="avandwp-job-card">';
				html += '<h3>کار #' + escHtml(res.data.job_id) + ' در صف قرار گرفت</h3>';
				html += '<p class="avandwp-note">' + escHtml(res.data.message) + '</p>';
				html += '<p class="avandwp-note">سقف درخواست این اجرا: ' + escHtml(brief.requests_budget) +
					' — وضعیت پس از تولید: ' + escHtml(brief.post_status) + '</p>';
				html += '</div>';
				$out.html(html);

				showToast('کار در صف پس‌زمینه ثبت شد.', 'success');

				pollLongformJob(res.data.job_id, $out);
			});
		});

		function pollLongformJob(jobId, $out) {
			var stopped = false;

			function tick() {
				if (stopped) return;

				$.post(AvandWPAdmin.ajaxUrl, {
					action: 'avandwp_poll_job',
					nonce: AvandWPAdmin.nonce,
					job_id: jobId
				}).done(function (res) {
					if (!res || !res.success || !res.data || !res.data.job) {
						$out.append('<p class="avandwp-error">وضعیت کار قابل خواندن نیست. با بازخوانی صفحه، وضعیت را از صف بررسی کنید.</p>');
						return;
					}

					var job = res.data.job;

					if (job.status === 'completed') {
						$out.html(renderLongformResult(job.result));
						showToast('تولید مقاله بلند تمام شد.', 'success');
						return;
					}

					if (job.status === 'failed') {
						$out.html(
							'<p class="avandwp-error">اجرای کار ناموفق بود: ' +
							escHtml(job.error_message || 'دلیل گزارش نشده است.') +
							'</p><p class="avandwp-note">مراحل انجام‌شده حفظ شده‌اند؛ علت را رفع کنید و از صفحه صف، کار را دوباره اجرا کنید.</p>'
						);
						return;
					}

					$out.html(renderLongformProgress(job));
					window.setTimeout(tick, 4000);
				}).fail(function () {
					// Polling is best effort; the job keeps running server-side
					// and its state is readable from the queue screen.
					$out.append('<p class="avandwp-note">ارتباط با سرور قطع شد. کار در پس‌زمینه ادامه دارد؛ وضعیت از صفحه «صف پردازش» قابل مشاهده است.</p>');
				});
			}

			tick();
		}

})(jQuery);
