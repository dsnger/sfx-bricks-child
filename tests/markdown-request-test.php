<?php

declare(strict_types=1);

// Any notice or warning fails the test (Review Focus 4).
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "FAIL: PHP error {$no}: {$msg} at {$file}:{$line}\n");
    exit(1);
});

// --- WordPress stubs: one mutable state per request. ---
$S = [];
function reset_state(): void
{
    global $S;
    $S = [
        'admin' => false, 'ajax' => false, 'cron' => false, 'rest' => false,
        'singular' => true, 'preview' => false, 'feed' => false, 'embed' => false,
        'trackback' => false, 'robots' => false, 'favicon' => false, 'sitemap' => '',
        'builder' => false, 'builder_iframe' => false, 'builder_call' => false,
        'post' => new WP_Post(5, 'page', 'publish'), 'password_required' => false,
        'options' => ['page_for_posts' => '0', 'sfx_markdown_options' => ['post_types' => ['page', 'post']]],
        'canonical' => 'https://ex.test/?p=5',
    ];
    $_GET = [];
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
}

final class WP_Post
{
    public $ID;
    public $post_type;
    public $post_status;
    public function __construct(int $id, string $type, string $status)
    {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_status = $status;
    }
}

function is_admin() { global $S; return $S['admin']; }
function wp_doing_ajax() { global $S; return $S['ajax']; }
function wp_doing_cron() { global $S; return $S['cron']; }
function wp_is_serving_rest_request() { global $S; return $S['rest']; }
function is_singular($types = '') { global $S; return $S['singular']; }
function is_preview() { global $S; return $S['preview']; }
function is_feed($feeds = '') { global $S; return $S['feed']; }
function is_embed() { global $S; return $S['embed']; }
function is_trackback() { global $S; return $S['trackback']; }
function is_robots() { global $S; return $S['robots']; }
function is_favicon() { global $S; return $S['favicon']; }
function get_query_var($var, $default = '') { global $S; return $var === 'sitemap' ? $S['sitemap'] : $default; }
function bricks_is_builder() { global $S; return $S['builder']; }
function bricks_is_builder_iframe() { global $S; return $S['builder_iframe']; }
function bricks_is_builder_call() { global $S; return $S['builder_call']; }
function get_queried_object() { global $S; return $S['post']; }
function get_post_type($post = null) { return $post instanceof WP_Post ? $post->post_type : false; }
function get_post_status($post = null) { return $post instanceof WP_Post ? $post->post_status : false; }
function post_password_required($post = null) { global $S; return $S['password_required']; }
function get_option($name, $default = false) { global $S; return array_key_exists($name, $S['options']) ? $S['options'][$name] : $default; }
function get_post_types($args = [], $output = 'names')
{
    $out = [];
    foreach (['page' => 'Pages', 'post' => 'Posts', 'cpt' => 'Jobs'] as $n => $l) {
        $out[$n] = (object) ['name' => $n, 'label' => $l, 'labels' => (object) ['name' => $l]];
    }
    return $output === 'objects' ? $out : array_keys($out);
}
function wp_get_canonical_url($post = null) { global $S; return $S['canonical']; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
function esc_url($url) { return str_replace('&', '&#038;', $url); }

$hooks = [];
function add_action($hook, $cb, $priority = 10, $args = 1) { global $hooks; $hooks[] = [$hook, $priority]; return true; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { global $hooks; $hooks[] = [$hook, $priority]; return true; }
function register_setting($group, $name, $args = []) { return true; }

require_once __DIR__ . '/../inc/Markdown/Settings.php';
require_once __DIR__ . '/../inc/Markdown/Request.php';
require_once __DIR__ . '/../inc/Markdown/Headers.php';
require_once __DIR__ . '/../inc/Markdown/Controller.php';

use SFX\Markdown\Controller;
use SFX\Markdown\Headers;
use SFX\Markdown\Request;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. Parser missing (no WP_HTML_Processor yet): never eligible, Controller adds no front-end hook.
reset_state();
assert_same(false, Request::parser_available(), '1: parser missing');
assert_same(false, Request::is_eligible(), '1: parser missing -> not eligible');
new Controller();
assert_same([['sfx_init_admin_features', 10]], $hooks, '1: only Settings::register() without parser');

// From here on the parser exists (declared at runtime, after the check above).
if (!class_exists('WP_HTML_Processor')) {
    final class WP_HTML_Processor
    {
        public static function create_full_parser($html) { return null; }
    }
}
assert_same(true, Request::parser_available(), '1: parser present');

$hooks = [];
new Controller();
assert_same(
    [['sfx_init_admin_features', 10], ['exit_on_http_head', 10], ['wp_head', 10], ['template_redirect', PHP_INT_MAX]],
    $hooks,
    '1: hooks with parser'
);

// 2. D8 Accept rule.
$accept = [
    ['text/markdown', true],
    ['text/html, text/markdown', true],
    ['text/markdown;q=0.5, text/html', false],
    ['text/markdown;q=0.5, text/*;q=0.5', true],
    ['text/markdown;q=0.4, */*;q=0.5', false],
    ['text/*', false],
    ['*/*', false],
    ['text/markdown;q=0', false],
    ['text/markdown;q=abc', true],
    ['text/markdown;q=0.1, text/markdown;q=1, text/html;q=0.5', false],
    ['TEXT/MARKDOWN', true],
    ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7', false],
    ['', false],
    ['text/markdown;q=0.6, text/html;q=0.5, text/*;q=0.9', true],
    [' text/markdown ; charset=utf-8 ; Q=0.8 , */*;q=0.9', false],
];
foreach ($accept as [$header, $expected]) {
    assert_same($expected, Request::accepts_markdown($header), "2: Accept '{$header}'");
}

// 3. wants_markdown: only the string '1' (Review Focus 4), or D8 on the Accept header.
reset_state();
assert_same(false, Request::wants_markdown(), '3: plain HTML request');
$_GET['markdown'] = '1';
assert_same(true, Request::wants_markdown(), '3: markdown=1');
foreach ([['1'], '1 ', 'true', '', '01', 1] as $bad) {
    $_GET['markdown'] = $bad;
    assert_same(false, Request::wants_markdown(), '3: markdown=' . var_export($bad, true));
}
$_GET = [];
$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
assert_same(true, Request::wants_markdown(), '3: Accept text/markdown');
$_SERVER['HTTP_ACCEPT'] = ['text/markdown'];
assert_same(false, Request::wants_markdown(), '3: non-string Accept');
unset($_SERVER['HTTP_ACCEPT']);
assert_same(false, Request::wants_markdown(), '3: no Accept');

// 4. Eligibility.
reset_state();
assert_same(true, Request::is_eligible(), '4: published page');
$S['post'] = new WP_Post(7, 'post', 'publish');
assert_same(true, Request::is_eligible(), '4: published post');

$ineligible = [
    'private' => static function () { global $S; $S['post'] = new WP_Post(5, 'page', 'private'); },
    'draft' => static function () { global $S; $S['post'] = new WP_Post(5, 'page', 'draft'); },
    'preview' => static function () { global $S; $S['preview'] = true; },
    'posts index page' => static function () { global $S; $S['options']['page_for_posts'] = '5'; },
    'password required' => static function () { global $S; $S['password_required'] = true; },
    'feed' => static function () { global $S; $S['feed'] = true; },
    'embed' => static function () { global $S; $S['embed'] = true; },
    'robots' => static function () { global $S; $S['robots'] = true; },
    'favicon' => static function () { global $S; $S['favicon'] = true; },
    'trackback' => static function () { global $S; $S['trackback'] = true; },
    'sitemap' => static function () { global $S; $S['sitemap'] = 'posts'; },
    'admin' => static function () { global $S; $S['admin'] = true; },
    'ajax' => static function () { global $S; $S['ajax'] = true; },
    'cron' => static function () { global $S; $S['cron'] = true; },
    'rest' => static function () { global $S; $S['rest'] = true; },
    'builder' => static function () { global $S; $S['builder'] = true; },
    'builder iframe' => static function () { global $S; $S['builder_iframe'] = true; },
    'builder call' => static function () { global $S; $S['builder_call'] = true; },
    'not singular' => static function () { global $S; $S['singular'] = false; },
    'no post' => static function () { global $S; $S['post'] = null; },
    'excluded post type' => static function () { global $S; $S['post'] = new WP_Post(5, 'cpt', 'publish'); },
    'no post types included' => static function () { global $S; $S['options']['sfx_markdown_options'] = ['post_types' => []]; },
];
foreach ($ineligible as $name => $setup) {
    reset_state();
    $setup();
    assert_same(false, Request::is_eligible(), "4: {$name} -> not eligible");
}

// 5. Markdown URL and head link.
reset_state();
assert_same('https://ex.test/?p=5&markdown=1', Request::markdown_url(), '5: markdown url');
$S['canonical'] = false;
assert_same(null, Request::markdown_url(), '5: no canonical -> null');

reset_state();
ob_start();
Controller::print_head_link();
assert_same("<link rel=\"alternate\" type=\"text/markdown\" href=\"https://ex.test/?p=5&#038;markdown=1\" />\n", ob_get_clean(), '5: head link, escaped');
$S['preview'] = true;
ob_start();
Controller::print_head_link();
assert_same('', ob_get_clean(), '5: no head link when not eligible');

// 6. exit_on_http_head: false only for eligible + wanting Markdown.
reset_state();
assert_same(true, Controller::exit_on_http_head(true), '6: eligible, not wanting');
$_GET['markdown'] = '1';
assert_same(false, Controller::exit_on_http_head(true), '6: eligible and wanting');
$S['post'] = new WP_Post(5, 'page', 'private');
assert_same(true, Controller::exit_on_http_head(true), '6: wanting, not eligible');

// 7. Vary merge.
assert_same('Accept', Headers::merged_vary(['Content-Type: text/html'], 'Accept'), '7: no Vary yet');
assert_same(
    'Accept-Encoding, Cookie, Accept',
    Headers::merged_vary(['Content-Type: text/html', 'Vary: Accept-Encoding, Cookie'], 'Accept'),
    '7: keeps existing, adds Accept'
);
assert_same(
    'Accept-Encoding, accept, Cookie',
    Headers::merged_vary(['vary: Accept-Encoding, accept', 'Vary: Cookie, Accept-Encoding'], 'Accept'),
    '7: merges several Vary lines, Accept once'
);

echo "markdown-request-test: PASS\n";
