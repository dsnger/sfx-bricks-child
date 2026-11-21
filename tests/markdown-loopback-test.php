<?php

declare(strict_types=1);

class WP_Error
{
    public function __construct(public $code = 'x', public $message = 'boom') {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['response']['code'] ?? '') : ''; }
function wp_remote_retrieve_header($r, $h) { return $r['headers'][strtolower($h)] ?? ''; }
function __($t, $d = null) { return $t; }
function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . $v; }
function apply_filters($hook, $value, ...$args) { return $value; }
$requests = [];
$queue = [];
function wp_remote_get($url, $args = []) { global $requests, $queue; $requests[] = [$url, $args]; return array_shift($queue); }

require_once __DIR__ . '/../inc/Markdown/LoopbackCheck.php';

use SFX\Markdown\LoopbackCheck;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function res(int $code, array $headers = []): array
{
    return ['response' => ['code' => $code], 'headers' => array_change_key_case($headers)];
}

// 1. classify_llms
assert_same('failed', LoopbackCheck::classify_llms(new WP_Error())['outcome'], '1: WP_Error');
assert_same('theme', LoopbackCheck::classify_llms(res(200, ['X-SFX-Llms-Txt' => '1', 'Content-Type' => 'text/plain']))['outcome'], '1: marker');
$r = LoopbackCheck::classify_llms(res(301, ['Location' => '/login']));
assert_same('redirected', $r['outcome'], '1: 301');
assert_same('/login', $r['detail'], '1: Location in detail');
assert_same('none', LoopbackCheck::classify_llms(res(404))['outcome'], '1: 404');
assert_same('none', LoopbackCheck::classify_llms(res(410))['outcome'], '1: 410');
assert_same('failed', LoopbackCheck::classify_llms(res(500))['outcome'], '1: 500');
assert_same('html', LoopbackCheck::classify_llms(res(200, ['Content-Type' => 'text/html; charset=UTF-8']))['outcome'], '1: html');
assert_same('foreign', LoopbackCheck::classify_llms(res(200, ['Content-Type' => 'text/plain; charset=utf-8']))['outcome'], '1: foreign');
assert_same('failed', LoopbackCheck::classify_llms(res(200))['outcome'], '1: no content type');
assert_same('failed', LoopbackCheck::classify_llms(res(200, ['Content-Type' => 'application/json']))['outcome'], '1: json');
assert_same('theme', LoopbackCheck::classify_llms(res(200, ['X-SFX-Llms-Txt' => '1']))['outcome'], '1: marker wins over missing type');

// 1b. repeated headers arrive as arrays
$r = LoopbackCheck::classify_llms(['response' => ['code' => 301], 'headers' => ['location' => ['/a', '/b']]]);
assert_same('/a', $r['detail'], '1b: repeated Location takes the first');
assert_same('foreign', LoopbackCheck::classify_llms(['response' => ['code' => 200], 'headers' => ['content-type' => ['text/plain', 'text/plain']]])['outcome'], '1b: repeated Content-Type');
assert_same('theme', LoopbackCheck::classify_llms(['response' => ['code' => 200], 'headers' => ['x-sfx-llms-txt' => ['1', '1']]])['outcome'], '1b: repeated marker');

// 2. classify_cache
$md = res(200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
$html = res(200, ['Content-Type' => 'text/html']);
assert_same('no_convert', LoopbackCheck::classify_cache($html, $html, $html), '2: control HTML');
assert_same('failed', LoopbackCheck::classify_cache(res(302, ['Location' => '/x']), $html, $md), '2: control redirect');
assert_same('failed', LoopbackCheck::classify_cache(res(500), $html, $md), '2: control error');
assert_same('failed', LoopbackCheck::classify_cache(new WP_Error(), $html, $md), '2: control WP_Error');
assert_same('cache_html', LoopbackCheck::classify_cache($md, $html, $html), '2: cache serves HTML');
assert_same('clean', LoopbackCheck::classify_cache($md, $html, $md), '2: clean');
assert_same('failed', LoopbackCheck::classify_cache($md, $html, new WP_Error()), '2: third WP_Error');
assert_same('failed', LoopbackCheck::classify_cache($md, $html, res(302)), '2: third redirect');
assert_same('failed', LoopbackCheck::classify_cache($md, new WP_Error(), $md), '2: warm WP_Error');

assert_same('clean', LoopbackCheck::classify_cache(['response' => ['code' => 200], 'headers' => ['content-type' => ['text/markdown', 'text/markdown']]], $html, $md), '2: repeated Content-Type');

// 3. guidance (constants cannot be undefined: none first)
$file_line = 'If a file llms.txt stays in the site root, remove it there.';
assert_same([$file_line], LoopbackCheck::guidance(), '3: none active');
define('WPSEO_VERSION', '1');
$g = LoopbackCheck::guidance();
assert_same(2, count($g), '3: yoast + file');
assert_same("Yoast SEO → Settings → llms.txt: switch off 'Enable llms.txt file feature'.", $g[0], '3: yoast line');
define('AIOSEO_VERSION', '1');
define('SEOPRESS_PRO_VERSION', '1');
$g = LoopbackCheck::guidance();
assert_same(4, count($g), '3: three plugins + file');
assert_same("All in One SEO → Sitemaps → LLMs.txt: switch off 'Enable llms.txt'.", $g[1], '3: aioseo line');
assert_same("SEOPress PRO: switch off llms.txt in SEOPress PRO's settings.", $g[2], '3: seopress line');
assert_same($file_line, $g[3], '3: file line last');

// 4. requests
$queue = [res(200)];
LoopbackCheck::get('https://site.test/llms.txt');
assert_same(['timeout' => 5, 'redirection' => 0, 'sslverify' => false], $requests[0][1], '4: plain request args');
$queue = [res(200)];
LoopbackCheck::get('https://site.test/p', ['Accept' => 'text/markdown']);
assert_same(['timeout' => 5, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Accept' => 'text/markdown']], $requests[1][1], '4: Accept request args');

$requests = [];
$queue = [res(200, ['Content-Type' => 'text/markdown']), res(200, ['Content-Type' => 'text/html']), res(200, ['Content-Type' => 'text/html'])];
assert_same('cache_html', LoopbackCheck::run_cache('https://site.test/p/'), '4: run_cache');
assert_same(['https://site.test/p/?markdown=1', 'https://site.test/p/', 'https://site.test/p/'], array_column($requests, 0), '4: run_cache order');

echo "markdown-loopback-test: OK\n";
