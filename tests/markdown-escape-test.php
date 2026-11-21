<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/Markdown/Escape.php';

use SFX\Markdown\Escape;

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

// 1. text(): every listed char escaped anywhere; no raw `<` survives.
assert_same('\<img src=x\>', Escape::text('<img src=x>'), '1: tag escaped');
assert_same(0, preg_match('/(?<!\\\\)</', Escape::text('a<b <<c')), '1: no unescaped <');
assert_same('a\*b\_c\[d\]e\`f\|g\&h\!', Escape::text('a*b_c[d]e`f|g&h!'), '1: listed chars, trailing ! included');
assert_same('Hi\![l](<u>)', Escape::text('Hi!') . '[l](<u>)', '1: trailing ! cannot form an image with a following link');
assert_same("a!\n", Escape::text("a!\n"), '1: ! before a final newline is not at the end');
assert_same('\\\\ \~ \> \&amp;', Escape::text('\\ ~ > &amp;'), '1: backslash, tilde, gt, entity text');
assert_same('\!\[x\](y)', Escape::text('![x](y)'), '1: bang before bracket');
assert_same('Hi! ok', Escape::text('Hi! ok'), '1: lone bang untouched (spec: only before [)');
assert_same('# x', Escape::text('# x'), '1: text() alone leaves line-start #');
assert_same('Grüße', Escape::text('Grüße'), '1: UTF-8 untouched');

// 2. block_start(): line-start markers.
foreach (['#' => '\# x', '+' => '\+ x', '-' => '\- x', '=' => '\= x'] as $c => $want) {
    assert_same($want, Escape::block_start(Escape::text("{$c} x")), "2: {$c}");
}
assert_same('\> x', Escape::block_start(Escape::text('> x')), '2: > (escaped once)');
assert_same('12\. x', Escape::block_start('12. x'), '2: ordered dot');
assert_same('3\) x', Escape::block_start('3) x'), '2: ordered paren');
assert_same('12x', Escape::block_start('12x'), '2: digits without marker');
assert_same('a # b', Escape::block_start('a # b'), '2: # mid-line untouched');
assert_same("a\n\\- b", Escape::block_start("a\n- b"), '2: every line of a multi-line string');
assert_same('  \- x', Escape::block_start('  - x'), '2: indented marker');
assert_same("a\n\\# b", Escape::block_start(Escape::text("a\r# b")), '2: lone CR is a line break');
assert_same("a\n\\- b", Escape::block_start("a\r- b"), '2: lone CR list marker');
assert_same("a\n\\# b", Escape::block_start("a\r\n# b"), '2: CRLF');

// 3. label(): newlines to spaces, then text().
assert_same('a b', Escape::label("a\nb"), '3: newline');
assert_same('a b c', Escape::label("a\r\nb\rc"), '3: CRLF and CR');
assert_same('\[x\] \*', Escape::label("[x]\n*"), '3: escaped after join');

// 4. destination().
assert_same('<https://x.test/a%20b\<c\>\&d\\\\%C3%A9>', Escape::destination('https://x.test/a b<c>&d\\é'), '4: brief example');
assert_same('<https://x.test/%0A%09%7F>', Escape::destination("https://x.test/\n\t\x7F"), '4: controls encoded');
assert_same('<tel:+49%201>', Escape::destination('tel:+49 1'), '4: tel space');

// 5. code_span().
assert_same('``a`b``', Escape::code_span('a`b'), '5: inner backtick');
assert_same('`` `x ``', Escape::code_span('`x'), '5: leading backtick padded');
assert_same('`   `', Escape::code_span('   '), '5: spaces only as is');
assert_same('`  a `', Escape::code_span(' a'), '5: leading space padded both sides');
assert_same('`x`', Escape::code_span('x'), '5: plain');
assert_same('```a``b```', Escape::code_span('a``b'), '5: longest run 2');
assert_same('`a # b`', Escape::code_span("a\n# b"), '5: newline cannot start a block');

// 6. fence().
assert_same("````\nx\n```\ny\n````", Escape::fence("x\n```\ny"), '6: longer fence');
assert_same("```\n~~~\n```", Escape::fence('~~~'), '6: tilde inside backtick fence');
assert_same("```\nplain\n```", Escape::fence('plain'), '6: minimum three');
assert_same("``````\n`````\n``````", Escape::fence('`````'), '6: run of five');

if ($checks < 35) {
    fwrite(STDERR, "FAIL: only {$checks} checks ran\n");
    exit(1);
}
echo "PASS: markdown-escape ({$checks} checks)\n";
