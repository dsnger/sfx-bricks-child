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
  remove(c) { this.set = this.set.filter((x) => x !== c); }
}
class El {
  constructor(tag) { this.tagName = tag; this.classList = new ClassList(); this.children = []; this.attrs = {}; this.listeners = {}; this.id = ''; this.textContent = ''; }
  appendChild(c) { this.children.push(c); return c; }
  setAttribute(k, v) { this.attrs[k] = v; }
  addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  get firstElementChild() { return this.children[0] || null; }
  // Only the alignment-frame query is used on the root; the stand-in returns the frames it was given.
  querySelectorAll(sel) {
    assert.equal(sel, '.wp-block[data-align]:not([data-block])', 'querySelectorAll selector');
    return this.frames || [];
  }
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
  assert.equal(JSON.stringify(observers[1].opts), JSON.stringify({ childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-align'] }), '4: observer options');
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

// 7. With requestAnimationFrame, observer bursts coalesce to one sync per frame.
{
  const frames = [];
  const { win, doc, observers, api } = load({ requestAnimationFrame: (fn) => frames.push(fn) });
  api.start(win, { classes: ['brxe-text', 'prose'], css: '.p{}', links: [] });
  const frame = new El('iframe');
  const cdoc = new Doc();
  makeWin(cdoc, observers);
  cdoc.root = new El('div');
  frame.contentDocument = cdoc;
  doc.frame = frame;
  observers[0].cb();
  frames.shift()();
  assert.equal(observers.length, 2, '7: canvas observed');
  cdoc.root = new El('div');
  observers[0].cb();
  observers[0].cb();
  observers[0].cb();
  assert.equal(frames.length, 1, '7: three callbacks -> one frame');
  assert.deepEqual(cdoc.root.classList.set, [], '7: not synced before the frame runs');
  frames.shift()();
  assert.deepEqual(cdoc.root.classList.set, ['brxe-text', 'prose'], '7: frame syncs');
  observers[1].cb();
  assert.equal(frames.length, 1, '7: next callback queues exactly one new frame');
  observers[1].cb();
  observers[1].cb();
  observers[1].cb();
  observers[0].cb();
  assert.equal(frames.length, 1, '7: canvas + admin bursts -> still exactly one queued frame');
  cdoc.root = new El('div');
  frames.shift()();
  assert.deepEqual(cdoc.root.classList.set, ['brxe-text', 'prose'], '7: new root gets the classes when the frame runs');
  assert.equal(frames.length, 0, '7: no stray frames left');
}

// 8. mirrorAlign: wide/full frames put their align class on the block inside, swap it on change,
//    and leave other alignments and non-block children alone; a complete state causes no writes.
{
  const { api } = load();
  const frame = (align, child) => { const f = new El('div'); f.attrs['data-align'] = align; if (child) f.appendChild(child); return f; };
  const block = () => { const b = new El('figure'); b.attrs['data-block'] = 'x'; return b; };
  const wide = block(), full = block(), left = block(), plain = new El('figure');
  const root = new El('div');
  root.frames = [frame('wide', wide), frame('full', full), frame('left', left), frame('wide', plain), frame('wide', null)];
  api.mirrorAlign(root);
  assert.deepEqual(wide.classList.set, ['alignwide'], '8: wide mirrored');
  assert.deepEqual(full.classList.set, ['alignfull'], '8: full mirrored');
  assert.deepEqual(left.classList.set, [], '8: left untouched');
  assert.deepEqual(plain.classList.set, [], '8: child without data-block untouched');
  const adds = wide.classList.adds;
  api.mirrorAlign(root);
  assert.equal(wide.classList.adds, adds, '8: no write when complete (no observer loop)');
  root.frames[0].attrs['data-align'] = 'full';
  api.mirrorAlign(root);
  assert.deepEqual(wide.classList.set, ['alignfull'], '8: wide -> full swaps the class');
  root.frames[0].attrs['data-align'] = 'left';
  api.mirrorAlign(root);
  assert.deepEqual(wide.classList.set, [], '8: full -> left removes the mirrored class');
}

// 9. Wiring: sync() mirrors alignment on start, and an alignment-only change reaches it through the canvas observer.
{
  const { win, doc, observers, api } = load();
  api.start(win, { classes: ['brxe-text'], css: '', links: [] });
  const frame = new El('iframe');
  const cdoc = new Doc();
  makeWin(cdoc, observers);
  const fig = new El('figure');
  fig.attrs['data-block'] = 'x';
  const wrap = new El('div');
  wrap.attrs['data-align'] = 'wide';
  wrap.appendChild(fig);
  cdoc.root = new El('div');
  cdoc.root.frames = [wrap];
  frame.contentDocument = cdoc;
  doc.frame = frame;
  observers[0].cb();
  assert.deepEqual(fig.classList.set, ['alignwide'], '9: mirrored on first sync');
  wrap.attrs['data-align'] = 'full';
  observers[1].cb(); // canvas observer fires for the data-align attribute change
  assert.deepEqual(fig.classList.set, ['alignfull'], '9: data-align change re-mirrors via the observer');
}

console.log('editor-prose-test: PASS');
