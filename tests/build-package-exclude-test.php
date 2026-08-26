<?php

/**
 * Pin the production zip exclude list so AI/dev folders cannot silently return.
 *
 * Run: php tests/build-package-exclude-test.php
 */

declare(strict_types=1);

$script = dirname(__DIR__) . '/build-theme.sh';
assert_true(is_file($script), 'build-theme.sh must exist');

$contents = file_get_contents($script);
assert_true($contents !== false && $contents !== '', 'build-theme.sh must be readable');

$required = [
    '/.cursor/',
    '/.claude/',
    '/.conductor/',
    '/.remember/',
    '/.superpowers/',
    '/.cloud/',
    '/docs/',
    '/tests/',
    '/test-github-updater.php',
    '/env-example.txt',
    '/inc/CustomDashboard/docs/',
    '/inc/ImageOptimizer/FIX-NOTES.md',
    '/release',
    '/todos.md',
    'FORBIDDEN_PATHS=(',
];

foreach ($required as $needle) {
    assert_true(
        str_contains($contents, $needle),
        "build-theme.sh must exclude or guard {$needle}"
    );
}

echo "All build-package exclude tests passed.\n";
exit(0);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
