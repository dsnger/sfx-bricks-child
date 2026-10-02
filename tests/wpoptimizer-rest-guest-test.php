<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/../../../../');
define('WPINC', 'wp-includes');

$test_options = [];
$test_filters = [];
$test_actions = [];
$test_removed_actions = [];
$test_user_id = 0;
$test_serving_rest = true;
$test_settings_errors = [];

function __($text, $domain = 'default') { return $text; }
function get_option($name, $default = false) { global $test_options; return array_key_exists($name, $test_options) ? $test_options[$name] : $default; }
function add_filter($hook, $cb, $prio = 10, $args = 1): bool { global $test_filters; $test_filters[$hook][] = ['callback' => $cb, 'priority' => $prio, 'accepted_args' => $args]; return true; }
function add_action($hook, $cb, $prio = 10, $args = 1): bool { global $test_actions; $test_actions[$hook][] = ['callback' => $cb, 'priority' => $prio, 'accepted_args' => $args]; return true; }
function remove_action($hook, $cb, $prio = 10): bool { global $test_removed_actions; $test_removed_actions[] = [$hook, $cb, $prio]; return true; }
function apply_filters(string $hook, $value, ...$args)
{
    global $test_filters;
    foreach ($test_filters[$hook] ?? [] as $f) {
        $value = ($f['callback'])(...array_merge([$value], array_slice($args, 0, max(0, $f['accepted_args'] - 1))));
    }
    return $value;
}
function get_current_user_id(): int { global $test_user_id; return $test_user_id; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_is_serving_rest_request(): bool { global $test_serving_rest; return $test_serving_rest; }
function wp_unslash($v) { return $v; }
function add_settings_error($setting, $code, $message, $type = 'error'): void { global $test_settings_errors; $test_settings_errors[] = $code; }

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data() { return $this->data; }
}

require_once dirname(__DIR__) . '/inc/WPOptimizer/classes/RestGuestAccess.php';

use SFX\WPOptimizer\classes\RestGuestAccess as G;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. option(): non-array stored values read as [].
$test_options['sfx_wpoptimizer_options'] = 'garbage';
assert_same([], G::option(), '1: scalar option -> []');
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_mode' => 'closed'];
assert_same(['rest_guest_mode' => 'closed'], G::option(), '1: array option kept');

// 2. supported(): strict anchors, no dot segments, strings only.
foreach (['wp/v2', 'bricks/v1', 'oembed/1.0', 'contact-form-7/v1', 'fluent-smtp', 'a~b/c_d'] as $ok) {
    assert_same(true, G::supported($ok), "2: supported {$ok}");
}
foreach (["wp/v2\n", 'a/../b', '.', 'a/./b', '', 'a b/v1', 'a+b/v1', 'a%41/v1', '/wp/v2', 'wp/v2/', 123, null] as $bad) {
    assert_same(false, G::supported($bad), '2: unsupported ' . var_export($bad, true));
}

// 3. mode(): explicit, legacy, default.
assert_same('allowlist', G::mode(['rest_guest_mode' => 'allowlist']), '3: explicit');
assert_same('closed', G::mode(['disable_rest_api_non_authenticated' => 1]), '3: legacy 1 -> closed');
assert_same('open', G::mode(['disable_rest_api_non_authenticated' => 0]), '3: legacy 0 -> open');
assert_same('open', G::mode(['rest_guest_mode' => 'open', 'disable_rest_api_non_authenticated' => 1]), '3: explicit wins over legacy');
assert_same('open', G::mode(['rest_guest_mode' => 'weird']), '3: invalid -> open');
assert_same('open', G::mode([]), '3: empty -> open');

// 4. namespaces(): null vs [], rows, form rows, idempotence.
assert_same(null, G::namespaces([]), '4: absent -> null');
assert_same(null, G::namespaces(['rest_guest_namespaces' => null]), '4: null -> null');
assert_same([], G::namespaces(['rest_guest_namespaces' => 'bad']), '4: scalar -> []');
$stored = [['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'a/../b'], ['namespace' => 'x/v1', 'method' => 'nope']];
$norm = G::namespaces(['rest_guest_namespaces' => $stored]);
assert_same([['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'x/v1', 'method' => 'all']], $norm, '4: stored rows normalised');
assert_same($norm, G::namespaces(['rest_guest_namespaces' => $norm]), '4: idempotent');
$form = [
    ['namespace' => 'bricks/v1', 'allowed' => '1', 'method' => 'all'],
    ['namespace' => 'wp/v2', 'allowed' => '0', 'method' => 'get'],
    ['namespace' => 'cut/v1'],
];
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], G::namespaces(['rest_guest_namespaces' => $form], true), '4: form rows need allowed=1');
assert_same([['namespace' => 'b/v1', 'method' => 'get']], G::namespaces(['rest_guest_namespaces' => [['namespace' => 'b/v1', 'method' => 'all'], ['namespace' => 'b/v1', 'method' => 'get']]]), '4: last duplicate wins');

// 5. seen(), hide_index(), to_map().
assert_same(['a/v1', 'odd name'], G::seen(['rest_guest_seen' => ['a/v1', 'a/v1', '', 'odd name', 5, 'sfx-guest/v1']]), '5: seen keeps non-empty strings, unique, no internal namespace');
assert_same([], G::namespaces(['rest_guest_namespaces' => [['namespace' => 'sfx-guest/v1', 'method' => 'all']]]), '5: internal namespace never stored');
assert_same([], G::seen(['rest_guest_seen' => 'x']), '5: seen scalar -> []');
assert_same(true, G::hide_index([]), '5: hide absent -> true');
assert_same(true, G::hide_index(['rest_guest_hide_index' => null]), '5: hide null -> true');
assert_same(false, G::hide_index(['rest_guest_hide_index' => '0']), '5: hide "0" -> false');
assert_same(false, G::hide_index(['rest_guest_hide_index' => 0]), '5: hide 0 -> false');
assert_same(true, G::hide_index(['rest_guest_hide_index' => 1]), '5: hide 1 -> true');
assert_same(['bricks/v1' => 'all', 'oembed/1.0' => 'get'], G::to_map([['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get']]), '5: to_map');

echo "wpoptimizer-rest-guest-test: PASS\n";
