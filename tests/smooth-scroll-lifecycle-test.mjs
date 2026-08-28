/**
 * Pin the SmoothScroll rAF loop's lifecycle: one loop, resumed after a
 * back/forward-cache restore, and never revived on a destroyed instance.
 *
 * The module stopped its loop on `pagehide`, which also fires when the page
 * enters the bfcache. On restore neither `load` nor its interaction triggers
 * fire again, so the loop stayed dead — and a dead loop is worse than no smooth
 * scroll, because Lenis keeps calling preventDefault() on wheel events it no
 * longer acts on. The page could not be scrolled at all (v0.22.3).
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. This is a wiring test. It drives the
 * module through a stubbed window, so the premise of the bug — that `load` does
 * not fire a second time on restore — is *set* by the stub rather than observed
 * in a browser. It catches the regression that is actually likely (someone drops
 * the `pageshow` handler, restores `{ once: true }` on `pagehide`, or removes the
 * `destroyed` guard); it cannot catch a browser changing its bfcache behaviour.
 * `tests/smooth-scroll-bfcache-test.html` is the manual counterpart that does
 * exercise real restores.
 *
 * Counting *pending* rAF callbacks is what makes "exactly one loop" observable:
 * the loop requests its next frame from inside its own callback, so after a
 * flushed frame exactly one request is outstanding. Two loops leave two — the
 * deterministic form of the per-frame driver count the HTML harness measures.
 *
 * Run: node tests/smooth-scroll-lifecycle-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const MODULE_PATH = join(
  dirname(dirname(fileURLToPath(import.meta.url))),
  'inc/SmoothScroll/assets/smooth-scroll.js'
);
const source = readFileSync(MODULE_PATH, 'utf8');

/**
 * Load the module into a fresh stubbed window and return the handles a test
 * needs. `onRaf` runs inside the fake Lenis' raf(), which is how the
 * destroy-from-inside-the-driver case is reproduced.
 */
function load({ onRaf } = {}) {
  const listeners = new Map();
  const frames = new Map();
  let nextFrameId = 1;
  let driverCalls = 0;
  let destroyCalls = 0;

  // One object serves as both the global and `window`, the way a classic script
  // sees a browser. Splitting them would couple this test to which access path
  // the module happens to use: `window.requestAnimationFrame(...)` is the same
  // call in a browser, and a stub that only answers the bare form would fail a
  // rewrite that is behaviour-preserving everywhere else.
  const win = {
    sfxSmoothScroll: { smoothWheel: true, anchors: false, easing: '', defaultEasing: '' },
    document: { readyState: 'loading' },
    console,
    addEventListener(type, fn, opts) {
      if (!listeners.has(type)) listeners.set(type, []);
      listeners.get(type).push({ fn, once: !!(opts && opts.once) });
    },
    removeEventListener(type, fn) {
      const l = listeners.get(type);
      if (!l) return;
      const i = l.findIndex((e) => e.fn === fn);
      if (i !== -1) l.splice(i, 1);
    },
    requestAnimationFrame(cb) {
      const id = nextFrameId++;
      frames.set(id, cb);
      return id;
    },
    cancelAnimationFrame(id) {
      frames.delete(id);
    },
  };
  win.window = win;
  win.globalThis = win;

  win.Lenis = function Lenis() {
    return {
      raf(time) {
        driverCalls++;
        if (onRaf) onRaf(win.sfxLenis, time);
      },
      destroy() {
        destroyCalls++;
      },
    };
  };

  runInContext(source, createContext(win));

  return {
    win,
    /** Requests outstanding right now — one per live loop. */
    pending: () => frames.size,
    listenerCount: (type) => (listeners.get(type) || []).length,
    driverCalls: () => driverCalls,
    destroyCalls: () => destroyCalls,
    dispatch(type, event = {}) {
      const l = listeners.get(type) || [];
      for (const entry of l.slice()) {
        if (entry.once) this.win.removeEventListener(type, entry.fn);
        entry.fn(event);
      }
    },
    /** Run every callback queued for one frame; they may queue the next. */
    flushFrame(time) {
      const due = [...frames.entries()];
      frames.clear();
      for (const [, cb] of due) cb(time);
    },
  };
}

/** Bring a freshly loaded module to a running loop. */
function started(opts) {
  const m = load(opts);
  m.dispatch('load', { type: 'load' });
  return m;
}

// 1. A normal load starts exactly one loop, and it keeps driving Lenis.
{
  const m = load();
  assert.equal(m.pending(), 0, 'nothing scheduled before load');
  m.dispatch('load', { type: 'load' });
  assert.equal(m.pending(), 1, 'load starts exactly one loop');
  m.flushFrame(16);
  assert.equal(m.driverCalls(), 1, 'the frame drives lenis.raf once');
  assert.equal(m.pending(), 1, 'and the loop requeues exactly one frame');
}

// 2. pagehide stops the loop — the bfcache entry as well as a real unload.
{
  const m = started();
  m.dispatch('pagehide', { persisted: true });
  assert.equal(m.pending(), 0, 'pagehide cancels the pending frame');
  m.flushFrame(32);
  assert.equal(m.driverCalls(), 0, 'a stopped loop drives nothing');
}

// 3. Only a *persisted* pageshow resumes it. A normal load fires pageshow too,
//    so the persisted check is the first of two defences against a second loop;
//    `rafId === null` is the other, and this pins both — the running case and
//    the stopped one.
{
  const m = started();
  m.dispatch('pageshow', { persisted: false });
  assert.equal(m.pending(), 1, 'a non-persisted pageshow adds no second loop');

  // The rafId guard on its own, with the loop still running: without it this
  // would leave two.
  m.dispatch('pageshow', { persisted: true });
  assert.equal(m.pending(), 1, 'a persisted pageshow adds none while one runs');
  m.flushFrame(40);
  assert.equal(m.driverCalls(), 1, 'one driver call per frame — still one loop');

  m.dispatch('pagehide', { persisted: true });
  m.dispatch('pageshow', { persisted: false });
  assert.equal(m.pending(), 0, 'and starts none when the loop is stopped');

  m.dispatch('pageshow', { persisted: true });
  assert.equal(m.pending(), 1, 'a persisted pageshow resumes the loop');
  m.flushFrame(48);
  assert.equal(m.driverCalls(), 2, 'and the resumed loop drives Lenis again');
}

// 4. Repeated back/forward cycles restart one loop, never a second. What
//    `{ once: true }` on pagehide would break is the *cancellation*: the listener
//    is gone after the first entry, so the second one leaves the loop running and
//    rafId non-null. The restore then finds the guard false and resumes nothing —
//    a page frozen from the second Back onwards, not a stacked loop.
{
  const m = started();
  for (const cycle of [1, 2, 3]) {
    m.dispatch('pagehide', { persisted: true });
    assert.equal(m.pending(), 0, `cycle ${cycle}: pagehide stops the loop`);
    m.dispatch('pageshow', { persisted: true });
    assert.equal(m.pending(), 1, `cycle ${cycle}: exactly one loop is restarted`);
  }
  m.flushFrame(64);
  assert.equal(m.driverCalls(), 1, 'one driver call per frame — a single loop');
  assert.equal(m.pending(), 1, 'and one outstanding request');
}

// 5. destroy() stops the loop and unregisters both lifecycle listeners, so no
//    later restore can revive a dead instance.
{
  const m = started();
  assert.equal(m.listenerCount('pagehide'), 1, 'pagehide listener registered');
  assert.equal(m.listenerCount('pageshow'), 1, 'pageshow listener registered');

  m.win.sfxLenis.destroy();
  assert.equal(m.destroyCalls(), 1, 'the wrapper delegates to the real destroy');
  assert.equal(m.pending(), 0, 'destroy stops the loop');
  assert.equal(m.listenerCount('pagehide'), 0, 'pagehide listener removed');
  assert.equal(m.listenerCount('pageshow'), 0, 'pageshow listener removed');

  m.dispatch('pageshow', { persisted: true });
  assert.equal(m.pending(), 0, 'a restore cannot revive a destroyed instance');
}

// 6. destroy() called synchronously from inside lenis.raf(). Cancelling only
//    reaches a callback that has not fired yet, so without a guard the loop
//    requeues itself after the instance is gone and drives it forever.
{
  let destroyed = false;
  const m = started({
    onRaf: (lenis) => {
      if (!destroyed) {
        destroyed = true;
        lenis.destroy();
      }
    },
  });
  m.flushFrame(80);
  assert.equal(m.pending(), 0, 'no frame is requeued after an in-driver destroy');
  m.flushFrame(96);
  assert.equal(m.driverCalls(), 1, 'and the destroyed instance is never driven again');
}

console.log('OK');
