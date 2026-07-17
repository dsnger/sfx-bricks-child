<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Cookie handling, mirroring WordPress core's own auth cookie scheme.
 *
 * Two revocation levers are folded into the signing key:
 *   - password hash  → changing the password logs EVERYONE out (both flavors).
 *   - bypass secret  → mixed into 'bp' cookies only, so the "lock out previous
 *                      visitors" action ends bypass sessions without touching
 *                      password sessions or forcing a password change.
 *
 * The bypass secret is deliberately SEPARATE from the shareable key: generating
 * a new link (rotating the key) changes only the link, not the secret, so
 * existing bypass visitors stay put unless you explicitly lock them out.
 *
 * The flavor ('pw' | 'bp') is part of what is signed, so a bypass cookie cannot
 * be downgraded to a password one to dodge the secret binding.
 */
class Auth
{
    private const SESSION_WINDOW = 20 * DAY_IN_SECONDS;

    public const FLAVOR_PASSWORD = 'pw';
    public const FLAVOR_BYPASS   = 'bp';

    public static function site_id(): string
    {
        $blog_id = $GLOBALS['blog_id'] ?? 1;

        return 'bid_' . $blog_id;
    }

    public static function cookie_name(): string
    {
        return 'sfx_pp_' . COOKIEHASH;
    }

    public static function generate_cookie(
        int $expiration,
        string $flavor,
        string $password_hash,
        string $bypass_secret
    ): string {
        $site_id  = self::site_id();
        $material = $site_id . '|' . $password_hash . '|' . $expiration;

        // Only bypass cookies fold in the bypass secret, so "lock out previous
        // visitors" (which bumps that secret) ends bypass sessions while
        // password sessions — which never include it — stay valid. The
        // shareable key is NOT in here: a new link and revoking old access are
        // two separate choices.
        if ($flavor === self::FLAVOR_BYPASS) {
            $material .= '|' . $bypass_secret;
        }

        $key  = wp_hash($material, 'auth');
        $hmac = hash_hmac('sha256', $site_id . '|' . $expiration . '|' . $flavor, $key);

        return $site_id . '|' . $expiration . '|' . $flavor . '|' . $hmac;
    }

    /**
     * @param mixed $cookie Raw cookie value; may be anything a client sent.
     *                      null means "read it from $_COOKIE".
     */
    public static function validate_cookie($cookie = null): bool
    {
        if ($cookie === null) {
            $cookie = $_COOKIE[self::cookie_name()] ?? null;
        }

        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 4) {
            return false;
        }

        [$site_id, $expiration, $flavor, $hmac] = $parts;

        if (!hash_equals(self::site_id(), $site_id)) {
            return false;
        }

        if ($flavor !== self::FLAVOR_PASSWORD && $flavor !== self::FLAVOR_BYPASS) {
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

        $settings = Settings::get();
        $expected = self::generate_cookie(
            (int) $expiration,
            $flavor,
            (string) $settings['password'],
            (string) $settings['bypass_session_secret']
        );

        return hash_equals($expected, $cookie);
    }

    public static function set_cookie(bool $remember, string $flavor): void
    {
        $settings = Settings::get();

        if ($remember) {
            $expiration = time() + ($settings['remember_me_lifetime'] * DAY_IN_SECONDS);
            $expire     = $expiration;
        } else {
            $expiration = time() + self::SESSION_WINDOW;
            $expire     = 0; // Browser session cookie.
        }

        $cookie = self::generate_cookie(
            $expiration,
            $flavor,
            (string) $settings['password'],
            (string) $settings['bypass_session_secret']
        );

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

        // Load-bearing for the current request, not just the browser's next
        // one: setcookie() does not touch $_COOKIE, so without this a wrong-
        // password login POST would clear the cookie header-wise while the
        // stale value stayed readable in $_COOKIE. maybe_show_login(), called
        // later in the same request, would then re-validate that stale value
        // against the unchanged hash and render the protected page anyway.
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
