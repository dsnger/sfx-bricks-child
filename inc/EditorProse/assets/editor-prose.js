/**
 * Editor Prose — mirror the frontend prose wrapper into the block editor canvas.
 *
 * Gives the canvas root the wrapper's classes and puts the prose CSS (compiled
 * and scoped by Bricks, see Payload.php) into the canvas document only. React
 * rewrites the root's class attribute (outline/focus/preview modes) and may
 * remount the root or replace the iframe, so one idempotent sync() runs on every
 * relevant mutation.
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

    // ponytail: runs on every admin-body mutation; it is two querySelector calls, cheap enough.
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
        new Observer(sync).observe(cdoc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
      }
      var rootEl = cdoc.querySelector('.is-root-container');
      if (!rootEl) {
        return;
      }
      ensureAssets(cdoc, config.css, config.links);
      ensureClasses(rootEl, config.classes);
    }

    new win.MutationObserver(sync).observe(doc.body, { childList: true, subtree: true });
    sync();
    return { sync: sync };
  }

  root.SFXEditorProse = { LAYER_ORDER: LAYER_ORDER, ensureClasses: ensureClasses, ensureAssets: ensureAssets, start: start };

  if (root.sfxEditorProseConfig) {
    start(root, root.sfxEditorProseConfig);
  }
})(window);
