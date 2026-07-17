<?php

declare(strict_types=1);

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function __($text, $domain = null)
{
    return $text;
}

function esc_url_raw($url)
{
    return $url;
}

function absint($value): int
{
    return abs((int) $value);
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

// Deterministic stand-ins. We are testing our own logic, not WordPress's hashing.
function wp_hash_password($password)
{
    return 'hashed:' . md5((string) $password);
}

function wp_check_password($password, $hash)
{
    return hash_equals((string) $hash, 'hashed:' . md5((string) $password));
}

$generated_keys = 0;

function wp_generate_password($length = 12, $special_chars = true)
{
    global $generated_keys;
    $generated_keys++;

    return 'generatedkey' . $generated_keys . str_repeat('x', max(0, $length - 13));
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';

use SFX\PasswordProtected\Settings;

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

// 1. Empty new password keeps the existing hash.
$r = snapshot(['password_new' => '', 'password_confirm' => ''], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '1: empty new password keeps existing hash');
assert_true($r['errors'] === [], '1: empty new password is not an error');

// 2. Mismatched confirm keeps the existing hash and reports an error.
$r = snapshot(['password_new' => 'abc', 'password_confirm' => 'xyz'], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '2: mismatch keeps existing hash');
assert_true(isset($r['errors']['password']), '2: mismatch reports a password error');

// 3. Matching new password produces a hash wp_check_password() accepts.
$r = snapshot(['password_new' => 'letmein', 'password_confirm' => 'letmein']);
assert_true(wp_check_password('letmein', $r['values']['password']), '3: matching password produces a usable hash');

// 4. Feeding values back as $existing with an empty $post password leaves the hash byte-identical.
$first = snapshot(['password_new' => 'letmein', 'password_confirm' => 'letmein', 'status' => '1']);
$second = Settings::validate_snapshot(['status' => '1'], $first['values']);
assert_true(
    $second['values']['password'] === $first['values']['password'],
    '4: re-validating over its own output does not re-hash or discard the password'
);

// 5. Status on + password set in the SAME snapshot => status stays on.
$r = snapshot(['status' => '1', 'password_new' => 'letmein', 'password_confirm' => 'letmein']);
assert_true($r['values']['status'] === true, '5: status survives when the password is set in the same save');
assert_true(!isset($r['errors']['status']), '5: no status error when password set in same save');

// 6. Status on + no password anywhere => error and status forced off.
$r = snapshot(['status' => '1']);
assert_true($r['values']['status'] === false, '6: status forced off without a password');
assert_true(isset($r['errors']['status']), '6: status without a password reports an error');

// 7. Bypass enabled with an empty stored key => key generated. With a stored key => preserved.
$r = snapshot(['bypass_enabled' => '1'], ['bypass_key' => '']);
assert_true($r['values']['bypass_key'] !== '', '7: enabling bypass with no stored key generates one');

$r = snapshot(['bypass_enabled' => '1'], ['bypass_key' => 'storedkey123']);
assert_true($r['values']['bypass_key'] === 'storedkey123', '7: an existing stored key is preserved byte-identical');

// 8. Rotate intent => new key differing from the old.
$r = snapshot(['bypass_enabled' => '1', 'bypass_rotate' => '1'], ['bypass_key' => 'storedkey123']);
assert_true($r['values']['bypass_key'] !== 'storedkey123', '8: rotate intent replaces the key');
assert_true($r['values']['bypass_key'] !== '', '8: rotate intent produces a non-empty key');

// 9. Stale-form regression: a posted key value cannot change the stored key.
$r = snapshot(
    ['bypass_enabled' => '1', 'bypass_key' => 'STALE_KEY_FROM_OLD_FORM'],
    ['bypass_key' => 'currentkey456']
);
assert_true(
    $r['values']['bypass_key'] === 'currentkey456',
    '9: a posted key is ignored — the stored key survives a stale form'
);

// 10. Disabling bypass preserves the key.
$r = snapshot([], ['bypass_key' => 'currentkey456']);
assert_true($r['values']['bypass_enabled'] === false, '10: absent checkbox disables bypass');
assert_true($r['values']['bypass_key'] === 'currentkey456', '10: disabling bypass preserves the key');

// 11. Unchecked checkboxes => false, not "unchanged".
$r = snapshot([], ['allow_admins' => true, 'allow_users' => true]);
assert_true($r['values']['allow_admins'] === false, '11: absent allow_admins becomes false');
assert_true($r['values']['allow_users'] === false, '11: absent allow_users becomes false');

// 12. remember_me_lifetime clamped into 1..365.
foreach (['0' => 1, '9999' => 365] as $input => $expected) {
    $r = snapshot(['remember_me_lifetime' => (string) $input]);
    assert_true(
        $r['values']['remember_me_lifetime'] === $expected,
        "12: lifetime {$input} clamps to {$expected}"
    );
}
$r = snapshot(['remember_me_lifetime' => '-5']);
assert_true(
    $r['values']['remember_me_lifetime'] >= 1 && $r['values']['remember_me_lifetime'] <= 365,
    '12: negative lifetime lands inside 1..365'
);
$r = snapshot(['remember_me_lifetime' => '']);
assert_true($r['values']['remember_me_lifetime'] === 14, '12: empty lifetime falls back to the default');

// 13. Array-valued inputs are treated as absent, with no TypeError.
$r = snapshot(['password_new' => ['x'], 'password_confirm' => ['x']], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '13: array-valued password is treated as absent');

// 13b. Array-valued CHECKBOXES are treated as absent too. `!empty(['x'])` is true,
//      which is exactly the trap: ?status[]=x must not switch protection on.
$r = snapshot(['status' => ['x'], 'password_new' => 'p', 'password_confirm' => 'p']);
assert_true($r['values']['status'] === false, '13b: array-valued status is treated as absent, not as checked');

$r = snapshot(['allow_admins' => ['x']], ['allow_admins' => false]);
assert_true($r['values']['allow_admins'] === false, '13b: array-valued allow_admins is treated as absent');

$r = snapshot(['bypass_enabled' => '1', 'bypass_rotate' => ['x']], ['bypass_key' => 'storedkey123']);
assert_true(
    $r['values']['bypass_key'] === 'storedkey123',
    '13b: array-valued bypass_rotate does not rotate the key'
);

// 13a. Key ORDER matches defaults(), because save_from_request() detects a failed
//      write with `get() !== values` and PHP's === on arrays is order-sensitive.
//      Without this, every successful save reports itself as a database failure.
$r = snapshot(['status' => '1', 'password_new' => 'p', 'password_confirm' => 'p']);
assert_true(
    array_keys($r['values']) === array_keys(Settings::defaults()),
    '13a: values come back in defaults() key order, so === against get() is meaningful'
);

// 13c. The request readers themselves.
assert_true(Settings::has_string(['a' => 'x'], 'a') === true, '13c: has_string true for a present string');
assert_true(Settings::has_string(['a' => ''], 'a') === true, '13c: has_string true for an empty string — presence, not emptiness');
assert_true(Settings::has_string([], 'a') === false, '13c: has_string false when absent');
assert_true(Settings::has_string(['a' => ['x']], 'a') === false, '13c: has_string false for an array');
assert_true(Settings::post_bool(['a' => '1'], 'a') === true, '13c: post_bool true for "1"');
assert_true(Settings::post_bool(['a' => '0'], 'a') === false, '13c: post_bool false for "0"');
assert_true(Settings::post_bool([], 'a') === false, '13c: post_bool false when absent');
assert_true(Settings::post_bool(['a' => ['x']], 'a') === false, '13c: post_bool false for an array');
assert_true(Settings::post_string(['a' => 'x'], 'a') === 'x', '13c: post_string returns the string');
assert_true(Settings::post_string(['a' => ['x']], 'a') === '', '13c: post_string empty for an array');
assert_true(Settings::request_string(['a' => 'a\\"b'], 'a') === 'a"b', '13c: request_string unslashes');
assert_true(Settings::request_string(['a' => ['x']], 'a') === '', '13c: request_string empty for an array');

// 14. Settings::get() normalizes anything the database throws at it.
foreach ([null, 'a scalar', 42] as $junk) {
    $test_options = [Settings::OPTION_NAME => $junk];
    $values = Settings::get();
    assert_true($values === Settings::defaults(), '14: non-array stored option falls back to defaults');
    assert_true(Settings::get_password_hash() === '', '14: get_password_hash() returns a string for junk input');
}

$test_options = [Settings::OPTION_NAME => [
    'status' => '1',
    'password' => ['not', 'a', 'string'],
    'remember_me_lifetime' => '9999',
    'allow_admins' => 0,
]];
$values = Settings::get();
assert_true($values['status'] === true, '14: string "1" casts to bool true');
assert_true($values['password'] === '', '14: array password normalizes to an empty string');
assert_true($values['remember_me_lifetime'] === 365, '14: stored lifetime is clamped on read');
assert_true($values['allow_admins'] === false, '14: stored 0 casts to bool false');
assert_true(Settings::get_password_hash() === '', '14: get_password_hash() is always a string');

// 15. IP allowlist keeps valid entries and drops the rest.
$test_options = [Settings::OPTION_NAME => ['allowed_ips' => "192.168.1.1\nnot-an-ip\n\n2001:db8::1"]];
assert_true(
    Settings::allowed_ips() === ['192.168.1.1', '2001:db8::1'],
    '15: allowed_ips() keeps valid IPv4/IPv6 and drops invalid lines'
);

echo "OK\n";
