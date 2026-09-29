/**
 * Redirects: target picker (spec Addendum A3).
 *
 * Progressive enhancement: the picker markup ships hidden and is only shown
 * here, so without JavaScript the target stays a plain text field. Results go
 * into a native <select> via textContent, never innerHTML. Searches are
 * debounced, and a response is applied only if it answers the latest request.
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

	function render(items) {
		if (items.length === 0) {
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
	}

	function search() {
		window.clearTimeout(timer);
		var id = ++latest;
		var term = query.value.trim();
		if (type.value === '' || term.length < 2) {
			message(cfg.i18n.minChars);
			return;
		}

		message(cfg.i18n.searching);
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
				if (!body || body.success !== true || !Array.isArray(body.data)) {
					throw new Error('response');
				}
				render(body.data);
			})
			.catch(function () {
				if (id === latest) {
					message(cfg.i18n.error);
				}
			});
	}

	type.addEventListener('change', function () {
		var custom = type.value === '';
		searchRow.hidden = custom;
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
