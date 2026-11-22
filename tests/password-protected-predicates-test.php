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

$test_home = 'https://ex.test/';

function home_url($path = '')
{
    global $test_home;

    return rtrim($test_home, '/') . '/' . ltrim((string) $path, '/');
}

$test_query_vars = [];

function get_query_var($name, $default = '')
{
    global $test_query_vars;

    return $test_query_vars[$name] ?? $default;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
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
    global $test_caps, $test_logged_in, $test_is_feed, $test_is_robots, $test_home;

    $test_caps = [];
    $test_logged_in = false;
    $test_is_feed = false;
    $test_is_robots = false;
    global $test_query_vars;
    $test_query_vars = [];
    unset($GLOBALS['wp'], $GLOBALS['wp_query']);
    $test_home = 'https://ex.test/';
    $_SERVER['REQUEST_URI'] = '/';
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
$_SERVER['REQUEST_URI'] = '/robots.txt';
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

// 7b. /llms.txt is never exempted as robots or feed: ?robots=1 and ?feed=rss2 make
// is_robots()/is_feed() true there, and Rank Math's llms.txt would answer past the gate.
foreach (['/llms.txt?robots=1', '/llms.txt/?robots=1', '/llms.txt?feed=rss2&robots=1'] as $uri) {
    reset_request();
    configure(['status' => true, 'password' => 'hashed:x']);
    $_SERVER['REQUEST_URI'] = $uri;
    $test_is_robots = true;
    assert_true(Controller::is_active(), "7b: {$uri} is gated, not exempt as robots");
}

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$_SERVER['REQUEST_URI'] = '/llms.txt?feed=rss2';
$test_is_feed = true;
assert_true(Controller::is_active(), '7b: /llms.txt?feed=rss2 is gated, even with allow_feeds on');

reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$_SERVER['REQUEST_URI'] = '/robots.txt';
$test_is_robots = true;
assert_true(!Controller::is_active(), '7b: the real /robots.txt stays exempt');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$_SERVER['REQUEST_URI'] = '/feed/';
$test_is_feed = true;
assert_true(!Controller::is_active(), '7b: a real feed stays exempt with allow_feeds on');

reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$test_home = 'https://ex.test/sub/';
$_SERVER['REQUEST_URI'] = '/sub/llms.txt?robots=1';
$test_is_robots = true;
assert_true(Controller::is_active(), '7b: subdirectory install, /sub/llms.txt?robots=1 is gated');
$_SERVER['REQUEST_URI'] = '/sub/robots.txt';
assert_true(!Controller::is_active(), '7b: subdirectory install, /sub/robots.txt stays exempt');

// 7c. PATH_INFO permalinks: /index.php/llms.txt still matches Rank Math's ^llms\.txt$ rule.
reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$_SERVER['REQUEST_URI'] = '/index.php/llms.txt?robots=1';
$test_is_robots = true;
assert_true(Controller::is_active(), '7c: /index.php/llms.txt?robots=1 is gated');
$_SERVER['REQUEST_URI'] = '/index.php/robots.txt';
assert_true(!Controller::is_active(), '7c: /index.php/robots.txt keeps its exemption');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$test_home = 'https://ex.test/sub/';
$_SERVER['REQUEST_URI'] = '/sub/index.php/llms.txt?feed=rss2';
$test_is_feed = true;
assert_true(Controller::is_active(), '7c: subdirectory install, /sub/index.php/llms.txt?feed=rss2 is gated');

// 7d. Fail closed on what WordPress parsed, not only on the raw path: an encoding the raw
// check misses still reaches Rank Math through its rule, the parsed request or its query var.
// Each case is an allowed feed on a raw path other than llms.txt, so only the one parsed
// signal under test decides; on a ?robots=1 request the robots-path check would decide first.
set_error_handler(static function (int $no, string $msg): bool {
    throw new ErrorException($msg, 0, $no); // a warning from a missing $wp property fails the test
});
foreach ([
    'Rank Math\'s matched rule' => static function (): void {
        $GLOBALS['wp'] = (object) ['matched_rule' => '^llms\.txt$', 'request' => ''];
    },
    'a parsed request of llms.txt' => static function (): void {
        $GLOBALS['wp'] = (object) ['matched_rule' => null, 'request' => 'llms.txt'];
    },
    'Rank Math\'s llms_txt query var' => static function (): void {
        $GLOBALS['wp_query'] = new stdClass();
        $GLOBALS['test_query_vars'] = ['llms_txt' => '1'];
    },
] as $signal => $set_signal) {
    reset_request();
    configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
    $_SERVER['REQUEST_URI'] = '/x?feed=rss2';
    $test_is_feed = true;
    assert_true(!Controller::is_active(), "7d control: /x?feed=rss2 without {$signal} is an allowed feed");
    $set_signal();
    assert_true(Controller::is_active(), "7d: /x?feed=rss2 with {$signal} is gated, even with allow_feeds on");
}

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$_SERVER['REQUEST_URI'] = '/llms%2Etxt?feed=rss2';
$test_is_feed = true;
assert_true(Controller::is_active(), '7d: /llms%2Etxt?feed=rss2 is gated by the decoded raw path alone');

reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$_SERVER['REQUEST_URI'] = '/robots.txt';
$test_is_robots = true;
$GLOBALS['wp'] = new stdClass(); // no matched_rule, no request: no warning
$GLOBALS['wp_query'] = new stdClass();
assert_true(!Controller::is_active(), '7d: /robots.txt stays exempt with $wp lacking the properties');
$GLOBALS['wp'] = (object) ['matched_rule' => 'robots\.txt$', 'request' => 'robots.txt'];
assert_true(!Controller::is_active(), '7d: /robots.txt stays exempt with its own rule matched');

// 7e. The robots exemption needs the request to really be robots.txt: is_robots() follows
// the robots query var, so ?robots=1 on any address would otherwise pass the gate.
reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$test_is_robots = true;
$_SERVER['REQUEST_URI'] = '/robots.txt';
$GLOBALS['wp'] = (object) ['matched_rule' => 'robots\.txt$'];
assert_true(!Controller::is_active(), '7e: /robots.txt with its rule matched is exempt');
unset($GLOBALS['wp']);
assert_true(!Controller::is_active(), '7e: /robots.txt by raw path is exempt');
$test_home = 'https://ex.test/sub/';
$_SERVER['REQUEST_URI'] = '/sub/robots.txt';
assert_true(!Controller::is_active(), '7e: /sub/robots.txt under home /sub/ is exempt');

$test_home = 'https://ex.test/';
foreach ([
    ['/some-endpoint/?robots=1', 'some-endpoint/?$'],
    ['/?robots=1', null],
    ['/sitemap.xml?robots=1', '^wp-sitemap\.xml$'],
    ['/sitemap.xml?robots=1', null],
] as [$uri, $rule]) {
    $_SERVER['REQUEST_URI'] = $uri;
    $GLOBALS['wp'] = (object) ['matched_rule' => $rule];
    assert_true(Controller::is_active(), "7e: {$uri} (rule " . var_export($rule, true) . ') is gated');
    unset($GLOBALS['wp']);
    assert_true(Controller::is_active(), "7e: {$uri} without \$wp is gated");
}
restore_error_handler();

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
