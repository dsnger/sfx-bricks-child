<?php

declare(strict_types=1);

// functions.php loads the theme's classes with its own autoloader only (no vendor/, no
// Composer notice), and dev mode comes from .env.local or the SFX_THEME_DEV_MODE constant.
// Each scenario runs in a fresh PHP process: the autoloader, the constant and the
// Environment state are process-global.

if (($argv[1] ?? '') === 'child') {
    $dir = $argv[2];
    define('ABSPATH', $dir . '/');
    if (($argv[3] ?? '') !== 'none') {
        define('SFX_THEME_DEV_MODE', $argv[3] === 'true' ? true : $argv[3]);
    }
    $GLOBALS['sfx_theme_dir'] = $dir;
    require __DIR__ . '/support/functions-php-stubs.php';
    require $dir . '/functions.php';
    echo json_encode([
        'dev' => \SFX\Environment::is_dev_mode(),
        'admin_class' => class_exists('SFX\\SFXBricksChildAdmin') ? (new ReflectionClass('SFX\\SFXBricksChildAdmin'))->getFileName() : null,
        'vendor_loaded' => defined('SFX_TEST_VENDOR_LOADED'),
        'composer' => class_exists('Composer\\Autoload\\ClassLoader', false),
        'notices' => in_array('admin_notices', $GLOBALS['sfx_actions'], true),
    ]);
    exit(0);
}

$failures = 0;
function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL: {$message}\n";
    }
}

$root = dirname(__DIR__);
$tmp = realpath(sys_get_temp_dir()) . '/sfx-bootstrap-' . bin2hex(random_bytes(6));
register_shutdown_function(static function () use ($tmp): void {
    if (is_dir($tmp)) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

// A copy of what ships: functions.php and inc/. A vendor/autoload.php that marks itself
// is added to one copy, to prove the theme never loads it.
function make_copy(string $root, string $dir, ?string $env, bool $vendor): void
{
    mkdir($dir, 0700, true);
    copy($root . '/functions.php', $dir . '/functions.php');
    exec('cp -R ' . escapeshellarg($root . '/inc') . ' ' . escapeshellarg($dir . '/inc'));
    if ($env !== null) {
        file_put_contents($dir . '/.env.local', $env);
    }
    if ($vendor) {
        mkdir($dir . '/vendor', 0700);
        file_put_contents($dir . '/vendor/autoload.php', "<?php define('SFX_TEST_VENDOR_LOADED', true);\n");
    }
}

function run_child(string $dir, string $constant): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($dir) . ' ' . escapeshellarg($constant) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    $data = json_decode($out, true);
    if (!is_array($data)) {
        echo "child output: " . implode("\n", array_slice(explode("\n", $out), 0, 2)) . "\n";
        return [];
    }
    return $data;
}

make_copy($root, "$tmp/plain", null, false);
make_copy($root, "$tmp/vendor", null, true);
make_copy($root, "$tmp/env", "SFX_THEME_DEV_MODE=true\n", false);

$r = run_child("$tmp/plain", 'none');
check(($r['dev'] ?? null) === false, 'neither .env.local nor the constant: dev mode off');
check(($r['admin_class'] ?? null) === "$tmp/plain/inc/SFXBricksChildAdmin.php", 'the own autoloader resolves SFX\\SFXBricksChildAdmin from inc/ without vendor/');

// Dev mode on keeps the old updater from registering its own notices, so any admin_notices
// hook left here is the bootstrap's.
$r = run_child("$tmp/plain", 'true');
check(($r['notices'] ?? null) === false, 'no admin notice about a missing Composer autoloader');

$r = run_child("$tmp/vendor", 'true');
check(($r['vendor_loaded'] ?? null) === false, 'a present vendor/autoload.php is never loaded');
check(($r['composer'] ?? null) === false, 'no Composer class loader is defined');
check(($r['admin_class'] ?? null) === "$tmp/vendor/inc/SFXBricksChildAdmin.php", 'classes still come from inc/ when vendor/ exists');

$r = run_child("$tmp/plain", 'true');
check(($r['dev'] ?? null) === true, 'constant true without .env.local: dev mode on');

$r = run_child("$tmp/plain", 'false');
check(($r['dev'] ?? null) === false, 'a constant that is not true leaves dev mode off');

$r = run_child("$tmp/env", 'none');
check(($r['dev'] ?? null) === true, '.env.local without the constant: dev mode on (unchanged)');

if ($failures > 0) {
    echo "theme-bootstrap: {$failures} failure(s)\n";
    exit(1);
}
echo "theme-bootstrap: PASS\n";
