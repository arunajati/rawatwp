(function() {
	'use strict';

	function normalizeText(value) {
		return String(value || '').replace(/\s+/g, ' ').trim();
	}

	function parseSortableValue(value) {
		var text = normalizeText(value);
		if (!text || '-' === text) {
			return { type: 'empty', value: '' };
		}

		var percentMatch = text.match(/^(-?\d+(?:\.\d+)?)%$/);
		if (percentMatch) {
			return { type: 'number', value: parseFloat(percentMatch[1]) };
		}

		var numeric = text.replace(/,/g, '');
		if (/^-?\d+(?:\.\d+)?$/.test(numeric)) {
			return { type: 'number', value: parseFloat(numeric) };
		}

		var dateMatch = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})(?:\s+(\d{1,2}):(\d{2})(?:\s*(AM|PM))?)?$/i);
		if (dateMatch) {
			var day = parseInt(dateMatch[1], 10);
			var month = parseInt(dateMatch[2], 10) - 1;
			var year = parseInt(dateMatch[3], 10);
			var hour = dateMatch[4] ? parseInt(dateMatch[4], 10) : 0;
			var minute = dateMatch[5] ? parseInt(dateMatch[5], 10) : 0;
			var meridiem = dateMatch[6] ? dateMatch[6].toUpperCase() : '';
			if ('PM' === meridiem && hour < 12) {
				hour += 12;
			}
			if ('AM' === meridiem && 12 === hour) {
				hour = 0;
			}
			return { type: 'date', value: new Date(year, month, day, hour, minute, 0, 0).getTime() };
		}

		var parsedDate = Date.parse(text);
		if (!Number.isNaN(parsedDate)) {
			return { type: 'date', value: parsedDate };
		}

		return { type: 'text', value: text.toLowerCase() };
	}

	function getCellText(row, index) {
		if (!row || !row.cells || !row.cells[index]) {
			return '';
		}
		var cell = row.cells[index];
		return normalizeText(cell.textContent || '');
	}

	function clearHeaderSortState(headers) {
		headers.forEach(function(headerCell) {
			headerCell.classList.remove('rawatwp-sort-asc');
			headerCell.classList.remove('rawatwp-sort-desc');
			headerCell.removeAttribute('aria-sort');
		});
	}

	function sortTableByColumn(table, headerCell, columnIndex) {
		var tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
		if (!tbody) {
			return;
		}

		var headers = Array.prototype.slice.call(table.tHead.rows[0].cells || []);
		var rows = Array.prototype.slice.call(tbody.rows || []);
		if (rows.length <= 1) {
			return;
		}

		var dataRows = [];
		var staticRows = [];
		rows.forEach(function(row) {
			if (1 === row.cells.length && row.cells[0] && row.cells[0].colSpan > 1) {
				staticRows.push(row);
				return;
			}
			dataRows.push(row);
		});

		if (dataRows.length <= 1) {
			return;
		}

		var currentDirection = headerCell.classList.contains('rawatwp-sort-asc') ? 'asc' : (headerCell.classList.contains('rawatwp-sort-desc') ? 'desc' : '');
		var nextDirection = 'asc' === currentDirection ? 'desc' : 'asc';
		var multiplier = 'asc' === nextDirection ? 1 : -1;

		dataRows.sort(function(a, b) {
			var aParsed = parseSortableValue(getCellText(a, columnIndex));
			var bParsed = parseSortableValue(getCellText(b, columnIndex));

			if ('empty' === aParsed.type && 'empty' === bParsed.type) {
				return 0;
			}
			if ('empty' === aParsed.type) {
				return 1;
			}
			if ('empty' === bParsed.type) {
				return -1;
			}

			if (aParsed.type === bParsed.type && ('number' === aParsed.type || 'date' === aParsed.type)) {
				return (aParsed.value - bParsed.value) * multiplier;
			}

			var aText = normalizeText(getCellText(a, columnIndex)).toLowerCase();
			var bText = normalizeText(getCellText(b, columnIndex)).toLowerCase();
			return aText.localeCompare(bText, undefined, { numeric: true, sensitivity: 'base' }) * multiplier;
		});

		clearHeaderSortState(headers);
		headerCell.classList.add('asc' === nextDirection ? 'rawatwp-sort-asc' : 'rawatwp-sort-desc');
		headerCell.setAttribute('aria-sort', 'asc' === nextDirection ? 'ascending' : 'descending');

		dataRows.forEach(function(row) {
			tbody.appendChild(row);
		});
		staticRows.forEach(function(row) {
			tbody.appendChild(row);
		});
	}

	function makeTableSortable(table) {
		if (!table || table.dataset.rawatwpSortedReady === '1' || !table.tHead || !table.tHead.rows.length) {
			return;
		}

		var headers = Array.prototype.slice.call(table.tHead.rows[0].cells || []);
		if (!headers.length) {
			return;
		}

		headers.forEach(function(headerCell, index) {
			if (!headerCell || headerCell.classList.contains('rawatwp-no-sort') || headerCell.querySelector('input[type="checkbox"]')) {
				return;
			}

			var headerText = normalizeText(headerCell.textContent || '');
			if (!headerText) {
				return;
			}
			var normalizedHeader = headerText.toLowerCase();
			if (
				'action' === normalizedHeader ||
				'security key' === normalizedHeader ||
				'key' === normalizedHeader
			) {
				headerCell.classList.add('rawatwp-no-sort');
				return;
			}

			headerCell.classList.add('rawatwp-sortable');
			headerCell.setAttribute('tabindex', '0');
			headerCell.setAttribute('role', 'button');
			headerCell.setAttribute('aria-label', 'Sort by ' + headerText);

			headerCell.addEventListener('click', function() {
				sortTableByColumn(table, headerCell, index);
			});

			headerCell.addEventListener('keydown', function(event) {
				if ('Enter' === event.key || ' ' === event.key) {
					event.preventDefault();
					sortTableByColumn(table, headerCell, index);
				}
			});
		});

		table.dataset.rawatwpSortedReady = '1';
	}

	function initSortableTables() {
		var tables = document.querySelectorAll('.rawatwp-admin table.widefat');
		if (!tables.length) {
			return;
		}

		Array.prototype.forEach.call(tables, function(table) {
			makeTableSortable(table);
		});
	}

	function ensureLoadingOverlay() {
		var existing = document.getElementById('rawatwp-loading-overlay');
		if (existing) {
			return existing;
		}

		var overlay = document.createElement('div');
		overlay.id = 'rawatwp-loading-overlay';
		overlay.className = 'rawatwp-loading-overlay';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.innerHTML = '' +
			'<div class="rawatwp-loading-card" role="status" aria-live="polite">' +
				'<span class="rawatwp-loading-spinner" aria-hidden="true"></span>' +
				'<div class="rawatwp-loading-content">' +
					'<strong>Please wait</strong>' +
					'<span class="rawatwp-loading-message">Processing your request...</span>' +
				'</div>' +
			'</div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function setLoadingOverlayState(show, message) {
		var overlay = ensureLoadingOverlay();
		var messageNode = overlay.querySelector('.rawatwp-loading-message');

		if (messageNode && message) {
			messageNode.textContent = String(message);
		}

		if (show) {
			overlay.classList.add('is-active');
			overlay.setAttribute('aria-hidden', 'false');
			return;
		}

		overlay.classList.remove('is-active');
		overlay.setAttribute('aria-hidden', 'true');
	}

	function bindLoadingForms() {
		var forms = document.querySelectorAll('form[data-rawatwp-loading="1"]');
		if (!forms.length) {
			return;
		}

		Array.prototype.forEach.call(forms, function(form) {
			if (!form || form.dataset.rawatwpLoadingBound === '1') {
				return;
			}

			form.dataset.rawatwpLoadingBound = '1';
			form.addEventListener('submit', function(event) {
				if (event.defaultPrevented) {
					return;
				}
				if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
					return;
				}

				var message = form.getAttribute('data-rawatwp-loading-message') || 'Processing your request...';
				setLoadingOverlayState(true, message);
			});
		});
	}

	function initLoadingUi() {
		ensureLoadingOverlay();
		bindLoadingForms();
		window.addEventListener('pageshow', function() {
			setLoadingOverlayState(false, 'Processing your request...');
		});

		window.addEventListener('rawatwp:loading', function(event) {
			var detail = event && event.detail ? event.detail : {};
			var show = Boolean(detail.show);
			var message = detail.message || 'Processing your request...';
			setLoadingOverlayState(show, message);
		});
	}

	function initAdminUi() {
		initSortableTables();
		initLoadingUi();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAdminUi);
	} else {
		initAdminUi();
	}
})();
