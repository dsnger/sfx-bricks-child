<?php

declare(strict_types=1);

// Prints {case: markdown} for tests/markdown-commonmark-test.mjs. Page text
// that looks like Markdown or HTML, written as HTML, run through the converter.

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

require_once __DIR__ . '/markdown-http-stubs.php';
require_once __DIR__ . '/html-api.php';
require_once __DIR__ . '/../../inc/Markdown/Url.php';
require_once __DIR__ . '/../../inc/Markdown/Escape.php';
require_once __DIR__ . '/../../inc/Markdown/Converter.php';

use SFX\Markdown\Converter;

$inputs = [
    'text_img'        => '<p>&lt;img src=x&gt;</p>',
    'text_link'       => '<p>[a](b)</p>',
    'text_autolink'   => '<p>&lt;http://x.test&gt;</p>',
    'text_html_block' => '<p>&lt;div&gt;x&lt;/div&gt;</p>',
    'start_ol'        => '<p>1. not a list</p>',
    'start_ul'        => '<p>- not a list</p>',
    'start_plus'      => '<p>+ not a list</p>',
    'start_h1'        => '<p># not a heading</p>',
    'start_quote'     => '<p>&gt; not a quote</p>',
    'start_hr'        => '<p>---</p>',
    'start_fence'     => '<p>```<br>not a fence</p>',
    'link_odd_url'    => '<p><a href="/a b/&lt;c&gt;">odd</a></p>',
    'link_js'         => '<p><a href="javascript:alert(1)">x</a> <a href=" JavaScript:alert(1)">y</a></p>',
    'code_backticks'  => '<p><code>a`b``c</code></p>',
    'heading_hash'    => '<h2>Title ##</h2>',
    'entity_amp'      => '<p>&amp;amp;</p>',
    'entity_copy'     => '<p>&amp;copy;</p>',
    // Gate B mdgb4k7r2x: strong/em/code are unwrapped; hard breaks, neighbours, lists, xmp.
    'br_emph_start'   => '<p>First<strong><br>Second</strong></p>',
    'br_emph_mid'     => '<p><strong>First<br>Second</strong></p>',
    'br_emph_end'     => '<p><strong>First<br></strong>Second</p>',
    'br_emph_nested'  => '<p><strong>a<em>b<br>c</em></strong></p>',
    'strong_strong'   => '<p><strong>First</strong><strong>Second</strong></p>',
    'em_strong'       => '<p><em>First</em><strong>Second</strong></p>',
    'em_punct_strong' => '<p><em>First.</em><strong>Second</strong></p>',
    'flank_open'      => '<p>x<strong>(b)</strong></p>',
    'flank_link'      => '<p><em>a.</em><a href="javascript:x">b</a></p>',
    'code_code'       => '<p><code>First</code><code>Second</code></p>',
    'code_img'        => '<p><code>a</code><code>`</code><code>&lt;img src=x onerror=alert(1)&gt;</code></p>',
    'code_link_img'   => '<p><a href="ftp://files.example/"><code>a</code></a><code>`</code><code>&lt;img src=x onerror=alert(1)&gt;</code></p>',
    'nbsp_edge'       => '<p><strong>First</strong><strong>Second&nbsp;</strong>end</p>',
    'emsp_edge'       => '<p><strong>a</strong><em>b&#x2003;</em>tail</p>',
    'button_links'    => '<div><a class="brxe-button" href="/a">A</a><a class="brxe-button" href="/b">B</a></div>',
    'literal_marks'   => '<p><strong>**a**</strong> <em>*b* _c_</em> <code>`d` ``e``</code></p>',
    'link_code'       => '<p><a href="/x">a</a><code>b</code><a href="/y">c</a></p>',
    'bang_image'      => '<p>Hi!<img src="/i.png" alt="i"><img src="/j.png" alt="j"></p>',
    'emph_code'       => '<p><strong>a</strong><code>b</code><strong>c</strong></p>',
    'lists_ol'        => '<ol start="10"><li>ten</li></ol><ol start="20"><li>twenty</li></ol>',
    'lists_ul'        => '<ul><li>a</li></ul><ul><li>b</li></ul><ul><li>c</li></ul>',
    'xmp'             => '<xmp>VISIBLE <b>EXAMPLE</b></xmp><p>Other</p>',
    'ol_empty_first'  => '<ol><li></li><li>second</li></ol>',
    'ol_empty_nested' => '<ul><li>text<ol><li></li><li>b</li></ol></li></ul>',
    // Readability, spec § Changes 2026-11-22.
    'price_parts'     => '<div class="brxe-pricing-tables"><div class="pricing-table-price-wrapper"><span class="pricing-table-price-prefix">$</span><span class="pricing-table-price">29</span><span class="pricing-table-price-suffix">90</span></div></div>',
    'post_meta'       => '<div class="brxe-post-meta"><span class="item">2026</span><span class="item">0 Kommentare</span></div>',
    'counter_end'     => '<div class="brxe-counter" data-bricks-counter-options="{&quot;countFrom&quot;:0,&quot;countTo&quot;:&quot;1000&quot;}"><span class="count">0</span><span class="suffix">+</span></div>',
    'tabs_paired'     => '<h2>S</h2><div class="brxe-tabs"><ul class="tab-menu"><li class="tab-title" aria-controls="p0"><span>T1</span></li><li class="tab-title" aria-controls="p1"><span>T2</span></li></ul><ul class="tab-content"><li class="tab-pane" id="p0"><p>C1</p></li><li class="tab-pane" id="p1"><p>C2</p></li></ul></div>',
];

$out = [];
foreach ($inputs as $name => $content) {
    $html = '<!DOCTYPE html><html><head><title>T</title></head><body><main id="brx-content">'
        . $content . '</main></body></html>';
    $out[$name] = Converter::convert($html, 'https://ex.test/page/');
}
// The llms.txt site-name heading, built as LlmsTxt::render() builds it.
$out['llms_heading'] = '# ' . \SFX\Markdown\Escape::heading_tail(\SFX\Markdown\Escape::label('Studio #')) . "\n";
$out['llms_heading_space'] = '# ' . \SFX\Markdown\Escape::heading_tail(\SFX\Markdown\Escape::label("Studio # \t")) . "\n";
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
