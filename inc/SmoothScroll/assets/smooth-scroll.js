(function () {
  var cfg = window.sfxSmoothScroll || {};
  if (typeof window.Lenis !== 'function') {
    return;
  }

  function isValidEasing(raw) {
    var e = (raw || '').replace(/\s+/g, ' ').trim();
    if (!e || e.length > 200) {
      return false;
    }
    var allowed = /Math\.(min|max|pow|sqrt|cbrt|abs|sign|floor|ceil|round|trunc|exp|expm1|log|log2|log10|sin|cos|tan|asin|acos|atan|atan2|sinh|cosh|tanh|hypot|PI|E)\b/g;
    var stripped = e.replace(allowed, ' ');
    if (/[A-Za-z]\s*\(/.test(stripped)) {
      return false;
    }
    return /^[0-9t.,+\-*\/%()\s]*$/.test(stripped);
  }

  function buildEasing() {
    var expr = (cfg.easing || '').replace(/\s+/g, ' ').trim();
    var def = (cfg.defaultEasing || '').replace(/\s+/g, ' ').trim();
    if (!expr || expr === def) {
      return undefined;
    }
    if (!isValidEasing(cfg.easing)) {
      if (window.console) {
        console.warn('[sfx-smooth-scroll] easing rejected by validator, using default');
      }
      return undefined;
    }
    try {
      var fn = new Function('t', 'return (' + expr + ');');
      if (typeof fn === 'function' && Number.isFinite(fn(0)) && Number.isFinite(fn(1))) {
        return fn;
      }
    } catch (e) {
      /* fall through */
    }
    if (window.console) {
      console.warn('[sfx-smooth-scroll] invalid easing, using default');
    }
    return undefined;
  }

  var opts = {
    duration: cfg.duration,
    orientation: cfg.orientation,
    gestureOrientation: cfg.gestureOrientation,
    smoothWheel: cfg.smoothWheel,
    syncTouch: cfg.syncTouch,
    wheelMultiplier: cfg.wheelMultiplier,
    touchMultiplier: cfg.touchMultiplier,
    infinite: cfg.infinite,
  };

  var easing = buildEasing();
  if (easing) {
    opts.easing = easing;
  }
  // An event Lenis cannot cancel is already being scrolled by the browser
  // (the rest of a gesture that began before Lenis ran). Smoothing it as well
  // would make two drivers fight over the page, so leave it to the browser.
  opts.virtualScroll = function (data) {
    return data.event.cancelable !== false;
  };
  // Anchor handling is done by us below (see setupAnchors) instead of Lenis'
  // built-in `anchors` option, so we can support /#-prefixed links, a
  // consistent offset, cross-page links, and the initial load-time hash.

  function start(skipHashScroll) {
    var lenis = new window.Lenis(opts);
    window.sfxLenis = lenis;

    var rafId = null;
    var destroyed = false;

    function raf(time) {
      lenis.raf(time);
      // lenis.raf() runs Lenis' own subscribers, one of which may destroy the
      // instance; requeueing unconditionally would revive the loop on a dead
      // one, because stop() can only cancel a callback that has not fired yet.
      if (!destroyed) {
        rafId = requestAnimationFrame(raf);
      }
    }

    function stop() {
      if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
      }
    }

    // `pagehide` also fires when the page enters the bfcache, and neither
    // `load` nor our interaction triggers fire again on restore -- so without
    // this the loop stays dead for the rest of the page's life. A dead loop is
    // not "no smooth scroll": Lenis keeps preventDefault()-ing wheel events it
    // never acts on, which freezes the page entirely.
    function onPageShow(event) {
      if (event.persisted && rafId === null) {
        rafId = requestAnimationFrame(raf);
      }
    }

    // Cancel our loop whenever the instance is destroyed (e.g. by a page
    // transition library) or the page is being unloaded, so raf() does not
    // keep firing against a dead instance.
    var originalDestroy = lenis.destroy.bind(lenis);
    lenis.destroy = function () {
      destroyed = true;
      stop();
      window.removeEventListener('pagehide', stop);
      window.removeEventListener('pageshow', onPageShow);
      return originalDestroy();
    };

    // Not `{ once: true }`: every bfcache entry must stop the loop, so the
    // `rafId === null` guard above stays true and back/forward cycles never
    // stack a second loop on one Lenis instance.
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', onPageShow);

    rafId = requestAnimationFrame(raf);

    // -------------------------------------------------------------------------
    // Anchor handling: only intercept links whose target section exists on the
    // CURRENT page (same origin, path, and query). Same-page links (#x, /#x)
    // smooth-scroll without a reload; other-page or other-query links (e.g.
    // /#x from a subpage, /imprint#x, pagination ?beginPos=10#x) are left to
    // the browser. After navigation, the load-time hash handler scrolls.
    function setupAnchors(skipHashScroll) {
      // The setting is expressed as the space to leave ABOVE the target (e.g. to
      // clear a sticky header), matching the scroll-padding-top mental model.
      // Lenis adds `offset` to the destination scroll position, so a positive
      // value would overshoot (target ends up above the viewport top); negate it
      // so a positive setting produces a downward gap below the top.
      var anchorOffset = -(cfg.anchorOffset || 0);

      function normPath(path) {
        return path.replace(/\/+$/, '') || '/';
      }

      // Returns the in-page target element for an href, or null when the href
      // points to another page, origin, query string, or missing section.
      function resolveLocalTarget(href) {
        if (!href) {
          return null;
        }
        var url;
        try {
          url = new URL(href, window.location.href);
        } catch (e) {
          return null;
        }
        if (url.origin !== window.location.origin) {
          return null;
        }
        if (normPath(url.pathname) !== normPath(window.location.pathname)) {
          return null;
        }
        // Different query (pagination, filters, sort) is a real navigation.
        if (url.search !== window.location.search) {
          return null;
        }
        var id = url.hash ? url.hash.slice(1) : '';
        if (!id) {
          return null;
        }
        return document.getElementById(id);
      }

      document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
          return;
        }
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) {
          return;
        }
        if (link.target && link.target !== '_self') {
          return;
        }
        var href = link.getAttribute('href');
        if (!href || href.indexOf('#') === -1) {
          return;
        }
        var target = resolveLocalTarget(href);
        if (!target) {
          return;
        }
        event.preventDefault();
        lenis.scrollTo(target, { offset: anchorOffset });
      });

      if (!skipHashScroll && window.location.hash && window.location.hash.length > 1) {
        var loadTarget = resolveLocalTarget(window.location.hash);
        if (loadTarget) {
          requestAnimationFrame(function () {
            lenis.scrollTo(loadTarget, { offset: anchorOffset, immediate: false });
          });
        }
      }
    }

    if (cfg.anchors) {
      setupAnchors(skipHashScroll);
    }
  }

  // Defer instantiation + the rAF loop until the page has loaded, so Lenis does
  // not compete with critical rendering and inflate page-load metrics (its
  // every-frame rAF was the main cost). Falls back to the first real scroll
  // intent in case the user interacts before `load` fires.
  //
  // Lenis must never take over a scroll the browser is already running. The
  // event that would start it is one Lenis cannot see, so the browser claims
  // the whole gesture and Lenis can no longer cancel its events: both then
  // drive the page, which jumps back and drags. So a trigger starts Lenis only
  // once nothing has scrolled for QUIET_MS and no finger is down; until then
  // the browser scrolls natively. With nothing scrolling it starts at once.
  var QUIET_MS = 200;
  var started = false;
  // touchmove too: a touch that began before this script ran shows itself only
  // by its moves.
  var triggers = ['wheel', 'touchstart', 'touchmove', 'keydown', 'pointerdown'];
  var gestures = ['scroll', 'wheel', 'touchstart', 'touchmove', 'touchend', 'touchcancel'];
  var events = ['load', 'keydown', 'pointerdown'].concat(gestures);
  var lastGesture = -Infinity;
  var touching = false;
  var armed = false;
  var interacted = false;
  var timer = null;

  function startOnce() {
    if (started) {
      return;
    }
    started = true;
    clearTimeout(timer);
    events.forEach(function (ev) {
      window.removeEventListener(ev, onEvent);
    });
    // If a user interaction (not `load`) came first, the user is already
    // scrolling — skip the one-time hash scroll so we don't yank the viewport
    // back to the URL anchor they just scrolled away from. Click-based anchor
    // navigation is unaffected.
    start(interacted);
  }

  function settle() {
    timer = null;
    var wait = touching ? QUIET_MS : lastGesture + QUIET_MS - performance.now();
    if (wait > 0) {
      timer = setTimeout(settle, wait);
    } else {
      startOnce();
    }
  }

  function onEvent(event) {
    var type = event.type;
    if (type.indexOf('touch') === 0) {
      touching = event.touches.length > 0;
    }
    if (gestures.indexOf(type) !== -1) {
      lastGesture = performance.now();
    }
    if (type === 'load' || triggers.indexOf(type) !== -1) {
      armed = true;
      interacted = interacted || type !== 'load';
    }
    if (armed && timer === null) {
      settle();
    }
  }

  if (document.readyState === 'complete') {
    startOnce();
  } else {
    events.forEach(function (ev) {
      window.addEventListener(ev, onEvent, { passive: true });
    });
  }
})();
