<?php

declare(strict_types=1);

$test_options = [];
$test_actions = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    global $test_actions;
    $test_actions[] = [$hook, $callback];

    return true;
}

function __($text, $domain = null)
{
    return $text;
}

require_once __DIR__ . '/../inc/Redirects/Settings.php';

use SFX\Redirects\Settings;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function snapshot(array $post, array $existing = []): array
{
    return Settings::validate_snapshot($post, array_merge(Settings::defaults(), $existing));
}

// 1. Defaults, exactly per the spec table.
assert_true(Settings::OPTION_NAME === 'sfx_redirects_options', '1: option name');
assert_true(Settings::defaults() === [
    'log_404'            => true,
    'log_referrer'       => true,
    'log_retention_days' => 30,
    'log_max_rows'       => 5000,
    'auto_slug_redirect' => true,
    'permanent_cache'    => 3600,
], '1: defaults match the spec');

// 2. A full, valid form comes back typed, no errors, in defaults() key order.
$r = snapshot([
    'log_404'            => '1',
    'log_referrer'       => '1',
    'log_retention_days' => '90',
    'log_max_rows'       => '20000',
    'auto_slug_redirect' => '1',
]);
assert_true($r['errors'] === [], '2: valid form has no errors');
assert_true($r['values'] === [
    'log_404'            => true,
    'log_referrer'       => true,
    'log_retention_days' => 90,
    'log_max_rows'       => 20000,
    'auto_slug_redirect' => true,
    'permanent_cache'    => 3600,
], '2: valid form is typed and in defaults() order');

// 3. Absent checkboxes are false, not "unchanged".
$r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000']);
assert_true($r['values']['log_404'] === false, '3: absent log_404 is false');
assert_true($r['values']['log_referrer'] === false, '3: absent log_referrer is false');
assert_true($r['values']['auto_slug_redirect'] === false, '3: absent auto_slug_redirect is false');
assert_true($r['errors'] === [], '3: absent checkboxes are not errors');

// 4. Array-valued checkboxes are absent: !empty(['x']) must not read as "checked".
$r = snapshot(['log_404' => ['x'], 'log_retention_days' => '30', 'log_max_rows' => '5000']);
assert_true($r['values']['log_404'] === false, '4: array-valued checkbox is absent');
$r = snapshot(['log_404' => '0', 'log_retention_days' => '30', 'log_max_rows' => '5000']);
assert_true($r['values']['log_404'] === false, '4: "0" is unchecked');

// 5. Out-of-range integers clamp to the nearest bound AND report an error.
$cases = [
    ['log_retention_days', '0', 1],
    ['log_retention_days', '366', 365],
    ['log_retention_days', '-5', 1],
    ['log_max_rows', '99', 100],
    ['log_max_rows', '50001', 50000],
    ['log_max_rows', '99999999999999999999999', 50000],
];
foreach ($cases as [$key, $input, $expected]) {
    $post = ['log_retention_days' => '30', 'log_max_rows' => '5000', $key => $input];
    $r = snapshot($post);
    assert_true($r['values'][$key] === $expected, "5: {$key}={$input} clamps to {$expected}");
    assert_true(count($r['errors']) === 1, "5: {$key}={$input} reports one error");
    assert_true(is_string($r['errors'][0]), "5: errors are a list of strings");
}

// 6. Bounds themselves are accepted silently.
foreach ([['log_retention_days', '1'], ['log_retention_days', '365'], ['log_max_rows', '100'], ['log_max_rows', '50000']] as [$key, $input]) {
    $r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', $key => $input]);
    assert_true($r['values'][$key] === (int) $input, "6: {$key}={$input} kept");
    assert_true($r['errors'] === [], "6: {$key}={$input} is no error");
}

// 7. Non-numeric, empty or array input keeps the EXISTING value and reports an error.
foreach (['', 'abc', '12abc', '1.5'] as $input) {
    $r = snapshot(['log_retention_days' => $input, 'log_max_rows' => '5000'], ['log_retention_days' => 60]);
    assert_true($r['values']['log_retention_days'] === 60, "7: '{$input}' keeps the existing value");
    assert_true(count($r['errors']) === 1, "7: '{$input}' reports an error");
}
$r = snapshot(['log_retention_days' => ['7'], 'log_max_rows' => '5000'], ['log_retention_days' => 60]);
assert_true($r['values']['log_retention_days'] === 60, '7: array-valued int keeps the existing value');
assert_true(count($r['errors']) === 1, '7: array-valued int reports an error');

// 7a. An out-of-range $existing (e.g. hand-edited) is clamped even when it is the fallback.
$r = Settings::validate_snapshot(['log_retention_days' => '', 'log_max_rows' => '5000'], ['log_retention_days' => 9999]);
assert_true($r['values']['log_retention_days'] === 365, '7a: fallback to existing is clamped too');

// 7b. Leading/trailing whitespace is tolerated.
$r = snapshot(['log_retention_days' => ' 45 ', 'log_max_rows' => '5000']);
assert_true($r['values']['log_retention_days'] === 45 && $r['errors'] === [], '7b: " 45 " reads as 45');

// 8. get(): non-array stored option falls back to defaults.
foreach ([null, false, 'a scalar', 42] as $junk) {
    $test_options = [Settings::OPTION_NAME => $junk];
    assert_true(Settings::get() === Settings::defaults(), '8: non-array stored option falls back to defaults');
}

// 9. get(): out-of-range stored ints clamp; wrong types fall back to the default.
$test_options = [Settings::OPTION_NAME => [
    'log_retention_days' => -3,
    'log_max_rows'       => PHP_INT_MAX,
]];
$v = Settings::get();
assert_true($v['log_retention_days'] === 1, '9: negative stored retention clamps to 1');
assert_true($v['log_max_rows'] === 50000, '9: huge stored max_rows clamps to 50000');

$test_options = [Settings::OPTION_NAME => [
    'log_retention_days' => '400',
    'log_max_rows'       => '50',
]];
$v = Settings::get();
assert_true($v['log_retention_days'] === 365, '9: numeric-string retention clamps to 365');
assert_true($v['log_max_rows'] === 100, '9: numeric-string max_rows clamps to 100');

$test_options = [Settings::OPTION_NAME => [
    'log_retention_days' => ['x'],
    'log_max_rows'       => 'lots',
    'log_404'            => ['x'],
    'log_referrer'       => null,
    'auto_slug_redirect' => '0',
]];
$v = Settings::get();
assert_true($v['log_retention_days'] === 30, '9: array retention falls back to default');
assert_true($v['log_max_rows'] === 5000, '9: non-numeric max_rows falls back to default');
assert_true($v['log_404'] === true, '9: array bool falls back to default');
assert_true($v['log_referrer'] === true, '9: null bool falls back to default');
assert_true($v['auto_slug_redirect'] === false, '9: stored "0" casts to false');

$test_options = [Settings::OPTION_NAME => [
    'log_retention_days' => 1e300,
    'log_max_rows'       => INF,
]];
$v = Settings::get();
assert_true($v['log_retention_days'] === 365, '9: huge float clamps to 365');
assert_true($v['log_max_rows'] === 5000, '9: INF falls back to default');

$test_options = [Settings::OPTION_NAME => ['log_max_rows' => true, 'foreign' => 'x']];
$v = Settings::get();
assert_true($v['log_max_rows'] === 5000, '9: bool max_rows falls back to default');
assert_true(array_keys($v) === array_keys(Settings::defaults()), '9: get() drops unknown keys, keeps order');

// 10. validate_snapshot output === get() after a round trip (the write check depends on it).
$r = snapshot(['log_404' => '1', 'log_retention_days' => '7', 'log_max_rows' => '100']);
$test_options = [Settings::OPTION_NAME => $r['values']];
assert_true(Settings::get() === $r['values'], '10: get() of a validated snapshot is identical to it');

// 11. register() hooks the admin-post save handler.
Settings::register();
assert_true(
    in_array(['admin_post_sfx_redirects_save_settings', [Settings::class, 'save_from_request']], $test_actions, true),
    '11: register() adds admin_post_sfx_redirects_save_settings'
);

// 12. permanent_cache (spec Addendum A2): the choices and their order are fixed.
assert_true(Settings::PERMANENT_CACHE_CHOICES === [3600, 86400, 604800, 0], '12: choices per the spec');
$labels = Settings::permanent_cache_labels();
assert_true(array_keys($labels) === Settings::PERMANENT_CACHE_CHOICES, '12: one label per choice, in choice order');
assert_true($labels[3600] === '1 hour' && $labels[86400] === '1 day' && $labels[604800] === '1 week'
    && $labels[0] === 'No cache header from this module', '12: labels per the spec table');

// 13. validate_snapshot: every choice is accepted silently, as an int.
foreach (Settings::PERMANENT_CACHE_CHOICES as $choice) {
    $r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', 'permanent_cache' => (string) $choice], ['permanent_cache' => 86400]);
    assert_true($r['values']['permanent_cache'] === $choice, "13: {$choice} accepted");
    assert_true($r['errors'] === [], "13: {$choice} is no error");
}
$r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', 'permanent_cache' => ' 604800 ']);
assert_true($r['values']['permanent_cache'] === 604800 && $r['errors'] === [], '13: surrounding whitespace tolerated');

// 14. validate_snapshot: a value outside the set keeps the STORED one and reports one error.
foreach (['1', '7200', '-3600', '', 'abc', '3600.0', '03600x', ['3600']] as $input) {
    $r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', 'permanent_cache' => $input], ['permanent_cache' => 604800]);
    $shown = var_export($input, true);
    assert_true($r['values']['permanent_cache'] === 604800, "14: {$shown} keeps the stored value");
    assert_true(count($r['errors']) === 1 && is_string($r['errors'][0]), "14: {$shown} reports one error");
}
// A stored value that is itself outside the set falls back to the default, not to itself.
$r = Settings::validate_snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', 'permanent_cache' => 'x'], ['permanent_cache' => 99]);
assert_true($r['values']['permanent_cache'] === 3600, '14: an invalid stored fallback maps to the default');

// 15. A form without the field (not a value at all) keeps the stored one silently.
$r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000'], ['permanent_cache' => 0]);
assert_true($r['values']['permanent_cache'] === 0 && $r['errors'] === [], '15: absent field keeps the stored value without an error');

// 16. get(): stored values outside the set map to the default (ImportExport JSON path).
foreach ([3600, 86400, 604800, 0, '86400', '0'] as $stored) {
    $test_options = [Settings::OPTION_NAME => ['permanent_cache' => $stored]];
    assert_true(Settings::get()['permanent_cache'] === (int) $stored, '16: stored ' . var_export($stored, true) . ' read as a choice');
}
foreach ([1, 7200, -3600, '7200', '', 'abc', 3600.0, true, null, ['3600'], '3600 '] as $stored) {
    $test_options = [Settings::OPTION_NAME => ['permanent_cache' => $stored]];
    assert_true(Settings::get()['permanent_cache'] === 3600, '16: stored ' . var_export($stored, true) . ' maps to the default');
}

// 17. Round trip with a non-default choice.
$r = snapshot(['log_retention_days' => '30', 'log_max_rows' => '5000', 'permanent_cache' => '0']);
$test_options = [Settings::OPTION_NAME => $r['values']];
assert_true(Settings::get() === $r['values'], '17: get() of a validated snapshot with permanent_cache=0 is identical to it');

echo "OK\n";
