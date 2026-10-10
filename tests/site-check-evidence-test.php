<?php

declare(strict_types=1);

/**
 * SiteCheck evidence rules, the file-exposure table, finding identity and the
 * run context. Spec: docs/superpowers/specs/2026-10-10-site-check-design.md,
 * "Results" → Evidence rules, File exposure, Finding identity.
 *
 * Pure classes: no WordPress function is needed, so there are no stubs.
 */

require_once __DIR__ . '/../inc/SiteCheck/Status.php';
require_once __DIR__ . '/../inc/SiteCheck/Evidence.php';
require_once __DIR__ . '/../inc/SiteCheck/Finding.php';
require_once __DIR__ . '/../inc/SiteCheck/RunContext.php';

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Finding;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function response(int $status, string $body = 'some page', bool $truncated = false, bool $redirect = false, bool $challenge = false): array
{
    return ['status' => $status, 'body' => $body, 'truncated' => $truncated, 'redirect' => $redirect, 'challenge' => $challenge];
}

const SIGNATURES = ['php_log' => '/PHP (?:Fatal error|Warning|Notice):/'];
const LOG_LINE   = "[10-Oct-2026 10:00:00 UTC] PHP Warning: Undefined variable \$x in /x.php on line 3\n";

$cmp404 = response(404, 'Not found');
$cmp410 = response(410, 'Gone');
$cmp200 = response(200, 'Home page');

// ---------------------------------------------------------------- file exposure table, every row

$table = [
    // disk, outside, expected
    ['present', 'signature', Status::RED],
    ['absent', 'signature', Status::RED],
    ['unknown', 'signature', Status::RED],
    ['present', 'unreachable', Status::YELLOW],
    ['absent', 'unreachable', Status::GREEN],
    ['unknown', 'unreachable', Status::UNKNOWN],
    ['present', 'indeterminate', Status::UNKNOWN],
    ['absent', 'indeterminate', Status::UNKNOWN],
    ['unknown', 'indeterminate', Status::UNKNOWN],
    ['present', 'ok200', Status::YELLOW],
    ['absent', 'ok200', Status::UNKNOWN],
    ['unknown', 'ok200', Status::UNKNOWN],
];
foreach ($table as [$disk, $outside, $expected]) {
    assert_same($expected, Evidence::file_exposure($disk, $outside), "file_exposure({$disk}, {$outside})");
}

// ---------------------------------------------------------------- classify: rule 3

assert_same('unreachable', Evidence::classify(response(404, 'Not found'), $cmp404, SIGNATURES), '404 like the 404 comparison');
assert_same('unreachable', Evidence::classify(response(410, 'Gone'), $cmp410, SIGNATURES), '410 like the 410 comparison');
assert_same('unreachable', Evidence::classify(response(403, 'Forbidden'), $cmp200, SIGNATURES), '403 is not reachable');
assert_same('unreachable', Evidence::classify(response(404, 'Not found'), $cmp200, SIGNATURES), '404 is not reachable even on a soft-404 site');
assert_same('unreachable', Evidence::classify(response(301, '', false, true), $cmp404, SIGNATURES), 'a redirect is not reachable');
assert_same('unreachable', Evidence::classify(response(0, '', false, true), $cmp404, SIGNATURES), 'a browser opaque redirect (status 0, no body) is not reachable');

foreach ([301, 302, 307, 308] as $code) {
    assert_same('unreachable', Evidence::classify(response($code, ''), $cmp404, SIGNATURES), "{$code} without the redirect flag is a redirect");
}

// ---------------------------------------------------------------- classify: challenge pages (rule 4)

assert_same('indeterminate', Evidence::classify(response(403, 'Just a moment...', false, false, true), $cmp404, SIGNATURES), '403 challenge page');
$challenge404 = Evidence::classify(response(404, 'Checking your browser', false, false, true), $cmp404, SIGNATURES);
assert_same('indeterminate', $challenge404, '404 challenge page');
assert_same(Status::UNKNOWN, Evidence::file_exposure('absent', $challenge404), '404 challenge + disk absent → Nicht prüfbar, never Grün');
assert_same('signature', Evidence::classify(response(403, LOG_LINE, false, false, true), $cmp404, SIGNATURES), 'signature beats challenge');
assert_same('unreachable', Evidence::classify(['status' => 404, 'body' => 'Not found', 'truncated' => false, 'redirect' => false], $cmp404, SIGNATURES), 'challenge defaults to false when absent');

// ---------------------------------------------------------------- classify: rule 4 (wins over rule 3)

assert_same('indeterminate', Evidence::classify(response(200, 'Home page'), $cmp200, SIGNATURES), '200 with comparison 200 = soft-404 site');
assert_same('indeterminate', Evidence::classify(response(200, ''), $cmp404, SIGNATURES), 'empty 200 body');
assert_same('indeterminate', Evidence::classify(response(404, ''), $cmp404, SIGNATURES), 'empty 404 body: rule 4 wins over rule 3');
assert_same('indeterminate', Evidence::classify(response(401, 'Auth'), $cmp404, SIGNATURES), '401');
assert_same('indeterminate', Evidence::classify(response(429, 'Slow down'), $cmp404, SIGNATURES), '429');
assert_same('indeterminate', Evidence::classify(response(503, 'Busy'), $cmp404, SIGNATURES), '503');
assert_same('indeterminate', Evidence::classify(response(500, 'Error'), $cmp404, SIGNATURES), '500');
assert_same('indeterminate', Evidence::classify(response(200, 'aaaa', true), $cmp404, SIGNATURES), 'truncated 200 without signature');
assert_same('indeterminate', Evidence::classify(response(404, 'aaaa', true), $cmp404, SIGNATURES), 'truncated 404 without signature: rule 4 wins');
assert_same('indeterminate', Evidence::classify(response(0, ''), $cmp404, SIGNATURES), 'timeout / no answer');
assert_same('indeterminate', Evidence::classify(response(200, 'Home page'), response(0, ''), SIGNATURES), '200 while the comparison gave no answer: soft-404 not excluded');
assert_same('indeterminate', Evidence::classify(response(204, 'x'), $cmp404, SIGNATURES), 'an unexpected status is not evidence');

// ---------------------------------------------------------------- classify: ok200

assert_same('ok200', Evidence::classify(response(200, 'something'), $cmp404, SIGNATURES), '200 without signature, comparison 404');
assert_same('ok200', Evidence::classify(response(200, 'something'), response(0, '', false, true), SIGNATURES), '200 without signature, comparison redirected');

// ---------------------------------------------------------------- classify: a signature beats everything

assert_same('signature', Evidence::classify(response(200, LOG_LINE), $cmp404, SIGNATURES), 'signature in a 200');
assert_same('signature', Evidence::classify(response(200, LOG_LINE), $cmp200, SIGNATURES), 'signature beats the soft-404 rule');
assert_same('signature', Evidence::classify(response(200, LOG_LINE, true), $cmp404, SIGNATURES), 'signature beats truncation');
assert_same('signature', Evidence::classify(response(503, LOG_LINE), $cmp404, SIGNATURES), 'signature beats 5xx');
assert_same('signature', Evidence::classify(response(404, LOG_LINE), $cmp404, SIGNATURES), 'signature beats 404');
assert_same('php_log', Evidence::signature(LOG_LINE, SIGNATURES), 'the matched signature name is reported');
assert_same(null, Evidence::signature('nothing here', SIGNATURES), 'no signature → null');

// ---------------------------------------------------------------- finding identity

assert_same('logs_public:/wp-content/debug.log', Finding::id('logs_public', '/wp-content/debug.log?sfxcb=123'), 'cache-buster removed');
assert_same('logs_public:/wp-content/debug.log', Finding::id('logs_public', '/wp-content/debug.log'), 'no query stays as is');
assert_same('vcs_env:/x?a=1', Finding::id('vcs_env', '/x?a=1&sfxcb=9'), 'only the cache-buster is removed');
assert_same('vcs_env:/x?a=1', Finding::id('vcs_env', '/x?sfxcb=9&a=1'), 'cache-buster removed in front position');
assert_same('admin_accounts:user-5:manage_options', Finding::id('admin_accounts', 'user-5:manage_options'), 'account target unchanged');
assert_same('php_in_uploads:probe', Finding::id('php_in_uploads', 'probe'), 'probe target');

// ---------------------------------------------------------------- run context

$ctx = new RunContext('run-1', 'live', ['/impressum/'], ['/sitemap.xml'], 'twentytwentyfive', true);
assert_same('run-1', $ctx->run(), 'run');
assert_same('live', $ctx->profile(), 'profile');
assert_same(['/impressum/'], $ctx->indexability_paths(), 'indexability paths');
assert_same(['/sitemap.xml'], $ctx->sitemap_allow(), 'sitemap allow list');
assert_same('twentytwentyfive', $ctx->fallback_theme(), 'fallback theme');
assert_same(true, $ctx->probe(), 'probe consent');
$reflection = new ReflectionClass(RunContext::class);
assert_same([], $reflection->getProperties(ReflectionProperty::IS_PUBLIC), 'RunContext exposes no public property');
foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getName() !== '__construct') {
        assert_same(0, $method->getNumberOfParameters(), "RunContext::{$method->getName()} takes no argument (no setter)");
    }
}

// ---------------------------------------------------------------- autoload in a fresh process

$autoload = __DIR__ . '/../vendor/autoload.php';
$code = 'require ' . var_export($autoload, true) . ';'
    . 'echo (class_exists("SFX\\\\SiteCheck\\\\Status") && class_exists("SFX\\\\SiteCheck\\\\RunContext") && class_exists("SFX\\\\SiteCheck\\\\Finding")) ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";';
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
assert_same('AUTOLOAD_OK', is_string($out) ? trim($out) : $out, 'Status, RunContext, Finding resolve via the Composer autoloader in a fresh process');

echo "site-check-evidence: PASS\n";
