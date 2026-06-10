/**
 * JavaScript handlers for the Manual Translations for Polylang admin page.
 * Implements AJAX CRUD operations, inline editing, and premium micro-interactions.
 */
jQuery(document).ready(function ($) {
	const dataTable = $('.mtfp-table');
	const translationsList = $('#mtfp-translations-list');
	const addForm = $('#mtfp-add-string-form');

	// --- Checkbox Selection Logic ---

	// Select All Checkbox
	$('#mtfp-select-all').on('change', function () {
		const isChecked = $(this).prop('checked');
		$('.mtfp-row-cb').prop('checked', isChecked);
		updateRowSelectionStyles();
	});

	// Individual Checkbox Changes
	translationsList.on('change', '.mtfp-row-cb', function () {
		const allChecked = $('.mtfp-row-cb:checked').length === $('.mtfp-row-cb').length;
		$('#mtfp-select-all').prop('checked', allChecked);
		updateRowSelectionStyles();
	});

	function updateRowSelectionStyles() {
		$('.mtfp-row').each(function () {
			const row = $(this);
			const cb = row.find('.mtfp-row-cb');
			if (cb.prop('checked')) {
				row.addClass('mtfp-row-selected');
			} else {
				row.removeClass('mtfp-row-selected');
			}
		});
	}

	// --- CRUD Operations via AJAX ---

	// Add New Translation
	addForm.on('submit', function (e) {
		e.preventDefault();

		const sourceVal = $('#mtfp-add-source').val().trim();
		if (!sourceVal) {
			alert(mtfpAdminData.i18n.emptySource);
			return;
		}

		// Gather translation languages
		const translations = {};
		$('.mtfp-lang-val').each(function () {
			const lang = $(this).data('lang');
			translations[lang] = $(this).val();
		});

		const submitBtn = addForm.find('button[type="submit"]');
		const originalHtml = submitBtn.html();
		submitBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + mtfpAdminData.i18n.saving);

		$.ajax({
			url: mtfpAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_save_translation',
				nonce: mtfpAdminData.nonce,
				source: sourceVal,
				translations: translations
			},
			success: function (response) {
				if (response.success) {
					// Clear form fields
					$('#mtfp-add-source').val('');
					$('.mtfp-lang-val').val('');

					// Reload page to show new item with pagination/sorting properly, or inject dynamically
					// For best UX, we will show a quick message and reload to ensure correct pagination
					location.reload();
				} else {
					alert(response.data.message || mtfpAdminData.i18n.error);
				}
			},
			error: function () {
				alert(mtfpAdminData.i18n.error);
			},
			complete: function () {
				submitBtn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	// Delete Individual Translation
	translationsList.on('click', '.mtfp-delete-row', function () {
		const btn = $(this);
		const row = btn.closest('tr');
		const hash = row.data('hash');

		if (!confirm(mtfpAdminData.i18n.confirmDel)) {
			return;
		}

		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

		$.ajax({
			url: mtfpAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_delete_translation',
				nonce: mtfpAdminData.nonce,
				hash: hash
			},
			success: function (response) {
				if (response.success) {
					row.fadeOut(300, function () {
						row.remove();
						// If no rows remain, show empty state
						if (translationsList.find('.mtfp-row').length === 0) {
							location.reload();
						}
					});
				} else {
					alert(response.data.message || mtfpAdminData.i18n.error);
					btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
				}
			},
			error: function () {
				alert(mtfpAdminData.i18n.error);
				btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
			}
		});
	});

	// Bulk Delete Action
	$('#mtfp-apply-bulk').on('click', function () {
		const action = $('#mtfp-bulk-action').val();
		if (action !== 'delete') {
			return;
		}

		const selectedCbs = $('.mtfp-row-cb:checked');
		if (selectedCbs.length === 0) {
			alert(mtfpAdminData.i18n.noSelection);
			return;
		}

		if (!confirm(mtfpAdminData.i18n.confirmBulk)) {
			return;
		}

		const hashes = [];
		selectedCbs.each(function () {
			hashes.push($(this).val());
		});

		const applyBtn = $(this);
		applyBtn.prop('disabled', true).text(mtfpAdminData.i18n.saving);

		$.ajax({
			url: mtfpAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_bulk_delete',
				nonce: mtfpAdminData.nonce,
				hashes: hashes
			},
			success: function (response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data.message || mtfpAdminData.i18n.error);
					applyBtn.prop('disabled', false).text('Apply');
				}
			},
			error: function () {
				alert(mtfpAdminData.i18n.error);
				applyBtn.prop('disabled', false).text('Apply');
			}
		});
	});

	// --- Inline Editing for Cell Translations ---

	translationsList.on('click', '.mtfp-cell-editable', function (e) {
		// Prevent editing multiple cells simultaneously
		if ($(this).find('input').length > 0) {
			return;
		}

		const cell = $(this);
		const originalText = cell.data('value');
		const lang = cell.data('lang');
		const row = cell.closest('tr');
		const hash = row.data('hash');
		const sourceStr = row.find('.mtfp-cell-source').data('value');

		// Create input field
		const input = $('<input type="text" class="mtfp-inline-input" />').val(originalText);
		cell.html(input);
		input.focus();

		// Handle inline saving
		function saveInline() {
			const newVal = input.val().trim();

			// If no change, restore original value
			if (newVal === originalText) {
				restoreCell(originalText);
				return;
			}

			// Show loader in cell
			cell.html('<span class="dashicons dashicons-update spin"></span> ' + mtfpAdminData.i18n.saving);

			// Gather translations for this row (include existing unchanged cells)
			const rowTranslations = {};
			row.find('.mtfp-cell-editable').each(function () {
				const c = $(this);
				const cLang = c.data('lang');
				if (cLang === lang) {
					rowTranslations[cLang] = newVal;
				} else {
					rowTranslations[cLang] = c.data('value');
				}
			});

			$.ajax({
				url: mtfpAdminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mtfp_save_translation',
					nonce: mtfpAdminData.nonce,
					source: sourceStr,
					translations: rowTranslations
				},
				success: function (response) {
					if (response.success) {
						cell.data('value', newVal);
						restoreCell(newVal);
						
						// Premium Success Flash Animation
						cell.css('background-color', 'rgba(16, 185, 129, 0.2)');
						setTimeout(function () {
							cell.css('background-color', '');
						}, 600);
					} else {
						alert(response.data.message || mtfpAdminData.i18n.error);
						restoreCell(originalText);
					}
				},
				error: function () {
					alert(mtfpAdminData.i18n.error);
					restoreCell(originalText);
				}
			});
		}

		function restoreCell(text) {
			cell.html('<span class="mtfp-editable-text">' + escapeHtml(text) + '</span><span class="dashicons dashicons-edit mtfp-edit-indicator"></span>');
		}

		// Save on blur or enter key
		input.on('blur', function () {
			saveInline();
		});

		input.on('keydown', function (keyEvent) {
			if (keyEvent.which === 13) { // Enter key
				input.off('blur'); // Prevent double triggers
				saveInline();
			} else if (keyEvent.which === 27) { // Escape key
				input.off('blur');
				restoreCell(originalText);
			}
		});
	});

	// Helper to escape HTML tags
	function escapeHtml(string) {
		return String(string).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Dynamic spins style
	const spinStyle = `
		@keyframes mtfp-spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		.spin {
			animation: mtfp-spin 1.5s linear infinite;
			display: inline-block;
		}
	`;
	$('<style>').text(spinStyle).appendTo('head');
});
