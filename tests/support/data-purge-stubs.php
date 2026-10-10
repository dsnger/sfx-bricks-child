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

if (!defined('DB_NAME')) {
    define('DB_NAME', 'test_db');
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

/** Tables that exist, full names. Empty by default: most cases are not about them. */
$test_tables = [];

/** Whether GET_LOCK succeeds. */
$test_lock_free = true;

/** IS_USED_LOCK checks that still find the lock ours; null = never lost. */
$test_lock_checks_left = null;

/** A DROP that silently leaves the table in place. */
$test_drop_fails = false;

/** Hooks passed to wp_clear_scheduled_hook(). */
$test_cleared_hooks = [];

function test_reset(): void
{
    global $test_options, $test_meta_deleted, $test_queries, $test_tables,
           $test_lock_free, $test_lock_checks_left, $test_drop_fails, $test_cleared_hooks;

    $test_options          = [];
    $test_meta_deleted     = [];
    $test_queries          = [];
    $test_tables           = [];
    $test_lock_free        = true;
    $test_lock_checks_left = null;
    $test_drop_fails       = false;
    $test_cleared_hooks    = [];

    \SFX\SiteCheck\Purge::$report = ['options' => 0, 'probes_failed' => [], 'busy' => false];
    \SFX\SiteCheck\Purge::$calls  = 0;
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

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    global $test_cleared_hooks;

    $test_cleared_hooks[] = $hook;

    return 0;
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
    public string $last_error = '';
    public string $postmeta = 'wp_postmeta';
    public string $prefix = 'wp_';

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
        global $test_queries, $test_tables, $test_drop_fails;

        $test_queries[] = $sql;

        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m) === 1 && !$test_drop_fails) {
            $test_tables = array_values(array_diff($test_tables, [$m[1]]));
        }

        return $this->rows_affected;
    }

    /**
     * Answers the three questions the table drop asks: the lock, whether it
     * is still ours, and whether a table exists. The LIKE pattern is
     * unescaped before the lookup, so a pattern that skipped esc_like() still
     * resolves — Case 7 asserts on the escaping separately.
     */
    public function get_var(string $sql): ?string
    {
        global $test_queries, $test_tables, $test_lock_free, $test_lock_checks_left;

        $test_queries[] = $sql;

        if (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            return $test_lock_free ? '1' : '0';
        }

        if (strpos($sql, 'SELECT IS_USED_LOCK(') === 0) {
            if ($test_lock_checks_left === null) {
                return '1';
            }

            return $test_lock_checks_left-- > 0 ? '1' : '0';
        }

        if (preg_match("/^SHOW TABLES LIKE '(.*)'$/", $sql, $m) === 1) {
            $name = stripcslashes($m[1]);

            return in_array($name, $test_tables, true) ? $name : null;
        }

        return null;
    }

    /** Core escapes the LIKE wildcards % and _; so does this. */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
}

$GLOBALS['wpdb'] = new Test_WPDB();

// PHP forbids a bracketed namespace block alongside non-namespaced code.
require_once __DIR__ . '/site-check-purge-stub.php';
