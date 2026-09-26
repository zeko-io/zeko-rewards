(function ($) {
	'use strict';

	var ZR = window.ZekoRewards || {};

	// Redemption form.
	$(document).on('submit', '[data-zr-redeem]', function (e) {
		e.preventDefault();

		var $form = $(this);
		var $msg = $form.closest('.zr-redeem').find('[data-zr-message]');
		var points = $form.find('input[name="points"]').val();
		var $btn = $form.find('button[type="submit"]');
		var original = $btn.text();

		if (!ZR.nonce || !ZR.ajaxUrl) {
			return;
		}

		$btn.prop('disabled', true).text(ZR.i18n && ZR.i18n.redeeming ? ZR.i18n.redeeming : 'Redeeming...');
		$msg.removeClass('zr-error zr-success').text('');

		$.post(
			ZR.ajaxUrl,
			{
				action: 'zeko_rewards_redeem',
				nonce: ZR.nonce,
				points: points
			},
			function (response) {
				$btn.prop('disabled', false).text(original);

				if (response && response.success) {
					$msg.addClass('zr-success').text(response.data.message);
				} else if (response && response.data && response.data.message) {
					$msg.addClass('zr-error').text(response.data.message);
				} else {
					$msg.addClass('zr-error').text(ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.');
				}
			}
		).fail(function () {
			$btn.prop('disabled', false).text(original);
			$msg.addClass('zr-error').text(ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.');
		});
	});

	// Mark all notifications read.
	$(document).on('click', '[data-zr-mark-read]', function () {
		var $btn = $(this);

		if (!ZR.nonce || !ZR.ajaxUrl) {
			return;
		}

		$.post(
			ZR.ajaxUrl,
			{
				action: 'zeko_rewards_mark_read',
				nonce: ZR.nonce
			},
			function (response) {
				if (response && response.success) {
					$btn.closest('.zr-column').find('.zr-notification').removeClass('zr-unread');
					$btn.remove();
				}
			}
		);
	});

	// Copy referral link to clipboard (fallback: select the input).
	$(document).on('click', '[data-zr-copy]', function () {
		var link = $(this).attr('data-zr-copy');
		var $btn = $(this);
		var original = $btn.text();

		if (link && navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(link).then(function () {
				$btn.text(ZR.i18n && ZR.i18n.copied ? ZR.i18n.copied : 'Copied!');
				window.setTimeout(function () { $btn.text(original); }, 1600);
			});
		} else {
			var $input = $btn.closest('.zr-refer-row').find('.zr-refer-link');
			if ($input.length) {
				$input.trigger('focus').trigger('select');
			}
		}
	});

	// Catalog reward request.
	$(document).on('submit', '[data-zr-catalog-redeem]', function (e) {
		e.preventDefault();

		var $form = $(this);
		var $msg = $form.closest('.zr-catalog-item').find('[data-zr-message]');
		var $btn = $form.find('button[type="submit"]');
		var original = $btn.text();

		if (!ZR.nonce || !ZR.ajaxUrl) {
			return;
		}

		$btn.prop('disabled', true).text(ZR.i18n && ZR.i18n.redeeming ? ZR.i18n.redeeming : 'Requesting...');
		$msg.removeClass('zr-error zr-success').text('');

		$.post(
			ZR.ajaxUrl,
			{
				action: 'zeko_rewards_redeem_item',
				nonce: ZR.nonce,
				item_id: $form.attr('data-zr-catalog-redeem')
			},
			function (response) {
				$btn.prop('disabled', false).text(original);

				if (response && response.success) {
					$msg.addClass('zr-success').text(response.data.message);
					$form.find('button[type="submit"]').addClass('zr-btn-disabled').prop('disabled', true);
				} else if (response && response.data && response.data.message) {
					$msg.addClass('zr-error').text(response.data.message);
				} else {
					$msg.addClass('zr-error').text(ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.');
				}
			}
		).fail(function () {
			$btn.prop('disabled', false).text(original);
			$msg.addClass('zr-error').text(ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.');
		});
	});

	// Points ledger history — filter & paginate via AJAX.
	var $historyTable = $('[data-zr-history-table]');
	if ($historyTable.length) {
		var historyPage = 1;

		function zrLoadHistory(page) {
			if (!ZR.nonce || !ZR.ajaxUrl) {
				return;
			}

			var mod = $('[data-zr-history-module]').val() || '';
			var act = $('[data-zr-history-action]').val() || '';
			historyPage = page || 1;

			$historyTable.html('<p class="zr-history-loading">' + (ZR.i18n && ZR.i18n.loading ? ZR.i18n.loading : 'Loading...') + '</p>');

			$.post(
				ZR.ajaxUrl,
				{
					action: 'zeko_rewards_filter_history',
					nonce: ZR.nonce,
					module: mod,
					action_filter: act,
					page: historyPage
				},
				function (response) {
					if (!response || !response.success) {
						$historyTable.html('<p class="zr-empty">' + (ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.') + '</p>');
						return;
					}

					var data = response.data;
					var events = data.events;
					var totalPages = data.pages;
					historyPage = data.page;

					$('[data-zr-history-total]').text(
						data.total + ' ' + (data.total === 1 ? 'event' : 'events')
					);

					if (!events || !events.length) {
						$historyTable.html('<p class="zr-empty">No events found.</p>');
						$('[data-zr-history-pagination]').hide();
						return;
					}

					var html = '<table class="zr-history-table"><thead><tr>' +
						'<th>Date</th><th>Action</th><th>Module</th><th>Points</th><th>Note</th>' +
						'</tr></thead><tbody>';

					$.each(events, function (i, e) {
						var pts = parseInt(e.points, 10);
						var sign = pts > 0 ? '+' : '';
						var cls = pts < 0 ? 'zr-negative' : 'zr-positive';
						var dt = new Date(e.created_at);
						var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
						var dateStr = months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear() + ' ' +
							dt.getHours() + ':' + (dt.getMinutes() < 10 ? '0' : '') + dt.getMinutes() + ' ' + (dt.getHours() >= 12 ? 'PM' : 'AM');

						html += '<tr>' +
							'<td class="zr-history-date">' + dateStr + '</td>' +
							'<td>' + e.action + '</td>' +
							'<td><span class="zr-history-module-badge zr-module-' + e.module + '">' + e.module.charAt(0).toUpperCase() + e.module.slice(1) + '</span></td>' +
							'<td><span class="zr-history-points ' + cls + '">' + sign + pts.toLocaleString() + '</span></td>' +
							'<td>' + (e.note || '') + '</td>' +
							'</tr>';
					});

					html += '</tbody></table>';
					$historyTable.html(html);

					var $pag = $('[data-zr-history-pagination]');
					if (totalPages > 1) {
						$pag.show();
						$('[data-zr-history-page-info]').text('Page ' + historyPage + ' of ' + totalPages);
						$('[data-zr-history-prev]').prop('disabled', historyPage <= 1);
						$('[data-zr-history-next]').prop('disabled', historyPage >= totalPages);
					} else {
						$pag.hide();
					}
				}
			).fail(function () {
				$historyTable.html('<p class="zr-empty">' + (ZR.i18n && ZR.i18n.error ? ZR.i18n.error : 'Something went wrong.') + '</p>');
			});
		}

		$(document).on('click', '[data-zr-history-filter-btn]', function () {
			zrLoadHistory(1);
		});

		$(document).on('click', '[data-zr-history-prev]', function () {
			if (historyPage > 1) {
				zrLoadHistory(historyPage - 1);
			}
		});

		$(document).on('click', '[data-zr-history-next]', function () {
			zrLoadHistory(historyPage + 1);
		});
	}
})(jQuery);
