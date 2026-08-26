<?php

declare(strict_types=1);

/**
 * Stubs for the DataPurge suite.
 *
 * WordPress doubles plus the assertion helpers, in the global namespace so
 * SFX\DataPurge resolves them the way it would in a real request.
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

// ---------------------------------------------------------- fixture state

/** option name => stored value. Doubles as the "what still exists" record. */
$test_options = [];

/** meta keys deleted through delete_post_meta_by_key(), in call order. */
$test_meta_deleted = [];

/** SQL statements the $wpdb double received. */
$test_queries = [];

function test_reset(): void
{
    global $test_options, $test_meta_deleted, $test_queries;

    $test_options      = [];
    $test_meta_deleted = [];
    $test_queries      = [];
}

// ------------------------------------------------------ WordPress doubles

function get_option(string $name, mixed $default = false): mixed
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function delete_option(string $name): bool
{
    global $test_options;

    if (!array_key_exists($name, $test_options)) {
        return false;
    }

    unset($test_options[$name]);

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    global $test_meta_deleted;

    $test_meta_deleted[] = $key;

    return true;
}

function get_stylesheet_directory(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Enough of wpdb to record what the purge would run. prepare() substitutes
 * %s the way core does — single-quoted — so a test can assert on the final
 * statement rather than on the call shape.
 */
class Test_WPDB
{
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';

    public function prepare(string $sql, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . (string) $arg . "'", $sql, 1);
        }

        return $sql;
    }

    /** Rows each query() call should claim to have deleted. */
    public int $rows_affected = 0;

    public function query(string $sql): int
    {
        global $test_queries;

        $test_queries[] = $sql;

        return $this->rows_affected;
    }

    /** Core escapes the LIKE wildcards % and _; so does this. */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
}

$GLOBALS['wpdb'] = new Test_WPDB();
