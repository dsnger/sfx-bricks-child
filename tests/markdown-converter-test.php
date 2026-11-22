<?php

declare(strict_types=1);

// Core functions the HTML processor calls on its error paths.
function __($text, $domain = 'default')
{
    return $text;
}
function _doing_it_wrong($function, $message, $version): void
{
}
function wp_trigger_error($function, $message, $level = E_USER_NOTICE): void
{
}

require_once __DIR__ . '/support/markdown-http-stubs.php';
require_once __DIR__ . '/support/html-api.php';
require_once __DIR__ . '/../inc/Markdown/Url.php';
require_once __DIR__ . '/../inc/Markdown/Escape.php';
require_once __DIR__ . '/../inc/Markdown/Converter.php';

use SFX\Markdown\Converter;

$checks = 0;
function assert_same($expected, $actual, string $message): void
{
    global $checks;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    $checks++;
}

const BASE = 'https://ex.test/page/';

/** A Bricks-like page: header and footer around the content area. */
function page(string $content): string
{
    return '<!DOCTYPE html><html><head><title>T</title></head><body>'
        . '<header id="brx-header"><nav><a href="/">Home</a></nav>Head</header>'
        . '<main id="brx-content">' . $content . '</main>'
        . '<footer id="brx-footer">Foot</footer></body></html>';
}

function md(string $content): ?string
{
    return Converter::convert(page($content), BASE);
}

// 1. Mapped elements.
assert_same("# A\n\n## B\n\n### C\n\n#### D\n\n##### E\n\n###### F\n", md('<h1>A</h1><h2>B</h2><h3>C</h3><h4>D</h4><h5>E</h5><h6>F</h6>'), '1: headings');
assert_same("One\n\nTwo\n", md('<p>One</p><p>Two</p>'), '1: paragraphs');
assert_same("a\\\nb\n", md('<p>a <br> b</p>'), '1: br is a hard break');
assert_same("a\n", md('<p>a<br></p>'), '1: trailing br dropped');
assert_same("- a\n- b\n", md('<ul><li>a</li><li>b</li></ul>'), '1: ul');
assert_same("1. a\n2. b\n", md('<ol><li>a</li><li>b</li></ol>'), '1: ol');
assert_same("10. ten\n    - a\n    - b\\\n      c\n11. eleven\n", md('<ol start="10"><li>ten<ul><li>a</li><li>b<br>c</li></ul></li><li>eleven</li></ol>'), '1: ul in ol, indent 4 and 2');
assert_same("1. p1\n\n   p2\n", md('<ol><li><p>p1</p><p>p2</p></li></ol>'), '1: continuation indent 3');
assert_same("- a\n", md('<ul><li> </li><li>a</li><li><span></span></li></ul>'), '1: empty items skipped');
assert_same("[X](<https://ex.test/x>)\n", md('<p><a href="/x">X</a></p>'), '1: link');
assert_same("plain\n", md('<p><a>plain</a></p>'), '1: a without href unwrapped');
assert_same("![A](<https://ex.test/a.png>)\n", md('<p><img src="/a.png" alt="A"></p>'), '1: image');
assert_same("![](<https://ex.test/a.png>)\n", md('<img src="/a.png">'), '1: image without alt');
assert_same("s b e i\n", md('<p><strong>s</strong> <b>b</b> <em>e</em> <i>i</i></p>'), '1: emphasis unwrapped');
assert_same("a b c\n", md('<p>a<strong> b </strong>c</p>'), '1: spaces inside emphasis kept');
assert_same("> q1\n>\n> q2\n", md('<blockquote><p>q1</p><p>q2</p></blockquote>'), '1: blockquote');
assert_same("```\na <b>\n  c\n```\n", md("<pre>\na &lt;b&gt;\n  c\n</pre>"), '1: pre');
assert_same("```\na\nb\n```\n", md('<pre><code>a<br>b</code></pre>'), '1: code and br inside pre');
assert_same("use x\\*y\n", md('<p>use <code>x*y</code></p>'), '1: inline code unwrapped, escaped');
assert_same("a\n\n---\n\nb\n", md('<p>a</p><hr><p>b</p>'), '1: hr');
assert_same("| A | B |\n| --- | --- |\n| 1 | 2\\|3 |\n| a\\|b |  |\n", md('<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2|3</td></tr><tr><td><code>a|b</code></td><td></td></tr></tbody></table>'), '1: GFM table, pipes escaped');
assert_same("a | b\n\nc\n\nx\\|y\n", md('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr><tr><td><code>x|y</code></td></tr></table>'), '1: ragged table, one line per row');
assert_same("Cap\n\n| A |\n| --- |\n| 1 |\n", md('<table><caption>Cap</caption><tr><th>A</th></tr><tr><td>1</td></tr></table>'), '1: caption before the table');
assert_same("![F](<https://ex.test/f.png>)\n\nCap\n", md('<figure><img src="/f.png" alt="F"><figcaption>Cap</figcaption></figure>'), '1: figure');
assert_same("![alt](<https://ex.test/a.png>)\n", md('<img src="data:image/svg+xml,%3Csvg%3E" data-src="/a.png" alt="alt">'), '1: Bricks lazy image');
assert_same("![alt](<https://ex.test/a.png>)\n", md('<img data-src="/a.png" alt="alt">'), '1: data-src without src');
assert_same("one\n\ntwo\n", md('<div>one</div><div>two</div>'), '1: div is a block boundary');
assert_same("ab\n", md('<p><span>a</span><span>b</span></p>'), '1: span unwrapped');

// 2. Dropped subtrees.
assert_same("keep\n\nend\n", md('<p>keep</p><script>s</script><style>x</style><svg><text>svgtext</text></svg><form><p>formtext</p></form>'
    . '<nav>navtext</nav><div hidden>h</div><div aria-hidden="true">ah</div><div role="navigation">rn</div>'
    . '<div class="x bricks-pagination">pg</div><header id="brx-header">hd</header><noscript>ns</noscript><template>tp</template>'
    . '<iframe src="/x"></iframe><form><button>b</button></form><select><option>o</option></select><textarea>t</textarea>'
    . '<video>v</video><audio>au</audio><canvas>c</canvas><object>ob</object><dialog>d</dialog><p>end</p>'), '2: dropped elements');
assert_same("a\n", md('<p>a<input hidden><embed src="/x"></p>'), '2: dropped void elements');

// 3. Slider slides stay in order; whitespace.
assert_same("### S1\n\n### S2\n", md('<div class="splide"><div class="splide__track"><div class="splide__list"><div class="splide__slide"><h3>S1</h3></div><div class="splide__slide"><h3>S2</h3></div></div></div></div>'), '3: slides in order');
assert_same("a b\n\nc\n", md("<p>  a \n\t b  </p>\n\n\n<div>\n</div><div></div><p>c</p>"), '3: whitespace collapse, one blank line');
assert_same("a\u{00A0}b\n", md('<p>a&nbsp;b</p>'), '3: nbsp kept');

// 4. Root cases.
assert_same(null, Converter::convert('<html><body><p>a</p></body></html>', BASE), '4: no root');
assert_same(null, Converter::convert('<main id="brx-content"><p>a</p></main><div id="brx-content"><p>b</p></div>', BASE), '4: two roots');
assert_same(null, Converter::convert('<main id="brx-content"><p>a</p><div id="brx-content">b</div></main>', BASE), '4: nested second root');
assert_same(null, Converter::convert('<main id="brx-content" hidden><p>a</p></main>', BASE), '4: hidden root');
assert_same(null, Converter::convert('<main id="brx-content" aria-hidden="true"><p>a</p></main>', BASE), '4: aria-hidden root');
assert_same(null, Converter::convert('<nav id="brx-content"><p>a</p></nav>', BASE), '4: dropped tag as root');
assert_same(null, Converter::convert('<nav><div id="brx-content"><p>a</p></div></nav>', BASE), '4: root inside nav');
assert_same(null, Converter::convert('<main id="brx-content"><p>a</p></main><nav><div id="brx-content"></div></nav>', BASE), '4: second root inside a dropped element');
assert_same(null, md(' <div> </div> <script>x</script> '), '4: empty root');
assert_same(null, Converter::convert('<img id="brx-content" src="/a.png">', BASE), '4: void root');
assert_same(null, Converter::convert('<main id="brx-content"><p>a<span cla', BASE), '4: truncated input');
assert_same(null, Converter::convert('<main id="brx-content"><p>a<b', BASE), '4: incomplete tag');
assert_same(null, Converter::convert('<main id="brx-content"><p>a<img src="/x', BASE), '4: incomplete attribute');
assert_same(null, Converter::convert('<main id="brx-content"><table><tr>x<td>1</td></tr></table></main>', BASE), '4: unsupported markup (foster parenting)');
assert_same("a\n", Converter::convert('<main id="brx-content"><p>a</p><footer id="brx-footer">Foot</footer>', BASE), '4: unclosed root, footer dropped');
assert_same("a\n", Converter::convert('<p>out</p><main id="brx-content"><p>a</p></main><p>after</p>', BASE), '4: only the root');
assert_same(null, md('<ul><div>x</div></ul>'), '4: non-li child of a list');

// 5. Adversarial text and URLs.
assert_same("\\<img src=x\\>\n", md('<p>&lt;img src=x&gt;</p>'), '5: entity tag stays text');
assert_same("\\& ©\n", md('<p>&amp; &copy;</p>'), '5: entities as text');
assert_same("[x](<https://ex.test/a?b=1\\&c=2>)\n", md('<a href="/a?b=1&amp;c=2">x</a>'), '5: &amp; in href');
assert_same("[x](<https://ex.test/%C2%A9>)\n", md('<a href="/&copy;">x</a>'), '5: &copy; in href');
assert_same("## # a \\#\n", md('<h2># a #</h2>'), '5: heading trailing # escaped');
assert_same("\\`\\`\n", md('<p><code>``</code></p>'), '5: backtick-only code is escaped text');
assert_same("ab\n", md('<p>a<code></code>b</p>'), '5: empty code skipped');
assert_same("x\n", md('<p><a href="javascript:alert(1)">x</a></p>'), '5: unsafe link keeps text');
assert_same("A\n", md('<p><img src="javascript:x" alt="A"></p>'), '5: unsafe image keeps alt');
assert_same("\\- a\n\n1\\. b\n\n\\# c\n", md('<p>- a</p><p>1. b</p><p># c</p>'), '5: line-start markers escaped');
assert_same("a\\\n\\- b\n", md('<p>a<br>- b</p>'), '5: line start after br escaped');
assert_same("\\[a\\](b) \\*c\\* \\!\\[d\\]\n", md('<p>[a](b) *c* ![d]</p>'), '5: inline markup escaped');
assert_same("[\\] x](<https://ex.test/y>)\n", md('<a href="/y">] x</a>'), '5: label bracket escaped');

assert_same("| [l](<https://a.test/x%7C[y](javascript:alert(1))>) | 2 |\n| --- | --- |\n", md('<table><tr><td><a href="https://a.test/x|[y](javascript:alert(1))">l</a></td><td>2</td></tr></table>'), '5: | in a link destination in a GFM cell is encoded');
assert_same("| ![i](<https://a.test/i.png%7C![y](https://evil/)>) | 2 |\n| --- | --- |\n", md('<table><tr><td><img alt="i" src="https://a.test/i.png|![y](https://evil/)"></td><td>2</td></tr></table>'), '5: | in an image destination in a GFM cell is encoded');

// 6. Review Focus 1, 2, 3, 5.
assert_same("[Text](<https://ex.test/go>)\n", md('<a class="brxe-button" href="/go"><i class="ti-arrow"></i><span>Text</span></a>'), 'RF1: button with icon font');
assert_same("[Go](<https://ex.test/go>)\n", md('<a href="/go"><svg viewBox="0 0 1 1"><path d="M0 0"/></svg> Go</a>'), 'RF1: inline svg icon');
assert_same("before\n\n[Title teaser](<https://ex.test/card>)\n\nafter\n", md('<p>before</p><a href="/card"><div><h3>Title</h3><p>teaser</p></div></a><p>after</p>'), 'RF2: card link');
assert_same("[a b](<https://ex.test/c>)\n", md('<a href="/c"><ul><li>a</li><li>b</li></ul></a>'), 'RF2: list in a link flattened');
assert_same("![](<https://ex.test/logo.png>)\n", md('<a href="/home"><img src="/logo.png"></a>'), 'RF3: image-only link without alt');
assert_same("![Logo](<https://ex.test/l.png>)\n", md('<a href="/home"><img alt="Logo" src="/l.png"></a>'), 'RF3: image-only link with alt');
assert_same("x\n", md('<p>x</p><a href="/h"><img src="data:x"></a>'), 'RF3: link with a dropped image and no text is dropped');
assert_same("[![](<https://ex.test/i.png>) Shop](<https://ex.test/s>)\n", md('<a href="/s"><img src="/i.png"> Shop</a>'), 'RF3: image and text in a link');
assert_same("x\n", md(str_repeat('<div>', 300) . '<p>x</p>' . str_repeat('</div>', 300)), 'RF5: 300 nested divs');

// 7. Served pages from the demo site (trimmed).
$unescaped_lt = static function (string $md): int {
    // Our own destinations are written as <…>; any other < must be escaped.
    $md = (string) preg_replace('/\]\(<(?:\\\\.|[^\\\\>\n])*>\)/', '](', $md);
    return preg_match_all('/(?<!\\\\)</', $md);
};
$front = Converter::convert((string) file_get_contents(__DIR__ . '/support/markdown-fixtures/front-page.html'), 'https://sfx-bricks-child.ddev.site/');
assert_same(true, is_string($front), '7: front page converts');
assert_same(true, str_contains($front, "# Hier die Hauptüberschrift der Seite eingeben"), '7: front page h1');
assert_same(true, str_contains($front, "\n## Lorem ipsum headline\n") || str_contains($front, "\n### Lorem ipsum headline\n"), '7: front page section heading');
assert_same(false, str_contains($front, 'Anmeldung für Mitglieder'), '7: front page has no header text');
assert_same(0, $unescaped_lt($front), '7: front page has no <');
$prose = Converter::convert((string) file_get_contents(__DIR__ . '/support/markdown-fixtures/editor-prose-test.html'), 'https://sfx-bricks-child.ddev.site/editor-prose-test/');
assert_same(true, is_string($prose), '7: editor page converts');
assert_same(true, str_contains($prose, "\n## Eine Zwischenüberschrift H2\n"), '7: editor page h2');
assert_same(true, str_contains($prose, "\n### Eine H3\n"), '7: editor page h3');
assert_same(false, str_contains($prose, 'Anmeldung für Mitglieder'), '7: editor page has no header text');
assert_same(0, $unescaped_lt($prose), '7: editor page has no <');

// 8. Gate B mdgb4k7r2x. strong/b, em/i and code are unwrapped (spec § Changes 2026-11-21).
// spec:1 a br inside emphasis is a hard break; inside a heading or link label it is a space.
assert_same("First\\\nSecond\n", md('<p>First<strong><br>Second</strong></p>'), '8: br at the start of emphasis');
assert_same("First\\\nSecond\n", md('<p><strong>First<br>Second</strong></p>'), '8: br inside emphasis');
assert_same("First\\\nSecond\n", md('<p><strong>First<br></strong>Second</p>'), '8: br at the end of emphasis');
assert_same("ab\\\nc\n", md('<p><strong>a<em>b<br>c</em></strong></p>'), '8: br in nested emphasis');
assert_same("- a\\\n  b\n", md('<ul><li><strong>a<br>b</strong></li></ul>'), '8: br in emphasis in a list item');
assert_same("a\n", md('<p><strong><br>a</strong></p>'), '8: br at the start of a paragraph dropped');
assert_same("## a b\n", md('<h2><em>a<br>b</em></h2>'), '8: br in emphasis in a heading is a space');
assert_same("[a b](<https://ex.test/x>)\n", md('<p><a href="/x"><em>a<br>b</em></a></p>'), '8: br in emphasis in a link is a space');
// spec:2 / quality:1 neighbouring inline elements: plain text, no delimiters to collide.
assert_same("FirstSecond\n", md('<p><strong>First</strong><strong>Second</strong></p>'), '8: strong+strong');
assert_same("FirstSecond\n", md('<p><em>First</em><strong>Second</strong></p>'), '8: em+strong');
assert_same("x(b)\n", md('<p>x<strong>(b)</strong></p>'), '8: emphasis after a letter');
assert_same("ab\n", md('<p><strong><em>a</em></strong><strong>b</strong></p>'), '8: nested emphasis next to emphasis');
assert_same("a\\`\\<img src=x onerror=alert(1)\\>\n", md('<p><code>a</code><code>`</code><code>&lt;img src=x onerror=alert(1)&gt;</code></p>'), '8: quality pass-1 adjacent code spans');
assert_same("a\\`\\<img src=x onerror=alert(1)\\>\n", md('<p><a href="ftp://files.example/"><code>a</code></a><code>`</code><code>&lt;img src=x onerror=alert(1)&gt;</code></p>'), '8: quality pass-2 code inside a dropped link');
assert_same("| a\\|b |\n| --- |\n", md('<table><tr><td><code>a|</code><code>b</code></td></tr></table>'), '8: code in a cell keeps its pipe escape');
assert_same("\u{00A0}x\n", md('<p><strong>&nbsp;x</strong></p>'), '8: emphasis starting with nbsp');
assert_same("FirstSecond\u{00A0}end\n", md('<p><strong>First</strong><strong>Second&nbsp;</strong>end</p>'), '8: spec pass-2 trailing nbsp');
assert_same("ab\u{2003}tail\n", md('<p><strong>a</strong><em>b&#x2003;</em>tail</p>'), '8: quality pass-2 trailing em space');
assert_same("## a b \\`c\\`\n", md('<h2>a<pre>b `c`</pre></h2>'), '8: pre inside a line is plain text');
// spec:3 adjacent lists alternate their delimiter.
assert_same("10. ten\n\n20) twenty\n", md('<ol start="10"><li>ten</li></ol><ol start="20"><li>twenty</li></ol>'), '8: adjacent ordered lists');
assert_same("- a\n\n* b\n\n- c\n", md('<ul><li>a</li></ul><ul><li>b</li></ul><ul><li>c</li></ul>'), '8: adjacent bullet lists');
assert_same("- a\n\n1. b\n", md('<ul><li>a</li></ul><ol><li>b</li></ol>'), '8: lists of different kinds keep their delimiter');
// spec:4 atomic elements: xmp text kept as code, listing like pre, invisible ones dropped.
assert_same("```\nVISIBLE <b>EXAMPLE</b>\n```\n\nOther\n", md('<xmp>VISIBLE <b>EXAMPLE</b></xmp><p>Other</p>'), '8: xmp and paragraph');
assert_same("[x](<https://ex.test/l>)\n", md('<a href="/l"><xmp>x</xmp></a>'), '8: xmp inline is plain text');
assert_same("```\nL1\nL2\n```\n", md("<listing>L1\nL2</listing>"), '8: listing like pre');
assert_same("x\n", md('<title>T</title><noembed>n</noembed><noframes>f</noframes><p>x</p>'), '8: invisible atomic elements dropped');

// Gate B pass 4: ordered-list numbers stay what the browser shows.
assert_same("999999999. a\n", md('<ol start="999999999"><li>a</li></ol>'), '9: nine-digit marker');
assert_same(null, md('<ol start="999999999"><li>a</li><li>b</li></ol>'), '9: a ten-digit marker passes through');
assert_same(null, md('<ol start="1000000000"><li>a</li></ol>'), '9: a ten-digit start passes through');
assert_same(null, md('<ol start="-2"><li>a</li></ol>'), '9: a negative start passes through');
assert_same(null, md('<ol start="3x"><li>a</li></ol>'), '9: a malformed start passes through');
assert_same(null, md('<ol reversed><li>a</li><li>b</li></ol>'), '9: a reversed list passes through');
assert_same(null, md('<ol><li>a</li><li value="7">b</li></ol>'), '9: an item value passes through');
assert_same("- a\n- b\n", md('<ul><li value="7">a</li><li>b</li></ul>'), '9: value on a bullet item changes nothing');
assert_same("7. a\n", md('<ol start=" 7 "><li>a</li></ol>'), '9: a start with spaces is read as its number');
foreach ([
    'table cell' => '<table><tr><td>%s</td></tr></table>',
    'link label' => '<p><a href="/x">%s</a></p>',
    'caption' => '<table><caption>%s</caption><tr><td>c</td></tr></table>',
    'heading' => '<h2>%s</h2>',
    'pre' => '<pre>%s</pre>',
] as $where => $wrap) {
    foreach (['<ol start="-1"><li>a</li></ol>', '<ol reversed><li>a</li></ol>', '<ol><li value="7">a</li></ol>'] as $list) {
        assert_same(null, md(sprintf($wrap, $list)), "9: unkeepable numbering in a $where passes through");
    }
}
assert_same(null, Converter::convert('<html><body><ol id="brx-content" reversed><li>a</li><li>b</li></ol></body></html>', BASE), '9: a reversed OL as the content root passes through');
assert_same(null, md('<ol><li><ul><li>x</li></ul><span>y</span></li><li value="3">b</li></ol>'), '9: li value after a nested UL still belongs to the OL');
assert_same("1.\n2. second\n", md('<ol><li></li><li>second</li></ol>'), '9: empty first item keeps its number');
assert_same("3. a\n4.\n5. c\n", md('<ol start="3"><li>a</li><li><script>x</script></li><li>c</li></ol>'), '9: emptied item keeps its number');
assert_same("- text\n\n  1.\n  2. b\n", md('<ul><li>text<ol><li></li><li>b</li></ol></li></ul>'), '9: list with an empty first item does not follow a line directly');
assert_same("## Studio \\#\n", md('<h2>Studio #</h2>'), '9: heading trailing # (Escape::heading_tail)');

// 10. Buttons are unwrapped (spec Changes 2026-11-22): a Bricks accordion title is a button.
assert_same("### Title\n\nPanel text\n", md('<div class="brxe-accordion-nested"><div class="brxe-block listening">'
    . '<button class="brxe-block accordion-title-wrapper" role="button" aria-expanded="false" aria-controls="p1"><h3 class="brxe-heading">Title</h3><span class="brxe-icon"><svg viewBox="0 0 1 1"><path d="M0 0"/></svg></span></button>'
    . '<div class="brxe-block accordion-content-wrapper" id="p1"><p>Panel text</p></div></div></div>'), '10: accordion title inside a button kept');
assert_same("a\n", md('<p>a</p><button><svg viewBox="0 0 1 1"><path d="M0 0"/></svg></button>'), '10: icon-only button yields nothing');
assert_same("a\n", md('<p>a</p><form><button>Send</button></form>'), '10: button inside a form dropped with it');
// Adjacent Bricks buttons are separate words.
assert_same("Mehr Infos Mehr Infos\n", md('<div><span class="brxe-button bricks-button">Mehr Infos</span><span class="brxe-button bricks-button">Mehr Infos</span></div>'), '10: adjacent span buttons separated');
assert_same("[A](<https://ex.test/a>) [B](<https://ex.test/b>)\n", md('<div><a class="brxe-button" href="/a">A</a><a class="brxe-button" href="/b">B</a></div>'), '10: adjacent linked buttons separated');
assert_same("Read [more](<https://ex.test/m>) now.\n", md('<p>Read <a class="brxe-button" href="/m">more</a> now.</p>'), '10: button in a sentence keeps normal spacing');
assert_same("x A B y\n", md('<p>x <span class="brxe-button">A</span> <span class="brxe-button"> B </span> y</p>'), '10: no double spaces around buttons');

// 11. Readability (spec § Changes 2026-11-22, Bricks element coverage test). Fixtures trimmed from Bricks 2.4.6 output.
// Parts of one value in adjacent tags are separate words.
assert_same("- $39 $ 29 90\n\n  pro Monat\n", md('<div class="brxe-pricing-tables"><ul class="pricing-tables"><li class="pricing-table repeater-item"><div class="pricing-table-pricing"><div class="pricing-table-price-wrapper"><span class="pricing-table-original-price">$39</span><span class="pricing-table-price-prefix">$</span><span class="pricing-table-price">29</span><span class="pricing-table-price-suffix">90</span></div><span class="pricing-table-price-meta">pro Monat</span></div></li></ul></div>'), '11: pricing table price parts separated');
assert_same("DevAdmin November 22, 2026 0 Kommentare\n", md('<div class="brxe-post-meta post-meta"><span class="item">DevAdmin</span><span class="item">November 22, 2026</span><span class="item">0 Kommentare</span></div>'), '11: post meta items separated');
assert_same("2026 · 0 Kommentare\n", md('<div class="brxe-post-meta post-meta"><span class="item">2026</span><span class="separator">·</span><span class="item">0 Kommentare</span></div>'), '11: post meta separator from the HTML kept');
assert_same("Webdesign 80%\n\nSEO 90%\n", md('<div class="brxe-progress-bar"><div class="bar-wrapper"><label><span class="label">Webdesign</span><span class="percentage">80%</span></label><div class="bar"><span data-width="80%"></span></div></div><div class="bar-wrapper"><label><span class="label">SEO</span><span class="percentage">90%</span></label><div class="bar"><span data-width="90%"></span></div></div></div>'), '11: progress bar label and percent separated');
assert_same("- Item $10.00\n\n  Desc\n- [Linked](<https://example.com/>) $5\n", md('<ul class="brxe-list"><li><div class="content"><span class="title">Item</span><span class="separator"></span><span class="meta">$10.00</span></div><div class="description">Desc</div></li><li data-highlight="H"><div class="content"><a href="https://example.com/"><span class="title">Linked</span></a><span class="separator"></span><span class="meta">$5</span></div></li></ul>'), '11: list item title and meta separated');
assert_same("[Report](<https://example.com/a.pdf>) [Download](<https://example.com/a.pdf>)\n", md('<div class="brxe-file file-wrapper"><div class="file-content"><a id="f-file-name" class="file-name" href="https://example.com/a.pdf">Report</a><a class="file-download bricks-button bricks-background-primary" href="https://example.com/a.pdf" download aria-describedby="f-file-name">Download</a></div></div>'), '11: adjacent file links separated');
assert_same("abc [d](<https://ex.test/x>)e fg h.\n", md('<div class="brxe-text"><p>a<strong>b</strong>c <a href="/x">d</a>e <span class="title">f</span>g <span class="item">h</span>.</p></div>'), '11: running text inside a Bricks element keeps its spacing');
// The counter shows its end value, not the start value JS counts up from.
$counter = static fn(string $options, string $count = '0'): ?string => md('<div class="brxe-counter" data-bricks-counter-options="' . htmlspecialchars($options, ENT_QUOTES) . '"><span class="prefix">$</span><span class="count">' . $count . '</span><span class="suffix">+</span></div>');
assert_same("$1000+\n", $counter('{"countFrom":0,"countTo":"1000","duration":1000,"thousands":"","separator":""}'), '11: counter end value with prefix and suffix');
assert_same("$1.000.000+\n", $counter('{"countFrom":0,"countTo":"1000000","duration":1000,"thousands":true,"separator":"."}'), '11: counter thousands separator from the options');
assert_same("$1000000+\n", $counter('{"countFrom":0,"countTo":"1000000","duration":1000,"thousands":true,"separator":""}'), '11: counter locale grouping unknown: plain digits');
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":'), '11: counter invalid JSON keeps the server text');
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":"1k"}'), '11: counter non-numeric end keeps the server text');
assert_same("$50+\n", $counter('{"countFrom":50,"countTo":"10"}', '50'), '11: counter end below start keeps the server text');
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":"5","thousands":true,"separator":"&amp;"}'), '11: counter odd separator keeps the server text');
assert_same("$0+\n", md('<div class="brxe-counter"><span class="prefix">$</span><span class="count">0</span><span class="suffix">+</span></div>'), '11: counter without options keeps the server text');
// Tabs: each pane under its own title.
$tabs = '<div id="brxe-ab3262" class="brxe-tabs horizontal" data-open-tab="0"><ul class="tab-menu" role="tablist"><li class="tab-title repeater-item" role="tab" aria-selected="true" aria-controls="panel-ab3262-0" tabindex="0" id="brx-tab-ab3262-0"><span>Title 1</span></li><li class="tab-title repeater-item" role="tab" aria-selected="false" aria-controls="panel-ab3262-1" tabindex="-1" id="brx-tab-ab3262-1"><span>Title 2</span></li></ul><ul class="tab-content" role="presentation"><li class="tab-pane" role="tabpanel" aria-labelledby="brx-tab-ab3262-0" id="panel-ab3262-0" tabindex="0"><p>Content 1</p>' . "\n" . '</li><li class="tab-pane" role="tabpanel" aria-labelledby="brx-tab-ab3262-1" id="panel-ab3262-1" tabindex="0"><p>Content 2</p>' . "\n" . '</li></ul></div>';
assert_same("## Leistungen\n\n### Title 1\n\nContent 1\n\n### Title 2\n\nContent 2\n\nafter\n", md('<h2>Leistungen</h2>' . $tabs . '<p>after</p>'), '11: repeater tabs, title above its pane, one level below the heading');
assert_same("- a\n\n### Title 1\n\nContent 1\n\n### Title 2\n\nContent 2\n", md('<ul><li>a</li></ul>' . $tabs), '11: tabs without a preceding heading: ###');
$nested = static fn(string $pane1, string $menu_extra = '', string $p2 = 'brxe-f1482f'): string => '<div id="brxe-f378f8" class="brxe-tabs-nested bricks-lazy-hidden" data-open-tab="0"><div id="brxe-fa9f03" class="brxe-block tab-menu bricks-lazy-hidden" role="tablist"><div id="brxe-d2dac1" class="brxe-div tab-title bricks-lazy-hidden" role="tab" aria-selected="true" tabindex="0" aria-controls="brxe-cc086c"><div id="brxe-4c5807" class="brxe-text-basic">T1</div></div><div id="brxe-2e430f" class="brxe-div tab-title bricks-lazy-hidden" role="tab" aria-selected="false" tabindex="-1" aria-controls="brxe-f1482f"><div id="brxe-9304ac" class="brxe-text-basic">T2</div></div>' . $menu_extra . '</div><div id="brxe-d8fbb4" class="brxe-block tab-content bricks-lazy-hidden"><div id="brxe-cc086c" class="brxe-block tab-pane bricks-lazy-hidden" role="tabpanel" aria-labelledby="brxe-d2dac1" tabindex="0">' . $pane1 . '</div><div id="' . $p2 . '" class="brxe-block tab-pane bricks-lazy-hidden" role="tabpanel" aria-labelledby="brxe-2e430f" tabindex="0"><div class="brxe-text"><p>C2</p>' . "\n" . '</div></div></div></div>';
assert_same("##### Pre\n\n###### T1\n\n#### Own\n\nC1\n\n###### T2\n\nC2\n", md('<h5>Pre</h5>' . $nested('<h4 class="brxe-heading">Own</h4><div class="brxe-text"><p>C1</p></div>')), '11: nested tabs, a pane with its own heading');
assert_same("###### Pre\n\n###### T1\n\nC1\n\n###### T2\n\nC2\n", md('<h6>Pre</h6>' . $nested('<p>C1</p>')), '11: tab title heading level stops at 6');
assert_same("T1\n\nT2\n\nT3\n\nC1\n\nC2\n", md($nested('<p>C1</p>', '<div class="brxe-div tab-title" role="tab" aria-controls="brxe-x"><div class="brxe-text-basic">T3</div></div>')), '11: tabs count mismatch falls back to document order');
assert_same("T1\n\nT2\n\nC1\n\nC2\n", md($nested('<p>C1</p>', '', 'brxe-other')), '11: tabs ids that do not pair fall back to document order');
assert_same("- a\n\n* T1\n* T2\n\n- C1\n- C2\n", md('<ul><li>a</li></ul><div class="brxe-tabs"><ul class="tab-menu" role="tablist"><li class="tab-title" role="tab" aria-controls="x1"><span>T1</span></li><li class="tab-title" role="tab" aria-controls="x9"><span>T2</span></li></ul><ul class="tab-content"><li class="tab-pane" id="x1"><p>C1</p></li><li class="tab-pane" id="x2"><p>C2</p></li></ul></div>'), '11: repeater tabs that do not pair keep today\'s output');

// Gate B mdrd5v2n8q pass 1: nested tabs, heading level after a splice, text before the tabs.
$one = static fn(string $title, string $body, string $id): string => '<div class="brxe-tabs-nested"><div class="tab-menu"><div class="tab-title" aria-controls="' . $id . '">' . $title . '</div></div><div class="tab-content"><div class="tab-pane" id="' . $id . '">' . $body . '</div></div></div>';
$inner = '<div class="brxe-tabs-nested"><div class="tab-menu"><div class="tab-title" aria-controls="ia">Inner A</div><div class="tab-title" aria-controls="ib">Inner B</div></div><div class="tab-content"><div class="tab-pane" id="ia"><p>IA</p></div><div class="tab-pane" id="ib"><p>IB</p></div></div></div>';
assert_same("### Outer\n\n#### Inner A\n\nIA\n\n#### Inner B\n\nIB\n", md($one('Outer', $inner, 'outer')), '11: tabs inside a pane paired on their own');
assert_same("### Outer1\n\n#### Inner A\n\nIA\n\n#### Inner B\n\nIB\n\n### Outer2\n\nO2\n", md('<div class="brxe-tabs"><div class="tab-menu"><div class="tab-title" aria-controls="o1">Outer1</div><div class="tab-title" aria-controls="o2">Outer2</div></div><div class="tab-content"><div class="tab-pane" id="o1">' . $inner . '</div><div class="tab-pane" id="o2"><p>O2</p></div></div></div>'), '11: outer tabs stay paired around inner tabs');
assert_same("## Section\n\n### First\n\nC1\n\n#### Second\n\nC2\n", md('<h2>Section</h2>' . $one('<h5>First</h5>', '<p>C1</p>', 'first') . $one('Second', '<p>C2</p>', 'second')), '11: heading level follows the paired output');
assert_same("BEFORE\n\n### T\n\nC\n\nAFTER\n", md('BEFORE<span class="brxe-tabs"><div class="tab-menu"><div class="tab-title" aria-controls="x">T</div></div><div class="tab-content"><div class="tab-pane" id="x">C</div></div></span>AFTER'), '11: text before inline tabs is kept');
assert_same("BEFORE\n\n### T\n\nC\n\nAFTER\n", md('BEFORE<span class="brxe-tabs"><span class="tab-menu"><span class="tab-title" aria-controls="x">T</span></span><span class="tab-content"><span class="tab-pane" id="x">C</span></span></span>AFTER'), '11: text before inline tabs stays out of the title');
// Word boundaries around void Bricks elements and at the start of a link.
assert_same("A ![B](<https://ex.test/x>) C\n", md('<p>A<img class="brxe-image" src="/x" alt="B">C</p>'), '11: Bricks image is a word of its own');
assert_same("pre [middle](<https://ex.test/x>) post\n", md('<p>pre<a href="/x"><span class="brxe-text">middle</span></a>post</p>'), '11: link starting with a Bricks element separated');
assert_same("## pre [middle](<https://ex.test/x>) post\n", md('<h2>pre<a href="/x"><span class="brxe-text">middle</span></a>post</h2>'), '11: the same in a heading');
// Counter values JS would show differently keep the server text.
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":9007199254740993,"thousands":false}'), '11: counter beyond Number.MAX_SAFE_INTEGER keeps the server text');
assert_same("$9007199254740991+\n", $counter('{"countFrom":0,"countTo":9007199254740991,"thousands":false}'), '11: counter at Number.MAX_SAFE_INTEGER converts');
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":"1000","thousands":true,"separator":"$$"}'), '11: counter separator with $$ keeps the server text');
assert_same("$0+\n", $counter('{"countFrom":0,"countTo":"1000","thousands":true,"separator":"$`"}'), '11: counter separator with $` keeps the server text');

echo "PASS: markdown-converter ({$checks} checks)\n";
