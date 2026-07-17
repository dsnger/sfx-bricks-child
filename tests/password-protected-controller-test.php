<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Pins three call-site invariants that password-protected-predicates-test.php
 * cannot reach because it only exercises the predicates' return values, never
 * who calls them:
 *
 *   - disable_caching() MUST key off is_protection_enabled(), not is_active().
 *     If it keyed off is_active(), an exempt visitor (e.g. an allowlisted IP)
 *     would get a response that is both exempt from the login gate AND
 *     cacheable, and a URL-keyed page cache would then serve that
 *     fully-rendered protected page to everyone. See Controller::disable_caching().
 *
 *   - login_url() MUST urlencode() the redirect_to target before handing it to
 *     add_query_arg(). WordPress's add_query_arg() does not encode the value
 *     you give it, so an un-encoded target's own "?" and "&" would tear the
 *     outer query string apart. See Controller::login_url().
 *
 *   - maybe_process_login() MUST reject a wrong password. This is the
 *     authentication check for the whole feature: inverting the
 *     wp_check_password() condition would issue an auth cookie and redirect
 *     on a WRONG password instead of recording incorrect_password. Both a
 *     wrong-password and a correct-password case are pinned together —
 *     otherwise the wrong-password assertion alone would still pass if
 *     maybe_process_login() did nothing at all. See
 *     Controller::maybe_process_login().
 */

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function is_admin(): bool
{
    return false;
}

// Not exercised by the correct implementation (disable_caching() only calls
// is_protection_enabled()), but stubbed so that inverting disable_caching()
// to key off is_active() instead fails on the real assertion below rather
// than on an unrelated missing WordPress stub.
function apply_filters(string $tag, $value, ...$args)
{
    return $value;
}

function is_feed(): bool
{
    return false;
}

function is_robots(): bool
{
    return false;
}

function home_url(string $path = '/'): string
{
    return 'https://example.test' . $path;
}

/**
 * Matches the one point about WordPress's real add_query_arg() that this test
 * cares about: it does NOT urlencode the value it is handed (build_query()
 * calls _http_build_query() with $urlencode = false). A stub that encoded the
 * value here would make the login_url() test vacuous — it would pass even if
 * Controller::login_url() dropped its own urlencode() call.
 */
function add_query_arg(string $key, string $value, string $url): string
{
    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . $key . '=' . $value;
}

// --- Stubs needed only by maybe_process_login() / Auth --------------------

define('DAY_IN_SECONDS', 86400); // Auth::SESSION_WINDOW is a class constant expression; must exist before Auth.php loads.
define('COOKIEHASH', 'testcookiehash');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('COOKIE_DOMAIN', '');

function wp_verify_nonce($nonce, $action = -1)
{
    // A stand-in "the nonce is valid" check: real enough to isolate the
    // password check without reimplementing WordPress's nonce hashing.
    return $nonce === 'valid-nonce';
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function __($text, $domain = null)
{
    return $text;
}

$nocache_headers_calls = 0;

function nocache_headers(): void
{
    global $nocache_headers_calls;
    $nocache_headers_calls++;
}

function is_ssl(): bool
{
    return false;
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt-for-' . $scheme;
}

function wp_hash($data, $scheme = 'auth')
{
    return hash_hmac('md5', (string) $data, wp_salt($scheme));
}

/**
 * Modeled exactly like tests/password-protected-settings-test.php's stub: the
 * real hashing algorithm is not available here, and is not what this test
 * cares about. What matters is that a password NOT matching the stored hash
 * returns false, and the one that does returns true.
 */
function wp_check_password($password, $hash)
{
    return hash_equals((string) $hash, 'hashed:' . md5((string) $password));
}

function wp_validate_redirect($location, $default = '')
{
    return $location;
}

/**
 * Thrown instead of exiting the process, per Gate B's approach: this makes a
 * wrongly-successful redirect (and the exit right after it) observable to the
 * test instead of terminating php before any assertion runs.
 */
class WpSafeRedirectCalled extends \Exception
{
}

function wp_safe_redirect($location, $status = 302)
{
    throw new WpSafeRedirectCalled((string) $location);
}

/**
 * Auth::set_cookie()/clear_cookie() are namespace SFX\PasswordProtected code
 * calling setcookie() unqualified, so — same fallback-to-current-namespace
 * rule already used above for get_option()/home_url()/etc. — this shadows
 * PHP's builtin inside this namespace without touching real HTTP headers.
 * Every call is recorded so a test can tell "cleared" (empty value) apart
 * from "a real auth cookie was set" (non-empty value).
 */
$test_cookie_calls = [];

function setcookie($name, $value = '', $options = [])
{
    global $test_cookie_calls;

    $test_cookie_calls[] = ['name' => $name, 'value' => $value];

    return true;
}

require_once __DIR__ . '/support/password-protected-wp-error-stub.php';
require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';
require_once __DIR__ . '/../inc/PasswordProtected/Auth.php';
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

// --- Test B: login_url() must percent-encode the redirect target -----------
//
// Otherwise the target's own "?", "&" and "=" land raw in the outer query
// string and tear it apart: a second-level "?" is not a delimiter to
// parse_url()/parse_str(), so redirect_to's value gets truncated at that
// embedded "?" — "a=1" stays merged into the (now-truncated) redirect_to
// value, but everything after the next "&" (here "b") resurfaces as its own
// top-level query arg instead of staying inside redirect_to's value.
$target = 'https://site.test/private/?a=1&b=2';
$url = Controller::login_url($target);

$expected_encoded = 'redirect_to=' . urlencode($target);
assert_true(
    str_contains($url, $expected_encoded),
    'login_url(): the redirect_to value must appear percent-encoded in the URL — without urlencode(), '
        . "the target's own ? and & would tear the query string apart"
);

$query = (string) parse_url($url, PHP_URL_QUERY);
parse_str($query, $parsed);

assert_true(
    ($parsed['redirect_to'] ?? null) === $target,
    'login_url(): round-tripping the URL through parse_str() must recover the exact original redirect target'
);

assert_true(
    !array_key_exists('a', $parsed) && !array_key_exists('b', $parsed),
    "login_url(): the redirect target's own query params (a, b) must stay inside redirect_to's value, "
        . 'not resurface as top-level query args'
);

// --- Test A: disable_caching() must key off is_protection_enabled() --------
//
// One test per process: DONOTCACHEPAGE is defined via define(), which is
// process-global and one-shot. The negative case (a non-exempt visitor still
// getting DONOTCACHEPAGE) is already covered by is_protection_enabled()'s own
// tests in password-protected-predicates-test.php; what is missing there is
// proof that disable_caching() actually calls it.
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
configure([
    'status' => true,
    'password' => 'hashed:x',
    'allowed_ips' => '203.0.113.7',
]);

assert_true(
    Controller::is_visitor_exempt(),
    'precondition: the visitor must be exempt (allowlisted IP) for this test to mean anything'
);

$nocache_headers_calls = 0;
Controller::disable_caching();

assert_true(
    defined('DONOTCACHEPAGE'),
    'disable_caching(): an exempt visitor must still get an uncacheable response — otherwise their '
        . 'exempt-and-therefore-fully-rendered protected page populates a shared, URL-keyed page cache for everyone'
);

assert_true(
    $nocache_headers_calls === 1,
    'disable_caching(): must also send real HTTP no-cache headers — DONOTCACHEPAGE only reaches cooperating '
        . 'WordPress caches, not the browser/CDN/reverse-proxy layer that a URL-keyed cache actually lives in'
);

// --- Test C: maybe_process_login() must reject a wrong password ------------
//
// This is the authentication check for the whole feature. Both halves of the
// pair below matter: the wrong-password assertion alone would still pass if
// maybe_process_login() did nothing at all — only pairing it with the
// correct-password case proves the success path is real and gated on the
// password actually being right.
$correct_password = 'correct horse battery staple';

configure([
    'status' => true,
    'password' => 'hashed:' . md5($correct_password),
]);

$_POST = [
    'sfx_pp_pwd' => 'a wrong password',
    '_wpnonce' => 'valid-nonce',
];
$_COOKIE = [];

assert_true(
    wp_verify_nonce($_POST['_wpnonce'], 'sfx_pp_login'),
    'precondition: the stubbed nonce must PASS, so the test below isolates the password check '
        . 'rather than accidentally passing because the nonce failed'
);

Controller::$errors = new \WP_Error();
$test_cookie_calls = [];

$redirected = false;
try {
    Controller::maybe_process_login();
} catch (WpSafeRedirectCalled $e) {
    $redirected = true;
}

assert_true(
    !$redirected,
    'maybe_process_login(): a wrong password must NOT reach redirect_to()/wp_safe_redirect() — '
        . 'that path is the success (authenticated) path'
);

$auth_cookie_set = false;
foreach ($test_cookie_calls as $call) {
    if ($call['value'] !== '') {
        $auth_cookie_set = true;
    }
}
assert_true(
    !$auth_cookie_set,
    'maybe_process_login(): a wrong password must NOT set an auth cookie (only clear_cookie() may run, '
        . 'which writes empty-value clearing cookies, never a real one)'
);

assert_true(
    in_array('incorrect_password', Controller::$errors->get_error_codes(), true),
    'maybe_process_login(): a wrong password must record incorrect_password on Controller::$errors'
);

// --- Test D: maybe_process_login() must accept the correct password --------
//
// The pair to Test C: without this, Test C's assertions would pass even if
// maybe_process_login() were a no-op, since a no-op also never redirects,
// never sets a cookie, and never adds an error. This proves the success path
// is real and is reached specifically when the password is right.
$_POST = [
    'sfx_pp_pwd' => $correct_password,
    '_wpnonce' => 'valid-nonce',
    'redirect_to' => '',
];
$_COOKIE = [];

Controller::$errors = new \WP_Error();
$test_cookie_calls = [];

$redirected = false;
try {
    Controller::maybe_process_login();
} catch (WpSafeRedirectCalled $e) {
    $redirected = true;
}

assert_true(
    $redirected,
    'maybe_process_login(): the correct password must reach redirect_to()/wp_safe_redirect() — '
        . 'the success path must actually be reachable, not just theoretically gated'
);

$auth_cookie_set = false;
foreach ($test_cookie_calls as $call) {
    if ($call['value'] !== '') {
        $auth_cookie_set = true;
    }
}
assert_true(
    $auth_cookie_set,
    'maybe_process_login(): the correct password must set a real (non-empty) auth cookie'
);

assert_true(
    !in_array('incorrect_password', Controller::$errors->get_error_codes(), true),
    'maybe_process_login(): the correct password must NOT record incorrect_password'
);

echo "OK\n";
