<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Where things are, on disk and on the web (spec "Locations"). Every place is
 * resolved from WordPress, never from fixed paths, and nothing is created:
 * uploads come from wp_get_upload_dir(), which does not make folders.
 */
final class Locations
{
    /** Roots a file URL may be derived from. `config` is a file, not a root. */
    private const ROOTS = ['wordpress', 'web', 'content', 'plugins', 'uploads'];


    /**
     * Each root is `{dir, url}` (both with a trailing slash) or null; `config`
     * is `{dir, url, file}` with `url` null when no root maps its folder.
     * `reasons` says why a root is null.
     *
     * @return array{wordpress:?array, web:?array, content:?array, plugins:?array, uploads:?array, config:?array, reasons:array<string,string>}
     */
    public static function current(): array
    {
        $reasons = [];

        [$web, $web_reason] = self::web_root();
        if ($web === null) {
            $reasons['web'] = $web_reason;
        }

        $upload = wp_get_upload_dir();
        $uploads = null;
        if (is_array($upload) && !empty($upload['basedir']) && !empty($upload['baseurl'])) {
            $uploads = self::root($upload['basedir'], $upload['baseurl']);
        } else {
            $reasons['uploads'] = __('The uploads folder could not be determined.', 'sfxtheme');
        }

        $locations = [
            'wordpress' => self::root(ABSPATH, site_url('/')),
            'web'       => $web,
            'content'   => self::root(WP_CONTENT_DIR, content_url()),
            'plugins'   => self::root(WP_PLUGIN_DIR, plugins_url()),
            'uploads'   => $uploads,
            'config'    => null,
            'reasons'   => [],
        ];

        $config = self::config_file();
        if ($config === null) {
            $reasons['config'] = __('wp-config.php was not found where WordPress looks for it.', 'sfxtheme');
        } else {
            $dir = trailingslashit(dirname($config));
            $locations['config'] = ['dir' => $dir, 'url' => self::url_for($dir, $locations), 'file' => $config];
        }

        // CDN / offload: the files are there, but outside checks cannot reach them.
        foreach (['wordpress', 'content', 'plugins', 'uploads'] as $name) {
            if ($locations[$name] !== null && !self::fetchable($locations[$name]['url'])) {
                $reasons[$name] = __('This location is served from a different address than the site (for example a CDN or offloaded media), so it cannot be checked from outside.', 'sfxtheme');
            }
        }

        $locations['reasons'] = $reasons;
        return $locations;
    }

    /**
     * The public URL of a file or folder: the mapping of the longest root
     * that contains it. Null when no established root contains it — such a
     * file is never fetched.
     */
    public static function url_for(string $path, ?array $locations = null): ?string
    {
        $locations = $locations ?? self::current();
        $path = wp_normalize_path($path);
        if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }

        $best = null;
        foreach (self::ROOTS as $name) {
            $root = $locations[$name] ?? null;
            if ($root === null) {
                continue;
            }
            $inside = $path === untrailingslashit($root['dir']) || strpos($path, $root['dir']) === 0;
            if ($inside && ($best === null || strlen($root['dir']) > strlen($best['dir']))) {
                $best = $root;
            }
        }
        if ($best === null) {
            return null;
        }

        $rest = (string) substr($path, strlen($best['dir']));
        return $best['url'] . implode('/', array_map('rawurlencode', explode('/', $rest)));
    }

    /**
     * The disk path a fetchable URL's path (decoded once) maps to: the root
     * with the longest URL path (decoded once, the same way) that contains it. Null when no
     * established, fetchable root contains it — what is there is unknown.
     */
    public static function path_for_url(string $decoded_path, ?array $locations = null): ?string
    {
        $locations = $locations ?? self::current();
        $best = null;
        $best_path = '';
        foreach (self::ROOTS as $name) {
            $root = $locations[$name] ?? null;
            if ($root === null || !self::fetchable($root['url'])) {
                continue;
            }
            // Decoded exactly once, like the requested path (Fetch::refusal()); an
            // escape left after that is ambiguous, and nothing is mapped then.
            $root_path = rawurldecode((string) wp_parse_url($root['url'], PHP_URL_PATH));
            if (preg_match('/%[0-9a-f]{2}/i', $root_path) === 1) {
                return null;
            }
            $root_path = '/' . trim($root_path, '/');
            $root_path = $root_path === '/' ? '/' : $root_path . '/';
            $inside = $root_path === '/' || $decoded_path === rtrim($root_path, '/') || strpos($decoded_path, $root_path) === 0;
            if ($inside && ($best === null || strlen($root_path) > strlen($best_path))) {
                $best = $root;
                $best_path = $root_path;
            }
        }
        if ($best === null) {
            return null;
        }

        $rest = trim((string) substr($decoded_path, strlen(rtrim($best_path, '/'))), '/');

        return untrailingslashit($best['dir'] . $rest);
    }

    /** http(s) on the host and port of home_url, without user info. */
    public static function fetchable(string $url): bool
    {
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($target) || !is_array($home) || empty($target['host']) || empty($home['host'])) {
            return false;
        }
        if (!in_array(strtolower($target['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        return self::host($target) === self::host($home) && self::port($target) === self::port($home);
    }

    /**
     * The web root: ABSPATH when home and siteurl are the same URL; in every
     * other case not established (ruling, Gate B pass 8) — its file checks are
     * Nicht prüfbar with that reason and only the other mappings are used.
     *
     * @return array{0:?array, 1:?string} root, reason
     */
    private static function web_root(): array
    {
        $home_url = home_url('/');
        if (untrailingslashit($home_url) === untrailingslashit(site_url('/'))) {
            return [self::root(ABSPATH, $home_url), null];
        }

        return [null, __('The site address and the WordPress address differ, so the web folder is not established.', 'sfxtheme')];
    }

    /** Where wp-load.php finds wp-config.php: ABSPATH, or one level above. */
    private static function config_file(): ?string
    {
        $abspath = trailingslashit(wp_normalize_path(ABSPATH));
        if (file_exists($abspath . 'wp-config.php')) {
            return $abspath . 'wp-config.php';
        }
        $parent = dirname($abspath);
        if (file_exists($parent . '/wp-config.php') && !file_exists($parent . '/wp-settings.php')) {
            return $parent . '/wp-config.php';
        }
        return null;
    }

    /** @return array{dir:string, url:string} */
    private static function root(string $dir, string $url): array
    {
        return ['dir' => trailingslashit(wp_normalize_path($dir)), 'url' => trailingslashit($url)];
    }

    private static function host(array $parts): string
    {
        return strtolower(rtrim((string) $parts['host'], '.'));
    }

    private static function port(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }
        return strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80;
    }
}
