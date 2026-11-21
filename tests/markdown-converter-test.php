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
    . '<iframe src="/x"></iframe><button>b</button><select><option>o</option></select><textarea>t</textarea>'
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
assert_same("1.\n2. second\n", md('<ol><li></li><li>second</li></ol>'), '9: empty first item keeps its number');
assert_same("3. a\n4.\n5. c\n", md('<ol start="3"><li>a</li><li><script>x</script></li><li>c</li></ol>'), '9: emptied item keeps its number');
assert_same("- text\n\n  1.\n  2. b\n", md('<ul><li>text<ol><li></li><li>b</li></ol></li></ul>'), '9: list with an empty first item does not follow a line directly');
assert_same("## Studio \\#\n", md('<h2>Studio #</h2>'), '9: heading trailing # (Escape::heading_tail)');

echo "PASS: markdown-converter ({$checks} checks)\n";
