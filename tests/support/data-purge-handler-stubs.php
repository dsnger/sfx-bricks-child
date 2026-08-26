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

function test_gates_reset(): void
{
    global $test_nonce_valid, $test_theme_access, $test_can_manage_options,
           $test_deleted_options, $test_deleted_meta;

    $test_nonce_valid        = true;
    $test_theme_access       = true;
    $test_can_manage_options = true;
    $test_deleted_options    = 0;
    $test_deleted_meta       = 0;

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

    public function prepare(string $sql, mixed ...$args): string
    {
        return $sql;
    }

    public function query(string $sql): int
    {
        return 0;
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
