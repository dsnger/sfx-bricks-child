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
/**
 * hook name => list<array{callback: mixed, priority: int, accepted_args: int}>
 *
 * A bare per-hook counter was not enough: it cannot see a wrong priority — the
 * defect that made render_tag inert behind Bricks' own priority-10 handler —
 * and it cannot see a wrong accepted_args: drop the `, 2` from bricks/query/run
 * and run() fatals on a missing $query while the hook count stays right.
 */
$test_filters                = [];

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

/**
 * Real WP derives ->title/->url/etc. from postmeta here. It does NOT set the
 * context flags — ->current, ->current_item_ancestor and the current-menu-*
 * classes come from _wp_menu_item_classes_by_context(), which only ever runs
 * on the original item. Clearing them here is what makes the production code's
 * "read context off $item, not off the clone" split observable in tests:
 * reading these from the prepared object now yields empty, and fails.
 */
function wp_setup_nav_menu_item($item)
{
    $item->current               = false;
    $item->current_item_ancestor = false;
    $item->classes               = [];

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

/** Records every registration in full, so priority and arity are assertable. */
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

/**
 * Registrations recorded for one hook.
 *
 * @return list<array{callback: mixed, priority: int, accepted_args: int}>
 */
function test_hook_registrations(string $hook): array
{
    global $test_filters;

    return $test_filters[$hook] ?? [];
}

/** How many times one hook was registered. */
function test_hook_count(string $hook): int
{
    return count(test_hook_registrations($hook));
}

/** Registrations across every hook — the once-guard's total. */
function test_hook_total(): int
{
    global $test_filters;

    return array_sum(array_map('count', $test_filters));
}
