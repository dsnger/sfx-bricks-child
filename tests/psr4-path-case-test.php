<?php

/**
 * Assert git-indexed paths under inc/ match PSR-4 class names byte-for-byte.
 *
 * macOS APFS is case-insensitive, so file_exists('inc/WPOptimizer/AdminPage.php')
 * succeeds even when git stores inc/wpoptimizer/AdminPage.php. Linux and GitHub
 * zipballs use the indexed case, so a mismatch fatals on autoload.
 *
 * Run: php tests/psr4-path-case-test.php
 */

declare(strict_types=1);

$repo_root = dirname(__DIR__);

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function git_indexed_inc_files(string $repo_root): array
{
    $output = [];
    $exit_code = 0;
    exec(
        'git -C ' . escapeshellarg($repo_root) . ' ls-files -z -- inc/',
        $output,
        $exit_code
    );
    assert_true($exit_code === 0, 'git ls-files inc/ should succeed');

    $joined = implode("\n", $output);
    $paths = array_values(array_filter(explode("\0", $joined), static fn($path) => $path !== ''));
    assert_true($paths !== [], 'git ls-files should list files under inc/');

    return $paths;
}

function expected_psr4_path(string $source): ?string
{
    if (!preg_match('/^namespace\s+(SFX(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*);/m', $source, $namespace_match)) {
        return null;
    }
    if (!preg_match('/^(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $class_match)) {
        return null;
    }

    $relative_namespace = substr($namespace_match[1], strlen('SFX'));
    $relative_dir = str_replace('\\', '/', $relative_namespace);

    return 'inc' . $relative_dir . '/' . $class_match[1] . '.php';
}

$indexed = git_indexed_inc_files($repo_root);
$php_files = array_values(array_filter(
    $indexed,
    static fn($path) => str_ends_with($path, '.php')
));
assert_true($php_files !== [], 'inc/ should contain tracked PHP files');

$mismatches = [];
$checked = 0;

foreach ($php_files as $path) {
    $absolute = $repo_root . '/' . $path;
    assert_true(is_readable($absolute), "Tracked file should be readable: {$path}");

    $source = file_get_contents($absolute);
    assert_true($source !== false, "Tracked file should be readable: {$path}");

    $expected = expected_psr4_path($source);
    if ($expected === null) {
        continue;
    }

    $checked++;
    if ($path !== $expected) {
        $mismatches[] = "{$path} (namespace/class requires {$expected})";
    }
}

assert_true(
    $checked > 0,
    'Should find at least one SFX namespaced class under inc/'
);
assert_true(
    $mismatches === [],
    "Git-indexed paths must match PSR-4 class paths on case-sensitive filesystems:\n- " . implode("\n- ", $mismatches)
);

$admin_page = 'inc/WPOptimizer/AdminPage.php';
assert_true(
    in_array($admin_page, $indexed, true),
    "SFX\\WPOptimizer\\AdminPage must be git-indexed as {$admin_page} so Linux autoload can find it"
);

echo "OK ({$checked} classes)\n";
