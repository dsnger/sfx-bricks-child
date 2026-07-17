<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * All settings for this module live in ONE array option.
 *
 * Not register_setting(): options.php writes a group's options one at a time
 * with no transaction, and this module's fields have cross-field rules
 * (status needs a password, bypass needs a key). A single row write is atomic;
 * twelve sequential ones are not. See the spec for the full reasoning.
 */
class Settings
{
    public const OPTION_NAME = 'sfx_password_protected_options';

    private const TYPES = [
        'status'               => 'bool',
        'allow_admins'         => 'bool',
        'allow_users'          => 'bool',
        'allow_feeds'          => 'bool',
        'allow_rest'           => 'bool',
        'password'             => 'string',
        'allowed_ips'          => 'string',
        'allow_remember_me'    => 'bool',
        'remember_me_lifetime' => 'int',
        'bypass_enabled'       => 'bool',
        'bypass_key'           => 'string',
        'bypass_redirect'      => 'string',
    ];

    private const BOOL_FIELDS = [
        'status',
        'allow_admins',
        'allow_users',
        'allow_feeds',
        'allow_rest',
        'allow_remember_me',
        'bypass_enabled',
    ];

    public static function defaults(): array
    {
        return [
            'status'               => false,
            // On by default: the safe setting for whoever flips the switch.
            'allow_admins'         => true,
            'allow_users'          => false,
            'allow_feeds'          => false,
            'allow_rest'           => false,
            'password'             => '',
            'allowed_ips'          => '',
            'allow_remember_me'    => false,
            'remember_me_lifetime' => 14,
            'bypass_enabled'       => false,
            'bypass_key'           => '',
            'bypass_redirect'      => '',
        ];
    }

    /**
     * Always returns every key, fully typed. The stored option may be null, a
     * scalar or an array of junk after an import or a hand-edited database;
     * none of that may reach wp_check_password() as a non-string.
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $values = self::defaults();
        foreach ($values as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = self::cast($key, $stored[$key], $default);
            }
        }

        $values['remember_me_lifetime'] = self::clamp_lifetime_int($values['remember_me_lifetime']);

        return $values;
    }

    public static function get_password_hash(): string
    {
        return self::get()['password'];
    }

    /**
     * @return array<int, string>
     */
    public static function allowed_ips(): array
    {
        $raw = self::get()['allowed_ips'];
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $raw) ?: []),
            static fn(string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
    }

    /**
     * Is this field present AND a string?
     *
     * Distinct from post_string(), which cannot tell an absent field from an
     * empty one — a login handler needs "was a password field submitted at
     * all", not "is it non-empty".
     */
    public static function has_string(array $source, string $key): bool
    {
        return isset($source[$key]) && is_string($source[$key]);
    }

    /**
     * Type guard for a request string. Does NOT unslash — use request_string()
     * when reading a raw superglobal.
     *
     * Anything not a string is treated as absent: `?field[]=x` supplies an
     * array, and handing that to wp_check_password() or hash_equals() is a
     * TypeError, i.e. a fatal on a public endpoint.
     */
    public static function post_string(array $source, string $key): string
    {
        return self::has_string($source, $key) ? $source[$key] : '';
    }

    /**
     * Type guard + unslash, for reading raw $_GET/$_POST/$_REQUEST.
     * WordPress slashes those, so a URL holding a quote arrives backslashed.
     */
    public static function request_string(array $source, string $key): string
    {
        $value = self::post_string($source, $key);

        return $value === '' ? '' : (string) wp_unslash($value);
    }

    /**
     * Type guard for a checkbox.
     *
     * NOT `!empty($source[$key])`: `!empty(['x'])` is true, so `?status[]=x`
     * would read as "checked" and could switch protection on — or, on the
     * public login form, silently upgrade a session cookie to a persistent one.
     */
    public static function post_bool(array $source, string $key): bool
    {
        return isset($source[$key]) && is_scalar($source[$key]) && (bool) $source[$key];
    }

    /**
     * @param array $post     UNTRUSTED request fields, already unslashed by the
     *                        caller. Never holds a hash or a bypass key.
     * @param array $existing TRUSTED stored values. The only source of the hash
     *                        and the key.
     *
     * @return array{values: array, errors: array<string, string>}
     */
    public static function validate_snapshot(array $post, array $existing): array
    {
        $existing = array_merge(self::defaults(), array_intersect_key($existing, self::defaults()));
        $errors = [];
        $values = [];

        foreach (self::BOOL_FIELDS as $key) {
            $values[$key] = self::post_bool($post, $key);
        }

        $new     = self::post_string($post, 'password_new');
        $confirm = self::post_string($post, 'password_confirm');

        if ($new === '') {
            $values['password'] = (string) $existing['password'];
        } elseif ($new !== $confirm) {
            $errors['password'] = __('The two password fields did not match. The password was left unchanged.', 'sfxtheme');
            $values['password'] = (string) $existing['password'];
        } else {
            $values['password'] = wp_hash_password($new);
        }

        // Checked against the hash resulting from THIS snapshot, not the stored
        // one, so "set a password and switch protection on" in one save works.
        if ($values['status'] && $values['password'] === '') {
            $errors['status'] = __('Set a password before switching protection on. Protection has been left off.', 'sfxtheme');
            $values['status'] = false;
        }

        // The key is never read from $post. A stale form would otherwise
        // silently restore a rotated key and revive links already revoked.
        $stored_key = (string) $existing['bypass_key'];
        if (self::post_bool($post, 'bypass_rotate')) {
            $values['bypass_key'] = wp_generate_password(20, false);
        } elseif ($values['bypass_enabled'] && $stored_key === '') {
            $values['bypass_key'] = wp_generate_password(20, false);
        } else {
            $values['bypass_key'] = $stored_key;
        }

        $values['allowed_ips']          = self::normalize_ip_list(self::post_string($post, 'allowed_ips'));
        $values['bypass_redirect']      = esc_url_raw(self::post_string($post, 'bypass_redirect'));
        $values['remember_me_lifetime'] = self::clamp_lifetime_raw(self::post_string($post, 'remember_me_lifetime'));

        // Canonical key order, matching defaults() and therefore get().
        // Not cosmetic: PHP's === on arrays is order-sensitive, and this array
        // is compared against get() to detect a failed write. Built in the
        // order above it would never compare equal, and every successful save
        // would report itself as a database failure.
        $values = array_replace(self::defaults(), $values);

        return ['values' => $values, 'errors' => $errors];
    }

    public static function normalize_ip_list(string $raw): string
    {
        $lines = array_filter(
            array_map('trim', preg_split('/\R/', $raw) ?: []),
            static fn(string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        );

        return implode("\n", $lines);
    }

    private static function cast(string $key, $value, $default)
    {
        switch (self::TYPES[$key]) {
            case 'bool':
                return is_scalar($value) ? (bool) $value : $default;
            case 'int':
                return is_scalar($value) ? (int) $value : $default;
            default:
                return is_string($value) ? $value : $default;
        }
    }

    private static function clamp_lifetime_raw(string $raw): int
    {
        if ($raw === '') {
            return (int) self::defaults()['remember_me_lifetime'];
        }

        return self::clamp_lifetime_int(absint($raw));
    }

    private static function clamp_lifetime_int(int $value): int
    {
        if ($value < 1) {
            return 1;
        }

        return $value > 365 ? 365 : $value;
    }
}
