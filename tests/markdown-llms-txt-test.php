<?php

declare(strict_types=1);

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    fwrite(STDERR, "FAIL: PHP error {$no}: {$msg} at {$file}:{$line}\n");
    exit(1);
});

// --- WordPress stubs ---
$S = [];
final class WP_Post
{
    public function __construct(public int $ID, public string $post_title, public string $post_date, public string $post_type = 'post') {}
}
function reset_state(): void
{
    global $S;
    $S = [
        'options' => ['page_for_posts' => '7', 'sfx_markdown_options' => ['post_types' => ['page', 'post']]],
        'name' => 'A *B* &amp; Co', 'description' => 'Tag &#8211; line', 'posts' => ['page' => [], 'post' => []], 'calls' => [],
    ];
}
function get_option($name, $default = false) { global $S; return array_key_exists($name, $S['options']) ? $S['options'][$name] : $default; }
function get_post_types($args = [], $output = 'names')
{
    $out = [];
    foreach (['page' => 'Pages', 'post' => 'Posts'] as $n => $l) {
        $l = $GLOBALS['labels'][$n] ?? $l;
        $out[$n] = (object) ['name' => $n, 'label' => $l, 'labels' => (object) ['name' => $l]];
    }
    return $output === 'objects' ? $out : array_keys($out);
}
function get_bloginfo($k) { global $S; return $S[$k]; }
function get_the_title($p) { return $p->post_title; }
function get_permalink($p) { return 'https://ex.test/p' . $p->ID . '/'; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
function get_posts($args) { global $S; $S['calls'][] = $args; return array_slice($S['posts'][$args['post_type']] ?? [], 0, $args['numberposts']); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function __($s, $d = 'default') { global $S; return $S['translation'] ?? $s; }

require_once __DIR__ . '/support/html-api.php';
require_once __DIR__ . '/../inc/Markdown/Settings.php';
require_once __DIR__ . '/../inc/Markdown/Escape.php';
require_once __DIR__ . '/../inc/Markdown/LlmsTxt.php';

use SFX\Markdown\LlmsTxt;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. matches (D13).
$cases = [
    ['/llms.txt', '/', 404, null, false, true, 'plain 404'],
    ['/llms.txt?robots=1', '/', 404, null, false, false, 'query robots'],
    ['/llms.txt?', '/', 404, null, false, false, 'empty query'],
    ['/llms.txt?feed=rss2', '/', 404, null, false, false, 'query feed'],
    ['/llms.txt/', '/', 404, null, false, false, 'trailing slash'],
    ['/LLMS.txt', '/', 404, null, false, false, 'case'],
    ['/llms.txt', '/', 410, null, false, false, '410'],
    ['/llms.txt', '/', 503, null, false, false, '503'],
    ['/llms.txt', '/', 302, null, false, false, '302'],
    ['/llms.txt', '/', 200, '^llms\.txt$', false, true, 'Rank Math rule'],
    ['/llms.txt', '/', 200, '^other$', false, false, '200 other rule'],
    ['/llms.txt', '/', 200, null, false, false, '200 no rule'],
    ['/llms.txt', '/', 404, null, true, false, 'maintenance 404'],
    ['/llms.txt', '/', 200, '^llms\.txt$', true, false, 'maintenance rank math'],
    ['/sub/llms.txt', '/sub/', 404, null, false, true, 'subdir'],
    ['/llms.txt', '/sub/', 404, null, false, false, 'subdir wrong path'],
];
foreach ($cases as [$uri, $home, $status, $rule, $maint, $want, $label]) {
    assert_same($want, LlmsTxt::matches($uri, $home, $status, $rule, $maint, 'GET', false, false), "matches: {$label}");
}
assert_same(true, LlmsTxt::matches('/llms.txt', '/', 404, null, false, 'HEAD', false, false), 'matches: HEAD');
assert_same(false, LlmsTxt::matches('/llms.txt', '/', 200, '^llms\.txt$', false, 'POST', false, false), 'matches: POST');
assert_same(false, LlmsTxt::matches('/llms.txt', '/', 200, '^llms\.txt$', false, 'GET', true, false), 'matches: feed');
assert_same(false, LlmsTxt::matches('/llms.txt', '/', 200, '^llms\.txt$', false, 'GET', false, true), 'matches: robots');

// 2. headers.
assert_same(
    ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, no-store, max-age=0', 'X-SFX-Llms-Txt' => '1'],
    LlmsTxt::headers(),
    'headers'
);

// 3. render.
reset_state();
assert_same(null, LlmsTxt::newest_listed_url(), 'nothing listable -> null');
assert_same("# A \\*B\\* \\& Co\n\n> Tag – line\n", LlmsTxt::render(), 'empty site');
assert_same('page', $S['calls'][0]['post_type'], 'queries pages first');

reset_state();
$S['posts']['page'] = [new WP_Post(1, 'About', '2026-01-01 00:00:00', 'page')];
$S['posts']['post'] = [new WP_Post(2, 'Hello [x] &amp; more', '2026-03-01 00:00:00'), new WP_Post(3, 'Old', '2026-02-01 00:00:00')];
$out = LlmsTxt::render();
assert_same(
    "# A \\*B\\* \\& Co\n\n> Tag – line\n\n## Pages\n\n- [About](<https://ex.test/p1/?markdown=1>)\n\n## Posts\n\n"
    . "- [Hello \\[x\\] \\& more](<https://ex.test/p2/?markdown=1>)\n- [Old](<https://ex.test/p3/?markdown=1>)\n",
    $out,
    'render sections and escaped lines'
);
$page = $S['calls'][0];
$post = $S['calls'][1];
assert_same('publish', $page['post_status'], 'status');
assert_same(false, $page['has_password'], 'has_password');
assert_same(501, $page['numberposts'], 'numberposts');
assert_same([7], $page['post__not_in'], 'posts index excluded');
assert_same(['menu_order' => 'ASC', 'title' => 'ASC'], $page['orderby'], 'pages order');
assert_same('date', $post['orderby'], 'posts order');
assert_same('DESC', $post['order'], 'posts direction');
assert_same('https://ex.test/p2/', LlmsTxt::newest_listed_url(), 'newest listed url');

reset_state();
$S['options']['page_for_posts'] = '0';
LlmsTxt::render();
assert_same(true, !isset($S['calls'][0]['post__not_in']), 'no exclusion without posts page');

reset_state();
$S['description'] = '';
$S['options']['sfx_markdown_options'] = ['post_types' => []];
assert_same("# A \\*B\\* \\& Co\n", LlmsTxt::render(), 'no tagline, no types');

// Gate B pass 4: a trailing # in the site name is heading text, not a closing sequence.
reset_state();
$S['name'] = 'Studio #';
$S['description'] = '';
$S['options']['sfx_markdown_options'] = ['post_types' => []];
assert_same("# Studio \\#\n", LlmsTxt::render(), 'site name with trailing #');

// A trailing # followed by spaces or a tab is still heading text, in the site name and in a type label.
reset_state();
$S['name'] = "Studio # \t";
$S['description'] = '';
assert_same("# Studio \\#\n", LlmsTxt::render(), 'site name with trailing # and whitespace');
$S['posts']['post'] = [new WP_Post(2, 'Hi', '2026-03-01 00:00:00')];
$S['options']['sfx_markdown_options'] = ['post_types' => ['post']];
$GLOBALS['labels'] = ['post' => 'Posts # '];
assert_same(true, strpos(LlmsTxt::render(), "\n## Posts \\#\n") !== false, 'type label with trailing # and space');
unset($GLOBALS['labels']);

// An untitled post is labelled with its plain permalink, not listed as [](url).
reset_state();
$S['posts']['post'] = [new WP_Post(4, '', '2026-01-01 00:00:00'), new WP_Post(5, " &nbsp; ", '2026-01-02 00:00:00')];
$out = LlmsTxt::render();
assert_same(true, strpos($out, "- [https://ex.test/p4/](<https://ex.test/p4/?markdown=1>)\n") !== false, 'empty title -> permalink label');
assert_same(true, strpos($out, "- [https://ex.test/p5/](<https://ex.test/p5/?markdown=1>)\n") !== false, 'whitespace-only title -> permalink label');

// Entities from wptexturize are decoded, not shown literally.
reset_state();
$S['posts']['post'] = [new WP_Post(9, 'Don&#8217;t &#8211; &amp;', '2026-01-01 00:00:00')];
$out = LlmsTxt::render();
assert_same(true, strpos($out, '&#') === false, 'no numeric entity left');
assert_same(true, strpos($out, '- [Don’t – \\&](') !== false, 'decoded title');

// Translated notice is escaped.
reset_state();
$S['translation'] = '*More* [here]';
for ($i = 1; $i <= 501; $i++) {
    $S['posts']['post'][] = new WP_Post($i, "T{$i}", '2026-01-01 00:00:00');
}
assert_same(true, in_array('\\*More\\* \\[here\\]', explode("\n", LlmsTxt::render()), true), 'notice escaped');

// 501 found -> 500 lines and one notice.
reset_state();
for ($i = 1; $i <= 501; $i++) {
    $S['posts']['post'][] = new WP_Post($i, "T{$i}", '2026-01-01 00:00:00');
}
$lines = explode("\n", LlmsTxt::render());
$items = array_filter($lines, static fn($l) => str_starts_with($l, '- ['));
assert_same(500, count($items), '500 lines');
assert_same(true, in_array('More entries exist than are listed here.', $lines, true), 'more line');

echo "PASS\n";
