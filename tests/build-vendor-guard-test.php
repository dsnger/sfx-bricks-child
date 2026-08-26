<?php

/**
 * The production zip must never ship without the Composer autoloader —
 * functions.php fatals on activation without vendor/autoload.php. A build
 * from a clean git export (vendor/ is not tracked) produced exactly that
 * package on 2026-08-26. Pin the guard behaviourally: build-theme.sh must
 * refuse to build when vendor/autoload.php is missing, and still build
 * when it is present.
 *
 * Run: php tests/build-vendor-guard-test.php
 */

declare(strict_types=1);

$script = dirname(__DIR__) . '/build-theme.sh';
assert_true(is_file($script), 'build-theme.sh must exist');

$fixture = sys_get_temp_dir() . '/sfx-vendor-guard-' . bin2hex(random_bytes(4));
assert_true(mkdir($fixture, 0755, true), 'fixture dir must be creatable');

copy($script, $fixture . '/build-theme.sh');
file_put_contents($fixture . '/style.css', "/*\n Theme Name: Fixture\n Version:      9.9.9\n*/\n");

// Without vendor/autoload.php the build must refuse.
[$exit, $output] = run_build($fixture);
assert_true($exit !== 0, 'build must fail when vendor/autoload.php is missing, got exit 0');
assert_true(
    str_contains($output, 'vendor/autoload.php'),
    "failure message must name vendor/autoload.php, got: {$output}"
);

// With vendor/autoload.php present the build must succeed again.
mkdir($fixture . '/vendor', 0755);
file_put_contents($fixture . '/vendor/autoload.php', "<?php // fixture\n");
[$exit, $output] = run_build($fixture);
assert_true($exit === 0, "build must succeed with vendor/autoload.php present, got exit {$exit}: {$output}");
assert_true(is_file($fixture . '/sfx-bricks-child-v9.9.9.zip'), 'zip must be produced');

cleanup($fixture);

// The same prerequisite must also run in release.sh's preflight, BEFORE the
// release commit and tag push: build_theme runs after both, so a late guard
// failure would leave a half-rolled-back release (bump commit kept as HEAD,
// tag deleted locally and on the remote). Pin the check into the existing
// pre-mutation section, check_git_status().
$release = file_get_contents(dirname(__DIR__) . '/release.sh');
assert_true($release !== false && $release !== '', 'release.sh must be readable');
$preflight = extract_function($release, 'check_git_status() {');
assert_true($preflight !== null, 'check_git_status() must exist in release.sh');
assert_true(
    str_contains($preflight, 'vendor/autoload.php'),
    'release.sh preflight (check_git_status) must verify vendor/autoload.php before any release mutation'
);

echo "All build-vendor-guard tests passed.\n";
exit(0);

function extract_function(string $haystack, string $opener): ?string
{
    $start = strpos($haystack, $opener);
    if ($start === false) {
        return null;
    }
    $end = strpos($haystack, "\n}", $start);
    return $end === false ? null : substr($haystack, $start, $end - $start);
}

/** @return array{0:int,1:string} */
function run_build(string $dir): array
{
    $output = [];
    $exit = 1;
    exec('cd ' . escapeshellarg($dir) . ' && bash build-theme.sh 2>&1', $output, $exit);
    return [$exit, implode("\n", $output)];
}

function cleanup(string $dir): void
{
    exec('rm -rf ' . escapeshellarg($dir));
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
