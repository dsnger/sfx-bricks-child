/**
 * Pin the SiteCheck manual run in the browser (spec "Manual run", "Sections"):
 * each check its own request, at most 4 in parallel; a failing or timed-out
 * request settles as Nicht prüfbar "Anfrage fehlgeschlagen" and the run still
 * saves; the save carries tokens only; sections sort Rot → Gelb → Nicht
 * prüfbar → Hinweis → Grün. The page (Task 11): six tabs with the ARIA
 * tabs pattern and fragments, counting, rows kept in place, run states,
 * Übersicht, empty states, guidance; labels reach the page as text, never
 * as HTML (the fake DOM throws on innerHTML).
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. The script runs in a vm context
 * with mocked AJAX and a small fake DOM (no layout, no real focus or
 * history); no real browser runs it.
 *
 * Run: node tests/site-check-page-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT = readFileSync(
  join(dirname(dirname(fileURLToPath(import.meta.url))), 'inc/SiteCheck/assets/site-check.js'),
  'utf8'
);

const flush = () => new Promise((resolve) => setImmediate(resolve));

function load(extra = {}) {
  const ctx = { AbortController, setTimeout, clearTimeout, Math, JSON, Promise, console, ...extra };
  ctx.window = ctx;
  createContext(ctx);
  runInContext(SCRIPT, ctx);
  return ctx.SfxSiteCheck;
}

const checks = [
  { id: 'logs_public', how: 'S+B', section: 'security', title: 'Logs' },
  { id: 'debug_display', how: 'S', section: 'security', title: 'Debug' },
  { id: 'php_in_uploads', how: 'S', section: 'security', title: 'Probe' },
  { id: 'robots_txt', how: 'B', section: 'golive', title: 'Robots' },
  { id: 'updates', how: 'S', section: 'cleanup', title: 'Updates' },
  { id: 'xmlrpc', how: 'B', section: 'security', title: 'XML-RPC' },
];

// ------------------------------------------------------------ the run: routing, 4 in parallel, failures, save

{
  const api = load();
  const posts = [];
  const pending = [];
  let inFlight = 0;
  let max = 0;
  const work = (value) => {
    inFlight++;
    max = Math.max(max, inFlight);
    return new Promise((resolve, reject) => pending.push(() => {
      inFlight--;
      if (value instanceof Error) reject(value); else resolve(value);
    }));
  };
  const post = (action, fields) => {
    posts.push({ action, fields });
    if (action === 'sfx_site_check_start') return Promise.resolve({ run: 'r1', profile: 'live', checks });
    if (action === 'sfx_site_check_save') return Promise.resolve({ last: { run: 'r1' } });
    if (fields.check === 'php_in_uploads') {
      const e = new Error('timeout');
      e.timeout = true;
      return work(e);
    }
    return work({ graded: { status: 'green', findings: [], perspective: 'server', note: '' }, observation: { body: 'NOPE' }, token: 'tok-' + fields.check });
  };
  const runCheck = (run, id) => {
    posts.push({ action: 'runCheck', fields: { run, check: id } });
    if (id === 'robots_txt') return work(new Error('request failed'));
    return work({ graded: { status: 'yellow', findings: [], perspective: 'browser', note: '' }, facts: { x: 1 }, token: 'tok-' + id });
  };
  const seen = {};
  const runner = api.createRunner({ post, runCheck, strings: { requestFailed: 'Anfrage fehlgeschlagen' } });
  const done = runner.run(true, { result: (check, graded) => { seen[check.id] = graded; } });
  for (let round = 0; round < 50 && pending.length + inFlight >= 0; round++) {
    await flush();
    assert.ok(inFlight <= 4, `at most 4 checks in flight (now ${inFlight})`);
    if (pending.length) pending.shift()();
    if (Object.keys(seen).length === checks.length) break;
  }
  const saved = await done;
  assert.equal(max, 4, 'uses 4 parallel slots');
  assert.deepEqual(saved, { last: { run: 'r1' } }, 'resolves to the save answer');
  assert.equal(posts[0].action, 'sfx_site_check_start');
  assert.equal(posts[0].fields.probe, '1', 'probe ticked → probe=1');
  const routed = Object.fromEntries(posts.filter((p) => p.fields.check).map((p) => [p.fields.check, p.action]));
  assert.deepEqual(routed, {
    logs_public: 'runCheck', debug_display: 'sfx_site_check_server', php_in_uploads: 'sfx_site_check_server',
    robots_txt: 'runCheck', updates: 'sfx_site_check_server', xmlrpc: 'runCheck',
  }, 'S checks go to the server endpoint, B and S+B to targets/observe');
  assert.equal(seen.robots_txt.status, 'unknown', 'failed request → Nicht prüfbar');
  assert.equal(seen.robots_txt.note, 'Anfrage fehlgeschlagen', 'failed request → "Anfrage fehlgeschlagen"');
  assert.equal(seen.php_in_uploads.status, 'unknown', 'timeout → Nicht prüfbar');
  const save = posts[posts.length - 1];
  assert.equal(save.action, 'sfx_site_check_save', 'the run completes with one save');
  assert.equal(save.fields.run, 'r1');
  const results = JSON.parse(save.fields.results);
  assert.deepEqual(results, {
    logs_public: { token: 'tok-logs_public' }, debug_display: { token: 'tok-debug_display' }, php_in_uploads: { error: 'timeout' },
    robots_txt: { error: 'request_failed' }, updates: { token: 'tok-updates' }, xmlrpc: { token: 'tok-xmlrpc' },
  }, 'the save carries one token or failure per check — no facts, observations or grades');
  assert.ok(!save.fields.results.includes('NOPE'), 'no observation text in the save');
}

// ------------------------------------------------------------ scheduling (Task 10): Loopback ≤ 2 in flight, the probe last

{
  const api = load();
  const list = [
    { id: 'php_in_uploads', how: 'S', perspective: 'loopback' },
    { id: 'https', how: 'S+B', perspective: 'loopback' },
    { id: 'xmlrpc', how: 'B', perspective: 'loopback' },
    { id: 'indexability', how: 'B', perspective: 'loopback' },
    { id: 'sitemap', how: 'B', perspective: 'loopback' },
    { id: 'sitemap_entries', how: 'B', perspective: 'loopback' },
    { id: 'robots_txt', how: 'B', perspective: 'browser' },
    { id: 'logs_public', how: 'S+B', perspective: 'browser' },
    { id: 'vcs_env', how: 'S+B', perspective: 'browser' },
    { id: 'debug_display', how: 'S', perspective: 'server' },
    { id: 'updates', how: 'S', perspective: 'server' },
  ];
  const kind = Object.fromEntries(list.map((c) => [c.id, c.perspective]));
  const pending = [];
  const running = new Set();
  let settled = 0;
  let maxAll = 0;
  let maxLoopback = 0;
  let probeStart = null;
  const loopbackNow = () => [...running].filter((id) => kind[id] === 'loopback').length;
  const work = (id) => {
    if (id === 'php_in_uploads') probeStart = { settled, running: running.size };
    running.add(id);
    maxAll = Math.max(maxAll, running.size);
    maxLoopback = Math.max(maxLoopback, loopbackNow());
    return new Promise((resolve) => pending.push(() => {
      running.delete(id);
      settled++;
      resolve({ graded: { status: 'green', findings: [] }, token: 't-' + id });
    }));
  };
  const post = (action, fields) => {
    if (action === 'sfx_site_check_start') return Promise.resolve({ run: 'rs', checks: list });
    if (action === 'sfx_site_check_save') return Promise.resolve({ last: {} });
    return work(fields.check);
  };
  const runner = api.createRunner({ post, runCheck: (run, id) => work(id), strings: {} });
  const done = runner.run(true, {});
  for (let round = 0; round < 100 && settled < list.length; round++) {
    await flush();
    assert.ok(loopbackNow() <= 2, `at most 2 Loopback checks in flight (now ${loopbackNow()})`);
    assert.ok(running.size <= 4, `at most 4 checks in flight (now ${running.size})`);
    if (pending.length) pending.shift()();
  }
  await done;
  assert.equal(settled, list.length, 'every check ran');
  assert.equal(maxLoopback, 2, 'Loopback checks use 2 slots');
  assert.equal(maxAll, 4, 'browser and server checks still fill 4 slots');
  assert.deepEqual(probeStart, { settled: list.length - 1, running: 0 }, 'the probe starts only after every other check has settled');
}

// Probe unticked → probe=0. A failed save rejects (the page shows the error; the server keeps the previous run).
{
  const api = load();
  const posts = [];
  const post = async (action, fields) => {
    posts.push({ action, fields });
    if (action === 'sfx_site_check_start') return { run: 'r2', checks: [checks[1]] };
    if (action === 'sfx_site_check_save') throw new Error('Netzwerkfehler');
    return { graded: { status: 'green', findings: [] }, token: 't' };
  };
  const runner = api.createRunner({ post, runCheck: async () => ({}), strings: {} });
  await assert.rejects(runner.run(false, {}), /Netzwerkfehler/, 'a failed save transport rejects');
  assert.equal(posts[0].fields.probe, '0', 'probe unticked → probe=0');
}

// ------------------------------------------------------------ AJAX timeout

{
  const timers = [];
  const api = load({
    setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
    clearTimeout: () => {},
    fetch: (url, init) => new Promise((resolve, reject) => {
      init.signal.addEventListener('abort', () => reject(new Error('aborted')));
    }),
  });
  class FakeFormData { append() {} }
  const fetcher = api.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData });
  const p = fetcher.post('sfx_site_check_server', { run: 'r', check: 'x' });
  await flush();
  const t = timers.find((x) => x.ms === api.AJAX_TIMEOUT_MS);
  assert.ok(t, 'an AJAX request carries its own timeout');
  t.fn();
  await assert.rejects(p, (e) => e.timeout === true, 'a hanging AJAX request is aborted as a timeout');

  // Gate B pass 1 (spec-15): the visible failure texts come localised from PHP.
  const localised = api.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData, strings: { timeout: 'ZEIT ABGELAUFEN', requestFailed: 'ANFRAGE GESCHEITERT' } });
  const q = localised.post('sfx_site_check_server', { run: 'r', check: 'x' });
  await flush();
  timers.filter((x) => x.ms === api.AJAX_TIMEOUT_MS).pop().fn();
  await assert.rejects(q, (e) => e.timeout === true && e.message === 'ZEIT ABGELAUFEN', 'the timeout text is the localised one');
}

{
  const api = load({ fetch: () => Promise.reject(new Error('offline')) });
  class FakeFormData { append() {} }
  const fetcher = api.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData, strings: { timeout: 'ZEIT ABGELAUFEN', requestFailed: 'ANFRAGE GESCHEITERT' } });
  await assert.rejects(fetcher.post('x', {}), (e) => e.lost === true && e.message === 'ANFRAGE GESCHEITERT', 'a failed request carries the localised text');
  const refusing = load({ fetch: async () => ({ status: 400, json: async () => ({ success: false }) }) });
  const f2 = refusing.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData, strings: { requestFailed: 'ANFRAGE GESCHEITERT' } });
  await assert.rejects(f2.post('x', {}), (e) => e.refused === true && e.message === 'ANFRAGE GESCHEITERT', 'a refusal without a server message carries the localised text');
}

// ------------------------------------------------------------ sort order

{
  const api = load();
  const results = {
    a: { status: 'green' }, b: { status: 'hint' }, c: { status: 'unknown' }, d: { status: 'yellow' }, e: { status: 'red' }, f: { status: 'yellow' },
  };
  const list = ['a', 'b', 'c', 'g', 'd', 'e', 'f'].map((id) => ({ id }));
  assert.deepEqual(api.sortChecks(list, results).map((c) => c.id), ['e', 'd', 'f', 'c', 'b', 'a', 'g'],
    'Rot → Gelb → Nicht prüfbar → Hinweis → Grün, catalogue order within a status, pending last');
}

// ------------------------------------------------------------ failure keeps the check's own perspective

{
  const api = load();
  const seen = {};
  const post = async (action) => {
    if (action === 'sfx_site_check_start') return { run: 'r3', checks: [{ id: 'https', how: 'S+B', perspective: 'loopback' }, { id: 'robots_txt', how: 'B', perspective: 'browser' }] };
    if (action === 'sfx_site_check_save') return { last: {} };
    throw new Error('x');
  };
  const runner = api.createRunner({ post, runCheck: async () => { throw new Error('down'); }, strings: {} });
  await runner.run(false, { result: (c, g) => { seen[c.id] = g; } });
  assert.equal(seen.https.perspective, 'loopback', 'a failed Loopback check stays Loopback');
  assert.equal(seen.robots_txt.perspective, 'browser', 'a failed browser check stays Browser');
}

// ------------------------------------------------------------ busy save: retry resends the same results, checks are not re-run

{
  const api = load({
    fetch: async () => ({ status: 503, json: async () => ({ success: false, data: { message: 'Busy, please try again.' } }) }),
  });
  class FakeFormData { append() {} }
  const fetcher = api.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData });
  await assert.rejects(fetcher.post('sfx_site_check_save', {}), (e) => e.busy === true && e.message === 'Busy, please try again.', 'HTTP 503 → err.busy');
}
{
  const api = load();
  const posts = [];
  let saves = 0;
  const post = async (action, fields) => {
    posts.push({ action, fields });
    if (action === 'sfx_site_check_start') return { run: 'r4', checks: [{ id: 'debug_display', how: 'S' }] };
    if (action === 'sfx_site_check_save') {
      saves++;
      if (saves === 1) { const e = new Error('Busy'); e.busy = true; throw e; }
      return { last: { run: 'r4' } };
    }
    return { graded: { status: 'green', findings: [] }, token: 'tok' };
  };
  const runner = api.createRunner({ post, runCheck: async () => ({}), strings: {} });
  let error;
  try { await runner.run(false, {}); } catch (e) { error = e; }
  assert.ok(error && error.busy && typeof error.retry === 'function', 'a busy save rejects with a retry');
  const checkPosts = posts.filter((p) => p.action === 'sfx_site_check_server').length;
  assert.deepEqual(await error.retry(), { last: { run: 'r4' } }, 'the retry saves');
  const saved = posts.filter((p) => p.action === 'sfx_site_check_save');
  assert.equal(saved.length, 2, 'the retry posts the save again');
  assert.deepEqual(saved[1].fields, saved[0].fields, 'the retry resends the same run and results');
  assert.equal(posts.filter((p) => p.action === 'sfx_site_check_server').length, checkPosts, 'the retry re-runs no check');
}

// ------------------------------------------------------------ errors carry where they happened (Task 11)

{
  const api = load({ fetch: async () => { throw new Error('offline'); } });
  class FakeFormData { append() {} }
  const fetcher = api.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData });
  await assert.rejects(fetcher.post('sfx_site_check_save', {}), (e) => e.lost === true && !e.refused, 'no answer → err.lost');
  const api2 = load({ fetch: async () => ({ status: 409, json: async () => ({ success: false, data: { message: 'Not current' } }) }) });
  const f2 = api2.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData });
  await assert.rejects(f2.post('sfx_site_check_save', {}), (e) => e.refused === true && !e.lost && e.status === 409, 'success:false → err.refused with the status');
  const api3 = load({ fetch: async () => ({ status: 500, json: async () => { throw new Error('not json'); } }) });
  const f3 = api3.createFetcher({ ajaxUrl: '/a', nonce: 'n', FormData: FakeFormData });
  await assert.rejects(f3.post('sfx_site_check_save', {}), (e) => e.lost === true, 'an unreadable answer is no answer');
}
{
  const api = load();
  const refuse = () => { const e = new Error('Busy'); e.refused = true; e.busy = true; return e; };
  let runner = api.createRunner({ post: async () => { throw refuse(); }, runCheck: async () => ({}), strings: {} });
  await assert.rejects(runner.run(false, {}), (e) => e.stage === 'start', 'a failed start says so');
  runner = api.createRunner({ post: async (a) => { if (a === 'sfx_site_check_start') return { run: 'r', checks: [] }; throw refuse(); }, runCheck: async () => ({}), strings: {} });
  await assert.rejects(runner.run(false, {}), (e) => e.stage === 'save' && typeof e.retry === 'function', 'a refused save says so and offers the retry');
}

// ------------------------------------------------------------ the page (mount) on a fake DOM

let doc;

class TextNode {
  constructor(t) { this._t = String(t); this.parentNode = null; }
  get textContent() { return this._t; }
}

class El {
  constructor(tag) {
    this.tagName = tag; this.children = []; this.parentNode = null; this.attrs = {}; this.listeners = {};
    this._text = ''; this.className = ''; this.hidden = false; this.disabled = false; this.open = false; this.checked = false; this.type = ''; this.value = '';
  }
  get id() { return this.attrs.id || ''; }
  set id(v) { this.attrs.id = String(v); }
  appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c, ref) {
    if (ref === null || ref === undefined) return this.appendChild(c);
    if (c.parentNode) c.parentNode.removeChild(c);
    c.parentNode = this; this.children.splice(this.children.indexOf(ref), 0, c); return c;
  }
  removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; return c; }
  get firstChild() { return this.children[0] || null; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; }
  set textContent(v) { this._text = String(v); this.children.forEach((c) => { c.parentNode = null; }); this.children = []; }
  get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
  set innerHTML(v) { throw new Error('innerHTML used'); }
  set outerHTML(v) { throw new Error('outerHTML used'); }
  insertAdjacentHTML() { throw new Error('insertAdjacentHTML used'); }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  dispatch(type, props = {}) {
    const e = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...props };
    for (let n = this; n; n = n.parentNode) (n.listeners && n.listeners[type] || []).forEach((fn) => fn(e));
    return e;
  }
  click() { return this.dispatch('click'); }
  focus() { doc.activeElement = this; }
  all() { return this.children.filter((c) => c instanceof El).flatMap((c) => [c, ...c.all()]); }
  querySelectorAll(sel) { const cls = sel.replace(/^\./, ''); return this.all().filter((e) => e.className.split(' ').includes(cls)); }
}

/** Visible = neither it nor an ancestor is hidden. */
const visible = (e) => { for (let n = e; n; n = n.parentNode) if (n.hidden) return false; return true; };
const find = (root, pred) => (root.all ? root.all() : []).find(pred) || null;
const findAll = (root, pred) => root.all().filter(pred);
const byText = (root, tag, text) => find(root, (e) => e.tagName === tag && e.textContent === text);

const guidance = (id) => ({
  why: 'WHY-' + id, recommendation: 'REC-' + id,
  steps: id === 'php_in_uploads' ? ['STEP-1', 'STEP-2'] : [],
  links: id === 'php_in_uploads' ? [{ label: 'Vorlage', url: '#htaccess' }] : (id === 'security_headers' ? [{ label: 'Security Header öffnen', url: 'https://ex.test/wp-admin/admin.php?page=sfx-security-header' }] : []),
});
const CATALOGUE = [
  ['logs_public', 'security', 'S+B', 'browser'], ['config_copies', 'security', 'S', 'server'], ['php_in_uploads', 'security', 'S', 'loopback'],
  ['https', 'security', 'S+B', 'loopback'], ['security_headers', 'security', 'B', 'browser'], ['admin_accounts', 'security', 'S', 'server'],
  ['search_visibility', 'golive', 'S', 'server'], ['robots_txt', 'golive', 'B', 'browser'], ['permalinks', 'golive', 'S', 'server'],
  ['updates', 'cleanup', 'S', 'server'], ['table_prefix', 'cleanup', 'S', 'server'], ['inactive_plugins', 'cleanup', 'S', 'server'],
].map(([id, section, how, perspective]) => ({ id, section, how, perspective, title: 'T-' + id, ...guidance(id) }));

const STRINGS = {
  status: { red: 'Rot', yellow: 'Gelb', unknown: 'Nicht prüfbar', hint: 'Hinweis', green: 'Grün', pending: 'Noch nicht geprüft', checking: 'wird geprüft' },
  perspective: { server: 'Server', browser: 'Browser', loopback: 'Loopback' },
  tag: { running: 'vorläufig', unsaved: 'nicht gespeichert', unknown: 'Speicherstatus unbekannt' },
  findingOne: '%1$s Fund', findingMany: '%1$s Funde',
  why: 'Warum', recommendation: 'Empfehlung', steps: "So geht's", noAction: 'Nichts zu tun.',
  onlyAction: 'Nur Handlungsbedarf', notChecked: 'Noch nicht geprüft.', intro: 'INTRO', noActionTab: 'Kein Handlungsbedarf in diesem Bereich.',
  meta: 'Zuletzt: %1$s von %2$s, Profil %3$s.', nextProfile: 'Nächste Prüfung: Profil %1$s.', change: 'ändern',
  check: 'Prüfen', probe: 'Uploads-Test', sectionsTitle: 'Bereiche', urgentTitle: 'Am dringendsten', urgentNone: 'Nichts Dringendes.',
  running: 'PRÜFUNG LÄUFT', progress: '%1$s von %2$s', saving: 'Speichere', saved: 'GESPEICHERT', startFailed: 'START-FEHLER %1$s',
  notSaved: 'NICHT GESPEICHERT %1$s', unknownSave: 'SPEICHERSTATUS UNBEKANNT',
  statusStartFailed: 'START KURZ', statusNotSaved: 'NICHT GESPEICHERT KURZ', statusUnknown: 'UNBEKANNT KURZ', retry: 'Erneut speichern', recheck: 'Neu prüfen', reload: 'Seite neu laden',
  requestFailed: 'Anfrage fehlgeschlagen', timeout: 'Keine Antwort in 60 Sekunden', itemsTitle: 'Von Hand geprüft', itemDone: 'erledigt von %1$s am %2$s', itemsReset: 'Zurücksetzen', itemsResetConfirm: 'Sicher?',
  copied: 'Kopiert', copyFailed: 'KOPIEREN NICHT MÖGLICH', settingsDirty: 'nicht gespeichert', tablist: 'Bereiche',
};
const TABS = [
  { id: 'uebersicht', title: 'Übersicht' }, { id: 'sicherheit', title: 'Sicherheit', section: 'security' },
  { id: 'live-gang', title: 'Live-Gang', section: 'golive' }, { id: 'aufraeumen', title: 'Aufräumen', section: 'cleanup' },
  { id: 'htaccess', title: '.htaccess-Vorlage' }, { id: 'einstellungen', title: 'Einstellungen' },
];
const g = (status, findings = [], note = '', perspective = 'server') => ({ status, findings: findings.map(([s, label], i) => ({ id: 'f' + i, status: s, label })), perspective, note });

/** A deferred AJAX layer: every request waits until the test answers it (or answers at once via auto). */
function page({ hash = '', last = null, settingsProfile = 'live', items = [], auto = null, navigator = undefined } = {}) {
  doc = { activeElement: null, body: new El('body') };
  doc.createElement = (tag) => new El(tag);
  doc.createTextNode = (t) => new TextNode(t);
  doc.getElementById = (id) => find(doc.body, (e) => e.id === id);
  doc.querySelectorAll = (sel) => doc.body.querySelectorAll(sel);
  const app = doc.body.appendChild(new El('div')); app.id = 'sfx-sc-app';
  const tpl = app.appendChild(new El('div')); tpl.id = 'sfx-sc-panel-htaccess';
  const pre = tpl.appendChild(new El('pre')); pre.id = 'sfx-sc-tpl-x'; pre.textContent = 'BLOCK TEXT';
  const copy = tpl.appendChild(new El('button')); copy.className = 'button sfx-sc-copy'; copy.setAttribute('data-target', 'sfx-sc-tpl-x');
  doc.createRange = () => ({ selectNodeContents(n) { doc.selected = n; } });
  const set = app.appendChild(new El('div')); set.id = 'sfx-sc-panel-einstellungen';
  const form = set.appendChild(new El('form')); form.id = 'sfx-sc-settings';
  const input = form.appendChild(new El('input'));
  const save = form.appendChild(new El('button')); save.id = 'sfx-sc-settings-save';
  const dirty = form.appendChild(new El('span')); dirty.id = 'sfx-sc-settings-dirty';

  const win = { location: { hash, reload() { win.reloaded = true; } }, listeners: {}, reloaded: false, confirm: () => true,
    getSelection: () => ({ removeAllRanges() {}, addRange(r) { win.range = r; } }) };
  win.addEventListener = (t, fn) => { (win.listeners[t] = win.listeners[t] || []).push(fn); };
  win.go = (h) => { win.location.hash = h; (win.listeners.hashchange || []).forEach((fn) => fn({})); };

  const requests = [];
  class FormData { constructor() { this.f = {}; } append(k, v) { this.f[k] = String(v); } }
  const api = load({
    navigator,
    FormData,
    fetch: (url, init) => new Promise((resolve, reject) => {
      const req = { f: init.body.f, resolve, reject };
      requests.push(req);
      const a = auto && auto(req.f);
      if (a) {
        if (a === 'lost') reject(new Error('offline'));
        else resolve({ status: a[0], json: async () => a[1] });
      }
    }),
  });
  const answer = (req, status, body) => req.resolve({ status, json: async () => body });
  api.mount(doc, {
    ajaxUrl: '/ajax', nonce: 'n', catalogue: CATALOGUE, tabs: TABS,
    sections: [{ id: 'security', title: 'Sicherheit' }, { id: 'golive', title: 'Live-Gang' }, { id: 'cleanup', title: 'Aufräumen' }],
    profiles: { live: 'Live', staging: 'Staging', private: 'Privat' }, settingsProfile, last, items, currentUser: 'Dev', strings: STRINGS,
  }, win);
  const tab = (id) => doc.getElementById('sfx-sc-tab-' + id);
  const panel = (id) => doc.getElementById('sfx-sc-panel-' + id);
  const row = (id) => doc.getElementById('sfx-sc-check-' + id);
  const summary = (id) => row(id).children.find((c) => c.tagName === 'summary');
  const pending = (action) => requests.filter((r) => r.f.action === action && !r.done);
  const take = (action) => { const r = requests.find((x) => x.f.action === action && !x.done); assert.ok(r, 'a pending ' + action); r.done = true; return r; };
  const reply = (action, data, status = 200) => answer(take(action), status, status === 200 ? { success: true, data } : { success: false, data });
  const startButton = () => doc.getElementById('sfx-sc-start');
  const status = () => doc.getElementById('sfx-sc-status');
  const notice = () => doc.getElementById('sfx-sc-notice');
  return { api, win, requests, tab, panel, row, summary, reply, take, pending, startButton, status, notice, form, input, save, dirty, copy };
}
const ok = (data) => [200, { success: true, data }];
const settle = async () => { for (let i = 0; i < 40; i++) await flush(); };
const savedRun = (results, extra = {}) => ({ run: 'r0', date: 1, date_display: '10.10.2026, 23:59', user: 'Anna', profile: 'live', results, ...extra });
const STARTED = { run: 'r1', checks: CATALOGUE.map(({ id, how, perspective }) => ({ id, how, perspective })) };

// ---- six tabs, ARIA, roving tabindex, keyboard

{
  const p = page();
  const list = p.tab('uebersicht').parentNode;
  assert.equal(list.getAttribute('role'), 'tablist', 'the tab bar is a tablist');
  assert.equal(list.className.includes('nav-tab-wrapper'), true, 'in wp-admin\'s tab look');
  const ids = TABS.map((t) => t.id);
  assert.deepEqual(list.children.map((t) => t.id), ids.map((id) => 'sfx-sc-tab-' + id), 'six tabs in order');
  for (const id of ids) {
    assert.equal(p.tab(id).getAttribute('role'), 'tab', `${id}: role tab`);
    assert.equal(p.tab(id).getAttribute('aria-controls'), 'sfx-sc-panel-' + id, `${id}: aria-controls`);
    assert.equal(p.panel(id).getAttribute('role'), 'tabpanel', `${id}: role tabpanel`);
    assert.equal(p.panel(id).getAttribute('aria-labelledby'), 'sfx-sc-tab-' + id, `${id}: labelled by its tab`);
  }
  const selected = () => ids.filter((id) => p.tab(id).getAttribute('aria-selected') === 'true');
  const stops = () => ids.filter((id) => p.tab(id).getAttribute('tabindex') === '0');
  const shown = () => ids.filter((id) => !p.panel(id).hidden);
  assert.deepEqual([selected(), stops(), shown()], [['uebersicht'], ['uebersicht'], ['uebersicht']], 'no fragment → Übersicht selected, one tab stop, other panels hidden');
  assert.ok(ids.every((id) => p.tab(id).getAttribute('tabindex') === (id === 'uebersicht' ? '0' : '-1')), 'roving tabindex');
  p.tab('uebersicht').focus();
  p.tab('uebersicht').dispatch('keydown', { key: 'ArrowRight' });
  assert.deepEqual([selected(), shown(), doc.activeElement.id], [['sicherheit'], ['sicherheit'], 'sfx-sc-tab-sicherheit'], 'ArrowRight moves focus and selection');
  assert.equal(p.win.location.hash, '#sicherheit', 'the fragment follows the tab');
  p.tab('sicherheit').dispatch('keydown', { key: 'End' });
  assert.deepEqual([selected(), doc.activeElement.id], [['einstellungen'], 'sfx-sc-tab-einstellungen'], 'End → last tab');
  p.tab('einstellungen').dispatch('keydown', { key: 'ArrowRight' });
  assert.deepEqual(selected(), ['uebersicht'], 'ArrowRight wraps');
  p.tab('uebersicht').dispatch('keydown', { key: 'ArrowLeft' });
  assert.deepEqual(selected(), ['einstellungen'], 'ArrowLeft wraps');
  p.tab('einstellungen').dispatch('keydown', { key: 'Home' });
  assert.deepEqual([selected(), stops()], [['uebersicht'], ['uebersicht']], 'Home → first tab, tab stop follows');
  const e = p.tab('live-gang').click();
  assert.ok(e.defaultPrevented, 'a tab click is handled in place');
  assert.deepEqual([selected(), p.win.location.hash], [['live-gang'], '#live-gang'], 'click selects and updates the fragment');
  assert.ok(p.tab('htaccess').className.includes('nav-tab') && p.tab('live-gang').className.includes('nav-tab-active'), 'active tab marked the wp-admin way');
}

// ---- fragments on load and later

{
  let p = page({ hash: '#sicherheit' });
  assert.equal(p.tab('sicherheit').getAttribute('aria-selected'), 'true', '#sicherheit on load opens that tab');
  p = page({ hash: '#nonsense' });
  assert.equal(p.tab('uebersicht').getAttribute('aria-selected'), 'true', 'unknown fragment → Übersicht');
  p = page({ hash: '#check-https', last: savedRun({ https: g('green', [['green', 'ok']]) }) });
  assert.equal(p.tab('sicherheit').getAttribute('aria-selected'), 'true', '#check-https on load opens Sicherheit');
  assert.ok(p.row('https').open && doc.activeElement === p.summary('https'), '#check-https opens and focuses the row');

  // Gate B pass 1 (spec-13): a check link shows its row before the first run too.
  p = page({ hash: '#check-https', last: null });
  assert.ok(visible(p.row('https')) && p.row('https').open && doc.activeElement === p.summary('https'), '#check-https before any run shows, opens and focuses the row');
  p.win.go('#sicherheit');
  assert.ok(!visible(p.row('https')), 'back on the tab before any run: rows hidden again');

  p = page({ last: savedRun({ https: g('green', [['green', 'ok']]), logs_public: g('red', [['red', 'leak']]) }) });
  const filter = find(p.panel('sicherheit'), (e) => e.tagName === 'input' && e.type === 'checkbox');
  p.win.go('#sicherheit');
  filter.checked = true; filter.dispatch('change');
  assert.ok(!visible(p.row('https')), 'fixture: the filter hides the green https row');
  p.win.go('#check-https');
  assert.ok(visible(p.row('https')) && p.row('https').open && doc.activeElement === p.summary('https'), 'a later #check-https shows, opens and focuses the row despite the filter');
  p.row('https').open = false; p.row('https').dispatch('toggle');
  p.win.go('#sicherheit');
  assert.ok(!visible(p.row('https')) && filter.checked, 'Back to the tab: the filter applies again');
  assert.equal(doc.activeElement, p.tab('sicherheit'), 'Back to the tab: focus on the tab');
  p.win.go('#check-https');
  assert.ok(visible(p.row('https')) && p.row('https').open && doc.activeElement === p.summary('https'), 'Forward to the check link: shown, opened and focused again');
  filter.checked = false; filter.dispatch('change');
  filter.checked = true; filter.dispatch('change');
  assert.ok(!visible(p.row('https')), 'toggling "Nur Handlungsbedarf" clears the check link\'s override');
  p.win.go('#live-gang');
  assert.equal(p.tab('live-gang').getAttribute('aria-selected'), 'true', 'a later tab fragment selects that tab');
  // Gate B pass 5: inherited Object names are no tabs.
  for (const frag of ['#constructor', '#toString', '#check-constructor', '#__proto__']) {
    const q = page({ hash: frag });
    assert.equal(q.tab('uebersicht').getAttribute('aria-selected'), 'true', `${frag} on load → Übersicht`);
    q.win.go('#sicherheit');
    q.win.go(frag);
    assert.equal(q.tab('uebersicht').getAttribute('aria-selected'), 'true', `${frag} later → Übersicht`);
  }
  p.win.go('#check-nope');
  assert.equal(p.tab('uebersicht').getAttribute('aria-selected'), 'true', 'a later unknown fragment → Übersicht');
}

// ---- counting, rows, guidance

{
  const last = savedRun({
    logs_public: g('red', [['red', 'R1'], ['unknown', 'U1']], 'Notiz-logs'),
    config_copies: g('yellow', [['yellow', 'Y1']]),
    https: g('green', [['green', 'G1']]),
    security_headers: g('green', [['green', 'alles gesetzt']], 'Header-Notiz', 'browser'),
    php_in_uploads: g('yellow', [['yellow', '<img src=x onerror=alert(1)>']], '<script>n</script>', 'loopback'),
    table_prefix: g('hint', [['hint', 'wp_']]),
  });
  const p = page({ last, hash: '#sicherheit' });
  const badge = (id) => find(p.tab(id), (e) => e.className.includes('sfx-sc-tabcount'));
  assert.equal(badge('sicherheit').textContent, '1 Rot2 Gelb', 'tab badge counts checks: Rot+Nicht prüfbar findings count once as Rot');
  const filter = find(p.panel('sicherheit'), (e) => e.tagName === 'input' && e.type === 'checkbox');
  filter.checked = true; filter.dispatch('change');
  assert.equal(badge('sicherheit').textContent, '1 Rot2 Gelb', 'the filter changes no badge');
  assert.ok(!visible(p.row('https')) && visible(p.row('logs_public')), '"Nur Handlungsbedarf" hides Grün, keeps Rot');
  filter.checked = false; filter.dispatch('change');

  assert.ok(p.row('logs_public').open && p.row('php_in_uploads').open, 'Rot and Gelb rows open when they first get their result');
  assert.ok(!p.row('https').open && !p.row('table_prefix').open, 'other rows start closed');
  const head = p.summary('logs_public').textContent;
  assert.ok(head.includes('Rot') && head.includes('T-logs_public') && head.includes('2 Funde'), 'closed: status, title, finding count');

  const text = p.row('php_in_uploads').textContent;
  const order = ['<img src=x onerror=alert(1)>', '<script>n</script>', 'Warum', 'WHY-php_in_uploads', 'Empfehlung', 'REC-php_in_uploads', "So geht's", 'STEP-1', 'STEP-2'].map((t) => text.indexOf(t));
  assert.ok(order.every((i) => i >= 0), 'findings, note and guidance shown as text: ' + order);
  assert.deepEqual([...order].sort((a, b) => a - b), order, 'order: findings → note → Warum → Empfehlung → So geht\'s');
  const ol = find(p.row('php_in_uploads'), (e) => e.tagName === 'ol');
  assert.deepEqual(ol && ol.children.map((li) => li.textContent), ['STEP-1', 'STEP-2'], 'steps as a numbered list');
  const link = find(p.row('php_in_uploads'), (e) => e.tagName === 'a');
  assert.deepEqual([link.getAttribute('href'), link.textContent], ['#htaccess', 'Vorlage'], 'link to the .htaccess-Vorlage tab');
  const sh = find(p.row('security_headers'), (e) => e.tagName === 'a');
  assert.equal(sh.getAttribute('href'), 'https://ex.test/wp-admin/admin.php?page=sfx-security-header', 'module link as given by the server');

  assert.ok(findAll(p.row('php_in_uploads'), (e) => e.tagName === 'h3').length === 3 && !find(p.row('php_in_uploads'), (e) => e.tagName === 'h4'), 'guidance headings are h3');
  const h2 = find(p.panel('sicherheit'), (e) => e.tagName === 'h2');
  assert.ok(h2 && h2.textContent === 'Sicherheit', 'each section tab has its h2, so headings do not jump');
  const tp = p.row('table_prefix').textContent;
  assert.ok(tp.includes('WHY-table_prefix') && tp.includes('REC-table_prefix') && !find(p.row('table_prefix'), (e) => e.tagName === 'ol'), 'informational check: Warum and Empfehlung, no steps');
  const green = p.row('security_headers');
  assert.ok(green.textContent.includes('Nichts zu tun.') && green.textContent.includes('Header-Notiz'), 'a green row says no action is needed and keeps its note');
  assert.ok(!visible(find(green, (e) => e.textContent === 'REC-security_headers' && e.tagName === 'p')), 'a green row hides the fix');
  assert.ok(green.textContent.includes('Browser'), 'the note names its perspective');
}

// ---- rows updated in place; open/closed choice kept

{
  const p = page({ last: savedRun({ logs_public: g('red', [['red', 'old']]), https: g('green', [['green', 'g']]) }) });
  const logs = p.row('logs_public');
  logs.open = false; logs.dispatch('toggle');
  const https = p.row('https');
  https.open = true; https.dispatch('toggle');
  p.summary('https').focus();
  p.startButton().click();
  p.reply('sfx_site_check_start', STARTED);
  await settle();
  p.reply('sfx_site_check_targets', { targets: [], comparison: null, skipped: [] });
  await settle();
  const obs = p.requests.find((r) => r.f.action === 'sfx_site_check_observe' && !r.done);
  assert.ok(obs, 'a browser check reaches observe');
  obs.done = true;
  obs.resolve({ status: 200, json: async () => ({ success: true, data: { graded: g('red', [['red', 'new']]), token: 't' } }) });
  await settle();
  assert.equal(p.row('logs_public'), logs, 'the row element is kept, not replaced');
  assert.ok(!logs.open, 'a row the admin closed stays closed when another result arrives');
  assert.ok(https.open, 'an open Grün row stays open');
  assert.equal(doc.activeElement, p.summary('https'), 'focus survives updates');
}

// ---- run states: counts vs rows, one operation at a time

{
  const p = page({ last: savedRun({ logs_public: g('yellow', [['yellow', 'SAVED-Y']]) }), settingsProfile: 'live' });
  const badge = () => find(p.tab('sicherheit'), (e) => e.className.includes('sfx-sc-tabcount')).textContent;
  assert.equal(badge(), '1 Gelb', 'fixture: saved run Gelb');
  assert.equal(p.status().getAttribute('aria-live'), 'polite', 'the run status line is announced');
  p.input.dispatch('input');
  assert.equal(p.dirty.textContent, 'nicht gespeichert', 'an edited setting is marked unsaved');
  p.startButton().click();
  assert.ok(p.save.disabled, 'settings save disabled while a run is in progress');
  p.reply('sfx_site_check_start', { run: 'r1', checks: [{ id: 'logs_public', how: 'S+B', perspective: 'browser' }, { id: 'config_copies', how: 'S', perspective: 'server' }] });
  await settle();
  p.startButton().click();
  assert.equal(p.requests.filter((r) => r.f.action === 'sfx_site_check_start').length, 1, 'a second start while running does nothing');
  p.reply('sfx_site_check_targets', { targets: [], comparison: null, skipped: [] });
  await settle();
  p.reply('sfx_site_check_observe', { graded: g('red', [['red', 'INCOMING-R']]), token: 't1' });
  await settle();
  assert.equal(badge(), '1 Rot', 'while running, incoming results drive the counts');
  assert.ok(p.row('logs_public').textContent.includes('INCOMING-R') && p.row('logs_public').textContent.includes('vorläufig'), 'and the rows, marked in progress');
  assert.equal(p.status().textContent, 'PRÜFUNG LÄUFT', 'the announced status line says the run started and does not change per result');
  const progress = doc.getElementById('sfx-sc-progress');
  assert.ok(progress && progress.textContent === '1 von 2' && progress.getAttribute('aria-live') === null, 'progress is counted outside the live region');
  p.reply('sfx_site_check_server', { graded: g('green', []), token: 't2' });
  await settle();
  // Delayed refusal of the save (409).
  assert.ok(p.startButton().disabled && p.save.disabled, 'still one operation: start and settings save disabled while saving');
  p.reply('sfx_site_check_save', { message: 'Veraltet.' }, 409);
  await settle();
  assert.ok(p.notice().textContent.includes('NICHT GESPEICHERT Veraltet.'), 'refused save: notice says not saved');
  assert.equal(p.status().textContent, 'NICHT GESPEICHERT KURZ', 'refused save: the status line announces the state in short, not the notice text again');
  assert.equal(badge(), '1 Gelb', 'refused save: counts show the last saved run');
  const urgent = find(p.panel('uebersicht'), (e) => e.className.includes('sfx-sc-urgent'));
  assert.ok(urgent.textContent.includes('Gelb') && !urgent.textContent.includes('Rot'), 'refused save: the urgent list shows the last saved run');
  assert.ok(p.row('logs_public').textContent.includes('INCOMING-R') && p.row('logs_public').textContent.includes('nicht gespeichert'), 'refused save: incoming rows stay, marked unsaved');
  const recheck = byText(p.notice(), 'button', 'Neu prüfen');
  assert.ok(recheck && !byText(p.notice(), 'button', 'Erneut speichern'), 'not busy → "Neu prüfen", no retry');
  assert.ok(!p.save.disabled && !p.startButton().disabled, 'controls free again');
}

{
  // Busy save → retry; a deferred retry blocks everything; delayed success.
  const p = page({ last: null });
  p.startButton().click();
  p.reply('sfx_site_check_start', { run: 'r1', checks: [{ id: 'config_copies', how: 'S', perspective: 'server' }] });
  await settle();
  p.reply('sfx_site_check_server', { graded: g('red', [['red', 'X']]), token: 't' });
  await settle();
  p.reply('sfx_site_check_save', { message: 'Beschäftigt.' }, 503);
  await settle();
  const retry = byText(p.notice(), 'button', 'Erneut speichern');
  assert.ok(retry, 'busy save → "Erneut speichern"');
  const summary = find(p.panel('uebersicht'), (e) => e.className.includes('sfx-sc-summaries'));
  assert.ok(summary.textContent.includes('Noch nicht geprüft.'), 'no previous run: the summaries still say not checked');
  assert.ok(p.row('config_copies').textContent.includes('nicht gespeichert'), 'no previous run: incoming rows visible, unsaved');
  retry.click();
  assert.ok(retry.disabled && p.startButton().disabled && p.save.disabled, 'a retry in flight disables retry, start and settings save');
  p.startButton().click(); retry.click();
  assert.equal(p.requests.filter((r) => r.f.action === 'sfx_site_check_start').length, 1, 'no new run while the retry is in flight');
  assert.equal(p.pending('sfx_site_check_save').length, 1, 'exactly one save in flight');
  p.reply('sfx_site_check_save', { last: savedRun({ config_copies: g('yellow', [['yellow', 'STORED']]) }, { date_display: '11.10.2026, 00:01' }) });
  await settle();
  assert.equal(p.status().textContent, 'GESPEICHERT', 'delayed success → saved');
  assert.ok(p.row('config_copies').textContent.includes('STORED') && !p.row('config_copies').textContent.includes('nicht gespeichert'), 'on success the stored run is shown');
  assert.ok(p.panel('uebersicht').textContent.includes('11.10.2026, 00:01'), 'the server-formatted date is shown as is');
  assert.equal(p.notice().textContent, '', 'the notice is gone');
}

{
  // Lost answer → unknown; failed start is a different notice.
  let p = page({ last: savedRun({ logs_public: g('yellow', [['yellow', 'S']]) }), auto: (f) => (f.action === 'sfx_site_check_start' ? ok({ run: 'r', checks: [{ id: 'config_copies', how: 'S', perspective: 'server' }] }) : f.action === 'sfx_site_check_server' ? ok({ graded: g('green', []), token: 't' }) : 'lost') });
  p.startButton().click();
  await settle();
  assert.ok(p.notice().textContent.includes('SPEICHERSTATUS UNBEKANNT'), 'lost answer → save status unknown');
  assert.equal(p.status().textContent, 'UNBEKANNT KURZ', 'lost answer: short status, not the notice text again');
  const reload = byText(p.notice(), 'button', 'Seite neu laden');
  assert.ok(reload, 'offers reloading the page');
  reload.click();
  assert.ok(p.win.reloaded, 'reload button reloads');
  assert.ok(!p.notice().textContent.includes('NICHT GESPEICHERT'), 'no claim that it was not saved');

  p = page({ last: savedRun({ logs_public: g('yellow', [['yellow', 'SAVED-ROW']]) }), auto: (f) => [503, { success: false, data: { message: 'Belegt.' } }] });
  p.startButton().click();
  await settle();
  assert.ok(p.notice().textContent.includes('START-FEHLER Belegt.') && !p.notice().textContent.includes('SPEICHERSTATUS'), 'a failed start is its own notice');
  assert.equal(p.status().textContent, 'START KURZ', 'a failed start is announced in short');
  assert.ok(p.row('logs_public').textContent.includes('SAVED-ROW'), 'after a failed start the saved run is shown');
  assert.ok(!p.startButton().disabled, 'and a new start is possible');

  // Gate B pass 1 (spec-15): a start that gets no answer shows the localised text.
  p = page({ last: null, auto: () => 'lost' });
  p.startButton().click();
  await settle();
  assert.ok(p.notice().textContent.includes('START-FEHLER Anfrage fehlgeschlagen'), 'lost start → localised failure text, not English');
}

// ---- Übersicht

{
  const last = savedRun({
    logs_public: g('yellow'), config_copies: g('red'), php_in_uploads: g('yellow'), https: g('red'),
    search_visibility: g('red'), robots_txt: g('yellow'), updates: g('yellow'), table_prefix: g('hint'),
  }, { profile: 'staging' });
  const p = page({ last, settingsProfile: 'live', items: [{ id: 'two_factor', label: 'ITEM-2FA', done: false, meta: '' }] });
  const ov = p.panel('uebersicht');
  const urgent = find(ov, (e) => e.className.includes('sfx-sc-urgent'));
  const links = findAll(urgent, (e) => e.tagName === 'a');
  assert.deepEqual(links.map((a) => a.getAttribute('href')), ['#check-config_copies', '#check-https', '#check-search_visibility', '#check-logs_public', '#check-php_in_uploads'], 'at most five, Rot first, then catalogue order');
  const sums = findAll(find(ov, (e) => e.className.includes('sfx-sc-summaries')), (e) => e.tagName === 'a');
  assert.deepEqual(sums.map((a) => a.getAttribute('href')), ['#sicherheit', '#live-gang', '#aufraeumen'], 'each section summary opens its tab');
  assert.ok(sums[0].parentNode.textContent.includes('2 Rot') && sums[0].parentNode.textContent.includes('2 Gelb'), 'section summary counts');
  assert.ok(ov.textContent.includes('Profil Staging') && ov.textContent.includes('Nächste Prüfung: Profil Live'), 'last and next profile both shown');
  const change = byText(ov, 'a', 'ändern');
  assert.equal(change && change.getAttribute('href'), '#einstellungen', 'the next profile links to the settings');
  assert.ok(ov.textContent.includes('ITEM-2FA'), 'manual items in Übersicht');
  for (const id of ['sicherheit', 'live-gang', 'aufraeumen', 'htaccess', 'einstellungen']) {
    assert.ok(!p.panel(id).textContent.includes('ITEM-2FA'), `manual items not in ${id}`);
  }
}

// ---- empty states

{
  const p = page({ last: null, auto: (f) => (f.action === 'sfx_site_check_start' ? null : null) });
  for (const id of ['sicherheit', 'live-gang', 'aufraeumen']) {
    assert.ok(p.panel(id).textContent.includes('Noch nicht geprüft.'), `${id}: "Noch nicht geprüft" before the first run`);
    assert.ok(!visible(p.row(CATALOGUE.find((c) => c.section === { sicherheit: 'security', 'live-gang': 'golive', aufraeumen: 'cleanup' }[id]).id)), `${id}: no rows before the first run`);
  }
  // Gate B pass 2 (spec-13): the adopted panels say so too, with a working control.
  for (const id of ['htaccess', 'einstellungen']) {
    assert.ok(p.panel(id).textContent.includes('Noch nicht geprüft.'), `${id}: "Noch nicht geprüft" before the first run`);
    const b = byText(p.panel(id), 'button', 'Prüfen');
    assert.ok(b && !b.parentNode.hidden, `${id}: a shown "Prüfen" control`);
  }
  byText(p.panel('htaccess'), 'button', 'Prüfen').click();
  assert.equal(p.pending('sfx_site_check_start').length, 1, 'the .htaccess tab control starts a run');
  const fresh = page({ last: null, auto: (f) => null });
  byText(fresh.panel('einstellungen'), 'button', 'Prüfen').click();
  assert.equal(fresh.pending('sfx_site_check_start').length, 1, 'the settings tab control starts a run');
  const after = page({ last: savedRun({ updates: g('hint') }) });
  for (const id of ['htaccess', 'einstellungen']) {
    const b = byText(after.panel(id), 'button', 'Prüfen');
    assert.ok(!b || b.parentNode.hidden, `${id}: no fresh-site box once a run is saved`);
  }
  const button = byText(p.panel('live-gang'), 'button', 'Prüfen');
  assert.ok(button, 'each empty tab has the "Prüfen" control');
  assert.ok(button, 'each empty tab has the "Prüfen" control');
  const third = page({ last: null, auto: (f) => null });
  byText(third.panel('live-gang'), 'button', 'Prüfen').click();
  assert.equal(third.pending('sfx_site_check_start').length, 1, 'and it starts a run');

  const q = page({ last: savedRun({ updates: g('hint'), table_prefix: g('green'), inactive_plugins: g('green') }) });
  const filter = find(q.panel('aufraeumen'), (e) => e.tagName === 'input' && e.type === 'checkbox');
  filter.checked = true; filter.dispatch('change');
  assert.ok(q.panel('aufraeumen').textContent.includes('Kein Handlungsbedarf in diesem Bereich.'), 'the filter leaving a tab empty says so');
}

// ---- dates: the script never formats a date itself

{
  assert.ok(!/toLocale(?:Date|Time)?String|Intl\.DateTimeFormat|new Date\(/.test(SCRIPT), 'site-check.js formats no date');
  const p = page({ items: [{ id: 'two_factor', label: 'ITEM', done: false, meta: '' }] });
  const box = find(p.panel('uebersicht'), (e) => e.tagName === 'input' && e.type === 'checkbox' && e.parentNode.textContent.includes('ITEM'));
  box.checked = true; box.dispatch('change');
  p.reply('sfx_site_check_items', { items: { two_factor: { user: 7, date: 1, date_display: '10.10.2026, 23:59' } } });
  await settle();
  assert.ok(box.parentNode.parentNode.textContent.includes('erledigt von Dev am 10.10.2026, 23:59'), 'an item shows the server-formatted date');
}

// ---- Gate B pass 3: manual items serialise their writes (tick vs reset)

{
  const p = page({ items: [{ id: 'two_factor', label: 'ITEM-A', done: false, meta: '' }, { id: 'backup', label: 'ITEM-B', done: false, meta: '' }] });
  const ov = p.panel('uebersicht');
  const box = (label) => find(ov, (e) => e.tagName === 'input' && e.type === 'checkbox' && e.parentNode.textContent.includes(label));
  const reset = byText(ov, 'button', 'Zurücksetzen');
  box('ITEM-A').checked = true; box('ITEM-A').dispatch('change');
  assert.ok(reset.disabled && box('ITEM-B').disabled, 'while a tick is pending, reset and the other items are disabled');
  p.reply('sfx_site_check_items', { items: { two_factor: { user: 7, date: 1, date_display: 'D1' } } });
  await settle();
  assert.ok(!reset.disabled && !box('ITEM-B').disabled && !box('ITEM-A').disabled, 'after the answer everything is enabled again');
  reset.click();
  assert.ok(box('ITEM-A').disabled && box('ITEM-B').disabled && reset.disabled, 'while a reset is pending no item can be ticked');
  p.reply('sfx_site_check_items', { items: {} });
  await settle();
  assert.ok(!box('ITEM-A').checked && !box('ITEM-A').disabled, 'the reset answer clears and re-enables');
  assert.equal(box('ITEM-A').parentNode.parentNode.textContent.includes('erledigt'), false, 'and removes the done note');
}

// ---- Gate B pass 4: a graded row with no findings shows "0 Funde"

{
  const p = page({ last: savedRun({ https: g('green', []) }) });
  assert.ok(p.row('https').textContent.includes('0 Funde'), 'zero findings are counted on a graded row');
  const q = page({ last: null });
  assert.ok(!q.row('https').textContent.includes('Funde'), 'an ungraded row shows no count');
}

// ---- Gate B pass 9: copy falls back to a selection with a message

for (const [what, navigator] of [['no clipboard', {}], ['rejected', { clipboard: { writeText: () => Promise.reject(new Error('denied')) } }]]) {
  const p = page({ navigator });
  const copy = find(p.panel('htaccess'), (e) => e.className && e.className.includes('sfx-sc-copy'));
  copy.click();
  await settle();
  assert.equal(doc.selected && doc.selected.id, 'sfx-sc-tpl-x', `${what}: the block text is selected`);
  assert.ok(p.panel('htaccess').textContent.includes('KOPIEREN NICHT MÖGLICH'), `${what}: the localised message is shown`);
}
{
  let written = null;
  const p = page({ navigator: { clipboard: { writeText: async (t) => { written = t; } } } });
  find(p.panel('htaccess'), (e) => e.className && e.className.includes('sfx-sc-copy')).click();
  await settle();
  assert.equal(written, 'BLOCK TEXT', 'clipboard available: the block text is written');
  assert.ok(!p.panel('htaccess').textContent.includes('KOPIEREN NICHT MÖGLICH'), 'and no failure message');
}

console.log('site-check page: ok');
