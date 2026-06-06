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
      var fn = new Function('t', 'return (' + cfg.easing + ');');
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
  // Anchor handling is done by us below (see setupAnchors) instead of Lenis'
  // built-in `anchors` option, so we can support /#-prefixed links, a
  // consistent offset, cross-page links, and the initial load-time hash.

  var lenis = new window.Lenis(opts);
  window.sfxLenis = lenis;

  var rafId = null;

  function raf(time) {
    lenis.raf(time);
    rafId = requestAnimationFrame(raf);
  }

  function stop() {
    if (rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
  }

  // Cancel our loop whenever the instance is destroyed (e.g. by a page
  // transition library) or the page is being unloaded, so raf() does not
  // keep firing against a dead instance.
  var originalDestroy = lenis.destroy.bind(lenis);
  lenis.destroy = function () {
    stop();
    return originalDestroy();
  };

  window.addEventListener('pagehide', stop, { once: true });

  rafId = requestAnimationFrame(raf);

  // ---------------------------------------------------------------------------
  // Anchor handling: only intercept links whose target section exists on the
  // CURRENT page. Same-page links (#x, /#x on the matching page) smooth-scroll
  // without a reload; cross-page links (e.g. /#x from a subpage, /imprint#x)
  // are left to the browser to navigate normally, after which the load-time
  // hash handler below scrolls to the section once the new page has loaded.
  function setupAnchors() {
    var anchorOffset = cfg.anchorOffset || 0;

    function normPath(path) {
      return path.replace(/\/+$/, '') || '/';
    }

    // Returns the in-page target element for an href, or null when the href
    // points to another page/origin or to a section not present on this page.
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

    if (window.location.hash && window.location.hash.length > 1) {
      var loadTarget = resolveLocalTarget(window.location.hash);
      if (loadTarget) {
        requestAnimationFrame(function () {
          lenis.scrollTo(loadTarget, { offset: anchorOffset, immediate: false });
        });
      }
    }
  }

  if (cfg.anchors) {
    setupAnchors();
  }
})();
