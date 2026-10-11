<?php

declare(strict_types=1);

/**
 * SiteCheck `.htaccess` template (spec "`.htaccess` template"): separate
 * blocks with sfx markers, access-control and Options directives only, both
 * Apache syntaxes, resolved paths, nginx text only when the server looks
 * like nginx. The block patterns are run through PCRE here, as Apache does.
 *
 * Run: php tests/site-check-template-test.php
 */

function __($text, $domain = null)
{
    return $text;
}

require_once __DIR__ . '/../inc/SiteCheck/Checks/FileChecks.php';
require_once __DIR__ . '/../inc/SiteCheck/Template.php';

use SFX\SiteCheck\Template;

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

$locations = [
    'wordpress' => ['dir' => '/srv/www/wp/', 'url' => 'https://ex.test/wp/'],
    'web'       => ['dir' => '/srv/www/', 'url' => 'https://ex.test/'],
    'uploads'   => ['dir' => '/data/media/uploads/', 'url' => 'https://ex.test/media/uploads/'],
];

$blocks = [];
foreach (Template::blocks($locations, false) as $block) {
    $blocks[$block['id']] = $block;
}
assert_same(['sensitive', 'git', 'indexes', 'uploads', 'xmlrpc'], array_keys($blocks), 'Apache blocks, no nginx text on Apache');

/** The FilesMatch pattern of a block, as a PHP regex. */
function files_match(string $text): string
{
    assert_true(preg_match('/<FilesMatch "([^"]+)">/', $text, $m) === 1, 'block has a FilesMatch');
    return "\x01" . $m[1] . "\x01";
}

// ------------------------------------------------------------ every block

$allowed = ['<FilesMatch', '</FilesMatch>', '<Files', '</Files>', '<IfModule', '</IfModule>', 'Require', 'Order', 'Deny', 'Options'];
foreach ($blocks as $id => $block) {
    $lines = explode("\n", rtrim($block['text'], "\n"));
    assert_true(preg_match('/^# BEGIN sfx-[a-z-]+$/', $lines[0]) === 1, "{$id}: starts with # BEGIN sfx-…");
    assert_same('# END ' . substr($lines[0], 8), end($lines), "{$id}: ends with the matching # END sfx-…");
    assert_true(preg_match('/\b(Rewrite\w*|Redirect\w*|Header|php_flag|php_value|\w*Handler)\b/i', $block['text']) !== 1, "{$id}: no RewriteRule, Redirect, Header, php_flag or handler change");
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $first = strtok($line, ' >');
        $first = in_array($first . '>', $allowed, true) ? $first . '>' : $first;
        assert_true(in_array($first, $allowed, true), "{$id}: only access-control and Options directives (found '{$line}')");
    }
    assert_true($block['title'] !== '', "{$id}: has a title");
}

foreach (['sensitive', 'git', 'uploads'] as $id) {
    $text = $blocks[$id]['text'];
    assert_true(strpos($text, "<IfModule mod_authz_core.c>") !== false && strpos($text, 'Require all denied') !== false, "{$id}: Apache 2.4 syntax");
    assert_true(strpos($text, "<IfModule !mod_authz_core.c>") !== false && strpos($text, 'Deny from all') !== false && strpos($text, 'Order allow,deny') !== false, "{$id}: Apache 2.2 syntax");
}

// ------------------------------------------------------------ sensitive files

assert_same('/srv/www/.htaccess', $blocks['sensitive']['file'], 'root blocks go into the web root');
$sensitive = files_match($blocks['sensitive']['text']);
foreach (['.wp-config.php.swo', 'wp-config.php.swo', 'wp-config.php.bak', 'wp-config.bak', 'wp-config.php.old', 'wp-config.php.save', 'wp-config.php.orig', 'wp-config.php.txt', 'wp-config.php~', '.wp-config.php.swp', 'WP-CONFIG.PHP.BAK',
          'dump.sql', 'DUMP.SQL', 'site.sql.gz', 'debug.log', 'php_errors.LOG', 'error_log', '.env', 'readme.html', 'license.txt', 'README.HTML'] as $name) {
    assert_true(preg_match($sensitive, $name) === 1, "sensitive block denies {$name}");
}
foreach (['wp-config.php', 'wp-config-sample.php', 'index.php', 'style.css', 'image.jpg', 'my-error_log-viewer.js', 'catalog.txt', 'readme.txt', 'environment.js'] as $name) {
    assert_true(preg_match($sensitive, $name) !== 1, "sensitive block leaves {$name} alone");
}

// One list of copy suffixes: every name FileChecks flags as a wp-config copy is denied here.
$copy = new ReflectionClassConstant(\SFX\SiteCheck\Checks\FileChecks::class, 'CONFIG_COPY');
foreach (explode('|', \SFX\SiteCheck\Checks\FileChecks::COPY_SUFFIXES) as $suffix) {
    foreach (["wp-config.php.{$suffix}", ".wp-config.php.{$suffix}", "wp-config.{$suffix}"] as $name) {
        assert_true(preg_match($copy->getValue(), $name) === 1, "FileChecks flags {$name}");
        assert_true(preg_match($sensitive, $name) === 1, "sensitive block denies every flagged copy: {$name}");
    }
}
assert_true(preg_match('/subfolder/i', $blocks['sensitive']['note']) === 1 && strpos($blocks['sensitive']['note'], 'readme.html') !== false && strpos($blocks['sensitive']['note'], '.log') !== false,
    'sensitive block says it also applies in subfolders (readme.html, .log)');

// ------------------------------------------------------------ .git

assert_same('/srv/www/.git/.htaccess', $blocks['git']['file'], '.git block is its own inner .htaccess');
assert_true(strpos($blocks['git']['text'], 'FilesMatch') === false, '.git block denies everything, no file-name pattern');

// ------------------------------------------------------------ directory listing

assert_true(strpos($blocks['indexes']['text'], 'Options -Indexes') !== false, 'Options -Indexes in its own block');
assert_same('/srv/www/.htaccess', $blocks['indexes']['file'], 'listing block goes into the web root');

// ------------------------------------------------------------ uploads

assert_same('/data/media/uploads/.htaccess', $blocks['uploads']['file'], 'uploads block path is the resolved uploads dir');
$uploads = files_match($blocks['uploads']['text']);
foreach (['x.php', 'X.PHP', 'x.php.jpg', 'shell.PhP.png', 'a.phtml', 'a.PHTML', 'b.php5', 'b.php7', 'c.phar', 'd.pht', 'e.Pht.gif', 'f.php7.jpg'] as $name) {
    assert_true(preg_match($uploads, $name) === 1, "uploads block denies {$name}");
}
foreach (['photo.jpg', 'php.jpg', 'my-php-notes.pdf', 'phpinfo.png', 'graph.svg'] as $name) {
    assert_true(preg_match($uploads, $name) !== 1, "uploads block leaves {$name} alone");
}

// ------------------------------------------------------------ XML-RPC: commented out

foreach (explode("\n", trim($blocks['xmlrpc']['text'])) as $line) {
    assert_true($line === '' || $line[0] === '#', "xmlrpc block is entirely commented out ('{$line}')");
}
assert_true(stripos($blocks['xmlrpc']['text'], 'Jetpack') !== false, 'xmlrpc block names apps and Jetpack');
assert_true(strpos($blocks['xmlrpc']['text'], 'xmlrpc.php') !== false, 'xmlrpc block targets xmlrpc.php');

// ------------------------------------------------------------ fallbacks and nginx

$no_web = $locations;
$no_web['web'] = null;
$files = array_column(Template::blocks($no_web, false), 'file', 'id');
foreach (['sensitive', 'git', 'indexes', 'xmlrpc'] as $id) {
    assert_same(null, $files[$id], "{$id}: web root unknown → location unknown, no bare .htaccess path");
}
assert_same('/data/media/uploads/.htaccess', $files['uploads'], 'uploads path unaffected by an unknown web root');
$no_uploads = $locations;
$no_uploads['uploads'] = null;
$files = array_column(Template::blocks($no_uploads, true), 'file', 'id');
assert_same(null, $files['uploads'], 'uploads unknown → location unknown');
assert_same('/srv/www/.htaccess', $files['sensitive'], 'root path unaffected by unknown uploads');
foreach (Template::blocks($no_uploads, true) as $block) {
    assert_true(strpos($block['text'], '.htaccess') === false || $block['id'] !== 'uploads', 'no bare path in block text');
}

$nginx = array_column(Template::blocks($locations, true), null, 'id');
assert_true(isset($nginx['nginx']), 'nginx text shown when the server looks like nginx');
$text = $nginx['nginx']['text'];
assert_true(strpos($text, 'deny all;') !== false, 'nginx text denies');
assert_true(strpos($text, '/media/uploads/') !== false, 'nginx uploads rule uses the uploads URL path');
assert_true(strpos($text, 'autoindex off;') !== false, 'nginx text turns listings off');
assert_true(preg_match('/^# BEGIN sfx-nginx/', $text) === 1 && preg_match('/# END sfx-nginx$/', rtrim($text)) === 1, 'nginx text carries sfx markers');
assert_true(preg_match('/\b(rewrite|return|add_header)\b/', $text) !== 1, 'nginx text: no rewrite, return or add_header');
assert_true(preg_match('/^# .*before.*PHP/m', $text) === 1, 'nginx text says the deny locations go before the PHP location block');
assert_true(strpos($text, 'location ~ \\.php$') !== false, 'nginx placement hint names the usual PHP location');

$GLOBALS['is_nginx'] = true;
assert_same(true, Template::looks_like_nginx(), 'WordPress\' $is_nginx → nginx');
$GLOBALS['is_nginx'] = false;
assert_same(false, Template::looks_like_nginx(), 'not nginx');

// Gate B pass 3: the PHP-like rule matches the extension anywhere in the name (rule 6), Apache and nginx.
foreach (['x.phtml~', 'x.php~', 'x.php_old', 'x.phpx', 'x.pharx'] as $name) {
    assert_true(preg_match($uploads, $name) === 1, "uploads block denies {$name}");
}
$nginx_text = array_column(Template::blocks($locations, true), null, 'id')['nginx']['text'];
preg_match('/location ~\* "([^"]+)" \{/', substr($nginx_text, strpos($nginx_text, '/media/uploads/') - 20), $m);
$re = '~' . ($m[1] ?? 'none') . '~i';
foreach (['/media/uploads/x.phtml~', '/media/uploads/x.php~', '/media/uploads/2026/x.php.jpg'] as $uri) {
    assert_true(preg_match($re, $uri) === 1, "nginx uploads rule denies {$uri}");
}
assert_true(preg_match($re, '/index.php') !== 1 && preg_match($re, '/wp-login.php') !== 1, 'nginx uploads rule stays inside uploads');
// Unknown uploads: no nginx uploads rule at all (never the whole site).
$text = array_column(Template::blocks($no_uploads, true), null, 'id')['nginx']['text'];
assert_true(strpos($text, '^/.*') === false && preg_match('/location ~\* \^\/\.\*/', $text) !== 1, 'unknown uploads → no site-wide PHP deny');
assert_true(preg_match('/(php\[57\]|phtml)/', $text) !== 1, 'unknown uploads → no PHP deny location in the nginx text');

// Gate B pass 4: nginx matches the decoded URI, so the uploads path is decoded once, escaped and quoted.
$encoded = $locations;
$encoded['uploads']['url'] = 'https://ex.test/media%20files/(x)/';
$text = array_column(Template::blocks($encoded, true), null, 'id')['nginx']['text'];
assert_true(strpos($text, 'location ~* "^/media files/\(x\)/.*\.(php|pht|phar)" {') !== false && preg_match('/location ~\* "(\^\/media[^"]+)" \{/', $text, $m) === 1, 'decoded, regex-escaped and quoted: ' . $text);
assert_true(preg_match('~' . $m[1] . '~i', '/media files/(x)/shell.php') === 1, 'the rule matches the decoded URI nginx sees');

// Gate B pass 7: uploads on another host (CDN) or at "/" → no nginx uploads rule, a note instead.
foreach (['https://cdn.example/' => 'CDN at the root', 'https://cdn.example/media/' => 'CDN with a path', 'https://ex.test/' => 'uploads at /'] as $url => $what) {
    $cdn = $locations;
    $cdn['uploads']['url'] = $url;
    $text = array_column(Template::blocks($cdn, true), null, 'id')['nginx']['text'];
    assert_true(preg_match('/(php\|pht\|phar)/', $text) !== 1, "{$what}: no PHP deny location");
    assert_true(strpos($text, 'another host') !== false || strpos($text, 'could not be determined') !== false || strpos($text, 'root') !== false, "{$what}: a note says why");
}

// Gate B pass 8: only with an established web root and uploads on its host below the root.
$split = $locations;
$split['web'] = null;
$split['wordpress'] = ['dir' => '/srv/www/wp/', 'url' => 'https://wp.example/'];
$split['uploads']['url'] = 'https://wp.example/media/';
$text = array_column(Template::blocks($split, true), null, 'id')['nginx']['text'];
assert_true(preg_match('/(php\|pht\|phar)/', $text) !== 1, 'web root not established → no uploads rule, never compared with the WordPress host');
assert_true(strpos($text, 'not established') !== false, 'and the note says why');

echo "site-check template: ok\n";
