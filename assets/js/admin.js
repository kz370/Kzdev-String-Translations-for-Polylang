/**
 * JavaScript handlers for the Manual Translations for Polylang admin page.
 * Implements a reactive client-side rendering engine with live search,
 * custom pagination, and AJAX-driven CRUD operations without page refreshes.
 */
jQuery(document).ready(function ($) {
	// Automatically blur buttons when clicked to prevent persistent focus styling
	$(document).on('click', '.mtfp-page-title-row button, .mtfp-page-title-row .page-title-action, #mtfp-ai-translation-helper button, .term-translation-helper-wrap button, .button.mtfp-btn-primary, .button.mtfp-btn-secondary', function () {
		$(this).trigger('blur');
	});

	const translationsList = $('#mtfp-translations-list');
	const bulkActionSelect = $('#mtfp-bulk-action');
	const applyBulkBtn = $('#mtfp-apply-bulk');
	const selectAllCheckbox = $('#mtfp-select-all');

	// --- Reactive UI State ---
	let allItems = manualTranslationsForPolylangAdminData.translations || [];
	let currentPage = 1;
	let perPage = parseInt($('#mtfp-per-page').val()) || 20;
	let searchQuery = '';
	let newRowActive = false;

	// --- Render Engine ---

	function renderTable() {
		if (translationsList.length === 0) {
			return;
		}
		// Filter items based on search query
		const query = searchQuery.toLowerCase().trim();
		const filteredItems = allItems.filter(function (item) {
			if (item.source.toLowerCase().includes(query)) {
				return true;
			}
			for (const lang in item.translations) {
				if (item.translations[lang].toLowerCase().includes(query)) {
					return true;
				}
			}
			return false;
		});

		const totalItems = filteredItems.length;
		const totalPages = Math.ceil(totalItems / perPage);

		// Adjust current page if out of bounds
		if (currentPage > totalPages) {
			currentPage = Math.max(1, totalPages);
		}

		// Calculate pagination slice
		const start = (currentPage - 1) * perPage;
		const end = start + perPage;
		const pageItems = filteredItems.slice(start, end);

		// 1. Render Table Body
		if (totalItems === 0) {
			const colSpan = manualTranslationsForPolylangAdminData.languages.length + 3;
			translationsList.html(`
				<tr class="mtfp-empty-row">
					<td colspan="${colSpan}">
						<div class="mtfp-empty-state">
							<span class="dashicons dashicons-editor-help"></span>
							<p>${allItems.length === 0 ? 'No translation strings found.' : 'No matching translation strings found.'}</p>
						</div>
					</td>
				</tr>
			`);
			$('#mtfp-table-info').text('');
			$('#mtfp-pagination').html('');
			selectAllCheckbox.prop('checked', false);
			return;
		}

		let tbodyHtml = '';
		pageItems.forEach(function (row) {
			let langCells = '';
			manualTranslationsForPolylangAdminData.languages.forEach(function (lang) {
				const val = row.translations[lang.slug] || '';
				const aiSettings = manualTranslationsForPolylangAdminData.aiSettings || { provider: 'none' };
				let aiButton = '';
				if (!val && aiSettings.provider !== 'none') {
					aiButton = `
						<button type="button" class="mtfp-cell-ai-translate" data-hash="${row.hash}" data-lang="${lang.slug}" title="Translate with AI">
							<span class="dashicons dashicons-admin-customizer"></span>
						</button>
					`;
				}
				langCells += `
					<td class="mtfp-cell-editable" data-lang="${lang.slug}" data-value="${escapeHtml(val)}">
						<span class="mtfp-editable-text">${escapeHtml(val)}</span>
						${aiButton}
						<span class="dashicons dashicons-edit mtfp-edit-indicator"></span>
					</td>
				`;
			});

			tbodyHtml += `
				<tr data-hash="${row.hash}" class="mtfp-row">
					<td>
						<input type="checkbox" name="mtfp_selected[]" class="mtfp-row-cb" value="${row.hash}" />
					</td>
					<td class="mtfp-cell-source" data-value="${escapeHtml(row.source)}">
						<strong class="mtfp-source-text">${escapeHtml(row.source)}</strong>
					</td>
					${langCells}
					<td class="mtfp-cell-actions">
						<button type="button" class="mtfp-btn-icon mtfp-delete-row" title="Delete">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</td>
				</tr>
			`;
		});

		translationsList.html(tbodyHtml);

		// Prepend new row if it is active and not saved yet
		if (newRowActive && $('#mtfp-row-new').length) {
			translationsList.prepend($('#mtfp-row-new'));
		}

		// Update checkboxes selection styling and state
		updateRowSelectionStyles();
		updateSelectAllCheckboxState();

		// 2. Render Info text: "Showing X to Y of Z entries"
		const showingStart = totalItems === 0 ? 0 : start + 1;
		const showingEnd = Math.min(end, totalItems);
		$('#mtfp-table-info').text(`Showing ${showingStart} to ${showingEnd} of ${totalItems} entries`);

		// 3. Render Pagination Buttons
		let paginationHtml = '';
		if (totalPages > 1) {
			// Prev Button
			paginationHtml += `<a href="#" class="mtfp-page-link prev ${currentPage === 1 ? 'disabled' : ''}" data-page="${currentPage - 1}">&laquo;</a>`;

			// Page numbers
			for (let i = 1; i <= totalPages; i++) {
				if (i === currentPage) {
					paginationHtml += `<span class="current">${i}</span>`;
				} else {
					paginationHtml += `<a href="#" class="mtfp-page-link" data-page="${i}">${i}</a>`;
				}
			}

			// Next Button
			paginationHtml += `<a href="#" class="mtfp-page-link next ${currentPage === totalPages ? 'disabled' : ''}" data-page="${currentPage + 1}">&raquo;</a>`;
		}
		$('#mtfp-pagination').html(paginationHtml);
	}

	// --- Checkbox Selection Handlers ---

	// Select All Checkbox Toggle
	selectAllCheckbox.on('change', function () {
		const isChecked = $(this).prop('checked');
		$('.mtfp-row-cb').prop('checked', isChecked);
		updateRowSelectionStyles();
	});

	// Individual Checkbox Changes
	translationsList.on('change', '.mtfp-row-cb', function () {
		updateRowSelectionStyles();
		updateSelectAllCheckboxState();
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

	function updateSelectAllCheckboxState() {
		const visibleCbs = $('.mtfp-row-cb');
		if (visibleCbs.length === 0) {
			selectAllCheckbox.prop('checked', false);
			return;
		}
		const allChecked = visibleCbs.filter(':checked').length === visibleCbs.length;
		selectAllCheckbox.prop('checked', allChecked);
	}

	// --- Live Filter & Search Listeners ---

	// Live Search Input handler
	$('#mtfp-search').on('input', function () {
		searchQuery = $(this).val();
		currentPage = 1;
		renderTable();
	});

	// Per-Page Selector dropdown
	$('#mtfp-per-page').on('change', function () {
		perPage = parseInt($(this).val()) || 20;
		currentPage = 1;
		renderTable();
	});

	// Pagination Link Click handlers
	$('#mtfp-pagination').on('click', '.mtfp-page-link', function (e) {
		e.preventDefault();
		if ($(this).hasClass('disabled')) {
			return;
		}
		currentPage = parseInt($(this).data('page'));
		renderTable();
	});

	// --- Inline Row Insertion Logic ---

	$('.mtfp-trigger-add-row').on('click', function () {
		if ($('#mtfp-row-new').length) {
			$('#mtfp-row-new .mtfp-new-source-input').focus();
			return;
		}

		newRowActive = true;
		$('.mtfp-empty-row').hide();

		const languages = manualTranslationsForPolylangAdminData.languages;
		let langCells = '';
		languages.forEach(function (lang) {
			langCells += `
				<td class="mtfp-cell-lang-new" data-lang="${lang.slug}">
					<input type="text" class="mtfp-new-lang-input mtfp-input" placeholder="Translation value..." style="width: 100%;" />
				</td>
			`;
		});

		const newRowHtml = `
			<tr id="mtfp-row-new" class="mtfp-row mtfp-row-new">
				<td class="mtfp-col-cb">
					<input type="checkbox" disabled />
				</td>
				<td class="mtfp-cell-source-new">
					<input type="text" class="mtfp-new-source-input mtfp-input" placeholder="e.g. Subtotal:" style="width: 100%; font-weight: 600;" />
				</td>
				${langCells}
				<td class="mtfp-cell-actions" style="white-space: nowrap;">
					<button type="button" class="mtfp-btn-icon mtfp-save-new-row" title="Save">
						<span class="dashicons dashicons-saved" style="color: var(--mtfp-success);"></span>
					</button>
					<button type="button" class="mtfp-btn-icon mtfp-cancel-new-row" title="Cancel">
						<span class="dashicons dashicons-no-alt" style="color: var(--mtfp-danger);"></span>
					</button>
				</td>
			</tr>
		`;

		translationsList.prepend(newRowHtml);

		const newRow = $('#mtfp-row-new');
		newRow.hide().fadeIn(250);
		newRow.find('.mtfp-new-source-input').focus();
	});

	// Cancel Row Creation
	translationsList.on('click', '.mtfp-cancel-new-row', function () {
		const newRow = $('#mtfp-row-new');
		newRow.fadeOut(200, function () {
			newRow.remove();
			newRowActive = false;
			renderTable();
		});
	});

	// Save Row Creation
	function saveNewRow() {
		const newRow = $('#mtfp-row-new');
		if (newRow.length === 0) {
			return;
		}

		const sourceVal = newRow.find('.mtfp-new-source-input').val().trim();
		if (!sourceVal) {
			alert(manualTranslationsForPolylangAdminData.i18n.emptySource);
			newRow.find('.mtfp-new-source-input').focus();
			return;
		}

		// Check if source already exists
		const existingMatch = allItems.find(i => i.source.toLowerCase() === sourceVal.toLowerCase());
		if (existingMatch) {
			alert("This source string already exists in the manual translations list.");
			newRow.find('.mtfp-new-source-input').focus();
			return;
		}

		newRow.find('input').prop('disabled', true);
		newRow.find('button').prop('disabled', true);

		const translations = {};
		newRow.find('.mtfp-cell-lang-new').each(function () {
			const cell = $(this);
			const lang = cell.data('lang');
			const val = cell.find('.mtfp-new-lang-input').val().trim();
			translations[lang] = val;
		});

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_save_translation',
				nonce: manualTranslationsForPolylangAdminData.nonce,
				source: sourceVal,
				translations: translations
			},
			success: function (response) {
				if (response.success) {
					// Add to local dataset reactively
					allItems.unshift({
						hash: response.data.hash,
						source: response.data.row.source,
						translations: response.data.row.translations
					});
					
					newRowActive = false;
					newRow.remove();
					
					// Go back to page 1 and render table
					currentPage = 1;
					renderTable();

					// Find row and flash success
					setTimeout(function () {
						const addedRow = translationsList.find(`tr[data-hash="${response.data.hash}"]`);
						addedRow.css('background-color', 'rgba(16, 185, 129, 0.2)');
						setTimeout(function () {
							addedRow.css('background-color', '');
						}, 600);
					}, 50);
				} else {
					alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
					newRow.find('input').prop('disabled', false);
					newRow.find('button').prop('disabled', false);
					newRow.find('.mtfp-new-source-input').focus();
				}
			},
			error: function () {
				alert(manualTranslationsForPolylangAdminData.i18n.error);
				newRow.find('input').prop('disabled', false);
				newRow.find('button').prop('disabled', false);
			}
		});
	}

	translationsList.on('click', '.mtfp-save-new-row', function () {
		saveNewRow();
	});

	translationsList.on('keydown', '#mtfp-row-new input', function (e) {
		if (e.which === 13) {
			saveNewRow();
		} else if (e.which === 27) {
			$('.mtfp-cancel-new-row').trigger('click');
		}
	});

	// --- AJAX CRUD Actions ---

	// Delete Individual Translation
	translationsList.on('click', '.mtfp-delete-row', function () {
		const btn = $(this);
		const row = btn.closest('tr');
		const hash = row.data('hash');

		if (!confirm(manualTranslationsForPolylangAdminData.i18n.confirmDel)) {
			return;
		}

		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_delete_translation',
				nonce: manualTranslationsForPolylangAdminData.nonce,
				hash: hash
			},
			success: function (response) {
				if (response.success) {
					row.fadeOut(250, function () {
						// Remove from local array
						allItems = allItems.filter(i => i.hash !== hash);
						renderTable();
					});
				} else {
					alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
					btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
				}
			},
			error: function () {
				alert(manualTranslationsForPolylangAdminData.i18n.error);
				btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
			}
		});
	});

	// Bulk Delete Action
	applyBulkBtn.on('click', function () {
		const action = bulkActionSelect.val();
		if (action !== 'delete') {
			return;
		}

		const selectedCbs = $('.mtfp-row-cb:checked');
		if (selectedCbs.length === 0) {
			alert(manualTranslationsForPolylangAdminData.i18n.noSelection);
			return;
		}

		if (!confirm(manualTranslationsForPolylangAdminData.i18n.confirmBulk)) {
			return;
		}

		const hashes = [];
		selectedCbs.each(function () {
			hashes.push($(this).val());
		});

		applyBulkBtn.prop('disabled', true).text(manualTranslationsForPolylangAdminData.i18n.saving);

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_bulk_delete',
				nonce: manualTranslationsForPolylangAdminData.nonce,
				hashes: hashes
			},
			success: function (response) {
				if (response.success) {
					// Remove from local dataset reactively
					allItems = allItems.filter(i => !hashes.includes(i.hash));
					renderTable();
				} else {
					alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
				}
			},
			error: function () {
				alert(manualTranslationsForPolylangAdminData.i18n.error);
			},
			complete: function () {
				applyBulkBtn.prop('disabled', false).text('Apply');
				selectAllCheckbox.prop('checked', false);
			}
		});
	});

	// --- Inline Editing for Cell Translations ---

	translationsList.on('click', '.mtfp-cell-editable', function (e) {
		if ($(this).find('input').length > 0) {
			return;
		}

		const cell = $(this);
		const originalText = cell.data('value');
		const lang = cell.data('lang');
		const row = cell.closest('tr');
		const hash = row.data('hash');
		
		// Find matching object in local memory
		const inMemoryRow = allItems.find(i => i.hash === hash);
		if (!inMemoryRow) {
			return;
		}

		const input = $('<input type="text" class="mtfp-inline-input" />').val(originalText);
		cell.html(input);
		input.focus();

		function saveInline() {
			const newVal = input.val().trim();

			if (newVal === originalText) {
				restoreCell(originalText);
				return;
			}

			cell.html('<span class="dashicons dashicons-update spin"></span> ' + manualTranslationsForPolylangAdminData.i18n.saving);

			// Gather sibling values from inMemoryRow to preserve other columns
			const rowTranslations = $.extend({}, inMemoryRow.translations);
			rowTranslations[lang] = newVal;

			$.ajax({
				url: manualTranslationsForPolylangAdminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mtfp_save_translation',
					nonce: manualTranslationsForPolylangAdminData.nonce,
					source: inMemoryRow.source,
					translations: rowTranslations
				},
				success: function (response) {
					if (response.success) {
						// Save value locally
						inMemoryRow.translations[lang] = newVal;
						cell.data('value', newVal);
						restoreCell(newVal);

						// Flash Cell success
						cell.css('background-color', 'rgba(16, 185, 129, 0.2)');
						setTimeout(function () {
							cell.css('background-color', '');
						}, 600);
					} else {
						alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
						restoreCell(originalText);
					}
				},
				error: function () {
					alert(manualTranslationsForPolylangAdminData.i18n.error);
					restoreCell(originalText);
				}
			});
		}

		function restoreCell(text) {
			cell.html(`<span class="mtfp-editable-text">${escapeHtml(text)}</span><span class="dashicons dashicons-edit mtfp-edit-indicator"></span>`);
		}

		input.on('blur', function () {
			saveInline();
		});

		input.on('keydown', function (keyEvent) {
			if (keyEvent.which === 13) {
				input.off('blur');
				saveInline();
			} else if (keyEvent.which === 27) {
				input.off('blur');
				restoreCell(originalText);
			}
		});
	});

	// --- Scan Modal & Theme Scanner Logic ---

	function showScanModal() {
		$('#mtfp-scan-modal').addClass('mtfp-modal-active');
	}

	function hideScanModal() {
		$('#mtfp-scan-modal').removeClass('mtfp-modal-active');
	}

	// Show scan modal
	$('.mtfp-trigger-scan-modal').on('click', function () {
		showScanModal();
	});

	// Hide scan modal
	$('#mtfp-scan-modal-close, #mtfp-scan-modal').on('click', function (e) {
		if (e.target === this) {
			hideScanModal();
		}
	});

	// Toggle specific plugin select input inside modal
	$('#mtfp-modal-scan-type').on('change', function () {
		if ($(this).val() === 'specific-plugin') {
			$('#mtfp-modal-specific-plugin-group').slideDown(200);
		} else {
			$('#mtfp-modal-specific-plugin-group').slideUp(200);
		}
	});

	// Start Scan from modal
	$('#mtfp-modal-start-scan-btn').on('click', function () {
		const modalScanType = $('#mtfp-modal-scan-type').val();
		let targetVal = modalScanType;

		if (modalScanType === 'specific-plugin') {
			const pluginFolder = $('#mtfp-modal-specific-plugin').val();
			if (!pluginFolder) {
				alert("Please select a plugin to scan.");
				return;
			}
			targetVal = 'plugin:' + pluginFolder;
		}

		// Close modal
		hideScanModal();

		// Trigger AJAX scan
		const scanTriggerBtn = $('.mtfp-trigger-scan-modal');
		const originalHtml = scanTriggerBtn.html();

		scanTriggerBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Scanning...');

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_scan_theme',
				nonce: manualTranslationsForPolylangAdminData.nonce,
				target: targetVal
			},
			success: function (response) {
				if (response.success) {
					renderScanResults(response.data.strings);
				} else {
					alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
				}
			},
			error: function () {
				alert(manualTranslationsForPolylangAdminData.i18n.error);
			},
			complete: function () {
				scanTriggerBtn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	function renderScanResults(strings) {
		const container = $('#mtfp-scan-results-container');
		if (strings.length === 0) {
			container.html(`
				<div class="notice notice-info notice-alt" style="margin-bottom: 24px;">
					<p>Scan complete. No new untranslated strings were found in the selected scan target.</p>
				</div>
			`).show();
			setTimeout(function () {
				container.fadeOut(300, function () {
					container.html('');
				});
			}, 4000);
			return;
		}

		let listHtml = '';
		strings.forEach(function (str, idx) {
			listHtml += `
				<div class="mtfp-scan-item" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--mtfp-border); font-size: 13px;">
					<input type="checkbox" class="mtfp-scan-cb" value="${escapeHtml(str)}" id="mtfp-scan-str-${idx}" />
					<label for="mtfp-scan-str-${idx}" style="cursor: pointer; font-weight: 500; word-break: break-all;">${escapeHtml(str)}</label>
				</div>
			`;
		});

		container.html(`
			<div class="mtfp-card mtfp-card-scan-results" style="border-left: 4px solid var(--mtfp-primary); margin-bottom: 24px; padding: 24px;">
				<h2 style="font-size: 18px; font-weight: 600; margin: 0 0 16px 0; padding-bottom: 12px; border-bottom: 1px solid var(--mtfp-border); color: var(--mtfp-text);">Scan Results – Found ${strings.length} Untranslated Strings</h2>
				<p class="description" style="margin-bottom: 16px; font-size: 13px; color: var(--mtfp-text-muted);">Select the strings you want to import into your manual translations list.</p>
				
				<div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
					<button type="button" class="button mtfp-btn-secondary" id="mtfp-scan-select-all">Select All</button>
					<button type="button" class="button mtfp-btn-secondary" id="mtfp-scan-deselect-all">Deselect All</button>
				</div>

				<div class="mtfp-scan-list" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--mtfp-border); border-radius: var(--mtfp-radius-md); background: #fafafa; margin-bottom: 20px;">
					${listHtml}
				</div>

				<div style="display: flex; gap: 12px;">
					<button type="button" class="button mtfp-btn-primary" id="mtfp-import-selected-scan">Import Selected</button>
					<button type="button" class="button mtfp-btn-secondary" id="mtfp-dismiss-scan">Dismiss</button>
				</div>
			</div>
		`).hide().fadeIn(300);

		// Event handlers for scan results card
		$('#mtfp-scan-select-all').on('click', function () {
			$('.mtfp-scan-cb').prop('checked', true);
		});

		$('#mtfp-scan-deselect-all').on('click', function () {
			$('.mtfp-scan-cb').prop('checked', false);
		});

		$('#mtfp-dismiss-scan').on('click', function () {
			container.fadeOut(300, function () {
				container.html('');
			});
		});

		$('#mtfp-import-selected-scan').on('click', function () {
			const selectedCbs = $('.mtfp-scan-cb:checked');
			if (selectedCbs.length === 0) {
				alert('No strings selected.');
				return;
			}

			const selectedStrings = [];
			selectedCbs.each(function () {
				selectedStrings.push($(this).val());
			});

			const importBtn = $(this);
			importBtn.prop('disabled', true).text('Importing...');

			$.ajax({
				url: manualTranslationsForPolylangAdminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mtfp_import_scanned',
					nonce: manualTranslationsForPolylangAdminData.nonce,
					strings: selectedStrings
				},
				success: function (response) {
					if (response.success) {
						// Inject reactively into allItems
						response.data.imported.forEach(function (item) {
							allItems.unshift(item);
						});

						// Update table
						currentPage = 1;
						renderTable();

						// Dismiss scan card
						container.fadeOut(300, function () {
							container.html('');
						});

						// Display message
						alert(response.data.message);
					} else {
						alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
						importBtn.prop('disabled', false).text('Import Selected');
					}
				},
				error: function () {
					alert(manualTranslationsForPolylangAdminData.i18n.error);
					importBtn.prop('disabled', false).text('Import Selected');
				}
			});
		});
	}

	// --- AI Auto Translation handlers ---

	// Toggle OpenAI fields visibility based on selected provider
	$('#mtfp-ai-provider').on('change', function () {
		if ($(this).val() === 'openai') {
			$('.mtfp-ai-openai-fields').slideDown(200);
		} else {
			$('.mtfp-ai-openai-fields').slideUp(200);
		}
	});

	// AI Settings Form Submit handler
	$('#mtfp-ai-settings-form').on('submit', function (e) {
		e.preventDefault();
		const form = $(this);
		const btn = form.find('button[type="submit"]');
		const originalHtml = btn.html();

		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Saving...');

		const provider = $('#mtfp-ai-provider').val();
		const openaiUrl = $('#mtfp-openai-url').val();
		const openaiKey = $('#mtfp-openai-key').val();
		const openaiModel = $('#mtfp-openai-model').val();

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_save_ai_settings',
				nonce: manualTranslationsForPolylangAdminData.nonce,
				provider: provider,
				openai_url: openaiUrl,
				openai_key: openaiKey,
				openai_model: openaiModel
			},
			success: function (response) {
				if (response.success) {
					// Update localized config state
					if (!manualTranslationsForPolylangAdminData.aiSettings) {
						manualTranslationsForPolylangAdminData.aiSettings = {};
					}
					manualTranslationsForPolylangAdminData.aiSettings.provider = provider;
					
					// Toggle header Auto Translate button visibility
					if (provider === 'none') {
						$('.mtfp-trigger-auto-translate').fadeOut(200);
					} else {
						$('.mtfp-trigger-auto-translate').fadeIn(200);
					}

					alert(response.data.message);
					renderTable();
				} else {
					alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
				}
			},
			error: function () {
				alert(manualTranslationsForPolylangAdminData.i18n.error);
			},
			complete: function () {
				btn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	function isBrowserTranslationSupported() {
		return (typeof window.Translator !== 'undefined') ||
		       (typeof window.translation !== 'undefined' && typeof window.translation.createTranslator === 'function') ||
			   (typeof window.ai !== 'undefined' && typeof window.ai.translator !== 'undefined');
	}

	// Browser Translation API Helper
	async function translateWithBrowser(sourceText, targetLangCode) {
		const sourceLang = 'en';
		const targetLang = targetLangCode;

		// 1. Try window.Translator (2026 Chrome Spec standard)
		if (typeof window.Translator !== 'undefined' && typeof window.Translator.create === 'function') {
			if (typeof window.Translator.canTranslate === 'function') {
				const canTranslate = await window.Translator.canTranslate({ sourceLanguage: sourceLang, targetLanguage: targetLang });
				if (canTranslate === 'no') {
					throw new Error(`Browser Translator API cannot translate from '${sourceLang}' to '${targetLang}'.`);
				}
			}
			const translator = await window.Translator.create({ sourceLanguage: sourceLang, targetLanguage: targetLang });
			return await translator.translate(sourceText);
		}

		// 2. Try window.translation API (Chrome experimental translation API)
		if (typeof window.translation !== 'undefined' && typeof window.translation.createTranslator === 'function') {
			const canTranslate = await window.translation.canTranslate({ sourceLanguage: sourceLang, targetLanguage: targetLang });
			if (canTranslate === 'no') {
				throw new Error(`Browser API cannot translate from '${sourceLang}' to '${targetLang}'.`);
			}
			const translator = await window.translation.createTranslator({ sourceLanguage: sourceLang, targetLanguage: targetLang });
			return await translator.translate(sourceText);
		}

		// 3. Try window.ai.translator API (Older Chrome experimental namespace)
		if (typeof window.ai !== 'undefined' && typeof window.ai.translator !== 'undefined') {
			if (typeof window.ai.translator.canTranslate === 'function') {
				const canTranslate = await window.ai.translator.canTranslate({ sourceLanguage: sourceLang, targetLanguage: targetLang });
				if (canTranslate === 'no') {
					throw new Error(`Browser AI API cannot translate from '${sourceLang}' to '${targetLang}'.`);
				}
			} else if (typeof window.ai.translator.capabilities === 'function') {
				const capabilities = await window.ai.translator.capabilities();
				const canTranslate = capabilities.languagePairAvailable(sourceLang, targetLang);
				if (canTranslate === 'no') {
					throw new Error(`Browser AI API cannot translate from '${sourceLang}' to '${targetLang}'.`);
				}
			}
			const translator = await window.ai.translator.create({ sourceLanguage: sourceLang, targetLanguage: targetLang });
			return await translator.translate(sourceText);
		}

		throw new Error("Built-in Translation API is not supported in this browser or is disabled. Make sure you enable 'Translation API' and relaunch your browser.");
	}

	// Perform AI Translation orchestration
	function performAiTranslation(source, lang) {
		// If target language is English, source text is already in English.
		// Simply return the source string as the translation.
		if (lang === 'en' || lang.split('-')[0] === 'en' || lang.split('_')[0] === 'en') {
			return Promise.resolve(source);
		}

		const provider = manualTranslationsForPolylangAdminData.aiSettings ? manualTranslationsForPolylangAdminData.aiSettings.provider : 'none';
		
		if (provider === 'browser') {
			return translateWithBrowser(source, lang);
		} else if (provider === 'openai') {
			return new Promise(function (resolve, reject) {
				$.ajax({
					url: manualTranslationsForPolylangAdminData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'mtfp_ai_translate',
						nonce: manualTranslationsForPolylangAdminData.nonce,
						source: source,
						target_lang: lang
					},
					success: function (response) {
						if (response.success) {
							resolve(response.data.translation);
						} else {
							reject(new Error(response.data.message || "Unknown error"));
						}
					},
					error: function () {
						reject(new Error("Network or server error"));
					}
				});
			});
		} else {
			return Promise.reject(new Error("No AI translation provider configured."));
		}
	}

	// Individual AI translation click trigger
	translationsList.on('click', '.mtfp-cell-ai-translate', function (e) {
		e.stopPropagation();

		const provider = manualTranslationsForPolylangAdminData.aiSettings ? manualTranslationsForPolylangAdminData.aiSettings.provider : 'none';
		if (provider === 'browser' && !isBrowserTranslationSupported()) {
			alert("The browser built-in AI Translation API is not supported or is disabled in your browser.\n\nTo use it, please enable the 'Translation API' flag in chrome://flags or edge://flags, or switch to the OpenAI-Compatible API provider in the settings card below.");
			return;
		}

		const btn = $(this);
		const cell = btn.closest('td');
		const row = btn.closest('tr');
		const hash = row.data('hash');
		const lang = cell.data('lang');
		const source = row.find('.mtfp-cell-source').data('value');

		const inMemoryRow = allItems.find(i => i.hash === hash);
		if (!inMemoryRow) {
			return;
		}

		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

		performAiTranslation(source, lang)
			.then(function (translation) {
				const rowTranslations = $.extend({}, inMemoryRow.translations);
				rowTranslations[lang] = translation;

				$.ajax({
					url: manualTranslationsForPolylangAdminData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'mtfp_save_translation',
						nonce: manualTranslationsForPolylangAdminData.nonce,
						source: inMemoryRow.source,
						translations: rowTranslations
					},
					success: function (response) {
						if (response.success) {
							inMemoryRow.translations[lang] = translation;
							cell.data('value', translation);
							cell.html(`<span class="mtfp-editable-text">${escapeHtml(translation)}</span><span class="dashicons dashicons-edit mtfp-edit-indicator"></span>`);
							
							cell.css('background-color', 'rgba(16, 185, 129, 0.2)');
							setTimeout(function () {
								cell.css('background-color', '');
							}, 600);
						} else {
							alert(response.data.message || manualTranslationsForPolylangAdminData.i18n.error);
							btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span>');
						}
					},
					error: function () {
						alert(manualTranslationsForPolylangAdminData.i18n.error);
						btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span>');
					}
				});
			})
			.catch(function (error) {
				alert("AI Translation failed: " + error.message);
				btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-customizer"></span>');
			});
	});

	// Bulk AI Auto Translate click trigger
	$('.mtfp-trigger-auto-translate').on('click', function () {
		const provider = manualTranslationsForPolylangAdminData.aiSettings ? manualTranslationsForPolylangAdminData.aiSettings.provider : 'none';
		if (provider === 'none') {
			alert("Please configure an AI provider in the settings card below first.");
			return;
		}
		if (provider === 'browser' && !isBrowserTranslationSupported()) {
			alert("The browser built-in AI Translation API is not supported or is disabled in your browser.\n\nTo use it, please enable the 'Translation API' flag in chrome://flags or edge://flags, or switch to the OpenAI-Compatible API provider in the settings card below.");
			return;
		}

		const emptyCells = [];
		const selectedRowHashes = [];
		
		$('.mtfp-row-cb:checked').each(function () {
			selectedRowHashes.push($(this).val());
		});

		const totalItems = allItems.length;
		const query = searchQuery.toLowerCase().trim();
		const filteredItems = allItems.filter(function (item) {
			if (item.source.toLowerCase().includes(query)) return true;
			for (const lang in item.translations) {
				if (item.translations[lang].toLowerCase().includes(query)) return true;
			}
			return false;
		});

		const start = (currentPage - 1) * perPage;
		const end = start + perPage;
		const pageItems = filteredItems.slice(start, end);

		const targetRows = selectedRowHashes.length > 0 
			? allItems.filter(i => selectedRowHashes.includes(i.hash))
			: pageItems;

		targetRows.forEach(function (row) {
			manualTranslationsForPolylangAdminData.languages.forEach(function (lang) {
				const val = row.translations[lang.slug] || '';
				if (!val) {
					emptyCells.push({
						hash: row.hash,
						lang: lang.slug,
						source: row.source
					});
				}
			});
		});

		if (emptyCells.length === 0) {
			alert("No empty translation cells found in the current " + (selectedRowHashes.length > 0 ? "selection" : "page") + ".");
			return;
		}

		if (!confirm(`Found ${emptyCells.length} empty translation cells. Do you want to automatically translate them using ${provider === 'browser' ? 'Browser Built-in AI' : 'OpenAI-Compatible API'}?`)) {
			return;
		}

		runBulkAiTranslation(emptyCells);
	});

	// Bulk Translation Executor with floating progress notification
	function runBulkAiTranslation(emptyCells) {
		const total = emptyCells.length;
		let completed = 0;
		let successful = 0;
		let failed = 0;

		let progressOverlay = $('#mtfp-progress-overlay');
		if (progressOverlay.length === 0) {
			$('body').append(`
				<div id="mtfp-progress-overlay" style="position: fixed; bottom: 24px; right: 24px; background: #ffffff; border: 1px solid var(--mtfp-border); border-radius: var(--mtfp-radius-lg); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); padding: 20px; width: 320px; z-index: 999999; font-family: sans-serif; box-sizing: border-box;">
					<h3 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600; color: var(--mtfp-text); display: flex; align-items: center; gap: 8px;">
						<span class="dashicons dashicons-admin-customizer spin" style="color: #8b5cf6;"></span>
						AI Translating Strings...
					</h3>
					<div class="mtfp-progress-bar-container" style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 12px;">
						<div id="mtfp-progress-bar-fill" style="background: #8b5cf6; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
					</div>
					<div id="mtfp-progress-status" style="font-size: 12px; color: var(--mtfp-text-muted); display: flex; justify-content: space-between;">
						<span>0 / ${total} Translated</span>
						<span>0%</span>
					</div>
				</div>
			`);
			progressOverlay = $('#mtfp-progress-overlay');
		} else {
			progressOverlay.find('#mtfp-progress-bar-fill').css('width', '0%');
			progressOverlay.find('#mtfp-progress-status').html(`<span>0 / ${total} Translated</span><span>0%</span>`);
			progressOverlay.fadeIn(200);
		}

		const concurrencyLimit = 3;
		let index = 0;

		function updateProgress() {
			completed++;
			const percent = Math.min(100, Math.round((completed / total) * 100));
			progressOverlay.find('#mtfp-progress-bar-fill').css('width', percent + '%');
			progressOverlay.find('#mtfp-progress-status').html(`<span>${completed} / ${total} Translated (${successful} ok, ${failed} failed)</span><span>${percent}%</span>`);
		}

		function processNext() {
			if (index >= total) {
				if (completed >= total) {
					setTimeout(function () {
						progressOverlay.fadeOut(300, function () {
							alert(`AI Translation complete!\n- Translated successfully: ${successful}\n- Failed: ${failed}`);
						});
					}, 1000);
				}
				return;
			}

			const cellInfo = emptyCells[index++];
			
			const rowDom = translationsList.find(`tr[data-hash="${cellInfo.hash}"]`);
			let cellDom = null;
			if (rowDom.length > 0) {
				cellDom = rowDom.find(`td[data-lang="${cellInfo.lang}"]`);
				cellDom.html('<span class="dashicons dashicons-update spin" style="color: var(--mtfp-primary);"></span>');
			}

			performAiTranslation(cellInfo.source, cellInfo.lang)
				.then(function (translation) {
					const inMemoryRow = allItems.find(i => i.hash === cellInfo.hash);
					if (inMemoryRow) {
						inMemoryRow.translations[cellInfo.lang] = translation;
					}

					const rowTranslations = inMemoryRow ? $.extend({}, inMemoryRow.translations) : {};
					if (inMemoryRow) {
						rowTranslations[cellInfo.lang] = translation;
					}

					return $.ajax({
						url: manualTranslationsForPolylangAdminData.ajaxUrl,
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'mtfp_save_translation',
							nonce: manualTranslationsForPolylangAdminData.nonce,
							source: cellInfo.source,
							translations: rowTranslations
						}
					}).then(function (response) {
						if (response.success) {
							successful++;
							if (cellDom && cellDom.length > 0) {
								cellDom.data('value', translation);
								cellDom.html(`<span class="mtfp-editable-text">${escapeHtml(translation)}</span><span class="dashicons dashicons-edit mtfp-edit-indicator"></span>`);
								cellDom.css('background-color', 'rgba(16, 185, 129, 0.2)');
								setTimeout(function () {
									cellDom.css('background-color', '');
								}, 800);
							}
						} else {
							throw new Error(response.data.message || "Database save failed");
						}
					});
				})
				.catch(function (err) {
					failed++;
					console.error("AI translation error for cell:", cellInfo, err);
					if (cellDom && cellDom.length > 0) {
						const aiButton = `
							<button type="button" class="mtfp-cell-ai-translate" data-hash="${cellInfo.hash}" data-lang="${cellInfo.lang}" title="Translate with AI">
								<span class="dashicons dashicons-admin-customizer"></span>
							</button>
						`;
						cellDom.html(`<span class="mtfp-editable-text"></span>${aiButton}<span class="dashicons dashicons-edit mtfp-edit-indicator"></span>`);
					}
				})
				.then(function () {
					updateProgress();
					processNext();
				});
		}

		for (let i = 0; i < Math.min(total, concurrencyLimit); i++) {
			processNext();
		}
	}

	// --- Helper Utilities ---

	// Escape HTML
	function escapeHtml(string) {
		return String(string).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Spin keyframes loader injection
	const spinStyle = `
		@keyframes mtfp-spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		.spin {
			animation: mtfp-spin 1.5s linear infinite;
			display: inline-block;
		}
		.mtfp-pagination a.disabled {
			pointer-events: none;
			opacity: 0.5;
			background: #f1f5f9;
			color: #94a3b8;
		}
	`;
	$('<style>').text(spinStyle).appendTo('head');

	// --- Post / Page Translation Sidebar Metabox Handler ---
	if (manualTranslationsForPolylangAdminData.postDetails) {
		const postWrapper = $('.mtfp-metabox-wrapper');
		
		function showPostLoading() {
			postWrapper.closest('#mtfp-ai-translation-helper').addClass('mtfp-panel-loading');
		}
		
		function hidePostLoading() {
			postWrapper.closest('#mtfp-ai-translation-helper').removeClass('mtfp-panel-loading');
		}

		postWrapper.on('click', '.mtfp-post-translate-btn, .mtfp-post-retranslate-btn', function (e) {
			e.preventDefault();
			const btn = $(this);
			const targetLang = btn.data('lang');
			const sourceDetails = manualTranslationsForPolylangAdminData.postDetails;
			const provider = manualTranslationsForPolylangAdminData.aiSettings ? manualTranslationsForPolylangAdminData.aiSettings.provider : 'none';

			if (provider === 'browser' && !isBrowserTranslationSupported()) {
				alert("The browser built-in AI Translation API is not supported or is disabled in your browser.\n\nTo use it, please enable the 'Translation API' flag in chrome://flags or edge://flags.");
				return;
			}

			showPostLoading();

			if (provider === 'browser') {
				const titlePromise = sourceDetails.title ? performAiTranslation(sourceDetails.title, targetLang) : Promise.resolve('');
				const contentPromise = sourceDetails.content ? performAiTranslation(sourceDetails.content, targetLang) : Promise.resolve('');
				const excerptPromise = sourceDetails.excerpt ? performAiTranslation(sourceDetails.excerpt, targetLang) : Promise.resolve('');

				Promise.all([titlePromise, contentPromise, excerptPromise])
					.then(function (results) {
						savePostTranslation(sourceDetails.id, targetLang, results[0], results[1], results[2]);
					})
					.catch(function (err) {
						alert("Browser AI Translation failed: " + err.message);
						hidePostLoading();
					});
			} else {
				// openai or none: delegate entirely to PHP
				savePostTranslation(sourceDetails.id, targetLang, '', '', '');
			}
		});

		function savePostTranslation(postId, targetLang, title, content, excerpt) {
			$.ajax({
				url: manualTranslationsForPolylangAdminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mtfp_create_post_translation',
					nonce: manualTranslationsForPolylangAdminData.nonce,
					post_id: postId,
					target_lang: targetLang,
					translated_title: title,
					translated_content: content,
					translated_excerpt: excerpt
				},
				success: function (response) {
					if (response.success) {
						window.location.href = response.data.edit_url;
					} else {
						alert(response.data.message || "An error occurred.");
						hidePostLoading();
					}
				},
				error: function () {
					alert("A server or connection error occurred.");
					hidePostLoading();
				}
			});
		}
	}

	// --- Category / Taxonomy Term Translation Panel Handler ---
	if (manualTranslationsForPolylangAdminData.termDetails) {
		const termContainer = $('.mtfp-term-translation-container');
		
		function showTermLoading() {
			termContainer.addClass('mtfp-panel-loading');
		}
		
		function hideTermLoading() {
			termContainer.removeClass('mtfp-panel-loading');
		}

		termContainer.on('click', '.mtfp-term-translate-btn, .mtfp-term-retranslate-btn', function (e) {
			e.preventDefault();
			const btn = $(this);
			const targetLang = btn.data('lang');
			const sourceDetails = manualTranslationsForPolylangAdminData.termDetails;
			const provider = manualTranslationsForPolylangAdminData.aiSettings ? manualTranslationsForPolylangAdminData.aiSettings.provider : 'none';

			if (provider === 'browser' && !isBrowserTranslationSupported()) {
				alert("The browser built-in AI Translation API is not supported or is disabled in your browser.\n\nTo use it, please enable the 'Translation API' flag in chrome://flags or edge://flags.");
				return;
			}

			showTermLoading();

			if (provider === 'browser') {
				const namePromise = sourceDetails.name ? performAiTranslation(sourceDetails.name, targetLang) : Promise.resolve('');
				const descPromise = sourceDetails.description ? performAiTranslation(sourceDetails.description, targetLang) : Promise.resolve('');

				Promise.all([namePromise, descPromise])
					.then(function (results) {
						saveTermTranslation(sourceDetails.id, targetLang, results[0], results[1]);
					})
					.catch(function (err) {
						alert("Browser AI Translation failed: " + err.message);
						hideTermLoading();
					});
			} else {
				// openai or none: delegate entirely to PHP
				saveTermTranslation(sourceDetails.id, targetLang, '', '');
			}
		});

		function saveTermTranslation(termId, targetLang, name, description) {
			$.ajax({
				url: manualTranslationsForPolylangAdminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'mtfp_create_term_translation',
					nonce: manualTranslationsForPolylangAdminData.nonce,
					term_id: termId,
					target_lang: targetLang,
					translated_name: name,
					translated_description: description
				},
				success: function (response) {
					if (response.success) {
						window.location.href = response.data.edit_url;
					} else {
						alert(response.data.message || "An error occurred.");
						hideTermLoading();
					}
				},
				error: function () {
					alert("A server or connection error occurred.");
					hideTermLoading();
				}
			});
		}
	}

	// --- Modern CSV File Dropzone ---
	(function () {
		const $dropzone = $('#mtfp-file-dropzone');
		if ($dropzone.length === 0) {
			return;
		}

		const $fileInput = $dropzone.find('#import_file');
		const $content = $dropzone.find('.mtfp-dropzone-content');
		const $fileRow = $dropzone.find('.mtfp-dropzone-file');
		const $fileNameEl = $dropzone.find('.mtfp-dropzone-filename');
		const $errorEl = $dropzone.find('.mtfp-dropzone-error');
		const $browseBtn = $dropzone.find('.mtfp-dropzone-browse');
		const $removeBtn = $dropzone.find('.mtfp-dropzone-remove');

		function showError(message) {
			$errorEl.text(message).attr('hidden', false);
			$dropzone.addClass('has-error');
		}

		function clearError() {
			$errorEl.text('').attr('hidden', true);
			$dropzone.removeClass('has-error');
		}

		function setFile(file) {
			if (!file) {
				return;
			}
			const name = file.name || '';
			if (!name.toLowerCase().endsWith('.csv')) {
				$fileInput.val('');
				showError('Please choose a valid CSV file (.csv).');
				return;
			}
			clearError();
			$fileNameEl.text(name);
			$fileRow.attr('hidden', false);
			$content.attr('hidden', true);
			$dropzone.addClass('has-file');
		}

		function clearFile() {
			$fileInput.val('');
			$fileRow.attr('hidden', true);
			$content.attr('hidden', false);
			$dropzone.removeClass('has-file');
			clearError();
		}

		$browseBtn.on('click', function (e) {
			e.stopPropagation();
			$fileInput.trigger('click');
		});

		$dropzone.on('click', function (e) {
			if ($(e.target).closest('.mtfp-dropzone-remove').length) {
				return;
			}
			if (!$dropzone.hasClass('has-file')) {
				$fileInput.trigger('click');
			}
		});

		$fileInput.on('change', function () {
			setFile(this.files[0]);
		});

		$removeBtn.on('click', function (e) {
			e.stopPropagation();
			clearFile();
		});

		$dropzone.on('dragenter dragover', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$dropzone.addClass('is-dragover');
		});

		$dropzone.on('dragleave drop', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$dropzone.removeClass('is-dragover');
		});

		$dropzone.on('drop', function (e) {
			const files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
			if (files && files.length > 0) {
				$fileInput.prop('files', files);
				setFile(files[0]);
			}
		});
	})();

	// --- Boot Engine ---
	renderTable();
});
