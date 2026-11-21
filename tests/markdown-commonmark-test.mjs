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

console.log(`markdown-commonmark-test: ${checks} checks passed`);
