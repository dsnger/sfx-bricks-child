/**
 * Pin the structural contract of the Editor Prose baseline (spec "Baseline"):
 * the sfx layer-order statement first (identical to styles.css), then exactly one
 * `@layer sfx.components` block and nothing else; every rule inside it anchored on
 * `:where(.sfx-prose)`, Bricks elements excluded from every descendant rule, no
 * nested at-rules, no !important, no rem literals, every var() with a fallback.
 * The checker is run against broken fixtures too, so it cannot pass vacuously.
 * Visual behaviour is covered by the browser verification.
 *
 * Run: node tests/editor-prose-baseline-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const SOURCE = readFileSync(join(ROOT, 'inc/EditorProse/assets/prose.css'), 'utf8');
const ORDER = readFileSync(join(ROOT, 'assets/css/frontend/styles.css'), 'utf8').match(/@layer[^;{]+;/)[0];
const EXCLUDE = ':not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *))';

function splitTop(list) {
  const out = []; let d = 0, cur = '';
  for (const ch of list) { if (ch === '(') d++; if (ch === ')') d--; if (ch === ',' && d === 0) { out.push(cur.trim()); cur = ''; continue; } cur += ch; }
  if (cur.trim()) out.push(cur.trim());
  return out;
}

function checkVars(text, where) {
  let i = text.indexOf('var(');
  while (i !== -1) {
    let d = 0, j = i + 3, args = '';
    for (; j < text.length; j++) {
      const c = text[j];
      if (c === '(') d++;
      if (c === ')') { d--; if (d === 0) break; }
      if (d >= 1 && !(d === 1 && c === '(')) args += c;
    }
    const parts = splitTop(args);
    if (parts.length < 2 || parts.slice(1).join(',').trim() === '') throw new Error(`var without fallback in ${where}: var(${args})`);
    checkVars(parts.slice(1).join(','), where); // any var() inside the fallback, at any position
    i = text.indexOf('var(', j + 1);
  }
}

/** Throws on the first violation; returns the rule count. */
function check(raw) {
  let css = raw.replace(/\/\*[\s\S]*?\*\//g, '').trim();
  if (!css.startsWith(ORDER)) throw new Error('layer-order statement missing or not first');
  css = css.slice(ORDER.length).trim();
  if (!css.startsWith('@layer sfx.components {')) throw new Error('second statement is not the sfx.components block');
  // Find the matching close of the layer block; nothing may follow it.
  let d = 0, end = -1;
  for (let k = 0; k < css.length; k++) { if (css[k] === '{') d++; if (css[k] === '}') { d--; if (d === 0) { end = k; break; } } }
  if (end === -1) throw new Error('unbalanced braces');
  if (css.slice(end + 1).trim() !== '') throw new Error('content outside the sfx.components block');
  const inner = css.slice('@layer sfx.components {'.length, end);

  let count = 0, pos = 0;
  while (pos < inner.length) {
    const open = inner.indexOf('{', pos);
    if (open === -1) { if (inner.slice(pos).trim() !== '') throw new Error('stray text in layer'); break; }
    const prelude = inner.slice(pos, open).trim();
    const close = inner.indexOf('}', open);
    const body = inner.slice(open + 1, close);
    if (close === -1 || body.includes('{')) throw new Error(`nested block in ${prelude}`);
    if (prelude.startsWith('@')) throw new Error(`at-rule inside the layer: ${prelude}`);
    for (const sel of splitTop(prelude)) {
      if (!sel.startsWith(':where(.sfx-prose)')) throw new Error(`selector not anchored: ${sel}`);
      if (sel !== ':where(.sfx-prose)' && !sel.replace(/::[a-z-]+$/, '').endsWith(EXCLUDE)) throw new Error(`Bricks elements not excluded: ${sel}`);
    }
    if (/!\s*important/i.test(body)) throw new Error(`!important in ${prelude}`);
    if (/\d(\.\d+)?rem\b/.test(body)) throw new Error(`rem literal in ${prelude}`);
    checkVars(body, prelude);
    count++;
    pos = close + 1;
  }
  return count;
}

// 1. The real file passes and has the expected size.
const n = check(SOURCE);
assert.ok(n >= 20, `1: rules found (${n})`);

// 2. The checker rejects each kind of violation (so a pass above means something).
const bad = {
  'rule outside the layer': SOURCE + '\nbody { color: red !important; }',
  'second layer': SOURCE + '\n@layer sfx.theme { :where(.sfx-prose) p' + EXCLUDE + ' { color: red; } }',
  'unanchored selector': SOURCE.replace(':where(.sfx-prose) hr:', 'hr:'),
  'missing exclusion': SOURCE.replace(':where(.sfx-prose) hr' + EXCLUDE, ':where(.sfx-prose) hr'),
  'important': SOURCE.replace('cursor: pointer;', 'cursor: pointer !important;'),
  'IMPORTANT spaced': SOURCE.replace('cursor: pointer;', 'cursor: pointer ! IMPORTANT;'),
  'rem literal': SOURCE.replace('padding: 0.1em 0.3em;', 'padding: 0.1rem 0.3em;'),
  'var without fallback': SOURCE.replace('var(--text-body, inherit)', 'var(--text-body)'),
  'inner var without fallback': SOURCE.replace('var(--link, var(--primary, currentColor))', 'var(--link, var(--primary))'),
  'nested at-rule': SOURCE.replace('@layer sfx.components {', '@layer sfx.components {\n@media (min-width: 1px) { :where(.sfx-prose) { color: red; } }'),
  'order statement missing': SOURCE.replace(ORDER, ''),
};
for (const [label, text] of Object.entries(bad)) {
  assert.notEqual(text, SOURCE, `2: fixture "${label}" actually changes the file`);
  assert.throws(() => check(text), `2: checker rejects "${label}"`);
}

// 3. Link rules exclude button links.
const linkPreludes = SOURCE.split('{').map((x) => x.split('}').pop().trim()).filter((p) => /\) a(:|$)/.test(p));
assert.ok(linkPreludes.length >= 2, '3: link and hover rules present');
for (const p of linkPreludes) assert.ok(p.includes(':not(.wp-block-button__link, .wp-element-button)'), `3: button links excluded in ${p}`);

console.log('editor-prose-baseline-test: PASS');
