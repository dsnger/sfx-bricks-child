<?php

declare(strict_types=1);

function apply_filters($hook, $value, ...$args) { return $value; }

require_once __DIR__ . '/../inc/EditorProse/Payload.php';

use SFX\EditorProse\Payload;

$RESET = Payload::BLOCK_MARGIN_RESET;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function opts(array $o = []): array
{
    return array_merge(['classes' => [], 'element' => 'text', 'all_post_types' => true, 'post_types' => [], 'baseline' => false, 'title_gap' => ''], $o);
}

const TITLE_1EM = ".editor-styles-wrapper .editor-post-title { margin-block-end: 1em; }\n";

// 1. No Bricks loaded: classes still ship, CSS is the reset + title rule.
$p = Payload::build(opts(['classes' => ['prose'], 'title_gap' => '1em']), 'post');
assert_same(['brxe-text', 'prose'], $p['classes'], '1: classes without Bricks');
assert_same($RESET . TITLE_1EM, $p['css'], '1: reset + title rule only');
assert_same("html :where(.wp-block) { margin-top: revert-layer; margin-bottom: revert-layer; }\n", $RESET, '1: reset string pinned');
assert_same([], $p['links'], '1: no links');
assert_same(['prose'], Payload::missing_classes(['prose']), '1: all missing without Bricks');

// 2. Bricks present but load_webfonts missing: the API counts as unavailable -> reset + title rule only.
//    Separate process, because a class cannot lose a method once declared.
$child = sprintf(
    'function apply_filters($h, $v) { return $v; } require %s; require %s; '
    . '\Bricks\Database::$global_data["globalClasses"] = [["id" => "abc", "name" => "prose"]]; '
    . 'echo json_encode(["payload" => \SFX\EditorProse\Payload::build(["classes" => ["prose"], "element" => "text", "all_post_types" => true, "post_types" => [], "baseline" => false, "title_gap" => "1em"], "post"), "called" => \Bricks\Assets::$called]);',
    var_export(__DIR__ . '/../inc/EditorProse/Payload.php', true),
    var_export(__DIR__ . '/support/editor-prose-bricks-stubs-partial.php', true)
);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child));
$partial = json_decode((string) $out, true);
assert_same(\SFX\EditorProse\Payload::BLOCK_MARGIN_RESET . TITLE_1EM, $partial['payload']['css'] ?? null, '2: missing load_webfonts -> reset + title rule only');
assert_same(false, $partial['called'] ?? null, '2: compiler not invoked when load_webfonts is missing');

require_once __DIR__ . '/support/editor-prose-bricks-stubs.php';

\Bricks\Database::$global_data['globalClasses'] = [
    ['id' => 'abc', 'name' => 'prose'],
    ['id' => 'def', 'name' => 'second'],
    'junk',
];

assert_same('', Payload::title_rule(''), '3: empty gap -> no rule');

// 4. Compiled, scoped, one call for all classes in settings order, element from settings.
\Bricks\Assets::$seen = [];
$p = Payload::build(opts(['classes' => ['second', 'prose', 'typo'], 'element' => 'post-content']), 'page');
assert_same(['brxe-post-content', 'second', 'prose', 'typo'], $p['classes'], '4: classes');
assert_same(".block-editor-iframe__body .def.brxe-post-content { font-family: \"Inter\"; }\n.block-editor-iframe__body .abc.brxe-post-content { font-family: \"Inter\"; }\n" . $RESET, $p['css'], '4: compiled + scoped');
assert_same([['sfx_editor_prose', ['def' => ['post-content'], 'abc' => ['post-content']]]], \Bricks\Assets::$seen, '4: one compiler call, ordered map');
assert_same(['https://fonts.example/css?family=Inter&display=swap'], $p['links'], '4: stylesheet links only, decoded');
assert_same(['typo'], Payload::missing_classes(['second', 'prose', 'typo']), '4: missing names');

// 5. All six statics restored, after a normal call and after a throw.
function seed(): array
{
    \Bricks\Assets::$global_classes_elements = ['orig' => ['div']];
    \Bricks\Assets::$inline_css = ['keep' => 'me'];
    \Bricks\Assets::$inline_css_breakpoints = ['bp' => 'orig'];
    \Bricks\Assets::$unique_inline_css = ['orig'];
    \Bricks\Assets::$inline_css_dynamic_data = 'orig';
    \Bricks\Assets::$current_generating_element = 'orig-el';
    return snapshot_statics();
}
function snapshot_statics(): array
{
    return [
        \Bricks\Assets::$global_classes_elements,
        \Bricks\Assets::$inline_css,
        \Bricks\Assets::$inline_css_breakpoints,
        \Bricks\Assets::$unique_inline_css,
        \Bricks\Assets::$inline_css_dynamic_data,
        \Bricks\Assets::$current_generating_element,
    ];
}
$before = seed();
Payload::build(opts(['classes' => ['prose']]), 'post');
assert_same($before, snapshot_statics(), '5: all six statics restored after a normal compile');

$before = seed();
\Bricks\Assets::$throw = true;
$p = Payload::build(opts(['classes' => ['prose'], 'title_gap' => '1em', 'baseline' => true]), 'post', 'https://site.test/prose.css?ver=1');
\Bricks\Assets::$throw = false;
assert_same($before, snapshot_statics(), '5: all six statics restored after a throw');
assert_same($RESET . TITLE_1EM, $p['css'], '5: throw -> reset + title rule only');
assert_same(['https://site.test/prose.css?ver=1'], $p['links'], '5: baseline link survives a throw');
assert_same(['brxe-text', 'prose', 'sfx-prose'], $p['classes'], '5: classes survive a throw');

// 6. Unknown only -> compiler not called, css is the reset only.
\Bricks\Assets::$seen = [];
$p = Payload::build(opts(['classes' => ['typo']]), 'post');
assert_same([], \Bricks\Assets::$seen, '6: compiler not called for unknown names');
assert_same($RESET, $p['css'], '6: reset only');

// 7. Baseline: link first, sfx-prose class last.
$p = Payload::build(opts(['classes' => ['prose'], 'baseline' => true]), 'post', 'https://site.test/prose.css?ver=1');
assert_same(['https://site.test/prose.css?ver=1', 'https://fonts.example/css?family=Inter&display=swap'], $p['links'], '7: baseline link first');
assert_same('sfx-prose', end($p['classes']), '7: sfx-prose added');

echo "editor-prose-payload-test: PASS\n";
