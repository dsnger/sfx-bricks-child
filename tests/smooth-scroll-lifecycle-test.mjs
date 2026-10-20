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
 * The start itself (cases 7-15) is pinned too: Lenis must never take over a
 * scroll the browser is still running, because both then drive the page and it
 * jumps back and forth (visitessen, 2026-10-20). A trigger only starts it once
 * nothing has scrolled for QUIET_MS and no finger is down, and once running it
 * leaves every event it cannot cancel to the browser. The one exception is a
 * plain click on a same-page anchor link, which starts it at once (case 15). The clock and timers are
 * faked, so "still running" is a premise the test sets, as above; the browser
 * measurement that observed the bounce is recorded in the commit introducing
 * these cases.
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
function load({ onRaf, hash = '', anchors = !!hash } = {}) {
  const listeners = new Map();
  const frames = new Map();
  const timers = new Map();
  const docListeners = [];
  const scrollTos = [];
  const scrollOpts = [];
  let nextFrameId = 1;
  let nextTimerId = 1;
  let now = 0;
  let driverCalls = 0;
  let destroyCalls = 0;

  // One object serves as both the global and `window`, the way a classic script
  // sees a browser. Splitting them would couple this test to which access path
  // the module happens to use: `window.requestAnimationFrame(...)` is the same
  // call in a browser, and a stub that only answers the bare form would fail a
  // rewrite that is behaviour-preserving everywhere else.
  const win = {
    sfxSmoothScroll: { smoothWheel: true, anchors, anchorOffset: 100, easing: '', defaultEasing: '' },
    document: {
      readyState: 'loading',
      addEventListener(type, fn) {
        if (type === 'click') docListeners.push(fn);
      },
      getElementById: (id) => ({ id }),
    },
    location: {
      href: 'https://example.test/page' + hash,
      origin: 'https://example.test',
      pathname: '/page',
      search: '',
      hash,
    },
    URL,
    console,
    performance: { now: () => now },
    setTimeout(cb, ms) {
      const id = nextTimerId++;
      timers.set(id, { at: now + ms, cb });
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
    // Capture-aware, as in a browser: a listener is identified by its function
    // AND its capture flag, so removing it with the wrong flag leaves it in place.
    addEventListener(type, fn, opts) {
      if (!listeners.has(type)) listeners.set(type, []);
      const capture = opts === true || !!(opts && opts.capture);
      listeners.get(type).push({ fn, once: !!(opts && opts.once), capture });
    },
    removeEventListener(type, fn, opts) {
      const l = listeners.get(type);
      if (!l) return;
      const capture = opts === true || !!(opts && opts.capture);
      const i = l.findIndex((e) => e.fn === fn && e.capture === capture);
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

  win.Lenis = function Lenis(opts) {
    win.lenisOpts = opts;
    return {
      raf(time) {
        driverCalls++;
        if (onRaf) onRaf(win.sfxLenis, time);
      },
      destroy() {
        destroyCalls++;
      },
      scrollTo(target, opts) {
        scrollTos.push(target);
        scrollOpts.push(opts);
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
    /** Lenis exists once the module has started it. */
    isStarted: () => !!win.sfxLenis,
    scrollTos: () => scrollTos,
    scrollOpts: () => scrollOpts,
    dispatch(type, event = {}) {
      const l = listeners.get(type) || [];
      for (const entry of l.slice()) {
        if (entry.once) this.win.removeEventListener(type, entry.fn, { capture: entry.capture });
        entry.fn({ type, ...event });
      }
    },
    /**
     * A click on a link inside document, in DOM dispatch order: window capture,
     * then document, then window bubble. Each step reads its listener list when
     * it is reached, so a document listener added during window capture still
     * receives this click -- which is what the early anchor start relies on.
     */
    click(fields) {
      const event = {
        type: 'click', button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
        ...fields,
      };
      const onWindow = (capture) => (listeners.get('click') || [])
        .filter((e) => e.capture === capture)
        .forEach((e) => e.fn(event));
      onWindow(true);
      docListeners.slice().forEach((fn) => fn(event));
      onWindow(false);
      return event;
    },
    /** Move the clock, firing every timer that falls due on the way. */
    advance(ms) {
      const end = now + ms;
      for (;;) {
        const due = [...timers.entries()]
          .filter(([, t]) => t.at <= end)
          .sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        timers.delete(due[0]);
        now = due[1].at;
        due[1].cb();
      }
      now = end;
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

// 7. Nothing scrolling: load and a click start Lenis at once, as before. The
//    click matters: started at pointerdown, Lenis' own anchor handler is in
//    place for the click that follows.
{
  const m = load();
  m.dispatch('load');
  assert.ok(m.isStarted(), 'load with no scroll running starts Lenis at once');

  const c = load();
  c.dispatch('pointerdown');
  assert.ok(c.isStarted(), 'a click with no scroll running starts Lenis at once');
}

// 8. A wheel before load: the browser keeps the gesture until nothing has
//    scrolled for QUIET_MS (200 ms) -- wheel smoothing reports itself through
//    scroll events, not wheel events.
{
  const m = load();
  m.dispatch('wheel');
  assert.ok(!m.isStarted(), 'the first wheel does not hand the gesture to Lenis');
  m.advance(150);
  m.dispatch('scroll');
  m.advance(150);
  m.dispatch('load');
  assert.ok(!m.isStarted(), 'load during a running scroll does not start it either');
  m.advance(49);
  assert.ok(!m.isStarted(), 'still inside the quiet time');
  m.advance(1);
  assert.ok(m.isStarted(), 'starts once nothing has scrolled for 200 ms');
  assert.equal(m.listenerCount('scroll'), 0, 'and stops listening for scroll');
  assert.equal(m.pending(), 1, 'with exactly one loop');
}

// 9. Load while the page is still scrolling without a trigger (scrollbar,
//    restored position) waits as well -- and, no user trigger having come
//    first, keeps the one-time scroll to the URL anchor.
{
  const m = load({ hash: '#sec' });
  m.dispatch('scroll');
  m.advance(50);
  m.dispatch('load');
  assert.ok(!m.isStarted(), 'load 50 ms after a scroll waits');
  m.advance(150);
  assert.ok(m.isStarted(), 'and starts 200 ms after that scroll');
  m.flushFrame(16);
  assert.deepEqual(m.scrollTos(), [{ id: 'sec' }], 'the load-time hash scroll still runs');
}

// 10. A user scroll before the start skips the hash scroll, exactly as an
//     interaction-triggered start always did -- even when load came first.
{
  const m = load({ hash: '#sec' });
  m.dispatch('scroll');
  m.dispatch('load');
  m.dispatch('wheel');
  m.advance(200);
  assert.ok(m.isStarted(), 'started after the quiet time');
  m.flushFrame(16);
  assert.deepEqual(m.scrollTos(), [], 'no jump back to the anchor the user scrolled away from');
}

// 11. Touch: never while a finger is down -- Lenis would miss the touchstart and
//     read the first move as a jump from (0,0) -- and not during the momentum
//     that follows the release.
{
  const m = load();
  m.dispatch('touchstart', { touches: [{}] });
  m.advance(1000);
  assert.ok(!m.isStarted(), 'a finger held still does not start Lenis');
  m.dispatch('touchstart', { touches: [{}, {}] });
  m.dispatch('touchend', { touches: [{}] });
  m.advance(1000);
  assert.ok(!m.isStarted(), 'nor does one finger left on the screen');
  m.dispatch('touchend', { touches: [] });
  m.advance(100);
  m.dispatch('scroll');
  m.advance(199);
  assert.ok(!m.isStarted(), 'momentum scroll keeps the gesture native');
  m.advance(1);
  assert.ok(m.isStarted(), 'starts 200 ms after the momentum ends');
}

// 12. A cancelled touch counts as released.
{
  const m = load();
  m.dispatch('touchstart', { touches: [{}] });
  m.dispatch('touchcancel', { touches: [] });
  m.advance(200);
  assert.ok(m.isStarted(), 'touchcancel releases the hold');
}

// 13. Once running, Lenis leaves alone every event it cannot cancel: the browser
//     is already scrolling by it (the rest of a gesture that began earlier, or
//     events the page delivered late while busy), and smoothing it as well puts
//     two drivers on the page. Events it can cancel stay Lenis' own.
{
  const m = load();
  m.dispatch('load');
  const filter = m.win.lenisOpts.virtualScroll;
  assert.equal(typeof filter, 'function', 'Lenis gets the virtualScroll filter');
  assert.equal(filter({ event: { type: 'wheel', cancelable: false } }), false, 'an uncancelable wheel stays native');
  assert.equal(filter({ event: { type: 'touchmove', cancelable: false } }), false, 'an uncancelable touchmove stays native');
  assert.equal(filter({ event: { type: 'wheel', cancelable: true } }), true, 'a cancelable wheel is smoothed');
}

// 14. A touch that began before the module ran: its touchstart was never seen,
//     but the moves that follow show a finger down and a user scrolling -- so
//     no start while it is held, and no jump back to the URL anchor after it.
{
  const m = load({ hash: '#sec' });
  m.dispatch('touchmove', { touches: [{}] });
  m.dispatch('load');
  m.advance(1000);
  assert.ok(!m.isStarted(), 'a finger held since before the module ran blocks the start');
  m.dispatch('touchend', { touches: [] });
  m.advance(200);
  assert.ok(m.isStarted(), 'and the start follows its release');
  m.flushFrame(16);
  assert.deepEqual(m.scrollTos(), [], 'that touch counts as a user scroll: no hash scroll');
}

// 15. The one exception to the quiet time: a plain click on a same-page anchor
//     link while Lenis waits (Greptile on PR #66). Lenis starts in the capture
//     phase, so its own anchor handler -- added to document during that same
//     click -- takes it: preventDefault and a scroll with the offset instead of
//     a native jump. Any other click leaves the wait alone.
const anchorLink = (href) => ({ closest: () => ({ target: '', getAttribute: () => href }) });
{
  const m = load({ anchors: true });
  m.dispatch('wheel');
  assert.ok(!m.isStarted(), 'waiting after the wheel');
  const ev = m.click({ target: anchorLink('#sec') });
  assert.ok(m.isStarted(), 'an anchor click starts Lenis at once');
  assert.ok(ev.defaultPrevented, 'and Lenis takes that same click: no native jump');
  assert.deepEqual(m.scrollTos(), [{ id: 'sec' }], 'scrolling to the section');
  assert.equal(m.scrollOpts()[0].offset, -100, 'with the configured offset');
  assert.equal(m.listenerCount('click'), 0, 'and the early click listener is gone');
}
{
  const m = load({ anchors: true });
  m.dispatch('wheel');
  m.click({ target: { closest: () => null } });
  assert.ok(!m.isStarted(), 'a click on anything else does not cut the wait');
  m.click({ target: anchorLink('#sec'), ctrlKey: true });
  assert.ok(!m.isStarted(), 'nor does a modified click on an anchor');
  m.click({ target: anchorLink('/other#sec') });
  assert.ok(!m.isStarted(), 'nor a link to another page');
  m.dispatch('touchstart', { touches: [{}] });
  m.click({ target: anchorLink('#sec') });
  assert.ok(!m.isStarted(), 'nor an anchor click while a finger is down');
}
{
  const m = load();
  m.dispatch('wheel');
  m.click({ target: anchorLink('#sec') });
  assert.ok(!m.isStarted(), 'with anchor handling off a click changes nothing');
}
{
  // A click with no pointer or key event before it (element.click()) is still
  // the user's navigation: the URL's old hash must not pull the page back.
  const m = load({ hash: '#old' });
  m.dispatch('scroll');
  m.dispatch('load');
  m.click({ target: anchorLink('#sec') });
  assert.ok(m.isStarted(), 'the anchor click starts Lenis');
  m.flushFrame(16);
  assert.deepEqual(m.scrollTos(), [{ id: 'sec' }], 'to the clicked section only, no load-hash scroll after it');
}

console.log('OK');
