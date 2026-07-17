<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Three predicates, split by what each one depends on:
 *
 *   is_protection_enabled()  one option        callable anywhere, incl. init
 *   is_visitor_exempt()      options + user    callable after init
 *   is_active()              + query state     callable only after `wp`
 *
 * Collapsing them into one predicate is what an earlier draft did, and it was
 * wrong twice: it let a per-visitor exemption disable cache protection, and it
 * called is_feed() before the query existed. See the spec.
 */
class Controller
{
    /**
     * `status` and nothing else.
     *
     * It deliberately does NOT also require a non-empty hash. Requiring one
     * inverts the failure mode: a corrupted status=on/password='' would make
     * this false, switching protection OFF and serving the site to the world.
     * The broken state is handled by is_configuration_broken() + behaviour.
     */
    public static function is_protection_enabled(): bool
    {
        return Settings::get()['status'];
    }

    /**
     * Status on with a normalized-empty hash. Settings::get() forces every
     * non-string to '', so this one comparison catches null, arrays and a
     * missing key alike.
     *
     * It does not judge whether a non-empty string is a "valid" hash: sniffing
     * for $P$/$wp$/$2y$ would couple this to core's hashing internals. A
     * garbage non-empty hash fails closed silently — wp_check_password() never
     * matches it — it just gets no notice.
     */
    public static function is_configuration_broken(): bool
    {
        $settings = Settings::get();

        return $settings['status'] && $settings['password'] === '';
    }

    public static function is_visitor_exempt(): bool
    {
        $settings = Settings::get();

        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        if ($ip !== '' && in_array($ip, Settings::allowed_ips(), true)) {
            return true;
        }

        if ($settings['allow_admins'] && current_user_can('manage_options')) {
            return true;
        }

        if ($settings['allow_users'] && is_user_logged_in()) {
            return true;
        }

        return false;
    }

    /**
     * Must not be called before `wp`: is_feed() and is_robots() are only
     * reliable once the main query is parsed. REST must not call this at all —
     * rest_authentication_errors fires at parse_request, before the query.
     */
    public static function is_active(): bool
    {
        $active = self::is_protection_enabled() && !self::is_visitor_exempt();

        if ($active && is_robots()) {
            $active = false;
        }

        if ($active && Settings::get()['allow_feeds'] && is_feed()) {
            $active = false;
        }

        return (bool) apply_filters('sfx_pp_is_active', $active);
    }

    /**
     * @param mixed $submitted Raw request value; may be anything.
     */
    public static function bypass_key_matches($submitted): bool
    {
        $stored = Settings::get()['bypass_key'];

        // The empty-stored guard is not padding: without it an empty stored key
        // plus ?sfx_bypass= compares '' to '' and lets the world in.
        if ($stored === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }
}
