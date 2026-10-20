<?php

/**
 * Two documentation statements about runtime behaviour that went stale once already
 * (Gate B cycle ioopr1xcoz, pass 1, findings 1 and 2):
 *
 * - README's production note named only `.env.local` after `SFX\Environment::is_dev_mode()`
 *   gained the wp-config constant as a second source, so following it left dev mode on.
 * - The Cursor rule still said `functions.php` loads a Composer autoloader after the theme
 *   dropped runtime Composer (AGENTS.md invariant 5), steering an agent back to `vendor/`.
 *
 * Run: php tests/docs-runtime-claims-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// 1. Every dev-mode source the code reads is named where README says how to switch it off.
$environment = file_get_contents($root . '/inc/Environment.php');
assert_true(is_string($environment) && $environment !== '', 'inc/Environment.php must be readable');
// Pin the sources to the code, so a source removed there fails here instead of leaving this check vacuous.
assert_true(
    str_contains($environment, "defined('SFX_THEME_DEV_MODE')") && str_contains($environment, "'/.env.local'"),
    'Environment.php no longer reads both the SFX_THEME_DEV_MODE constant and .env.local — update this test and README'
);

$readme = file_get_contents($root . '/README.md');
assert_true(is_string($readme) && $readme !== '', 'README.md must be readable');
assert_true(
    preg_match('/^For production,(.+?)\n\n/ms', $readme, $m) === 1,
    'README.md must carry a "For production, ..." paragraph under Development mode'
);
assert_true(
    str_contains($m[1], 'constant') && str_contains($m[1], '.env.local'),
    'README\'s production note must tell the reader to switch off both the constant and .env.local'
);

// 2. No Cursor rule claims a Composer autoloader or vendor/autoload.php at runtime.
$rules = glob($root . '/.cursor/rules/*.mdc');
assert_true(is_array($rules) && $rules !== [], 'no .cursor/rules/*.mdc found');
foreach ($rules as $rule) {
    $text = file_get_contents($rule);
    assert_true(is_string($text), basename($rule) . ' must be readable');
    assert_true(
        preg_match('/composer autoload|vendor\/autoload/i', $text) !== 1,
        basename($rule) . ' mentions a Composer autoloader or vendor/autoload.php; the theme loads its classes only from inc/ (AGENTS.md invariant 5)'
    );
}

echo "All docs runtime-claim tests passed.\n";
exit(0);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
