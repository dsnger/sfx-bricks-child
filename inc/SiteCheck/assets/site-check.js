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
 * The page (mount): six tabs (spec "Page layout and guidance"), one
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

  /** Tab fragment of each section (spec "Links"). */
  var SECTION_TABS = { security: 'sicherheit', golive: 'live-gang', cleanup: 'aufraeumen' };
  /** Rows "Nur Handlungsbedarf" hides. */
  var NO_ACTION = ['green', 'hint'];
  var URGENT_MAX = 5;

  /** Guidance block of one check: Warum, Empfehlung, So geht's (catalogue text only, never site data). */
  function renderGuidance(doc, check, strings) {
    var box = el(doc, 'div', 'sfx-sc-guidance');
    var why = el(doc, 'div', 'sfx-sc-why');
    why.appendChild(el(doc, 'h3', null, strings.why));
    why.appendChild(el(doc, 'p', null, check.why));
    box.appendChild(why);
    var fix = el(doc, 'div', 'sfx-sc-fix');
    fix.appendChild(el(doc, 'h3', null, strings.recommendation));
    fix.appendChild(el(doc, 'p', null, check.recommendation));
    var steps = Array.isArray(check.steps) ? check.steps : [];
    var links = Array.isArray(check.links) ? check.links : [];
    if (steps.length || links.length) {
      fix.appendChild(el(doc, 'h3', null, strings.steps));
      if (steps.length) {
        var ol = el(doc, 'ol', 'sfx-sc-steps');
        steps.forEach(function (step) { ol.appendChild(el(doc, 'li', null, step)); });
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

  /** A pill "2 Rot" — the number with its word, never colour alone. */
  function pill(doc, status, count, labels) {
    return el(doc, 'span', 'sfx-sc-pill sfx-sc-badge-' + status, count + ' ' + (labels[status] || status));
  }

  /**
   * Wires the check page (Tools → Sicherheits-Check): tab bar, Übersicht,
   * the three section tabs with one row per check, the run and its notices.
   * The .htaccess template and the settings form come from PHP and are
   * adopted as panels. Every write to the DOM is text or createElement.
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
    var filters = {};
    var override = null;
    var current = null;
    var rows = {};
    var startButtons = [];

    // ---------------------------------------------------------- frame

    var statusLine = el(doc, 'p', 'sfx-sc-runline');
    statusLine.id = 'sfx-sc-status';
    statusLine.setAttribute('role', 'status');
    statusLine.setAttribute('aria-live', 'polite');
    /** Run progress, outside the live region: only start, saving and the final state are announced. */
    var progressLine = el(doc, 'p', 'sfx-sc-progress');
    progressLine.id = 'sfx-sc-progress';
    var noticeArea = el(doc, 'div', 'sfx-sc-notices');
    noticeArea.id = 'sfx-sc-notice';
    var tablist = el(doc, 'nav', 'nav-tab-wrapper sfx-sc-tabs');
    tablist.setAttribute('role', 'tablist');
    tablist.setAttribute('aria-label', strings.tablist || '');

    var tabEls = {};
    var panels = {};
    var counts = {};
    var adopted = { htaccess: doc.getElementById('sfx-sc-panel-htaccess'), einstellungen: doc.getElementById('sfx-sc-panel-einstellungen') };
    var built = [statusLine, progressLine, noticeArea, tablist];
    tabs.forEach(function (tab) {
      var a = el(doc, 'a', 'nav-tab');
      a.id = 'sfx-sc-tab-' + tab.id;
      a.setAttribute('href', '#' + tab.id);
      a.setAttribute('role', 'tab');
      a.setAttribute('aria-controls', 'sfx-sc-panel-' + tab.id);
      a.appendChild(el(doc, 'span', 'sfx-sc-tablabel', tab.title));
      if (tab.section) {
        counts[tab.section] = a.appendChild(el(doc, 'span', 'sfx-sc-tabcount'));
      }
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
      var next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: ids.length - 1 }[e.key];
      if (next === undefined) {
        return;
      }
      e.preventDefault();
      var id = ids[(next + ids.length) % ids.length];
      go(id);
      tabEls[id].focus();
    });

    // ---------------------------------------------------------- Übersicht

    var overview = panels.uebersicht;
    var meta = overview.appendChild(el(doc, 'p', 'sfx-sc-meta'));
    var next = overview.appendChild(el(doc, 'p', 'sfx-sc-next'));
    var nextText = next.appendChild(el(doc, 'span'));
    next.appendChild(doc.createTextNode(' '));
    var change = next.appendChild(el(doc, 'a', null, strings.change));
    change.setAttribute('href', '#einstellungen');
    var controls = overview.appendChild(el(doc, 'p', 'sfx-sc-controls'));
    var probeLabel = controls.appendChild(el(doc, 'label', 'sfx-sc-probe'));
    var probe = probeLabel.appendChild(el(doc, 'input'));
    probe.type = 'checkbox';
    probe.id = 'sfx-sc-probe';
    probeLabel.appendChild(doc.createTextNode(' ' + (strings.probe || '')));
    var start = controls.appendChild(startButton(true));
    start.id = 'sfx-sc-start';

    overview.appendChild(el(doc, 'h2', null, strings.sectionsTitle));
    var summaries = overview.appendChild(el(doc, 'ul', 'sfx-sc-summaries'));
    var summaryRows = {};
    (config.sections || []).forEach(function (section) {
      var li = summaries.appendChild(el(doc, 'li', 'sfx-sc-summary-item'));
      var a = li.appendChild(el(doc, 'a', null, section.title));
      a.setAttribute('href', '#' + SECTION_TABS[section.id]);
      summaryRows[section.id] = li.appendChild(el(doc, 'span', 'sfx-sc-summary-counts'));
    });
    var urgentBox = overview.appendChild(el(doc, 'div', 'sfx-sc-urgent'));
    urgentBox.appendChild(el(doc, 'h2', null, strings.urgentTitle));
    var urgentList = urgentBox.appendChild(el(doc, 'ol', 'sfx-sc-urgent-list'));
    overview.appendChild(renderItems());

    function startButton(primary) {
      var b = el(doc, 'button', primary ? 'button button-primary' : 'button', strings.check);
      b.type = 'button';
      b.addEventListener('click', startRun);
      startButtons.push(b);
      return b;
    }

    // Fresh site: the adopted panels (.htaccess, settings) say "not checked yet" with a working control too.
    var freshBoxes = [];
    Object.keys(adopted).forEach(function (id) {
      var panel = adopted[id];
      if (!panel) {
        return;
      }
      var box = el(doc, 'div', 'sfx-sc-empty');
      box.appendChild(el(doc, 'p', null, strings.notChecked));
      box.appendChild(startButton(false));
      panel.insertBefore(box, panel.firstChild || null);
      freshBoxes.push(box);
    });

    // ---------------------------------------------------------- section tabs

    var lists = {};
    var empties = {};
    (config.sections || []).forEach(function (section) {
      var panel = panels[SECTION_TABS[section.id]];
      if (!panel) {
        return;
      }
      panel.appendChild(el(doc, 'h2', 'screen-reader-text', section.title));
      var head = panel.appendChild(el(doc, 'p', 'sfx-sc-panelhead'));
      var label = head.appendChild(el(doc, 'label'));
      var box = label.appendChild(el(doc, 'input'));
      box.type = 'checkbox';
      label.appendChild(doc.createTextNode(' ' + (strings.onlyAction || '')));
      box.addEventListener('change', function () {
        filters[section.id] = !!box.checked;
        // The admin's own filter choice ends a check link's exception.
        override = null;
        render();
      });
      var empty = panel.appendChild(el(doc, 'div', 'sfx-sc-empty'));
      var emptyText = empty.appendChild(el(doc, 'p'));
      var emptyButton = empty.appendChild(startButton(false));
      empties[section.id] = { box: empty, text: emptyText, button: emptyButton };
      lists[section.id] = panel.appendChild(el(doc, 'div', 'sfx-sc-list'));
      catalogue.filter(function (c) { return c.section === section.id; }).forEach(function (check) {
        rows[check.id] = createRow(check);
        lists[section.id].appendChild(rows[check.id].details);
      });
    });

    function createRow(check) {
      var details = el(doc, 'details', 'sfx-sc-check');
      details.id = 'sfx-sc-check-' + check.id;
      var summary = details.appendChild(el(doc, 'summary', 'sfx-sc-head'));
      var badge = summary.appendChild(el(doc, 'span', 'sfx-sc-badge'));
      summary.appendChild(el(doc, 'span', 'sfx-sc-title', check.title));
      var count = summary.appendChild(el(doc, 'span', 'sfx-sc-count'));
      var tag = summary.appendChild(el(doc, 'span', 'sfx-sc-tag'));
      var body = details.appendChild(el(doc, 'div', 'sfx-sc-body'));
      var result = body.appendChild(el(doc, 'div', 'sfx-sc-result'));
      var guide = renderGuidance(doc, check, strings);
      body.appendChild(guide.box);
      var row = { details: details, summary: summary, badge: badge, count: count, tag: tag, result: result, guide: guide, decided: false, key: null };
      details.addEventListener('toggle', function () { row.decided = true; });
      return row;
    }

    /** Findings, then the note with its perspective; rebuilt inside the kept row. */
    function fillResult(row, graded) {
      var box = row.result;
      while (box.firstChild) {
        box.removeChild(box.firstChild);
      }
      if (!graded) {
        return;
      }
      var findings = Array.isArray(graded.findings) ? graded.findings : [];
      if (findings.length) {
        var list = box.appendChild(el(doc, 'ul', 'sfx-sc-findings'));
        findings.forEach(function (finding) {
          var fs = statusOf(finding) || 'unknown';
          var li = list.appendChild(el(doc, 'li', 'sfx-sc-finding'));
          li.appendChild(el(doc, 'span', 'sfx-sc-dot sfx-sc-badge-' + fs, labels[fs] || fs));
          li.appendChild(el(doc, 'span', 'sfx-sc-label', finding.label));
        });
      }
      var note = box.appendChild(el(doc, 'p', 'sfx-sc-note'));
      note.appendChild(el(doc, 'span', 'sfx-sc-perspective', (strings.perspective || {})[graded.perspective] || graded.perspective || ''));
      if (graded.note) {
        note.appendChild(doc.createTextNode(' ' + graded.note));
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
      if (row.key !== key) {
        row.key = key;
        fillResult(row, graded);
      }
      var green = status === 'green';
      row.guide.fix.hidden = green;
      row.guide.none.hidden = !green;
      if (!row.decided && (status === 'red' || status === 'yellow')) {
        row.decided = true;
        row.details.open = true;
      }
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

    function tally(results, section) {
      var n = { red: 0, yellow: 0, unknown: 0, hint: 0, green: 0, any: 0 };
      catalogue.forEach(function (check) {
        var status = results ? statusOf(results[check.id]) : null;
        if (check.section === section && status) {
          n[status]++;
          n.any++;
        }
      });
      return n;
    }

    function render() {
      var rowResults = rowSource();
      var countResults = countSource();
      var tagText = { running: (strings.tag || {}).running, saving: (strings.tag || {}).running, refused: (strings.tag || {}).unsaved, unknown: (strings.tag || {}).unknown }[phase] || '';

      (config.sections || []).forEach(function (section) {
        var n = tally(countResults, section.id);
        var badge = counts[section.id];
        if (badge) {
          while (badge.firstChild) {
            badge.removeChild(badge.firstChild);
          }
          ['red', 'yellow'].forEach(function (s) {
            if (n[s]) {
              badge.appendChild(pill(doc, s, n[s], labels));
            }
          });
        }
        var sum = summaryRows[section.id];
        if (sum) {
          while (sum.firstChild) {
            sum.removeChild(sum.firstChild);
          }
          if (n.any) {
            ['red', 'yellow', 'unknown'].forEach(function (s) { sum.appendChild(pill(doc, s, n[s], labels)); });
          } else {
            sum.appendChild(el(doc, 'span', 'sfx-sc-muted', strings.notChecked));
          }
        }
        renderSection(section.id, rowResults, tagText);
      });

      renderUrgent(countResults);
      renderMeta();
      freshBoxes.forEach(function (box) { box.hidden = !(saved === null && incoming === null && phase === 'idle'); });
      startButtons.forEach(function (b) { b.disabled = busy; });
      probe.disabled = busy;
      if (formSave) {
        formSave.disabled = busy;
      }
      if (retryButton) {
        retryButton.disabled = busy;
      }
    }

    function renderSection(section, results, tagText) {
      var list = lists[section];
      if (!list) {
        return;
      }
      var own = catalogue.filter(function (c) { return c.section === section; });
      var any = own.some(function (c) { return results[c.id]; });
      var noRun = !any && phase === 'idle';
      var shown = 0;
      own.forEach(function (check) {
        var row = rows[check.id];
        var graded = results[check.id];
        updateRow(row, graded, graded ? tagText : '');
        var status = statusOf(graded);
        // A check link (#check-<id>) shows its row even before the first run.
        var hide = override !== check.id && (noRun || (filters[section] && NO_ACTION.indexOf(status) !== -1));
        row.details.hidden = hide;
        shown += hide ? 0 : 1;
      });
      reorder(list, sortChecks(own, results));
      var empty = empties[section];
      empty.box.hidden = !noRun && shown > 0;
      empty.text.textContent = noRun ? strings.notChecked : strings.noActionTab;
      empty.button.hidden = !noRun;
    }

    /** Moves rows into order only when it changed; focus inside a moved row is restored. */
    function reorder(list, ordered) {
      var same = ordered.every(function (check, i) { return list.children[i] === rows[check.id].details; });
      if (same) {
        return;
      }
      var active = doc.activeElement;
      ordered.forEach(function (check) { list.appendChild(rows[check.id].details); });
      if (active && doc.activeElement !== active && typeof active.focus === 'function') {
        active.focus();
      }
    }

    function renderUrgent(results) {
      while (urgentList.firstChild) {
        urgentList.removeChild(urgentList.firstChild);
      }
      var urgent = catalogue
        .map(function (check, index) { return { check: check, index: index, status: results ? statusOf(results[check.id]) : null }; })
        .filter(function (e) { return e.status === 'red' || e.status === 'yellow'; })
        .sort(function (a, b) { return ORDER.indexOf(a.status) - ORDER.indexOf(b.status) || a.index - b.index; })
        .slice(0, URGENT_MAX);
      urgentBox.hidden = results === null;
      if (!urgent.length) {
        urgentList.appendChild(el(doc, 'li', 'sfx-sc-muted', strings.urgentNone));
        return;
      }
      urgent.forEach(function (e) {
        var li = urgentList.appendChild(el(doc, 'li'));
        var a = li.appendChild(el(doc, 'a'));
        a.setAttribute('href', '#check-' + e.check.id);
        a.appendChild(el(doc, 'span', 'sfx-sc-badge sfx-sc-badge-' + e.status, labels[e.status]));
        a.appendChild(doc.createTextNode(' ' + e.check.title));
        li.appendChild(el(doc, 'span', 'sfx-sc-muted', ' · ' + (sectionTitle[e.check.section] || '')));
      });
    }

    function profileName(profile) {
      return (config.profiles || {})[profile] || profile || '';
    }

    function renderMeta() {
      meta.textContent = saved
        ? format(strings.meta, [saved.date_display, saved.user, profileName(saved.profile)])
        : (strings.notChecked || '') + ' ' + (strings.intro || '');
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
      select(id);
      render();
      setHash(id);
    }

    /** Reads the fragment: a tab, a check (`check-<id>`), or anything else → Übersicht. */
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
        select(SECTION_TABS[check.section]);
        render();
        var row = rows[check.id];
        row.decided = true;
        row.details.open = true;
        row.summary.focus();
        return;
      }
      override = null;
      select(own(tabEls, id) ? id : 'uebersicht');
      render();
      if (focus) {
        tabEls[current].focus();
      }
    }

    if (win.addEventListener) {
      win.addEventListener('hashchange', function () { route(true); });
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

    // ---------------------------------------------------------- manual items (Übersicht only)

    function renderItems() {
      var wrap = el(doc, 'div', 'sfx-sc-items');
      wrap.appendChild(el(doc, 'h2', null, strings.itemsTitle));
      var itemRows = [];
      /** One item write at a time: every control is disabled until it settles; older answers are ignored. */
      var itemOp = 0;
      var reset = null;
      function lockItems(on) {
        itemRows.forEach(function (r) { r.box.disabled = on; });
        if (reset) {
          reset.disabled = on;
        }
      }
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
              lockItems(false);
            }
          });
        });
      });
      reset = wrap.appendChild(el(doc, 'button', 'button', strings.itemsReset));
      reset.type = 'button';
      reset.addEventListener('click', function () {
        if (!win.confirm(strings.itemsResetConfirm)) {
          return;
        }
        var mine = ++itemOp;
        lockItems(true);
        fetcher.post('sfx_site_check_items', { reset: '1' }).then(function () {
          if (mine === itemOp) {
            itemRows.forEach(function (r) { r.box.checked = false; r.note.textContent = ''; });
          }
        }, function (e) {
          if (mine === itemOp) {
            setStatus(e.message);
          }
        }).then(function () {
          if (mine === itemOp) {
            lockItems(false);
          }
        });
      });
      return wrap;
    }

    // ---------------------------------------------------------- template copy buttons (rendered by PHP)

    Array.prototype.forEach.call(doc.querySelectorAll('.sfx-sc-copy'), function (copy) {
      var message = null;
      /** No clipboard, or it refused: select the block's text and say how to copy it by hand. */
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
        var source = doc.getElementById(copy.getAttribute('data-target'));
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
