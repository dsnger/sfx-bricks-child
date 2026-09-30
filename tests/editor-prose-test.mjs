/**
 * Pin the Editor Prose canvas script: classes added once and never looped on,
 * style + links inserted once per document in order, a root that mounts late is
 * picked up by the per-document observer, and a replaced iframe document is
 * handled. A wiring test against a stand-in DOM in a vm context; the browser
 * verification covers real React re-renders.
 *
 * Run: node tests/editor-prose-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const SCRIPT = readFileSync(join(ROOT, 'inc/EditorProse/assets/editor-prose.js'), 'utf8');

class ClassList {
  constructor() { this.set = []; this.adds = 0; }
  contains(c) { return this.set.includes(c); }
  add(c) { this.adds++; if (!this.set.includes(c)) this.set.push(c); }
}
class El {
  constructor(tag) { this.tagName = tag; this.classList = new ClassList(); this.children = []; this.attrs = {}; this.listeners = {}; this.id = ''; this.textContent = ''; }
  appendChild(c) { this.children.push(c); return c; }
  setAttribute(k, v) { this.attrs[k] = v; }
  addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
}
class Doc {
  constructor() { this.head = new El('head'); this.body = new El('body'); this.documentElement = new El('html'); this.root = null; this.frame = null; this.defaultView = null; }
  createElement(t) { return new El(t); }
  getElementById(id) { return this.head.children.find((c) => c.id === id) || null; }
  querySelector(sel) {
    if (sel === '.is-root-container') return this.root;
    if (sel === 'iframe[name="editor-canvas"]') return this.frame;
    return null;
  }
}
function makeWin(doc, observers) {
  const win = {
    document: doc,
    MutationObserver: class { constructor(cb) { this.cb = cb; } observe(target, opts) { observers.push({ target, opts, cb: this.cb }); } },
  };
  doc.defaultView = win;
  return win;
}
function load(extra = {}) {
  const observers = [];
  const doc = new Doc();
  const win = makeWin(doc, observers);
  Object.assign(win, extra);
  const ctx = createContext({ window: win, WeakSet });
  runInContext(SCRIPT, ctx);
  return { win, doc, observers, api: win.SFXEditorProse };
}

// 1. Layer order constant equals the theme's frontend declaration.
{
  const { api } = load();
  const styles = readFileSync(join(ROOT, 'assets/css/frontend/styles.css'), 'utf8');
  const first = styles.match(/@layer[^;{]+;/)[0];
  assert.equal(api.LAYER_ORDER, first, '1: layer order pinned to styles.css');
}

// 2. ensureClasses adds missing, keeps existing, reports no change when complete.
{
  const { api } = load();
  const el = new El('div');
  el.classList.add('is-root-container');
  assert.equal(api.ensureClasses(el, ['brxe-text', 'prose']), true, '2: first call changes');
  assert.deepEqual(el.classList.set, ['is-root-container', 'brxe-text', 'prose'], '2: classes added, existing kept');
  const adds = el.classList.adds;
  assert.equal(api.ensureClasses(el, ['brxe-text', 'prose']), false, '2: second call no change');
  assert.equal(el.classList.adds, adds, '2: no classList.add when complete (no observer loop)');
}

// 3. ensureAssets: style (layer order + css) first, then links in order; once per document.
{
  const { api } = load();
  const d = new Doc();
  assert.equal(api.ensureAssets(d, '.x{}', ['a.css', 'b.css']), true, '3: first insert');
  assert.equal(d.head.children.length, 3, '3: style + 2 links');
  assert.equal(d.head.children[0].id, 'sfx-editor-prose', '3: style first');
  assert.equal(d.head.children[0].textContent, api.LAYER_ORDER + '\n.x{}', '3: style text');
  assert.deepEqual(d.head.children.slice(1).map((l) => [l.rel, l.href]), [['stylesheet', 'a.css'], ['stylesheet', 'b.css']], '3: links in order');
  assert.equal(api.ensureAssets(d, '.x{}', ['a.css']), false, '3: second call no-op');
  assert.equal(d.head.children.length, 3, '3: nothing duplicated');
}

// 4. start(): no iframe -> nothing; iframe without root -> observer attached first; root mounting later is picked up.
{
  const { win, doc, observers, api } = load();
  const config = { classes: ['brxe-text', 'prose'], css: '.p{}', links: [] };
  const { sync } = api.start(win, config);
  assert.equal(observers.length, 1, '4: body observer only');
  assert.equal(observers[0].target, doc.body, '4: observes admin body');

  const frame = new El('iframe');
  const cdoc = new Doc();
  makeWin(cdoc, observers);
  frame.contentDocument = cdoc;
  doc.frame = frame;
  observers[0].cb(); // the admin-body observer notices the iframe mounting
  assert.equal(observers.length, 2, '4: canvas document observed before root exists');
  assert.equal(observers[1].target, cdoc.documentElement, '4: observes canvas documentElement');
  // JSON: the options object comes from the vm realm, so a strict deep-equal would fail on its prototype.
  assert.equal(JSON.stringify(observers[1].opts), JSON.stringify({ childList: true, subtree: true, attributes: true, attributeFilter: ['class'] }), '4: observer options');
  assert.equal(cdoc.head.children.length, 0, '4: nothing inserted without root');
  assert.equal((frame.listeners.load || []).length, 1, '4: load listener added once');

  cdoc.root = new El('div');
  observers[1].cb();
  assert.deepEqual(cdoc.root.classList.set, ['brxe-text', 'prose'], '4: late root gets classes');
  assert.equal(cdoc.head.children[0].id, 'sfx-editor-prose', '4: style inserted');
  sync();
  assert.equal(observers.length, 2, '4: no second observer for the same document');
  assert.equal((frame.listeners.load || []).length, 1, '4: no second load listener');
}

// 5. Replaced iframe document gets its own observer and assets; null contentDocument is tolerated.
{
  const { win, doc, observers, api } = load();
  const { sync } = api.start(win, { classes: ['brxe-text'], css: '', links: [] });
  const frame = new El('iframe');
  frame.contentDocument = null;
  doc.frame = frame;
  sync();
  assert.equal(observers.length, 1, '5: null document -> no observer');
  const d2 = new Doc();
  makeWin(d2, observers);
  d2.root = new El('div');
  frame.contentDocument = d2;
  frame.listeners.load[0]();
  assert.equal(observers.length, 2, '5: new document observed');
  assert.deepEqual(d2.root.classList.set, ['brxe-text'], '5: new document root classed');
  assert.equal(d2.head.children[0].id, 'sfx-editor-prose', '5: new document gets the style');

  // A second, different document in the same iframe (reload / device switch).
  const d3 = new Doc();
  makeWin(d3, observers);
  d3.root = new El('div');
  frame.contentDocument = d3;
  frame.listeners.load[0]();
  assert.equal(observers.length, 3, '5: replacement document observed');
  assert.deepEqual(d3.root.classList.set, ['brxe-text'], '5: replacement root classed');
  assert.equal(d3.head.children[0].id, 'sfx-editor-prose', '5: replacement document gets the style');
  assert.equal(d2.head.children.length, 1, '5: old document untouched by the replacement');
}

// 6. Auto-start only with config present.
{
  const { observers } = load();
  assert.equal(observers.length, 0, '6: no config -> not started');
  const started = load({ sfxEditorProseConfig: { classes: [], css: '', links: [] } });
  assert.equal(started.observers.length, 1, '6: config -> started');
}

console.log('editor-prose-test: PASS');
