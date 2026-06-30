<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

$failures = 0;
$test_posts = [];
$test_meta = [];
$test_meta_single_as_array = [];
$test_post_lists = [];
$test_transients = [];
$test_options = [];

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, "{$message} (needle '{$needle}' not found)");
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return (string) $text;
}

function esc_attr_e($text, $domain = 'default'): void
{
    echo esc_attr($text);
}

function esc_html_e($text, $domain = 'default'): void
{
    echo esc_html($text);
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url($url): string
{
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : '';
}

function sanitize_text_field($text): string
{
    return is_string($text) ? trim($text) : '';
}

function wp_kses_post($text): string
{
    return (string) $text;
}

function add_shortcode($tag, $callback): bool
{
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return true;
}

function shortcode_atts($defaults, $atts, $shortcode = ''): array
{
    $atts = is_array($atts) ? $atts : [];
    return array_merge($defaults, $atts);
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = '';
    public string $post_status = '';
    public string $post_title = '';
}

function sfx_make_post(int $id, string $post_type, string $post_status, string $post_title): WP_Post
{
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_type = $post_type;
    $post->post_status = $post_status;
    $post->post_title = $post_title;
    return $post;
}

function get_post($post_id)
{
    global $test_posts;
    $post_id = (int) $post_id;
    return $test_posts[$post_id] ?? null;
}

function get_posts(array $args = []): array
{
    global $test_post_lists;
    $post_type = $args['post_type'] ?? '';
    return $test_post_lists[$post_type] ?? [];
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $test_meta, $test_meta_single_as_array;
    $post_id = (int) $post_id;

    if ($key === '') {
        return $test_meta[$post_id] ?? [];
    }

    $raw = $test_meta[$post_id][$key] ?? ($single ? '' : []);

    if ($single && isset($test_meta_single_as_array[$post_id])
        && in_array($key, $test_meta_single_as_array[$post_id], true)) {
        return $test_meta[$post_id][$key] ?? [];
    }

    if ($single) {
        return is_array($raw) ? (string) reset($raw) : (string) $raw;
    }

    return $raw;
}

function get_transient(string $key)
{
    global $test_transients;
    return $test_transients[$key] ?? false;
}

function set_transient(string $key, $value, int $expiration): bool
{
    global $test_transients;
    $test_transients[$key] = $value;
    return true;
}

function delete_transient(string $key): bool
{
    global $test_transients;
    unset($test_transients[$key]);
    return true;
}

function get_option(string $option, $default = false)
{
    global $test_options;
    return $test_options[$option] ?? $default;
}

function update_option(string $option, $value, $autoload = null): bool
{
    global $test_options;
    $test_options[$option] = $value;
    return true;
}

class WP_Query
{
    public array $posts = [];
    public int $post_count = 0;
    private int $index = 0;

    public function __construct(array $args = [])
    {
        global $test_post_lists;
        $post_type = $args['post_type'] ?? '';
        $this->posts = $test_post_lists[$post_type] ?? [];
        $this->post_count = count($this->posts);
    }

    public function have_posts(): bool
    {
        return $this->index < $this->post_count;
    }

    public function the_post(): void
    {
        $this->index++;
    }
}

class WPDBStub
{
    public string $options = 'wp_options';

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function query(string $query)
    {
        return true;
    }
}

$wpdb = new WPDBStub();

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

function run_social_account_field_case(string $label, callable $callback): void
{
    global $sc;
    if (!method_exists($sc, 'render_account_field')) {
        assert_true(false, "{$label}: render_account_field() not implemented yet (expected until Task 3)");
        return;
    }
    $callback($sc);
}

function run_social_bricks_case(string $label, string $method, callable $callback): void
{
    if (!method_exists(\SFX\SocialMediaAccounts\Controller::class, $method)) {
        assert_true(false, "{$label}: {$method}() not implemented yet (expected until Task 4)");
        return;
    }
    $callback();
}

// Stub data
$test_posts[123] = sfx_make_post(123, 'sfx_social_account', 'publish', 'Instagram');
$test_meta[123] = [
    '_link_url'    => ['https://social.example/ig'],
    '_icon_image'  => ['https://cdn.example/icon.svg'],
    '_link_title'  => [''],
    '_link_target' => ['_blank'],
];
$test_post_lists['sfx_social_account'] = [$test_posts[123]];

$test_posts[124] = sfx_make_post(124, 'sfx_social_account', 'publish', 'ArrayMeta');
$test_meta[124] = [
    '_link_url'    => ['https://array-meta.example/x'],
    '_icon_image'  => ['https://cdn.example/icon.svg'],
    '_link_target' => ['_blank'],
];
$test_meta_single_as_array[124] = ['_link_url', '_icon_image', '_link_target'];
$test_post_lists['sfx_social_account'][] = $test_posts[124];

$test_posts[200] = sfx_make_post(200, 'page', 'publish', 'Page');
$test_meta[200] = ['_link_url' => ['https://evil.example']];

$test_posts[201] = sfx_make_post(201, 'sfx_social_account', 'draft', 'Draft');
$test_meta[201] = ['_link_url' => ['https://draft.example']];

$test_posts[99] = sfx_make_post(99, 'sfx_contact_info', 'publish', 'HQ');
$test_meta[99] = [
    '_email' => ['billing@example.test'],
];
