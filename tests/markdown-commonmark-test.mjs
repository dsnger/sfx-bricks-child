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
  assert.equal(typeof cases[name], 'string', `case ${name} produced no Markdown`);
  return writer.render(reader.parse(cases[name]));
};

let checks = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); checks++; };

// Text that looks like HTML, a link, or a block start stays text.
ok(!render('text_img').includes('<img'), 'text <img src=x> became an element');
ok(render('text_img').includes('&lt;img src=x&gt;'), 'text <img src=x> not shown literally');
ok(!render('text_link').includes('<a'), 'text [a](b) became a link');
ok(!render('text_autolink').includes('<a'), 'text <http://x.test> became a link');
ok(!render('text_html_block').includes('<div'), 'text <div> became raw HTML');
for (const [name, tag] of [['start_ol', '<ol'], ['start_ul', '<ul'], ['start_h1', '<h1'], ['start_quote', '<blockquote'], ['start_plus', '<ul'], ['start_hr', '<hr'], ['start_fence', '<pre']]) {
  ok(!render(name).includes(tag), `${name} line-start text became ${tag}`);
}

// Links: odd URLs keep one <a>, dangerous schemes never reach an href.
const link = render('link_odd_url');
ok((link.match(/<a /g) || []).length === 1, 'link with spaces/<> is not exactly one <a>');
ok(/href="[^"]*%20[^"]*"/.test(link) && !/href="[^"]*[ <>]/.test(link), 'href not encoded: ' + link);
ok(!/href="\s*javascript:/i.test(render('link_js')), 'javascript: reached an href');

// Code spans and headings.
ok(render('code_backticks').includes('<code>a`b``c</code>'), 'code span text changed: ' + render('code_backticks'));
ok(render('heading_hash').includes('<h2>Title ##</h2>') || render('heading_hash').includes('<h2>Title #</h2>'), 'trailing # lost: ' + render('heading_hash'));

// Entities in text are literal.
ok(render('entity_amp').includes('&amp;amp;'), 'text &amp; was decoded: ' + render('entity_amp'));
ok(render('entity_copy').includes('&amp;copy;') && !render('entity_copy').includes('©'), 'text &copy; became a symbol: ' + render('entity_copy'));

console.log(`markdown-commonmark-test: ${checks} checks passed`);
