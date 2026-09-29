<?php

declare(strict_types=1);

// Stubs. Rule.php is pure; these are the only WordPress functions it may call.

function __($text, $domain = null)
{
    return $text;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

// Copied verbatim from wp-includes/pluggable.php: target_ok() compares against
// this function's exact output, so an approximation would test the wrong thing.
function wp_sanitize_redirect( $location ) {
	// Encode spaces.
	$location = str_replace( ' ', '%20', $location );

	$regex    = '/
	(
		(?: [\xC2-\xDF][\x80-\xBF]        # double-byte sequences   110xxxxx 10xxxxxx
		|   \xE0[\xA0-\xBF][\x80-\xBF]    # triple-byte sequences   1110xxxx 10xxxxxx * 2
		|   [\xE1-\xEC][\x80-\xBF]{2}
		|   \xED[\x80-\x9F][\x80-\xBF]
		|   [\xEE-\xEF][\x80-\xBF]{2}
		|   \xF0[\x90-\xBF][\x80-\xBF]{2} # four-byte sequences   11110xxx 10xxxxxx * 3
		|   [\xF1-\xF3][\x80-\xBF]{3}
		|   \xF4[\x80-\x8F][\x80-\xBF]{2}
	){1,40}                              # ...one or more times
	)/x';
	$location = preg_replace_callback( $regex, '_wp_sanitize_utf8_in_redirect', $location );
	$location = preg_replace( '|[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]|i', '', $location );
	$location = wp_kses_no_null( $location );

	// Remove %0D and %0A from location.
	$strip = array( '%0d', '%0a', '%0D', '%0A' );
	return _deep_replace( $strip, $location );
}

// Verbatim from wp-includes/pluggable.php.
function _wp_sanitize_utf8_in_redirect( $matches ) {
	return urlencode( $matches[0] );
}

// Verbatim from wp-includes/kses.php.
function wp_kses_no_null( $content, $options = null ) {
	if ( ! isset( $options['slash_zero'] ) ) {
		$options = array( 'slash_zero' => 'remove' );
	}

	$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );
	if ( 'remove' === $options['slash_zero'] ) {
		$content = preg_replace( '/\\\\+0+/', '', $content );
	}

	return $content;
}

// Verbatim from wp-includes/formatting.php.
function _deep_replace( $search, $subject ) {
	$subject = (string) $subject;

	$count = 1;
	while ( $count ) {
		$subject = str_replace( $search, '', $subject, $count );
	}

	return $subject;
}

require_once __DIR__ . '/../inc/Redirects/Rule.php';

use SFX\Redirects\Rule;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

/** A valid rule input with overrides. */
function input(array $overrides = []): array
{
    return array_merge([
        'source'      => '/old',
        'match_type'  => 'exact',
        'target'      => '/new',
        'status_code' => '301',
        'enabled'     => true,
        'note'        => '',
    ], $overrides);
}

function row(int $id, string $source, string $target, int $status = 301, string $type = 'exact'): array
{
    return ['id' => $id, 'source' => $source, 'match_type' => $type, 'target' => $target, 'status_code' => $status];
}

const HOME = 'https://ex.test';

// ---------------------------------------------------------------- canonical_path

$paths = [
    '/About/'            => '/about',
    '/a//b'              => '/a/b',
    '/a/./b'             => '/a/b',
    '/a/b/../c'          => '/a/c',
    '/../../a'           => '/a',
    '/a/%2e%2e/b'        => '/b',
    '/a/%2E%2E/b'        => '/b',
    '/a%2Fb'             => '/a/b',
    '/%252F'             => '/%252f',
    '/a%3Fb'             => '/a%3fb',
    '/a%23b'             => '/a%23b',
    '/über-uns'          => '/%c3%bcber-uns',
    '/%C3%BCber-uns'     => '/%c3%bcber-uns',
    '/a b'               => '/a%20b',
    ''                   => '/',
    '/'                  => '/',
    '//'                 => '/',
    '/?x=1'              => '/',
    '/a?b=1'             => '/a',
    '/a#frag'            => '/a',
    '/A/B.html'          => '/a/b.html',
    '/...'               => '/...',
];
foreach ($paths as $raw => $expected) {
    $once = Rule::canonical_path((string) $raw);
    assert_same($expected, $once, "canonical_path({$raw})");
    assert_same($once, Rule::canonical_path($once), "canonical_path idempotent for {$raw}");
}
assert_same(Rule::canonical_path('/über'), Rule::canonical_path('/%C3%BCber'), 'canonical_path: /über ≡ /%C3%BCber');

// ---------------------------------------------------------------- canonical_query

assert_same('a=1&b=2', Rule::canonical_query('b=2&a=1'), 'canonical_query: order');
assert_same('x=1&x=2', Rule::canonical_query('x=2&x=1'), 'canonical_query: repeated keys kept');
assert_same('a.b=1&c_d=2', Rule::canonical_query('c_d=2&a.b=1'), 'canonical_query: dotted key kept');
assert_same('a[a]=2&a[b]=1', Rule::canonical_query('a[b]=1&a[a]=2'), 'canonical_query: nested keys kept');
assert_same('a=1', Rule::canonical_query('&&a=1&'), 'canonical_query: empty pieces dropped');
assert_same('A=B', Rule::canonical_query('A=B'), 'canonical_query: case preserved');
assert_same('', Rule::canonical_query(''), 'canonical_query: empty');

// ---------------------------------------------------------------- request_parts / home_relative

assert_same('/', Rule::request_parts('/blog', '/blog')['path'], 'request_parts: /blog → /');
assert_same('/', Rule::request_parts('/blog/', '/blog')['path'], 'request_parts: /blog/ → /');
assert_same('/x', Rule::request_parts('/blog/x', '/blog')['path'], 'request_parts: /blog/x → /x');
assert_same('/blogger', Rule::request_parts('/blogger', '/blog')['path'], 'request_parts: /blogger untouched');
assert_same('/blog/x', Rule::request_parts('/blog/blog/x', '/blog')['path'], 'request_parts: stripped once');
assert_same('/x', Rule::request_parts('/blog/x', '/blog/')['path'], 'request_parts: home path with trailing slash');
$parts = Rule::request_parts('/X/?b=2&a=1', '');
assert_same(['path' => '/x', 'query' => 'a=1&b=2', 'raw_query' => 'b=2&a=1'], $parts, 'request_parts: raw_query passes through unchanged');
assert_same(['path' => '/', 'query' => '', 'raw_query' => ''], Rule::request_parts('/', ''), 'request_parts: root');
assert_same('/x', Rule::home_relative('/blog/x', '/blog'), 'home_relative: strips segment prefix');
assert_same('/blogger', Rule::home_relative('/blogger', '/blog'), 'home_relative: not a segment prefix');
assert_same('/a', Rule::home_relative('/a', ''), 'home_relative: root install');

// ---------------------------------------------------------------- source_hash

assert_same(sha1("exact\n/a"), Rule::source_hash('exact', '/a'), 'source_hash formula');
assert_true(Rule::source_hash('exact', '/a') !== Rule::source_hash('regex', '/a'), 'source_hash: type is part of identity');

// ---------------------------------------------------------------- target_ok

foreach (['/x', '/', 'https://a.b/x', 'https://a.b:8080/x', 'http://a.b', 'https://a.b/x?y=1#z', '/x?y=../z', '/a..b', '/price%24'] as $ok) {
    assert_true(Rule::target_ok($ok), "target_ok accepts {$ok}");
}
foreach ([
    '', '//a.b', '/\\a.b', 'https://u:p@a.b/', 'https://u@a.b/', 'https:///x', 'javascript:x', 'data:x', 'ftp://a',
    'a.b/x', 'x', '/a b', " /x", "/x\r\n", "/x\n", "/x\t", "/x\x00", '/x\\y', 'https://a.b/ü', '/$1', 'http:x',
    // dot segments, literal and encoded
    '/x/../a', '/a//../b', '/a/%2e%2e/b', '/a/.', '/a/..', '/./a', '/a/%2E/b', 'https://a.b/x/../y',
] as $bad) {
    assert_true(!Rule::target_ok($bad), 'target_ok rejects ' . var_export($bad, true));
}

// ---------------------------------------------------------------- validate

$r = Rule::validate(input());
assert_same([], $r['errors'], 'validate: valid baseline');
assert_same(['source' => '/old', 'match_type' => 'exact', 'target' => '/new', 'status_code' => 301, 'enabled' => true, 'note' => ''], $r['rule'], 'validate: baseline rule');

$r = Rule::validate(input(['source' => '/Old-Page/?b=2&a=1']));
assert_same('/old-page?a=1&b=2', $r['rule']['source'], 'validate: exact source canonicalised with query');
$r = Rule::validate(input(['source' => '/x?']));
assert_same('/x', $r['rule']['source'], 'validate: empty query dropped');
assert_true(Rule::validate(input(['source' => 'https://a.b/x']))['errors'] !== [], 'validate: pasted full URL rejected');
assert_true(Rule::validate(input(['source' => '/a#b']))['errors'] !== [], 'validate: # in exact source rejected');
assert_true(Rule::validate(input(['source' => '']))['errors'] !== [], 'validate: source required');
assert_true(Rule::validate(input(['source' => '/' . str_repeat('a', 255)]))['errors'] !== [], 'validate: 256-byte source rejected');
assert_same([], Rule::validate(input(['source' => '/' . str_repeat('a', 254)]))['errors'], 'validate: 255-byte source accepted');
assert_same([], Rule::validate(input(['source' => '/x/../old']))['errors'], 'validate: dot segments allowed in source');

$r = Rule::validate(input(['status_code' => '']));
assert_same(301, $r['rule']['status_code'], 'validate: status default 301');
assert_same(302, Rule::validate(input(['status_code' => 302]))['rule']['status_code'], 'validate: int status accepted');
assert_true(Rule::validate(input(['status_code' => '404']))['errors'] !== [], 'validate: status 404 rejected');
assert_true(Rule::validate(input(['status_code' => 'abc']))['errors'] !== [], 'validate: non-numeric status rejected');

$r = Rule::validate(input(['status_code' => '410', 'target' => "junk \x01"]));
assert_same([], $r['errors'], 'validate: 410 ignores target');
assert_same('', $r['rule']['target'], 'validate: 410 stores empty target');

assert_true(Rule::validate(input(['target' => '']))['errors'] !== [], 'validate: target required for 3xx');
assert_true(Rule::validate(input(['target' => '/' . str_repeat('a', 2000)]))['errors'] !== [], 'validate: 2001-byte target rejected');
assert_same([], Rule::validate(input(['target' => '/' . str_repeat('a', 1999)]))['errors'], 'validate: 2000-byte target accepted');
foreach (['/x/../a', '/a//../b', '/a/%2e%2e/b'] as $dot) {
    assert_true(Rule::validate(input(['target' => $dot]))['errors'] !== [], "validate: dot-segment target {$dot} rejected");
}
assert_true(Rule::validate(input(['note' => str_repeat('n', 256)]))['errors'] !== [], 'validate: 256-byte note rejected');
assert_true(Rule::validate(input(['match_type' => 'glob']))['errors'] !== [], 'validate: unknown match type rejected');
assert_same('exact', Rule::validate(input(['match_type' => '']))['rule']['match_type'], 'validate: match type default exact');
assert_true(Rule::validate(input(['enabled' => '1']))['errors'] !== [], 'validate: non-bool enabled rejected');
assert_same(false, Rule::validate(input(['enabled' => false]))['rule']['enabled'], 'validate: enabled false kept');
assert_true(Rule::validate(input(['source' => ['/a']]))['errors'] !== [], 'validate: array source rejected');
assert_true(Rule::validate(input(['note' => "\xFF"]))['errors'] !== [], 'validate: invalid UTF-8 rejected');
assert_true(Rule::validate(input(['note' => "a\x7Fb"]))['errors'] !== [], 'validate: DEL rejected');
assert_true(Rule::validate(input(['source' => "/a\nb"]))['errors'] !== [], 'validate: control char in source rejected');
assert_true(Rule::validate(input(['target' => '/$1']))['errors'] !== [], 'validate: placeholder in exact target rejected');

// ---------------------------------------------------------------- regex

assert_same(null, Rule::compile_regex('^/(a'), 'regex: invalid pattern → null');
assert_same(null, Rule::compile_regex('^/a~b'), 'regex: unescaped ~ → null');
assert_same(null, Rule::compile_regex('^/a\\\\~b'), 'regex: ~ after an escaped backslash is unescaped → null');
assert_same('~^/a\\~b~iu', Rule::compile_regex('^/a\\~b'), 'regex: escaped ~ accepted');
assert_same(null, Rule::compile_regex('^/a\\'), 'regex: trailing backslash → null');

$regex = ['match_type' => 'regex', 'source' => '^/blog/(\d+)/(.*)$', 'target' => '/news/$2-$1'];
$r = Rule::validate(input($regex));
assert_same([], $r['errors'], 'regex: template target with placeholders passes validation');
assert_same('^/blog/(\d+)/(.*)$', $r['rule']['source'], 'regex: body stored as written');
$msgs = Rule::validate(input(['match_type' => 'regex', 'source' => '^/a~b']))['errors'];
assert_true($msgs !== [] && str_contains(implode(' ', $msgs), '\\~'), 'regex: unescaped ~ message names \\~');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a']))['errors'] !== [], 'regex: invalid pattern rejected by validate');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)(b)$', 'target' => '/$3']))['errors'] !== [], 'regex: $3 beyond group count rejected');
assert_same([], Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)(b)?(c)?$', 'target' => '/$3']))['errors'], 'regex: trailing optional group counts');
assert_same([], Rule::validate(input(['match_type' => 'regex', 'source' => '^/(?<n>a)$', 'target' => '/$1']))['errors'], 'regex: named group counts once');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)$', 'target' => 'https://$1.example.com/x']))['errors'] !== [], 'regex: placeholder in host rejected');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)$', 'target' => 'https://a.b$1/x']))['errors'] !== [], 'regex: placeholder in authority rejected');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)$', 'target' => 'https://a.b?x=$1']))['errors'] !== [], 'regex: placeholder before first path slash rejected');
assert_same([], Rule::validate(input(['match_type' => 'regex', 'source' => '^/(a)$', 'target' => 'https://a.b/$1']))['errors'], 'regex: placeholder in absolute path accepted');
assert_true(Rule::validate(input(['match_type' => 'regex', 'source' => '^/' . str_repeat('a', 255)]))['errors'] !== [], 'regex: 256-byte body rejected');

assert_same('/news/%c3%bc', Rule::resolve_target('/news/$1', ['/x/%c3%bc', '%c3%bc']), 'resolve: capture inserted as is, not double-encoded');
assert_same('/x/', Rule::resolve_target('/x/$2', ['/a', 'a']), 'resolve: unmatched (absent) group → empty');
assert_same('/x/-a', Rule::resolve_target('/x/$1-$2', ['/a', '', 'a']), 'resolve: unmatched (empty) group → empty');
assert_same('/price%24/a', Rule::resolve_target('/price%24/$1', ['/a', 'a']), 'resolve: %24 literal kept');
assert_same('/a0', Rule::resolve_target('/$10', ['/a', 'a']), 'resolve: $10 is $1 followed by 0');
assert_same(null, Rule::resolve_target('/$1', ['/evil.com', '/evil.com']), 'resolve: /$1 fed /evil.com → null');
assert_same(null, Rule::resolve_target('/$1', ['x', 'a/../b']), 'resolve: dot segment in resolved target → null');
assert_same('https://a.b/x/c', Rule::resolve_target('https://a.b/x/$1', ['/c', 'c']), 'resolve: absolute target');

// ---------------------------------------------------------------- pick

$c = [
    row(1, '/a', '/b'),
    row(2, '/a?x=1', '/c'),
    row(3, '^/a$', '/d', 301, 'regex'),
];
assert_same(['id' => 2, 'status_code' => 301, 'url' => HOME . '/c'], Rule::pick($c, '/a', 'x=1', 'x=1', HOME), 'pick: exact+query wins, no passthrough');
assert_same(['id' => 1, 'status_code' => 301, 'url' => HOME . '/b?y=2&a=1'], Rule::pick($c, '/a', 'a=1&y=2', 'y=2&a=1', HOME), 'pick: exact path wins over regex, raw query passed through');
assert_same(['id' => 1, 'status_code' => 301, 'url' => HOME . '/b'], Rule::pick($c, '/a', '', '', HOME), 'pick: exact path without query');
assert_same(['id' => 3, 'status_code' => 301, 'url' => HOME . '/d'], Rule::pick([$c[2]], '/a', '', '', HOME), 'pick: regex when no exact');
assert_same(null, Rule::pick($c, '/zzz', '', '', HOME), 'pick: no match');
assert_same(['id' => 4, 'status_code' => 302, 'url' => HOME . '/four'], Rule::pick([row(5, '^/r', '/five', 301, 'regex'), row(4, '^/r', '/four', 302, 'regex')], '/r', '', '', HOME), 'pick: regex first match in id order');
assert_same(HOME . '/b?z=1', Rule::pick([row(1, '/a', '/b?z=1')], '/a', 'y=2', 'y=2', HOME)['url'], 'pick: target with query drops request query');
assert_same(HOME . '/b?y=2#top', Rule::pick([row(1, '/a', '/b#top')], '/a', 'y=2', 'y=2', HOME)['url'], 'pick: fragment stays after passed-through query');
assert_same(['id' => 9, 'status_code' => 410, 'url' => null], Rule::pick([row(9, '/gone', '', 410)], '/gone', '', '', HOME), 'pick: 410 has no url');
assert_same(['id' => 9, 'status_code' => 410, 'url' => null], Rule::pick([row(9, '^/gone', '', 410, 'regex')], '/gone', '', '', HOME), 'pick: regex 410 has no url');
assert_same('https://ex.test/blog/new/', Rule::pick([row(1, '/a', '/new/')], '/a', '', '', 'https://ex.test/blog')['url'], 'pick: relative target under sub-directory home');
assert_same('https://other.test/x', Rule::pick([row(1, '/a', 'https://other.test/x')], '/a', '', '', HOME)['url'], 'pick: absolute target as is');
assert_same(HOME . '/b?q=x', Rule::pick([row(1, '/a', '/b')], '/a', 'q=<x>', 'q=<x>', HOME)['url'], 'pick: final URL goes through wp_sanitize_redirect');
assert_same(null, Rule::pick([row(1, '/a', '/b')], '/a', 'q=1', 'q=' . str_repeat('1', 2000), HOME), 'pick: over-long URL after passthrough → null');
assert_same(HOME . '/news/hello-12', Rule::pick([row(1, $regex['source'], $regex['target'], 301, 'regex')], '/blog/12/hello', '', '', HOME)['url'], 'pick: regex substitution');
assert_same(null, Rule::pick([row(1, '^(.*)$', '/$1', 301, 'regex'), row(2, '^/evil', '/fallback', 301, 'regex')], '/evil.com', '', '', HOME), 'pick: failed resolution → null, no fallback');
assert_same(['id' => 1, 'status_code' => 301, 'url' => HOME . '/b'], Rule::pick([['id' => '1', 'source' => '/a', 'match_type' => 'exact', 'target' => '/b', 'status_code' => '301']], '/a', '', '', HOME), 'pick: string columns from $wpdb are cast');

$long_ok = '/' . str_repeat('a', Rule::MAX_REGEX_SUBJECT - 1);
$long_bad = '/' . str_repeat('a', Rule::MAX_REGEX_SUBJECT);
assert_true(Rule::pick([row(1, '^/a+$', '/x', 301, 'regex')], $long_ok, '', '', HOME) !== null, 'pick: 1024-byte subject matched');
assert_same(null, Rule::pick([row(1, '^/a+$', '/x', 301, 'regex')], $long_bad, '', '', HOME), 'pick: 1025-byte subject skipped');

// Catastrophic backtracking is cut off by the PCRE budget and reads as "no match".
$backtrack_before = ini_get('pcre.backtrack_limit');
$subject = '/' . str_repeat('a', 998) . 'b';
assert_same(1000, strlen($subject), 'catastrophic: subject is 1000 bytes');
$t = microtime(true);
$result = Rule::pick([row(1, '^/(a+)+$', '/x', 301, 'regex')], $subject, '', '', HOME);
$elapsed = microtime(true) - $t;
assert_same(null, $result, 'catastrophic: no match');
assert_true($elapsed < 1.0, "catastrophic: finished within 1 s ({$elapsed})");
assert_same($backtrack_before, ini_get('pcre.backtrack_limit'), 'catastrophic: pcre.backtrack_limit restored');

// 50 ms aggregate budget: enough slow rules to take well over 50 ms, then a matching one.
// Calibrate one rule under the same PCRE limits pick() sets, so the rule count
// scales with this machine's speed instead of assuming it.
$recursion_before = ini_get('pcre.recursion_limit');
ini_set('pcre.backtrack_limit', '100000');
ini_set('pcre.recursion_limit', '10000');
$t = microtime(true);
preg_match('~^/(a+)+$~iu', $subject);
$per_rule = max(microtime(true) - $t, 0.0001);
ini_set('pcre.backtrack_limit', (string) $backtrack_before);
ini_set('pcre.recursion_limit', (string) $recursion_before);
$n = (int) ceil(0.5 / $per_rule);
$slow = [];
for ($i = 1; $i <= $n; $i++) {
    $slow[] = row($i, '^/(a+)+$', '/x', 301, 'regex');
}
$slow[] = row($n + 1, '^/a', '/reached', 301, 'regex');
$t = microtime(true);
$result = Rule::pick($slow, $subject, '', '', HOME);
$elapsed = microtime(true) - $t;
assert_same(null, $result, "budget: loop stopped before the matching rule ({$n} slow rules)");
assert_true($elapsed < 0.25, "budget: stopped near 50 ms ({$elapsed})");

// ---------------------------------------------------------------- loops

assert_true(Rule::url_identity(HOME . '/?p=1') !== Rule::url_identity(HOME . '/?p=2'), 'identity: /?p=1 → /?p=2 allowed');
assert_same(Rule::url_identity('https://ex.test/a'), Rule::url_identity('https://EX.test:443/A/'), 'identity: self-redirect detected');
assert_same(Rule::url_identity(HOME . '/a'), Rule::url_identity(HOME . '/x/../a'), 'identity: /a → /x/../a is a self-redirect');
assert_same(Rule::url_identity(HOME . '/a?b=1&c=2'), Rule::url_identity(HOME . '/a?c=2&b=1'), 'identity: canonical query');
assert_true(Rule::url_identity('http://ex.test/a') !== Rule::url_identity('https://ex.test/a'), 'identity: http → https allowed');
assert_same(null, Rule::pick([row(1, '/a', '/x/../a')], '/a', '', '', HOME), 'pick: /a → /x/../a skipped (dot-segment target)');

assert_same('/b', Rule::target_path('/B/', HOME), 'target_path: relative');
assert_same('/b?x=1', Rule::target_path(HOME . '/b?x=1', HOME), 'target_path: same host absolute');
assert_same('/b', Rule::target_path('http://EX.test/b', HOME), 'target_path: same host, other scheme');
assert_same('/b', Rule::target_path('https://ex.test/blog/b', 'https://ex.test/blog'), 'target_path: home path stripped');
assert_same(null, Rule::target_path('https://ex.test/other', 'https://ex.test/blog'), 'target_path: outside home path is external');
assert_same(null, Rule::target_path('https://other.test/b', HOME), 'target_path: external');
assert_same(null, Rule::target_path('https://ex.test:8443/b', HOME), 'target_path: other port is external');
assert_same(null, Rule::target_path('', HOME), 'target_path: 410');

function rule(string $source, string $target, int $status = 301): array
{
    return Rule::validate(input(['source' => $source, 'target' => $target, 'status_code' => (string) $status]))['rule'];
}

assert_true(Rule::loop_conflict(rule('/a', '/a'), 0, [], HOME) !== null, 'loop (a): self target rejected');
assert_true(Rule::loop_conflict(rule('/a', '/A/'), 0, [], HOME) !== null, 'loop (a): self target in another spelling rejected');
assert_true(Rule::loop_conflict(rule('/a', HOME . '/a'), 0, [], HOME) !== null, 'loop (a): absolute self target rejected');
assert_same(null, Rule::loop_conflict(rule('/a', 'http://ex.test/a'), 0, [], HOME), 'loop (a): scheme change is not a loop');
assert_same(null, Rule::loop_conflict(rule('/?p=1', '/?p=2'), 0, [], HOME), 'loop (a): /?p=1 → /?p=2 passes');
assert_same(null, Rule::loop_conflict(rule('/gone', '', 410), 0, [row(2, '/x', '/gone')], HOME), 'loop: 410 rule ignored');
assert_same(null, Rule::loop_conflict(rule('/a', '/b'), 0, [row(2, '/b', '', 410)], HOME), 'loop (b): 410 reverse ignored');
assert_same(null, Rule::loop_conflict(rule('/a', '/b'), 0, [row(2, '/b', 'https://other.test/a')], HOME), 'loop (b): external second target is not a loop');
assert_true(Rule::loop_conflict(rule('/a', '/b'), 0, [row(2, '/b', '/a?x=1')], HOME) !== null, 'loop (b): /a→/b + /b→/a?x=1 rejected');
assert_true(Rule::loop_conflict(rule('/b', '/a?x=1'), 0, [row(1, '/a', '/b')], HOME) !== null, 'loop (b): /b→/a?x=1 + /a→/b rejected');
assert_true(Rule::loop_conflict(rule('/a', '/b'), 0, [row(2, '/b', HOME . '/a')], HOME) !== null, 'loop (b): absolute same-host reverse target rejected');
assert_same(null, Rule::loop_conflict(rule('/a', '/b'), 2, [row(2, '/b', '/a')], HOME), 'loop (b): own id excluded');
assert_same(null, Rule::loop_conflict(rule('/a', 'https://other.test/b'), 0, [row(2, '/b', '/a')], HOME), 'loop: external target ends the chain');
$regex_rule = Rule::validate(input(['match_type' => 'regex', 'source' => '^/a$', 'target' => '/a']))['rule'];
assert_same(null, Rule::loop_conflict($regex_rule, 0, [], HOME), 'loop: regex rules are not checked here');

// ---------------------------------------------------------------- CSV

$h = Rule::csv_header_map(["\xEF\xBB\xBFSource", ' Target ', 'STATUS_CODE', 'hits']);
assert_same([], $h['errors'], 'csv: header with BOM, spaces, case, unknown column');
assert_same(0, $h['map']['source'], 'csv: BOM stripped from first header');
assert_same(1, $h['map']['target'], 'csv: header trimmed and lowercased');
assert_true(!isset($h['map']['hits']), 'csv: unknown column ignored');
assert_true(Rule::csv_header_map(['source', 'target', 'Source'])['errors'] !== [], 'csv: duplicate header rejected');
assert_true(Rule::csv_header_map(['target'])['errors'] !== [], 'csv: missing source rejected');

$map = Rule::csv_header_map(['source', 'target', 'status_code', 'match_type', 'enabled', 'note'])['map'];
$rec = Rule::csv_record(['/a', '/b'], $map);
assert_same(['source' => '/a', 'target' => '/b', 'status_code' => '', 'match_type' => '', 'enabled' => true, 'note' => ''], $rec, 'csv: fewer fields take defaults');
assert_same(['source' => '/a', 'match_type' => 'exact', 'target' => '/b', 'status_code' => 301, 'enabled' => true, 'note' => ''], Rule::validate($rec)['rule'], 'csv: defaults validate to 301/exact/enabled');
foreach (['1' => true, 'yes' => true, 'TRUE' => true, '0' => false, 'no' => false, 'false' => false, '' => true] as $spelling => $expected) {
    assert_same($expected, Rule::csv_record(['/a', '/b', '', '', (string) $spelling], $map)['enabled'], "csv: enabled '{$spelling}'");
}
assert_true(is_string(Rule::csv_record(['/a', '/b', '', '', 'maybe'], $map)), 'csv: bad enabled value is a record error');
assert_true(is_string(Rule::csv_record(['/a', '/b', '301', 'exact', '1', '', 'extra'], $map)), 'csv: more fields than header is a record error');
$only_source = Rule::csv_header_map(['source', 'status_code'])['map'];
assert_same([], Rule::validate(Rule::csv_record(['/gone', '410'], $only_source))['errors'], 'csv: no target column is fine for a 410 row');
assert_true(Rule::validate(Rule::csv_record(['/a', '301'], $only_source))['errors'] !== [], 'csv: no target column fails a 301 row');

// Quoted commas, embedded newline, backslashes: round-trip through fputcsv/fgetcsv
// with the spec's parameters (no escape character).
$cells = ['^/blog/(\d+)\\\\$', '/n,ews/$1', '301', 'regex', '1', "line1\nline2, \"quoted\""];
$fh = fopen('php://memory', 'w+');
fputcsv($fh, array_map([Rule::class, 'csv_escape_cell'], $cells), ',', '"', '');
rewind($fh);
$back = fgetcsv($fh, 0, ',', '"', '');
fclose($fh);
assert_same($cells, array_map([Rule::class, 'csv_unescape_cell'], $back), 'csv: quoted commas, newline, backslashes round-trip');

foreach (['=1+1', '+1', '-1', '@x', "\tx", "\rx", "\nx", "'x", '＝x', '＋x', '－x', '＠x'] as $danger) {
    $escaped = Rule::csv_escape_cell($danger);
    assert_same("'" . $danger, $escaped, 'csv: escape ' . var_export($danger, true));
    assert_same($danger, Rule::csv_unescape_cell($escaped), 'csv: unescape ' . var_export($danger, true));
}
assert_same("''=x", Rule::csv_escape_cell("'=x"), "csv: '=x exports as ''=x");
assert_same("'=x", Rule::csv_unescape_cell("''=x"), "csv: ''=x imports as '=x");
assert_same('/a', Rule::csv_escape_cell('/a'), 'csv: safe cell unchanged');
assert_same('', Rule::csv_escape_cell(''), 'csv: empty cell unchanged');
assert_same("'x", Rule::csv_unescape_cell("'x"), "csv: 'x (not an escape) unchanged on import");
assert_same('=cmd', Rule::csv_record(['/a', '/b', '', '', '', "'=cmd"], $map)['note'], 'csv: record cells are unescaped');

// Capture counting must not break valid PCRE bodies (Gate B pass 1).
foreach (['(*UTF)^/old/(.*)$', '(*UCP)^/old/(.*)$', "(?x)^/old/(.*)$ # trailing comment"] as $body) {
    $v = Rule::validate(['source' => $body, 'match_type' => 'regex', 'target' => '/new/$1', 'status_code' => '301', 'enabled' => true, 'note' => '']);
    assert_same([], $v['errors'], "regex: \$1 accepted with body {$body}");
}
$v = Rule::validate(['source' => '(?x)^/old/(.*)$ # c', 'match_type' => 'regex', 'target' => '/new/$2', 'status_code' => '301', 'enabled' => true, 'note' => '']);
assert_true($v['errors'] !== [], 'regex: $2 still rejected with one group and a (?x) comment');

// Encoded or non-ASCII hostnames are rejected (Gate B pass 2): browsers normalise them.
assert_true(!Rule::target_ok('https://%65x.test/a'), 'target_ok: percent-encoded host rejected');
assert_true(!Rule::target_ok('https://bücher.test/a'), 'target_ok: non-punycode IDN host rejected');
assert_true(Rule::target_ok('https://xn--bcher-kva.test/a'), 'target_ok: punycode host accepted');

// Non-canonical IPv4 forms are rejected (Gate B pass 3); canonical ones pass.
foreach (['http://127.1/a', 'http://0x7f000001/a', 'http://2130706433/a', 'http://127.000.0.1/a', 'http://1.2.3.4.5/a'] as $t) {
    assert_true(!Rule::target_ok($t), "target_ok: non-canonical IPv4 {$t} rejected");
}
assert_true(Rule::target_ok('http://127.0.0.1/a'), 'target_ok: canonical IPv4 accepted');
assert_true(Rule::target_ok('https://shop2.example.com/a'), 'target_ok: hostname with digits accepted');

// ---------------------------------------------------------------- CSV: widened escape (Addendum A1)

foreach ([' =x', "  +1", " \t-1", "\x01@x", ' ＝x'] as $danger) {
    $escaped = Rule::csv_escape_cell($danger);
    assert_same("'" . $danger, $escaped, 'csv: escape after leading blank ' . var_export($danger, true));
    assert_same($danger, Rule::csv_unescape_cell($escaped), 'csv: unescape after leading blank ' . var_export($danger, true));
}
assert_same(' x', Rule::csv_escape_cell(' x'), 'csv: leading blank before a safe character unchanged');
assert_same(' ', Rule::csv_escape_cell(' '), 'csv: blank-only cell unchanged');
assert_same("' x", Rule::csv_unescape_cell("' x"), "csv: ' before a blank and a safe character is not an escape");
$fh = fopen('php://memory', 'w+');
fputcsv($fh, array_map([Rule::class, 'csv_escape_cell'], ['/a', '/b', '301', 'exact', '1', ' =x']), ',', '"', '');
rewind($fh);
$back = fgetcsv($fh, 0, ',', '"', '');
fclose($fh);
assert_same("' =x", $back[5], 'csv: " =x" exported escaped');
assert_same(' =x', Rule::csv_record($back, $map)['note'], 'csv: " =x" round-trips through export and import');

// ---------------------------------------------------------------- Redirection dialect (Addendum A1)

assert_same('ours', Rule::csv_dialect(['source', 'target', 'status_code', 'match_type', 'enabled', 'note']), 'dialect: our header');
assert_same('ours', Rule::csv_dialect(['source', 'target']), 'dialect: minimal header is ours');
assert_same('ours', Rule::csv_dialect(['source', 'regex']), 'dialect: regex without code is ours');
assert_same('redirection', Rule::csv_dialect(Rule::REDIRECTION_COLUMNS), 'dialect: Redirection header');
assert_same('redirection', Rule::csv_dialect(["\xEF\xBB\xBFSource", ' TARGET ', 'Regex', 'CODE']), 'dialect: Redirection header normalised (BOM, spaces, case)');
foreach (['status_code', 'Match_Type'] as $ours_column) {
    $d = Rule::csv_dialect(['source', 'target', 'regex', 'code', $ours_column]);
    assert_true(!in_array($d, ['ours', 'redirection'], true) && $d !== '', "dialect: mixed header with {$ours_column} → error message");
}

$rh = Rule::redirection_header_map(["\xEF\xBB\xBFsource", 'Target', 'regex', 'code', 'type', 'hits', 'title', 'status', 'status_code']);
assert_same([], $rh['errors'], 'redirection header: normalised, unknown column ignored');
assert_same(0, $rh['map']['source'], 'redirection header: BOM stripped');
assert_same(1, $rh['map']['target'], 'redirection header: lowercased');
assert_true(!isset($rh['map']['status_code']), 'redirection header: our column name is not a Redirection column');
assert_true(Rule::redirection_header_map(['target', 'regex', 'code'])['errors'] !== [], 'redirection header: missing source rejected');
assert_true(Rule::redirection_header_map(['source', 'code', 'Code'])['errors'] !== [], 'redirection header: duplicate rejected');

$rmap = Rule::redirection_header_map(Rule::REDIRECTION_COLUMNS)['map'];

/** A Redirection export record (column order of REDIRECTION_COLUMNS) with overrides. */
function red(array $overrides = []): array
{
    $cells = array_merge([
        'source' => '/old',
        'target' => '/new',
        'regex'  => '0',
        'code'   => '301',
        'type'   => 'url',
        'hits'   => '7',
        'title'  => 'Moved',
        'status' => 'active',
    ], $overrides);

    return array_values($cells);
}

function red_record(array $overrides = [], string $home = HOME)
{
    global $rmap;

    return Rule::redirection_record(red($overrides), $rmap, $home);
}

/** Asserts a skip whose reason contains $needle. */
function assert_skip(array $overrides, string $needle, string $message, string $home = HOME): void
{
    $r = red_record($overrides, $home);
    assert_true(is_string($r), "{$message}: skipped");
    assert_true(stripos($r, $needle) !== false, "{$message}: reason names \"{$needle}\" (got: {$r})");
}

const BLOG = 'https://ex.test/blog';
const PORT_BLOG = 'https://ex.test:8443/blog';

// Codes and actions.
foreach (['301', '302', '307', '308'] as $code) {
    $r = red_record(['code' => $code]);
    assert_same(['source' => '/old', 'match_type' => 'exact', 'target' => '/new', 'status_code' => $code, 'enabled' => true, 'note' => 'Moved'], $r, "redirection: url {$code}");
    assert_same([], Rule::validate($r)['errors'], "redirection: url {$code} validates");
}
$r = red_record(['type' => 'error', 'code' => '410', 'target' => '']);
assert_same(['source' => '/old', 'match_type' => 'exact', 'target' => '', 'status_code' => '410', 'enabled' => true, 'note' => 'Moved'], $r, 'redirection: error 410');
assert_same([], Rule::validate($r)['errors'], 'redirection: error 410 validates');
assert_same('', red_record(['type' => 'error', 'code' => '410', 'target' => '/ignored/[userid]'])['target'], 'redirection: 410 target ignored');
foreach ([
    ['random', '301'], ['pass', '301'], ['nothing', '301'], ['url', '303'], ['url', '304'], ['url', '404'],
    ['url', '451'], ['url', '410'], ['error', '404'], ['error', '500'], ['error', '301'], ['url', ''], ['', '301'],
] as [$type, $code]) {
    assert_skip(['type' => $type, 'code' => $code], 'not supported', "redirection: {$type}/{$code}");
}
assert_same('301', red_record(['type' => ' URL ', 'code' => ' 301 '])['status_code'], 'redirection: type and code trimmed, type case-insensitive');

// enabled, note, hits.
assert_same(false, red_record(['status' => 'disabled'])['enabled'], 'redirection: disabled → false');
assert_same(false, red_record(['status' => ' Disabled '])['enabled'], 'redirection: Disabled (case, spaces) → false');
foreach (['active', '', 'whatever'] as $status) {
    assert_same(true, red_record(['status' => $status])['enabled'], "redirection: status '{$status}' → true");
}
assert_same('Moved', red_record(['hits' => '999'])['note'], 'redirection: title → note, hits ignored');
assert_true(is_string(Rule::redirection_record(array_merge(red(), ['extra']), $rmap, HOME)), 'redirection: more fields than header is a record error');
assert_same('/old', Rule::redirection_record(['/old', '/new', '0', '301', 'url'], $rmap, HOME)['source'], 'redirection: fewer fields are fine');

// Exact sources on a sub-directory install (whole-segment strip).
foreach ([BLOG, PORT_BLOG, BLOG . '/'] as $home) {
    assert_same('/old', red_record(['source' => '/blog/old'], $home)['source'], "redirection: /blog/old → /old on {$home}");
    assert_same('/', red_record(['source' => '/blog'], $home)['source'], "redirection: /blog → / on {$home}");
    assert_same('/', red_record(['source' => '/blog/'], $home)['source'], "redirection: /blog/ → / on {$home}");
    assert_same('/?x=1', red_record(['source' => '/blog?x=1'], $home)['source'], "redirection: /blog?x=1 → /?x=1 on {$home}");
    assert_same('/#x', red_record(['source' => '/blog#x'], $home)['source'], "redirection: /blog#x keeps its boundary on {$home}");
    assert_same('/Old', red_record(['source' => '/Blog/Old'], $home)['source'], "redirection: home path strip is case-insensitive on {$home}");
    assert_skip(['source' => '/blogger'], 'home path', "redirection: /blogger outside {$home}", $home);
    assert_skip(['source' => '/other'], 'home path', "redirection: /other outside {$home}", $home);
}
assert_same('/blog/old', red_record(['source' => '/blog/old'])['source'], 'redirection: root install keeps the source');

// Regex sources on a sub-directory install.
$r = red_record(['source' => '^/blog/(.*)$', 'regex' => '1', 'target' => '/blog/new/$1'], BLOG);
assert_same(['source' => '^/(.*)$', 'match_type' => 'regex', 'target' => '/new/$1', 'status_code' => '301', 'enabled' => true, 'note' => 'Moved'], $r, 'redirection: regex source and target home path stripped');
assert_same([], Rule::validate($r)['errors'], 'redirection: stripped regex rule validates');
assert_same('^/old$', red_record(['source' => '^/blog/old$', 'regex' => '1'], PORT_BLOG)['source'], 'redirection: ^/blog/old$ → ^/old$ (port home)');
assert_same('^/x$', red_record(['source' => '^/my\.site/x$', 'regex' => '1'], 'https://ex.test/my.site')['source'], 'redirection: quoted home path accepted');
// An unescaped "." is pattern syntax (any character), so it proves no literal prefix.
assert_true(is_string(red_record(['source' => '^/my.site/x$', 'regex' => '1'], 'https://ex.test/my.site')), 'redirection: unescaped "." home path is not a literal prefix');
foreach (['^/other/(.*)$', '^/blogger$', '^/blog$', '^/(blog)/x$', '^.*/blog/x$'] as $pattern) {
    assert_skip(['source' => $pattern, 'regex' => '1'], 'home path', "redirection: regex {$pattern} outside home path", BLOG);
}
assert_same('^/blog/x$', red_record(['source' => '^/blog/x$', 'regex' => '1'])['source'], 'redirection: root install keeps the pattern');
assert_same('exact', red_record(['regex' => '2'])['match_type'], 'redirection: regex other than 1 → exact');
assert_same('regex', red_record(['source' => '^/a$', 'regex' => ' 1 '])['match_type'], 'redirection: regex 1 trimmed');

// Regex importability scan.
foreach ([
    '^/a/(b|c)$', '^/a/(?:b|c)$', '^/a[|]$', '^/a\|b$', '^/a\\\\$', '^/a/[]|]$', '^/a/[^]|]$',
    '^/[[:alpha:]|]+$', '^/a/(b(c|d))$', '^/a[(|)]$',
] as $pattern) {
    $r = red_record(['source' => $pattern, 'regex' => '1']);
    assert_true(is_array($r), "regex scan accepts {$pattern}" . (is_string($r) ? " (got: {$r})" : ''));
    assert_same($pattern, $r['source'], "regex scan keeps {$pattern}");
}
foreach ([
    '^/a|/b$', '^/a\K$', '^/a(?x)# $', '^/a(*SKIP)$', '^/a(?|x)$', '/a/(.*)', '^/a/(.*)', '/a/(.*)$',
    '^/a\$', '^/a(?=x)$', '^/a(?<!x)$', '^/a(?i)$', '(*UTF)^/a$', '^/a\Q$', '^/a\c$', '^/a[$]', '^/(a)|(b)$',
    '^/a\(|b$', '^/a[\]|]|b$', '',
] as $pattern) {
    assert_skip(['source' => $pattern, 'regex' => '1'], 'importable', 'regex scan rejects ' . var_export($pattern, true));
}

// Replacement syntax (regex rules only).
foreach (['/\1', '/x/${1}', '/$10', '/$0'] as $target) {
    assert_skip(['source' => '^/(a)$', 'regex' => '1', 'target' => $target], 'replacement syntax', "redirection: regex target {$target}");
}
foreach (['/$1', '/$9-$1', '/a$1b'] as $target) {
    assert_same($target, red_record(['source' => '^/(a)$', 'regex' => '1', 'target' => $target])['target'], "redirection: regex target {$target} kept");
}

// Transform tags and legacy tokens.
foreach (['/u/[userid]', '/[upper]x[/upper]', '/[userlogin /]', '/[unixtime]', '/[md5]x[/md5]', '/[lower]X[/lower]', '/[dashes]a_b[/dashes]', '/[underscores]a-b[/underscores]', '/%userid%', '/%userlogin%', '/%userurl%', 'https://other.test/?u=[userid]'] as $target) {
    assert_skip(['target' => $target], 'dynamic target tags', "redirection: target {$target}");
}
assert_same('/[other]', red_record(['target' => '/[other]'])['target'], 'redirection: unknown bracket text is not a tag');

// Targets.
assert_same('https://cdn.test/x', red_record(['target' => '//cdn.test/x'])['target'], 'redirection: protocol-relative target gets the home scheme');
assert_same('http://cdn.test/x', red_record(['target' => '//cdn.test/x'], 'http://ex.test')['target'], 'redirection: protocol-relative target, http home');
assert_same('https://cdn.test/x', red_record(['source' => '/blog/old', 'target' => '//cdn.test/x'], BLOG)['target'], 'redirection: protocol-relative target is not home-path stripped');
assert_same('https://other.test/x', red_record(['source' => '/blog/old', 'target' => 'https://other.test/x'], BLOG)['target'], 'redirection: absolute target as is');
assert_same('/new', red_record(['target' => '/new'])['target'], 'redirection: root install relative target as is');
foreach ([BLOG => 'https://ex.test', PORT_BLOG => 'https://ex.test:8443'] as $home => $origin) {
    assert_same('/new?a=1', red_record(['source' => '/blog/old', 'target' => '/blog/new?a=1'], $home)['target'], "redirection: target inside home path → home-relative on {$home}");
    assert_same('/', red_record(['source' => '/blog/old', 'target' => '/blog'], $home)['target'], "redirection: target = home path → / on {$home}");
    assert_same($origin . '/other?a=1#f', red_record(['source' => '/blog/old', 'target' => '/other?a=1#f'], $home)['target'], "redirection: target outside home path → absolute on {$home}");
    assert_same($origin . '/blogger', red_record(['source' => '/blog/old', 'target' => '/blogger'], $home)['target'], "redirection: /blogger target → absolute on {$home}");
}
assert_same([], Rule::validate(red_record(['source' => '/blog/old', 'target' => '/other'], PORT_BLOG))['errors'], 'redirection: absolute port target validates');

// [FORMULA] unescaping, Redirection's own rule.
assert_same('=1+1', Rule::redirection_unescape('[FORMULA] =1+1'), 'formula: single prefix before = removed');
assert_same(" \t-x", Rule::redirection_unescape("[FORMULA]  \t-x"), 'formula: single prefix before blank + - removed');
assert_same('＝x', Rule::redirection_unescape('[FORMULA] ＝x'), 'formula: single prefix before full-width = removed');
assert_same('@x', Rule::redirection_unescape('[FORMULA] @x'), 'formula: single prefix before @ removed');
assert_same('[FORMULA] x', Rule::redirection_unescape('[FORMULA] [FORMULA] x'), 'formula: doubled prefix loses one');
assert_same('[FORMULA] =x', Rule::redirection_unescape('[FORMULA] [FORMULA] =x'), 'formula: doubled prefix loses exactly one');
assert_same('[FORMULA] hello', Rule::redirection_unescape('[FORMULA] hello'), 'formula: not-dangerous remainder keeps the prefix');
assert_same('[FORMULA]=x', Rule::redirection_unescape('[FORMULA]=x'), 'formula: prefix without its space is not a prefix');
assert_same("'=x", Rule::redirection_unescape("'=x"), "formula: our ' escape is not applied");
assert_same('/a', Rule::redirection_unescape('/a'), 'formula: plain value unchanged');
assert_same('=SUM(A1)', red_record(['title' => '[FORMULA] =SUM(A1)'])['note'], 'formula: record cells are unescaped');
assert_same("'=x", red_record(['title' => "'=x"])['note'], "formula: record keeps a leading '");

// A real Redirection export, parsed as the import handler reads it.
$export = "source,target,regex,code,type,hits,title,status\n"
    . "\"/old-page\",\"/new-page\",0,301,\"url\",12,\"Moved \"\"page\"\"\",\"active\"\n"
    . "\"^/blog/(\\d+)\\.html$\",\"/news/$1\",1,302,\"url\",0,\"\",\"active\"\n"
    . "\"/gone\",\"\",0,410,\"error\",3,\"[FORMULA] =cmd\",\"disabled\"\n"
    . "\"/random\",\"/x\",0,301,\"random\",0,\"\",\"active\"\n";
$fh = fopen('php://memory', 'w+');
fwrite($fh, $export);
rewind($fh);
$header = fgetcsv($fh, 0, ',', '"', '');
assert_same('redirection', Rule::csv_dialect($header), 'export: detected as Redirection');
$emap = Rule::redirection_header_map($header);
assert_same([], $emap['errors'], 'export: header maps');
$got = [];
while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $got[] = Rule::redirection_record($line, $emap['map'], HOME);
}
fclose($fh);
assert_same(['source' => '/old-page', 'match_type' => 'exact', 'target' => '/new-page', 'status_code' => '301', 'enabled' => true, 'note' => 'Moved "page"'], $got[0], 'export: exact 301 line');
assert_same(['source' => '^/blog/(\d+)\.html$', 'match_type' => 'regex', 'target' => '/news/$1', 'status_code' => '302', 'enabled' => true, 'note' => ''], $got[1], 'export: regex line keeps its backslashes');
assert_same([], Rule::validate($got[1])['errors'], 'export: regex line validates');
assert_same(['source' => '/gone', 'match_type' => 'exact', 'target' => '', 'status_code' => '410', 'enabled' => false, 'note' => '=cmd'], $got[2], 'export: disabled 410 line with a formula title');
assert_true(is_string($got[3]) && str_contains($got[3], 'not supported'), 'export: random action skipped');
assert_same(4, count($got), 'export: four records read');

// Gate B (addendum) pass 1: a home path with regex syntax can never be stripped
// as a literal prefix; a dotted one only in its escaped form.
$rmap = Rule::redirection_header_map(['source', 'target', 'regex', 'code', 'type', 'hits', 'title', 'status'])['map'];
$rec  = static fn(string $src, string $home): array|string => Rule::redirection_record([$src, '/new/$1', '1', '301', 'url', '0', '', 'active'], $rmap, $home);
assert_true(is_string($rec('^/foo(bar)/(x)$', 'https://ex.test/foo(bar)')), 'redirection: home path with "(" is not stripped as a literal');
assert_true(is_string($rec('^/my.site/(x)$', 'https://ex.test/my.site')), 'redirection: unescaped "." in the pattern is not a literal home prefix');
$ok = $rec('^/my\\.site/(x)$', 'https://ex.test/my.site');
assert_true(is_array($ok) && $ok['source'] === '^/(x)$', 'redirection: escaped "\\." home prefix is stripped');

// Gate B (addendum) pass 3: a destination keeps its case; a differently-cased
// home prefix is not this site's home path and the target stays absolute.
$t = Rule::redirection_record(['/blog/old', '/BLOG/New', '0', '301', 'url', '0', '', 'active'], $rmap, 'https://ex.test/blog');
assert_true(is_array($t) && $t['target'] === 'https://ex.test/BLOG/New', 'redirection: /BLOG/New on home /blog stays absolute with its case');
$t = Rule::redirection_record(['/blog/old', '/blog/New', '0', '301', 'url', '0', '', 'active'], $rmap, 'https://ex.test/blog');
assert_true(is_array($t) && $t['target'] === '/New', 'redirection: /blog/New becomes home-relative /New');

echo "OK\n";
