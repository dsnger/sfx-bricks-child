<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The module's critical section (spec "Storage" → Writes).
 *
 * The mutex is one option row holding `<token>:<taken-at>`. It is taken with
 * a plain INSERT IGNORE rather than add_option(): add_option() checks first
 * and then runs INSERT … ON DUPLICATE KEY UPDATE, so two processes can both
 * "add" it and both believe they hold it. INSERT IGNORE fails atomically
 * when the row exists, which is the "fails if present" the spec relies on.
 *
 * A lock older than 30 s is stale and is taken over with a conditional
 * UPDATE on its exact old value. A takeover must not let the old owner
 * write afterwards, so every protected write and delete is a single SQL
 * statement that only touches the target row while the mutex row still holds
 * the caller's own value. Module code must write its options only through
 * write() and delete() — update_option() is unfenced.
 */
final class Mutex
{
    /** Returned by with() when the section could not be entered within 5 s. */
    public const Busy = "\0sfx_site_check:busy";

    private const STALE_SECONDS = 30;
    private const WAIT_SECONDS = 5;
    private const RETRY_MICROSECONDS = 100000;

    /** This process's mutex row value while it is inside the section. */
    private static ?string $held = null;

    /** Whether the last with() could not release its row (a database error, not a takeover). */
    private static bool $release_failed = false;

    /** True when the last with() left its own mutex row behind because the delete failed. */
    public static function release_failed(): bool
    {
        return self::$release_failed;
    }

    /**
     * Runs $fn inside the critical section and returns its result, or
     * Mutex::Busy. A nested call runs inside the section already held.
     *
     * @return mixed
     */
    public static function with(callable $fn)
    {
        if (self::$held !== null) {
            return $fn();
        }

        $value = self::acquire();
        if ($value === null) {
            return self::Busy;
        }

        self::$held = $value;
        self::$release_failed = false;
        try {
            // Another process may have written since this one cached an option.
            foreach (Options::KEYS as $key) {
                self::forget(Options::name($key));
            }
            return $fn();
        } finally {
            self::$release_failed = !self::release($value);
            self::$held = null;
        }
    }

    /**
     * Creates or updates $option, leaving it autoload off even when the row
     * existed with autoload on — only while this process
     * still holds the mutex. False means refused (or a database error).
     *
     * @param mixed $value
     */
    public static function write(string $option, $value): bool
    {
        if (self::$held === null) {
            return false;
        }

        global $wpdb;
        $serialized = maybe_serialize($value);
        $autoload = function_exists('wp_determine_option_autoload_value')
            ? wp_determine_option_autoload_value($option, $value, $serialized, false)
            : 'no';
        $mutex = Options::name('mutex');

        $rows = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)"
            . " SELECT %s, %s, %s FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s"
            . ' ON DUPLICATE KEY UPDATE option_value = %s, autoload = %s',
            $option,
            $serialized,
            $autoload,
            $mutex,
            self::$held,
            $serialized,
            $autoload
        ));
        if ($rows === 0) {
            // MySQL reports 0 rows both for "refused" and for "value unchanged".
            $rows = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} o JOIN {$wpdb->options} m"
                . ' ON m.option_name = %s AND m.option_value = %s'
                . ' WHERE o.option_name = %s AND o.option_value = %s',
                $mutex,
                self::$held,
                $option,
                $serialized
            ));
        }
        self::forget($option);

        return is_int($rows) && $rows > 0;
    }

    /**
     * Deletes $option — only while this process still holds the mutex. An
     * option that is already absent counts as deleted.
     */
    public static function delete(string $option): bool
    {
        if (self::$held === null) {
            return false;
        }

        global $wpdb;
        $mutex = Options::name('mutex');
        $rows = $wpdb->query($wpdb->prepare(
            "DELETE o FROM {$wpdb->options} o JOIN {$wpdb->options} m"
            . ' ON m.option_name = %s AND m.option_value = %s'
            . ' WHERE o.option_name = %s',
            $mutex,
            self::$held,
            $option
        ));
        if ($rows === 0) {
            $rows = self::still_held() ? 1 : 0;
        }
        self::forget($option);

        return is_int($rows) && $rows > 0;
    }

    /** The mutex row value on success, null after 5 s. */
    private static function acquire(): ?string
    {
        global $wpdb;
        $mutex = Options::name('mutex');
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (true) {
            $mine = bin2hex(random_bytes(16)) . ':' . time();
            $autoload = function_exists('wp_determine_option_autoload_value')
                ? wp_determine_option_autoload_value($mutex, $mine, $mine, false)
                : 'no';
            $taken = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                $mutex,
                $mine,
                $autoload
            ));
            if ($taken === 1) {
                return $mine;
            }

            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $mutex
            ));
            if (is_string($current) && self::is_stale($current)) {
                $took_over = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $mine,
                    $mutex,
                    $current
                ));
                if ($took_over === 1) {
                    return $mine;
                }
            }

            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(self::RETRY_MICROSECONDS);
        }
    }

    /**
     * Removes the mutex row only if it is still this owner's. False only on a
     * database error; 0 rows (taken over meanwhile) is not this owner's row.
     */
    private static function release(string $value): bool
    {
        global $wpdb;
        return false !== $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            Options::name('mutex'),
            $value
        ));
    }

    private static function still_held(): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            Options::name('mutex'),
            self::$held
        )) === 1;
    }

    /** A value without a readable time counts as stale. */
    private static function is_stale(string $value): bool
    {
        $pos = strrpos($value, ':');
        $taken_at = $pos === false ? 0 : (int) substr($value, $pos + 1);
        return time() - $taken_at > self::STALE_SECONDS;
    }

    /** Drops an option from the object cache so the next get_option() reads the row. */
    private static function forget(string $option): void
    {
        wp_cache_delete($option, 'options');
        foreach (['notoptions', 'alloptions'] as $list) {
            $cached = wp_cache_get($list, 'options');
            if (is_array($cached) && array_key_exists($option, $cached)) {
                unset($cached[$option]);
                wp_cache_set($list, $cached, 'options');
            }
        }
    }
}
