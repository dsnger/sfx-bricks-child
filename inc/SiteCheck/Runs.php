<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\ServerChecks;

/**
 * The manual run (spec "Manual run") and the manual items.
 *
 * Option `manual` = ['issued' => issued run, 'saved' => last saved run]:
 *
 * - issued: {run, started, user, profile, indexability_paths (valid ones
 *   only), sitemap_allow, fallback_theme, probe (consent, this run only),
 *   checks (the catalogue IDs this run covers)} — written by start(),
 *   dropped by the save that consumes it and by purge.
 * - saved: {run, date, user, profile, results: [check => graded]} — the
 *   only thing a run leaves behind (spec rule 7); read by last().
 *
 * **What save trusts.** Every endpoint that observes (server, observe) also
 * returns a `token`: the facts it produced, sealed with an HMAC over the run
 * ID, the check ID and the exact JSON bytes (seal()). The save carries one
 * token per check and re-grades the unsealed facts against the issued run's
 * snapshot. So the stored grade always comes from facts this server produced
 * for this run and this check; a browser can drop a check (→ Nicht prüfbar)
 * but cannot alter or invent facts, replay another run's, or move one
 * check's facts to another. Nothing is re-observed at save and the probe is
 * never re-run: its result enters only through its own sealed facts.
 *
 * Option `items` = [item => {user, date}]: ticked manual items only.
 */
final class Runs
{
    /** The fixed manual items (spec "Manual items"), in display order. */
    public const ITEMS = ['contact_form', 'offsite_backup', 'two_factor', 'accounts_handover'];

    /** A saved run larger than this (serialized) is refused. */
    public const MAX_BYTES = 524288;

    /** Compaction (manual-run storage and display only): findings kept per check. */
    public const MAX_FILE_FINDINGS = 50;
    public const MAX_COVERAGE_FINDINGS = 20;

    /** Stored labels and notes are cut to this many bytes. */
    private const MAX_TEXT = 300;

    /** Failure kinds a browser may report instead of a token. */
    private const FAILURES = ['request_failed', 'timeout'];

    /** @var callable|null test seam: runs inside save's section, between its validity check and its write */
    private static $before_write = null;

    public static function register(): void
    {
        foreach (['start', 'server', 'save', 'items'] as $action) {
            add_action('wp_ajax_sfx_site_check_' . $action, [self::class, 'handle_' . $action]);
        }
    }

    /** @internal For tests and the live harness only. */
    public static function before_write(?callable $fn): void
    {
        self::$before_write = $fn;
    }

    // ------------------------------------------------------------ run

    /**
     * Issues a new run, replacing any issued one; the saved run stays.
     *
     * @return array{run:string, checks:list<array>}|string the run, or 'busy' / 'failed'
     */
    public static function start(bool $probe)
    {
        $settings = Settings::get();
        $issued = [
            'run' => bin2hex(random_bytes(16)),
            'started' => time(),
            'user' => get_current_user_id(),
            'profile' => $settings['profile'],
            // RunContext does not filter: an invalid stored path never reaches a run.
            'indexability_paths' => array_values(array_filter($settings['indexability_paths'], [Settings::class, 'valid_path'])),
            'sitemap_allow' => $settings['sitemap_allow'],
            'fallback_theme' => $settings['fallback_theme'],
            'probe' => $probe,
            'checks' => array_keys(Catalogue::all()),
        ];

        $written = Mutex::with(static function () use ($issued): bool {
            $manual = self::manual();
            $manual['issued'] = $issued;
            return Mutex::write(Options::name('manual'), $manual);
        });
        if ($written === Mutex::Busy) {
            return 'busy';
        }
        if ($written !== true) {
            return 'failed';
        }

        $checks = [];
        foreach (Catalogue::all() as $id => $row) {
            $checks[] = ['id' => $id, 'how' => $row['how'], 'perspective' => Catalogue::perspective_for($id, $row['how'])];
        }
        return ['run' => $issued['run'], 'checks' => $checks];
    }

    /** The issued run's snapshot, or null when $run is not the issued run. */
    public static function context(string $run): ?RunContext
    {
        $issued = self::issued();
        if ($issued === null || !hash_equals($issued['run'], $run)) {
            return null;
        }
        $list = static fn($value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];

        return new RunContext(
            $issued['run'],
            isset($issued['profile']) && in_array($issued['profile'], Settings::PROFILES, true) ? $issued['profile'] : 'live',
            $list($issued['indexability_paths'] ?? []),
            $list($issued['sitemap_allow'] ?? []),
            isset($issued['fallback_theme']) && is_string($issued['fallback_theme']) ? $issued['fallback_theme'] : '',
            ($issued['probe'] ?? false) === true
        );
    }

    /**
     * Observes one server check (catalogue `how` = S) for the issued run.
     * The probe runs only when the run carries consent; Probe::run() itself
     * refuses otherwise and creates nothing.
     *
     * @return array{graded:array, observation:array, token:string}
     */
    public static function observe_server(string $check, RunContext $ctx): array
    {
        $observation = $check === 'php_in_uploads'
            // Re-checked inside the section that creates the file (spec "Writes"):
            // a purge or a newer start in between means nothing is created.
            ? Probe::run($ctx, static fn(): bool => self::consented($ctx->run()))
            : ServerChecks::observe($check, $ctx);

        return [
            'graded' => self::compact($check, Catalogue::grade($check, $observation, $ctx)),
            'observation' => $observation,
            'token' => self::seal($ctx->run(), $check, $observation),
        ];
    }

    /**
     * Re-grades and stores a run. $results maps check ID → {token} or
     * {error: request_failed|timeout}; every issued check without a valid
     * entry is stored as Nicht prüfbar with the reason, IDs not issued are
     * ignored.
     *
     * @return string saved | no_run | superseded | too_large | busy | refused
     */
    public static function save(string $run, array $results): string
    {
        $ctx = self::context($run);
        if ($ctx === null) {
            return self::issued() === null ? 'no_run' : 'superseded';
        }
        $issued = self::issued();
        $catalogue = Catalogue::all();

        $graded = [];
        foreach ((array) ($issued['checks'] ?? []) as $check) {
            if (is_string($check) && isset($catalogue[$check])) {
                $graded[$check] = self::settle($check, $results[$check] ?? null, $ctx, $catalogue[$check]['how']);
            }
        }
        $saved = [
            'run' => $run,
            'date' => time(),
            'user' => get_current_user_id(),
            'profile' => $ctx->profile(),
            'results' => $graded,
        ];
        if (strlen(serialize($saved)) > self::MAX_BYTES) {
            return 'too_large';
        }

        $outcome = Mutex::with(static function () use ($run, $saved): string {
            $issued = self::issued();
            if ($issued === null) {
                return 'no_run';
            }
            if (!hash_equals($issued['run'], $run)) {
                return 'superseded';
            }
            if (self::$before_write !== null) {
                (self::$before_write)();
            }
            // The run is consumed: its consent and snapshot go with it.
            return Mutex::write(Options::name('manual'), ['saved' => $saved]) ? 'saved' : 'refused';
        });

        return $outcome === Mutex::Busy ? 'busy' : $outcome;
    }

    /**
     * The last saved run, or null.
     *
     * @return array{run:string, date:int, user:int, profile:string, results:array<string,array>}|null
     */
    public static function last(): ?array
    {
        $saved = self::manual()['saved'] ?? null;
        if (!is_array($saved) || !isset($saved['run'], $saved['results']) || !is_array($saved['results'])) {
            return null;
        }
        return [
            'run' => (string) $saved['run'],
            'date' => (int) ($saved['date'] ?? 0),
            'user' => (int) ($saved['user'] ?? 0),
            'profile' => (string) ($saved['profile'] ?? ''),
            'results' => $saved['results'],
        ];
    }

    // ------------------------------------------------------------ sealed facts

    public static function seal(string $run, string $check, array $facts): string
    {
        $json = json_encode($facts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $payload . '.' . self::mac($run, $check, $payload);
    }

    /** The facts sealed for exactly this run and check, else null. */
    public static function unseal(string $run, string $check, $token): ?array
    {
        if (!is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $mac] = explode('.', $token);
        if ($payload === '' || !hash_equals(self::mac($run, $check, $payload), $mac)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $facts = is_string($json) ? json_decode($json, true) : null;

        return is_array($facts) ? $facts : null;
    }

    // ------------------------------------------------------------ manual items

    /** @return array<string, array{user:int, date:int}> ticked items only */
    public static function items(): array
    {
        $stored = get_option(Options::name('items'), []);
        $items = [];
        foreach (self::ITEMS as $item) {
            if (is_array($stored) && isset($stored[$item]) && is_array($stored[$item])) {
                $items[$item] = ['user' => (int) ($stored[$item]['user'] ?? 0), 'date' => (int) ($stored[$item]['date'] ?? 0)];
            }
        }
        return $items;
    }

    /** @return string saved | unknown | busy | refused */
    public static function set_item(string $item, bool $done): string
    {
        if (!in_array($item, self::ITEMS, true)) {
            return 'unknown';
        }
        $outcome = Mutex::with(static function () use ($item, $done): string {
            $items = self::items();
            if ($done) {
                $items[$item] = ['user' => get_current_user_id(), 'date' => time()];
            } else {
                unset($items[$item]);
            }
            return self::store_items($items) ? 'saved' : 'refused';
        });
        return $outcome === Mutex::Busy ? 'busy' : $outcome;
    }

    /** Clears all four items; the saved run is not touched. */
    public static function reset_items(): string
    {
        $outcome = Mutex::with(static fn(): string => self::store_items([]) ? 'saved' : 'refused');
        return $outcome === Mutex::Busy ? 'busy' : $outcome;
    }

    // ------------------------------------------------------------ AJAX

    public static function handle_start(): void
    {
        self::send(self::start_request());
    }

    public static function handle_server(): void
    {
        self::send(self::server_request());
    }

    public static function handle_save(): void
    {
        self::send(self::save_request());
    }

    public static function handle_items(): void
    {
        self::send(self::items_request());
    }

    /** POST probe ('1' = the active test was ticked). @return array{0:int, 1:array} */
    public static function start_request(): array
    {
        if (!self::gate()) {
            return self::denied();
        }
        $started = self::start(isset($_POST['probe']) && $_POST['probe'] === '1');
        if (!is_array($started)) {
            return self::outcome_error($started);
        }
        return [200, ['success' => true, 'data' => $started]];
    }

    /** POST run, check (a catalogue check with `how` = S). @return array{0:int, 1:array} */
    public static function server_request(): array
    {
        if (!self::gate()) {
            return self::denied();
        }
        $check = isset($_POST['check']) && is_string($_POST['check']) ? $_POST['check'] : '';
        $catalogue = Catalogue::all();
        if (!isset($catalogue[$check]) || $catalogue[$check]['how'] !== 'S') {
            return self::error(400, __('Unknown check.', 'sfxtheme'));
        }
        $ctx = self::context(self::posted_run());
        if ($ctx === null) {
            return self::outcome_error('superseded');
        }
        return [200, ['success' => true, 'data' => self::observe_server($check, $ctx)]];
    }

    /** POST run, results (JSON object check → {token} | {error}). @return array{0:int, 1:array} */
    public static function save_request(): array
    {
        if (!self::gate()) {
            return self::denied();
        }
        $raw = isset($_POST['results']) && is_string($_POST['results']) ? wp_unslash($_POST['results']) : '';
        $results = json_decode($raw, true);
        if (!is_array($results)) {
            return self::error(400, __('The results could not be read. The previous results stay.', 'sfxtheme'));
        }
        $outcome = self::save(self::posted_run(), $results);
        if ($outcome !== 'saved') {
            return self::outcome_error($outcome);
        }
        $last = self::last();
        // The page shows dates as the server formats them (site zone and format).
        return [200, ['success' => true, 'data' => ['last' => $last === null ? null : $last + ['date_display' => self::date_display($last['date'])]]]];
    }

    /** POST item + done ('1'/'0'), or reset = '1'. @return array{0:int, 1:array} */
    public static function items_request(): array
    {
        if (!self::gate()) {
            return self::denied();
        }
        if (isset($_POST['reset']) && $_POST['reset'] === '1') {
            $outcome = self::reset_items();
        } else {
            $item = isset($_POST['item']) && is_string($_POST['item']) ? $_POST['item'] : '';
            $outcome = self::set_item($item, isset($_POST['done']) && $_POST['done'] === '1');
        }
        if ($outcome === 'unknown') {
            return self::error(400, __('Unknown item.', 'sfxtheme'));
        }
        if ($outcome !== 'saved') {
            return self::outcome_error($outcome);
        }
        $items = array_map(static fn(array $item): array => $item + ['date_display' => self::date_display($item['date'])], self::items());

        return [200, ['success' => true, 'data' => ['items' => $items]]];
    }

    /** A timestamp in the site's time zone and date/time format; the page and the box show only this. */
    public static function date_display(int $timestamp): string
    {
        return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    // ------------------------------------------------------------ internals

    /** One check of a save: the sealed facts re-graded, or Nicht prüfbar with the reason. */
    private static function settle(string $check, $entry, RunContext $ctx, string $how): array
    {
        if ($entry === null) {
            return self::unknown(__('No result was sent for this check.', 'sfxtheme'), $check, $how);
        }
        if (is_array($entry) && isset($entry['error']) && in_array($entry['error'], self::FAILURES, true)) {
            return self::unknown(__('Request failed.', 'sfxtheme'), $check, $how);
        }
        $facts = is_array($entry) ? self::unseal($ctx->run(), $check, $entry['token'] ?? null) : null;
        if ($facts === null) {
            return self::unknown(__('The result could not be verified and was not used.', 'sfxtheme'), $check, $how);
        }

        return self::bounded(self::compact($check, Catalogue::grade($check, $facts, $ctx)));
    }

    /**
     * Bounds a manual grade wherever it leaves the server (saved run, server
     * and observe answers); sealed facts and Catalogue::grade() stay complete.
     * Per check at most MAX_FILE_FINDINGS findings and MAX_COVERAGE_FINDINGS
     * coverage findings (a finding with `coverage`: unreadable folder, scan
     * limit). Only a group over its cap changes: kept by worst status, then
     * the check's own priority (`first`), then path, and the dropped rest
     * becomes one `<check>:more-files` / `<check>:more-coverage` line with the
     * worst dropped status. The check's status is the one graded before.
     * Findings leave with id, status and label only.
     */
    public static function compact(string $check, array $graded): array
    {
        $groups = ['files' => [], 'coverage' => []];
        foreach ((array) ($graded['findings'] ?? []) as $f) {
            $groups[empty($f['coverage']) ? 'files' : 'coverage'][] = $f;
        }
        $caps = ['files' => self::MAX_FILE_FINDINGS, 'coverage' => self::MAX_COVERAGE_FINDINGS];
        $kept = [];
        $overflow = [];
        $over = false;
        foreach ($groups as $group => $findings) {
            if (count($findings) <= $caps[$group]) {
                $kept = array_merge($kept, $findings);
                continue;
            }
            $over = true;
            usort($findings, [self::class, 'compare']);
            $dropped = array_slice($findings, $caps[$group]);
            $kept = array_merge($kept, array_slice($findings, 0, $caps[$group]));
            $overflow[] = [
                'id' => Finding::id($check, 'more-' . $group),
                'status' => Status::worst(array_column($dropped, 'status'), Status::UNKNOWN),
                /* translators: %d: number of findings not listed */
                'label' => sprintf(__('… and %d more', 'sfxtheme'), count($dropped)),
            ];
        }
        if ($over) {
            usort($kept, [self::class, 'compare']);
        } else {
            $kept = (array) ($graded['findings'] ?? []);
        }

        $graded['findings'] = array_map(
            static fn(array $f): array => ['id' => (string) ($f['id'] ?? ''), 'status' => (string) ($f['status'] ?? Status::UNKNOWN), 'label' => (string) ($f['label'] ?? '')],
            array_merge($kept, $overflow)
        );

        return $graded;
    }

    /** Worst status first, then the check's own priority, then path (the target in the ID). */
    private static function compare(array $a, array $b): int
    {
        $rank = static function (array $f): int {
            $i = array_search($f['status'] ?? '', Status::ORDER, true);
            return $i === false ? count(Status::ORDER) : (int) $i;
        };
        $target = static fn(array $f): string => (string) substr((string) ($f['id'] ?? ''), (int) strpos((string) ($f['id'] ?? ''), ':') + 1);

        return [$rank($a), empty($a['first']), $target($a)] <=> [$rank($b), empty($b['first']), $target($b)];
    }

    private static function unknown(string $reason, string $check, string $how): array
    {
        return ['status' => Status::UNKNOWN, 'findings' => [], 'perspective' => Catalogue::perspective_for($check, $how), 'note' => $reason];
    }

    /** Only the graded shape, texts cut (spec "Stored text is size-limited"). */
    private static function bounded(array $graded): array
    {
        $findings = [];
        foreach ((array) ($graded['findings'] ?? []) as $f) {
            $findings[] = [
                'id' => (string) ($f['id'] ?? ''),
                'status' => (string) ($f['status'] ?? Status::UNKNOWN),
                'label' => Observations::short((string) ($f['label'] ?? ''), self::MAX_TEXT),
            ];
        }
        return [
            'status' => (string) ($graded['status'] ?? Status::UNKNOWN),
            'findings' => $findings,
            'perspective' => (string) ($graded['perspective'] ?? 'server'),
            'note' => Observations::short((string) ($graded['note'] ?? ''), self::MAX_TEXT),
        ];
    }

    private static function mac(string $run, string $check, string $payload): string
    {
        return hash_hmac('sha256', 'sfx-site-check-facts|' . $run . '|' . $check . '|' . $payload, wp_salt('nonce'));
    }

    private static function manual(): array
    {
        $manual = get_option(Options::name('manual'), []);
        return is_array($manual) ? $manual : [];
    }

    /** @return array{run:string}|null */
    private static function issued(): ?array
    {
        $issued = self::manual()['issued'] ?? null;
        if (!is_array($issued) || !isset($issued['run']) || !is_string($issued['run']) || $issued['run'] === '') {
            return null;
        }
        return $issued;
    }

    /** Inside a section: $run is still the issued run and carries probe consent. */
    private static function consented(string $run): bool
    {
        $issued = self::issued();
        return $issued !== null && hash_equals($issued['run'], $run) && ($issued['probe'] ?? false) === true;
    }

    private static function store_items(array $items): bool
    {
        return $items === []
            ? Mutex::delete(Options::name('items'))
            : Mutex::write(Options::name('items'), $items);
    }

    /** Access and nonce, before any other input is read. */
    private static function gate(): bool
    {
        return Access::allowed() && check_ajax_referer(Access::NONCE, '_ajax_nonce', false) !== false;
    }

    private static function posted_run(): string
    {
        return isset($_POST['run']) && is_string($_POST['run']) ? wp_unslash($_POST['run']) : '';
    }

    private static function denied(): array
    {
        return self::error(403, __('You are not allowed to do this.', 'sfxtheme'));
    }

    private static function outcome_error(string $outcome): array
    {
        switch ($outcome) {
            case 'busy':
                return self::error(503, __('Busy, please try again.', 'sfxtheme'));
            case 'no_run':
            case 'superseded':
                return self::error(409, __('This run is no longer current. Start the check again.', 'sfxtheme'));
            case 'too_large':
                return self::error(413, __('The results are too large to be saved. The previous results stay.', 'sfxtheme'));
        }
        return self::error(500, __('Could not be saved. Nothing was changed.', 'sfxtheme'));
    }

    private static function error(int $code, string $message): array
    {
        return [$code, ['success' => false, 'data' => ['message' => $message]]];
    }

    private static function send(array $response): void
    {
        wp_send_json($response[1], $response[0]);
    }
}
