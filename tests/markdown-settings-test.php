<?php

declare(strict_types=1);

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;
    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}
function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
function register_setting($group, $name, $args = []) { return true; }
function get_post_types($args = [], $output = 'names')
{
    $all = [
        'page' => ['Pages', false],
        'post' => ['Posts', true],
        'attachment' => ['Media', true],
        'bricks_template' => ['Templates', true],
        'cpt_public' => ['Jobs', true],
        'cpt_private' => ['Secrets', false],
    ];
    $out = [];
    foreach ($all as $name => [$label, $queryable]) {
        if (isset($args['name']) ? $args['name'] !== $name : (!empty($args['publicly_queryable']) && !$queryable)) {
            continue;
        }
        $out[$name] = (object) ['name' => $name, 'label' => $label, 'labels' => (object) ['name' => $label]];
    }
    return $output === 'objects' ? $out : array_keys($out);
}

require_once __DIR__ . '/../inc/Markdown/Settings.php';

use SFX\Markdown\Settings;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assert_same('sfx_markdown_options', Settings::OPTION_NAME, '1: option name');
assert_same('sfx_markdown_options', Settings::OPTION_GROUP, '1: option group');
$d = Settings::defaults();
assert_same(['post_types' => ['page', 'post']], $d, '1: defaults');

assert_same($d, Settings::sanitize('garbage'), '2: string -> defaults');
assert_same($d, Settings::sanitize(null), '2: null -> defaults');

assert_same(
    ['page', 'cpt_public'],
    Settings::sanitize(['post_types' => ['page', 'attachment', 'bricks_template', 'cpt_public', 'cpt_private', 'ghost', 'page', 3]])['post_types'],
    '3: post types filtered to the offered set'
);
assert_same([], Settings::sanitize(['post_types' => 'page'])['post_types'], '4: string post_types -> []');
assert_same(['post_types' => []], Settings::sanitize(['_present' => '1']), '4b: presence field only -> empty selection, nothing else stored');
assert_same($d, Settings::sanitize([]), '4c: neither post_types nor _present (an exported never-saved option) -> defaults');
assert_same($d, Settings::sanitize(['other' => 'x']), '4c: unrelated keys only -> defaults');
assert_same(['post_types' => []], Settings::sanitize(['post_types' => []]), '4d: saved empty selection stays empty');

$test_options['sfx_markdown_options'] = ['post_types' => ['ghost', 'cpt_public']];
assert_same(['post_types' => ['cpt_public']], Settings::get(), '5: get() re-sanitizes stored junk');
$test_options['sfx_markdown_options'] = 'junk';
assert_same($d, Settings::get(), '5: get() of non-array -> defaults');

assert_same(['post' => 'Posts', 'cpt_public' => 'Jobs', 'page' => 'Pages'], Settings::offered_post_types(), '6: offered post types, name => plural label');

echo "markdown-settings-test: PASS\n";
