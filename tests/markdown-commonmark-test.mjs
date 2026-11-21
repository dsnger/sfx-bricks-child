/**
 * Render the Markdown converter's output with a real CommonMark renderer
 * (vendored commonmark.js, plain CommonMark, no GFM tables) and assert on what
 * the rendered HTML means: page text must never become structure, links or HTML.
 *
 * Run: PHP=php node tests/markdown-commonmark-test.mjs
 */

import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const require = createRequire(import.meta.url);
const commonmark = require('./support/commonmark/commonmark.js');

if (!process.env.PHP) assert.fail('PHP is not set; the converter cannot run (quality.sh exports it)');
const run = spawnSync(process.env.PHP, [join(root, 'tests/support/markdown-convert-cases.php')], { encoding: 'utf8' });
assert.equal(run.status, 0, `cases script failed: ${run.stderr}${run.stdout}`);
const cases = JSON.parse(run.stdout);

const reader = new commonmark.Parser();
const writer = new commonmark.HtmlRenderer();
const render = (name) => {
  assert.ok(typeof cases[name] === 'string' && cases[name] !== '', `case ${name} produced no Markdown`);
  return writer.render(reader.parse(cases[name]));
};

let checks = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); checks++; };

// Text that looks like HTML, a link, or a block start stays text: the literal
// input appears in a single <p>, and nothing became an element.
const textCases = {
  text_img: ['<p>&lt;img src=x&gt;</p>', '<img'],
  text_link: ['<p>[a](b)</p>', '<a'],
  text_autolink: ['<p>&lt;http://x.test&gt;</p>', '<a'],
  text_html_block: ['<p>&lt;div&gt;x&lt;/div&gt;</p>', '<div'],
  start_ol: ['<p>1. not a list</p>', '<ol'],
  start_ul: ['<p>- not a list</p>', '<ul'],
  start_plus: ['<p>+ not a list</p>', '<ul'],
  start_h1: ['<p># not a heading</p>', '<h1'],
  start_quote: ['<p>&gt; not a quote</p>', '<blockquote'],
  start_hr: ['<p>---</p>', '<hr'],
  start_fence: ['<p>```<br />\nnot a fence</p>', '<pre'],
};
for (const [name, [paragraph, tag]] of Object.entries(textCases)) {
  const html = render(name);
  ok(html.includes(paragraph), `${name} did not render as ${paragraph}: ${html}`);
  ok(!html.includes(tag), `${name} became ${tag}: ${html}`);
}

// Links: odd URLs keep one <a>, dangerous schemes never reach an href.
const link = render('link_odd_url');
ok((link.match(/<a /g) || []).length === 1, 'link with spaces/<> is not exactly one <a>');
ok(/href="[^"]*%20[^"]*"/.test(link) && !/href="[^"]*[ <>]/.test(link), 'href not encoded: ' + link);
const js = render('link_js');
ok(js.includes('<p>x y</p>') && !js.includes('<a') && !/javascript:/i.test(js), 'javascript: link not reduced to its text: ' + js);

// Code spans and headings.
ok(render('code_backticks').includes('<code>a`b``c</code>'), 'code span text changed: ' + render('code_backticks'));
ok(render('heading_hash').includes('<h2>Title ##</h2>'), 'trailing # lost: ' + render('heading_hash'));

// Entities in text are literal.
ok(render('entity_amp').includes('&amp;amp;'), 'text &amp; was decoded: ' + render('entity_amp'));
ok(render('entity_copy').includes('&amp;copy;') && !render('entity_copy').includes('©'), 'text &copy; became a symbol: ' + render('entity_copy'));

// Gate B mdgb4k7r2x pass 1. textOf: what a reader sees, tags removed.
const textOf = (html) => html.replace(/<br \/>\n?/g, '\n').replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();
const means = (name, fragment, text) => {
  const html = render(name);
  ok(html.includes(fragment), `${name} did not render ${fragment}: ${html}`);
  if (text !== undefined) ok(textOf(html) === text, `${name} text is not ${JSON.stringify(text)}: ${JSON.stringify(textOf(html))}`);
};
// A br inside emphasis stays a hard break.
means('br_emph_start', '<p>First<br />\n<strong>Second</strong></p>', 'First\nSecond');
means('br_emph_mid', '<p><strong>First<br />\nSecond</strong></p>', 'First\nSecond');
means('br_emph_end', '<p><strong>First</strong><br />\nSecond</p>', 'First\nSecond');
means('br_emph_nested', '<p><strong>a<em>b<br />\nc</em></strong></p>', 'ab\nc');
// Neighbouring inline frames never merge their delimiters: no stray * or `, text intact.
means('strong_strong', '<p><strong>FirstSecond</strong></p>');
means('em_em', '<p><em>FirstSecond</em></p>');
means('em_strong', '<p><em>First</em><strong>Second</strong></p>');
means('strong_em', '<p><strong>First</strong><em>Second</em></p>');
means('em_punct_strong', '<strong>Second</strong>', 'First.Second');
means('em_strong_punct', '<p><em>First</em>', 'First(Second)');
means('flank_open', '<p>x', 'x(b)');
means('flank_close', '<p>', 'a.b');
means('flank_link', '<p>', 'a.b');
means('code_code', '<p><code>FirstSecond</code></p>');
means('code_img', '<p><code>a`&lt;img src=x onerror=alert(1)&gt;</code></p>');
ok(!render('code_img').includes('<img'), 'adjacent code spans let an escaped tag become HTML: ' + render('code_img'));
means('code_text_code', '<p><code>a</code>text<code>b</code></p>');
means('link_code', '<p><a href="https://ex.test/x">a</a><code>b</code><a href="https://ex.test/y">c</a></p>');
means('bang_image', '<p>Hi!<img src="https://ex.test/i.png" alt="i" /><img src="https://ex.test/j.png" alt="j" /></p>');
means('emph_code', '<p><strong>a</strong><code>b</code><strong>c</strong></p>');
for (const name of ['strong_strong', 'em_em', 'em_strong', 'strong_em', 'em_punct_strong', 'em_strong_punct', 'flank_open', 'flank_close', 'flank_link', 'code_code', 'code_text_code', 'emph_code']) {
  ok(!/[*`]/.test(textOf(render(name))), `${name} shows a delimiter as text: ${render(name)}`);
}
// A list right after a list of its kind keeps its own identity and start.
const ols = render('lists_ol');
ok((ols.match(/<ol/g) || []).length === 2 && ols.includes('<ol start="10">') && ols.includes('<ol start="20">'), 'adjacent ordered lists merged or lost their start: ' + ols);
ok((render('lists_ul').match(/<ul>/g) || []).length === 3, 'adjacent bullet lists merged: ' + render('lists_ul'));
// xmp text is kept, as a code block.
means('xmp', '<pre><code>VISIBLE &lt;b&gt;EXAMPLE&lt;/b&gt;\n</code></pre>\n<p>Other</p>');

console.log(`markdown-commonmark-test: ${checks} checks passed`);
