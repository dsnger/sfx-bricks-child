<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\FileChecks;
use SFX\SiteCheck\Checks\ServerChecks;

/**
 * The uploads probe (spec "Uploads probe (active test)"): the only check that
 * writes a file. One server request creates `<uploads>/sfx-site-check/<32 hex>.php`,
 * fetches it once by Loopback and removes it again.
 *
 * Entries `{path, run, expires}` live in the `probes` option and are written
 * only inside the critical section (Mutex). Every removal path — the run's own,
 * cleanup() and teardown() — follows one rule: expected content → delete, then
 * drop the entry; file missing → drop the entry; anything else → keep both and
 * report. Unregistered files are reported, never deleted.
 *
 * The file prints `sfx-site-check-probe-<hex>` until `expires`, nothing after.
 * The source builds that line from two literals, so the source never contains
 * it and a server that delivers the source is never mistaken for one that ran it.
 */
final class Probe
{
    public const FOLDER = 'sfx-site-check';

    /** Seconds a probe prints its marker; after that it is inert. */
    public const LIFETIME = 300;

    private const MARKER_PREFIX = 'sfx-site-check-';

    private const MAX_LOCATION = 200;

    /** The one PHP URL Fetch may request, set only while run() fetches it. */
    private static ?string $fetching = null;

    /** Rule 6: true only for the exact URL of the probe run() is fetching right now. */
    public static function fetching(string $url): bool
    {
        return self::$fetching !== null && hash_equals(self::$fetching, $url);
    }

    /**
     * Creates, fetches and removes one probe. Returns facts only (no body).
     * `step` is '' when every step ran, else the failing one: consent,
     * location, busy, run, create, register, write, entry, fetch.
     *
     * $valid, when given, is asked inside the critical section that creates
     * the file (validity check and guarded write in one section, spec
     * "Writes"); false → step `run`, nothing created.
     */
    public static function run(RunContext $ctx, ?callable $valid = null): array
    {
        if (!$ctx->probe()) {
            return self::observation('consent');
        }

        $loc = Locations::current();
        if ($loc['uploads'] === null || isset($loc['reasons']['uploads'])) {
            return self::observation('location', ['reason' => (string) ($loc['reasons']['uploads'] ?? '')]);
        }

        $comparison = ['status' => 0, 'redirect' => false];
        $hex = bin2hex(random_bytes(16));
        $dir = $loc['uploads']['dir'] . self::FOLDER;
        $path = $dir . '/' . $hex . '.php';
        $url = $loc['uploads']['url'] . self::FOLDER . '/' . $hex . '.php';

        $created = Mutex::with(static fn(): string => $valid !== null && !$valid() ? 'run' : self::create($dir, $path, $ctx->run()));
        if ($created === Mutex::Busy) {
            return self::observation('busy');
        }
        if ($created === 'run' || $created === 'create' || $created === 'register') {
            return self::observation($created);
        }
        if ($created === 'register_kept') {
            // Not recorded and not removable: an unregistered leftover, reported, never silent.
            return self::observation('register', ['cleanup_failed' => [$path]]);
        }

        $facts = self::observation($created);
        if ($created === '') {
            $present = Mutex::with(static fn(): bool => self::find($path) !== null);
            if ($present === true) {
                // Rule 2: this batch's comparison URL, by Loopback, right before the probe.
                $comparison = Checks\OutsideChecks::loopback_comparison('php_in_uploads', $ctx);
                self::$fetching = $url;
                try {
                    $response = Fetch::loopback('GET', $url);
                } finally {
                    self::$fetching = null;
                }
                $facts = self::facts($response, self::MARKER_PREFIX . 'probe-' . $hex);
            } else {
                $facts = self::observation($present === Mutex::Busy ? 'busy' : 'entry');
            }
        }

        // Step 3, same request: the removal rule on this probe's own entry.
        // Files in the folder that no entry records are reported, never deleted.
        $unregistered = [];
        $removed = Mutex::with(static function () use ($path, $dir, &$unregistered): string {
            $entries = self::entries();
            $index = self::find($path, $entries);
            if ($index === null) {
                self::remove_empty_folder($dir);
                $unregistered = self::unregistered(array_column($entries, 'path'));
                return 'gone';
            }
            if (!self::remove_file($entries[$index])) {
                $unregistered = self::unregistered(array_column($entries, 'path'));
                return 'kept';
            }
            unset($entries[$index]);
            $stored = self::store(array_values($entries));
            self::remove_empty_folder($dir);
            $unregistered = self::unregistered(array_column($entries, 'path'));
            return $stored ? 'removed' : 'kept';
        });
        $facts['unregistered'] = $unregistered;
        $facts['comparison'] = $comparison;
        if ($removed === 'gone' && $facts['step'] === '') {
            // Purged during the fetch: whatever answered says nothing about the file.
            $facts = self::observation('entry');
        }
        if ($removed !== 'removed' && $removed !== 'gone') {
            $facts['cleanup_failed'] = [$path];
        }

        return $facts;
    }

    /**
     * Background cleanup (lifecycle step 4): expired entries only. Unregistered
     * files in the current probe folder are reported, never deleted.
     *
     * `store_failed`: the registry could not be rewritten (fenced write
     * refused or a database error); entries of deleted files stay recorded
     * until a later cleanup drops them as missing.
     *
     * @return array{busy:bool, deleted:int, kept:list<string>, unregistered:list<string>, store_failed:bool}
     */
    public static function cleanup(): array
    {
        $result = Mutex::with(static function (): array {
            $deleted = 0;
            $kept = [];
            $left = [];
            $entries = self::entries();
            foreach ($entries as $entry) {
                if (is_int($entry['expires'] ?? null) && time() < $entry['expires']) {
                    $left[] = $entry;
                } elseif (self::remove_file($entry)) {
                    $deleted++;
                } else {
                    $left[] = $entry;
                    $kept[] = (string) $entry['path'];
                }
            }
            $stored = $left === $entries || self::store($left);
            return ['busy' => false, 'deleted' => $deleted, 'kept' => $kept, 'registered' => array_column($left, 'path'), 'store_failed' => !$stored];
        });
        if ($result === Mutex::Busy) {
            return ['busy' => true, 'deleted' => 0, 'kept' => [], 'unregistered' => [], 'store_failed' => false];
        }

        $result['unregistered'] = self::unregistered($result['registered']);
        unset($result['registered']);
        return $result;
    }

    /**
     * Teardown (lifecycle step 5, purge only): every entry, expired or not.
     * Must run inside Mutex::with() — Purge::run() does. When a probe could not
     * be deleted the option is rewritten to hold only those; otherwise it is
     * left for the purge to delete (and count). `store_failed` says that
     * rewrite did not land; `unregistered` lists files in the probe folders
     * no entry records — reported, never deleted.
     *
     * @return array{deleted:int, failed:list<string>, store_failed:bool, unregistered:list<string>}
     */
    public static function teardown(): array
    {
        $deleted = 0;
        $failed = [];
        $left = [];
        $folders = [];
        $loc = Locations::current();
        if ($loc['uploads'] !== null) {
            $folders[] = $loc['uploads']['dir'] . self::FOLDER;
        }
        foreach (self::entries() as $entry) {
            if (self::hex($entry) !== null) {
                $folders[] = dirname((string) $entry['path']);
            }
            if (self::remove_file($entry)) {
                $deleted++;
            } else {
                $left[] = $entry;
                $failed[] = (string) $entry['path'];
            }
        }
        $stored = $left === [] || self::store($left);
        $unregistered = [];
        foreach (array_unique($folders) as $folder) {
            self::remove_empty_folder($folder);
            $unregistered = array_merge($unregistered, self::unregistered(array_column($left, 'path'), $folder));
        }
        return ['deleted' => $deleted, 'failed' => $failed, 'store_failed' => !$stored, 'unregistered' => array_values(array_unique($unregistered))];
    }

    /**
     * The exact bytes a probe file holds for this entry. Derived from the
     * entry alone (file name and `expires`), so every removal path and the
     * php_files_uploads scan can compare without reading anything else.
     */
    public static function expected_content(array $entry): string
    {
        $hex = self::hex($entry);
        $expires = $entry['expires'] ?? null;
        if ($hex === null || !is_int($expires)) {
            // Never matches a file this module wrote, and never an empty one.
            return "\0invalid sfx-site-check probe entry";
        }

        return "<?php\n"
            . "// Sicherheits-Check test file (sfx-bricks-child). Inert after its expiry; safe to delete.\n"
            . "if (time() < {$expires}) {\n"
            . "    echo '" . self::MARKER_PREFIX . "' . 'probe-{$hex}';\n"
            . "}\n";
    }

    /**
     * @return array{status:string, findings:list<array{id:string,status:string,label:string}>, perspective:string, note:string}
     */
    public static function grade(array $obs): array
    {
        $findings = [self::finding_for($obs)];

        $failed = array_values(array_filter((array) ($obs['cleanup_failed'] ?? []), 'is_string'));
        if ($failed !== []) {
            $findings[] = ServerChecks::finding('php_in_uploads', 'probe-cleanup', Status::YELLOW, sprintf(
                /* translators: %s: server path(s) of the test file */
                __('Test file could not be deleted: %s', 'sfxtheme'),
                implode(', ', $failed)
            ));
        }
        $unregistered = array_slice(array_values(array_filter((array) ($obs['unregistered'] ?? []), 'is_string')), 0, 5);
        if ($unregistered !== []) {
            $findings[] = ServerChecks::finding('php_in_uploads', 'probe-unregistered', Status::YELLOW, sprintf(
                /* translators: %s: server path(s) of the files */
                __('Files in the test folder that no test run recorded were kept, not deleted; check them: %s', 'sfxtheme'),
                implode(', ', $unregistered)
            ));
        }

        return ServerChecks::result(
            $findings,
            Status::UNKNOWN,
            __('Covers .php files in the uploads folder only.', 'sfxtheme'),
            'loopback'
        );
    }

    // ------------------------------------------------------------ internals

    /** Inside the section: '' on success, else the failing step. */
    private static function create(string $dir, string $path, string $run): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'create';
        }
        // 'x': fails if anything exists at that name; nothing there is touched.
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return 'create';
        }

        $entry = ['path' => $path, 'run' => $run, 'expires' => time() + self::LIFETIME];
        $entries = self::entries();
        $entries[] = $entry;
        if (!self::store($entries)) {
            fclose($handle);
            // Opened exclusively a moment ago and still empty: certainly ours.
            return @unlink($path) ? 'register' : 'register_kept';
        }

        $content = self::expected_content($entry);
        $written = fwrite($handle, $content);
        fclose($handle);

        return $written === strlen($content) ? '' : 'write';
    }

    /** The removal rule. True when the file is gone (deleted or missing). */
    private static function remove_file(array $entry): bool
    {
        $path = (string) ($entry['path'] ?? '');
        if ($path === '' || self::hex($entry) === null) {
            return false;
        }
        clearstatcache(true, $path);
        if (!is_link($path) && FileChecks::disk_state($path) === 'absent') {
            return true;
        }
        if (is_link($path) || !is_file($path)) {
            return false;
        }

        $expected = self::expected_content($entry);
        if (@filesize($path) !== strlen($expected)) {
            return false;
        }
        $content = @file_get_contents($path);
        if (!is_string($content) || !hash_equals($expected, $content)) {
            return false;
        }

        return @unlink($path);
    }

    /** Removes a probe folder (named FOLDER, not a link) only when it is empty; a non-empty one stays. */
    private static function remove_empty_folder(string $dir): void
    {
        if (basename($dir) !== self::FOLDER || is_link($dir) || !is_dir($dir)) {
            return;
        }
        $names = @scandir($dir);
        if ($names !== false && array_diff($names, ['.', '..']) === []) {
            @rmdir($dir);
        }
    }

    /** The 32-hex name of a well-formed entry's file, else null. */
    private static function hex(array $entry): ?string
    {
        $path = $entry['path'] ?? null;
        if (!is_string($path) || basename(dirname($path)) !== self::FOLDER) {
            return null;
        }
        return preg_match('/^([0-9a-f]{32})\.php$/', basename($path), $m) === 1 ? $m[1] : null;
    }

    /** @return list<array> well-formed entries of the probes option */
    private static function entries(): array
    {
        $value = get_option(Options::name('probes'), []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn($e): bool => is_array($e) && isset($e['path']) && is_string($e['path'])));
    }

    /** Fenced write of the entries; an empty list deletes the option. */
    private static function store(array $entries): bool
    {
        return $entries === []
            ? Mutex::delete(Options::name('probes'))
            : Mutex::write(Options::name('probes'), $entries);
    }

    private static function find(string $path, ?array $entries = null): ?int
    {
        foreach ($entries ?? self::entries() as $i => $entry) {
            if ($entry['path'] === $path) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Files in the probe folder (by default the current one) that no entry records.
     *
     * @param list<string> $registered
     */
    private static function unregistered(array $registered, ?string $dir = null): array
    {
        if ($dir === null) {
            $loc = Locations::current();
            if ($loc['uploads'] === null) {
                return [];
            }
            $dir = $loc['uploads']['dir'] . self::FOLDER;
        }
        if (basename($dir) !== self::FOLDER || is_link($dir)) {
            return [];
        }
        $names = is_dir($dir) ? @scandir($dir) : false;
        $found = [];
        foreach ($names ?: [] as $name) {
            $path = $dir . '/' . $name;
            if ($name !== '.' && $name !== '..' && !in_array($path, $registered, true)) {
                $found[] = $path;
            }
        }
        return $found;
    }

    /** Turns the fetch result into facts; the body itself is not kept. */
    private static function facts(array $r, string $marker): array
    {
        if ($r['error'] !== '') {
            return self::observation('fetch', ['error' => $r['error']]);
        }
        $body = (string) $r['body'];
        $location = preg_replace('/[\x00-\x1f\x7f]/', '', (string) $r['location']);

        return self::observation('', [
            'status'    => (int) $r['status'],
            'marker'    => $body === $marker,
            'source'    => strpos($body, '<?php') !== false,
            'redirect'  => (bool) $r['redirect'],
            'location'  => substr((string) $location, 0, self::MAX_LOCATION),
            'challenge' => (bool) $r['challenge'],
            'empty'     => $body === '',
            'truncated' => (bool) $r['truncated'],
        ]);
    }

    private static function observation(string $step, array $facts = []): array
    {
        return array_replace([
            'step'           => $step,
            'reason'         => '',
            'error'          => '',
            'status'         => 0,
            'marker'         => false,
            'source'         => false,
            'redirect'       => false,
            'location'       => '',
            'challenge'      => false,
            'empty'          => false,
            'truncated'      => false,
            'cleanup_failed' => [],
            'unregistered'   => [],
            'comparison'     => ['status' => 0, 'redirect' => false],
        ], $facts);
    }

    /** The outcome table, top to bottom. */
    private static function finding_for(array $obs): array
    {
        $f = static fn(string $status, string $label): array => ServerChecks::finding('php_in_uploads', 'probe', $status, $label);
        $step = (string) ($obs['step'] ?? 'missing');

        if ($step !== '') {
            $labels = [
                'consent'  => __('Not requested: the active test was not ticked for this run.', 'sfxtheme'),
                'location' => __('The uploads folder cannot be tested from here.', 'sfxtheme') . ' ' . (string) ($obs['reason'] ?? ''),
                'busy'     => __('Busy, please try again.', 'sfxtheme'),
                'run'      => __('This run is no longer current (a newer check or a data purge); nothing was created.', 'sfxtheme'),
                'create'   => __('The test file could not be created.', 'sfxtheme'),
                'register' => __('The test file could not be recorded, so it was not fetched.', 'sfxtheme'),
                'write'    => __('The test file could not be written completely, so it was not fetched.', 'sfxtheme'),
                'entry'    => __('The test file\'s record was gone before the fetch had finished (the theme data was probably deleted meanwhile).', 'sfxtheme'),
                'fetch'    => sprintf(
                    /* translators: %s: error kind, e.g. timeout */
                    __('The test file could not be fetched (%s).', 'sfxtheme'),
                    (string) ($obs['error'] ?? '')
                ),
            ];
            return $f(Status::UNKNOWN, trim($labels[$step] ?? __('No result.', 'sfxtheme')));
        }

        $status = (int) ($obs['status'] ?? 0);
        if (!empty($obs['marker'])) {
            return $f(Status::RED, __('PHP runs in the uploads folder.', 'sfxtheme'));
        }
        if (!empty($obs['source'])) {
            return $f(Status::YELLOW, __('The source code is delivered, but not executed.', 'sfxtheme'));
        }
        if (!empty($obs['redirect']) || ($status >= 300 && $status < 400)) {
            return $f(Status::UNKNOWN, sprintf(
                /* translators: %s: redirect target */
                __('Redirects to %s; not followed.', 'sfxtheme'),
                (string) ($obs['location'] ?? '')
            ));
        }

        // Rule 3 without redirects, rule 4 winning over it.
        $response = [
            'status'    => $status,
            'body'      => empty($obs['empty']) ? '-' : '',
            'truncated' => !empty($obs['truncated']),
            'redirect'  => false,
            'challenge' => !empty($obs['challenge']),
        ];
        $comparison = is_array($obs['comparison'] ?? null) ? $obs['comparison'] : [];
        $comparison = ['status' => (int) ($comparison['status'] ?? 0), 'redirect' => !empty($comparison['redirect'])];
        if (Evidence::classify($response, $comparison) === Evidence::UNREACHABLE) {
            return $f(Status::GREEN, sprintf(
                /* translators: %d: HTTP status */
                __('Blocked (HTTP %d).', 'sfxtheme'),
                $status
            ));
        }

        return $f(Status::UNKNOWN, sprintf(
            /* translators: %d: HTTP status */
            __('No reliable answer (HTTP %d).', 'sfxtheme'),
            $status
        ));
    }
}
