<?php

declare(strict_types=1);

require_once __DIR__ . '/support/markdown-http-stubs.php';

require_once __DIR__ . '/../inc/Markdown/Url.php';

use SFX\Markdown\Url;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$checks = 0;
function check($expected, string $ref, string $base, string $kind, string $message): void
{
    global $checks;
    assert_same($expected, Url::resolve($ref, $base, $kind), $message);
    $checks++;
}

// 1. Relative references through make_absolute_url().
$b = 'https://ex.test/sub/a/b/';
check('https://ex.test/sub/a/c', '../c', $b, 'link', '1: dot-dot');
check('https://ex.test/sub/a/b/?download=1', '?download=1', $b, 'link', '1: query only');
check('https://cdn.test/x.png', '//cdn.test/x.png', $b, 'image', '1: protocol-relative');
check('https://ex.test/contact', '/contact', $b, 'link', '1: root-relative');

// 2. Fragment-only and empty references keep the base's query (browser rule).
check('https://ex.test/?page_id=1120#s', '#s', 'https://ex.test/?page_id=1120', 'link', '2: fragment keeps query');
check('https://ex.test/?page_id=1120', '', 'https://ex.test/?page_id=1120', 'link', '2: empty = base');
check('https://ex.test/?p=5&page=2#s', '#s', 'https://ex.test/?p=5&page=2#top', 'link', '2: base fragment replaced');
check('https://ex.test/?p=5&page=2', '', 'https://ex.test/?p=5&page=2#top', 'link', '2: empty drops base fragment');
check('https://ex.test/?page_id=1120#s', "  #s\n", 'https://ex.test/?page_id=1120', 'link', '2: trimmed fragment');

// 3. Unsafe schemes dropped, after stripping controls and whitespace, case-insensitive.
foreach (['  JaVaScRiPt:alert(1)', "java\tscript:x", "\x01javascript:x", "java\nscript:x", "vbscript:x"] as $evil) {
    check(null, $evil, $b, 'link', '3: link ' . json_encode($evil));
    check(null, $evil, $b, 'image', '3: image ' . json_encode($evil));
}
check('MAILTO:a@b.test', 'MAILTO:a@b.test', $b, 'link', '3: mailto link kept');
check(null, 'MAILTO:a@b.test', $b, 'image', '3: mailto image dropped');
check(null, 'data:image/png;base64,AA', $b, 'image', '3: data image dropped');
check(null, 'data:text/html,x', $b, 'link', '3: data link dropped');
check('tel:+49 1', 'tel:+49 1', $b, 'link', '3: tel link kept');
check(null, 'tel:+49 1', $b, 'image', '3: tel image dropped');
check('HTTPS://x.test/a', ' HTTPS://x.test/a ', $b, 'image', '3: uppercase https image kept');
check(null, 'https://x.test/', $b, 'video', '3: unknown kind dropped');
check("https://ex.test/sub/a/b/\u{A0}javascript:x", "\u{A0}javascript:x", $b, 'link', '3: leading NBSP stays a harmless relative path');

if ($checks < 25) {
    fwrite(STDERR, "FAIL: only {$checks} checks ran\n");
    exit(1);
}
echo "PASS: markdown-url ({$checks} checks)\n";
