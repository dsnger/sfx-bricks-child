<?php

/**
 * The production zip must never ship without the Composer autoloader —
 * functions.php fatals on activation without vendor/autoload.php. A build
 * from a clean git export (vendor/ is not tracked) produced exactly that
 * package on 2026-08-26. Pin the guard behaviourally: build-theme.sh must
 * refuse to build when the Composer autoloader is missing OR does not load,
 * and build when it is real.
 *
 * PRESENT IS NOT ENOUGH. On 2026-08-28 a test harness wrote a one-line `<?php`
 * placeholder into the real checkout's vendor/autoload.php. It satisfied every
 * file-exists check in build-theme.sh and release.sh, and the package built
 * from it loads no class at all — the exact failure invariant 5 exists to stop,
 * reached by a different route. This fixture used to be that placeholder, which
 * is why the hole survived: the test asserted the stub was ACCEPTED.
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

// A file that exists but is not a Composer autoloader must be refused too.
mkdir($fixture . '/vendor', 0755);
file_put_contents($fixture . '/vendor/autoload.php', "<?php\n");
[$exit, $output] = run_build($fixture);
assert_true($exit !== 0, 'build must fail on a placeholder autoloader, got exit 0');
assert_true(
    str_contains($output, "does not resolve this theme's classes"),
    "failure message must say the autoloader does not load, got: {$output}"
);

// The real thing — the WHOLE vendor/ from this checkout. Copying only
// autoload.php and ClassLoader.php produced a tree that looks right and fatals
// on require, because Composer's entry point pulls in composer/autoload_real.php
// and its siblings. Copying two files was the same class of mistake as checking
// that one file exists.
$real = dirname(__DIR__) . '/vendor';
assert_true(
    is_file($real . '/autoload.php') && is_dir($real . '/composer'),
    'this checkout needs a real vendor/ for this test; run composer install'
);
copy_tree($real, $fixture . '/vendor');
// The guard resolves classes through the loader, so the fixture needs the files
// they live in — checking the PSR-4 prefix alone let a map pointing at a missing
// directory through. Two of them, matching what the guard requires.
@mkdir($fixture . '/inc', 0755, true);
foreach (['SFXBricksChildTheme', 'SFXBricksChildAdmin'] as $class) {
    $src = dirname(__DIR__) . "/inc/{$class}.php";
    assert_true(is_file($src), "the guard resolves {$class}; its source must exist");
    copy($src, $fixture . "/inc/{$class}.php");
}
assert_true(
    is_file($fixture . '/vendor/composer/autoload_real.php'),
    'the copied vendor/ must include Composer generated files'
);
// A foreign but perfectly valid Composer tree must be refused too: it loads,
// it is a real ClassLoader, and it maps nothing this theme needs. Both the
// generated map AND the static one have to be renamed — with
// --optimize-autoloader the loader reads autoload_static.php, so patching only
// autoload_psr4.php leaves the real prefix in place.
$maps = [
    $fixture . '/vendor/composer/autoload_psr4.php',
    $fixture . '/vendor/composer/autoload_static.php',
];
$saved = [];
foreach ($maps as $map) {
    if (!is_file($map)) {
        continue;
    }
    $saved[$map] = file_get_contents($map);
    file_put_contents($map, str_replace('SFX', 'Other', $saved[$map]));
}
assert_true($saved !== [], 'the copied vendor/ must contain a PSR-4 map to rename');
[$exit, $output] = run_build($fixture);
assert_true($exit !== 0, 'build must fail on a Composer tree that does not map this theme');
foreach ($saved as $map => $content) {
    file_put_contents($map, $content);
}

[$exit, $output] = run_build($fixture);
assert_true($exit === 0, "build must succeed with a real autoloader, got exit {$exit}: {$output}");
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

// release.sh must LOAD the autoloader, not merely see the file: the placeholder
// above passes any is-file check. This is a text check and knows it — running
// release.sh needs a git remote and a gh stub. The behavioural coverage is the
// `stubvendor` scenario in tests/support/release-push-harness.sh, which drives
// release.sh end to end with exactly that placeholder in place.
assert_true(
    str_contains($preflight, 'class_exists("SFX\\\\SFXBricksChildTheme")'),
    'release.sh preflight must require() the autoloader and load a theme class'
);
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

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0755, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $to . DIRECTORY_SEPARATOR . $items->getSubPathName();
        $item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
    }
}
