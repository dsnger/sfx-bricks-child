<?php

declare(strict_types=1);

/**
 * Stubs for the MediaCredits suite.
 *
 * Global-namespace WordPress doubles, mutable fixture state and the assertion
 * helpers. Bricks doubles live in a sibling file because PHP forbids a
 * bracketed namespace block alongside non-namespaced code.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

$failures = 0;

// ------------------------------------------------------------- assertions

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
    assert_true(
        $expected === $actual,
        "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, "{$message} (needle '{$needle}' not found)");
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, "{$message} (needle '{$needle}' unexpectedly found)");
}

// ---------------------------------------------------------- fixture state

$test_options        = [];   // option name => value
$test_post_meta      = [];   // post id => [meta key => value]
$test_attachment_url = [];   // attachment id => url|false
$test_attachment_img = [];   // attachment id => image url|false
$test_is_image       = [];   // attachment id => bool
$test_filter_returns = [];   // filter name => callable(mixed $value, array $args): mixed
$test_filters        = [];   // hook name => list of registrations
$test_is_admin       = true; // is_admin() return value

// ------------------------------------------------------ WordPress doubles

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/**
 * Note the fourth argument. WordPress runs esc_html() through
 * _wp_specialchars() with $double_encode = false (formatting.php:945), so an
 * entity already in the string survives untouched. PHP's htmlspecialchars()
 * defaults the other way, and a stub that double-encodes would quietly turn
 * our brace entities into &amp;#123; and let a wrong escaping order pass.
 */
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false);
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false);
}

function esc_url($url)
{
    return str_replace(['"', '<', '>'], '', (string) $url);
}

function wp_kses_post($content)
{
    // Enough for our contract: strip script tags, leave everything else,
    // and in particular leave braces alone — the real wp_kses_post() does
    // not touch them either, which is exactly why escape_braces() exists.
    return preg_replace('#<script.*?</script>#is', '', (string) $content);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function is_admin(): bool
{
    global $test_is_admin;

    return $test_is_admin;
}

/**
 * A plain passthrough for the string case is honest here: filter_query() and
 * filter_dropdown() only ever hand this a $_GET scalar, never the nested
 * array that the real wp_unslash() also has to walk via stripslashes_deep().
 * Stubbing that recursive walk would only be weight nothing here exercises.
 */
function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function absint($value)
{
    return abs((int) $value);
}

function get_option($name, $default = false)
{
    global $test_options;

    return $test_options[$name] ?? $default;
}

function update_option($name, $value)
{
    global $test_options;

    $test_options[$name] = $value;

    return true;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $test_post_meta;

    $value = $test_post_meta[$post_id][$key] ?? '';

    return $single ? $value : [$value];
}

function update_post_meta($post_id, $key, $value)
{
    global $test_post_meta;

    $test_post_meta[$post_id][$key] = $value;

    return true;
}

function wp_get_attachment_url($id)
{
    global $test_attachment_url;

    return $test_attachment_url[$id] ?? false;
}

function wp_get_attachment_image_url($id, $size = 'thumbnail')
{
    global $test_attachment_img;

    return $test_attachment_img[$id] ?? false;
}

function wp_attachment_is_image($id)
{
    global $test_is_image;

    return $test_is_image[$id] ?? false;
}

function apply_filters($hook, $value, ...$args)
{
    global $test_filter_returns;

    if (isset($test_filter_returns[$hook])) {
        return ($test_filter_returns[$hook])($value, $args);
    }

    return $value;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_filters;

    $test_filters[$hook][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];

    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

/** Registrations recorded for one hook. */
function test_registrations(string $hook): array
{
    global $test_filters;

    return $test_filters[$hook] ?? [];
}

function test_reset(): void
{
    global $test_options, $test_post_meta, $test_attachment_url, $test_attachment_img,
           $test_is_image, $test_filter_returns, $test_filters, $test_is_admin;

    $test_options        = [];
    $test_post_meta      = [];
    $test_attachment_url = [];
    $test_attachment_img = [];
    $test_is_image       = [];
    $test_filter_returns = [];
    $test_filters        = [];
    $test_is_admin       = true;
}

/**
 * A minimal WP_Query double for filter_query()'s scope-guard tests.
 *
 * Just get()/set()/is_main_query(), backed by a plain array, so a test can
 * drive every branch of the guard chain and inspect what filter_query()
 * did (or, correctly, did not) call set() with.
 */
class Test_WP_Query
{
    /** @var array<string, mixed> */
    private array $data;

    private bool $is_main_query;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [], bool $is_main_query = true)
    {
        $this->data          = $data;
        $this->is_main_query = $is_main_query;
    }

    public function is_main_query(): bool
    {
        return $this->is_main_query;
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? '';
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }
}
