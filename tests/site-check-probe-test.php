<?php

declare(strict_types=1);

/**
 * SiteCheck uploads probe (spec "Uploads probe (active test)", "Storage" →
 * Writes and Purge) and SiteCheck's purge segment.
 *
 * Real files in a temporary uploads folder, a stubbed $wpdb that understands
 * the Mutex statements (as in site-check-mutex-test.php), and a stubbed HTTP
 * layer. "Another process" runs from a hook in the fake $wpdb that fires when
 * a mutex acquire starts — the moment a second PHP process could slip in.
 *
 * Time, random_bytes() and fwrite() are replaced in the module's namespace so
 * the 5 s mutex wait costs no real time, a probe name can be forced, and a
 * write can fail after the open. The generated probe file is executed with
 * the real PHP CLI, which knows nothing of these replacements.
 *
 * Every fixture lives under one temporary tree, removed by the single
 * teardown declared below before the first fixture.
 */

namespace SFX\SiteCheck {
    function time(): int
    {
        return (int) floor(\FakeClock::$now);
    }

    function microtime(bool $as_float = false)
    {
        return \FakeClock::$now;
    }

    function usleep(int $microseconds): void
    {
        \FakeClock::$now += $microseconds / 1000000;
    }

    function random_bytes(int $length): string
    {
        if (\Seams::$name !== null) {
            $bytes = \Seams::$name;
            \Seams::$name = null;
            return $bytes;
        }
        return \random_bytes($length);
    }

    function unlink(string $filename, $context = null): bool
    {
        if (\Seams::$fail_unlink) {
            return false;
        }
        return \unlink($filename);
    }

    function fwrite($handle, string $data, ?int $length = null)
    {
        if (\Seams::$fail_write) {
            return false;
        }
        return $length === null ? \fwrite($handle, $data) : \fwrite($handle, $data, $length);
    }
}

namespace {
    use SFX\SiteCheck\Catalogue;
    use SFX\SiteCheck\Fetch;
    use SFX\SiteCheck\Options;
    use SFX\SiteCheck\Probe;
    use SFX\SiteCheck\Purge;
    use SFX\SiteCheck\RunContext;
    use SFX\SiteCheck\Status;

    final class FakeClock
    {
        public static float $now = 0.0;
    }
    FakeClock::$now = (float) \time();

    final class Seams
    {
        public static ?string $name = null;
        public static bool $fail_write = false;
        public static bool $fail_unlink = false;
    }

    $tmp = sys_get_temp_dir() . '/sfx-site-check-probe-' . bin2hex(\random_bytes(6));

    function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    // One teardown, declared before the first fixture is created.
    register_shutdown_function(static function () use ($tmp): void {
        remove_tree($tmp);
    });

    if (!mkdir($tmp, 0700)) {
        fwrite(STDERR, "error: cannot create {$tmp}\n");
        exit(2);
    }
    $tmp = realpath($tmp);
    if ($tmp === false || strpos($tmp, realpath(sys_get_temp_dir())) !== 0) {
        fwrite(STDERR, "error: temp dir is not under the system temp dir\n");
        exit(2);
    }
    foreach (['/site/wp-content/plugins', '/site/wp-content/uploads', '/site/wp-content/uploads-new'] as $dir) {
        mkdir($tmp . $dir, 0700, true);
    }
    file_put_contents($tmp . '/site/wp-config.php', "<?php\n");

    define('ABSPATH', $tmp . '/site/');
    define('WP_CONTENT_DIR', $tmp . '/site/wp-content');
    define('WP_PLUGIN_DIR', $tmp . '/site/wp-content/plugins');

    $uploads = WP_CONTENT_DIR . '/uploads';
    $folder = $uploads . '/sfx-site-check';

    // ------------------------------------------------------------ $wpdb double

    final class FakeWpdb
    {
        public string $options = 'wp_options';
        /** @var array<string,string> */
        public array $rows = [];
        /** @var array<string,string> */
        public array $autoload = [];
        public int $acquires = 0;
        /** @var array<int,callable> acquire number => what another process does first */
        public array $on_acquire = [];
        /** @var array<string,callable> option name => what happens right before its fenced delete */
        public array $on_delete = [];
        /** @var list<string> option names whose fenced write or delete fails as a database error */
        public array $fail = [];

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            return json_encode([$query, $args]);
        }

        public function query($prepared)
        {
            [$q, $a] = json_decode($prepared, true);
            if (str_starts_with($q, 'INSERT IGNORE')) {
                $this->acquires++;
                if (isset($this->on_acquire[$this->acquires])) {
                    $other = $this->on_acquire[$this->acquires];
                    unset($this->on_acquire[$this->acquires]);
                    $other();
                }
                [$name, $value] = $a;
                if (isset($this->rows[$name])) {
                    return 0;
                }
                $this->rows[$name] = $value;
                return 1;
            }
            if (str_starts_with($q, 'UPDATE')) {
                [$new, $name, $old] = $a;
                if (($this->rows[$name] ?? null) !== $old) {
                    return 0;
                }
                $this->rows[$name] = $new;
                return 1;
            }
            if (str_starts_with($q, 'INSERT INTO')) {
                [$option, $value, $autoload, $mutex, $held, $update_value, $update_autoload] = $a;
                if (in_array($option, $this->fail, true)) {
                    return false;
                }
                if (($this->rows[$mutex] ?? null) !== $held) {
                    return 0;
                }
                if (!array_key_exists($option, $this->rows)) {
                    $this->rows[$option] = $value;
                    $this->autoload[$option] = $autoload;
                    return 1;
                }
                if ($this->rows[$option] === $update_value && ($this->autoload[$option] ?? null) === $update_autoload) {
                    return 0;
                }
                $this->rows[$option] = $update_value;
                $this->autoload[$option] = $update_autoload;
                return 2;
            }
            if (str_starts_with($q, 'DELETE o')) {
                [$mutex, $held, $option] = $a;
                if (isset($this->on_delete[$option])) {
                    $other = $this->on_delete[$option];
                    unset($this->on_delete[$option]);
                    $other();
                }
                if (in_array($option, $this->fail, true)) {
                    return false;
                }
                if (($this->rows[$mutex] ?? null) !== $held || !array_key_exists($option, $this->rows)) {
                    return 0;
                }
                unset($this->rows[$option]);
                return 1;
            }
            if (str_starts_with($q, 'DELETE FROM')) {
                [$name, $value] = $a;
                if (in_array($name, $this->fail, true)) {
                    return false;
                }
                if (($this->rows[$name] ?? null) !== $value) {
                    return 0;
                }
                unset($this->rows[$name]);
                return 1;
            }
            throw new RuntimeException('FakeWpdb: unexpected query ' . $q);
        }

        public function get_var($prepared)
        {
            [$q, $a] = json_decode($prepared, true);
            if (str_starts_with($q, 'SELECT option_value')) {
                return $this->rows[$a[0]] ?? null;
            }
            if (str_starts_with($q, 'SELECT COUNT(*)') && count($a) === 4) {
                [$mutex, $held, $option, $value] = $a;
                return (($this->rows[$mutex] ?? null) === $held && ($this->rows[$option] ?? null) === $value) ? '1' : '0';
            }
            if (str_starts_with($q, 'SELECT COUNT(*)') && count($a) === 2) {
                [$mutex, $held] = $a;
                return (($this->rows[$mutex] ?? null) === $held) ? '1' : '0';
            }
            throw new RuntimeException('FakeWpdb: unexpected get_var ' . $q);
        }
    }

    $GLOBALS['wpdb'] = new FakeWpdb();
    $db = $GLOBALS['wpdb'];

    // ---------------------------------------------------------------- stubs

    $GLOBALS['stub'] = [
        'uploads'  => ['basedir' => $uploads, 'baseurl' => 'https://ex.test/wp-content/uploads', 'error' => false],
        'answer'   => null,
        'requests' => [],
        'cleared'  => [],
    ];
    $GLOBALS['cache'] = [];

    function __($text, $domain = null)
    {
        return $text;
    }

    function maybe_serialize($data)
    {
        return (is_array($data) || is_object($data)) ? serialize($data) : $data;
    }

    function maybe_unserialize($data)
    {
        if (!is_string($data)) {
            return $data;
        }
        $value = @unserialize($data);
        return ($value !== false || $data === 'b:0;') ? $value : $data;
    }

    /** With $GLOBALS['option_cache'] on, options are cached like WordPress does (object cache, group `options`). */
    function get_option($name, $default = false)
    {
        if (!empty($GLOBALS['option_cache'])) {
            $cached = wp_cache_get($name, 'options');
            if ($cached !== false) {
                return $cached;
            }
        }
        $rows = $GLOBALS['wpdb']->rows;
        if (!array_key_exists($name, $rows)) {
            return $default;
        }
        $value = maybe_unserialize($rows[$name]);
        if (!empty($GLOBALS['option_cache'])) {
            wp_cache_set($name, $value, 'options');
        }
        return $value;
    }

    function wp_cache_get($key, $group = '')
    {
        return $GLOBALS['cache'][$group][$key] ?? false;
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        $GLOBALS['cache'][$group][$key] = $data;
        return true;
    }

    function wp_cache_delete($key, $group = '')
    {
        unset($GLOBALS['cache'][$group][$key]);
        return true;
    }

    function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false)
    {
        $GLOBALS['stub']['cleared'][] = $hook;
        if (!empty($GLOBALS['stub']['clear_fail'])) {
            return $wp_error ? new WP_Error('could_not_set', 'The cron event list could not be saved.') : false;
        }
        return 0;
    }

    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['stub']['actions'][$hook][] = $callback;
        return true;
    }

    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['stub']['filters'][$hook][] = $callback;
        return true;
    }

    function wp_next_scheduled($hook, $args = [])
    {
        return $GLOBALS['stub']['scheduled'][$hook] ?? false;
    }

    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false)
    {
        $GLOBALS['stub']['scheduled'][$hook] = $recurrence;
        return true;
    }

    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }

    function wp_normalize_path($path)
    {
        $path = str_replace('\\', '/', $path);
        return preg_replace('|(?<=.)/+|', '/', $path);
    }

    function trailingslashit($value)
    {
        return untrailingslashit($value) . '/';
    }

    function untrailingslashit($value)
    {
        return rtrim($value, '/\\');
    }

    function home_url($path = '')
    {
        return 'https://ex.test/' . ltrim($path, '/');
    }

    function site_url($path = '')
    {
        return 'https://ex.test/' . ltrim($path, '/');
    }

    function content_url($path = '')
    {
        return 'https://ex.test/wp-content';
    }

    function plugins_url($path = '', $plugin = '')
    {
        return 'https://ex.test/wp-content/plugins';
    }

    function wp_get_upload_dir()
    {
        return $GLOBALS['stub']['uploads'];
    }

    final class WP_Error
    {
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }

    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }

    /** The answer callback gets the URL and returns [status, body, headers] or a WP_Error. */
    function wp_salt($scheme = 'auth')
    {
        return 'test-salt';
    }

    function wp_remote_get($url, $args = [])
    {
        $GLOBALS['stub']['requests'][] = $url;
        $answer = $GLOBALS['stub']['answer'];
        if (strpos($url, '/sfx-site-check-missing-') !== false) {
            // The batch's comparison URL (rule 2): 404 unless a case says otherwise.
            $route = $GLOBALS['stub']['comparison'] ?? [404, 'Not found', []];
        } else {
            $route = $answer === null ? [404, 'Not found', []] : $answer($url);
        }
        if ($route instanceof WP_Error) {
            return $route;
        }
        [$status, $body, $headers] = $route + [2 => []];
        return ['response' => ['code' => $status], 'headers' => array_change_key_case($headers), 'body' => $body];
    }

    function wp_remote_retrieve_response_code($r)
    {
        return $r['response']['code'];
    }

    function wp_remote_retrieve_header($r, $name)
    {
        return $r['headers'][strtolower($name)] ?? '';
    }

    function wp_remote_retrieve_body($r)
    {
        return $r['body'];
    }

    spl_autoload_register(static function (string $class): void {
        if (strpos($class, 'SFX\\SiteCheck\\Checks\\') === 0) {
            require_once __DIR__ . '/../inc/SiteCheck/Checks/' . substr($class, 21) . '.php';
        } elseif (strpos($class, 'SFX\\SiteCheck\\') === 0) {
            require_once __DIR__ . '/../inc/SiteCheck/' . substr($class, 14) . '.php';
        }
    });

    // -------------------------------------------------------------- helpers

    function assert_true($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function assert_same($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function ctx(bool $probe = true, string $run = 'run-1'): RunContext
    {
        return new RunContext($run, 'live', [], [], '', $probe);
    }

    function entries(): array
    {
        $value = get_option(Options::name('probes'), []);
        return is_array($value) ? $value : [];
    }

    function url_to_path(string $url): string
    {
        $base = 'https://ex.test/wp-content/uploads/';
        assert_true(strpos($url, $base) === 0, "request under the uploads URL: {$url}");
        return $GLOBALS['stub']['uploads']['basedir'] . '/' . substr($url, strlen($base));
    }

    /** What a server that runs PHP in uploads answers: the file, executed by the real PHP CLI. */
    function execute(string $path): string
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        assert_true($code === 0, "probe file runs without error: {$path}");
        return implode("\n", $out);
    }

    function probe(?callable $answer, bool $consent = true, string $run = 'run-1'): array
    {
        $GLOBALS['stub']['answer'] = $answer;
        $GLOBALS['stub']['requests'] = [];
        return Probe::run(ctx($consent, $run));
    }

    function grade(array $obs): array
    {
        $result = Catalogue::grade('php_in_uploads', $obs, ctx());
        assert_same('loopback', $result['perspective'], 'probe perspective is Loopback');
        foreach ($result['findings'] as $f) {
            assert_true(strpos($f['id'], 'php_in_uploads:') === 0, 'finding ID starts with the check ID');
            assert_true(preg_match('/[0-9a-f]{32}/', $f['id']) !== 1, 'the random file name is never part of an ID');
        }
        return $result;
    }

    function main_finding(array $result): array
    {
        foreach ($result['findings'] as $f) {
            if ($f['id'] === 'php_in_uploads:probe') {
                return $f;
            }
        }
        assert_true(false, 'a php_in_uploads:probe finding');
        return [];
    }

    function folder_files(string $folder): array
    {
        $names = is_dir($folder) ? array_values(array_diff(scandir($folder) ?: [], ['.', '..'])) : [];
        sort($names);
        return $names;
    }

    /** Back to a clean state between cases. */
    function reset_state(): void
    {
        global $db, $folder;
        $db->rows = [];
        $db->autoload = [];
        $db->on_acquire = [];
        $db->on_delete = [];
        $db->fail = [];
        $db->acquires = 0;
        $GLOBALS['cache'] = [];
        $GLOBALS['stub']['cleared'] = [];
        $GLOBALS['stub']['uploads']['basedir'] = WP_CONTENT_DIR . '/uploads';
        $GLOBALS['stub']['uploads']['baseurl'] = 'https://ex.test/wp-content/uploads';
        $GLOBALS['stub']['uploads']['error'] = false;
        Seams::$name = null;
        Seams::$fail_write = false;
        Seams::$fail_unlink = false;
        remove_tree($folder);
        remove_tree(WP_CONTENT_DIR . '/uploads-new/sfx-site-check');
    }

    /** Pretend another process holds the mutex right now. */
    function hold_mutex(): void
    {
        $GLOBALS['wpdb']->rows[Options::name('mutex')] = 'other:' . (int) floor(FakeClock::$now);
    }

    /** A registered probe written by hand, as an earlier (crashed) request left it. */
    function fixture_probe(string $dir, int $expires, ?string $content = null, string $run = 'old-run'): array
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $entry = ['path' => $dir . '/' . bin2hex(\random_bytes(16)) . '.php', 'run' => $run, 'expires' => $expires];
        file_put_contents($entry['path'], $content ?? Probe::expected_content($entry));
        return $entry;
    }

    function set_entries(array $entries): void
    {
        $GLOBALS['wpdb']->rows[Options::name('probes')] = serialize($entries);
    }

    $now = static fn(): int => (int) floor(FakeClock::$now);

    // ------------------------------------------------- consent and location

    reset_state();
    $obs = probe(null, false);
    assert_same('consent', $obs['step'], 'without consent the probe refuses');
    assert_same([], $GLOBALS['stub']['requests'], 'without consent nothing is fetched');
    assert_true(!is_dir($folder), 'without consent no folder is created');
    assert_same([], entries(), 'without consent nothing is recorded');
    assert_same(Status::UNKNOWN, grade($obs)['status'], 'not requested → Nicht prüfbar');

    reset_state();
    $GLOBALS['stub']['uploads'] = ['basedir' => '', 'baseurl' => '', 'error' => 'no uploads'];
    $obs = probe(null);
    assert_same('location', $obs['step'], 'no uploads folder → location step');
    assert_same(Status::UNKNOWN, grade($obs)['status'], 'no uploads folder → Nicht prüfbar');
    $GLOBALS['stub']['uploads'] = ['basedir' => $uploads, 'baseurl' => 'https://ex.test/wp-content/uploads', 'error' => false];

    // ------------------------------------------------ PHP runs in uploads: Rot

    reset_state();
    $seen = [];
    $obs = probe(static function (string $url) use (&$seen, $folder): array {
        $path = url_to_path($url);
        $source = (string) file_get_contents($path);
        $entry = entries()[0] ?? [];
        $seen = [
            'path'       => $path,
            'source'     => $source,
            'entries'    => entries(),
            'marker'     => execute($path),
            'probe_url'  => Fetch::refusal($url),
            'other_php'  => Fetch::refusal('https://ex.test/wp-content/uploads/sfx-site-check/' . str_repeat('a', 32) . '.php'),
            'other_name' => Fetch::refusal('https://ex.test/wp-content/uploads/sfx-site-check/shell.php'),
            'encoded'    => Fetch::refusal(str_replace('.php', '%2Ephp', $url)),
            'query'      => Fetch::refusal($url . '?x=1'),
            'expected'   => Probe::expected_content($entry),
        ];
        return [200, $seen['marker']];
    });
    assert_same('', $obs['step'], 'a normal run completes every step');
    assert_same(2, count($GLOBALS['stub']['requests']), 'two fetches: the comparison URL (rule 2), then the probe');
    assert_true(strpos($GLOBALS['stub']['requests'][0], '/sfx-site-check-missing-') !== false, 'Gate B pass 3: the comparison URL comes first, by Loopback');
    assert_same(\SFX\SiteCheck\Checks\OutsideChecks::comparison_url('php_in_uploads', ctx()), $GLOBALS['stub']['requests'][0], 'it is the issued comparison URL of this check and run');
    assert_same(1, count($seen['entries']), 'the entry is recorded before the fetch');
    assert_same($seen['path'], $seen['entries'][0]['path'], 'the entry holds the full path');
    assert_same('run-1', $seen['entries'][0]['run'], 'the entry holds the run');
    assert_same($now() + 300, $seen['entries'][0]['expires'], 'the entry expires 5 minutes after creation');
    assert_true(preg_match('#/sfx-site-check/[0-9a-f]{32}\.php$#', $seen['path']) === 1, 'the probe is <uploads>/sfx-site-check/<32 hex>.php');
    assert_same($seen['expected'], $seen['source'], 'the file holds exactly the expected content');
    assert_true($seen['marker'] !== '', 'the probe prints its marker');
    assert_true(strpos($seen['source'], $seen['marker']) === false, 'the marker text does not appear in the generated source');
    assert_true(strpos($seen['source'], '<?php') === 0, 'the source is PHP');
    assert_true(preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)|include|require/i', $seen['source']) !== 1, 'no includes, no request parameters');
    assert_same('', $seen['probe_url'], 'rule 6: the probe URL may be fetched while the probe fetches it');
    assert_same('php', $seen['other_php'], 'rule 6: another .php in the probe folder stays refused, even during the fetch');
    assert_same('php', $seen['other_name'], 'rule 6: any other .php name in the probe folder stays refused');
    assert_same('php', $seen['encoded'], 'rule 6: an encoded variant of the probe URL stays refused');
    assert_same('php', $seen['query'], 'rule 6: the probe URL with anything added stays refused');
    assert_same('php', Fetch::refusal($GLOBALS['stub']['requests'][1]), 'rule 6: after the run the probe URL is refused again');
    assert_true(!file_exists($seen['path']), 'after a normal run the file is gone');
    assert_same([], entries(), 'after a normal run the entry is gone');
    assert_true(!file_exists($folder), 'after a normal run the empty probe folder is removed');
    assert_same([], $obs['cleanup_failed'], 'no cleanup line after a clean removal');
    $r = grade($obs);
    assert_same(Status::RED, $r['status'], 'body equals the marker → Rot');
    assert_same(1, count($r['findings']), 'one finding without a cleanup problem');
    assert_true(strpos($r['note'], '.php') !== false, 'the result says it covers .php in that folder only');
    assert_true(!array_key_exists('body', $obs) && strpos(serialize($obs), $seen['marker']) === false, 'the observation holds facts, not the body');

    // ---------------------------------------------------------- the outcome table

    $table = [
        'source'      => [static fn(string $url): array => [200, (string) file_get_contents(url_to_path($url))], Status::YELLOW],
        '403'         => [static fn(string $url): array => [403, 'Forbidden'], Status::GREEN],
        '404'         => [static fn(string $url): array => [404, 'Not found'], Status::GREEN],
        // Rule 3's comparison clause only fires on a 404/410 comparison, so "like the comparison" is a 404/410 answer.
        'like 404/410 comparison' => [static fn(string $url): array => [410, 'Gone'], Status::GREEN],
        'redirect'    => [static fn(string $url): array => [302, '', ['Location' => 'https://ex.test/wp-login.php']], Status::UNKNOWN],
        'empty 200'   => [static fn(string $url): array => [200, ''], Status::UNKNOWN],
        'other 200'   => [static fn(string $url): array => [200, '<html>a page</html>'], Status::UNKNOWN],
        '500'         => [static fn(string $url): array => [500, 'error'], Status::UNKNOWN],
        'challenge'   => [static fn(string $url): array => [403, 'blocked', ['cf-mitigated' => 'challenge']], Status::UNKNOWN],
        'timeout'     => [static fn(string $url) => new WP_Error('http', 'cURL error 28: Operation timed out'), Status::UNKNOWN],
    ];
    foreach ($table as $case => [$answer, $expected]) {
        reset_state();
        $obs = probe($answer);
        $r = grade($obs);
        assert_same($expected, $r['status'], "outcome: {$case}");
        assert_same([], folder_files($folder), "outcome {$case}: the file is removed");
        assert_same([], entries(), "outcome {$case}: the entry is removed");
    }
    reset_state();
    $r = grade(probe($table['redirect'][0]));
    assert_true(strpos(main_finding($r)['label'], 'https://ex.test/wp-login.php') !== false, 'a redirect names its target');
    assert_same(2, count($GLOBALS['stub']['requests']), 'a redirect is not followed (comparison plus the probe)');
    // Rule 3 with the probe's own comparison: an answer like a 410 comparison is "not reachable".
    reset_state();
    $GLOBALS['stub']['comparison'] = [410, 'Gone', []];
    $r = grade(probe(static fn(string $url): array => [410, 'Gone too']));
    unset($GLOBALS['stub']['comparison']);
    assert_same(Status::GREEN, $r['status'], 'a 410 like the 410 comparison → Grün');
    reset_state();
    assert_same(404, probe(static fn(string $url): array => [404, 'nf'])['comparison']['status'] ?? null, 'the comparison is kept in the facts');
    reset_state();
    assert_same('fetch', probe($table['timeout'][0])['step'], 'a failed fetch names the fetch step');

    // ------------------------------------------- create fails: name already taken

    reset_state();
    mkdir($folder, 0700, true);
    $taken = str_repeat("\xab", 16);
    $taken_path = $folder . '/' . bin2hex($taken) . '.php';
    file_put_contents($taken_path, 'not ours');
    Seams::$name = $taken;
    $obs = probe(null);
    assert_same('create', $obs['step'], 'an existing file at the drawn name → create failed');
    assert_same([], entries(), 'create failed → no entry');
    assert_same('not ours', file_get_contents($taken_path), 'the pre-existing file is untouched');
    assert_same([], $GLOBALS['stub']['requests'], 'create failed → nothing fetched');
    assert_same(Status::UNKNOWN, grade($obs)['status'], 'create failed → Nicht prüfbar');
    assert_true(strpos(main_finding(grade($obs))['label'], 'creat') !== false, 'the failing step is named');

    // ----------------------------------------------- write fails after the open

    reset_state();
    Seams::$fail_write = true;
    $obs = probe(null);
    Seams::$fail_write = false;
    assert_same('write', $obs['step'], 'write failure → write step');
    assert_same([], $GLOBALS['stub']['requests'], 'write failure → not fetched');
    assert_same(1, count(entries()), 'write failure → the entry is kept');
    $kept = entries()[0]['path'];
    assert_true(file_exists($kept), 'write failure → the file is kept');
    assert_same([$kept], $obs['cleanup_failed'], 'write failure → the file is reported');
    $r = grade($obs);
    assert_same(Status::UNKNOWN, main_finding($r)['status'], 'write failure → Nicht prüfbar for execution');
    assert_same(2, count($r['findings']), 'the cleanup failure is its own line');
    assert_same('php_in_uploads:probe-cleanup', $r['findings'][1]['id'], 'cleanup line ID');
    assert_same(Status::YELLOW, $r['findings'][1]['status'], 'cleanup line is Gelb');
    assert_true(strpos($r['findings'][1]['label'], $kept) !== false, 'cleanup line names the path');

    // --------------------------- a modified file survives the same-request removal

    reset_state();
    $unregistered = $folder . '/' . bin2hex(\random_bytes(16)) . '.php';
    $obs = probe(static function (string $url) use ($unregistered): array {
        file_put_contents(url_to_path($url), "\n// changed", FILE_APPEND);
        file_put_contents($unregistered, '<?php // someone else');
        return [404, 'Not found'];
    });
    assert_same(1, count($obs['cleanup_failed']), 'modified probe → reported');
    assert_true(file_exists($obs['cleanup_failed'][0]), 'modified probe → kept');
    assert_same(1, count(entries()), 'modified probe → entry kept');
    assert_same('<?php // someone else', file_get_contents($unregistered), 'an unregistered file is untouched by the same-request removal');
    assert_same([$unregistered], $obs['unregistered'], 'Gate B pass 1 (spec-10): the same-request removal reports the unregistered file');
    $g = grade($obs);
    $ids = array_column($g['findings'], 'status', 'id');
    assert_same(Status::YELLOW, $ids['php_in_uploads:probe-unregistered'] ?? null, 'the unregistered file is its own Gelb line');
    assert_true(strpos(implode(' ', array_column($g['findings'], 'label')), $unregistered) !== false, 'and names its path');
    assert_true(is_dir($folder), 'a probe folder that is not empty after the run is kept');
    assert_same(Status::YELLOW, grade($obs)['status'], 'execution Grün, cleanup line Gelb → overall Gelb');

    // Gate B pass 6: registry write and unlink both fail → the leftover is reported, never silent.
    reset_state();
    $db->fail = [Options::name('probes')];
    Seams::$fail_unlink = true;
    $obs = probe(static fn(string $url): array => [404, 'nf']);
    Seams::$fail_unlink = false;
    $db->fail = [];
    assert_same('register', $obs['step'], 'fixture: the registry write failed');
    assert_same(1, count($obs['cleanup_failed']), 'the file left behind is reported');
    assert_true(file_exists($obs['cleanup_failed'][0]), 'and it is really there');
    assert_same(Status::YELLOW, array_column(grade($obs)['findings'], 'status', 'id')['php_in_uploads:probe-cleanup'] ?? null, 'as its own Gelb cleanup line');
    @\unlink($obs['cleanup_failed'][0]);

    // ------------------------------------------------- two runs interleaved

    reset_state();
    $counts = [];
    // The second process slips in when the first starts its pre-fetch check.
    $db->on_acquire[2] = static function (): void {
        $GLOBALS['second'] = Probe::run(ctx(true, 'run-2'));
    };
    $GLOBALS['stub']['answer'] = static function (string $url) use (&$counts): array {
        $counts[] = count(entries());
        return [404, 'Not found'];
    };
    $GLOBALS['stub']['requests'] = [];
    $first = Probe::run(ctx(true, 'run-1'));
    assert_same([2, 1], $counts, 'two probes created at the same moment both keep their entries');
    assert_same('', $first['step'], 'the first run completes');
    assert_same('', $GLOBALS['second']['step'], 'the second run completes');
    assert_same([], folder_files($folder), 'both files removed');
    assert_same([], entries(), 'both entries removed');

    // -------------------------------- entry removed by purge before the fetch

    reset_state();
    $db->on_acquire[2] = static function (): void {
        $GLOBALS['purge'] = Purge::run();
    };
    $obs = probe(static fn(string $url): array => [404, 'Not found']);
    assert_same('entry', $obs['step'], 'entry purged before the fetch → entry step');
    assert_same([], $GLOBALS['stub']['requests'], 'entry purged before the fetch → not fetched');
    $r = grade($obs);
    assert_same(Status::UNKNOWN, $r['status'], 'entry purged before the fetch → Nicht prüfbar, never Grün');
    assert_true(strpos(main_finding($r)['label'], 'before the fetch') !== false, 'the label says the entry was gone before the fetch');
    assert_same([], folder_files($folder), 'purge removed the unexpired probe');

    // Purge during the fetch: the 404 says nothing about the file.
    reset_state();
    $obs = probe(static function (string $url): array {
        Purge::run();
        return [404, 'Not found'];
    });
    assert_same('entry', $obs['step'], 'entry purged during the fetch → entry step');
    assert_same(Status::UNKNOWN, grade($obs)['status'], 'entry purged during the fetch → Nicht prüfbar, never Grün');

    // ------------------------------------------------------ busy mutex

    reset_state();
    hold_mutex();
    $obs = probe(null);
    assert_same('busy', $obs['step'], 'busy mutex → busy step');
    assert_true(!is_dir($folder) || folder_files($folder) === [], 'busy mutex → nothing created');
    assert_same(Status::UNKNOWN, grade($obs)['status'], 'busy mutex → Nicht prüfbar');

    // ---------------------------------------------------------- the probe file itself

    reset_state();
    mkdir($folder, 0700, true);
    $live = ['path' => $folder . '/' . str_repeat('c', 32) . '.php', 'run' => 'x', 'expires' => \time() + 60];
    file_put_contents($live['path'], Probe::expected_content($live));
    $printed = execute($live['path']);
    assert_same('sfx-site-check-probe-' . str_repeat('c', 32), $printed, 'before its expiry the probe prints the marker (PHP CLI, no WordPress)');
    $dead = ['path' => $folder . '/' . str_repeat('d', 32) . '.php', 'run' => 'x', 'expires' => \time() - 1];
    file_put_contents($dead['path'], Probe::expected_content($dead));
    assert_same('', execute($dead['path']), 'after its expiry the probe prints nothing');
    assert_true(Probe::expected_content(['path' => 'nope', 'expires' => 'x']) !== '', 'an invalid entry never expects an empty file');

    // ------------------------------------------------------------ cleanup

    reset_state();
    $t = $now();
    $expired_ok = fixture_probe($folder, $t - 1);
    $expired_changed = fixture_probe($folder, $t - 1, '<?php echo "changed";');
    $fresh = fixture_probe($folder, $t + 200);
    $old_location = fixture_probe(WP_CONTENT_DIR . '/uploads-new/sfx-site-check', $t - 1);
    $stranger = $folder . '/' . str_repeat('e', 32) . '.php';
    file_put_contents($stranger, '<?php // not registered');
    // $old_location was created while uploads lived in uploads-new; uploads has moved since.
    set_entries([$expired_ok, $expired_changed, $fresh, $old_location]);
    $c = Probe::cleanup();
    assert_true(!file_exists($expired_ok['path']), 'cleanup: expired probe with expected content → deleted');
    assert_true(file_exists($expired_changed['path']), 'cleanup: expired probe with changed content → kept');
    assert_true(in_array($expired_changed['path'], $c['kept'], true), 'cleanup: changed probe → reported');
    assert_true(file_exists($fresh['path']), 'cleanup: unexpired probe → untouched');
    assert_true(!file_exists($old_location['path']), 'cleanup: a probe at its recorded path is removed after the uploads location changed');
    assert_true(file_exists($stranger), 'cleanup: unregistered file → kept');
    assert_true(in_array($stranger, $c['unregistered'], true), 'cleanup: unregistered file → reported');
    assert_true(!in_array($fresh['path'], $c['unregistered'], true), 'cleanup: a registered probe is not called unregistered');
    $left = array_column(entries(), 'path');
    sort($left);
    $want = [$expired_changed['path'], $fresh['path']];
    sort($want);
    assert_same($want, $left, 'cleanup: entries of deleted probes go, kept and unexpired ones stay');
    assert_same(false, $c['busy'], 'cleanup: not busy');

    reset_state();
    $gone = ['path' => $folder . '/' . str_repeat('f', 32) . '.php', 'run' => 'x', 'expires' => $now() - 1];
    mkdir($folder, 0700, true);
    set_entries([$gone]);
    Probe::cleanup();
    assert_same([], entries(), 'cleanup: missing file → entry dropped');

    reset_state();
    $expired = fixture_probe($folder, $now() - 1);
    set_entries([$expired]);
    hold_mutex();
    $c = Probe::cleanup();
    assert_same(true, $c['busy'], 'cleanup: busy mutex is reported');
    assert_true(file_exists($expired['path']), 'cleanup: busy → nothing deleted');

    // ------------------------------- php_files_uploads and the probe folder (Task 10)

    $scan = static function (): array {
        $result = Catalogue::grade('php_files_uploads', \SFX\SiteCheck\Checks\ServerChecks::observe('php_files_uploads', ctx()), ctx());
        return array_column($result['findings'], null, 'id');
    };
    $id_of = static fn(string $path): string => 'php_files_uploads:/wp-content/uploads/sfx-site-check/' . basename($path);

    // An own probe with exactly the expected content is skipped.
    reset_state();
    $own = fixture_probe($folder, $now() + 200);
    set_entries([$own]);
    assert_same([], $scan(), 'php_files_uploads: a registered probe with the expected content is skipped');

    // The registry was cached before another request created the probe: decided on a fresh read.
    reset_state();
    $GLOBALS['option_cache'] = true;
    set_entries([]);
    assert_same([], get_option(Options::name('probes')), 'fixture: the empty registry is now cached');
    $other = fixture_probe($folder, $now() + 200);
    set_entries([$other]);
    assert_same([], get_option(Options::name('probes')), 'fixture: the cache still says no probe');
    assert_same([], $scan(), 'php_files_uploads: a probe created by another request after the registry was cached is not reported');

    // … and removed again by that request before the decision: nothing to report either.
    reset_state();
    $GLOBALS['option_cache'] = true;
    $other = fixture_probe($folder, $now() + 200);
    set_entries([$other]);
    get_option(Options::name('probes'));
    $db->on_acquire[1] = static function () use ($other): void {
        unlink($other['path']);
        set_entries([]);
    };
    assert_same([], $scan(), 'php_files_uploads: a probe removed before the decision is not reported');
    $GLOBALS['option_cache'] = false;

    // Empty, truncated, modified and unregistered files stay reportable.
    reset_state();
    $t = $now() + 200;
    $empty = fixture_probe($folder, $t, '');
    $truncated = fixture_probe($folder, $t, '');
    file_put_contents($truncated['path'], substr(Probe::expected_content($truncated), 0, 20));
    $modified = fixture_probe($folder, $t, '');
    file_put_contents($modified['path'], Probe::expected_content($modified) . "system(\$_GET['c']);");
    $stranger = $folder . '/' . str_repeat('7', 32) . '.php';
    file_put_contents($stranger, '<?php // not registered');
    set_entries([$empty, $truncated, $modified]);
    $f = $scan();
    foreach (['empty' => $empty['path'], 'truncated' => $truncated['path'], 'modified' => $modified['path'], 'unregistered' => $stranger] as $what => $path) {
        assert_same(Status::YELLOW, $f[$id_of($path)]['status'] ?? null, "php_files_uploads: a {$what} file in the probe folder is reported");
    }

    // A failed write before expiry: the empty registered file is reported.
    reset_state();
    Seams::$fail_write = true;
    $obs = probe(null);
    Seams::$fail_write = false;
    assert_same('write', $obs['step'], 'fixture: the write failed');
    $kept = entries()[0];
    assert_true($kept['expires'] > $now(), 'fixture: the entry has not expired');
    assert_same(Status::YELLOW, $scan()[$id_of($kept['path'])]['status'] ?? null, 'php_files_uploads: the file of a failed write is reported before its expiry');

    // Busy: probe-folder files are Nicht prüfbar, never suspicious code; the rest is scanned.
    reset_state();
    mkdir($folder, 0700, true);
    $shell = $folder . '/' . str_repeat('8', 32) . '.php';
    file_put_contents($shell, "<?php eval(base64_decode('ZWNobyAxOw=='));");
    file_put_contents($uploads . '/elsewhere.php', '<?php echo 1;');
    hold_mutex();
    $f = $scan();
    unlink($uploads . '/elsewhere.php');
    assert_same(Status::UNKNOWN, $f[$id_of($shell)]['status'] ?? null, 'php_files_uploads: busy → probe-folder file Nicht prüfbar');
    assert_true(strpos($f[$id_of($shell)]['label'], 'test-file folder in use right now') !== false, 'busy → "Testdatei-Ordner gerade in Benutzung"');
    assert_true(strpos($f[$id_of($shell)]['label'], 'suspicious') === false, 'busy → never suspicious code');
    assert_same(Status::YELLOW, $f['php_files_uploads:/wp-content/uploads/elsewhere.php']['status'] ?? null, 'busy → files outside the probe folder are still scanned');

    // ------------------------------------------------- purge: SiteCheck's segment

    $all_options = static function (): void {
        foreach (Options::KEYS as $key) {
            if ($key !== 'mutex') {
                $GLOBALS['wpdb']->rows[Options::name($key)] = serialize(['x' => $key]);
            }
        }
    };

    reset_state();
    $all_options();
    $own = fixture_probe($folder, $now() + 200);
    $modified = fixture_probe($folder, $now() + 200, '<?php // edited');
    $stranger = $folder . '/' . str_repeat('9', 32) . '.php';
    file_put_contents($stranger, '<?php // not registered');
    set_entries([$own, $modified]);
    $p = Purge::run();
    assert_same(false, $p['busy'], 'purge: not busy');
    assert_true(!file_exists($own['path']), 'purge: an unexpired own probe is deleted');
    assert_true(file_exists($modified['path']), 'purge: a modified probe is kept');
    assert_true(file_exists($stranger), 'purge: an unregistered file is kept');
    assert_same([$modified['path']], $p['probes_failed'], 'purge: the kept probe is reported');
    assert_same([Options::name('probes')], array_keys($db->rows), 'purge: every option goes except the probes option, which keeps the failed probe');
    assert_same([$modified['path']], array_column(entries(), 'path'), 'purge: the kept option holds only the failed probe');
    assert_same(6, $p['options'], 'purge: counts the six options it deleted');
    assert_same([Options::hook('probe_cleanup')], $GLOBALS['stub']['cleared'], 'purge: unschedules the cleanup hook by its Options name');

    // An undeletable own probe: the folder refuses the unlink.
    $can_test_permissions = !(function_exists('posix_geteuid') && posix_geteuid() === 0);
    if ($can_test_permissions) {
        reset_state();
        $all_options();
        $stuck = fixture_probe($folder, $now() + 200);
        set_entries([$stuck]);
        chmod($folder, 0500);
        $p = Purge::run();
        assert_same([$stuck['path']], $p['probes_failed'], 'purge: an undeletable probe is reported');
        assert_true(file_exists($stuck['path']), 'purge: the undeletable probe is still there');
        assert_same([$stuck['path']], array_column(entries(), 'path'), 'purge: its entry is kept');
        assert_same(6, $p['options'], 'purge: the other options are deleted and counted');

        // Busy: another process holds the mutex — nothing at all is deleted.
        hold_mutex();
        $before = $db->rows;
        $GLOBALS['stub']['cleared'] = [];
        $p = Purge::run();
        assert_same(true, $p['busy'], 'purge: a held mutex → busy');
        assert_same(0, $p['options'], 'purge: busy → nothing counted');
        assert_same($before, $db->rows, 'purge: busy → nothing deleted');
        assert_same([], $GLOBALS['stub']['cleared'], 'purge: busy → no hook unscheduled');
        unset($db->rows[Options::name('mutex')]);

        // Retry once the probe is deletable.
        chmod($folder, 0700);
        $p = Purge::run();
        assert_same([], $p['probes_failed'], 'purge retry: nothing failed');
        assert_true(!file_exists($stuck['path']), 'purge retry: the probe is deleted');
        assert_same([], array_keys($db->rows), 'purge retry: the probes option is deleted');
        assert_same(1, $p['options'], 'purge retry: the probes option is counted');
    } else {
        echo "skip: running as root, permission cases cannot be staged\n";
    }

    // After purge an empty probe folder is removed; a non-empty one is kept.
    reset_state();
    $own = fixture_probe($folder, $now() + 200);
    set_entries([$own]);
    Purge::run();
    assert_true(!file_exists($folder), 'purge: the probe folder, empty after the teardown, is removed');
    reset_state();
    mkdir($folder, 0700, true);
    Purge::run();
    assert_true(!file_exists($folder), 'purge: an empty probe folder without entries is removed');
    reset_state();
    $own = fixture_probe($folder, $now() + 200);
    file_put_contents($folder . '/' . str_repeat('6', 32) . '.php', '<?php // not registered');
    set_entries([$own]);
    Purge::run();
    assert_true(is_dir($folder), 'purge: a probe folder that is not empty is kept');

    reset_state();
    $done = Purge::run();
    assert_same(['options' => 0, 'probes_failed' => [], 'probes_unregistered' => [], 'refused' => [], 'hooks_failed' => [], 'busy' => false], $done, 'purge on a fresh site deletes nothing and reports nothing');

    // Gate B pass 2 (quality-12): a cron event that could not be unscheduled is reported.
    reset_state();
    $GLOBALS['stub']['clear_fail'] = true;
    $p = Purge::run();
    $GLOBALS['stub']['clear_fail'] = false;
    assert_same([Options::hook('probe_cleanup')], $p['hooks_failed'], 'failed unscheduling → the hook is named');

    // Gate B pass 3: a mutex row that could not be released is reported (never someone else's).
    reset_state();
    $db->fail = [Options::name('mutex')];
    $p = Purge::run();
    $db->fail = [];
    assert_true(in_array(Options::name('mutex'), $p['refused'], true), 'failed mutex release → reported');
    unset($db->rows[Options::name('mutex')]);
    reset_state();
    assert_same([], Purge::run()['refused'], 'a normal release reports nothing');

    // ------------------------------- Gate B pass 1: refused or failed deletes (spec-9, quality-2)

    // Another process takes the section over right before the lock is deleted:
    // every fenced delete is refused, and nothing after it runs.
    $takeover = static function (): void {
        $GLOBALS['wpdb']->rows[Options::name('mutex')] = 'taker:' . (int) floor(FakeClock::$now);
    };
    foreach (['lock', 'manual'] as $key) {
        reset_state();
        $all_options();
        $own = fixture_probe($folder, $now() + 200);
        set_entries([$own]);
        $db->on_delete[Options::name($key)] = $takeover;
        $p = Purge::run();
        assert_same(false, $p['busy'], "refused {$key}: the section was entered (not busy)");
        assert_same([Options::name($key)], $p['refused'], "refused {$key}: the refused delete is reported, not counted as absent");
        assert_true(isset($db->rows[Options::name('manual')]), "refused {$key}: the issued run is still there");
        assert_true(file_exists($own['path']), "refused {$key}: the probe teardown did not run");
        assert_true(isset($db->rows[Options::name('settings')]), "refused {$key}: the remaining options were not touched");
        assert_same($key === 'manual' ? 1 : 0, $p['options'], "refused {$key}: only what was really deleted is counted");
    }

    // A database error on one later option: the others go, the failure is reported.
    reset_state();
    $all_options();
    $db->fail = [Options::name('settings')];
    $p = Purge::run();
    assert_same([Options::name('settings')], $p['refused'], 'failed delete of settings → reported');
    assert_true(isset($db->rows[Options::name('settings')]), 'failed delete → the option is still there');
    assert_same(6, $p['options'], 'the other six options are deleted and counted');

    // Teardown cannot rewrite the probes option: reported, the option stays.
    reset_state();
    $all_options();
    $kept_probe = fixture_probe($folder, $now() + 200, '<?php // edited');
    set_entries([$kept_probe]);
    $db->fail = [Options::name('probes')];
    $p = Purge::run();
    assert_same([$kept_probe['path']], $p['probes_failed'], 'teardown: the kept probe is reported');
    assert_true(in_array(Options::name('probes'), $p['refused'], true), 'teardown: the failed rewrite of the probes option is reported');

    // Unregistered files are reported (never deleted) by the purge too (spec-10).
    reset_state();
    $stranger = $folder . '/' . str_repeat('5', 32) . '.php';
    mkdir($folder, 0700, true);
    file_put_contents($stranger, '<?php // not registered');
    $p = Purge::run();
    assert_same([$stranger], $p['probes_unregistered'], 'purge: an unregistered file is reported');
    assert_true(file_exists($stranger), 'purge: and kept');

    // Cleanup: a failed rewrite of the registry is reported, not ignored.
    reset_state();
    $expired = fixture_probe($folder, $now() - 1);
    set_entries([$expired]);
    $db->fail = [Options::name('probes')];
    $c = Probe::cleanup();
    assert_same(true, $c['store_failed'], 'cleanup: a failed registry write is reported');
    $db->fail = [];
    assert_same(false, Probe::cleanup()['store_failed'], 'cleanup: a working registry write is not');

    // ------------------------------------------------------------ wiring

    reset_state();
    $GLOBALS['stub']['actions'] = [];
    $GLOBALS['stub']['scheduled'] = [];
    new \SFX\SiteCheck\Controller();
    assert_true(in_array([\SFX\SiteCheck\DashboardBox::class, 'widgets'], $GLOBALS['stub']['filters']['sfx/custom_dashboard/widgets'] ?? [], true), 'the dashboard box joins the Custom Dashboard filter');
    assert_true(in_array([\SFX\SiteCheck\DashboardBox::class, 'register_native'], $GLOBALS['stub']['actions']['wp_dashboard_setup'] ?? [], true), 'the dashboard box registers on wp_dashboard_setup');
    $hook = Options::hook('probe_cleanup');
    assert_same('sfx_site_check_probe_cleanup', $hook, 'cleanup hook name');
    assert_true(in_array([\SFX\SiteCheck\Controller::class, 'run_cleanup'], $GLOBALS['stub']['actions'][$hook] ?? [], true), 'the daily hook runs the cleanup');
    assert_true(in_array([\SFX\SiteCheck\AdminPage::class, 'on_load'], $GLOBALS['stub']['actions']['load-tools_page_sfx-site-check'] ?? [], true), 'loading the check page runs the cleanup');
    assert_true(in_array([\SFX\SiteCheck\Controller::class, 'maybe_schedule_cleanup'], $GLOBALS['stub']['actions']['admin_init'] ?? [], true), 'scheduling happens on admin_init');
    \SFX\SiteCheck\Controller::maybe_schedule_cleanup();
    assert_same(['sfx_site_check_probe_cleanup' => 'daily'], $GLOBALS['stub']['scheduled'], 'scheduled daily');
    $expired = fixture_probe($folder, $now() - 1);
    set_entries([$expired]);
    \SFX\SiteCheck\Controller::run_cleanup();
    assert_true(!file_exists($expired['path']), 'the cleanup callback removes an expired probe');

    echo "PASS: site-check probe tests\n";
    exit(0);
}
