/**
 * Redirects: target picker (spec Addendum A3).
 *
 * Progressive enhancement: the picker markup ships hidden and is only shown
 * here, so without JavaScript the target stays a plain text field. Choosing a
 * type lists its entries right away; a search box to narrow them appears when
 * the list is longer than cfg.searchThreshold, was cut, or failed to load. Results go into
 * a native <select> via textContent, never innerHTML. Typing is debounced, and
 * a response is applied only if it answers the latest request.
 */
(function () {
	'use strict';

	var cfg = window.sfxRedirectsPicker;
	var picker = document.getElementById('sfx-redirects-picker');
	var target = document.getElementById('sfx-redirects-target');
	if (!cfg || !picker || !target) {
		return;
	}

	var type = document.getElementById('sfx-redirects-picker-type');
	var searchRow = document.getElementById('sfx-redirects-picker-search');
	var query = document.getElementById('sfx-redirects-picker-q');
	var filter = document.getElementById('sfx-redirects-picker-filter');
	// wp_localize_script() sends numbers as strings.
	var threshold = parseInt(cfg.searchThreshold, 10);
	if (isNaN(threshold)) {
		threshold = 20;
	}
	var results = document.getElementById('sfx-redirects-picker-results');
	var timer = 0;
	var latest = 0;

	function option(value, text) {
		var el = document.createElement('option');
		el.value = value;
		el.textContent = text;
		return el;
	}

	function message(text) {
		results.textContent = '';
		results.appendChild(option('', text));
	}

	function render(items, more) {
		// The search box only earns its place on a long list; once someone has
		// typed, it stays so the text can be changed back.
		if (more || items.length > threshold || query.value.trim() !== '') {
			filter.hidden = false;
		}
		if (items.length === 0 && !more) {
			message(cfg.i18n.noResults);
			return;
		}
		results.textContent = '';
		results.appendChild(option('', cfg.i18n.choose));
		items.forEach(function (item) {
			if (item && typeof item.path === 'string' && typeof item.label === 'string') {
				results.appendChild(option(item.path, item.label + ' — ' + item.path));
			}
		});
		if (more) {
			var hint = option('', cfg.i18n.more);
			hint.disabled = true;
			results.appendChild(hint);
		}
	}

	function search() {
		window.clearTimeout(timer);
		var id = ++latest;
		var term = query.value.trim();
		if (type.value === '') {
			results.textContent = '';
			return;
		}

		// An empty box lists the type's entries; text narrows them.
		message(cfg.i18n.loading);
		var params = new URLSearchParams({
			action: 'sfx_redirects_search',
			_ajax_nonce: cfg.nonce,
			type: type.value,
			q: term
		});
		fetch(cfg.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error(String(response.status));
				}
				return response.json();
			})
			.then(function (body) {
				if (id !== latest) {
					return;
				}
				if (!body || body.success !== true || !body.data || !Array.isArray(body.data.items)) {
					throw new Error('response');
				}
				render(body.data.items, body.data.more === true);
			})
			.catch(function () {
				if (id === latest) {
					message(cfg.i18n.error);
					// Offer the search box as a way to retry, whatever the list length.
					filter.hidden = false;
				}
			});
	}

	type.addEventListener('change', function () {
		var custom = type.value === '';
		searchRow.hidden = custom;
		// A new type starts as a plain list; its length decides about the search box.
		query.value = '';
		filter.hidden = true;
		if (custom) {
			window.clearTimeout(timer);
			latest++; // drop any answer still in flight
			results.textContent = '';
			return;
		}
		search();
	});

	query.addEventListener('input', function () {
		window.clearTimeout(timer);
		// The text changed: results for the old text are no longer valid, and an
		// answer still in flight for it must not repopulate the list.
		latest++;
		results.textContent = '';
		timer = window.setTimeout(search, 300);
	});

	// Enter in the search box searches now instead of submitting the rule form.
	query.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			search();
		}
	});

	results.addEventListener('change', function () {
		if (results.value !== '') {
			target.value = results.value;
		}
	});

	picker.hidden = false;
}());
