<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Locations;
use SFX\SiteCheck\Mutex;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\Options;
use SFX\SiteCheck\Probe;
use SFX\SiteCheck\Status;

/**
 * Checks that look at files on disk: config_copies, phpinfo,
 * php_files_uploads, and the server half of logs_public, backups_public,
 * vcs_env and public_files. Read-only. A file is read at most READ_BYTES from its start and
 * only facts are kept (name, size, signature names) — never contents.
 *
 * S+B observation shape (logs_public, backups_public, vcs_env):
 *   ['targets' => list<{target, path, url, disk, size, reason}>] — backups
 *   add `kind` (sql|archive|folder). A target with `url` null is never
 *   fetched.
 *
 * OutsideChecks joins the outside half by URL and adds to each target it
 * fetched:
 *   - `outside`: an Evidence::classify() result;
 *   - `outside_signature`, only when `outside` is a signature: the group name
 *     in Data/signatures.php (log|sql|archive|git_head|git_config|env).
 * A target without `outside` grades as Nicht prüfbar. Only a match in the
 * `archive` group grades Gelb instead of Rot: a .sql.gz served decoded shows
 * the SQL header, a confirmed leak.
 */
final class FileChecks
{
    /** Rule 6: a PHP-like extension anywhere in the name (.php .phtml .php5 .php7 .phar .pht). */
    public const PHP_LIKE = '/\.(?:php|pht|phar)/i';

    /** php_files_uploads scan limit per run: directory entries visited, and seconds. */
    public const MAX_ENTRIES = 2000;
    public const MAX_SECONDS = 5.0;

    public const READ_BYTES = 4096;
    private const SILENCE_MAX_BYTES = 64;

    private const PHPINFO_NAMES = ['phpinfo.php', 'php_info.php', 'php-info.php', 'info.php', 'pi.php', 'php.php', 'test.php', 'phptest.php'];
    /** Suffixes of a wp-config copy; Template's sensitive-files block reads the same list. */
    public const COPY_SUFFIXES = 'bak|old|save|orig|txt|swp|swo';
    private const CONFIG_COPY = '/^\.?wp-config(?:\.php)?(?:~|\.(?:' . self::COPY_SUFFIXES . '))$/i';
    private const BACKUP_FILE = '/\.(?:sql|sql\.gz|zip|tar|tar\.gz|tgz)$/i';
    private const SQL_FILE = '/\.sql$/i';

    /**
     * Folders of common backup plugins, relative to a location root. A
     * pattern with `*` is globbed. Sources: the plugins' default storage paths
     * (UpdraftPlus, All-in-One WP Migration, Duplicator Lite/Pro, WPvivid,
     * BackWPup, BackupGuard, WP STAGING, Solid Security, WP Migrate).
     */
    private const BACKUP_FOLDERS = [
        ['content', 'updraft'],
        ['content', 'ai1wm-backups'],
        ['content', 'backups-dup-lite'],
        ['content', 'backups-dup-pro'],
        ['content', 'wpvividbackups'],
        ['content', 'backup-db'],
        ['content', 'backups'],
        ['uploads', 'backwpup-*-backups'],
        ['uploads', 'backup-guard'],
        ['uploads', 'wp-staging/backups'],
        ['uploads', 'ithemes-security/backups'],
        ['uploads', 'wp-migrate-db'],
    ];

    public static function observe(string $id, \SFX\SiteCheck\RunContext $ctx): array
    {
        $loc = Locations::current();

        switch ($id) {
            case 'logs_public':
                return ['targets' => self::log_targets($loc)];
            case 'backups_public':
                return ['targets' => self::backup_targets($loc)];
            case 'vcs_env':
                return ['targets' => self::vcs_targets($loc)];
            case 'config_copies':
                return self::observe_config_copies($loc);
            case 'phpinfo':
                return self::observe_phpinfo($loc);
            case 'public_files':
                return ['targets' => self::default_file_targets($loc)];
            case 'php_files_uploads':
                if ($loc['uploads'] === null) {
                    return ['reason' => $loc['reasons']['uploads'] ?? '', 'items' => [], 'unreadable' => [], 'limit' => null];
                }
                $scan = self::scan_uploads($loc['uploads']['dir'], $loc, self::MAX_ENTRIES, self::MAX_SECONDS);
                return self::decide_probe_folder($scan, $loc);
        }
        throw new \InvalidArgumentException('Not a file check: ' . $id);
    }

    public static function grade(string $id, array $obs, \SFX\SiteCheck\RunContext $ctx): array
    {
        switch ($id) {
            case 'logs_public':
            case 'backups_public':
            case 'vcs_env':
                return self::grade_exposure($id, $obs);
            case 'config_copies':
                return self::grade_config_copies($obs);
            case 'phpinfo':
                return self::grade_phpinfo($obs);
            case 'php_files_uploads':
                return self::grade_php_files_uploads($obs);
            case 'public_files':
                return self::grade_public_files($obs);
        }
        throw new \InvalidArgumentException('Not a file check: ' . $id);
    }

    /**
     * Walks the uploads folder for PHP-like names, bounded by entry count and
     * time. Files directly in the probe folder are not classified here: they
     * are listed in `probe_folder` for decide_probe_folder().
     */
    public static function scan_uploads(string $dir, array $loc, int $max_entries, float $max_seconds): array
    {
        $start = microtime(true);
        $seen = 0;
        $stack = [untrailingslashit(wp_normalize_path($dir))];
        $probe_dir = $stack[0] . '/' . Probe::FOLDER;
        $items = [];
        $probe_folder = [];
        $unreadable = [];
        $linked = [];
        $limit = null;

        while ($stack !== [] && $limit === null) {
            $folder = array_pop($stack);
            // Read incrementally: the limits apply to the enumeration itself.
            $handle = @opendir($folder);
            if ($handle === false) {
                $unreadable[] = self::site_target($folder, $loc);
                continue;
            }
            if (!@is_executable($folder)) {
                // A folder that can be listed but not searched hides what its entries are — empty or not.
                closedir($handle);
                $unreadable[] = self::site_target($folder, $loc);
                continue;
            }
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if ($seen >= $max_entries || (microtime(true) - $start) > $max_seconds) {
                    $pending = array_merge([$folder], array_reverse($stack));
                    $limit = [
                        'max_entries' => $max_entries,
                        'max_seconds' => $max_seconds,
                        'checked'     => $seen,
                        'pending'     => array_map(static fn(string $p): string => self::site_target($p, $loc), array_slice($pending, 0, 5)),
                        'pending_count' => count($pending),
                    ];
                    break;
                }
                $seen++;
                $path = $folder . '/' . $name;
                $type = self::entry_type($path);
                if ($type === 'unknown') {
                    // Failed stat: neither a file nor a folder is known — named, never skipped.
                    $unreadable[] = self::site_target($path, $loc);
                    continue;
                }
                if ($type === 'dir') {
                    $stack[] = $path;
                    continue;
                }
                if ($type === 'link_dir') {
                    // Not followed (no loops, no escape from uploads), but named.
                    $linked[] = self::site_target($path, $loc);
                    continue;
                }
                if ($type !== 'file' || preg_match(self::PHP_LIKE, $name) !== 1) {
                    continue;
                }

                if ($folder === $probe_dir) {
                    $probe_folder[] = $path;
                    continue;
                }
                $items[] = self::classify_upload(self::site_target($path, $loc), self::read_head($path), @filesize($path));
            }
            closedir($handle);
        }

        return ['reason' => '', 'items' => $items, 'unreadable' => $unreadable, 'linked' => $linked, 'limit' => $limit, 'probe_folder' => $probe_folder];
    }

    /**
     * The probe folder's files, decided inside the critical section (which
     * drops the option cache), against a fresh read of the registry: only a
     * registered file holding exactly its entry's expected content is
     * skipped. Probe creation writes the entry and the content inside one
     * section, so a file is never seen half-written here. A file gone by now
     * was removed by its own run. Busy → each file Nicht prüfbar ("in use"),
     * never classified.
     */
    private static function decide_probe_folder(array $scan, array $loc): array
    {
        $paths = $scan['probe_folder'];
        unset($scan['probe_folder']);
        if ($paths === []) {
            return $scan;
        }

        $decided = Mutex::with(static function () use ($paths, $loc): array {
            $registered = [];
            $entries = get_option(Options::name('probes'), []);
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                    $registered[wp_normalize_path($entry['path'])] = $entry;
                }
            }
            $items = [];
            foreach ($paths as $path) {
                clearstatcache(true, $path);
                if (!is_link($path) && !file_exists($path)) {
                    continue;
                }
                $head = self::read_head($path);
                $size = @filesize($path);
                if ($head !== null && isset($registered[$path])) {
                    $expected = Probe::expected_content($registered[$path]);
                    if ($size === strlen($expected) && hash_equals($expected, $head)) {
                        continue;
                    }
                }
                $items[] = self::classify_upload(self::site_target($path, $loc), $head, $size);
            }
            return $items;
        });
        if ($decided === Mutex::Busy) {
            $decided = array_map(static fn(string $path): array => ['target' => self::site_target($path, $loc), 'state' => 'busy', 'signature' => ''], $paths);
        }
        $scan['items'] = array_merge($scan['items'], $decided);

        return $scan;
    }

    /** present | absent | unknown — "absent" only when the nearest existing ancestor could be searched. */
    public static function disk_state(string $path): string
    {
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            // There is an entry; a link whose target cannot be inspected is unknown, never absent.
            return @stat($path) !== false ? 'present' : 'unknown';
        }
        $dir = dirname($path);
        while (@lstat($dir) === false) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return 'unknown';
            }
            $dir = $parent;
        }
        // The nearest existing ancestor (followed if it is a link) must be a searchable folder.
        return @stat($dir) !== false && @is_dir($dir) && @is_executable($dir) ? 'absent' : 'unknown';
    }

    /** True only for a readable regular file that is exactly WordPress' silence placeholder (≤ 64 bytes). */
    public static function is_silence_file(string $path): bool
    {
        if (is_link($path) || !@is_file($path)) {
            return false;
        }
        $size = @filesize($path);
        $head = self::read_head($path);

        return $head !== null && self::is_silence($head, $size);
    }

    // ------------------------------------------------------------ S+B targets

    private static function log_targets(array $loc): array
    {
        $paths = [WP_CONTENT_DIR . '/debug.log'];
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && !in_array(strtolower(WP_DEBUG_LOG), ['', 'true', '1', 'false', '0'], true)) {
            $paths[] = WP_DEBUG_LOG;
        }
        [$roots, $targets] = self::roots($loc);
        foreach ($roots as $root) {
            $paths[] = $root . 'error_log';
        }
        $ini = (string) ini_get('error_log');
        if ($ini !== '' && $ini !== 'syslog' && wp_normalize_path($ini)[0] === '/') {
            $paths[] = $ini;
        }

        return array_merge($targets, self::file_targets($paths, $loc));
    }

    private static function vcs_targets(array $loc): array
    {
        [$roots, $targets] = self::roots($loc);
        $paths = [];
        foreach ($roots as $root) {
            array_push($paths, $root . '.git/HEAD', $root . '.git/config', $root . '.env');
        }
        return array_merge($targets, self::file_targets($paths, $loc));
    }

    private static function backup_targets(array $loc): array
    {
        [$folders, $targets] = self::roots($loc);
        foreach (self::BACKUP_FOLDERS as [$base, $relative]) {
            if ($loc[$base] === null) {
                continue;
            }
            $pattern = $loc[$base]['dir'] . $relative;
            if (strpos($relative, '*') !== false) {
                // An empty glob proves nothing unless its folder could be listed and searched.
                $parent = dirname($pattern);
                $parent_state = self::disk_state($parent);
                if ($parent_state === 'absent') {
                    continue;
                }
                $found = $parent_state === 'present' && @is_readable($parent) && @is_executable($parent) ? @glob($pattern, GLOB_NOSORT) : false;
                if ($found === false) {
                    // The search itself failed: what is there is unknown.
                    $target = self::target($parent, $loc, 'unknown');
                    $target['url'] = null;
                    $target['reason'] = __('The folder could not be searched for backup folders.', 'sfxtheme');
                    $targets[] = $target + ['kind' => 'folder'];
                    continue;
                }
            } else {
                $found = self::disk_state($pattern) === 'absent' ? [] : [$pattern];
            }
            foreach ($found as $folder) {
                $type = self::entry_type($folder);
                if ($type === 'dir' || $type === 'link_dir') {
                    $folders[] = trailingslashit(wp_normalize_path($folder));
                } elseif ($type === 'unknown') {
                    // Uninspectable (broken link, failed stat): named, never dropped.
                    $target = self::target(wp_normalize_path($folder), $loc, 'unknown');
                    $target['url'] = null;
                    $target['reason'] = __('The folder could not be inspected.', 'sfxtheme');
                    $targets[] = $target + ['kind' => 'folder'];
                }
            }
        }

        foreach (array_unique($folders) as $folder) {
            $names = self::list_dir(untrailingslashit($folder));
            if ($names === null) {
                $target = self::target(untrailingslashit($folder), $loc, 'unknown');
                $target['url'] = null;
                $target['reason'] = __('The folder could not be listed.', 'sfxtheme');
                $targets[] = $target + ['kind' => 'folder'];
                continue;
            }
            // Searchability right after listing, empty or not: an unsearchable folder is named
            // itself (its matching names below are kept as unknown too).
            if (!@is_executable(untrailingslashit($folder))) {
                $target = self::target(untrailingslashit($folder), $loc, 'unknown');
                $target['url'] = null;
                $target['reason'] = __('The folder could not be inspected.', 'sfxtheme');
                $targets[] = $target + ['kind' => 'folder'];
            }
            foreach ($names as $name) {
                if (preg_match(self::BACKUP_FILE, $name) !== 1) {
                    continue;
                }
                $type = self::entry_type($folder . $name);
                if ($type === 'file' || $type === 'unknown') {
                    // A failed stat keeps the candidate, disk unknown: Nicht prüfbar, never dropped.
                    $targets[] = self::target($folder . $name, $loc, $type === 'unknown' ? 'unknown' : null) + ['kind' => preg_match(self::SQL_FILE, $name) === 1 ? 'sql' : 'archive'];
                }
            }
        }

        return $targets;
    }

    /**
     * The web root and the WordPress root (once when they are the same), and
     * a Nicht prüfbar target for a web root that is not established.
     *
     * @return array{0:list<string>, 1:list<array>}
     */
    private static function roots(array $loc): array
    {
        $roots = [];
        $targets = [];
        foreach (['web', 'wordpress'] as $name) {
            if ($loc[$name] === null) {
                $targets[] = ['target' => $name . '-root', 'path' => '', 'url' => null, 'disk' => 'unknown', 'size' => null, 'reason' => (string) ($loc['reasons'][$name] ?? '')];
                continue;
            }
            $roots[] = $loc[$name]['dir'];
        }
        return [array_values(array_unique($roots)), $targets];
    }

    /** Targets for fixed candidate paths; an absent file that is unmapped or PHP-named is left out. */
    private static function file_targets(array $paths, array $loc): array
    {
        $targets = [];
        foreach (array_unique(array_map('wp_normalize_path', $paths)) as $path) {
            $target = self::target($path, $loc);
            // Nothing there and nothing to fetch. An off-host (CDN) location stays: Nicht prüfbar with its reason.
            $never_fetched = Locations::url_for($target['path'], $loc) === null || preg_match(self::PHP_LIKE, basename($target['path'])) === 1;
            if ($target['disk'] === 'absent' && $target['url'] === null && $never_fetched) {
                continue;
            }
            $targets[] = $target;
        }
        return $targets;
    }

    /** @return array{target:string, path:string, url:?string, disk:string, size:?int, reason:string} */
    private static function target(string $path, array $loc, ?string $disk = null): array
    {
        $path = wp_normalize_path($path);
        $mapped = Locations::url_for($path, $loc);
        $url = $mapped;
        $reason = '';
        if (preg_match(self::PHP_LIKE, basename($path)) === 1) {
            $url = null;
            $reason = __('The name contains a PHP extension, so the file is never requested from outside.', 'sfxtheme');
        } elseif ($mapped === null) {
            $reason = __('Lies outside the known web folders; a server alias would not be visible.', 'sfxtheme');
        } elseif (!Locations::fetchable($mapped)) {
            $url = null;
            $reason = __('This location is served from a different address than the site, so it cannot be checked from outside.', 'sfxtheme');
        }

        $disk = $disk ?? self::disk_state($path);
        $size = null;
        if ($disk === 'present' && @is_file($path)) {
            $bytes = @filesize($path);
            $size = $bytes === false ? null : $bytes;
        }

        return ['target' => self::site_target($path, $loc), 'path' => $path, 'url' => $url, 'disk' => $disk, 'size' => $size, 'reason' => $reason];
    }

    /** Site-relative URL path when the file lies in a mapped location, else the disk path. */
    private static function site_target(string $path, array $loc): string
    {
        $path = wp_normalize_path($path);
        $mapped = Locations::url_for($path, $loc);
        if ($mapped === null) {
            return $path;
        }
        $url_path = (string) wp_parse_url($mapped, PHP_URL_PATH);
        return rawurldecode($url_path === '' ? '/' : $url_path);
    }

    private static function grade_exposure(string $id, array $obs): array
    {
        $findings = [];
        foreach ($obs['targets'] ?? [] as $t) {
            $outside = $t['url'] === null ? null : ($t['outside'] ?? null);
            $status = Evidence::file_exposure((string) $t['disk'], $outside ?? Evidence::INDETERMINATE);
            $archive_only = $outside === Evidence::SIGNATURE && ($t['outside_signature'] ?? '') === 'archive';
            if ($archive_only) {
                $status = Status::YELLOW;
            }

            if ($status === Status::RED) {
                $label = __('publicly readable — remove it or block access at once', 'sfxtheme');
            } elseif ($archive_only) {
                $label = __('public archive — check it', 'sfxtheme');
            } elseif ($status === Status::YELLOW && $outside === Evidence::OK200) {
                $label = __('reachable, content not recognised — check and delete', 'sfxtheme');
            } elseif ($status === Status::YELLOW) {
                $label = __('is there, currently blocked — delete it', 'sfxtheme');
            } elseif ($status === Status::GREEN) {
                $label = __('not there and not reachable', 'sfxtheme');
            } elseif ($t['reason'] !== '') {
                $label = $t['reason'];
            } elseif ($outside === null) {
                $label = __('not checked from outside', 'sfxtheme');
            } else {
                $label = __('no reliable answer from outside', 'sfxtheme');
            }
            // An unlisted backup folder is coverage, not a file (Runs::compact()).
            $findings[] = ServerChecks::finding($id, (string) $t['target'], $status, $t['target'] . ' — ' . $label) + (($t['kind'] ?? '') === 'folder' ? ['coverage' => true] : []);
        }

        return ServerChecks::result($findings, Status::GREEN, __('Server: what is on disk. Browser: whether it can be read from outside.', 'sfxtheme'), 'browser');
    }

    // ------------------------------------------------------------ public_files

    /**
     * Server half of public_files: readme.html and license.txt in the
     * WordPress root, present | absent | unknown. The installer has no disk
     * half. The target is the one OutsideFiles fetches, so the halves join by it.
     *
     * @return list<array{target:string, kind:string, disk:string}>
     */
    private static function default_file_targets(array $loc): array
    {
        $targets = [];
        foreach (['readme' => 'readme.html', 'license' => 'license.txt'] as $kind => $name) {
            $targets[] = [
                'target' => Observations::target_of(site_url('/' . $name)),
                'kind'   => $kind,
                'disk'   => $loc['wordpress'] === null ? 'unknown' : self::disk_state($loc['wordpress']['dir'] . $name),
            ];
        }

        return $targets;
    }

    /**
     * readme/license (state from OutsideFiles): readable → Gelb; blocked →
     * Grün when the disk state is known; anything else Nicht prüfbar. The
     * installer: setup form → Rot, "already installed" → Hinweis, blocked → Grün.
     */
    private static function grade_public_files(array $obs): array
    {
        $findings = [];
        foreach ($obs['targets'] ?? [] as $t) {
            $target = (string) $t['target'];
            $state = (string) ($t['state'] ?? '');
            $disk = (string) ($t['disk'] ?? '');
            if (($t['kind'] ?? '') === 'install') {
                if ($state === 'form') {
                    [$status, $label] = [Status::RED, __('the WordPress installer shows its setup form — anyone could set up the site; close it at once', 'sfxtheme')];
                } elseif ($state === 'installed') {
                    [$status, $label] = [Status::HINT, __('reachable, reports "already installed"', 'sfxtheme')];
                } elseif ($state === 'blocked') {
                    [$status, $label] = [Status::GREEN, __('not reachable', 'sfxtheme')];
                } else {
                    [$status, $label] = [Status::UNKNOWN, __('no reliable answer from outside', 'sfxtheme')];
                }
            } elseif ($state === 'readable') {
                [$status, $label] = [Status::YELLOW, __('publicly readable — remove it or block it; it comes back with every WordPress update', 'sfxtheme')];
            } elseif ($state === 'blocked' && $disk === 'present') {
                [$status, $label] = [Status::GREEN, __('is there, but blocked', 'sfxtheme')];
            } elseif ($state === 'blocked' && $disk === 'absent') {
                [$status, $label] = [Status::GREEN, __('not there and not reachable', 'sfxtheme')];
            } elseif ($state === 'blocked') {
                [$status, $label] = [Status::UNKNOWN, __('not reachable, but whether the file is there could not be read on the server', 'sfxtheme')];
            } else {
                [$status, $label] = [Status::UNKNOWN, __('no reliable answer from outside', 'sfxtheme')];
            }
            if ($status === Status::UNKNOWN && (string) ($t['reason'] ?? '') !== '') {
                $label = (string) $t['reason'];
            }
            $findings[] = ServerChecks::finding('public_files', $target, $status, $target . ' — ' . $label);
        }

        return ServerChecks::result($findings, Status::UNKNOWN, __('Server: whether readme.html and license.txt are on disk. Browser: what can be read without login; nothing is submitted.', 'sfxtheme'), 'browser');
    }

    // ------------------------------------------------------------ config_copies

    private static function observe_config_copies(array $loc): array
    {
        if ($loc['config'] === null) {
            return ['listed' => false, 'reason' => (string) ($loc['reasons']['config'] ?? ''), 'copies' => []];
        }
        $dir = $loc['config']['dir'];
        $names = self::list_dir(untrailingslashit($dir));
        if ($names === null) {
            return ['listed' => false, 'reason' => __('The configuration folder could not be listed.', 'sfxtheme'), 'copies' => []];
        }

        $copies = [];
        foreach ($names as $name) {
            if (preg_match(self::CONFIG_COPY, $name) !== 1) {
                continue;
            }
            $type = self::entry_type($dir . $name);
            if ($type === 'unknown') {
                $copies[] = ['target' => self::site_target($dir . $name, $loc), 'size' => 0, 'credentials' => null, 'unknown' => true];
                continue;
            }
            if ($type !== 'file') {
                continue;
            }
            $head = self::read_head($dir . $name);
            $copies[] = [
                'target'      => self::site_target($dir . $name, $loc),
                'size'        => (int) @filesize($dir . $name),
                'credentials' => $head === null ? null : strpos($head, 'DB_PASSWORD') !== false,
            ];
        }
        return ['listed' => true, 'reason' => '', 'copies' => $copies];
    }

    private static function grade_config_copies(array $obs): array
    {
        $note = __('Copies are read on the server only; whether one is publicly readable is not tested, because such files are never requested.', 'sfxtheme');
        if (empty($obs['listed'])) {
            $f = ServerChecks::finding('config_copies', 'config', Status::UNKNOWN, (string) ($obs['reason'] ?? ''));
            return ServerChecks::result([$f], Status::UNKNOWN, $note);
        }

        $copies = $obs['copies'] ?? [];
        usort($copies, static fn(array $a, array $b): int => (int) ($b['credentials'] === true) <=> (int) ($a['credentials'] === true));
        $findings = [];
        foreach ($copies as $copy) {
            if (!empty($copy['unknown'])) {
                $findings[] = ServerChecks::finding('config_copies', (string) $copy['target'], Status::UNKNOWN, $copy['target'] . ' — ' . __('could not be examined on the server (its stat failed)', 'sfxtheme'));
                continue;
            }
            $label = $copy['target'] . ' — ' . __('copy of the configuration, delete it at once', 'sfxtheme');
            if ($copy['credentials'] === true) {
                $label .= ' ' . __('(contains credentials)', 'sfxtheme');
            } elseif ($copy['credentials'] === null) {
                $label .= ' ' . __('(could not be read)', 'sfxtheme');
            }
            // `first`: copies with credentials lead the list, also after Runs::compact().
            $findings[] = ServerChecks::finding('config_copies', (string) $copy['target'], Status::YELLOW, $label) + ($copy['credentials'] === true ? ['first' => true] : []);
        }
        return ServerChecks::result($findings, Status::GREEN, $note);
    }

    // ------------------------------------------------------------ phpinfo

    private static function observe_phpinfo(array $loc): array
    {
        $items = [];
        [$roots, $unknown] = self::roots($loc);
        foreach ($unknown as $u) {
            $items[] = ['target' => $u['target'], 'state' => 'unknown', 'reason' => $u['reason']];
        }
        foreach ($roots as $root) {
            foreach (self::PHPINFO_NAMES as $name) {
                $path = $root . $name;
                $disk = self::disk_state($path);
                if ($disk === 'absent') {
                    continue;
                }
                $target = self::site_target($path, $loc);
                if ($disk === 'unknown') {
                    $items[] = ['target' => $target, 'state' => 'unknown', 'reason' => __('Could not be read on the server.', 'sfxtheme')];
                    continue;
                }
                $head = self::read_head($path);
                if ($head === null) {
                    // Not a regular file, or not readable: never guessed.
                    $items[] = ['target' => $target, 'state' => 'unknown', 'reason' => __('Could not be read on the server.', 'sfxtheme')];
                    continue;
                }
                $state = preg_match('/\bphpinfo\s*\(/i', $head) === 1 ? 'phpinfo' : 'script';
                $items[] = ['target' => $target, 'state' => $state, 'reason' => ''];
            }
        }
        return ['items' => $items];
    }

    private static function grade_phpinfo(array $obs): array
    {
        $findings = [];
        foreach ($obs['items'] ?? [] as $item) {
            if ($item['state'] === 'phpinfo') {
                $findings[] = ServerChecks::finding('phpinfo', $item['target'], Status::YELLOW, $item['target'] . ' — ' . __('reveals server details, delete it', 'sfxtheme'));
            } elseif ($item['state'] === 'script') {
                $findings[] = ServerChecks::finding('phpinfo', $item['target'], Status::YELLOW, $item['target'] . ' — ' . __('unknown script, check and delete it', 'sfxtheme'));
            } else {
                $findings[] = ServerChecks::finding('phpinfo', $item['target'], Status::UNKNOWN, $item['target'] . ' — ' . $item['reason']);
            }
        }
        return ServerChecks::result($findings, Status::GREEN, __('Read on the server only; these scripts are never requested.', 'sfxtheme'));
    }

    // ------------------------------------------------------------ php_files_uploads

    private static function classify_upload(string $target, ?string $head, $size): array
    {
        if ($head === null) {
            return ['target' => $target, 'state' => 'unreadable', 'signature' => ''];
        }
        if (self::is_silence($head, $size)) {
            return ['target' => $target, 'state' => 'silence', 'signature' => ''];
        }
        $shell = Evidence::signature($head, self::signatures()['shell']);
        if ($shell !== null) {
            return ['target' => $target, 'state' => 'shell', 'signature' => $shell];
        }
        return ['target' => $target, 'state' => 'other', 'signature' => ''];
    }

    private static function is_silence(string $head, $size): bool
    {
        return is_int($size) && $size <= self::SILENCE_MAX_BYTES && Evidence::signature($head, self::signatures()['silence']) !== null;
    }

    private static function grade_php_files_uploads(array $obs): array
    {
        $note = __('Reads the first 4 KB of each PHP-like file in the uploads folder on the server.', 'sfxtheme');
        if (($obs['reason'] ?? '') !== '') {
            $f = ServerChecks::finding('php_files_uploads', 'uploads', Status::UNKNOWN, (string) $obs['reason']);
            return ServerChecks::result([$f], Status::UNKNOWN, $note);
        }

        $buckets = ['shell' => [], 'other' => [], 'unknown' => [], 'silence' => []];
        foreach ($obs['items'] ?? [] as $item) {
            $t = $item['target'];
            switch ($item['state']) {
                case 'shell':
                    /* translators: %s: signature name */
                    $buckets['shell'][] = ServerChecks::finding('php_files_uploads', $t, Status::YELLOW, $t . ' — ' . sprintf(__('suspicious code (%s), check it at once', 'sfxtheme'), $item['signature'])) + ['first' => true];
                    break;
                case 'other':
                    $buckets['other'][] = ServerChecks::finding('php_files_uploads', $t, Status::YELLOW, $t . ' — ' . __('PHP file in the uploads folder, check it', 'sfxtheme'));
                    break;
                case 'silence':
                    $buckets['silence'][] = ServerChecks::finding('php_files_uploads', $t, Status::HINT, $t . ' — ' . __('empty placeholder ("Silence is golden")', 'sfxtheme'));
                    break;
                case 'busy':
                    $buckets['unknown'][] = ServerChecks::finding('php_files_uploads', $t, Status::UNKNOWN, $t . ' — ' . __('test-file folder in use right now', 'sfxtheme'));
                    break;
                default:
                    $buckets['unknown'][] = ServerChecks::finding('php_files_uploads', $t, Status::UNKNOWN, $t . ' — ' . __('could not be read', 'sfxtheme'));
            }
        }
        foreach ($obs['unreadable'] ?? [] as $folder) {
            $buckets['unknown'][] = ServerChecks::finding('php_files_uploads', $folder, Status::UNKNOWN, $folder . ' — ' . __('folder could not be read', 'sfxtheme')) + ['coverage' => true];
        }
        foreach ($obs['linked'] ?? [] as $folder) {
            $buckets['unknown'][] = ServerChecks::finding('php_files_uploads', $folder, Status::UNKNOWN, $folder . ' — ' . __('linked folder, not followed; check what it points to', 'sfxtheme')) + ['coverage' => true];
        }
        if (!empty($obs['limit'])) {
            $l = $obs['limit'];
            $label = sprintf(
                /* translators: 1: entries checked, 2: entry limit, 3: seconds limit, 4: number of folders not finished, 5: folder list */
                __('Scan limit reached after %1$d entries (limit %2$d entries or %3$s seconds); %4$d folders not checked completely: %5$s', 'sfxtheme'),
                (int) $l['checked'],
                (int) $l['max_entries'],
                (string) $l['max_seconds'],
                (int) $l['pending_count'],
                implode(', ', $l['pending'])
            );
            $buckets['unknown'][] = ServerChecks::finding('php_files_uploads', 'scan-limit', Status::UNKNOWN, $label) + ['coverage' => true];
        }

        return ServerChecks::result(array_merge($buckets['shell'], $buckets['other'], $buckets['unknown'], $buckets['silence']), Status::GREEN, $note);
    }

    // ------------------------------------------------------------ helpers

    /** @return list<string>|null sorted names, null when the folder cannot be listed */
    /**
     * file | dir | link_dir | other | unknown — from lstat (and stat for a
     * link); a failed stat is `unknown`, never a guess. A linked file is `file`.
     */
    private static function entry_type(string $path): string
    {
        $lstat = @lstat($path);
        if ($lstat === false) {
            return 'unknown';
        }
        $mode = $lstat['mode'] & 0170000;
        if ($mode === 0120000) {
            $stat = @stat($path);
            if ($stat === false) {
                return 'unknown';
            }
            $mode = $stat['mode'] & 0170000;
            return $mode === 0040000 ? 'link_dir' : ($mode === 0100000 ? 'file' : 'other');
        }

        return $mode === 0040000 ? 'dir' : ($mode === 0100000 ? 'file' : 'other');
    }

    private static function list_dir(string $dir): ?array
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return null;
        }
        $names = [];
        while (($name = readdir($handle)) !== false) {
            if ($name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }
        closedir($handle);
        sort($names, SORT_STRING);
        return $names;
    }

    /** The first READ_BYTES of a file, or null when it cannot be read. */
    private static function read_head(string $path): ?string
    {
        // Only a regular file (or a link to one) is opened: a FIFO, socket or device could block or never end.
        if (self::entry_type($path) !== 'file') {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = @fread($handle, self::READ_BYTES);
        fclose($handle);
        return is_string($head) ? $head : null;
    }

    private static function signatures(): array
    {
        static $signatures = null;
        if ($signatures === null) {
            $signatures = require dirname(__DIR__) . '/Data/signatures.php';
        }
        return $signatures;
    }
}
