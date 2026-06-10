/**
 * JavaScript handlers for the Manual Translations for Polylang admin page.
 * Implements a reactive client-side rendering engine with live search,
 * custom pagination, and AJAX-driven CRUD operations without page refreshes.
 */
jQuery(document).ready(function ($) {
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
				langCells += `
					<td class="mtfp-cell-editable" data-lang="${lang.slug}" data-value="${escapeHtml(val)}">
						<span class="mtfp-editable-text">${escapeHtml(val)}</span>
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

	// --- Theme Scanner Logic ---

	$('.mtfp-trigger-scan').on('click', function () {
		const btn = $(this);
		const originalHtml = btn.html();
		btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Scanning...');

		$.ajax({
			url: manualTranslationsForPolylangAdminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mtfp_scan_theme',
				nonce: manualTranslationsForPolylangAdminData.nonce
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
				btn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	function renderScanResults(strings) {
		const container = $('#mtfp-scan-results-container');
		if (strings.length === 0) {
			container.html(`
				<div class="notice notice-info notice-alt" style="margin-bottom: 24px;">
					<p>Scan complete. No new untranslated strings were found in the active theme files.</p>
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

	// --- Boot Engine ---
	renderTable();
});
