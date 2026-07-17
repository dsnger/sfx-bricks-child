<?php

declare(strict_types=1);

$test_options = [];
$test_caps = [];
$test_logged_in = false;
$test_is_feed = false;
$test_is_robots = false;

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function current_user_can($cap)
{
    global $test_caps;

    return in_array($cap, $test_caps, true);
}

function is_user_logged_in()
{
    global $test_logged_in;

    return $test_logged_in;
}

function is_feed()
{
    global $test_is_feed;

    return $test_is_feed;
}

function is_robots()
{
    global $test_is_robots;

    return $test_is_robots;
}

function apply_filters($tag, $value, ...$args)
{
    return $value;
}

function __($text, $domain = null)
{
    return $text;
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';
require_once __DIR__ . '/../inc/PasswordProtected/Controller.php';

use SFX\PasswordProtected\Controller;
use SFX\PasswordProtected\Settings;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function configure(array $values): void
{
    global $test_options;

    $test_options = [Settings::OPTION_NAME => array_merge(Settings::defaults(), $values)];
}

function reset_request(): void
{
    global $test_caps, $test_logged_in, $test_is_feed, $test_is_robots;

    $test_caps = [];
    $test_logged_in = false;
    $test_is_feed = false;
    $test_is_robots = false;
    unset($_SERVER['REMOTE_ADDR']);
}

// 1. is_protection_enabled() follows status and nothing else.
reset_request();
configure(['status' => false, 'password' => 'hashed:x']);
assert_true(!Controller::is_protection_enabled(), '1: disabled when status is off');

configure(['status' => true, 'password' => 'hashed:x']);
assert_true(Controller::is_protection_enabled(), '1: enabled when status is on');

// 2. THE FAIL-CLOSED INVARIANT: an empty hash must NOT switch protection off.
configure(['status' => true, 'password' => '']);
assert_true(
    Controller::is_protection_enabled(),
    '2: protection stays enabled with an empty password hash — otherwise a broken credential serves the site publicly'
);

// 3. is_configuration_broken() is exactly status-on plus a normalized-empty hash.
assert_true(Controller::is_configuration_broken(), '3: status on + empty hash is broken');

configure(['status' => true, 'password' => 'total-garbage-not-a-real-hash']);
assert_true(
    !Controller::is_configuration_broken(),
    '3: a non-empty hash of any shape is not "broken" — no format sniffing'
);

configure(['status' => false, 'password' => '']);
assert_true(!Controller::is_configuration_broken(), '3: status off is never broken');

// 4. Each exemption flips only when its own option is on.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
assert_true(Controller::is_visitor_exempt(), '4: an allowlisted IP is exempt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
assert_true(!Controller::is_visitor_exempt(), '4: a non-allowlisted IP is not exempt');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => true]);
$test_caps = ['manage_options'];
assert_true(Controller::is_visitor_exempt(), '4: an admin is exempt when allow_admins is on');

configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => false]);
assert_true(!Controller::is_visitor_exempt(), '4: an admin is not exempt when allow_admins is off');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_users' => true]);
$test_logged_in = true;
assert_true(Controller::is_visitor_exempt(), '4: a logged-in user is exempt when allow_users is on');

configure(['status' => true, 'password' => 'hashed:x', 'allow_users' => false]);
assert_true(!Controller::is_visitor_exempt(), '4: a logged-in user is not exempt when allow_users is off');

// 5. Absent / non-string REMOTE_ADDR is no exemption and no error.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
assert_true(!Controller::is_visitor_exempt(), '5: absent REMOTE_ADDR grants no IP exemption');
$_SERVER['REMOTE_ADDR'] = ['203.0.113.7'];
assert_true(!Controller::is_visitor_exempt(), '5: array REMOTE_ADDR grants no IP exemption');

// 6. is_active() is false for an exempt visitor, true otherwise.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => true]);
$test_caps = ['manage_options'];
assert_true(!Controller::is_active(), '6: an exempt visitor is not gated');

reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
assert_true(Controller::is_active(), '6: an ordinary visitor is gated');

// 7. Query-dependent exemptions.
reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$test_is_robots = true;
assert_true(!Controller::is_active(), '7: robots.txt is never gated');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$test_is_feed = true;
assert_true(!Controller::is_active(), '7: a feed is not gated when allow_feeds is on');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => false]);
$test_is_feed = true;
assert_true(Controller::is_active(), '7: a feed IS gated when allow_feeds is off');

// 8. THE CACHE INVARIANT: an exemption must never switch protection off globally.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
assert_true(Controller::is_visitor_exempt(), '8: precondition — the visitor is exempt');
assert_true(
    Controller::is_protection_enabled(),
    '8: protection stays enabled for an exempt visitor — this is what keeps DONOTCACHEPAGE on and stops an allowlisted IP warming a public cache with protected content'
);

// 9. Bypass key comparison.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'bypass_key' => 'correctkey']);
assert_true(Controller::bypass_key_matches('correctkey'), '9: the correct key matches');
assert_true(!Controller::bypass_key_matches('wrongkey'), '9: a wrong key does not match');
assert_true(!Controller::bypass_key_matches(''), '9: an empty submitted key does not match');
assert_true(!Controller::bypass_key_matches(['correctkey']), '9: an array submitted key does not match');
assert_true(!Controller::bypass_key_matches(null), '9: a null submitted key does not match');

configure(['status' => true, 'password' => 'hashed:x', 'bypass_key' => '']);
assert_true(!Controller::bypass_key_matches(''), '9: empty stored key + empty submitted key does not match');
assert_true(!Controller::bypass_key_matches('anything'), '9: an empty stored key never matches');

echo "OK\n";
