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

// Inline code is plain text; headings.
ok(render('code_backticks').includes('<p>a`b``c</p>'), 'code text changed: ' + render('code_backticks'));
ok(render('heading_hash').includes('<h2>Title ##</h2>'), 'trailing # lost: ' + render('heading_hash'));

// Entities in text are literal.
ok(render('entity_amp').includes('&amp;amp;'), 'text &amp; was decoded: ' + render('entity_amp'));
ok(render('entity_copy').includes('&amp;copy;') && !render('entity_copy').includes('©'), 'text &copy; became a symbol: ' + render('entity_copy'));

// Gate B mdgb4k7r2x. textOf: what a reader sees, tags removed.
const textOf = (html) => html.replace(/<br \/>\n?/g, '\n').replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&').trim();
const means = (name, fragment, text) => {
  const html = render(name);
  ok(html.includes(fragment), `${name} did not render ${fragment}: ${html}`);
  if (text !== undefined) ok(textOf(html) === text, `${name} text is not ${JSON.stringify(text)}: ${JSON.stringify(textOf(html))}`);
};
// Any raw HTML node, inline or block, in the parsed document.
const rawHtml = (name) => {
  const w = reader.parse(cases[name]).walker();
  let e;
  while ((e = w.next())) if (e.entering && (e.node.type === 'html_inline' || e.node.type === 'html_block')) return true;
  return false;
};
// A br inside emphasis stays a hard break.
means('br_emph_start', '<p>First<br />\nSecond</p>', 'First\nSecond');
means('br_emph_mid', '<p>First<br />\nSecond</p>', 'First\nSecond');
means('br_emph_end', '<p>First<br />\nSecond</p>', 'First\nSecond');
means('br_emph_nested', '<p>ab<br />\nc</p>', 'ab\nc');
// Neighbouring inline elements: plain text, text intact.
means('strong_strong', '<p>FirstSecond</p>');
means('em_strong', '<p>FirstSecond</p>');
means('em_punct_strong', '<p>First.Second</p>');
means('flank_open', '<p>x(b)</p>');
means('flank_link', '<p>a.b</p>');
means('code_code', '<p>FirstSecond</p>');
// The two review reproductions: an escaped tag stays text.
means('code_img', '<p>a`&lt;img src=x onerror=alert(1)&gt;</p>');
means('code_link_img', '<p>a`&lt;img src=x onerror=alert(1)&gt;</p>');
// Unicode spaces at an emphasis edge: the visible text, no stray *.
means('nbsp_edge', '<p>', 'FirstSecond\u00A0end');
means('emsp_edge', '<p>', 'ab\u2003tail');
// Markdown delimiters inside strong/em/code render literally.
means('literal_marks', '<p>**a** *b* _c_ `d` ``e``</p>');
// Two adjacent Bricks button links stay two links with a space between them.
means('button_links', '<p><a href="https://ex.test/a">A</a> <a href="https://ex.test/b">B</a></p>');
means('link_code', '<p><a href="https://ex.test/x">a</a>b<a href="https://ex.test/y">c</a></p>');
means('bang_image', '<p>Hi!<img src="https://ex.test/i.png" alt="i" /><img src="https://ex.test/j.png" alt="j" /></p>');
means('emph_code', '<p>abc</p>');
const inline = ['br_emph_start', 'br_emph_mid', 'br_emph_end', 'br_emph_nested', 'strong_strong', 'em_strong', 'em_punct_strong', 'flank_open', 'flank_link', 'code_code', 'code_img', 'code_link_img', 'nbsp_edge', 'emsp_edge', 'literal_marks', 'emph_code'];
for (const name of inline) {
  const html = render(name);
  ok(!/<(strong|em|code)>/.test(html), `${name} rendered emphasis or code: ${html}`);
  ok(!html.includes('<img'), `${name} rendered an <img: ${html}`);
  ok(!rawHtml(name), `${name} holds raw HTML: ${cases[name]}`);
}
for (const name of ['strong_strong', 'em_strong', 'em_punct_strong', 'flank_open', 'flank_link', 'code_code', 'nbsp_edge', 'emsp_edge', 'emph_code']) {
  ok(!/[*`]/.test(textOf(render(name))), `${name} shows a delimiter as text: ${render(name)}`);
}
// A list right after a list of its kind keeps its own identity and start.
const ols = render('lists_ol');
ok((ols.match(/<ol/g) || []).length === 2 && ols.includes('<ol start="10">') && ols.includes('<ol start="20">'), 'adjacent ordered lists merged or lost their start: ' + ols);
ok((render('lists_ul').match(/<ul>/g) || []).length === 3, 'adjacent bullet lists merged: ' + render('lists_ul'));
// xmp text is kept, as a code block.
means('xmp', '<pre><code>VISIBLE &lt;b&gt;EXAMPLE&lt;/b&gt;\n</code></pre>\n<p>Other</p>');
// Gate B pass 4: an empty ordered item keeps its position, so the next one stays number 2.
means('ol_empty_first', '<ol>\n<li></li>\n<li>second</li>\n</ol>');
// Inside an item it cannot follow the text line directly (a blank line, so a loose item), but it stays a list.
means('ol_empty_nested', '<p>text</p>\n<ol>\n<li></li>\n<li>b</li>\n</ol>');
// llms.txt: a trailing # in the site name stays heading text.
means('llms_heading', '<h1>Studio #</h1>');
// ... also when spaces or a tab follow it.
means('llms_heading_space', '<h1>Studio #</h1>');

console.log(`markdown-commonmark-test: ${checks} checks passed`);
