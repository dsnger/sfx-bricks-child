/**
 * Editor Prose — mirror the frontend prose wrapper into the block editor canvas.
 *
 * Gives the canvas root the wrapper's classes and puts the prose CSS (compiled
 * and scoped by Bricks, see Payload.php) into the canvas document only. React
 * rewrites the root's and blocks' class attributes (outline/focus/preview modes) and may
 * remount the root or replace the iframe, so observer callbacks schedule one
 * idempotent sync() per animation frame (immediately where requestAnimationFrame is
 * unavailable); start-up and the iframe load call it directly.
 */
(function (root) {
  'use strict';

  // Same statement as assets/css/frontend/styles.css; tests/editor-prose-test.mjs pins it.
  var LAYER_ORDER = '@layer sfx.reset, sfx.utilities, sfx.components, sfx.theme;';
  var STYLE_ID = 'sfx-editor-prose';

  function ensureClasses(el, classes) {
    var changed = false;
    classes.forEach(function (c) {
      if (!el.classList.contains(c)) {
        el.classList.add(c);
        changed = true;
      }
    });
    return changed;
  }

  // Classic themes (Bricks has no theme.json): the editor wraps a wide/full block in an extra
  // div.wp-block[data-align] and leaves the align class off the block itself. Live, the block
  // carries alignwide/alignfull, so the prose class's rules for it only match once mirrored here.
  var ALIGNS = ['wide', 'full'];
  function mirrorAlign(rootEl) {
    var frames = rootEl.querySelectorAll('.wp-block[data-align]:not([data-block])');
    Array.prototype.forEach.call(frames, function (frame) {
      var align = frame.getAttribute('data-align');
      var block = frame.firstElementChild;
      if (!block || !block.hasAttribute('data-block')) {
        return;
      }
      // WordPress keeps the frame and the block across alignment changes (wide -> full, wide -> left),
      // so a class mirrored earlier must go when it no longer matches.
      ALIGNS.forEach(function (a) {
        if (a !== align && block.classList.contains('align' + a)) {
          block.classList.remove('align' + a);
        }
      });
      if (ALIGNS.indexOf(align) !== -1) {
        ensureClasses(block, ['align' + align]);
      }
    });
  }

  function ensureAssets(doc, css, links) {
    if (!doc.head || doc.getElementById(STYLE_ID)) {
      return false;
    }
    var style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = LAYER_ORDER + '\n' + css;
    doc.head.appendChild(style);
    links.forEach(function (href) {
      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.setAttribute('data-sfx-editor-prose', '');
      doc.head.appendChild(link);
    });
    return true;
  }

  function start(win, config) {
    var doc = win.document;
    var seenFrames = new WeakSet();
    var seenDocs = new WeakSet();

    function sync() {
      var frame = doc.querySelector('iframe[name="editor-canvas"]');
      if (!frame) {
        return;
      }
      if (!seenFrames.has(frame)) {
        seenFrames.add(frame);
        frame.addEventListener('load', sync);
      }
      var cdoc = frame.contentDocument;
      if (!cdoc || !cdoc.documentElement) {
        return;
      }
      if (!seenDocs.has(cdoc)) {
        seenDocs.add(cdoc);
        var Observer = (cdoc.defaultView || win).MutationObserver;
        new Observer(schedule).observe(cdoc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-align'] });
      }
      var rootEl = cdoc.querySelector('.is-root-container');
      if (!rootEl) {
        return;
      }
      ensureAssets(cdoc, config.css, config.links);
      ensureClasses(rootEl, config.classes);
      mirrorAlign(rootEl);
    }

    var pending = false;
    // Many class changes arrive in one burst while editing; run sync() at most once per frame.
    function schedule() {
      if (typeof win.requestAnimationFrame !== 'function') {
        sync();
        return;
      }
      if (pending) {
        return;
      }
      pending = true;
      win.requestAnimationFrame(function () {
        pending = false;
        sync();
      });
    }

    new win.MutationObserver(schedule).observe(doc.body, { childList: true, subtree: true });
    sync();
    return { sync: sync, schedule: schedule };
  }

  root.SFXEditorProse = { LAYER_ORDER: LAYER_ORDER, ensureClasses: ensureClasses, mirrorAlign: mirrorAlign, ensureAssets: ensureAssets, start: start };

  if (root.sfxEditorProseConfig) {
    start(root, root.sfxEditorProseConfig);
  }
})(window);
