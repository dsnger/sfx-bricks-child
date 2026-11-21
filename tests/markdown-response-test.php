<?php

declare(strict_types=1);

// Spec § Markdown response, Converting step 1: the pass-through checks.
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "FAIL: PHP error {$no}: {$msg} at {$file}:{$line}\n");
    exit(1);
});

require_once __DIR__ . '/../inc/Markdown/Response.php';

use SFX\Markdown\Response;

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

$html = ['Content-Type: text/html; charset=UTF-8'];

assert_same(true, Response::passes_through(404, $html, false), 'status 404');
assert_same(true, Response::passes_through(301, $html, false), 'status 301');
assert_same(true, Response::passes_through(200, ['Content-Type: text/html', 'Location: https://ex.test/x'], false), 'Location header with 200');
assert_same(true, Response::passes_through(200, ['location:https://ex.test/x'], false), 'Location header, lower case, no space');
assert_same(true, Response::passes_through(200, ['Content-Type: application/json'], false), 'JSON');
assert_same(true, Response::passes_through(200, ['Content-Type: text/htmlx'], false), 'text/htmlx is not text/html');
assert_same(true, Response::passes_through(200, $html, true), 'headers already sent');
assert_same(false, Response::passes_through(200, $html, false), '200 text/html; charset=UTF-8');
assert_same(false, Response::passes_through(200, ['content-type:TEXT/HTML ;charset=latin1'], false), 'media type case and spacing');
assert_same(false, Response::passes_through(200, ['Vary: Accept-Encoding'], false), 'no Content-Type, default_mimetype text/html');
ini_set('default_mimetype', 'text/plain');
assert_same(true, Response::passes_through(200, ['Vary: Accept-Encoding'], false), 'no Content-Type, default_mimetype text/plain');

// R6: the Link header.
assert_same('Link: <https://ex.test/a/?p=1&b=2>; rel="canonical"', Response::link_header('https://ex.test/a/?p=1&b=2'), 'Link: plain URL unchanged');
assert_same('Link: <https://ex.test/%3Cx%3E%20%22y%22%2Cz/>; rel="canonical"', Response::link_header('https://ex.test/<x> "y",z/'), 'Link: < > space " , percent-encoded');
assert_same(null, Response::link_header("https://ex.test/a\r\nX-Evil: 1"), 'Link: CR/LF passes through');
assert_same(null, Response::link_header("https://ex.test/a\tb"), 'Link: tab passes through');
assert_same(null, Response::link_header("https://ex.test/a\x7f"), 'Link: DEL passes through');
assert_same(null, Response::link_header("https://ex.test/a\x00"), 'Link: NUL passes through');

echo "PASS: markdown-response ({$checks} checks)\n";
