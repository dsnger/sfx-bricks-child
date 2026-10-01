<?php

declare(strict_types=1);

function apply_filters($hook, $value, ...$args) { return $value; }

require_once __DIR__ . '/../inc/EditorProse/Payload.php';

use SFX\EditorProse\Payload;

$tmp_logs = [];
register_shutdown_function(static function () use (&$tmp_logs) {
    foreach ($tmp_logs as $f) {
        @unlink($f);
    }
});
function temp_log(): string
{
    global $tmp_logs;
    $f = sys_get_temp_dir() . '/ep-log-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $tmp_logs[] = $f; // registered for cleanup before the file exists
    touch($f);
    return $f;
}

$log = temp_log();
ini_set('error_log', $log);

$RESET = Payload::BLOCK_MARGIN_RESET . Payload::EDITOR_FRAME_RESET;

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
assert_same("html :where(.wp-block) { margin-top: revert-layer; margin-bottom: revert-layer; }\n", Payload::BLOCK_MARGIN_RESET, '1: reset string pinned');
// Editor-only frames: both alignment wrappers (not the block itself) and both Bricks component block namespaces;
// horizontal box values only, so vertical spacing between blocks is untouched.
[$frame_selectors, $frame_body] = explode('{', Payload::EDITOR_FRAME_RESET, 2);
assert_same(
    implode(', ', ['.is-root-container .wp-block[data-align="wide"]:not([data-block])', '.is-root-container .wp-block[data-align="full"]:not([data-block])', '.is-root-container [data-type^="bricks-components/"]:not([data-align], [data-align] > *, .alignwide, .alignfull)', '.is-root-container [data-type^="bricks-component-ids/"]:not([data-align], [data-align] > *, .alignwide, .alignfull)']),
    trim($frame_selectors),
    '1: frame selectors pinned'
);
assert_same(
    ['max-width: none !important', 'width: auto !important', 'margin-left: 0 !important', 'margin-right: 0 !important', 'padding-left: 0 !important', 'padding-right: 0 !important'],
    array_values(array_filter(array_map('trim', explode(';', rtrim(trim($frame_body), '}'))))),
    '1: frame declarations pinned (horizontal only)'
);
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
assert_same($RESET . TITLE_1EM, $partial['payload']['css'] ?? null, '2: missing load_webfonts -> reset + title rule only');
assert_same(false, $partial['called'] ?? null, '2: compiler not invoked when load_webfonts is missing');

// 2b. Same partial stubs under WP_DEBUG: the unavailable API is logged.
$child_log = temp_log();
$child_debug = sprintf(
    'define("WP_DEBUG", true); ini_set("error_log", %s); function apply_filters($h, $v) { return $v; } require %s; require %s; '
    . '\Bricks\Database::$global_data["globalClasses"] = [["id" => "abc", "name" => "prose"]]; '
    . '\SFX\EditorProse\Payload::build(["classes" => ["prose"], "element" => "text", "all_post_types" => true, "post_types" => [], "baseline" => false, "title_gap" => ""], "post");',
    var_export($child_log, true),
    var_export(__DIR__ . '/../inc/EditorProse/Payload.php', true),
    var_export(__DIR__ . '/support/editor-prose-bricks-stubs-partial.php', true)
);
shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child_debug));
assert_same(true, strpos((string) file_get_contents($child_log), '[sfx-editor-prose] Bricks API unavailable; class CSS skipped.') !== false, '2b: unavailable API logged with WP_DEBUG');

// 2c. WP_DEBUG=false with a throwing compiler: nothing is logged (pins the `&& WP_DEBUG` half of the guard).
$child_log2 = temp_log();
$child_off = sprintf(
    'define("WP_DEBUG", false); ini_set("error_log", %s); function apply_filters($h, $v) { return $v; } require %s; require %s; '
    . '\Bricks\Database::$global_data["globalClasses"] = [["id" => "abc", "name" => "prose"]]; \Bricks\Assets::$throw = true; '
    . '\SFX\EditorProse\Payload::build(["classes" => ["prose"], "element" => "text", "all_post_types" => true, "post_types" => [], "baseline" => false, "title_gap" => ""], "post");',
    var_export($child_log2, true),
    var_export(__DIR__ . '/../inc/EditorProse/Payload.php', true),
    var_export(__DIR__ . '/support/editor-prose-bricks-stubs.php', true)
);
shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child_off));
assert_same('', (string) file_get_contents($child_log2), '2c: no log with WP_DEBUG=false');

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
assert_same('', (string) file_get_contents($log), '5: no log without WP_DEBUG');
assert_same($before, snapshot_statics(), '5: all six statics restored after a throw');
assert_same($RESET . TITLE_1EM, $p['css'], '5: throw -> reset + title rule only');
assert_same(['https://site.test/prose.css?ver=1'], $p['links'], '5: baseline link survives a throw');
assert_same(['brxe-text', 'prose', 'sfx-prose'], $p['classes'], '5: classes survive a throw');

define('WP_DEBUG', true);
\Bricks\Assets::$throw = true;
Payload::build(opts(['classes' => ['prose']]), 'post');
\Bricks\Assets::$throw = false;
assert_same(true, strpos((string) file_get_contents($log), '[sfx-editor-prose] Bricks class CSS could not be built: boom') !== false, '5: failure logged with WP_DEBUG');

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
