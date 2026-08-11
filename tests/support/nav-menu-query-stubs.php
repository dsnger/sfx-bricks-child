<?php

declare(strict_types=1);

/**
 * Stubs for the NavMenuQuery suite.
 *
 * Global-namespace WordPress functions, mutable fixture state, and the
 * assertion helpers. The Bricks doubles live in a sibling file because PHP
 * forbids a bracketed namespace block alongside non-namespaced code.
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

// ---------------------------------------------------------- fixture state

$test_registered_nav_menus   = [];   // slug => label
$test_nav_menu_locations     = [];   // slug => term_id
$test_nav_menus              = [];   // list of objects with ->term_id, ->name
$test_menu_items             = [];   // menu_id => list<WP_Post>|false
$test_classes_by_context_calls = []; // item counts captured per call
$test_current_user_can       = true;
$test_nonce_valid            = true;
$test_filters                = [];   // hook name => registration count

// ----------------------------------------------------------------- WP_Post

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'nav_menu_item';
        public string $title = '';
        public string $url = '';
        public string $menu_item_parent = '0';
        public string $target = '';
        public string $xfn = '';
        public string $description = '';
        public array $classes = [];
        public bool $current = false;
        public bool $current_item_ancestor = false;

        /** @param array<string, mixed> $props */
        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// ----------------------------------------------------------- WP functions

function get_registered_nav_menus(): array
{
    global $test_registered_nav_menus;

    return $test_registered_nav_menus;
}

function get_nav_menu_locations(): array
{
    global $test_nav_menu_locations;

    return $test_nav_menu_locations;
}

function wp_get_nav_menus(): array
{
    global $test_nav_menus;

    return $test_nav_menus;
}

function wp_get_nav_menu_items($menu_id)
{
    global $test_menu_items;

    return $test_menu_items[(int) $menu_id] ?? false;
}

/** Mirrors WP: mutates by reference, and records what it was handed. */
function _wp_menu_item_classes_by_context(&$menu_items): void
{
    global $test_classes_by_context_calls;

    $test_classes_by_context_calls[] = count($menu_items);

    foreach ($menu_items as $item) {
        if ($item->current) {
            $item->classes[] = 'current-menu-item';
        }
        if ($item->current_item_ancestor) {
            $item->classes[] = 'current-menu-ancestor';
        }
    }
}

/** Real WP resolves ->url and ->title here; fixtures already carry both. */
function wp_setup_nav_menu_item($item)
{
    return $item;
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return esc_html(__($text, $domain));
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url)
{
    return str_replace(' ', '%20', (string) $url);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function check_ajax_referer($action, $query_arg = false, $stop = true)
{
    global $test_nonce_valid;

    return $test_nonce_valid;
}

function current_user_can($capability)
{
    global $test_current_user_can;

    return $test_current_user_can;
}

/** wp_send_json_* exit in production; here they unwind so a test can assert. */
class SfxJsonSent extends \Exception
{
    public bool $success;
    public mixed $payload;

    public function __construct(bool $success, mixed $payload)
    {
        parent::__construct('json sent');

        $this->success = $success;
        $this->payload = $payload;
    }
}

function wp_send_json_success($payload = null): void
{
    throw new SfxJsonSent(true, $payload);
}

function wp_send_json_error($payload = null): void
{
    throw new SfxJsonSent(false, $payload);
}

/** Counts registrations per hook so the once-guard can be asserted. */
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_filters;

    $test_filters[$hook] = ($test_filters[$hook] ?? 0) + 1;

    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}
