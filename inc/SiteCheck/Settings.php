<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The module's settings option (`sfx_site_check_settings`). Plan 1 owns three
 * keys; the monitor keys of plan 2 live in the same row and are never touched
 * here — every write reads the stored row, replaces only the keys it owns and
 * writes the whole row back inside the Mutex.
 *
 * Only `sitemap_allow` is exported (ImportExport); the profile and the
 * fallback theme are site-local.
 */
final class Settings
{
    public const PROFILES = ['live', 'staging', 'private'];

    private const KEYS = ['profile', 'sitemap_allow', 'fallback_theme'];
    /** attachments, author sitemap, or a post type / taxonomy slug. */
    private const ENTRY_TYPE = '/^(attachments|authors|post_type:[a-z0-9_-]{1,20}|taxonomy:[a-z0-9_-]{1,32})$/';
    private const THEME_SLUG = '/^[A-Za-z0-9._-]{1,100}$/';

    public static function defaults(): array
    {
        return ['profile' => 'live', 'sitemap_allow' => [], 'fallback_theme' => ''];
    }

    /** Always every key, typed. */
    public static function get(): array
    {
        $stored = self::stored();
        $values = self::defaults();

        if (isset($stored['profile']) && is_string($stored['profile']) && in_array($stored['profile'], self::PROFILES, true)) {
            $values['profile'] = $stored['profile'];
        }
        if (isset($stored['sitemap_allow']) && is_array($stored['sitemap_allow'])) {
            $values['sitemap_allow'] = self::clean_entry_types($stored['sitemap_allow']);
        }
        if (isset($stored['fallback_theme']) && is_string($stored['fallback_theme']) && preg_match(self::THEME_SLUG, $stored['fallback_theme']) === 1) {
            $values['fallback_theme'] = $stored['fallback_theme'];
        }

        return $values;
    }

    /** An allow-list entry: attachments, authors, or a post type / taxonomy slug. */
    public static function is_entry_type(string $type): bool
    {
        return preg_match(self::ENTRY_TYPE, $type) === 1;
    }

    /**
     * Cleans the keys present in $raw and leaves the others out, so a caller
     * can merge the result over a stored row.
     *
     * @param array $raw UNTRUSTED
     */
    public static function sanitize(array $raw): array
    {
        $out = [];

        if (array_key_exists('profile', $raw)) {
            // An invalid submission keeps what is stored (live when nothing valid is): it must not downgrade a stored profile.
            $out['profile'] = is_string($raw['profile']) && in_array($raw['profile'], self::PROFILES, true) ? $raw['profile'] : self::get()['profile'];
        }
        if (array_key_exists('sitemap_allow', $raw)) {
            $out['sitemap_allow'] = self::clean_entry_types($raw['sitemap_allow']);
        }
        if (array_key_exists('fallback_theme', $raw)) {
            $theme = is_string($raw['fallback_theme']) ? trim($raw['fallback_theme']) : '';
            $out['fallback_theme'] = preg_match(self::THEME_SLUG, $theme) === 1 ? $theme : '';
        }

        return $out;
    }

    public static function save(array $changes): bool
    {
        return self::save_status($changes) === 'saved';
    }

    /** @return 'saved'|'busy'|'failed' */
    public static function save_status(array $changes): string
    {
        // Sanitised inside the section: an invalid profile falls back to the row as stored now.
        $status = Mutex::with(static function () use ($changes) {
            return Mutex::write(Options::name('settings'), array_merge(self::stored(), self::sanitize($changes))) ? 'saved' : 'failed';
        });

        return $status === Mutex::Busy ? 'busy' : $status;
    }

    /**
     * ImportExport's callback for the `site_check` group. Reads and touches
     * only `sitemap_allow`; a field of the wrong type is left alone.
     *
     * @param array  $values UNTRUSTED, the group's slice of the import file
     * @param string $mode   'merge' or 'replace'
     *
     * @return array{status:string, message:string}
     */
    public static function import_fields(array $values, string $mode): array
    {
        if (!isset($values['sitemap_allow']) || !is_array($values['sitemap_allow'])) {
            return ['status' => 'error', 'message' => __('Security check: nothing to import (the fields are missing or malformed).', 'sfxtheme')];
        }

        $values = $values['sitemap_allow'];
        $merge = $mode === 'merge';
        $invalid = self::count_invalid($values);
        $kept = 0;
        $outcome = Mutex::with(static function () use ($values, $merge, &$kept) {
            $stored = self::stored();
            $incoming = self::clean_entry_types($values);
            $current = $merge && isset($stored['sitemap_allow']) && is_array($stored['sitemap_allow']) ? $stored['sitemap_allow'] : [];
            $stored['sitemap_allow'] = $merge ? self::merge_list($current, $incoming) : $incoming;
            // Stored now and not there before.
            $kept = count(array_diff($incoming, $merge ? $current : []));

            return Mutex::write(Options::name('settings'), $stored) ? 'saved' : 'failed';
        });

        if ($outcome === Mutex::Busy) {
            return ['status' => 'error', 'message' => __('Security check: busy, please try the import again.', 'sfxtheme')];
        }
        if ($outcome !== 'saved') {
            return ['status' => 'error', 'message' => __('Security check: the settings could not be written.', 'sfxtheme')];
        }

        $message = $invalid > 0
            /* translators: 1: number of entries imported, 2: number of entries rejected */
            ? sprintf(__('Security check: %1$d entries imported, %2$d rejected as invalid.', 'sfxtheme'), $kept, $invalid)
            : '';
        if ($message !== '') {
            return ['status' => 'success', 'message' => $message];
        }

        return [
            'status' => 'success',
            /* translators: %d: number of entries imported */
            'message' => sprintf(__('Security check: %d entries imported.', 'sfxtheme'), $kept),
        ];
    }

    /** The stored row as an array, whatever is in the database. */
    private static function stored(): array
    {
        $stored = get_option(Options::name('settings'), []);

        return is_array($stored) ? $stored : [];
    }

    /** Entries of an imported list that are no valid value (duplicates are not invalid). */
    private static function count_invalid(array $values): int
    {
        $invalid = 0;
        foreach ($values as $value) {
            $ok = is_string($value) && preg_match(self::ENTRY_TYPE, $value) === 1;
            $invalid += $ok ? 0 : 1;
        }

        return $invalid;
    }

    /** @param mixed $types */
    private static function clean_entry_types($types): array
    {
        if (!is_array($types)) {
            return [];
        }
        $clean = [];
        foreach ($types as $type) {
            if (is_string($type) && preg_match(self::ENTRY_TYPE, $type) === 1 && !in_array($type, $clean, true)) {
                $clean[] = $type;
            }
        }

        return $clean;
    }

    /** Existing entries first, so an import never reorders what is there. */
    private static function merge_list(array $current, array $incoming): array
    {
        return array_values(array_unique(array_merge(self::clean_entry_types($current), $incoming)));
    }
}
