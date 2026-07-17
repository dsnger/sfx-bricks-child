<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Pins two call-site invariants that password-protected-predicates-test.php
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

// --- Test B: login_url() must percent-encode the redirect target -----------
//
// Otherwise the target's own "?", "&" and "=" land raw in the outer query
// string and tear it apart: a second-level "?" is not a delimiter to
// parse_url()/parse_str(), so the target's own params (here "a" and "b")
// would resurface as top-level query args instead of staying inside
// redirect_to's value, and redirect_to itself would be truncated.
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

Controller::disable_caching();

assert_true(
    defined('DONOTCACHEPAGE'),
    'disable_caching(): an exempt visitor must still get an uncacheable response — otherwise their '
        . 'exempt-and-therefore-fully-rendered protected page populates a shared, URL-keyed page cache for everyone'
);

echo "OK\n";
