/**
 * Pin the SiteCheck browser fetcher (spec "Results", evidence rules 2, 6, 8, 9):
 * bounded reads (64 KB, 10 s), credentials omitted, redirects never followed,
 * at most 4 requests in flight, the first 512 bytes as hex, cache-buster plus
 * one plain fetch per target and one comparison fetch per batch.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. The script runs in a vm context
 * against a mocked fetch and fake timers; no network request is made. Real
 * browser behaviour of `redirect: 'manual'` (the opaqueredirect response) is
 * reproduced by the mock as the Fetch standard defines it, not observed.
 *
 * Run: node tests/site-check-fetcher-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT = readFileSync(
  join(dirname(dirname(fileURLToPath(import.meta.url))), 'inc/SiteCheck/assets/site-check.js'),
  'utf8'
);

const flush = () => new Promise((resolve) => setImmediate(resolve));

function fakeTimers() {
  let now = 0;
  let seq = 0;
  const timers = new Map();
  return {
    setTimeout(fn, ms) { const id = ++seq; timers.set(id, { fn, at: now + ms }); return id; },
    clearTimeout(id) { timers.delete(id); },
    async advance(ms) {
      now += ms;
      for (const [id, t] of [...timers]) {
        if (t.at <= now) { timers.delete(id); t.fn(); }
      }
      await flush();
    },
    pending() { return timers.size; },
  };
}

class FakeHeaders {
  constructor(obj = {}) { this.map = Object.fromEntries(Object.entries(obj).map(([k, v]) => [k.toLowerCase(), v])); }
  forEach(fn) { for (const [k, v] of Object.entries(this.map)) fn(v, k); }
  get(k) { return this.map[k.toLowerCase()] ?? null; }
}

/** A body stream that yields the given chunks (or endless chunks) and records cancel(). */
function stream(chunks, { endless = false, chunkSize = 4096 } = {}) {
  const state = { cancelled: false, reads: 0 };
  let i = 0;
  return {
    state,
    getReader() {
      return {
        read: async () => {
          state.reads++;
          if (state.cancelled) return { done: true, value: undefined };
          if (endless) return { done: false, value: new Uint8Array(chunkSize).fill(0x61) };
          if (i < chunks.length) return { done: false, value: chunks[i++] };
          return { done: true, value: undefined };
        },
        cancel: async () => { state.cancelled = true; },
        releaseLock() {},
      };
    },
  };
}

function response({ status = 200, type = 'basic', headers = {}, body = null } = {}) {
  return { status, type, headers: new FakeHeaders(headers), body };
}

function setup(fetchImpl) {
  const timers = fakeTimers();
  const calls = [];
  const ctx = {
    TextDecoder,
    Uint8Array,
    AbortController,
    setTimeout: timers.setTimeout,
    clearTimeout: timers.clearTimeout,
    Math,
    console,
    fetch: (url, init) => { calls.push({ url, init }); return fetchImpl(url, init, calls.length); },
  };
  ctx.window = ctx;
  createContext(ctx);
  runInContext(SCRIPT, ctx);
  const api = ctx.SfxSiteCheck;
  assert.ok(api && typeof api.createFetcher === 'function', 'SfxSiteCheck.createFetcher is exported');
  return { api, fetcher: api.createFetcher(), calls, timers, ctx };
}

const enc = (s) => new TextEncoder().encode(s);
const hex = (bytes) => Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

// ------------------------------------------------------------ endless stream → stops at 64 KB

{
  const body = stream([], { endless: true });
  const { fetcher } = setup(async () => response({ body }));
  const obs = await fetcher.observe('https://ex.test/wp-content/debug.log');
  assert.equal(obs.truncated, true, 'endless stream → truncated');
  assert.equal(obs.body.length, 65536, 'body is exactly 64 KB of text');
  assert.equal(body.state.cancelled, true, 'the reader is cancelled once the limit is passed');
  assert.ok(body.state.reads <= 17, `reading stops right after 64 KB (reads: ${body.state.reads})`);
  assert.equal(obs.error, '', 'no error');
  assert.equal(obs.status, 200);
}

// A body of exactly 64 KB is not truncated.
{
  const { fetcher } = setup(async () => response({ body: stream([new Uint8Array(65536).fill(0x62)]) }));
  const obs = await fetcher.observe('https://ex.test/a');
  assert.equal(obs.truncated, false, 'exactly 64 KB → not truncated');
  assert.equal(obs.body.length, 65536);
}

// ------------------------------------------------------------ server never answers → aborted at 10 s

{
  let aborted = false;
  const { fetcher, timers } = setup((url, init) => new Promise((resolve, reject) => {
    init.signal.addEventListener('abort', () => { aborted = true; reject(new Error('AbortError')); });
  }));
  let settled = null;
  fetcher.observe('https://ex.test/slow').then((o) => { settled = o; });
  await flush();
  await timers.advance(9999);
  assert.equal(settled, null, 'still waiting before 10 s');
  await timers.advance(1);
  assert.equal(aborted, true, 'the request is aborted at 10 s');
  assert.ok(settled, 'the observation settles');
  assert.equal(settled.error, 'timeout', 'observation says timeout');
  assert.equal(settled.status, 0);
  assert.equal(timers.pending(), 0, 'no timer left behind');
}

// A body that stalls mid-stream is aborted at 10 s too.
{
  const { fetcher, timers } = setup((url, init) => Promise.resolve(response({
    body: {
      getReader() {
        let first = true;
        return {
          read: () => {
            if (first) { first = false; return Promise.resolve({ done: false, value: enc('partial') }); }
            return new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(new Error('AbortError'))));
          },
          cancel: async () => {},
          releaseLock() {},
        };
      },
    },
  })));
  let settled = null;
  fetcher.observe('https://ex.test/stall').then((o) => { settled = o; });
  await flush();
  await timers.advance(10000);
  assert.equal(settled.error, 'timeout', 'stalled body → timeout');
  assert.equal(settled.body, '', 'a timed-out observation carries no partial body');
}

// ------------------------------------------------------------ opaqueredirect vs empty 200 vs network error

{
  const { fetcher, calls } = setup(async () => response({ status: 0, type: 'opaqueredirect', headers: {}, body: null }));
  const obs = await fetcher.observe('https://ex.test/.env');
  assert.equal(obs.redirect, true, 'opaqueredirect → redirect: true');
  assert.equal(obs.status, 0);
  assert.equal(obs.type, 'opaqueredirect');
  assert.equal(obs.error, '', 'a redirect is not an error');
  assert.equal(calls.length, 1, 'no second request: the redirect is not followed');
  assert.equal(calls[0].init.redirect, 'manual', "redirect: 'manual'");
}
{
  const { fetcher } = setup(async () => response({ status: 200, body: stream([]) }));
  const obs = await fetcher.observe('https://ex.test/empty');
  assert.deepEqual([obs.status, obs.redirect, obs.error, obs.body, obs.truncated], [200, false, '', '', false], 'empty 200 is its own case');
}
{
  const { fetcher } = setup(async () => response({ status: 200, body: null }));
  const obs = await fetcher.observe('https://ex.test/nobody');
  assert.deepEqual([obs.status, obs.redirect, obs.error, obs.body], [200, false, '', ''], 'null body on a 200 reads as empty');
}
{
  const { fetcher } = setup(async () => { throw new TypeError('Failed to fetch'); });
  const obs = await fetcher.observe('https://ex.test/down');
  assert.deepEqual([obs.status, obs.redirect, obs.error], [0, false, 'network'], 'network error is neither redirect nor timeout');
}

// ------------------------------------------------------------ Gate B pass 2: security headers whole up to 8 KB, longer ones marked cut

{
  const csp = "default-src 'self'; img-src " + 'x'.repeat(2000) + '; script-src *';
  const { fetcher } = setup(async () => response({ headers: { 'Content-Security-Policy': csp, 'X-Other': 'y'.repeat(3000) }, body: stream([enc('<html>')]) }));
  const obs = await fetcher.observe('https://ex.test/');
  assert.equal(obs.headers['content-security-policy'], csp, 'a 2 KB CSP arrives whole');
  assert.equal(obs.headers['x-other'].length, 1025, 'other headers: 1 KB plus one character, so the server sees the cut');
  const { fetcher: f2 } = setup(async () => response({ headers: { 'Content-Security-Policy': 'z'.repeat(9000) }, body: stream([enc('<html>')]) }));
  const long = await f2.observe('https://ex.test/');
  assert.equal(long.headers['content-security-policy'].length, 8193, 'a CSP over 8 KB: 8 KB plus one character, so the server sees the cut');
}

// ------------------------------------------------------------ headers are kept, lower-cased

{
  const { fetcher } = setup(async () => response({ headers: { 'X-Powered-By': 'PHP/8.1.2', 'Content-Type': 'text/html' }, body: stream([enc('<html>')]) }));
  const obs = await fetcher.observe('https://ex.test/');
  assert.equal(obs.headers['x-powered-by'], 'PHP/8.1.2');
  assert.equal(obs.headers['content-type'], 'text/html');
  assert.equal(obs.body, '<html>');
  assert.equal(obs.head_hex, hex(enc('<html>')), 'short body → head_hex of all of it');
}

// ------------------------------------------------------------ binary: gzip, ZIP, tar > 64 KB → head_hex

function binary(prefix, size) {
  const bytes = new Uint8Array(size);
  for (let i = 0; i < size; i++) bytes[i] = (i * 31 + 7) & 0xff;
  bytes.set(prefix, 0);
  return bytes;
}
const tarHeader = new Uint8Array(512);
tarHeader.set(enc('backup/db.sql'), 0);
tarHeader.set(enc('ustar'), 257);
const fixtures = {
  gzip: binary([0x1f, 0x8b, 0x08, 0x00], 70000),
  zip: binary([0x50, 0x4b, 0x03, 0x04], 70000),
  tar: binary(tarHeader, 70000),
};
for (const [name, bytes] of Object.entries(fixtures)) {
  const chunks = [];
  for (let i = 0; i < bytes.length; i += 5000) chunks.push(bytes.slice(i, i + 5000));
  const { fetcher } = setup(async () => response({ headers: { 'content-type': 'application/octet-stream' }, body: stream(chunks) }));
  const obs = await fetcher.observe(`https://ex.test/backup.${name}`);
  assert.equal(obs.head_hex, hex(bytes.slice(0, 512)), `${name}: head_hex is the first 512 bytes`);
  assert.equal(obs.head_hex.length, 1024, `${name}: 512 bytes → 1024 hex digits`);
  assert.equal(obs.truncated, true, `${name}: larger than 64 KB → truncated`);
  // The patterns of inc/SiteCheck/Data/signatures.php (group `archive`).
  const pattern = { gzip: /^1f8b/i, zip: /^504b0304/i, tar: /^[0-9a-f]{514}7573746172/i }[name];
  assert.match(obs.head_hex, pattern, `${name}: head_hex matches the archive signature`);
}

// ------------------------------------------------------------ at most 4 in flight; credentials omitted

{
  let inFlight = 0;
  let max = 0;
  const release = [];
  const { fetcher, calls } = setup(() => {
    inFlight++;
    max = Math.max(max, inFlight);
    return new Promise((resolve) => release.push(() => { inFlight--; resolve(response({ status: 404, body: stream([enc('nf')]) })); }));
  });
  let done = 0;
  const all = Promise.all(Array.from({ length: 10 }, (_, i) => fetcher.observe(`https://ex.test/f${i}`).then((o) => { done++; return o; })));
  for (let round = 0; round < 100 && done < 10; round++) {
    await flush();
    assert.ok(inFlight <= 4, `at most 4 in flight (now ${inFlight})`);
    if (release.length) release.shift()();
  }
  const results = await all;
  assert.equal(results.length, 10, 'every queued request completes');
  assert.equal(max, 4, 'the queue uses all 4 slots, never more');
  assert.equal(calls.length, 10);
  for (const c of calls) {
    assert.equal(c.init.credentials, 'omit', "credentials: 'omit' on every call");
    assert.equal(c.init.redirect, 'manual', "redirect: 'manual' on every call");
  }
}

// ------------------------------------------------------------ a batch: cache-buster + plain per target, one comparison

{
  const { fetcher, calls } = setup(async () => response({ status: 404, body: stream([enc('nf')]) }));
  const plan = {
    targets: [{ url: 'https://ex.test/wp-content/debug.log' }, { url: 'https://ex.test/?author=1' }],
    comparison: 'https://ex.test/sfx-site-check-missing-abc',
  };
  const obs = await fetcher.batch(plan);
  const urls = calls.map((c) => c.url);
  assert.equal(urls.length, 5, 'two fetches per target plus one comparison');
  assert.ok(urls.includes('https://ex.test/wp-content/debug.log'), 'plain fetch');
  assert.ok(urls.some((u) => /^https:\/\/ex\.test\/wp-content\/debug\.log\?sfxcb=[a-z0-9]+$/.test(u)), 'cache-buster with ?');
  assert.ok(urls.some((u) => /^https:\/\/ex\.test\/\?author=1&sfxcb=[a-z0-9]+$/.test(u)), 'cache-buster appended with &');
  assert.equal(urls.filter((u) => u.startsWith('https://ex.test/sfx-site-check-missing-abc')).length, 1, 'one comparison fetch, no buster');
  assert.equal(obs.length, 5, 'one observation per fetch');
  for (const o of obs) assert.ok(urls.includes(o.url), 'each observation names the URL fetched');
  for (const c of calls) assert.equal(c.init.credentials, 'omit');
}

// ------------------------------------------------------------ runCheck: targets → fetch → observe

{
  const posted = [];
  const { api, calls } = setup(async (url, init) => {
    if (url === '/wp-admin/admin-ajax.php') {
      const fields = Object.fromEntries(init.body.entries());
      posted.push({ fields, init });
      if (fields.action === 'sfx_site_check_targets') {
        return { ok: true, status: 200, json: async () => ({ success: true, data: { targets: [{ url: 'https://ex.test/.env' }], comparison: 'https://ex.test/sfx-site-check-missing-x', skipped: [] } }) };
      }
      return { ok: true, status: 200, json: async () => ({ success: true, data: { graded: { status: 'green' }, facts: { targets: [] } } }) };
    }
    return response({ status: 404, body: stream([enc('nf')]) });
  });
  class FakeFormData {
    constructor() { this.list = []; }
    append(k, v) { this.list.push([k, String(v)]); }
    entries() { return this.list[Symbol.iterator](); }
  }
  const runner = api.createFetcher({ ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n1', FormData: FakeFormData });
  const result = await runner.runCheck('run1', 'vcs_env');
  assert.deepEqual(result, { graded: { status: 'green' }, facts: { targets: [] } }, 'returns {graded, facts}');
  assert.deepEqual(posted.map((p) => p.fields.action), ['sfx_site_check_targets', 'sfx_site_check_observe']);
  for (const p of posted) {
    assert.equal(p.fields._ajax_nonce, 'n1', 'every AJAX call carries the nonce');
    assert.equal(p.fields.run, 'run1');
    assert.equal(p.fields.check, 'vcs_env');
    assert.equal(p.init.method, 'POST');
    assert.equal(p.init.credentials, 'same-origin', 'AJAX posts carry the admin session; outside fetches never do');
  }
  const sent = JSON.parse(posted[1].fields.observations);
  assert.equal(sent.length, 3, 'observations of both target fetches and the comparison');
  assert.deepEqual(Object.keys(sent[0]).sort(), ['body', 'error', 'head_hex', 'headers', 'redirect', 'status', 'truncated', 'type', 'url'].sort(), 'observation shape');
  const outside = calls.filter((c) => c.url !== '/wp-admin/admin-ajax.php');
  for (const c of outside) assert.equal(c.init.credentials, 'omit', 'outside fetches omit credentials');
}

console.log('site-check fetcher: ok');
