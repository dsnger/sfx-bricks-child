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
  if (cfg.anchors) {
    opts.anchors = { offset: cfg.anchorOffset || 0 };
  }

  var lenis = new window.Lenis(opts);
  window.sfxLenis = lenis;

  function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
  }

  requestAnimationFrame(raf);
})();
