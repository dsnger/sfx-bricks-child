/**
 * Sicherheits-Check — the browser fetcher (spec "Results", evidence rules 2,
 * 6, 8 and 9). Plain script, no build step; exposes window.SfxSiteCheck.
 *
 * Every outside fetch: credentials omitted, redirects never followed
 * (`redirect: 'manual'`), at most 4 in flight, aborted after 10 s, body read
 * through a stream reader and cut after 64 KB. The URLs come from the server
 * (`sfx_site_check_targets`); the browser only adds the cache-buster. What it
 * saw goes once to `sfx_site_check_observe`, which answers `{graded, facts}`;
 * the raw observation is not kept.
 *
 * Observation: {url, status, type, headers, body, head_hex, truncated,
 * redirect, error} — error is '' | 'timeout' | 'network'.
 *
 * The manual run (createRunner): start → every check as its own request,
 * at most 4 at a time → one save carrying each check's sealed `token` (or
 * the failure kind), never facts or observations.
 *
 * The page (mount): a verdict panel and three tabs (spec "Page layout and guidance"), one
 * operation at a time, rows updated in place. Everything a check reports,
 * and the catalogue's guidance, reaches the page through textContent and
 * createElement only; dates arrive formatted by the server.
 */
(function (root) {
  'use strict';

  var LIMIT = 65536;
  var HEAD_BYTES = 512;
  var TIMEOUT_MS = 10000;
  var PARALLEL = 4;
  /** Loopback checks make the server fetch itself; fewer at once spare small hosts. */
  var LOOPBACK_PARALLEL = 2;
  /** The active probe runs alone, after every other check has settled. */
  var PROBE = 'php_in_uploads';
  var BUSTER = 'sfxcb';
  var MAX_HEADERS = 50;
  var MAX_HEADER_LENGTH = 1024;
  /** Security headers are judged by content, so they travel whole up to 8 KB. */
  var LONG_HEADERS = ['content-security-policy', 'content-security-policy-report-only', 'permissions-policy', 'strict-transport-security', 'referrer-policy', 'x-frame-options', 'x-content-type-options'];
  var MAX_LONG_HEADER_LENGTH = 8192;
  var AJAX_TIMEOUT_MS = 60000;
  /** Spec "Sections": Rot → Gelb → Nicht prüfbar → Hinweis → Grün. */
  var ORDER = ['red', 'yellow', 'unknown', 'hint', 'green'];

  function own(map, key) {
    return Object.prototype.hasOwnProperty.call(map, key);
  }

  function hexOf(bytes) {
    var out = '';
    for (var i = 0; i < bytes.length; i++) {
      out += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
    }
    return out;
  }

  function headersOf(headers) {
    var out = {};
    var count = 0;
    if (headers && typeof headers.forEach === 'function') {
      headers.forEach(function (value, name) {
        if (count < MAX_HEADERS) {
          var key = String(name).toLowerCase();
          // One character over the limit, so the server can tell a cut value from a whole one.
          var limit = (LONG_HEADERS.indexOf(key) !== -1 ? MAX_LONG_HEADER_LENGTH : MAX_HEADER_LENGTH) + 1;
          out[key] = String(value).slice(0, limit);
          count++;
        }
      });
    }
    return out;
  }

  /** Reads at most LIMIT bytes; one byte more marks the body truncated. */
  async function readBounded(body) {
    var bytes = new Uint8Array(0);
    var truncated = false;
    if (!body || typeof body.getReader !== 'function') {
      return { bytes: bytes, truncated: truncated };
    }
    var reader = body.getReader();
    var chunks = [];
    var total = 0;
    for (;;) {
      var step = await reader.read();
      if (step.done) {
        break;
      }
      var chunk = step.value || new Uint8Array(0);
      chunks.push(chunk);
      total += chunk.length;
      if (total > LIMIT) {
        truncated = true;
        try { await reader.cancel(); } catch (e) { /* already closed */ }
        break;
      }
    }
    bytes = new Uint8Array(Math.min(total, LIMIT));
    var offset = 0;
    for (var i = 0; i < chunks.length && offset < bytes.length; i++) {
      var part = chunks[i].subarray(0, bytes.length - offset);
      bytes.set(part, offset);
      offset += part.length;
    }
    return { bytes: bytes, truncated: truncated };
  }

  function bust(url, token) {
    return url + (url.indexOf('?') === -1 ? '?' : '&') + BUSTER + '=' + token;
  }

  /**
   * @param {object} [options] ajaxUrl and nonce for runCheck(); FormData for tests.
   */
  function createFetcher(options) {
    options = options || {};
    var doFetch = function (url, init) { return root.fetch(url, init); };
    var FormDataCtor = options.FormData || root.FormData;
    /** Visible failure texts, localised by PHP (script_data). */
    var texts = options.strings || {};
    var active = 0;
    var waiting = [];

    function acquire() {
      if (active < PARALLEL) {
        active++;
        return Promise.resolve();
      }
      return new Promise(function (resolve) { waiting.push(resolve); });
    }

    function release() {
      var next = waiting.shift();
      if (next) {
        next();
      } else {
        active--;
      }
    }

    async function fetchOne(url) {
      var obs = { url: url, status: 0, type: '', headers: {}, body: '', head_hex: '', truncated: false, redirect: false, error: '' };
      var controller = new root.AbortController();
      var timedOut = false;
      var timer = root.setTimeout(function () { timedOut = true; controller.abort(); }, TIMEOUT_MS);
      try {
        var res = await doFetch(url, {
          method: 'GET',
          credentials: 'omit',
          redirect: 'manual',
          cache: 'no-store',
          signal: controller.signal
        });
        obs.type = String(res.type || '');
        obs.status = Number(res.status) || 0;
        // redirect:'manual' hides the target: status 0, no headers, no body.
        if (obs.type === 'opaqueredirect' || (obs.status >= 300 && obs.status < 400)) {
          obs.redirect = true;
          return obs;
        }
        obs.headers = headersOf(res.headers);
        var read = await readBounded(res.body);
        obs.truncated = read.truncated;
        obs.head_hex = hexOf(read.bytes.subarray(0, HEAD_BYTES));
        obs.body = new root.TextDecoder('utf-8').decode(read.bytes);
      } catch (e) {
        obs = { url: url, status: 0, type: '', headers: {}, body: '', head_hex: '', truncated: false, redirect: false, error: timedOut ? 'timeout' : 'network' };
      } finally {
        root.clearTimeout(timer);
      }
      return obs;
    }

    /** One bounded fetch, queued behind the 4-in-flight limit. */
    async function observe(url) {
      await acquire();
      try {
        return await fetchOne(url);
      } finally {
        release();
      }
    }

    /** Each target twice (cache-buster and plain), the comparison URL once. */
    function batch(plan) {
      var urls = [];
      (plan.targets || []).forEach(function (target) {
        urls.push(bust(target.url, Math.random().toString(36).slice(2, 12) || '0'));
        urls.push(target.url);
      });
      if (plan.comparison) {
        urls.push(plan.comparison);
      }
      return Promise.all(urls.map(observe));
    }

    /**
     * One AJAX call. Rejects with err.lost when no readable answer arrived
     * (transport failure, timeout — err.timeout — or a body that is no JSON),
     * or with err.refused and err.status when the server answered
     * success:false (err.busy on HTTP 503).
     */
    async function post(action, fields) {
      var form = new FormDataCtor();
      form.append('action', action);
      form.append('_ajax_nonce', options.nonce);
      Object.keys(fields).forEach(function (key) { form.append(key, fields[key]); });
      var controller = new root.AbortController();
      var timedOut = false;
      var timer = root.setTimeout(function () { timedOut = true; controller.abort(); }, AJAX_TIMEOUT_MS);
      var json;
      var status = 0;
      try {
        var res = await doFetch(options.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form, signal: controller.signal });
        status = Number(res.status) || 0;
        json = await res.json();
      } catch (e) {
        var failure = new Error(timedOut ? (texts.timeout || 'timeout') : (texts.requestFailed || 'request failed'));
        failure.timeout = timedOut;
        failure.lost = true;
        throw failure;
      } finally {
        root.clearTimeout(timer);
      }
      if (!json || json.success !== true) {
        var refused = new Error((json && json.data && json.data.message) || texts.requestFailed || 'request failed');
        refused.refused = true;
        refused.status = status;
        refused.busy = status === 503;
        throw refused;
      }
      return json.data;
    }

    /** targets → bounded fetches → observe; resolves to {graded, facts}. */
    async function runCheck(run, check) {
      var plan = await post('sfx_site_check_targets', { run: run, check: check });
      var observations = await batch(plan);
      return post('sfx_site_check_observe', { run: run, check: check, observations: JSON.stringify(observations) });
    }

    return { observe: observe, batch: batch, runCheck: runCheck, post: post };
  }

  function statusOf(graded) {
    return graded && ORDER.indexOf(graded.status) !== -1 ? graded.status : null;
  }

  /** Checks of one section by status, catalogue order within a status, not yet graded last. */
  function sortChecks(list, results) {
    var rank = function (check) {
      var status = statusOf(results[check.id]);
      return status === null ? ORDER.length : ORDER.indexOf(status);
    };
    return list
      .map(function (check, index) { return { check: check, index: index, rank: rank(check) }; })
      .sort(function (a, b) { return a.rank - b.rank || a.index - b.index; })
      .map(function (entry) { return entry.check; });
  }

  /**
   * @param {object} opts post(action, fields), runCheck(run, id), strings.requestFailed
   */
  function createRunner(opts) {
    var strings = opts.strings || {};

    /**
     * Resolves to the save answer; rejects when start or save fails, with
     * err.stage 'start' or 'save'. A busy save rejects with err.retry(),
     * which resends the same results.
     */
    async function run(probe, hooks) {
      hooks = hooks || {};
      var started;
      try {
        started = await opts.post('sfx_site_check_start', { probe: probe ? '1' : '0' });
      } catch (e) {
        e.stage = 'start';
        throw e;
      }
      if (hooks.started) {
        hooks.started(started);
      }
      var results = {};
      var queue = started.checks.filter(function (check) { return check.id !== PROBE; });
      var probeChecks = started.checks.filter(function (check) { return check.id === PROBE; });
      var loopback = 0;
      var waiting = [];

      /** The next check a free slot may start: a Loopback check only while fewer than 2 run. */
      function next() {
        for (var i = 0; i < queue.length; i++) {
          if (queue[i].perspective !== 'loopback' || loopback < LOOPBACK_PARALLEL) {
            return queue.splice(i, 1)[0];
          }
        }
        return null;
      }

      async function runOne(check) {
        var graded;
        try {
          var data = check.how === 'S'
            ? await opts.post('sfx_site_check_server', { run: started.run, check: check.id })
            : await opts.runCheck(started.run, check.id);
          results[check.id] = { token: String(data.token || '') };
          graded = data.graded;
        } catch (e) {
          results[check.id] = { error: e && e.timeout ? 'timeout' : 'request_failed' };
          graded = {
            status: 'unknown',
            findings: [],
            perspective: check.perspective || (check.how === 'S' ? 'server' : 'browser'),
            note: strings.requestFailed || 'Request failed.'
          };
        }
        if (hooks.result) {
          hooks.result(check, graded);
        }
      }

      async function worker() {
        while (queue.length) {
          var check = next();
          if (check === null) {
            // Only Loopback checks are left and 2 run: wait for one to settle.
            await new Promise(function (resolve) { waiting.push(resolve); });
            continue;
          }
          var isLoopback = check.perspective === 'loopback';
          if (isLoopback) {
            loopback++;
          }
          try {
            await runOne(check);
          } finally {
            if (isLoopback) {
              loopback--;
            }
            waiting.splice(0).forEach(function (resolve) { resolve(); });
          }
        }
      }

      var workers = [];
      for (var i = 0; i < PARALLEL; i++) {
        workers.push(worker());
      }
      await Promise.all(workers);
      for (var p = 0; p < probeChecks.length; p++) {
        await runOne(probeChecks[p]);
      }
      var fields = { run: started.run, results: JSON.stringify(results) };
      var save = function () { return opts.post('sfx_site_check_save', fields); };
      try {
        return await save();
      } catch (e) {
        e.stage = 'save';
        if (e.busy) {
          e.retry = save;
        }
        throw e;
      }
    }

    return { run: run };
  }

  function el(doc, tag, className, text) {
    var node = doc.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  function format(template, values) {
    return String(template || '').replace(/%(\d)\$s/g, function (m, n) {
      var value = values[Number(n) - 1];
      return value === undefined ? '' : String(value);
    });
  }

  /** Area of each check by section; the old section fragments open Ergebnis filtered to that area. */
  var AREA_FRAGMENTS = { sicherheit: 'security', 'live-gang': 'golive', aufraeumen: 'cleanup' };
  /** Result groups (Site-Health pattern, owner ruling 2026-10-11): to do, check yourself, in order. */
  var GROUPS = { red: 'act', yellow: 'act', unknown: 'self', hint: 'fine', green: 'fine' };
  /** Finding glyphs: neutral dashicons, never the check's traffic light (owner ruling 2026-10-11). */
  var FINDING_ICON = { red: 'warning', yellow: 'warning', unknown: 'editor-help', hint: 'info-outline', green: 'yes' };

  /** Guidance block of one check: Warum, Empfehlung, So geht's (catalogue text only; no site data beyond WP Optimizer switch states). */
  /** A copy button for a text block: clipboard, else select the text and say how to copy it by hand. */
  function wireCopy(doc, win, strings, copy, getSource) {
    var message = null;
    function fallback(source) {
      if (doc.createRange && win.getSelection) {
        var range = doc.createRange();
        range.selectNodeContents(source);
        var selection = win.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      }
      if (!message) {
        message = el(doc, 'span', 'sfx-sc-copy-msg');
        message.setAttribute('role', 'status');
        copy.parentNode.appendChild(message);
      }
      message.textContent = ' ' + (strings.copyFailed || '');
    }
    copy.addEventListener('click', function () {
      var source = getSource();
      if (!source) {
        return;
      }
      var clipboard = root.navigator && root.navigator.clipboard;
      if (!clipboard || typeof clipboard.writeText !== 'function') {
        fallback(source);
        return;
      }
      clipboard.writeText(source.textContent).then(function () {
        copy.textContent = strings.copied;
      }, function () {
        fallback(source);
      });
    });
  }

  function renderGuidance(doc, check, strings, win) {
    var box = el(doc, 'div', 'sfx-sc-guidance');
    var why = el(doc, 'div', 'sfx-sc-why');
    why.appendChild(el(doc, 'h4', null, strings.why));
    why.appendChild(el(doc, 'p', null, check.why));
    box.appendChild(why);
    var fix = el(doc, 'div', 'sfx-sc-fix');
    fix.appendChild(el(doc, 'h4', null, strings.recommendation));
    fix.appendChild(el(doc, 'p', null, check.recommendation));
    var steps = Array.isArray(check.steps) ? check.steps : [];
    var links = Array.isArray(check.links) ? check.links : [];
    if (steps.length || links.length) {
      fix.appendChild(el(doc, 'h4', null, strings.steps));
      if (steps.length) {
        var ol = el(doc, 'ol', 'sfx-sc-steps');
        steps.forEach(function (step) {
          if (typeof step === 'string') {
            ol.appendChild(el(doc, 'li', null, step));
            return;
          }
          // A step with a value to copy as a whole: the explanation, then the value as a block.
          var li = ol.appendChild(el(doc, 'li'));
          li.appendChild(el(doc, 'p', null, step.text));
          var pre = li.appendChild(el(doc, 'pre', 'sfx-sc-template', step.code));
          var p = li.appendChild(el(doc, 'p'));
          var copy = p.appendChild(el(doc, 'button', 'button sfx-sc-copy', strings.copy));
          copy.type = 'button';
          wireCopy(doc, win, strings, copy, function () { return pre; });
        });
        fix.appendChild(ol);
      }
      if (links.length) {
        var ul = el(doc, 'ul', 'sfx-sc-links');
        links.forEach(function (link) {
          var a = el(doc, 'a', null, link.label);
          a.setAttribute('href', String(link.url));
          var li = el(doc, 'li');
          li.appendChild(a);
          ul.appendChild(li);
        });
        fix.appendChild(ul);
      }
    }
    box.appendChild(fix);
    var none = el(doc, 'p', 'sfx-sc-noaction', strings.noAction);
    none.hidden = true;
    box.appendChild(none);
    return { box: box, fix: fix, none: none };
  }

  /**
   * Wires the check page (Tools → Sicherheits-Check) in WordPress' own
   * "Website-Zustand" grammar: one verdict for the site with the run controls,
   * then the tabs Ergebnis | .htaccess-Vorlage | Einstellungen. Ergebnis lists
   * every check by what to do — Zu erledigen, Selbst prüfen, In Ordnung
   * (folded) — each a closed disclosure whose one traffic light is the check's
   * summary; its findings inside are a neutral detail list. The .htaccess
   * template and the settings form come from PHP and are adopted as panels.
   * Every write to the DOM is text or createElement.
   *
   * @param {object} [win] window (location, hashchange, confirm); tests pass a fake
   */
  function mount(doc, config, win) {
    win = win || root;
    var app = doc.getElementById('sfx-sc-app');
    if (!app) {
      return;
    }
    var strings = config.strings || {};
    var labels = strings.status || {};
    var catalogue = config.catalogue || [];
    var tabs = config.tabs || [];
    var fetcher = createFetcher({ ajaxUrl: config.ajaxUrl, nonce: config.nonce, strings: strings });
    var runner = createRunner({ post: fetcher.post, runCheck: fetcher.runCheck, strings: strings });

    var byId = {};
    catalogue.forEach(function (check) { byId[check.id] = check; });
    var sectionTitle = {};
    (config.sections || []).forEach(function (s) { sectionTitle[s.id] = s.title; });

    /** The last saved run as the page shows it: {results, date_display, user, profile}. */
    var saved = config.last || null;
    /** Results of the run in progress, or of the last run whose save failed. */
    var incoming = null;
    /** idle | running | saving | refused | unknown */
    var phase = 'idle';
    var busy = false;
    var op = 0;
    var retrySave = null;
    var progress = { done: 0, total: 0 };
    /** The area filter on Ergebnis: '' (all) or a section id. */
    var area = '';
    /** A check link's row: shown even before a run, and "In Ordnung" unfolds once for it. */
    var override = null;
    var unfold = null;
    /** The fragment an area-filter click just set; its hashchange keeps focus in the filter. */
    var quietHash = null;
    var current = null;
    var rows = {};
    var startButtons = [];
    var clearItems = null;
    /** An item write is pending: the reset waits for it (one write at a time). */
    var itemsBusy = false;
    var lockItems = null;

    // ---------------------------------------------------------- verdict and run controls

    var head = el(doc, 'section', 'sfx-sc-head-panel');
    head.setAttribute('aria-labelledby', 'sfx-sc-verdict');
    var verdictBox = head.appendChild(el(doc, 'div', 'sfx-sc-verdict-box'));
    var mark = verdictBox.appendChild(el(doc, 'span', 'sfx-sc-mark'));
    mark.setAttribute('aria-hidden', 'true');
    var verdictText = verdictBox.appendChild(el(doc, 'div', 'sfx-sc-verdict-text'));
    var verdict = verdictText.appendChild(el(doc, 'h2', 'sfx-sc-verdict'));
    verdict.id = 'sfx-sc-verdict';
    var verdictSub = verdictText.appendChild(el(doc, 'p', 'sfx-sc-verdict-sub'));
    var meta = verdictText.appendChild(el(doc, 'p', 'sfx-sc-meta'));
    var next = verdictText.appendChild(el(doc, 'p', 'sfx-sc-next'));
    var nextText = next.appendChild(el(doc, 'span'));
    next.appendChild(doc.createTextNode(' '));
    var change = next.appendChild(el(doc, 'a', null, strings.change));
    change.setAttribute('href', '#einstellungen');

    var actions = head.appendChild(el(doc, 'div', 'sfx-sc-actions'));
    var start = actions.appendChild(startButton());
    start.id = 'sfx-sc-start';
    var probeLabel = actions.appendChild(el(doc, 'label', 'sfx-sc-probe'));
    var probe = probeLabel.appendChild(el(doc, 'input'));
    probe.type = 'checkbox';
    probe.id = 'sfx-sc-probe';
    probeLabel.appendChild(doc.createTextNode(' ' + (strings.probe || '')));
    actions.appendChild(el(doc, 'p', 'sfx-sc-cache', strings.cacheHint || ''));
    var resetButton = actions.appendChild(el(doc, 'button', 'button-link sfx-sc-reset', strings.reset));
    resetButton.type = 'button';
    resetButton.id = 'sfx-sc-reset';
    resetButton.addEventListener('click', resetAll);

    var statusLine = el(doc, 'p', 'sfx-sc-runline');
    statusLine.id = 'sfx-sc-status';
    statusLine.setAttribute('role', 'status');
    statusLine.setAttribute('aria-live', 'polite');
    /** Run progress, outside the live region: only start, saving and the final state are announced. */
    var progressLine = el(doc, 'p', 'sfx-sc-progress');
    progressLine.id = 'sfx-sc-progress';
    var noticeArea = el(doc, 'div', 'sfx-sc-notices');
    noticeArea.id = 'sfx-sc-notice';

    function startButton() {
      var b = el(doc, 'button', 'button button-primary button-hero', strings.check);
      b.type = 'button';
      b.addEventListener('click', startRun);
      startButtons.push(b);
      return b;
    }

    // ---------------------------------------------------------- tabs

    var tablist = el(doc, 'nav', 'nav-tab-wrapper sfx-sc-tabs');
    tablist.setAttribute('role', 'tablist');
    tablist.setAttribute('aria-label', strings.tablist || '');
    var tabEls = {};
    var panels = {};
    var adopted = { htaccess: doc.getElementById('sfx-sc-panel-htaccess'), einstellungen: doc.getElementById('sfx-sc-panel-einstellungen') };
    var built = [head, statusLine, progressLine, noticeArea, tablist];
    tabs.forEach(function (tab) {
      var a = el(doc, 'a', 'nav-tab');
      a.id = 'sfx-sc-tab-' + tab.id;
      a.setAttribute('href', '#' + tab.id);
      a.setAttribute('role', 'tab');
      a.setAttribute('aria-controls', 'sfx-sc-panel-' + tab.id);
      a.appendChild(el(doc, 'span', 'sfx-sc-tablabel', tab.title));
      a.addEventListener('click', function (e) {
        e.preventDefault();
        go(tab.id);
      });
      tablist.appendChild(a);
      tabEls[tab.id] = a;
      var panel = adopted[tab.id] || el(doc, 'div', 'sfx-sc-panel');
      panel.id = 'sfx-sc-panel-' + tab.id;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', a.id);
      panels[tab.id] = panel;
      built.push(panel);
    });
    built.forEach(function (node) { app.appendChild(node); });

    tablist.addEventListener('keydown', function (e) {
      var ids = tabs.map(function (t) { return t.id; });
      var i = ids.indexOf(current);
      var step = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: ids.length - 1 }[e.key];
      if (step === undefined) {
        return;
      }
      e.preventDefault();
      var id = ids[(step + ids.length) % ids.length];
      go(id);
      tabEls[id].focus();
    });

    // ---------------------------------------------------------- Ergebnis

    var result = panels.ergebnis;
    result.appendChild(el(doc, 'h2', 'screen-reader-text', (tabs[0] || {}).title || ''));
    var filterBar = result.appendChild(el(doc, 'ul', 'subsubsub sfx-sc-filter'));
    filterBar.setAttribute('aria-label', strings.filterLabel || '');
    var filterLinks = {};
    [{ id: '', title: strings.filterAll }].concat(config.sections || []).forEach(function (section, i) {
      var li = filterBar.appendChild(el(doc, 'li'));
      if (i > 0) {
        li.appendChild(doc.createTextNode(' | '));
      }
      var a = li.appendChild(el(doc, 'a', null, section.title || ''));
      var fragment = section.id === '' ? 'ergebnis' : Object.keys(AREA_FRAGMENTS).filter(function (f) { return AREA_FRAGMENTS[f] === section.id; })[0];
      a.setAttribute('href', '#' + fragment);
      var count = a.appendChild(el(doc, 'span', 'count'));
      a.addEventListener('click', function (e) {
        e.preventDefault();
        area = section.id;
        override = null;
        unfold = null;
        render();
        // The hashchange this causes must not pull focus to the tab: the admin is in the filter.
        quietHash = '#' + fragment;
        setHash(fragment);
      });
      filterLinks[section.id] = { a: a, count: count };
    });
    var empty = result.appendChild(el(doc, 'div', 'sfx-sc-empty'));
    empty.appendChild(el(doc, 'p', null, strings.emptyResult));
    var groups = {
      act: group('act', strings.groupAct, strings.groupActIntro),
      self: group('self', strings.groupSelf, strings.groupSelfIntro),
      fine: group('fine', strings.groupFine, null)
    };
    catalogue.forEach(function (check) {
      rows[check.id] = createRow(check);
      groups.act.list.appendChild(rows[check.id].details);
    });
    result.appendChild(renderItems());

    /** One result group; "fine" folds behind its own toggle (closed by default), the others are headed lists. */
    function group(kind, title, intro) {
      var wrap = result.appendChild(el(doc, kind === 'fine' ? 'details' : 'section', 'sfx-sc-group sfx-sc-group-' + kind));
      var headEl = wrap.appendChild(el(doc, kind === 'fine' ? 'summary' : 'h3', 'sfx-sc-grouphead'));
      var titleEl = headEl.appendChild(el(doc, 'span', null, title || ''));
      var count = headEl.appendChild(el(doc, 'span', 'sfx-sc-groupcount'));
      if (intro) {
        wrap.appendChild(el(doc, 'p', 'sfx-sc-groupintro', intro));
      }
      var none = wrap.appendChild(el(doc, 'p', 'sfx-sc-groupnone', kind === 'act' ? strings.noActionTab : ''));
      var list = wrap.appendChild(el(doc, 'div', 'sfx-sc-list'));
      return { wrap: wrap, summary: headEl, title: titleEl, count: count, none: none, list: list };
    }

    /** One check: a closed disclosure. Its summary carries the one traffic light, title, area and next step. */
    function createRow(check) {
      var details = el(doc, 'details', 'sfx-sc-check');
      details.id = 'sfx-sc-check-' + check.id;
      var summary = details.appendChild(el(doc, 'summary', 'sfx-sc-head'));
      var badge = summary.appendChild(el(doc, 'span', 'sfx-sc-badge'));
      var main = summary.appendChild(el(doc, 'span', 'sfx-sc-headmain'));
      var line = main.appendChild(el(doc, 'span', 'sfx-sc-headline'));
      line.appendChild(el(doc, 'span', 'sfx-sc-title', check.title));
      // Area and count wrap as one unit, so "· 1 Fund" never starts a line.
      var metaInline = line.appendChild(el(doc, 'span', 'sfx-sc-meta-inline'));
      metaInline.appendChild(el(doc, 'span', 'sfx-sc-area', sectionTitle[check.section] || ''));
      var count = metaInline.appendChild(el(doc, 'span', 'sfx-sc-count'));
      var tag = line.appendChild(el(doc, 'span', 'sfx-sc-tag'));
      var hint = main.appendChild(el(doc, 'span', 'sfx-sc-nextstep', check.recommendation));
      var body = details.appendChild(el(doc, 'div', 'sfx-sc-body'));
      var resultBox = body.appendChild(el(doc, 'div', 'sfx-sc-result'));
      var guide = renderGuidance(doc, check, strings, win);
      body.appendChild(guide.box);
      var note = body.appendChild(el(doc, 'p', 'sfx-sc-note'));
      return { id: check.id, details: details, summary: summary, badge: badge, count: count, tag: tag, hint: hint, result: resultBox, note: note, guide: guide, key: null };
    }

    /** Inside a check: the findings as a neutral detail list, then (to check yourself) how by hand. */
    function fillResult(row, graded) {
      var box = row.result;
      while (box.firstChild) {
        box.removeChild(box.firstChild);
      }
      while (row.note.firstChild) {
        row.note.removeChild(row.note.firstChild);
      }
      row.note.textContent = '';
      if (!graded) {
        return;
      }
      var findings = (Array.isArray(graded.findings) ? graded.findings : []).slice().sort(function (a, b) {
        return ORDER.indexOf(statusOf(a) || 'unknown') - ORDER.indexOf(statusOf(b) || 'unknown');
      });
      if (findings.length) {
        box.appendChild(el(doc, 'h4', 'sfx-sc-findings-title', strings.findingsTitle));
        var list = box.appendChild(el(doc, 'ul', 'sfx-sc-findings'));
        findings.forEach(function (finding) {
          var fs = statusOf(finding) || 'unknown';
          var li = list.appendChild(el(doc, 'li', 'sfx-sc-finding sfx-sc-finding-' + (GROUPS[fs] || 'self')));
          var icon = li.appendChild(el(doc, 'span', 'dashicons dashicons-' + FINDING_ICON[fs]));
          icon.setAttribute('aria-hidden', 'true');
          li.appendChild(el(doc, 'span', 'screen-reader-text', (strings.findingKind || {})[fs] || ''));
          li.appendChild(el(doc, 'span', 'sfx-sc-label', finding.label));
        });
      }
      if (statusOf(graded) === 'unknown') {
        var steps = strings.selfStep || {};
        var step = box.appendChild(el(doc, 'p', 'sfx-sc-selfcheck', (own(steps, row.id) && steps[row.id]) || steps[graded.perspective] || ''));
        step.appendChild(doc.createTextNode(' ' + (strings.selfCaveat || '')));
      }
      // The technical note (perspective, coverage) closes the check, below the guidance.
      row.note.appendChild(el(doc, 'span', 'sfx-sc-perspective', (strings.perspective || {})[graded.perspective] || graded.perspective || ''));
      if (graded.note) {
        row.note.appendChild(doc.createTextNode(' ' + graded.note));
      }
    }

    function updateRow(row, graded, tagText) {
      var status = statusOf(graded) || (phase === 'running' ? 'checking' : 'pending');
      var badgeClass = status === 'checking' ? 'pending' : status;
      var findings = graded && Array.isArray(graded.findings) ? graded.findings.length : 0;
      var key = JSON.stringify([graded || null, tagText]);
      row.details.className = 'sfx-sc-check sfx-sc-' + badgeClass;
      row.badge.className = 'sfx-sc-badge sfx-sc-badge-' + badgeClass;
      row.badge.textContent = labels[status] || status;
      // A graded row always shows its count, "0 findings" included; an ungraded one shows none.
      row.count.textContent = graded && status !== 'checking' ? format(findings === 1 ? strings.findingOne : strings.findingMany, [findings]) : '';
      row.tag.textContent = tagText;
      // The next step shows on rows to act on; elsewhere the title says enough.
      row.hint.hidden = !(status === 'red' || status === 'yellow');
      if (row.key !== key) {
        row.key = key;
        fillResult(row, graded);
      }
      var green = status === 'green';
      row.guide.fix.hidden = green;
      row.guide.none.hidden = !green;
    }

    // ---------------------------------------------------------- state → page

    /** What rows show: the run in progress or not saved, else the saved run. */
    function rowSource() {
      return phase !== 'idle' && incoming ? incoming : (saved ? saved.results || {} : {});
    }

    /** What counts: the run in progress, else the saved run (also after a refused or unknown save). */
    function countSource() {
      if (phase === 'running' || phase === 'saving') {
        return incoming || {};
      }
      return saved ? saved.results || {} : null;
    }

    function render() {
      var rowResults = rowSource();
      var tagText = { running: (strings.tag || {}).running, saving: (strings.tag || {}).running, refused: (strings.tag || {}).unsaved, unknown: (strings.tag || {}).unknown }[phase] || '';
      renderGroups(rowResults, tagText);
      renderVerdict(countSource());
      renderMeta();
      startButtons.forEach(function (b) { b.disabled = busy; });
      probe.disabled = busy;
      resetButton.disabled = busy || itemsBusy;
      if (formSave) {
        formSave.disabled = busy;
      }
      if (retryButton) {
        retryButton.disabled = busy;
      }
    }

    function renderGroups(results, tagText) {
      var any = catalogue.some(function (c) { return results[c.id]; });
      var noRun = !any && phase === 'idle';
      var members = { act: [], self: [], fine: [] };
      var actCount = { '': 0 };
      // Rows can change group; focus is put back once every move is done and the target shown.
      var active = doc.activeElement;
      sortChecks(catalogue, results).forEach(function (check) {
        var row = rows[check.id];
        var graded = results[check.id];
        updateRow(row, graded, graded ? tagText : '');
        var kind = GROUPS[statusOf(graded)] || 'act';
        // The filter counts what is to fix in that area (spec: Rot and Gelb), as the verdict does.
        if (kind === 'act' && graded) {
          actCount[''] += 1;
          actCount[check.section] = (actCount[check.section] || 0) + 1;
        }
        // Rows still waiting for a result stay with the ones to act on.
        members[kind].push(check);
        // A check link (#check-<id>) shows its row even before the first run and outside the filter.
        var inArea = area === '' || check.section === area || override === check.id;
        row.details.hidden = (noRun || !inArea) && override !== check.id;
      });
      Object.keys(members).forEach(function (kind) {
        var g = groups[kind];
        reorder(g.list, members[kind]);
        var shown = members[kind].filter(function (c) { return !rows[c.id].details.hidden; }).length;
        // The count is of graded checks, as the verdict counts; the act group keeps "(0)" after a run.
        var graded = members[kind].filter(function (c) { return !rows[c.id].details.hidden && results[c.id]; }).length;
        g.count.textContent = graded || (kind === 'act' && !noRun) ? ' (' + graded + ')' : '';
        // The act group stays visible after a run to say there is nothing to do.
        g.wrap.hidden = shown === 0 && !(kind === 'act' && !noRun);
        g.none.hidden = shown > 0;
        if (kind === 'fine' && unfold !== null && members.fine.indexOf(byId[unfold]) !== -1) {
          g.wrap.open = true;
          unfold = null;
        }
      });
      // A focused row that moved into the folded group does not unfold it (accordions stay closed):
      // focus goes to the group's own toggle instead, so the keyboard position is not lost.
      var fineList = groups.fine.list;
      if (active && active !== doc.body && fineList.contains && fineList.contains(active) && !groups.fine.wrap.open) {
        active = groups.fine.summary;
      }
      if (active && doc.activeElement !== active && typeof active.focus === 'function') {
        active.focus();
      }
      empty.hidden = !noRun || override !== null;
      filterBar.hidden = noRun;
      Object.keys(filterLinks).forEach(function (id) {
        var link = filterLinks[id];
        link.count.textContent = ' (' + (actCount[id] || 0) + ')';
        link.a.className = id === area ? 'current' : '';
        if (id === area) {
          link.a.setAttribute('aria-current', 'true');
        } else {
          link.a.removeAttribute('aria-current');
        }
      });
    }

    /** Moves rows into order only when it changed; focus inside a moved row is restored. */
    function reorder(list, ordered) {
      var same = list.children.length === ordered.length && ordered.every(function (check, i) { return list.children[i] === rows[check.id].details; });
      if (same) {
        return;
      }
      var active = doc.activeElement;
      ordered.forEach(function (check) { list.appendChild(rows[check.id].details); });
      if (active && doc.activeElement !== active && typeof active.focus === 'function') {
        active.focus();
      }
    }

    /** The site's one verdict: how many checks to act on, else to check yourself, else all in order. */
    function renderVerdict(results) {
      var n = { red: 0, yellow: 0, unknown: 0, hint: 0, green: 0 };
      catalogue.forEach(function (check) {
        var s = results ? statusOf(results[check.id]) : null;
        if (s) {
          n[s]++;
        }
      });
      var act = n.red + n.yellow;
      var state;
      var text;
      if (phase === 'running' || phase === 'saving') {
        state = 'running';
        text = strings.verdictRunning;
      } else if (results === null) {
        state = 'pending';
        text = strings.notChecked;
      } else if (act > 0) {
        state = n.red > 0 ? 'red' : 'yellow';
        text = format(act === 1 ? strings.verdictActOne : strings.verdictActMany, [act]);
      } else if (n.unknown > 0) {
        state = 'unknown';
        text = format(n.unknown === 1 ? strings.verdictSelfOne : strings.verdictSelfMany, [n.unknown]);
      } else {
        state = 'green';
        text = strings.verdictOk;
      }
      head.className = 'sfx-sc-head-panel sfx-sc-state-' + state;
      mark.className = 'sfx-sc-mark dashicons dashicons-' + { red: 'warning', yellow: 'warning', unknown: 'editor-help', green: 'yes-alt', pending: 'shield', running: 'update' }[state];
      verdict.textContent = text;
      var parts = [];
      if (results !== null && state !== 'running') {
        if (n.red) {
          parts.push(n.red + ' ' + (labels.red || ''));
        }
        if (n.yellow) {
          parts.push(n.yellow + ' ' + (labels.yellow || ''));
        }
        if (n.unknown && act > 0) {
          parts.push(n.unknown + ' ' + (labels.unknown || ''));
        }
        parts.push(n.hint + n.green + ' ' + (strings.verdictFine || ''));
      }
      verdictSub.textContent = state === 'pending' ? (strings.intro || '') : parts.join(' · ');
    }

    function profileName(profile) {
      return (config.profiles || {})[profile] || profile || '';
    }

    function renderMeta() {
      meta.textContent = saved ? format(strings.meta, [saved.date_display, saved.user, profileName(saved.profile)]) : '';
      meta.hidden = !saved;
      nextText.textContent = format(strings.nextProfile, [profileName(config.settingsProfile)]);
    }

    // ---------------------------------------------------------- tabs and fragments

    function select(id) {
      current = id;
      tabs.forEach(function (tab) {
        var on = tab.id === id;
        tabEls[tab.id].setAttribute('aria-selected', on ? 'true' : 'false');
        tabEls[tab.id].setAttribute('tabindex', on ? '0' : '-1');
        tabEls[tab.id].className = on ? 'nav-tab nav-tab-active' : 'nav-tab';
        panels[tab.id].hidden = !on;
      });
    }

    function setHash(id) {
      if (win.location && win.location.hash !== '#' + id) {
        win.location.hash = '#' + id;
      }
    }

    function go(id) {
      override = null;
      unfold = null;
      select(id);
      render();
      setHash(id);
    }

    /** Reads the fragment: a tab, an area (`sicherheit` …), a check (`check-<id>`), or anything else → Ergebnis. */
    function route(focus) {
      var raw = String((win.location && win.location.hash) || '').replace(/^#/, '');
      var id;
      try {
        id = decodeURIComponent(raw);
      } catch (e) {
        id = raw;
      }
      // Only own keys count: "#constructor" or "#check-toString" are no tab and no check.
      var check = id.indexOf('check-') === 0 && own(byId, id.slice(6)) ? byId[id.slice(6)] : null;
      if (check && rows[check.id]) {
        override = check.id;
        unfold = check.id;
        select('ergebnis');
        render();
        // The link unfolds "In Ordnung" only for where the row is now; a later result never does.
        unfold = null;
        var row = rows[check.id];
        row.details.open = true;
        row.summary.focus();
        return;
      }
      override = null;
      unfold = null;
      if (own(AREA_FRAGMENTS, id)) {
        area = AREA_FRAGMENTS[id];
        id = 'ergebnis';
      } else if (id === 'ergebnis' || !own(tabEls, id)) {
        area = '';
      }
      select(own(tabEls, id) ? id : 'ergebnis');
      render();
      if (focus) {
        tabEls[current].focus();
      }
    }

    if (win.addEventListener) {
      win.addEventListener('hashchange', function () {
        var quiet = quietHash !== null && win.location.hash === quietHash;
        quietHash = null;
        route(!quiet);
      });
    }

    // ---------------------------------------------------------- run, save, notices

    var retryButton = null;

    function notice(kind, text, action) {
      while (noticeArea.firstChild) {
        noticeArea.removeChild(noticeArea.firstChild);
      }
      retryButton = null;
      if (!kind) {
        return;
      }
      var box = noticeArea.appendChild(el(doc, 'div', 'notice notice-' + kind + ' inline'));
      var p = box.appendChild(el(doc, 'p', null, text));
      if (action) {
        p.appendChild(doc.createTextNode(' '));
        var b = p.appendChild(el(doc, 'button', 'button', action.label));
        b.type = 'button';
        b.addEventListener('click', action.run);
        if (action.retry) {
          retryButton = b;
        }
      }
    }

    function setStatus(text) {
      statusLine.textContent = text || '';
    }

    function showProgress() {
      progressLine.textContent = progress.total ? format(strings.progress, [progress.done, progress.total]) : '';
    }

    function startRun() {
      if (busy) {
        return;
      }
      busy = true;
      var mine = ++op;
      phase = 'running';
      incoming = {};
      retrySave = null;
      progress = { done: 0, total: catalogue.length };
      notice(null);
      setStatus(strings.running);
      showProgress();
      render();
      runner.run(!!probe.checked, {
        started: function (started) {
          // Safety net (here and below): busy already keeps operations from overlapping.
          if (mine === op) {
            progress.total = (started.checks || []).length;
            showProgress();
          }
        },
        result: function (check, graded) {
          if (mine !== op) {
            return;
          }
          incoming[check.id] = graded;
          progress.done++;
          showProgress();
          if (progress.done >= progress.total) {
            phase = 'saving';
            setStatus(strings.saving);
          }
          render();
        }
      }).then(function (data) {
        // Safety net: busy already keeps operations from overlapping.
        if (mine === op) {
          stored(data);
        }
      }, function (e) {
        if (mine === op) {
          failed(e);
        }
      }).then(function () {
        if (mine === op) {
          busy = false;
          render();
        }
      });
    }

    /** The page shows exactly what was stored. */
    function stored(data) {
      var last = data && data.last;
      if (last && last.results) {
        saved = { results: last.results, date_display: last.date_display || '', user: config.currentUser, profile: last.profile };
      }
      incoming = null;
      phase = 'idle';
      retrySave = null;
      notice(null);
      progressLine.textContent = '';
      setStatus(strings.saved);
    }

    function failed(e) {
      e = e || {};
      if (e.stage === 'start') {
        incoming = null;
        phase = 'idle';
        progressLine.textContent = '';
        setStatus(strings.statusStartFailed);
        notice('error', format(strings.startFailed, [e.message]));
        return;
      }
      if (e.lost) {
        phase = 'unknown';
        setStatus(strings.statusUnknown);
        notice('warning', strings.unknownSave, { label: strings.reload, run: function () { win.location.reload(); } });
        return;
      }
      phase = 'refused';
      setStatus(strings.statusNotSaved);
      if (e.busy && (e.retry || retrySave)) {
        retrySave = e.retry || retrySave;
        notice('error', format(strings.notSaved, [e.message]), { label: strings.retry, run: retry, retry: true });
      } else {
        retrySave = null;
        notice('error', format(strings.notSaved, [e.message]), { label: strings.recheck, run: startRun });
      }
    }

    function retry() {
      if (busy || !retrySave) {
        return;
      }
      busy = true;
      var mine = ++op;
      phase = 'saving';
      setStatus(strings.saving);
      render();
      retrySave().then(function (data) {
        // Safety net: busy already keeps operations from overlapping.
        if (mine === op) {
          stored(data);
        }
      }, function (e) {
        if (mine === op) {
          failed(e);
        }
      }).then(function () {
        if (mine === op) {
          busy = false;
          render();
        }
      });
    }

    /** Back to "not checked yet": results and ticked items are deleted; settings stay. */
    function resetAll() {
      if (busy || itemsBusy || !win.confirm(strings.resetConfirm)) {
        return;
      }
      busy = true;
      var mine = ++op;
      lockItems(true);
      render();
      fetcher.post('sfx_site_check_reset', {}).then(function () {
        if (mine !== op) {
          return;
        }
        saved = null;
        incoming = null;
        phase = 'idle';
        retrySave = null;
        progressLine.textContent = '';
        Object.keys(rows).forEach(function (id) { rows[id].details.open = false; });
        clearItems();
        notice(null);
        setStatus(strings.resetDone);
      }, function (e) {
        if (mine !== op) {
          return;
        }
        var text = e.lost ? strings.resetUnknown : format(strings.resetFailed, [e.message]);
        setStatus(text);
        if (!e.busy) {
          // Lost answer or partial delete: what is stored is unknown, so the page stops claiming it.
          notice('warning', text, { label: strings.reload, run: function () { win.location.reload(); } });
        }
        // Busy: nothing was deleted; any notice from a run (and its retry) stays as it was.
      }).then(function () {
        if (mine === op) {
          busy = false;
          lockItems(false);
          render();
        }
      });
    }

    // ---------------------------------------------------------- settings form (rendered by PHP)

    var form = doc.getElementById('sfx-sc-settings');
    var formSave = doc.getElementById('sfx-sc-settings-save');
    var formDirty = doc.getElementById('sfx-sc-settings-dirty');
    if (form) {
      var dirty = function () {
        if (formDirty) {
          formDirty.textContent = strings.settingsDirty || '';
        }
      };
      form.addEventListener('input', dirty);
      form.addEventListener('change', dirty);
      form.addEventListener('submit', function (e) {
        if (busy) {
          e.preventDefault();
        }
      });
    }

    // ---------------------------------------------------------- manual items (Ergebnis only)

    function renderItems() {
      var wrap = el(doc, 'div', 'sfx-sc-items');
      wrap.appendChild(el(doc, 'h2', null, strings.itemsTitle));
      var itemRows = [];
      /** One item write at a time: every control is disabled until it settles; older answers are ignored. */
      var itemOp = 0;
      lockItems = function (on) {
        itemRows.forEach(function (r) { r.box.disabled = on; });
      };
      clearItems = function () {
        itemOp++;
        itemRows.forEach(function (r) { r.box.checked = false; r.note.textContent = ''; });
      };
      (config.items || []).forEach(function (item) {
        var row = wrap.appendChild(el(doc, 'p', 'sfx-sc-item'));
        var label = row.appendChild(el(doc, 'label'));
        var box = label.appendChild(el(doc, 'input'));
        box.type = 'checkbox';
        box.checked = !!item.done;
        label.appendChild(doc.createTextNode(' ' + item.label + ' '));
        var note = row.appendChild(el(doc, 'span', 'sfx-sc-item-meta', item.meta || ''));
        itemRows.push({ box: box, note: note });
        box.addEventListener('change', function () {
          var done = !!box.checked;
          var mine = ++itemOp;
          lockItems(true);
          itemsBusy = true;
          resetButton.disabled = true;
          fetcher.post('sfx_site_check_items', { item: item.id, done: done ? '1' : '0' }).then(function (data) {
            if (mine !== itemOp) {
              return;
            }
            var entry = data && data.items && data.items[item.id];
            box.checked = !!entry;
            note.textContent = entry ? format(strings.itemDone, [config.currentUser, entry.date_display]) : '';
          }, function (e) {
            if (mine !== itemOp) {
              return;
            }
            box.checked = !done;
            note.textContent = e.message;
          }).then(function () {
            if (mine === itemOp) {
              itemsBusy = false;
              lockItems(false);
              resetButton.disabled = busy;
            }
          });
        });
      });
      return wrap;
    }

    // ---------------------------------------------------------- template copy buttons (rendered by PHP)

    Array.prototype.forEach.call(doc.querySelectorAll('.sfx-sc-copy'), function (copy) {
      wireCopy(doc, win, strings, copy, function () { return doc.getElementById(copy.getAttribute('data-target')); });
    });

    route(false);
  }

  root.SfxSiteCheck = {
    createFetcher: createFetcher,
    createRunner: createRunner,
    sortChecks: sortChecks,
    mount: mount,
    LIMIT: LIMIT,
    TIMEOUT_MS: TIMEOUT_MS,
    AJAX_TIMEOUT_MS: AJAX_TIMEOUT_MS,
    PARALLEL: PARALLEL
  };

  if (root.document && root.sfxSiteCheck) {
    if (root.document.readyState === 'loading') {
      root.document.addEventListener('DOMContentLoaded', function () { mount(root.document, root.sfxSiteCheck); });
    } else {
      mount(root.document, root.sfxSiteCheck);
    }
  }
})(typeof window !== 'undefined' ? window : globalThis);
