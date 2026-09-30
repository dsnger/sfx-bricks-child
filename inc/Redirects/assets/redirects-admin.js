/**
 * Redirects: target picker (spec Addendum A3).
 *
 * Progressive enhancement: the type select ships hidden and is only shown
 * here, so without JavaScript the target stays a plain text field.
 *
 * "Custom URL" shows the target field. Any other type replaces it with a list
 * of that type's entries, loaded right away; picking one writes its address
 * into the (then hidden) target field and shows it below. A search box to
 * narrow the list appears when the list is longer than cfg.searchThreshold,
 * was cut, or failed to load. Results go into a native <select> via
 * textContent, never innerHTML. Typing is debounced, and a response is applied
 * only if it answers the latest request.
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
	var results = document.getElementById('sfx-redirects-picker-results');
	var filter = document.getElementById('sfx-redirects-picker-filter');
	var query = document.getElementById('sfx-redirects-picker-q');
	var help = document.getElementById('sfx-redirects-target-help');
	var address = document.getElementById('sfx-redirects-target-address');
	var addressCode = address ? address.querySelector('code') : null;
	var label = document.getElementById('sfx-redirects-target-label');

	// wp_localize_script() sends numbers as strings.
	var threshold = parseInt(cfg.searchThreshold, 10);
	if (isNaN(threshold)) {
		threshold = 20;
	}
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

	function showAddress() {
		if (!address || !addressCode) {
			return;
		}
		addressCode.textContent = target.value;
		address.hidden = target.value === '';
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

	function load() {
		window.clearTimeout(timer);
		var id = ++latest;
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
			q: query.value.trim()
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

	// "Custom URL" edits the target directly; any other type picks from a list.
	function setMode(custom) {
		target.hidden = !custom;
		// The visible "Target" label points at whichever control is visible.
		if (label) {
			label.htmlFor = custom ? 'sfx-redirects-target' : 'sfx-redirects-picker-results';
		}
		if (help) {
			help.hidden = !custom;
		}
		searchRow.hidden = custom;
		if (custom) {
			filter.hidden = true;
			if (address) {
				address.hidden = true;
			}
		} else {
			showAddress();
		}
	}

	type.addEventListener('change', function () {
		// A new type starts as a plain list; its length decides about the search box.
		query.value = '';
		filter.hidden = true;
		var custom = type.value === '';
		if (!custom) {
			// A list type starts with nothing chosen: an address picked under the
			// previous type must not be submitted from a now-hidden field.
			target.value = '';
		}
		setMode(custom);
		if (custom) {
			window.clearTimeout(timer);
			latest++; // drop any answer still in flight
			results.textContent = '';
			target.focus();
			return;
		}
		load();
	});

	query.addEventListener('input', function () {
		window.clearTimeout(timer);
		// The text changed: results for the old text are no longer valid, and an
		// answer still in flight for it must not repopulate the list.
		latest++;
		results.textContent = '';
		timer = window.setTimeout(load, 300);
	});

	// Enter in the search box searches now instead of submitting the rule form.
	query.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			load();
		}
	});

	results.addEventListener('change', function () {
		if (results.value !== '') {
			target.value = results.value;
			showAddress();
		}
	});

	picker.hidden = false;
	setMode(true);
}());
