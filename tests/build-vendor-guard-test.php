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
echo "All build-vendor-guard tests passed.\n";
exit(0);

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
