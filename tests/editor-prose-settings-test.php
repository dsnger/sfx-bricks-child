<?php

declare(strict_types=1);

$test_options = [];
$registered_post_types = ['post' => true, 'page' => true, 'cpt_job' => true, 'attachment' => false];

function get_option($name, $default = false)
{
    global $test_options;
    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}
function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
function register_setting($group, $name, $args = []) { return true; }
function post_type_exists($pt) { global $registered_post_types; return array_key_exists($pt, $registered_post_types); }
function use_block_editor_for_post_type($pt) { global $registered_post_types; return !empty($registered_post_types[$pt]); }

require_once __DIR__ . '/../inc/EditorProse/Settings.php';

use SFX\EditorProse\Settings;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$d = Settings::defaults();

// 1. Defaults and name.
assert_same('sfx_editor_prose_options', Settings::OPTION_NAME, '1: option name');
assert_same(['classes' => [], 'element' => 'text', 'all_post_types' => true, 'post_types' => [], 'baseline' => false, 'title_gap' => ''], $d, '1: defaults');

// 2. Non-array input -> defaults.
assert_same($d, Settings::sanitize('garbage'), '2: string option');
assert_same($d, Settings::sanitize(null), '2: null option');

// 3. Classes: string form input is tokenized; leading dot stripped; invalid and duplicates dropped; trailing newline trimmed.
assert_same(['rich-text-content', 'article__prose', '-x'], Settings::sanitize(['classes' => ".rich-text-content, article__prose rich-text-content 1bad -x bad{"])['classes'], '3: string classes');
assert_same(['prose'], Settings::sanitize(['classes' => ["prose\n", 42, ['a'], '.']])['classes'], '3: array classes with junk');
assert_same([], Settings::sanitize(['classes' => 5])['classes'], '3: non-array non-string classes');

// 4. Element whitelist.
assert_same('post-content', Settings::sanitize(['element' => 'post-content'])['element'], '4: post-content kept');
assert_same('text', Settings::sanitize(['element' => 'heading'])['element'], '4: unknown element -> text');

// 5. Booleans: only true/1/'1' are true; form sends '0' via hidden input when unticked.
assert_same(false, Settings::sanitize(['all_post_types' => '0'])['all_post_types'], '5: all_post_types 0');
assert_same(true, Settings::sanitize(['all_post_types' => '1'])['all_post_types'], '5: all_post_types 1');
assert_same(true, Settings::sanitize([])['all_post_types'], '5: missing key -> default true');
assert_same(false, Settings::sanitize(['baseline' => 'yes'])['baseline'], '5: baseline junk -> false');
assert_same(true, Settings::sanitize(['baseline' => 1])['baseline'], '5: baseline 1');

// 6. Post types: only registered, block-editor ones; unique; non-array -> [].
assert_same(['post', 'cpt_job'], Settings::sanitize(['post_types' => ['post', 'ghost', 'attachment', 'cpt_job', 'post', 3]])['post_types'], '6: post types filtered');
assert_same([], Settings::sanitize(['post_types' => 'post'])['post_types'], '6: string post_types -> []');

// 7. title_gap.
foreach (['2rem', '0', 'clamp(1rem, 2vw, 2rem)', 'var(--gap, 1rem)', ' 1.5em '] as $ok) {
    assert_same(trim($ok), Settings::sanitize(['title_gap' => $ok])['title_gap'], "7: accepts {$ok}");
}
foreach (['1rem;color:red', '1rem}', 'a{', '<b>', 'x>y', 'a\\b', '1rem/*x*/', 'url(x)', 'URL(x)', str_repeat('1', 101), 5] as $bad) {
    assert_same('', Settings::sanitize(['title_gap' => $bad])['title_gap'], '7: rejects ' . var_export($bad, true));
}

// 8. get() sanitizes on read.
$test_options[Settings::OPTION_NAME] = ['classes' => 'a b', 'element' => 'nope', 'title_gap' => '1rem;'];
assert_same(['a', 'b'], Settings::get()['classes'], '8: get tokenizes');
assert_same('text', Settings::get()['element'], '8: get whitelists');
assert_same('', Settings::get()['title_gap'], '8: get rejects');

// 9. Gate.
$o = Settings::sanitize(['classes' => 'x']);
assert_same(true, Settings::applies_to($o, 'post'), '9: all post types');
$o = Settings::sanitize(['classes' => 'x', 'all_post_types' => '0', 'post_types' => ['page']]);
assert_same(true, Settings::applies_to($o, 'page'), '9: listed type');
assert_same(false, Settings::applies_to($o, 'post'), '9: unlisted type');
$o = Settings::sanitize(['classes' => 'x', 'all_post_types' => '0', 'post_types' => []]);
assert_same(false, Settings::applies_to($o, 'post'), '9: false + empty = nowhere');
$o = Settings::sanitize([]);
assert_same(false, Settings::applies_to($o, 'post'), '9: no classes, baseline off -> closed');
$o = Settings::sanitize(['baseline' => '1']);
assert_same(true, Settings::applies_to($o, 'post'), '9: no classes, baseline on -> open');

// 10. Bricks version.
assert_same(false, Settings::bricks_ok(null), '10: no Bricks');
assert_same(false, Settings::bricks_ok('2.3.9'), '10: 2.3.9');
assert_same(true, Settings::bricks_ok('2.4'), '10: 2.4');
assert_same(true, Settings::bricks_ok('2.4.2'), '10: 2.4.2');

echo "editor-prose-settings-test: PASS\n";
