<?php

declare(strict_types=1);

/**
 * Stubs for the DataPurge handler suite.
 *
 * Every exit path of the handler — wp_die() and wp_safe_redirect()+exit —
 * throws Stopped instead, so a test can see WHERE it stopped rather than
 * losing the process. Each gate is a global a test flips.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'test_db');
}

$failures = 0;

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

/** Raised where production code would end the request. */
class Stopped extends \Exception
{
}

// ------------------------------------------------------------ gate state

$test_nonce_valid        = true;
$test_theme_access       = true;
$test_can_manage_options = true;
$test_deleted_options    = 0;
$test_deleted_meta       = 0;

/** The Redirects tables that exist, and whether their lock can be taken. */
$test_tables    = [];
$test_lock_free = true;

/** Query args of the last redirect, and the notices the screen raised. */
$test_redirect_args = [];
$test_notices       = [];

function test_gates_reset(): void
{
    global $test_nonce_valid, $test_theme_access, $test_can_manage_options,
           $test_deleted_options, $test_deleted_meta, $test_tables, $test_lock_free,
           $test_redirect_args, $test_notices;

    $test_nonce_valid        = true;
    $test_theme_access       = true;
    $test_can_manage_options = true;
    $test_deleted_options    = 0;
    $test_deleted_meta       = 0;
    $test_tables             = ['wp_sfx_redirects', 'wp_sfx_redirects_404'];
    $test_lock_free          = true;
    $test_redirect_args      = [];
    $test_notices            = [];

    \SFX\SiteCheck\Purge::$report = ['options' => 0, 'probes_failed' => [], 'busy' => false];
    \SFX\SiteCheck\Purge::$calls  = 0;

    $_POST = ['sfx_purge_confirmation' => \SFX\DataPurge::CONFIRMATION_PHRASE];
    $_GET  = [];
}

// ----------------------------------------------------- WordPress doubles

function check_admin_referer(string $action = '', string $query_arg = '_wpnonce'): bool
{
    global $test_nonce_valid;

    if (!$test_nonce_valid) {
        throw new Stopped('nonce');
    }

    return true;
}

function current_user_can(string $capability): bool
{
    global $test_can_manage_options;

    return $capability === 'manage_options' ? (bool) $test_can_manage_options : true;
}

/**
 * The handler reaches wp_die() twice, for two different reasons. The message
 * is not asserted on; the label a test sees comes from the response code the
 * handler chose — 403 for the capability gate, 400 for the phrase.
 */
function wp_die(string $message = '', string $title = '', array $args = []): void
{
    throw new Stopped(($args['response'] ?? 0) === 403 ? 'capability' : 'phrase');
}

function wp_safe_redirect(string $location, int $status = 302): bool
{
    throw new Stopped('redirect');
}

function add_query_arg(mixed $args, string $url = ''): string
{
    global $test_redirect_args;

    $test_redirect_args = $args;

    return $url;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html_e(string $text, string $domain = 'default'): void
{
    echo $text;
}

function esc_html(string $text): string
{
    return $text;
}

function esc_attr(string $text): string
{
    return $text;
}

function esc_url(string $url): string
{
    return $url;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function get_option(string $name, mixed $default = false): mixed
{
    return $default;
}

function wp_nonce_field(string $action = '-1'): string
{
    return '';
}

/** The screen's result notice, captured rather than printed. */
function wp_admin_notice(string $message, array $args = []): void
{
    global $test_notices;

    $test_notices[] = ['message' => $message, 'type' => $args['type'] ?? ''];
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    return 0;
}

function delete_option(string $name): bool
{
    global $test_deleted_options;

    $test_deleted_options++;

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    global $test_deleted_meta;

    $test_deleted_meta++;

    return true;
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return true;
}

class Test_Handler_WPDB
{
    public string $options = 'wp_options';
    public string $last_error = '';
    public string $prefix = 'wp_';

    public function prepare(string $sql, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . (string) $arg . "'", $sql, 1);
        }

        return $sql;
    }

    public function query(string $sql): int
    {
        global $test_tables;

        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m) === 1) {
            $test_tables = array_values(array_diff($test_tables, [$m[1]]));
        }

        return 0;
    }

    /** The lock, the lock re-check, and SHOW TABLES LIKE — nothing more. */
    public function get_var(string $sql): ?string
    {
        global $test_tables, $test_lock_free;

        if (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            return $test_lock_free ? '1' : '0';
        }

        if (strpos($sql, 'SELECT IS_USED_LOCK(') === 0) {
            return '1';
        }

        if (preg_match("/^SHOW TABLES LIKE '(.*)'$/", $sql, $m) === 1) {
            $name = stripcslashes($m[1]);

            return in_array($name, $test_tables, true) ? $name : null;
        }

        return null;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
}

$GLOBALS['wpdb'] = new Test_Handler_WPDB();

// The theme's own access gate lives in a sibling file: PHP forbids a bracketed
// namespace block alongside non-namespaced code.
require_once __DIR__ . '/data-purge-accesscontrol-stub.php';
require_once __DIR__ . '/site-check-purge-stub.php';
