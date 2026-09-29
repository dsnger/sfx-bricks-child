<?php

declare(strict_types=1);

namespace SFX\Redirects;

/**
 * All module settings live in ONE array option, saved through our own
 * admin-post handler — PasswordProtected's pattern, so one save is one atomic
 * row write and the gate is ours (AdminPage::guard), not options.php's
 * manage_options.
 */
final class Settings
{
    public const OPTION_NAME = 'sfx_redirects_options';

    /** Seconds a browser may keep a 301/308 (spec Addendum A2); 0 = send no cache header. */
    public const PERMANENT_CACHE_CHOICES = [3600, 86400, 604800, 0];

    private const TYPES = [
        'log_404'            => 'bool',
        'log_referrer'       => 'bool',
        'log_retention_days' => 'int',
        'log_max_rows'       => 'int',
        'auto_slug_redirect' => 'bool',
        'permanent_cache'    => 'choice',
    ];

    /**
     * Inclusive ranges. The ceilings bound what the 404 cleanup has to keep and
     * delete; the floors keep "0 days" / "0 rows" from meaning "log nothing",
     * which is what the log_404 switch is for.
     */
    private const RANGES = [
        'log_retention_days' => [1, 365],
        'log_max_rows'       => [100, 50000],
    ];

    public static function defaults(): array
    {
        return [
            'log_404'            => true,
            'log_referrer'       => true,
            'log_retention_days' => 30,
            'log_max_rows'       => 5000,
            'auto_slug_redirect' => true,
            'permanent_cache'    => 3600,
        ];
    }

    /** @return array<int,string> choice => label, in PERMANENT_CACHE_CHOICES order */
    public static function permanent_cache_labels(): array
    {
        return [
            3600   => __('1 hour', 'sfxtheme'),
            86400  => __('1 day', 'sfxtheme'),
            604800 => __('1 week', 'sfxtheme'),
            0      => __('No cache header from this module', 'sfxtheme'),
        ];
    }

    /**
     * Always every key, typed and in range. The stored value can come from paths
     * that never saw validate_snapshot() — the ImportExport JSON import writes
     * options through its own generic sanitiser, or a hand-edited database — and
     * the cleanup must never run with a negative or absurd limit.
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $values = self::defaults();
        foreach ($values as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $values[$key] = match (self::TYPES[$key]) {
                'bool'   => self::stored_bool($stored[$key], $default),
                'choice' => self::stored_choice($stored[$key], $default),
                default  => self::stored_int($key, $stored[$key], $default),
            };
        }

        return $values;
    }

    public static function register(): void
    {
        add_action('admin_post_sfx_redirects_save_settings', [self::class, 'save_from_request']);
    }

    /**
     * @param array $post     UNTRUSTED request fields, already unslashed by the caller.
     * @param array $existing TRUSTED stored values; the fallback for an unusable number.
     *
     * @return array{values: array, errors: list<string>}
     */
    public static function validate_snapshot(array $post, array $existing): array
    {
        $existing = array_merge(self::defaults(), array_intersect_key($existing, self::defaults()));
        $values = [];
        $errors = [];

        foreach (self::TYPES as $key => $type) {
            if ($type === 'bool') {
                // NOT !empty(): !empty(['x']) is true, so ?log_404[]=x would read as checked.
                $values[$key] = isset($post[$key]) && is_scalar($post[$key]) && (bool) $post[$key];
                continue;
            }

            if ($type === 'choice') {
                $stored = self::stored_choice($existing[$key], self::defaults()[$key]);
                // Absent is not a value (a form without the field): keep the stored one silently.
                if (!array_key_exists($key, $post)) {
                    $values[$key] = $stored;
                    continue;
                }
                $chosen = self::stored_choice(is_string($post[$key]) ? trim($post[$key]) : null, -1);
                $values[$key] = $chosen === -1 ? $stored : $chosen;
                if ($chosen === -1) {
                    $errors[] = __('The browser cache time for permanent redirects must be one of the offered values. The previous value was kept.', 'sfxtheme');
                }
                continue;
            }

            [$min, $max] = self::RANGES[$key];
            $raw = isset($post[$key]) && is_string($post[$key]) ? trim($post[$key]) : '';

            if (preg_match('/\A-?\d+\z/', $raw) !== 1) {
                // Nothing usable was typed: keep what is stored rather than invent a value.
                $values[$key] = self::stored_int($key, $existing[$key], self::defaults()[$key]);
                $errors[] = sprintf(
                    /* translators: 1: field label, 2: minimum, 3: maximum */
                    __('%1$s must be a whole number between %2$d and %3$d. The previous value was kept.', 'sfxtheme'),
                    self::label($key),
                    $min,
                    $max
                );
                continue;
            }

            $clamped = self::clamp((float) $raw, $min, $max);
            $values[$key] = $clamped;
            // Through float, not (int): (int) of a 30-digit string saturates
            // silently, a float stays comparable and lands above the ceiling.
            if ((float) $raw !== (float) $clamped) {
                $errors[] = sprintf(
                    /* translators: 1: field label, 2: minimum, 3: maximum, 4: value used */
                    __('%1$s must be between %2$d and %3$d. It was set to %4$d.', 'sfxtheme'),
                    self::label($key),
                    $min,
                    $max,
                    $clamped
                );
            }
        }

        // Canonical key order: save_from_request() detects a failed write with
        // get() !== values, and PHP's === on arrays is order-sensitive.
        $values = array_replace(self::defaults(), $values);

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Post/Redirect/Get through AdminPage::finish(): notices travel in the
     * module's per-user transient and are escaped where AdminPage prints them.
     */
    public static function save_from_request(): void
    {
        // Nonce + capability + theme-settings access, before any input is read.
        AdminPage::guard('sfx_redirects_save_settings', true);

        // A form save only: a GET carrying the nonce would read an empty body and
        // switch every checkbox off.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('This action requires a form submission.', 'sfxtheme'), '', ['response' => 405]);
        }

        // Same refusal as every other handler while the tables are missing
        // (spec: schema readiness everywhere).
        if (!Repository::ready()) {
            add_settings_error('sfx_redirects', 'sfx_redirects_settings_not_ready', __('The redirect database tables are not installed yet. Open this page again to install them, then retry.', 'sfxtheme'), 'error');
            AdminPage::finish('settings');
        }

        $result = self::validate_snapshot(wp_unslash($_POST), self::get());

        update_option(self::OPTION_NAME, $result['values']);

        // update_option() returns false both for "nothing changed" and for "the
        // write failed". Read back instead: a failed write leaves get() unchanged.
        $write_failed = self::get() !== $result['values'];

        foreach ($result['errors'] as $i => $message) {
            add_settings_error('sfx_redirects', 'sfx_redirects_settings_' . $i, $message, 'error');
        }

        if ($write_failed) {
            add_settings_error(
                'sfx_redirects',
                'sfx_redirects_settings_write_failed',
                __('Settings could NOT be saved — the database write failed. Nothing was changed.', 'sfxtheme'),
                'error'
            );
        } else {
            // Never an unqualified success next to a red error.
            add_settings_error(
                'sfx_redirects',
                'sfx_redirects_settings_saved',
                $result['errors'] === []
                    ? __('Settings saved.', 'sfxtheme')
                    : __('Settings saved, but some fields were corrected.', 'sfxtheme'),
                $result['errors'] === [] ? 'success' : 'warning'
            );
        }

        // The module's own escaped notice channel, never core's global one.
        AdminPage::finish('settings');
    }

    private static function label(string $key): string
    {
        return $key === 'log_retention_days'
            ? __('Log retention (days)', 'sfxtheme')
            : __('Maximum log entries', 'sfxtheme');
    }

    /**
     * Same reading as PasswordProtected's cast(): any scalar is truthy/falsy,
     * anything else (array, null) is not a checkbox value at all.
     */
    private static function stored_bool($value, bool $default): bool
    {
        return is_scalar($value) ? (bool) $value : $default;
    }

    /**
     * Only an int or a plain digit string naming one of the choices; anything
     * else — another number, a float, whitespace, a bool — is not a choice.
     */
    private static function stored_choice($value, int $default): int
    {
        if (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && in_array($value, self::PERMANENT_CACHE_CHOICES, true) ? $value : $default;
    }

    /**
     * Numbers (int, float, numeric string) are clamped; anything else — bool,
     * array, null, "lots" — is the wrong type and yields the default. Floats
     * are clamped before the int cast: casting INF or 1e300 to int is undefined
     * in spirit and a warning in recent PHP.
     */
    private static function stored_int(string $key, $value, int $default): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $default;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return $default;
        }

        [$min, $max] = self::RANGES[$key];

        return self::clamp($number, $min, $max);
    }

    private static function clamp(float $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }

        return $value > $max ? $max : (int) $value;
    }
}
