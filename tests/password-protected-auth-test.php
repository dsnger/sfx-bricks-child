<?php

declare(strict_types=1);

define('DAY_IN_SECONDS', 86400);
define('COOKIEHASH', 'testcookiehash');

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt-for-' . $scheme;
}

function wp_hash($data, $scheme = 'auth')
{
    return hash_hmac('md5', (string) $data, wp_salt($scheme));
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';
require_once __DIR__ . '/../inc/PasswordProtected/Auth.php';

use SFX\PasswordProtected\Auth;
use SFX\PasswordProtected\Settings;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Drive Settings::get() (and therefore Auth::validate_cookie(), which reads it)
 * without a database. validate_cookie() no longer takes a password override —
 * the current secrets come from the stored option, so the test sets them here.
 */
function set_pp_settings(string $password, string $bypass_secret, string $bypass_key = ''): void
{
    global $test_options;
    $test_options[Settings::OPTION_NAME] = array_merge(
        Settings::defaults(),
        [
            'password'               => $password,
            'bypass_session_secret'  => $bypass_secret,
            'bypass_key'             => $bypass_key,
        ]
    );
}

$GLOBALS['blog_id'] = 1;

$hash   = 'hashed:letmein';
$secret = 'bp-secret-1';
$future = time() + 3600;
$pw     = Auth::FLAVOR_PASSWORD;
$bp     = Auth::FLAVOR_BYPASS;

set_pp_settings($hash, $secret, 'shareable-key');

$pwCookie = Auth::generate_cookie($future, $pw, $hash, $secret);
$bpCookie = Auth::generate_cookie($future, $bp, $hash, $secret);

// 1. Freshly generated cookies validate (both flavors).
assert_true(Auth::validate_cookie($pwCookie), '1a: a fresh password cookie validates');
assert_true(Auth::validate_cookie($bpCookie), '1b: a fresh bypass cookie validates');

// 2. Tampered HMAC fails.
[$sid, $exp, $flavor, $hmac] = explode('|', $pwCookie);
assert_true(
    !Auth::validate_cookie($sid . '|' . $exp . '|' . $flavor . '|' . strrev($hmac)),
    '2: a tampered HMAC fails'
);

// 3. Tampered expiration fails.
assert_true(
    !Auth::validate_cookie($sid . '|' . ($future + 9999) . '|' . $flavor . '|' . $hmac),
    '3: a tampered expiration fails'
);

// 4. Expired cookie fails.
$expired = Auth::generate_cookie(time() - 10, $pw, $hash, $secret);
assert_true(!Auth::validate_cookie($expired), '4: an expired cookie fails');

// 5. Malformed cookies fail. `null` means "read $_COOKIE", which is empty here.
//    Note a|b|c is the OLD 3-part format and must now be rejected outright.
foreach (['', 'garbage', 'a|b', 'a|b|c', 'a|b|c|d', null, ['array']] as $i => $bad) {
    assert_true(!Auth::validate_cookie($bad), "5: malformed cookie #{$i} fails");
}

// 6. Non-integer expiration fails.
assert_true(!Auth::validate_cookie($sid . '|9e99|' . $flavor . '|' . $hmac), '6: a non-integer expiration fails');

// 7. Foreign site_id fails.
assert_true(!Auth::validate_cookie('bid_999|' . $exp . '|' . $flavor . '|' . $hmac), '7: a foreign site_id fails');

// 8. Unknown flavor fails (only pw/bp are valid).
$badFlavor = preg_replace('/\|' . preg_quote($pw, '/') . '\|/', '|xx|', $pwCookie);
assert_true(!Auth::validate_cookie($badFlavor), '8: an unknown flavor fails');

// 9. Changing the password invalidates BOTH flavors.
set_pp_settings('hashed:a-different-password', $secret, 'shareable-key');
assert_true(!Auth::validate_cookie($pwCookie), '9a: password change revokes password cookie');
assert_true(!Auth::validate_cookie($bpCookie), '9b: password change revokes bypass cookie');

// 10. Bumping the bypass secret ("lock out previous visitors") revokes ONLY
//     bypass cookies; password cookies survive.
set_pp_settings($hash, 'bp-secret-2', 'shareable-key');
assert_true(!Auth::validate_cookie($bpCookie), '10a: bypass-secret bump revokes bypass cookie');
assert_true(Auth::validate_cookie($pwCookie),  '10b: bypass-secret bump keeps password cookie valid');

// 11. Rotating the shareable key alone (secret unchanged) revokes NOBODY: the
//     key is not part of the signing, so existing visitors stay after a new link.
set_pp_settings($hash, $secret, 'a-brand-new-key');
assert_true(Auth::validate_cookie($bpCookie), '11a: a new link keeps existing bypass visitors');
assert_true(Auth::validate_cookie($pwCookie), '11b: a new link keeps password sessions');

// 12. Flavor downgrade: relabel a bypass cookie as a password one to dodge the
//     secret binding. Must fail — the flavor is authenticated.
$forged = preg_replace('/\|' . preg_quote($bp, '/') . '\|/', '|' . $pw . '|', $bpCookie);
assert_true(!Auth::validate_cookie($forged), '12: a flavor-downgraded cookie fails');

echo "OK\n";
