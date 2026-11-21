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
];

$out = [];
foreach ($inputs as $name => $content) {
    $html = '<!DOCTYPE html><html><head><title>T</title></head><body><main id="brx-content">'
        . $content . '</main></body></html>';
    $out[$name] = Converter::convert($html, 'https://ex.test/page/');
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
