<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The module's settings option (`sfx_site_check_settings`). Plan 1 owns four
 * keys; the monitor keys of plan 2 live in the same row and are never touched
 * here — every write reads the stored row, replaces only the keys it owns and
 * writes the whole row back inside the Mutex.
 *
 * Only `indexability_paths` and `sitemap_allow` are exported (ImportExport);
 * the profile and the fallback theme are site-local.
 */
final class Settings
{
    public const PROFILES = ['live', 'staging', 'private'];
    public const MAX_PATHS = 5;

    private const KEYS = ['profile', 'indexability_paths', 'sitemap_allow', 'fallback_theme'];
    private const MAX_PATH_LENGTH = 200;
    /** attachments, author sitemap, or a post type / taxonomy slug. */
    private const ENTRY_TYPE = '/^(attachments|authors|post_type:[a-z0-9_-]{1,20}|taxonomy:[a-z0-9_-]{1,32})$/';
    private const THEME_SLUG = '/^[A-Za-z0-9._-]{1,100}$/';

    public static function defaults(): array
    {
        return ['profile' => 'live', 'indexability_paths' => [], 'sitemap_allow' => [], 'fallback_theme' => ''];
    }

    /**
     * Always every key, typed. Stored paths are NOT filtered for validity: an
     * invalid stored path is shown as rejected by the page and skipped by the
     * run (spec "Admin-chosen paths").
     */
    public static function get(): array
    {
        $stored = self::stored();
        $values = self::defaults();

        if (isset($stored['profile']) && is_string($stored['profile']) && in_array($stored['profile'], self::PROFILES, true)) {
            $values['profile'] = $stored['profile'];
        }
        if (isset($stored['indexability_paths']) && is_array($stored['indexability_paths'])) {
            $values['indexability_paths'] = array_slice(
                array_values(array_filter($stored['indexability_paths'], 'is_string')),
                0,
                self::MAX_PATHS
            );
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

    /** Site-relative: starts with one `/`, no host, query, fragment, `..` or `.php`. */
    public static function valid_path(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || $path[0] !== '/' || ($path[1] ?? '') === '/') {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7f?#\\\\]/', $path) === 1) {
            return false;
        }
        // Encoded dots count too: %2e%2e is `..`.
        $decoded = strtolower(rawurldecode($path));

        // Rule 6: every PHP-like extension, anywhere in the path.
        return !str_contains($decoded, '..') && preg_match(Checks\FileChecks::PHP_LIKE, $decoded) !== 1;
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
        if (array_key_exists('indexability_paths', $raw)) {
            $out['indexability_paths'] = self::clean_paths($raw['indexability_paths']);
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
     * ImportExport's callback for the `site_check` group. Touches only the two
     * exported keys; a field of the wrong type is left alone.
     *
     * @param array  $values UNTRUSTED, the group's slice of the import file
     * @param string $mode   'merge' or 'replace'
     *
     * @return array{status:string, message:string}
     */
    public static function import_fields(array $values, string $mode): array
    {
        $changes = [];
        foreach (['indexability_paths', 'sitemap_allow'] as $key) {
            if (isset($values[$key]) && is_array($values[$key])) {
                $changes[$key] = $values[$key];
            }
        }

        if ($changes === []) {
            return ['status' => 'error', 'message' => __('Security check: nothing to import (the fields are missing or malformed).', 'sfxtheme')];
        }

        $merge = $mode === 'merge';
        $invalid = 0;
        $kept = 0;
        $full = 0;
        $outcome = Mutex::with(static function () use ($changes, $merge, &$invalid, &$kept, &$full) {
            $stored = self::stored();
            foreach (array_keys(self::sanitize($changes)) as $key) {
                // Validated without the cap, so "invalid" and "did not fit" are counted apart.
                $incoming = $key === 'indexability_paths' ? self::clean_paths($changes[$key], false) : self::clean_entry_types($changes[$key]);
                $invalid += self::count_invalid($key, $changes[$key]);
                $current = $merge && isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : [];
                $stored[$key] = $merge
                    ? self::merge_list($key, $current, $incoming)
                    : ($key === 'indexability_paths' ? array_slice($incoming, 0, self::MAX_PATHS) : $incoming);
                // Counted after merge and the cap: stored now and not there before; the rest of the new ones did not fit.
                $before = $merge ? $current : [];
                $new = array_diff($incoming, $before);
                $stored_new = count(array_intersect($new, $stored[$key]));
                $kept += $stored_new;
                $full += count($new) - $stored_new;
            }

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
        if ($full > 0) {
            $message = ($message !== '' ? $message : sprintf(
                /* translators: %d: number of entries imported */
                __('Security check: %d entries imported.', 'sfxtheme'),
                $kept
            )) . ' ' . sprintf(
                /* translators: 1: number of entries, 2: maximum number of paths */
                __('%1$d not stored: the list holds at most %2$d paths.', 'sfxtheme'),
                $full,
                self::MAX_PATHS
            );
        }
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
    private static function count_invalid(string $key, array $values): int
    {
        $invalid = 0;
        foreach ($values as $value) {
            $ok = is_string($value) && ($key === 'indexability_paths' ? self::valid_path(trim($value)) : preg_match(self::ENTRY_TYPE, $value) === 1);
            $invalid += $ok ? 0 : 1;
        }

        return $invalid;
    }

    /** @param mixed $paths */
    private static function clean_paths($paths, bool $cap = true): array
    {
        if (!is_array($paths)) {
            return [];
        }
        $clean = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if (self::valid_path($path) && !in_array($path, $clean, true)) {
                $clean[] = $path;
            }
        }

        return $cap ? array_slice($clean, 0, self::MAX_PATHS) : $clean;
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
    private static function merge_list(string $key, array $current, array $incoming): array
    {
        $merged = array_values(array_unique(array_merge(
            $key === 'indexability_paths' ? self::clean_paths($current) : self::clean_entry_types($current),
            $incoming
        )));

        return $key === 'indexability_paths' ? array_slice($merged, 0, self::MAX_PATHS) : $merged;
    }
}
