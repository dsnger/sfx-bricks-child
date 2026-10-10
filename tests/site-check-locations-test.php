<?php

declare(strict_types=1);

/**
 * SiteCheck locations: where the roots are on disk and on the web, which URL a
 * file maps to, and which URLs may be fetched at all. Spec:
 * docs/superpowers/specs/2026-10-10-site-check-design.md, "Locations".
 *
 * Runs against a temporary directory tree. The WordPress functions are stubs
 * driven by $GLOBALS['stub']; wp_upload_dir() creates its folder when asked
 * to, exactly like WordPress, so a creating call would show up in the
 * filesystem snapshot below.
 */

$tmp = sys_get_temp_dir() . '/sfx-site-check-locations-' . bin2hex(random_bytes(6));

function remove_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($dir . '/' . $entry);
        }
    }
    @chmod($dir, 0700);
    rmdir($dir);
}

// One teardown, declared before the first fixture is created.
register_shutdown_function(static function () use ($tmp): void {
    remove_tree($tmp);
});

if (!mkdir($tmp, 0700) ) {
    fwrite(STDERR, "error: cannot create {$tmp}\n");
    exit(2);
}
$tmp = realpath($tmp);

// public/            web root of a subdirectory install
//   index.php        requires wp/wp-blog-header.php
//   wp/              ABSPATH
//     wp-content/plugins/
// other/wp/          another installation
// elsewhere/uploads/ an uploads folder outside every root
foreach (['/public/wp/wp-content/plugins', '/other/wp', '/elsewhere/uploads/2026'] as $dir) {
    mkdir($tmp . $dir, 0700, true);
}
file_put_contents($tmp . '/public/wp/wp-blog-header.php', "<?php\n");
file_put_contents($tmp . '/public/wp/wp-config.php', "<?php\n");
file_put_contents($tmp . '/other/wp/wp-blog-header.php', "<?php\n");
$index_ok = "<?php\ndefine( 'WP_USE_THEMES', true );\nrequire __DIR__ . '/wp/wp-blog-header.php';\n";
file_put_contents($tmp . '/public/index.php', $index_ok);

define('ABSPATH', $tmp . '/public/wp/');
define('WP_CONTENT_DIR', $tmp . '/public/wp/wp-content');
define('WP_PLUGIN_DIR', $tmp . '/public/wp/wp-content/plugins');

// ---------------------------------------------------------------- stubs

$GLOBALS['stub'] = [];

function stub_reset(array $overrides = []): void
{
    global $tmp;
    $GLOBALS['stub'] = array_merge([
        'home'      => 'https://ex.test',
        'siteurl'   => 'https://ex.test',
        'home_path' => ABSPATH,
        'content'   => 'https://ex.test/wp-content',
        'plugins'   => 'https://ex.test/wp-content/plugins',
        'basedir'   => $tmp . '/public/wp/wp-content/uploads',
        'baseurl'   => 'https://ex.test/wp-content/uploads',
    ], $overrides);
}

function __($text, $domain = null)
{
    return $text;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_normalize_path($path)
{
    $path = str_replace('\\', '/', $path);
    return preg_replace('|(?<=.)/+|', '/', $path);
}

function trailingslashit($value)
{
    return untrailingslashit($value) . '/';
}

function untrailingslashit($value)
{
    return rtrim($value, '/\\');
}

function join_url(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function home_url($path = '')
{
    return join_url($GLOBALS['stub']['home'], $path);
}

function site_url($path = '')
{
    return join_url($GLOBALS['stub']['siteurl'], $path);
}

function content_url($path = '')
{
    return $path === '' ? $GLOBALS['stub']['content'] : join_url($GLOBALS['stub']['content'], $path);
}

function plugins_url($path = '', $plugin = '')
{
    return $path === '' ? $GLOBALS['stub']['plugins'] : join_url($GLOBALS['stub']['plugins'], $path);
}

function get_home_path()
{
    return $GLOBALS['stub']['home_path'];
}

/** Like WordPress: creates the folder unless $create_dir is false. */
function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false)
{
    $basedir = $GLOBALS['stub']['basedir'];
    if ($create_dir && !is_dir($basedir)) {
        mkdir($basedir, 0700, true);
    }
    return ['basedir' => $basedir, 'baseurl' => $GLOBALS['stub']['baseurl'], 'error' => false];
}

/** Like WordPress: delegates without creating anything. */
function wp_get_upload_dir()
{
    return wp_upload_dir(null, false);
}

require_once __DIR__ . '/../inc/SiteCheck/Locations.php';

use SFX\SiteCheck\Locations;

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

function tree(string $dir): array
{
    $list = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $list[] = $item->getPathname() . '|' . $item->getMTime();
    }
    sort($list);
    return $list;
}

// ---------------------------------------------------------------- home == siteurl

stub_reset();
$loc = Locations::current();
assert_same(['dir' => ABSPATH, 'url' => 'https://ex.test/'], $loc['wordpress'], 'wordpress root = ABSPATH at site_url');
assert_same(['dir' => ABSPATH, 'url' => 'https://ex.test/'], $loc['web'], 'home == siteurl → web root = ABSPATH');
assert_same(['dir' => WP_CONTENT_DIR . '/', 'url' => 'https://ex.test/wp-content/'], $loc['content'], 'content root');
assert_same(['dir' => WP_PLUGIN_DIR . '/', 'url' => 'https://ex.test/wp-content/plugins/'], $loc['plugins'], 'plugins root');
assert_same(['dir' => $tmp . '/public/wp/wp-content/uploads/', 'url' => 'https://ex.test/wp-content/uploads/'], $loc['uploads'], 'uploads root');
assert_same(ABSPATH . 'wp-config.php', $loc['config']['file'], 'config in ABSPATH');
assert_same(ABSPATH, $loc['config']['dir'], 'config dir');
assert_same('https://ex.test/', $loc['config']['url'], 'config dir mapped through the wordpress root');
assert_same('https://ex.test/wp-content/debug.log', Locations::url_for(WP_CONTENT_DIR . '/debug.log'), 'url_for a file in content');
assert_same('https://ex.test/a%20b.sql', Locations::url_for(ABSPATH . 'a b.sql'), 'url_for encodes each segment');
assert_same(null, Locations::url_for('/etc/passwd'), 'a path in no root is not mapped');
assert_same(null, Locations::url_for(ABSPATH . '../outside.sql'), 'a path with .. is not mapped');

// Longest matching root wins.
stub_reset(['plugins' => 'https://ex.test/plug']);
assert_same('https://ex.test/plug/x/readme.txt', Locations::url_for(WP_PLUGIN_DIR . '/x/readme.txt'), 'plugins mapping beats content and wordpress');

// ---------------------------------------------------------------- home differs from siteurl (Gate B pass 8 ruling)

// The web root is established only when home and siteurl are the same URL; a
// subdirectory install's web folder is not established, whatever its index.php says.
file_put_contents($tmp . '/public/index.php', $index_ok);
stub_reset(['siteurl' => 'https://ex.test/wp', 'home_path' => $tmp . '/public/']);
$loc = Locations::current();
assert_same(null, $loc['web'], 'home and siteurl differ (subdirectory install) → web root not established, even with a correct index.php');
assert_true(($loc['reasons']['web'] ?? '') !== '', 'home and siteurl differ → reason given');
assert_same(['dir' => ABSPATH, 'url' => 'https://ex.test/wp/'], $loc['wordpress'], 'wordpress root still at site_url');
assert_same('https://ex.test/wp/backup.sql', Locations::url_for(ABSPATH . 'backup.sql'), 'file in ABSPATH maps through site_url');
assert_same(null, Locations::url_for($tmp . '/public/backup.sql'), 'a file only in the unestablished web folder is not mapped');
unlink($tmp . '/public/index.php');

// ---------------------------------------------------------------- different host / port / scheme

foreach (['https://other.test/wp', 'https://ex.test:8443/wp', 'http://ex.test/wp'] as $siteurl) {
    stub_reset(['siteurl' => $siteurl, 'home_path' => $tmp . '/public/']);
    $loc = Locations::current();
    assert_same(null, $loc['web'], "siteurl {$siteurl} → web not established");
    assert_true(($loc['reasons']['web'] ?? '') !== '', "siteurl {$siteurl} → reason given");
    assert_true($loc['wordpress'] !== null, "siteurl {$siteurl} → wordpress root still mapped");
}

// ---------------------------------------------------------------- config one level above ABSPATH

rename(ABSPATH . 'wp-config.php', $tmp . '/public/wp-config.php');
stub_reset();
$loc = Locations::current();
assert_same($tmp . '/public/wp-config.php', $loc['config']['file'], 'config one level above ABSPATH');
assert_same(null, $loc['config']['url'], 'config above ABSPATH, web = ABSPATH → no URL');
rename($tmp . '/public/wp-config.php', ABSPATH . 'wp-config.php');

// ---------------------------------------------------------------- uploads outside every root

stub_reset(['basedir' => $tmp . '/elsewhere/uploads', 'baseurl' => 'https://ex.test/media']);
assert_same('https://ex.test/media/2026/a.jpg', Locations::url_for($tmp . '/elsewhere/uploads/2026/a.jpg'), 'uploads outside every root mapped by baseurl');
assert_same(null, Locations::url_for($tmp . '/elsewhere/other.jpg'), 'next to uploads but outside it → not mapped');

// ---------------------------------------------------------------- CDN / offload: other host → reason

stub_reset();
$loc = Locations::current();
foreach (['wordpress', 'content', 'plugins', 'uploads'] as $name) {
    assert_true(!isset($loc['reasons'][$name]), "same host → no reason for {$name}");
}
stub_reset(['baseurl' => 'https://cdn.example.net/uploads']);
$loc = Locations::current();
assert_true(($loc['reasons']['uploads'] ?? '') !== '', 'uploads baseurl on a CDN host → reason given');
assert_same('https://cdn.example.net/uploads/', $loc['uploads']['url'], 'uploads still mapped');
assert_true(!isset($loc['reasons']['wordpress']), 'CDN uploads → wordpress root unaffected');
stub_reset(['siteurl' => 'https://wp.example.net', 'home_path' => $tmp . '/public/']);
$loc = Locations::current();
assert_true(($loc['reasons']['wordpress'] ?? '') !== '', 'site_url on another host → reason for the wordpress root');
assert_true(($loc['reasons']['web'] ?? '') !== '', 'site_url on another host → web not established, reason given');

// ---------------------------------------------------------------- no folder is created

stub_reset(['basedir' => $tmp . '/public/wp/wp-content/absent-uploads', 'baseurl' => 'https://ex.test/wp-content/absent-uploads']);
$before = tree($tmp);
$loc = Locations::current();
Locations::url_for($tmp . '/public/wp/wp-content/absent-uploads/x.log');
assert_same($before, tree($tmp), 'resolving locations creates nothing on disk');
assert_true(!is_dir($tmp . '/public/wp/wp-content/absent-uploads'), 'absent uploads basedir stays absent');
assert_same($tmp . '/public/wp/wp-content/absent-uploads/', $loc['uploads']['dir'], 'absent uploads basedir is still reported');

// ---------------------------------------------------------------- fetchable

stub_reset();
assert_same(true, Locations::fetchable('https://ex.test/wp-content/debug.log'), 'same host and port, https');
assert_same(true, Locations::fetchable('https://EX.test/x'), 'host compared case-insensitively');
assert_same(true, Locations::fetchable('https://ex.test:443/x'), 'explicit default port');
assert_same(false, Locations::fetchable('https://ex.test:8443/x'), 'other port');
assert_same(false, Locations::fetchable('http://ex.test/x'), 'http on an https home is another port');
assert_same(false, Locations::fetchable('https://user:pw@ex.test/x'), 'userinfo');
assert_same(false, Locations::fetchable('https://user@ex.test/x'), 'user without password');
assert_same(false, Locations::fetchable('ftp://ex.test/x'), 'ftp scheme');
assert_same(false, Locations::fetchable('https://other.test/x'), 'other host');
assert_same(false, Locations::fetchable('/relative'), 'no host');
assert_same(false, Locations::fetchable('https:///nohost'), 'unparseable');
stub_reset(['home' => 'http://ex.test:8080']);
assert_same(true, Locations::fetchable('http://ex.test:8080/x'), 'home on a custom port');
assert_same(false, Locations::fetchable('http://ex.test/x'), 'home on a custom port, default port given');

echo "site-check-locations: PASS\n";
