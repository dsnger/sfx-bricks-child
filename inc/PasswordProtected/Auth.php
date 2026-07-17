<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Cookie handling, mirroring WordPress core's own auth cookie scheme.
 *
 * The stored password hash is baked into the signing key, so changing the
 * password invalidates every outstanding cookie for free. That is also the
 * only revocation this module has — see the spec.
 */
class Auth
{
    private const SESSION_WINDOW = 20 * DAY_IN_SECONDS;

    public static function site_id(): string
    {
        $blog_id = $GLOBALS['blog_id'] ?? 1;

        return 'bid_' . $blog_id;
    }

    public static function cookie_name(): string
    {
        return 'sfx_pp_' . COOKIEHASH;
    }

    public static function generate_cookie(int $expiration, string $password_hash): string
    {
        $site_id = self::site_id();
        $key     = wp_hash($site_id . '|' . $password_hash . '|' . $expiration, 'auth');
        $hmac    = hash_hmac('sha256', $site_id . '|' . $expiration, $key);

        return $site_id . '|' . $expiration . '|' . $hmac;
    }

    /**
     * @param mixed $cookie Raw cookie value; may be anything a client sent.
     *                      null means "read it from $_COOKIE".
     */
    public static function validate_cookie($cookie = null, ?string $password_hash = null): bool
    {
        if ($cookie === null) {
            $cookie = $_COOKIE[self::cookie_name()] ?? null;
        }

        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 3) {
            return false;
        }

        [$site_id, $expiration, $hmac] = $parts;

        if (!hash_equals(self::site_id(), $site_id)) {
            return false;
        }

        // An integer, not "9e99" and not padding.
        if (preg_match('/^\d{1,12}$/', $expiration) !== 1) {
            return false;
        }

        // time() is UTC. The plugin's current_time('timestamp') is timezone-
        // shifted and compares wrongly against a UTC lifetime.
        if ((int) $expiration < time()) {
            return false;
        }

        if ($password_hash === null) {
            $password_hash = Settings::get_password_hash();
        }

        $expected = self::generate_cookie((int) $expiration, $password_hash);

        return hash_equals($expected, $cookie);
    }

    public static function set_cookie(bool $remember): void
    {
        $settings = Settings::get();

        if ($remember) {
            $expiration = time() + ($settings['remember_me_lifetime'] * DAY_IN_SECONDS);
            $expire     = $expiration;
        } else {
            $expiration = time() + self::SESSION_WINDOW;
            $expire     = 0; // Browser session cookie.
        }

        $cookie = self::generate_cookie($expiration, $settings['password']);

        foreach (self::cookie_paths() as $path) {
            setcookie(self::cookie_name(), $cookie, self::cookie_options($expire, $path));
        }
    }

    public static function clear_cookie(): void
    {
        // Identical attributes to set_cookie(), for every path it writes.
        // Mismatched attributes leave a live cookie behind, which is a logout
        // that does not log out.
        foreach (self::cookie_paths() as $path) {
            setcookie(self::cookie_name(), '', self::cookie_options(time() - DAY_IN_SECONDS, $path));
        }

        unset($_COOKIE[self::cookie_name()]);
    }

    /**
     * @return array<int, string>
     */
    private static function cookie_paths(): array
    {
        $paths = [COOKIEPATH];

        if (SITECOOKIEPATH !== COOKIEPATH) {
            $paths[] = SITECOOKIEPATH;
        }

        return $paths;
    }

    private static function cookie_options(int $expires, string $path): array
    {
        return [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            // Lax still sets cookies on top-level cross-site navigation, which
            // is exactly what clicking a bypass link in an email is.
            'samesite' => 'Lax',
        ];
    }
}
