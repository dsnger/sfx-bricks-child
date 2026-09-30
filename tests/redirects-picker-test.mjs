/**
 * Pin the Redirects target picker's client behaviour (spec Addendum A3):
 * the list loads on type choice, the search box appears only above the
 * threshold (or when the list was cut, or failed to load), the cut-list hint,
 * the latest-request guard, and that switching type drops an address picked
 * under the previous type.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. A wiring test: the script runs in a
 * vm context against a small stand-in DOM and a stubbed fetch whose answers the
 * test releases in any order. It cannot catch layout or browser differences;
 * the browser check in the PR covers those.
 *
 * Run: node tests/redirects-picker-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT = readFileSync(
  join(dirname(dirname(fileURLToPath(import.meta.url))), 'inc/Redirects/assets/redirects-admin.js'),
  'utf8'
);

class El {
  constructor(id) {
    this.id = id;
    this.hidden = false;
    this.value = '';
    this.children = [];
    this.listeners = {};
    this.htmlFor = '';
    this.disabled = false;
    this._text = '';
  }
  set textContent(v) { this._text = String(v); this.children = []; }
  get textContent() { return this._text; }
  appendChild(c) { this.children.push(c); return c; }
  querySelector() { return this.code || null; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  dispatch(type, extra = {}) { (this.listeners[type] || []).forEach((fn) => fn({ preventDefault() {}, ...extra })); }
  focus() {}
  get options() { return this.children; }
}

function setup(threshold = '20') {
  const ids = ['sfx-redirects-picker', 'sfx-redirects-target', 'sfx-redirects-picker-type',
    'sfx-redirects-picker-search', 'sfx-redirects-picker-results', 'sfx-redirects-picker-filter',
    'sfx-redirects-picker-q', 'sfx-redirects-target-help', 'sfx-redirects-target-address',
    'sfx-redirects-target-label'];
  const els = Object.fromEntries(ids.map((id) => [id, new El(id)]));
  els['sfx-redirects-target-address'].code = new El('code');
  // Markup defaults: picker, search row, filter and address ship hidden.
  ['sfx-redirects-picker', 'sfx-redirects-picker-search', 'sfx-redirects-picker-filter', 'sfx-redirects-target-address']
    .forEach((id) => { els[id].hidden = true; });

  const pending = [];
  const ctx = {
    window: {
      sfxRedirectsPicker: {
        ajaxUrl: 'https://ex.test/wp-admin/admin-ajax.php',
        nonce: 'n',
        searchThreshold: threshold, // wp_localize_script sends strings
        i18n: { loading: 'L', noResults: 'N', choose: 'C', more: 'M', error: 'E' },
      },
      setTimeout: (fn) => { pending.push({ timer: fn }); return pending.length; },
      clearTimeout() {},
    },
    document: {
      getElementById: (id) => els[id] || null,
      createElement: () => new El('option'),
    },
    URLSearchParams,
    fetch: (url) => new Promise((resolve, reject) => pending.push({ url: String(url), resolve, reject })),
    Array, Error, String, parseInt, isNaN,
  };
  ctx.window.window = ctx.window;
  createContext(ctx);
  runInContext(SCRIPT, ctx);
  return { els, pending };
}

const ok = (items, more = false) => ({ ok: true, json: async () => ({ success: true, data: { items, more } }) });
const flush = () => new Promise((r) => setImmediate(r));
const entries = (n) => Array.from({ length: n }, (_, i) => ({ label: `P${i}`, path: `/p${i}/` }));
const labels = (sel) => sel.children.map((o) => o.textContent);

// Start state: Custom URL — field visible, picker shown, list parts hidden.
{
  const { els } = setup();
  assert.equal(els['sfx-redirects-picker'].hidden, false, 'the script shows the type select');
  assert.equal(els['sfx-redirects-target'].hidden, false, 'custom mode shows the target field');
  assert.equal(els['sfx-redirects-picker-search'].hidden, true, 'custom mode hides the entry list');
}

// Short list: loads on type choice, no search box.
{
  const { els, pending } = setup();
  els['sfx-redirects-picker-type'].value = 'page';
  els['sfx-redirects-picker-type'].dispatch('change');
  assert.equal(pending.length, 1, 'choosing a type loads the list at once');
  assert.match(pending[0].url, /type=page/);
  assert.match(pending[0].url, /q=(&|$)/, 'the first request is a list request (empty q)');
  assert.deepEqual(labels(els['sfx-redirects-picker-results']), ['L'], 'loading message while waiting');
  pending[0].resolve(ok(entries(3)));
  await flush(); await flush();
  assert.equal(els['sfx-redirects-picker-results'].children.length, 4, 'choose + 3 entries');
  assert.equal(els['sfx-redirects-picker-filter'].hidden, true, '3 entries: no search box');
  assert.equal(els['sfx-redirects-target'].hidden, true, 'list mode hides the target field');
}

// Exactly at the threshold: still no search box ("more than 20").
{
  const { els, pending } = setup('20');
  els['sfx-redirects-picker-type'].value = 'page';
  els['sfx-redirects-picker-type'].dispatch('change');
  pending[0].resolve(ok(entries(20)));
  await flush(); await flush();
  assert.equal(els['sfx-redirects-picker-filter'].hidden, true, '20 entries: no search box');
}

// Long list (threshold arrives as a string): search box appears.
{
  const { els, pending } = setup('20');
  els['sfx-redirects-picker-type'].value = 'page';
  els['sfx-redirects-picker-type'].dispatch('change');
  pending[0].resolve(ok(entries(21)));
  await flush(); await flush();
  assert.equal(els['sfx-redirects-picker-filter'].hidden, false, '21 entries: search box shown');
}

// Cut list: hint as a disabled last option, search box shown.
{
  const { els, pending } = setup();
  els['sfx-redirects-picker-type'].value = 'page';
  els['sfx-redirects-picker-type'].dispatch('change');
  pending[0].resolve(ok(entries(5), true));
  await flush(); await flush();
  const opts = els['sfx-redirects-picker-results'].children;
  assert.equal(opts[opts.length - 1].textContent, 'M', 'cut list ends with the hint');
  assert.equal(opts[opts.length - 1].disabled, true, 'the hint cannot be chosen');
  assert.equal(els['sfx-redirects-picker-filter'].hidden, false, 'cut list: search box shown');
}

// Failed load: error message, search box offered as retry.
{
  const { els, pending } = setup();
  els['sfx-redirects-picker-type'].value = 'page';
  els['sfx-redirects-picker-type'].dispatch('change');
  pending[0].resolve({ ok: false, status: 500, json: async () => ({}) });
  await flush(); await flush();
  assert.deepEqual(labels(els['sfx-redirects-picker-results']), ['E'], 'error message in the select');
  assert.equal(els['sfx-redirects-picker-filter'].hidden, false, 'failed load: search box shown for retry');
}

// Latest-request guard: an older answer arriving after a type change is dropped.
{
  const { els, pending } = setup();
  const type = els['sfx-redirects-picker-type'];
  type.value = 'page'; type.dispatch('change');
  type.value = 'post'; type.dispatch('change');
  pending[1].resolve(ok([{ label: 'Post', path: '/post/' }]));
  await flush(); await flush();
  pending[0].resolve(ok([{ label: 'Page', path: '/page/' }]));
  await flush(); await flush();
  assert.deepEqual(labels(els['sfx-redirects-picker-results']), ['C', 'Post — /post/'], 'the older page answer did not overwrite the post list');
}

// ... and an older *failure* does not replace a newer list with an error.
{
  const { els, pending } = setup();
  const type = els['sfx-redirects-picker-type'];
  type.value = 'page'; type.dispatch('change');
  type.value = 'post'; type.dispatch('change');
  pending[1].resolve(ok([{ label: 'Post', path: '/post/' }]));
  await flush(); await flush();
  pending[0].reject(new Error('network'));
  await flush(); await flush();
  assert.deepEqual(labels(els['sfx-redirects-picker-results']), ['C', 'Post — /post/'], 'the older failure did not overwrite the post list');
  assert.equal(els['sfx-redirects-picker-filter'].hidden, true, 'nor open the retry search box');
}

// Picking writes the address; switching type drops it (PR #42 review).
{
  const { els, pending } = setup();
  const type = els['sfx-redirects-picker-type'];
  const results = els['sfx-redirects-picker-results'];
  type.value = 'page'; type.dispatch('change');
  pending[0].resolve(ok([{ label: 'About', path: '/about/' }]));
  await flush(); await flush();
  results.value = '/about/'; results.dispatch('change');
  assert.equal(els['sfx-redirects-target'].value, '/about/', 'picking writes the target');
  assert.equal(els['sfx-redirects-target-address'].hidden, false, 'the chosen address is shown');
  type.value = 'post'; type.dispatch('change');
  assert.equal(els['sfx-redirects-target'].value, '', 'switching to another list type drops the old address');
  assert.equal(els['sfx-redirects-target-address'].hidden, true, 'and hides the address line');
}

console.log('PASS: redirects picker');
